<?php
/**
 * login_form.php – Secure EduSync Login Portal
 * -------------------------------------------------
 * • CSRF + Remember-me (secure)
 * • Show/Hide password toggle
 * • Role-based redirect (admin|teacher|student|parent)
 * • Activity logging (function defined inline)
 * • GSAP/Tailwind hero + loader + confetti
 *
 * @author NerdSpring Dev Team
 * @version 1.1.1
 * @since 2025
 */
ob_start();
session_start();

// -------------------------------------------------------------------
// 1. LOAD CORE
// -------------------------------------------------------------------
require_once 'config/db.php';
require_once 'functions/csrf.php';

// -------------------------------------------------------------------
// 2. LOG ACTIVITY (fallback – works even if external file is missing)
// -------------------------------------------------------------------
if (!function_exists('log_activity')) {
    function log_activity(int $user_id, string $action, string $description = ''): void
    {
        try {
            $db = db(); // PDO instance from db.php
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $stmt = $db->prepare(
                "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())"
            );
            $stmt->execute([$user_id, $action, $description, $ip, $user_agent]);
        } catch (Throwable $e) {
            error_log("log_activity error: " . $e->getMessage());
        }
    }
}

// -------------------------------------------------------------------
// 3. ALREADY LOGGED IN → REDIRECT TO DASHBOARD
// -------------------------------------------------------------------
if (isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    $map = [
        'admin'   => 'admin/dashboard.php',
        'teacher' => 'teacher/dashboard.php',
        'student' => 'student/attendance.php',
        'parent'  => 'parent/dashboard.php',
    ];
    $target = $map[$_SESSION['user_role']] ?? 'index.php';
    header('Location: ' . $target);
    exit;
}

// -------------------------------------------------------------------
// 4. PROCESS POST
// -------------------------------------------------------------------
$errors = [];
$email = $password = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ---- CSRF ----------------------------------------------------
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh.';
    } else {
        $email    = trim(filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL));
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember']);

        if (empty($email) || empty($password)) {
            $errors[] = 'Email and password are required.';
        } else {
            try {
                $db = db();
                $stmt = $db->prepare("
                    SELECT id, name, email, password, role, profile_picture, status
                    FROM users
                    WHERE email = ? AND status = 'active'
                    LIMIT 1
                ");
                $stmt->execute([$email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && password_verify($password, $user['password'])) {

                    // ---- SESSION ------------------------------------------------
                    $_SESSION['user_id']          = (int)$user['id'];
                    $_SESSION['user_role']        = $user['role'];
                    $_SESSION['user_name']        = $user['name'];
                    $_SESSION['user_email']       = $user['email'];
                    $_SESSION['profile_picture']  = $user['profile_picture']
                        ?: 'assets/images/default_avatar.png';

                    // ---- LAST LOGIN / IP ----------------------------------------
                    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                    $upd = $db->prepare("UPDATE users SET last_login = NOW(), last_ip = ? WHERE id = ?");
                    $upd->execute([$ip, $user['id']]);

                    // ---- REMEMBER-ME --------------------------------------------
                    if ($remember) {
                        $token  = bin2hex(random_bytes(32));
                        $hashed = password_hash($token, PASSWORD_DEFAULT);
                        setcookie('remember_token', $token, [
                            'expires'  => time() + 30*24*60*60,
                            'path'     => '/',
                            'secure'   => true,
                            'httponly' => true,
                            'samesite' => 'Lax',
                        ]);
                        $db->prepare("UPDATE users SET remember_token = ? WHERE id = ?")
                           ->execute([$hashed, $user['id']]);
                    }

                    // ---- LOG ACTIVITY -------------------------------------------
                    log_activity($user['id'], 'login', "Login from {$ip}");

                    // ---- FLASH SUCCESS (confetti) -------------------------------
                    $_SESSION['flash_success'] = 'Welcome back, ' . htmlspecialchars($user['name']) . '!';

                    // ---- ROLE REDIRECT ------------------------------------------
                    $redir = match ($user['role']) {
                        'admin'   => 'admin/dashboard.php',
                        'teacher' => 'teacher/dashboard.php',
                        'student' => 'student/attendance.php',
                        'parent'  => 'parent/dashboard.php',
                        default   => 'index.php',
                    };
                    header('Location: ' . $redir);
                    exit;
                } else {
                    $errors[] = 'Invalid email or password.';
                }
            } catch (Throwable $e) {
                error_log('Login error: ' . $e->getMessage());
                $errors[] = 'Login failed – try again later.';
            }
        }
    }
}

