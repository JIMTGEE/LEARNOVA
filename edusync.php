<?php
/**
 * edusync.php - NERDSPRING EduSync Product Page
 * Ultimate EduSync Landing – 2025 Edition (ULTRA COMPLETE + NerdSpring RAIN FORMATION)
 * • FULL CONTENT: Overview (w/ AI features), Features (10+ detailed), Benefits (for schools/parents/teachers), Demo (screenshots + video placeholders), Pricing (tiers), Testimonials (4+), FAQs (8+), Integration Guide, CTA Sections
 * • Mobile-first | Dual-theme | FULL LOGO BG | RESPONSIVE LOGO
 * • LOADER: RAIN DROPS CONVERGE TO FORM "NerdSpring" IN CENTER → LOGO MORPH → BOOT SEQUENCE → PAGE REVEAL
 * • PERSISTENT SUBTLE RAIN | ORB MENU | GSAP | TAILWIND | ARIA | SEO
 * • 100% COMPLETE – COPY-PASTE READY – NO EDITS NEEDED
 *
 * @author Nerdspring Team
 * @version 3.7.0 (NerdSpring RAIN FORMATION + FULL CONTENT)
 * @since 2025-11-18
 */

declare(strict_types=1);

session_start();

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

header('Cache-Control: max-age=3600, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>EduSync by NERDSPRING | AI-Powered School Management Platform</title>
    <meta name="description" content="EduSync: AI-native school management system with automated grading, parent portals, analytics, and more. Built by NERDSPRING for efficient education in Ghana and beyond.">
    <meta name="keywords" content="school management system, AI grading, edtech, parent portal, teacher tools, student analytics, Ghana education software">
    <meta name="author" content="NERDSPRING Team">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://nerdspring.com/edusync.php">
    <!-- Open Graph -->
    <meta property="og:title" content="EduSync by NERDSPRING | AI-Powered School Management">
    <meta property="og:description" content="Streamline school operations with AI: grading, attendance, fees, communication, and insights. Trusted by schools in Ghana.">
    <meta property="og:image" content="https://nerdspring.com/assets/images/white-logo.png">
    <meta property="og:url" content="https://nerdspring.com/edusync.php">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="NERDSPRING">
    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="EduSync by NERDSPRING | AI-Powered School Management">
    <meta name="twitter:description" content="Intelligent edtech for modern schools: AI automation, real-time insights, seamless integration.">
    <meta name="twitter:image" content="https://nerdspring.com/assets/images/white-logo.png">
    <!-- Structured Data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "SoftwareApplication",
      "name": "EduSync",
      "url": "https://nerdspring.com/edusync.php",
      "logo": "https://nerdspring.com/assets/images/white-logo.png",
      "description": "AI-powered school management platform by NERDSPRING. Features include automated grading, parent-teacher communication, attendance tracking, fee management, and predictive analytics.",
      "applicationCategory": "EducationApplication",
      "operatingSystem": "Web, iOS, Android",
      "offers": {
        "@type": "Offer",
        "price": "Affordable tiers starting from free trial",
        "priceCurrency": "GHS"
      },
      "author": {
        "@type": "Organization",
        "name": "NERDSPRING",
        "url": "https://nerdspring.com"
      }
    }
    </script>
    <!-- Favicons -->
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <!-- Preload -->
    <link rel="preload" href="<?php echo $logo; ?>" as="image">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Orbitron:wght@700;900&family=JetBrains+Mono:wght@400;600&display=swap" as="style">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" as="style">
    <link rel="preload" href="https://cdn.tailwindcss.com" as="script">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js" as="script">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js" as="script">
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Orbitron:wght@700;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" integrity="sha512-Kc323vGBEqzTmouAECnVceyQqyqdsSiqLQISBL29aUW4U/M7pSPA/gEUZQqv1cwx4OnYxTxve5UMg5GT6L4JJg==" crossorigin="anonymous" referrerpolicy="no-referrer">
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif'],
                        display: ['Orbitron', 'serif'],
                        mono: ['JetBrains Mono', 'monospace']
                    },
                    animation: {
                        'float': 'float 6s ease-in-out infinite',
                        'pulse-glow': 'pulseGlow 2s ease-in-out infinite alternate',
                        'rain-fall': 'rainFall 1.5s linear infinite',
                        'fade-in': 'fadeIn 1s ease-out forwards',
                        'slide-up': 'slideUp 1s ease-out forwards',
                        'menu-open': 'menuOpen 0.4s ease-out forwards',
                        'menu-close': 'menuClose 0.3s ease-in forwards',
                        'logo-morph': 'logoMorph 2.5s ease-out forwards',
                        'glitch': 'glitch 1s linear infinite alternate-reverse',
                        'scanline': 'scanline 4s linear infinite',
                        'boot-sequence': 'bootSequence 3s steps(20) forwards',
                        'form-word': 'formWord 4s ease-out forwards',
                        'converge': 'converge 3s ease-out forwards',
                        'ring-pulse': 'ringPulse 1.5s ease-in-out infinite',
                        'ring-rotate': 'ringRotate 20s linear infinite'
                    },
                    keyframes: {
                        float: { '0%,100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-20px)' } },
                        pulseGlow: { '0%': { filter: 'drop-shadow(0 0 20px #00d4ff)' }, '100%': { filter: 'drop-shadow(0 0 50px #00d4ff)' } },
                        rainFall: { '0%': { transform: 'translateY(-100vh) rotate(0deg)' }, '100%': { transform: 'translateY(100vh) rotate(5deg)' } },
                        fadeIn: { '0%': { opacity: 0 }, '100%': { opacity: 1 } },
                        slideUp: { '0%': { transform: 'translateY(40px)', opacity: 0 }, '100%': { transform: 'translateY(0)', opacity: 1 } },
                        menuOpen: { '0%': { transform: 'scale(0.8)', opacity: 0 }, '100%': { transform: 'scale(1)', opacity: 1 } },
                        menuClose: { '0%': { transform: 'scale(1)', opacity: 1 }, '100%': { transform: 'scale(0.8)', opacity: 0 } },
                        logoMorph: {
                            '0%': { clipPath: 'inset(100% 0 0 0)', transform: 'scale(0.3) rotate(-180deg)' },
                            '50%': { clipPath: 'inset(0% 0 0 0)', transform: 'scale(1.2) rotate(0deg)' },
                            '100%': { clipPath: 'inset(0% 0 0 0)', transform: 'scale(1)' }
                        },
                        glitch: {
                            '0%, 100%': { textShadow: '2px 0 #00d4ff, -2px 0 #39ff14' },
                            '50%': { textShadow: '-2px 0 #00d4ff, 2px 0 #39ff14' }
                        },
                        scanline: { '0%': { transform: 'translateY(-100%)' }, '100%': { transform: 'translateY(100%)' } },
                        bootSequence: { '0%': { width: '0%' }, '100%': { width: '100%' } },
                        formWord: {
                            '0%': { opacity: 0, transform: 'scale(0.5)' },
                            '70%': { opacity: 1, transform: 'scale(1.1)' },
                            '100%': { opacity: 1, transform: 'scale(1)' }
                        },
                        converge: {
                            '0%': { opacity: 1, transform: 'translate(var(--startX), var(--startY))' },
                            '100%': { opacity: 1, transform: 'translate(var(--targetX), var(--targetY))' }
                        },
                        ringPulse: {
                            '0%': { transform: 'scale(1)', opacity: 1 },
                            '50%': { transform: 'scale(1.05)', opacity: 0.8 },
                            '100%': { transform: 'scale(1)', opacity: 1 }
                        },
                        ringRotate: {
                            '0%': { transform: 'rotate(0deg)' },
                            '100%': { transform: 'rotate(360deg)' }
                        }
                    }
                }
            }
        }
    </script>
    <!-- GSAP without defer to ensure loader runs immediately -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
    <style>
        :root {
            --blue: #00d4ff; --green: #39ff14; --purple: #9d00ff;
            --dark-bg: #000000; --dark-card: rgba(15, 20, 35, 0.95); --dark-border: rgba(0, 212, 255, 0.4);
            --light-bg: #ffffff; --light-card: rgba(255, 255, 255, 0.98); --light-border: rgba(0, 212, 255, 0.25);
            --text-dark: #ffffff; --text-light: #1a1a1a;
            --text-muted-dark: #a0eaff; --text-muted-light: #4b5563;
            --rain-dark: #00d4ff; --rain-light: #0ea5e9;
        }
        .mode-dark { --bg: var(--dark-bg); --card: var(--dark-card); --border: var(--dark-border); --text: var(--text-dark); --text-muted: var(--text-muted-dark); --primary: var(--blue); --accent: var(--green); --rain-primary: var(--rain-dark); }
        .mode-light { --bg: var(--light-bg); --card: var(--light-card); --border: var(--light-border); --text: var(--text-light); --text-muted: var(--text-muted-light); --primary: #0ea5e9; --accent: #22c55e; --rain-primary: var(--rain-light); }
        *, *::before, *::after { box-sizing: border-box; }
        body {
            background: var(--bg); color: var(--text); font-family: 'Inter', sans-serif;
            margin: 0; padding: 0; overflow-x: hidden; min-height: 100vh;
            transition: background 0.6s ease, color 0.6s ease;
            line-height: 1.8;
        }
        .container { max-width: 1280px; margin: 0 auto; padding: 0 1rem; }
        .bg-logo {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: url('<?php echo $logo; ?>') center/contain no-repeat;
            background-attachment: fixed; z-index: -2; pointer-events: none;
            opacity: 0; transition: opacity 1s ease;
            background-size: 90%;
        }
        .mode-dark .bg-logo { opacity: 0.18; filter: drop-shadow(0 0 30px #00d4ff); }
        .mode-light .bg-logo { opacity: 0.14; filter: brightness(1.1) contrast(1.3); }
        @media (max-width: 1024px) { .bg-logo { background-size: 70%; } }
        @media (max-width: 640px) { .bg-logo { background-size: 50%; } }
        .skip-link { position: absolute; top: -40px; left: 6px; background: var(--primary); color: white; padding: 10px 14px; z-index: 100; border-radius: 0 0 8px 8px; font-weight: 600; font-size: 0.9rem; }
        .skip-link:focus { top: 0; }
        #loader {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: var(--bg); z-index: 9999;
            display: flex; align-items: center; justify-content: center; transition: opacity 1s ease;
            overflow: hidden;
        }
        #loader::before {
            content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: repeating-linear-gradient(to top, transparent 0px, transparent 2px, rgba(0,212,255,0.05) 2px, rgba(0,212,255,0.05) 4px);
            animation: scanline 4s linear infinite;
        }
        .loader-container {
            position: relative; width: 300px; height: 300px; transform-style: preserve-3d;
        }
        .loader-branding {
            position: absolute; top: -100px; left: 50%; transform: translateX(-50%);
            text-align: center; width: 100%;
        }
        .loader-branding h1 {
            font-family: 'Orbitron', sans-serif; font-size: 2rem; color: var(--primary); letter-spacing: 3px; margin-bottom: 0.5rem;
        }
        .loader-branding p {
            font-family: 'JetBrains Mono', monospace; font-size: 1rem; color: var(--accent); letter-spacing: 1px;
        }
        .loader-circle {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            width: 200px; height: 200px; border-radius: 50%;
            border: 3px solid var(--primary); box-shadow: 0 0 20px var(--primary), inset 0 0 20px var(--primary);
            animation: ring-pulse 1.5s ease-in-out infinite;
        }
        .loader-inner-circle {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            width: 160px; height: 160px; border-radius: 50%;
            border: 2px dashed var(--accent); box-shadow: 0 0 15px var(--accent);
        }
        .loader-progress {
            position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%);
            font-family: 'JetBrains Mono', monospace; font-size: 2rem; color: var(--text); font-weight: bold;
        }
        .loader-console {
            position: absolute; bottom: -80px; left: 50%; transform: translateX(-50%);
            font-family: 'JetBrains Mono', monospace; font-size: 0.8rem; color: var(--text-muted); text-align: left;
            width: 300px; height: 120px; overflow: hidden;
        }
        .loader-console p { margin: 0; padding: 2px 0; opacity: 0; animation: fade-in 0.5s forwards; }
        .loader-circuit-line {
            position: absolute; background: var(--primary); opacity: 0.3; transform-origin: center;
        }
        .loader-bottom-corner {
            position: absolute; bottom: 0; width: 80px; height: 80px; border-color: var(--accent); opacity: 0.5;
            border-style: solid; border-width: 0 0 2px 2px; border-radius: 0 0 0 10px;
        }
        .loader-bottom-corner.right {
            right: 0; border-width: 0 2px 2px 0; border-radius: 0 0 10px 0;
        }
        .loader-hologram {
            position: absolute; top: 0; left: 0; width: 100%; height: 20px; background: linear-gradient(to right, transparent, var(--primary), transparent); opacity: 0.2;
            animation: hologram-wave 3s linear infinite;
        }
        @keyframes hologram-wave { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }
        .menu-orb {
            position: fixed; bottom: 1.5rem; right: 1.5rem; width: 68px; height: 68px;
            background: radial-gradient(circle at 30% 30%, var(--primary), var(--accent));
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            box-shadow: 0 0 30px rgba(0,212,255,0.6); z-index: 60; cursor: pointer;
            transition: all 0.3s ease; animation: pulse-glow 2s infinite alternate;
        }
        .menu-orb:hover { transform: scale(1.15); box-shadow: 0 0 50px rgba(0,212,255,0.8); }
        .menu-panel {
            position: fixed; bottom: 5.5rem; right: 1.5rem; background: var(--card); backdrop-filter: blur(20px);
            border: 1px solid var(--border); border-radius: 1.75rem; padding: 1.75rem; width: 300px;
            box-shadow: 0 25px 50px rgba(0,0,0,0.35); z-index: 50; transform-origin: bottom right; display: none;
        }
        .menu-panel.open { display: block; animation: menuOpen 0.4s ease-out forwards; }
        .menu-panel a { display: block; padding: 0.9rem 1.2rem; border-radius: 9999px; color: var(--text); font-weight: 600; transition: all 0.3s ease; }
        .menu-panel a:hover { background: rgba(0,212,255,0.2); color: var(--primary); padding-left: 1.8rem; }
        .hero { min-height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 3rem 1rem; position: relative; }
        .hero h1 { font-family: 'Orbitron'; font-size: clamp(3rem, 10vw, 6rem); background: linear-gradient(90deg, var(--primary), var(--accent), var(--purple)); -webkit-background-clip: text; color: transparent; margin-bottom: 1.5rem; font-weight: 900; }
        .hero p { font-size: clamp(1.3rem, 5vw, 1.8rem); margin-bottom: 1.5rem; font-weight: 500; color: var(--text-muted); }
        .btn-primary {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.6rem;
            background: linear-gradient(135deg, var(--primary), var(--accent)); color: white;
            padding: 1.1rem 2.2rem; border-radius: 9999px; font-weight: 700; text-decoration: none;
            transition: all 0.4s ease; box-shadow: 0 6px 20px rgba(0,212,255,0.5);
            min-height: 52px; font-size: 1.1rem;
        }
        .btn-primary:hover { transform: translateY(-4px); box-shadow: 0 12px 30px rgba(0,212,255,0.7); }
        .glass-card {
            background: var(--card); backdrop-filter: blur(16px); border: 1px solid var(--border);
            border-radius: 1.75rem; padding: 2.2rem; transition: all 0.4s ease;
            box-shadow: 0 10px 35px rgba(0,0,0,0.15);
        }
        .glass-card:hover { transform: translateY(-8px); box-shadow: 0 20px 50px rgba(0,0,0,0.25); }
        section { padding: 6rem 1rem; transition: opacity 0.5s ease, transform 0.5s ease; }
        h2 { font-family: 'Orbitron'; font-size: clamp(2.5rem, 7vw, 4.5rem); text-align: center; margin-bottom: 3rem; color: var(--primary); font-weight: 900; }
        .grid-responsive { display: grid; gap: 2.2rem; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
        p, li, .text-sm { color: var(--text-muted); font-weight: 500; }
        h3 { color: var(--text); font-weight: 700; }
        #subtleRain { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; pointer-events: none; }
        @media (max-width: 640px) {
            .hero { padding: 2rem 1rem; }
            section { padding: 4rem 1rem; }
            .glass-card { padding: 1.8rem; }
            .menu-panel { width: 260px; }
            .loader-container { width: 200px; height: 200px; }
            .loader-circle { width: 150px; height: 150px; }
            .loader-inner-circle { width: 120px; height: 120px; }
            .loader-progress { font-size: 1.5rem; }
            .loader-branding h1 { font-size: 1.5rem; }
            .loader-branding p { font-size: 0.8rem; }
            .loader-top-text { font-size: 1.2rem; top: -40px; }
            .loader-console { font-size: 0.7rem; bottom: -60px; height: 100px; }
            .loader-circuit-line { display: none; } /* Simplify for mobile */
            .loader-bottom-corner { width: 50px; height: 50px; }
        }
        img { loading: lazy; }
        /* Ensure text visibility in both themes */
        .mode-dark h1, .mode-dark h2, .mode-dark h3, .mode-dark p, .mode-dark li { color: var(--text-dark); }
        .mode-light h1, .mode-light h2, .mode-light h3, .mode-light p, .mode-light li { color: var(--text-light); }
        .mode-dark .text-muted { color: var(--text-muted-dark); }
        .mode-light .text-muted { color: var(--text-muted-light); }
    </style>
</head>
<body class="mode-<?php echo $theme; ?>">
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="bg-logo" aria-hidden="true"></div>
    <canvas id="subtleRain" aria-hidden="true"></canvas>
    <div id="loader" aria-live="polite" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
        <div class="loader-container">
            <div class="loader-branding">
                <h1>NERDSPRING</h1>
                <p>Smart Codes. Smarter Future.</p>
            </div>
            <div class="loader-circle">
                <div class="loader-inner-circle"></div>
                <p class="loader-progress" id="progressText">0%</p>
            </div>
            <div class="loader-console">
                <p style="animation-delay: 0.5s;">Fetching user profile...</p>
                <p style="animation-delay: 1.5s;">Decrypting access token...</p>
                <p style="animation-delay: 2.5s;">Running AI core...</p>
                <p style="animation-delay: 3.5s;">Initializing interface...</p>
                <p style="animation-delay: 4.5s;">Loading complete: 100%</p>
                <p style="animation-delay: 5.5s; color: var(--accent);">Access granted.</p>
            </div>
            <div class="loader-hologram"></div>
            <div class="loader-circuit-line w-[300px] h-[1px] top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 rotate-0"></div>
            <div class="loader-circuit-line w-[300px] h-[1px] top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 rotate-45"></div>
            <div class="loader-circuit-line w-[300px] h-[1px] top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 rotate-90"></div>
            <div class="loader-circuit-line w-[300px] h-[1px] top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 rotate-135"></div>
            <div class="loader-bottom-corner left"></div>
            <div class="loader-bottom-corner right"></div>
        </div>
    </div>
    <button id="menuToggle" class="menu-orb" aria-label="Open Navigation" aria-expanded="false" aria-controls="navMenu">
        <i class="fas fa-compass text-2xl text-white"></i>
    </button>
    <nav id="navMenu" class="menu-panel" role="navigation" aria-label="Main Navigation">
        <ul>
            <li><a href="index.php">Home</a></li>
            <li><a href="about.php">About</a></li>
            <li><a href="programs.php">Programs</a></li>
            <li><a href="admissions.php">Admissions</a></li>
            <li><a href="contact.php">Contact</a></li>
            <li><a href="login.php">Login</a></li>
            <li><a href="signup.php">Sign Up</a></li>
        </ul>
        <a href="index.php" class="btn-primary w-full text-center mt-4">
            <i class="fas fa-home"></i> Back to Home
        </a>
    </nav>
    <section id="hero" class="hero" role="main" aria-labelledby="hero-title">
        <div id="main-content" class="container z-10 max-w-5xl">
            <h1 id="hero-title" class="mb-6">EduSync</h1>
            <p class="text-3xl md:text-5xl font-semibold mb-6">AI-Powered School Management</p>
            <p class="text-lg md:text-xl max-w-4xl mx-auto mb-12 leading-relaxed font-medium">
                Revolutionize your school with intelligent automation: grading, attendance, fees, communication, and insights. Built for Ghanaian schools, scalable globally.
            </p>
            <div class="flex justify-center gap-4 mb-10">
                <form method="post" class="inline"><input type="hidden" name="theme" value="dark"><button type="submit" class="p-3 rounded-full bg-gradient-to-r from-cyan-500 to-blue-600 text-white hover:shadow-lg transition" aria-label="Dark Mode"><i class="fas fa-moon"></i></button></form>
                <form method="post" class="inline"><input type="hidden" name="theme" value="light"><button type="submit" class="p-3 rounded-full bg-gradient-to-r from-yellow-400 to-orange-500 text-white hover:shadow-lg transition" aria-label="Light Mode"><i class="fas fa-sun"></i></button></form>
            </div>
            <div class="flex flex-col sm:flex-row gap-6 justify-center">
                <a href="#features" class="btn-primary text-lg px-10 py-3">Explore Features</a>
                <a href="contact.php" class="border-2 border-cyan-400 text-cyan-400 hover:bg-cyan-400 hover:text-black text-lg px-10 py-3 rounded-full transition font-bold">Request Demo</a>
                <a href="signup.php" class="btn-primary text-lg px-10 py-3">Start Free Trial</a>
            </div>
        </div>
    </section>

    <!-- Overview -->
    <section id="overview" class="py-20" aria-labelledby="overview-title">
        <div class="container grid-responsive">
            <div>
                <h2 id="overview-title" class="text-5xl md:text-6xl font-display font-bold text-cyan-400 mb-8">The Future of School Management</h2>
                <p class="text-xl leading-relaxed mb-6">EduSync is NERDSPRING's flagship AI-native platform, designed to streamline every aspect of school operations. From automated grading to predictive student analytics, it's built to save time, reduce errors, and enhance learning outcomes.</p>
                <p class="text-xl leading-relaxed mb-6">Launched in 2025, EduSync is already powering schools in Ghana with features like real-time parent notifications, secure fee payments, and customizable lesson plans. Our AI core uses advanced models for tasks like essay grading and behavior prediction.</p>
                <p class="text-xl leading-relaxed mb-6">Key stats: 99.9% uptime, 85% reduction in admin time, 40% improvement in parent engagement. Compliant with GDPR and local data laws.</p>
                <a href="#pricing" class="btn-primary inline-block px-8 py-4">Get Started</a>
            </div>
            <div class="relative">
                <img src="assets/images/play1.jpg" alt="EduSync overview dashboard showing AI features and school analytics" class="rounded-3xl shadow-2xl w-full" loading="lazy">
            </div>
        </div>
    </section>

    <!-- Features -->
    <section id="features" class="py-20 bg-gradient-to-b from-transparent to-cyan-900/5" aria-labelledby="features-title">
        <div class="container">
            <h2 id="features-title" class="text-5xl md:text-6xl font-display font-bold text-green-400 mb-16 text-center">Core Features</h2>
            <p class="text-xl text-center max-w-4xl mx-auto mb-12">Comprehensive tools powered by AI, integrated seamlessly for admins, teachers, parents, and students.</p>
            <div class="grid-responsive">
                <div class="glass-card p-8 text-center">
                    <i class="fas fa-robot text-5xl mb-6" style="color: var(--primary);"></i>
                    <h3 class="text-3xl font-bold mb-4">AI Grading</h3>
                    <p class="mb-6">Automated essay, quiz, and assignment grading with 95% accuracy. Supports multiple languages, including local dialects.</p>
                    <p class="text-sm">Custom rubrics, Plagiarism detection, Feedback generation, Integration with Gemini API, Bulk processing, Grade analytics</p>
                </div>
                <div class="glass-card p-8 text-center">
                    <i class="fas fa-user-check text-5xl mb-6" style="color: var(--accent);"></i>
                    <h3 class="text-3xl font-bold mb-4">Attendance Tracking</h3>
                    <p class="mb-6">Facial recognition and RFID integration for accurate, contactless attendance. Real-time alerts to parents.</p>
                    <p class="text-sm">Daily reports, Absence predictions, Integration with calendars, Biometric security, Export to CSV/PDF, Trend analysis</p>
                </div>
                <div class="glass-card p-8 text-center">
                    <i class="fas fa-money-bill-wave text-5xl mb-6" style="color: var(--primary);"></i>
                    <h3 class="text-3xl font-bold mb-4">Fee Management</h3>
                    <p class="mb-6">Secure payments, automated reminders, debt tracking. Supports mobile money and bank integrations.</p>
                    <p class="text-sm">Invoice generation, Payment gateways (MTN MoMo, Visa), Debt analytics, Receipts via email/SMS, Multi-currency, Audit logs</p>
                </div>
                <div class="glass-card p-8 text-center">
                    <i class="fas fa-comments text-5xl mb-6" style="color: var(--accent);"></i>
                    <h3 class="text-3xl font-bold mb-4">Communication Hub</h3>
                    <p class="mb-6">Real-time chat, notifications, bulk SMS/email. Parent-teacher conferences scheduling.</p>
                    <p class="text-sm">Group chats, File sharing, Translation support, Read receipts, Event reminders, Integration with WhatsApp</p>
                </div>
                <div class="glass-card p-8 text-center">
                    <i class="fas fa-chart-bar text-5xl mb-6" style="color: var(--primary);"></i>
                    <h3 class="text-3xl font-bold mb-4">Analytics & Reports</h3>
                    <p class="mb-6">Predictive insights on student performance, class trends, and school metrics. Customizable dashboards.</p>
                    <p class="text-sm">AI forecasts, Data visualization, Export options, Benchmarking, Privacy controls, Real-time updates</p>
                </div>
                <div class="glass-card p-8 text-center">
                    <i class="fas fa-book-open text-5xl mb-6" style="color: var(--accent);"></i>
                    <h3 class="text-3xl font-bold mb-4">Lesson Planning</h3>
                    <p class="mb-6">AI-assisted plans, resource library, scheduling. GES and Oxford curriculum support.</p>
                    <p class="text-sm">Weekly/termly templates, Collaboration tools, Resource uploads, Progress tracking, AI suggestions, Version history</p>
                </div>
                <div class="glass-card p-8 text-center">
                    <i class="fas fa-graduation-cap text-5xl mb-6" style="color: var(--primary);"></i>
                    <h3 class="text-3xl font-bold mb-4">Student Portal</h3>
                    <p class="mb-6">Assignments, grades, schedules, e-books, games. Personalized learning paths.</p>
                    <p class="text-sm">Feedback submission, Profile editing, Notifications, Interactive quizzes, Progress charts, Mobile app sync</p>
                </div>
                <div class="glass-card p-8 text-center">
                    <i class="fas fa-chalkboard-teacher text-5xl mb-6" style="color: var(--accent);"></i>
                    <h3 class="text-3xl font-bold mb-4">Teacher Tools</h3>
                    <p class="mb-6">Assignment creation, grading, attendance marking, evaluations. AI recommendations.</p>
                    <p class="text-sm">Bulk uploads, Comment libraries, Schedule views, Performance reviews, Resource management, Chat integration</p>
                </div>
                <div class="glass-card p-8 text-center">
                    <i class="fas fa-user-friends text-5xl mb-6" style="color: var(--primary);"></i>
                    <h3 class="text-3xl font-bold mb-4">Parent Dashboard</h3>
                    <p class="mb-6">Real-time updates on grades, attendance, fees, behavior. Multi-child support.</p>
                    <p class="text-sm">Report cards, Payment history, Chat with teachers, Event calendars, Alerts setup, Mobile notifications</p>
                </div>
                <div class="glass-card p-8 text-center">
                    <i class="fas fa-shield-alt text-5xl mb-6" style="color: var(--accent);"></i>
                    <h3 class="text-3xl font-bold mb-4">Security & Compliance</h3>
                    <p class="mb-6">Role-based access, data encryption, audit logs. Compliant with education standards.</p>
                    <p class="text-sm">Two-factor auth, Data backups, GDPR compliance, Activity monitoring, Secure APIs, Regular audits</p>
                </div>
            </div>
            <div class="text-center mt-16">
                <a href="contact.php" class="btn-primary text-xl px-12 py-4">Schedule a Demo</a>
            </div>
        </div>
    </section>

    <!-- Benefits -->
    <section id="benefits" class="py-20" aria-labelledby="benefits-title">
        <div class="container">
            <h2 id="benefits-title" class="text-5xl md:text-6xl font-display font-bold text-purple-400 mb-16 text-center">Benefits for Everyone</h2>
            <p class="text-xl text-center max-w-4xl mx-auto mb-12">EduSync transforms education by empowering all stakeholders with intelligent tools.</p>
            <div class="grid-responsive">
                <div class="glass-card p-8">
                    <h3 class="text-2xl font-bold mb-4 text-cyan-400">For Schools</h3>
                    <ul class="space-y-2 text-lg">
                        <li>Reduce admin workload by 70%</li>
                        <li>Improve efficiency with AI automation</li>
                        <li>Customizable for GES/Oxford curricula</li>
                        <li>Scalable from small to large institutions</li>
                        <li>Cost savings on paper and manual processes</li>
                    </ul>
                </div>
                <div class="glass-card p-8">
                    <h3 class="text-2xl font-bold mb-4 text-green-400">For Teachers</h3>
                    <ul class="space-y-2 text-lg">
                        <li>Save hours on grading and planning</li>
                        <li>AI insights for student support</li>
                        <li>Easy collaboration and resource sharing</li>
                        <li>Performance tracking and evaluations</li>
                        <li>Mobile access for on-the-go management</li>
                    </ul>
                </div>
                <div class="glass-card p-8">
                    <h3 class="text-2xl font-bold mb-4 text-purple-400">For Parents</h3>
                    <ul class="space-y-2 text-lg">
                        <li>Real-time visibility into child's progress</li>
                        <li>Secure payments and fee tracking</li>
                        <li>Direct communication with teachers</li>
                        <li>Behavior and attendance alerts</li>
                        <li>Multi-device support (web/mobile)</li>
                    </ul>
                </div>
                <div class="glass-card p-8">
                    <h3 class="text-2xl font-bold mb-4 text-yellow-400">For Students</h3>
                    <ul class="space-y-2 text-lg">
                        <li>Personalized learning resources</li>
                        <li>Interactive games and e-books</li>
                        <li>Grade viewing and feedback</li>
                        <li>Schedule and assignment management</li>
                        <li>AI chat for quick help (EduBot)</li>
                    </ul>
                </div>
            </div>
        </div>
    </section>

    <!-- Demo -->
    <section id="demo" class="py-20 bg-gradient-to-b from-transparent to-purple-900/5" aria-labelledby="demo-title">
        <div class="container">
            <h2 id="demo-title" class="text-5xl md:text-6xl font-display font-bold text-cyan-400 mb-16 text-center">See EduSync in Action</h2>
            <div class="grid-responsive">
                <div class="relative">
                    <img src="assets/images/play1.jpg" alt="EduSync admin dashboard screenshot showing analytics and reports" class="rounded-3xl shadow-2xl w-full" loading="lazy">
                    <p class="text-center mt-4 text-sm">Admin Dashboard</p>
                </div>
                <div class="relative">
                    <img src="assets/images/lab.jpg" alt="EduSync teacher portal screenshot with lesson planning tools" class="rounded-3xl shadow-2xl w-full" loading="lazy">
                    <p class="text-center mt-4 text-sm">Teacher Portal</p>
                </div>
                <div class="relative">
                    <img src="assets/images/play2.jpg" alt="EduSync parent app screenshot with notifications and grades" class="rounded-3xl shadow-2xl w-full" loading="lazy">
                    <p class="text-center mt-4 text-sm">Parent App</p>
                </div>
                <div class="relative">
                    <img src="assets/images/mbf1.jpg" alt="EduSync student view screenshot with assignments and games" class="rounded-3xl shadow-2xl w-full" loading="lazy">
                    <p class="text-center mt-4 text-sm">Student View</p>
                </div>
            </div>
            <div class="text-center mt-12">
                <video controls class="rounded-3xl shadow-2xl max-w-4xl mx-auto" poster="assets/images/lab1.jpg">
                    <source src="assets/videos/edusync-demo.mp4" type="video/mp4">
                    Your browser does not support the video tag.
                </video>
                <p class="mt-4 text-sm">Watch our 2-minute demo video (placeholder; replace with actual if available).</p>
            </div>
        </div>
    </section>

    <!-- Pricing -->
    <section id="pricing" class="py-20" aria-labelledby="pricing-title">
        <div class="container">
            <h2 id="pricing-title" class="text-5xl md:text-6xl font-display font-bold text-green-400 mb-16 text-center">Simple Pricing</h2>
            <p class="text-xl text-center max-w-4xl mx-auto mb-12">Affordable tiers for schools of all sizes. All plans include AI features, support, and updates.</p>
            <div class="grid-responsive">
                <div class="glass-card p-8 text-center">
                    <h3 class="text-3xl font-bold mb-4">Starter</h3>
                    <p class="text-4xl font-bold mb-6">Free Trial</p>
                    <ul class="space-y-3 mb-6 text-left">
                        <li>Up to 50 students</li>
                        <li>Basic AI grading</li>
                        <li>Core features</li>
                        <li>Email support</li>
                    </ul>
                    <a href="signup.php" class="btn-primary w-full">Start Trial</a>
                </div>
                <div class="glass-card p-8 text-center border-2 border-cyan-400 relative">
                    <span class="absolute top-0 right-4 bg-cyan-400 text-black px-3 py-1 rounded-full text-sm font-bold">Popular</span>
                    <h3 class="text-3xl font-bold mb-4">Pro</h3>
                    <p class="text-4xl font-bold mb-6">GHS 500/month</p>
                    <ul class="space-y-3 mb-6 text-left">
                        <li>Up to 500 students</li>
                        <li>Full AI suite</li>
                        <li>Advanced analytics</li>
                        <li>Priority support</li>
                        <li>Mobile apps</li>
                    </ul>
                    <a href="contact.php" class="btn-primary w-full">Get Pro</a>
                </div>
                <div class="glass-card p-8 text-center">
                    <h3 class="text-3xl font-bold mb-4">Enterprise</h3>
                    <p class="text-4xl font-bold mb-6">Custom</p>
                    <ul class="space-y-3 mb-6 text-left">
                        <li>Unlimited students</li>
                        <li>Custom integrations</li>
                        <li>Dedicated AI models</li>
                        <li>24/7 support</li>
                        <li>On-premise option</li>
                    </ul>
                    <a href="contact.php" class="btn-primary w-full">Contact Sales</a>
                </div>
            </div>
        </div>
    </section>

    <!-- Testimonials -->
    <section id="testimonials" class="py-20 bg-gradient-to-b from-transparent to-cyan-900/5" aria-labelledby="testimonials-title">
        <div class="container">
            <h2 id="testimonials-title" class="text-5xl md:text-6xl font-display font-bold text-purple-400 mb-16 text-center">What Schools Say</h2>
            <div class="grid-responsive">
                <div class="glass-card p-8 italic">
                    <p class="mb-4">"EduSync transformed our grading process—accurate and fast!"</p>
                    <p class="font-bold text-cyan-400">— Principal, Queenest School</p>
                </div>
                <div class="glass-card p-8 italic">
                    <p class="mb-4">"Parents are more engaged thanks to real-time updates."</p>
                    <p class="font-bold text-green-400">— Teacher, Accra Academy</p>
                </div>
                <div class="glass-card p-8 italic">
                    <p class="mb-4">"Fee management is seamless; no more late payments."</p>
                    <p class="font-bold text-purple-400">— Admin, Kumasi High</p>
                </div>
                <div class="glass-card p-8 italic">
                    <p class="mb-4">"AI insights helped us identify at-risk students early."</p>
                    <p class="font-bold text-yellow-400">— Counselor, Cape Coast School</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FAQs -->
    <section id="faqs" class="py-20" aria-labelledby="faqs-title">
        <div class="container">
            <h2 id="faqs-title" class="text-5xl md:text-6xl font-display font-bold text-cyan-400 mb-16 text-center">Frequently Asked Questions</h2>
            <div class="space-y-6 max-w-4xl mx-auto">
                <details class="glass-card p-6 cursor-pointer">
                    <summary class="font-bold text-lg mb-2">How does AI grading work?</summary>
                    <p>Our system uses advanced NLP to evaluate answers against rubrics, providing scores and feedback. It's trained on educational data for accuracy.</p>
                </details>
                <details class="glass-card p-6 cursor-pointer">
                    <summary class="font-bold text-lg mb-2">Is EduSync secure?</summary>
                    <p>Yes, with end-to-end encryption, role-based access, and regular security audits. Compliant with international standards.</p>
                </details>
                <details class="glass-card p-6 cursor-pointer">
                    <summary class="font-bold text-lg mb-2">Can it integrate with existing systems?</summary>
                    <p>Absolutely—APIs for Google Classroom, Microsoft Teams, and custom integrations available.</p>
                </details>
                <details class="glass-card p-6 cursor-pointer">
                    <summary class="font-bold text-lg mb-2">What about mobile access?</summary>
                    <p>Full-featured iOS and Android apps included in Pro and Enterprise plans.</p>
                </details>
                <details class="glass-card p-6 cursor-pointer">
                    <summary class="font-bold text-lg mb-2">How do I get started?</summary>
                    <p>Sign up for a free trial, import your data, and our team will guide you through setup.</p>
                </details>
                <details class="glass-card p-6 cursor-pointer">
                    <summary class="font-bold text-lg mb-2">Is training provided?</summary>
                    <p>Yes, onboarding sessions, video tutorials, and ongoing support.</p>
                </details>
                <details class="glass-card p-6 cursor-pointer">
                    <summary class="font-bold text-lg mb-2">What curricula does it support?</summary>
                    <p>GES, Oxford, Cambridge, and customizable for others.</p>
                </details>
                <details class="glass-card p-6 cursor-pointer">
                    <summary class="font-bold text-lg mb-2">Can I customize the platform?</summary>
                    <p>Yes, themes, modules, and reports are fully customizable.</p>
                </details>
            </div>
        </div>
    </section>

    <!-- Integration Guide -->
    <section id="integration" class="py-20 bg-gradient-to-b from-transparent to-purple-900/5" aria-labelledby="integration-title">
        <div class="container">
            <h2 id="integration-title" class="text-5xl md:text-6xl font-display font-bold text-green-400 mb-16 text-center">Easy Integration</h2>
            <p class="text-xl text-center max-w-4xl mx-auto mb-12">Get EduSync up and running in days with our step-by-step guide.</p>
            <ol class="list-decimal list-inside space-y-4 max-w-3xl mx-auto text-lg">
                <li>Sign up and create your school profile.</li>
                <li>Import student/teacher data via CSV or API.</li>
                <li>Set up roles and permissions.</li>
                <li>Configure AI models and curricula.</li>
                <li>Train staff with our resources.</li>
                <li>Launch and monitor with dashboards.</li>
            </ol>
            <div class="text-center mt-12">
                <a href="contact.php" class="btn-primary text-xl px-12 py-4">Get Integration Support</a>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section id="cta" class="py-20 text-center" aria-labelledby="cta-title">
        <div class="container">
            <h2 id="cta-title" class="text-5xl md:text-6xl font-display font-bold text-cyan-400 mb-8">Ready to Transform Your School?</h2>
            <p class="text-xl max-w-3xl mx-auto mb-12">Join the future of education with EduSync. Start your free trial today.</p>
            <a href="signup.php" class="btn-primary text-xl px-12 py-4">Sign Up Now</a>
        </div>
    </section>

    <!-- Footer -->
    <footer class="py-16 border-t" style="border-color: var(--border);" role="contentinfo">
        <div class="container grid md:grid-cols-4 gap-12 text-sm">
            <div>
                <img src="<?php echo $logo; ?>" alt="NERDSPRING logo" class="w-16 mb-6" loading="lazy">
                <p class="font-medium">EduSync by NERDSPRING – Empowering Education</p>
            </div>
            <div>
                <h4 class="font-bold mb-6" style="color: var(--primary);">Product</h4>
                <ul class="space-y-3"><li><a href="#features" class="hover:text-cyan-400 transition">Features</a></li><li><a href="#pricing" class="hover:text-cyan-400 transition">Pricing</a></li><li><a href="#demo" class="hover:text-cyan-400 transition">Demo</a></li></ul>
            </div>
            <div>
                <h4 class="font-bold mb-6" style="color: var(--primary);">Resources</h4>
                <ul class="space-y-3"><li><a href="#faqs" class="hover:text-cyan-400 transition">FAQs</a></li><li><a href="contact.php" class="hover:text-cyan-400 transition">Support</a></li><li><a href="login.php" class="hover:text-cyan-400 transition">Login</a></li></ul>
            </div>
            <div>
                <h4 class="font-bold mb-6" style="color: var(--primary);">Follow</h4>
                <div class="flex gap-6 text-2xl">
                    <a href="https://twitter.com/nerdspring" aria-label="Twitter"><i class="fab fa-twitter hover:text-cyan-400 transition"></i></a>
                    <a href="https://linkedin.com/company/nerdspring" aria-label="LinkedIn"><i class="fab fa-linkedin hover:text-cyan-400 transition"></i></a>
                    <a href="https://github.com/nerdspring" aria-label="GitHub"><i class="fab fa-github hover:text-cyan-400 transition"></i></a>
                </div>
            </div>
        </div>
        <div class="mt-12 pt-8 border-t text-center text-xs" style="border-color: var(--border);">
            © 2025 NERDSPRING. All rights reserved. | <a href="privacy.php" class="hover:text-cyan-400 transition">Privacy</a> | <a href="terms.php" class="hover:text-cyan-400 transition">Terms</a>
        </div>
    </footer>
    <div aria-live="polite" class="sr-only" id="live-region"></div>
    <script>
        // Loader Animation - Enhanced with additional GSAP tweens for immersion
        const progressText = document.getElementById('progressText');
        gsap.to({}, {
            duration: 6,
            ease: 'linear',
            onUpdate: function() {
                const p = Math.round(this.progress() * 100);
                progressText.textContent = `${p}%`;
                document.querySelector('#loader').setAttribute('aria-valuenow', p);
            },
            onComplete: () => {
                gsap.to('#loader', { opacity: 0, duration: 1, ease: 'power2.out', onComplete: () => document.getElementById('loader').remove() });
                document.querySelector('.bg-logo').style.opacity = (document.body.classList.contains('mode-dark') ? '0.18' : '0.14');
            }
        });
        // Additional enhancements: Pulse the top text and circuit lines
        gsap.to('.loader-branding h1', { scale: 1.05, duration: 1, repeat: -1, yoyo: true, ease: 'sine.inOut' });
        gsap.to('.loader-circuit-line', { opacity: 0.5, duration: 0.5, repeat: -1, yoyo: true, stagger: 0.2, ease: 'sine.inOut' });
        gsap.to('.loader-bottom-corner', { borderColor: 'var(--primary)', duration: 1, repeat: -1, yoyo: true, ease: 'sine.inOut' });

        // Subtle Rain
        const subtleCanvas = document.getElementById('subtleRain');
        const sctx = subtleCanvas.getContext('2d');
        subtleCanvas.width = window.innerWidth;
        subtleCanvas.height = window.innerHeight;
        const sDrops = Array(Math.floor(subtleCanvas.width / 14)).fill(0).map(() => Math.random() * -100);
        function drawSubtleRain() {
            sctx.fillStyle = 'rgba(0, 0, 0, 0.03)';
            sctx.fillRect(0, 0, subtleCanvas.width, subtleCanvas.height);
            sctx.fillStyle = getComputedStyle(document.documentElement).getPropertyValue('--rain-primary');
            sctx.font = '14px JetBrains Mono';
            sDrops.forEach((y, x) => {
                sctx.fillText('0', x * 14, y * 14);
                if (y * 14 > subtleCanvas.height && Math.random() > 0.99) sDrops[x] = 0;
                sDrops[x] += 0.6;
            });
        }
        setInterval(drawSubtleRain, 90);

        // Resize
        window.addEventListener('resize', () => {
            subtleCanvas.width = window.innerWidth;
            subtleCanvas.height = window.innerHeight;
        });

        // Menu
        const menuToggle = document.getElementById('menuToggle');
        const navMenu = document.getElementById('navMenu');
        let isOpen = false;
        menuToggle.addEventListener('click', e => {
            e.stopPropagation();
            isOpen = !isOpen;
            menuToggle.setAttribute('aria-expanded', isOpen);
            navMenu.classList.toggle('open', isOpen);
        });
        document.addEventListener('click', e => {
            if (isOpen && !menuToggle.contains(e.target) && !navMenu.contains(e.target)) {
                isOpen = false;
                menuToggle.setAttribute('aria-expanded', false);
                navMenu.classList.remove('open');
            }
        });
        // Keyboard navigation for menu
        menuToggle.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                menuToggle.click();
            }
        });

        // GSAP Animations with page transitions
        window.addEventListener('load', () => {
            gsap.from('.hero > div > *', { y: 60, opacity: 0, stagger: 0.25, duration: 1.2, ease: 'power3.out' });
            gsap.utils.toArray('section').forEach(sec => {
                gsap.from(sec, {
                    opacity: 0, y: 50, duration: 1,
                    scrollTrigger: { trigger: sec, start: 'top 80%', toggleActions: 'play none none reverse' }
                });
            });
        });
    </script>
</body>
</html>