// -------------------------------------------------------------------
// 5. CSRF TOKEN FOR FORM
// -------------------------------------------------------------------
$csrf_token = generate_csrf_token();

// -------------------------------------------------------------------
// 6. THEME HANDLING
// -------------------------------------------------------------------
const ASSET_PATH = 'assets/images/';
const LOGO_WHITE = ASSET_PATH . 'white-logo.png';
const LOGO_BLUE = ASSET_PATH . 'blue-logo.png';
const DEFAULT_THEME = 'dark';

function getCurrentTheme(): string
{
    return $_SESSION['theme'] ?? DEFAULT_THEME;
}

function setTheme(string $theme): void
{
    $_SESSION['theme'] = ($theme === 'light') ? 'light' : 'dark';
}

if (isset($_POST['theme'])) {
    setTheme($_POST['theme']);
}

$theme = getCurrentTheme();
$logo = ($theme === 'light') ? LOGO_BLUE : LOGO_WHITE;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | EduSync Portal</title>

    <!-- Tailwind + GSAP + Fonts -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui'] },
                    colors: {
                        primary: {50:'#f0f9ff',100:'#e0f2fe',200:'#bae6fd',300:'#7dd3fc',400:'#38bdf8',500:'#0ea5e9',600:'#0284c7',700:'#0369a1',800:'#1e40af',900:'#1e3a8a',950:'#172554'},
                        accent:   {500:'#10b981',600:'#059669',700:'#047857'}
                    },
                    animation: {
                        float:'float 6s ease-in-out infinite',
                        rotate:'rotate 20s linear infinite',
                        pulse:'pulse 4s cubic-bezier(0.4,0,0.6,1) infinite',
                        fadeUp:'fadeUp 0.8s ease-out forwards',
                        confetti:'confetti 1.2s ease-out forwards'
                    },
                    keyframes: {
                        float:{'0%,100%':{transform:'translateY(0)'},'50%':{transform:'translateY(-20px)'}},
                        rotate:{'0%':{transform:'rotate(0deg)'},'100%':{transform:'rotate(360deg)'}},
                        fadeUp:{'0%':{opacity:0,transform:'translateY(30px)'},'100%':{opacity:1,transform:'translateY(0)'}},
                        confetti:{'0%':{transform:'translateY(0) rotate(0deg)',opacity:1},'100%':{transform:'translateY(-120px) rotate(720deg)',opacity:0}}
                    }
                }
            }
        }
    </script>

    <style>
        :root{--primary-900:#1e3a8a;--primary-500:#0ea5e9;--accent-500:#10b981}
        body{font-family:'Inter',sans-serif;background:#f8fafc;color:#1f2937;overflow-x:hidden}
        html{scroll-behavior:smooth}
        ::-webkit-scrollbar{width:10px}::-webkit-scrollbar-track{background:#f1f5f9}::-webkit-scrollbar-thumb{background:linear-gradient(#1e3a8a,#0ea5e9);border-radius:5px}
        .hero-gradient{background:linear-gradient(135deg,#1e3a8a 0%,#0ea5e9 50%,#10b981 100%)}
        .login-card{background:white;border-radius:2rem;padding:2.5rem;box-shadow:0 25px 50px -12px rgba(30,58,138,.25);transition:all .4s cubic-bezier(.175,.885,.32,1.275)}
        .login-card:hover{transform:translateY(-12px) scale(1.02);box-shadow:0 35px 70px -12px rgba(30,58,138,.35)}
        .btn-glow{position:relative;overflow:hidden}
        .btn-glow::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.3),transparent);transition:.6s}
        .btn-glow:hover::before{left:100%}
        .ornament{position:absolute;opacity:.15;animation:rotate 30s linear infinite}
        #loader{transition:opacity .5s}
        .error-msg{background:#fee2e2;border:1px solid #fecaca;color:#dc2626;padding:1rem;border-radius:1rem;margin-bottom:1.5rem}
        .input-focus{transition:border .3s}
        .input-focus:focus{border-color:#0ea5e9;box-shadow:0 0 0 3px rgba(14,165,233,.2)}
        .password-toggle{cursor:pointer;color:#9ca3af}
        .password-toggle:hover{color:#4b5563}
    </style>
</head>
<body>

<!-- ==================== LOADER ==================== -->
<div id="loader" class="fixed inset-0 hero-gradient flex items-center justify-center z-50">
    <div class="text-center">
        <div class="animate-spin rounded-full h-32 w-32 border-t-4 border-b-4 border-white mx-auto mb-4"></div>
        <p class="text-white font-bold text-2xl">Loading Portal...</p>
    </div>
</div>

<!-- ==================== HERO + LOGIN ==================== -->
<section class="hero-gradient min-h-screen flex items-center relative overflow-hidden">
    <div class="absolute inset-0 bg-black/20"></div>
    <div class="absolute inset-0">
        <div class="absolute top-10 left-10 w-96 h-96 bg-white/10 rounded-full blur-3xl animate-pulse"></div>
        <div class="absolute bottom-20 right-20 w-80 h-80 bg-emerald-400/10 rounded-full blur-3xl animate-pulse" style="animation-delay:1s;"></div>
        <div class="ornament top-20 right-40 w-64 h-64 bg-emerald-300/20 rounded-full blur-2xl"></div>
        <div class="ornament bottom-40 left-40 w-48 h-48 bg-prince-300/20 rounded-full blur-2xl" style="animation-direction:reverse;"></div>
    </div>

    <div class="relative z-10 container mx-auto px-4 py-20">
        <div class="max-w-md mx-auto">
            <div class="login-card text-center">

                <!-- Icon -->
                <div class="mb-8 relative">
                    <div class="inline-block p-6 bg-gradient-to-br from-prince-600 to-prince-800 rounded-full shadow-2xl animate-float">
                        <i class="fas fa-user-lock text-5xl text-white"></i>
                    </div>
                    <div class="absolute -top-2 -right-2 w-6 h-6 bg-emerald-500 rounded-full animate-ping"></div>
                    <div class="absolute -top-2 -right-2 w-6 h-6 bg-emerald-500 rounded-full"></div>
                </div>

                <h1 class="text-4xl md:text-5xl font-black mb-4 text-prince-900 drop-shadow-md">
                    Welcome Back
                </h1>
                <p class="text-lg text-gray-700 mb-8 leading-relaxed">
                    Log in to access your EduSync dashboard.
                </p>

                <!-- Errors -->
                <?php if (!empty($errors)): ?>
                    <div class="error-msg text-left">
                        <ul class="list-disc list-inside space-y-1">
                            <?php foreach ($errors as $e): ?>
                                <li><?= htmlspecialchars($e) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Form -->
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 text-left">Email Address</label>
                        <div class="relative">
                            <i class="fas fa-envelope absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="email" name="email" value="<?= htmlspecialchars($email) ?>"
                                   class="w-full pl-12 pr-4 py-3 rounded-xl border border-gray-300 input-focus focus:outline-none"
                                   placeholder="you@school.edu.gh" required>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 text-left">Password</label>
                        <div class="relative">
                            <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
                            <input type="password" name="password" id="pwd"
                                   class="w-full pl-12 pr-12 py-3 rounded-xl border border-gray-300 input-focus focus:outline-none"
                                   placeholder="••••••••" required>
                            <span class="password-toggle absolute right-4 top-1/2 -translate-y-1/2" onclick="togglePwd()">
                                <i class="fas fa-eye-slash"></i>
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center justify-between text-sm">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="remember" class="rounded text-prince-600 focus:ring-prince-500">
                            <span class="text-gray-600">Remember me</span>
                        </label>
                        <a href="/forgot.php" class="text-prince-600 hover:text-prince-800 font-medium">Forgot Password?</a>
                    </div>

                    <button type="submit"
                            class="btn-glow w-full bg-prince-900 text-white font-bold text-lg px-8 py-4 rounded-full shadow-xl hover:shadow-2xl transition-all duration-300 transform hover:scale-105 flex items-center justify-center gap-3">
                        <i class="fas fa-sign-in-alt"></i>
                        <span>Login Securely</span>
                    </button>
                </form>

                <!-- Register Link -->
                <p class="mt-8 text-sm text-gray-600">
                    Don't have an account?
                    <a href="/register.php" class="text-prince-600 font-bold hover:text-prince-800">Sign Up</a>
                </p>

                <!-- Security Badges -->
                <div class="flex justify-center gap-3 mt-8 flex-wrap">
                    <div class="flex items-center gap-2 px-3 py-1 bg-gray-100 rounded-full text-xs font-medium text-gray-700">
                        <i class="fas fa-shield-alt"></i> Encrypted
                    </div>
                    <div class="flex items-center gap-2 px-3 py-1 bg-gray-100 rounded-full text-xs font-medium text-gray-700">
                        <i class="fas fa-lock"></i> Secure
                    </div>
                    <div class="flex items-center gap-2 px-3 py-1 bg-gray-100 rounded-full text-xs font-medium text-gray-700">
                        <i class="fas fa-fingerprint"></i> Protected
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Confetti Container -->
<div id="confetti-container"></div>

<script>
/* ---------- Loader ---------- */
document.documentElement.style.visibility = 'hidden';
window.addEventListener('load', () => {
    document.documentElement.style.visibility = 'visible';
    gsap.to('#loader', {opacity:0, duration:0.6, ease:'power2.out',
        onComplete:()=>document.getElementById('loader').remove()
    });
});

/* ---------- Password Toggle ---------- */
function togglePwd() {
    const input = document.getElementById('pwd');
    const icon  = document.querySelector('.password-toggle i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('fa-eye-slash','fa-eye');
    } else {
        input.type = 'password';
        icon.classList.replace('fa-eye','fa-eye-slash');
    }
}

/* ---------- Confetti on flash success ---------- */
<?php if (isset($_SESSION['flash_success'])): ?>
setTimeout(() => {
    const colors = ['#1e3a8a','#0ea5e9','#10b981','#0284c7','#059669'];
    for (let i = 0; i < 80; i++) {
        const c = document.createElement('div');
        c.style.position = 'fixed';
        c.style.left = Math.random()*100 + 'vw';
        c.style.top  = '100vh';
        c.style.width = c.style.height = '10px';
        c.style.background = colors[Math.floor(Math.random()*colors.length)];
        c.style.borderRadius = '50%';
        c.style.zIndex = '9999';
        c.style.pointerEvents = 'none';
        c.style.animation = `confetti ${Math.random()*1.5+1.2}s ease-out forwards`;
        document.getElementById('confetti-container').appendChild(c);
        setTimeout(() => c.remove(), 4000);
    }
}, 500);
<?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>

/* ---------- GSAP Animations ---------- */
gsap.registerPlugin(ScrollTrigger);
gsap.from('.login-card', {y:100, opacity:0, duration:1, ease:'back.out(1.7)'});
gsap.from('.login-card > *', {opacity:0, y:30, stagger:0.1, delay:0.6});
</script>
</body>
</html>
<?php ob_end_flush(); ?>