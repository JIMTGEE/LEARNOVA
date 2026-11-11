<?php
/**
 * programs.php - NERDSPRING Training Programs (2025 ULTRA EDITION)
 * • FULLY DETAILED: 6+ Programs (Web, Mobile, AI, UI/UX, School Integration, Bootcamps)
 * • INCLUDES: School Tech Integration Program (teaching programming in schools)
 * • UI 100% MATCHED TO index.php: Dual-theme, GSAP animations, glass cards, rain effects, orb menu, responsive
 * • DYNAMIC: Mock data (replace with DB later), SEO, ARIA, mobile-first
 * • LOGO: white-logo.png (dark) / blue-logo.png (light)
 * • LOADER: Reuses index.php rain formation + logo morph
 * • COPY-PASTE READY – NO EDITS NEEDED
 *
 * @author Nerdspring Team
 * @version 3.7.1
 * @since 2025-11-17
 */
declare(strict_types=1);
session_start();
const ASSET_PATH = 'assets/images/';
const LOGO_WHITE = ASSET_PATH . 'white-logo.png';
const LOGO_BLUE = ASSET_PATH . 'blue-logo.png';
const DEFAULT_THEME = 'dark';

function getCurrentTheme(): string {
    return $_SESSION['theme'] ?? DEFAULT_THEME;
}
function setTheme(string $theme): void {
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
    <title>Training Programs | NERDSPRING – Learn Web, Mobile, AI & Teach in Schools</title>
    <meta name="description" content="NERDSPRING offers intensive tech training: Full-Stack Web, Mobile Apps, AI, UI/UX, and our unique School Integration Program — teaching programming to students in Ghanaian schools.">
    <meta name="keywords" content="tech training, web development course, mobile app bootcamp, AI training Ghana, UI/UX design, teach coding in schools, Nerdspring programs">
    <meta name="author" content="NERDSPRING Team">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://nerdspring.com/programs.php">

    <!-- Open Graph -->
    <meta property="og:title" content="NERDSPRING Training Programs">
    <meta property="og:description" content="Master Web, Mobile, AI, and bring coding to schools. 85% job placement.">
    <meta property="og:image" content="https://nerdspring.com/assets/images/white-logo.png">
    <meta property="og:url" content="https://nerdspring.com/programs.php">
    <meta property="og:type" content="website">

    <!-- Twitter -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="NERDSPRING Training Programs">
    <meta name="twitter:description" content="From bootcamps to school tech integration — we train and empower.">
    <meta name="twitter:image" content="https://nerdspring.com/assets/images/white-logo.png">

    <!-- Structured Data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "EducationalOrganization",
      "name": "NERDSPRING Training Academy",
      "url": "https://nerdspring.com/programs",
      "logo": "https://nerdspring.com/assets/images/white-logo.png",
      "description": "Hands-on tech training programs with 85% job placement. Includes unique school integration initiative.",
      "offers": [
        {"@type": "EducationalOccupationalProgram", "name": "Full-Stack Web Development", "duration": "P12W", "occupationalCredentialAwarded": "Certificate"},
        {"@type": "EducationalOccupationalProgram", "name": "Mobile App Mastery (Flutter)", "duration": "P10W"},
        {"@type": "EducationalOccupationalProgram", "name": "AI & Data Science", "duration": "P14W"},
        {"@type": "EducationalOccupationalProgram", "name": "UI/UX Design Mastery", "duration": "P8W"},
        {"@type": "EducationalOccupationalProgram", "name": "School Tech Integration", "description": "Train teachers to teach coding in schools"}
      ]
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
                        'converge': 'converge 3s ease-out forwards'
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
                        }
                    }
                }
            }
        }
    </script>

    <!-- GSAP -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js" defer></script>

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
            display: flex; align-items: center; justify-content: center; flex-direction: column; transition: opacity 1.5s ease;
            overflow: hidden; perspective: 1000px;
        }
        #loader::before {
            content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(transparent, rgba(0,212,255,0.05), transparent);
            animation: scanline 4s linear infinite; z-index: 1;
        }
        #loader canvas { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
        #loader .logo-container { position: relative; width: 220px; height: 220px; margin-bottom: 2rem; z-index: 2; }
        #loader .logo { width: 100%; height: 100%; object-fit: contain; clip-path: inset(100% 0 0 0); filter: drop-shadow(0 0 40px var(--primary)); }
        #loader .logo.morphed { animation: logoMorph 2.5s ease-out forwards; }
        #loader .boot-text { font-family: 'JetBrains Mono'; font-size: 1.1rem; letter-spacing: 3px; color: var(--primary); opacity: 0; z-index: 2; }
        #loader .boot-text.active { animation: bootSequence 3s steps(20) forwards; opacity: 1; }
        #loader .glitch-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-family: 'Orbitron'; font-size: 2rem; color: var(--primary); animation: glitch 1s linear infinite alternate-reverse; z-index: 2; }
        .rain-drop {
            position: absolute; font-family: 'JetBrains Mono'; font-size: 16px; color: var(--primary); opacity: 0;
            animation: converge 3s ease-out forwards;
            pointer-events: none;
        }
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
        section { padding: 6rem 1rem; }
        h2 { font-family: 'Orbitron'; font-size: clamp(2.5rem, 7vw, 4.5rem); text-align: center; margin-bottom: 3rem; color: var(--primary); font-weight: 900; }
        .grid-responsive { display: grid; gap: 2.2rem; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }
        p, li, .text-sm { color: var(--text-muted); font-weight: 500; }
        h3 { color: var(--text); font-weight: 700; }
        #subtleRain { position: fixed; top: 0; left: 0; width: 100%; height: 100%; z-index: -1; pointer-events: none; }
        .program-badge { display: inline-block; padding: 0.5rem 1rem; border-radius: 9999px; font-size: 0.8rem; font-weight: 700; }
        .badge-web { background: linear-gradient(135deg, #00d4ff, #0099cc); }
        .badge-mobile { background: linear-gradient(135deg, #39ff14, #22c55e); }
        .badge-ai { background: linear-gradient(135deg, #9d00ff, #7c3aed); }
        .badge-ux { background: linear-gradient(135deg, #ff6b6b, #ee5a24); }
        .badge-school { background: linear-gradient(135deg, #ffd93d, #ff8c00); }
        @media (max-width: 640px) {
            .hero { padding: 2rem 1rem; }
            section { padding: 4rem 1rem; }
            .glass-card { padding: 1.8rem; }
            .menu-panel { width: 260px; }
        }
    </style>
</head>
<body class="mode-<?php echo $theme; ?>">
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="bg-logo" aria-hidden="true"></div>
    <canvas id="subtleRain" aria-hidden="true"></canvas>

    <!-- LOADER (RAIN → "Programs" → LOGO) -->
    <div id="loader">
        <canvas id="loaderRain" aria-hidden="true"></canvas>
        <div id="rainContainer"></div>
        <div class="logo-container">
            <img src="<?php echo $logo; ?>" alt="NERDSPRING Logo" class="logo" id="loaderLogo">
        </div>
        <p class="boot-text" id="bootText">TRAINING MODULES LOADING...</p>
        <div class="glitch-text" id="glitchText">PROGRAMS</div>
    </div>

    <!-- ORB MENU -->
    <button id="menuToggle" class="menu-orb" aria-label="Open Navigation" aria-expanded="false" aria-controls="navMenu">
        <i class="fas fa-compass text-2xl text-white"></i>
    </button>
    <nav id="navMenu" class="menu-panel" role="navigation" aria-label="Main Navigation">
        <ul>
            <li><a href="index.php">Home</a></li>
            <li><a href="index.php#about">About</a></li>
            <li><a href="index.php#services">Services</a></li>
            <li><a href="programs.php">Programs</a></li>
            <li><a href="index.php#team">Team</a></li>
            <li><a href="edusync.php">EduSync</a></li>
            <li><a href="contact.php">Contact</a></li>
        </ul>
        <a href="application-form.php" class="btn-primary w-full text-center mt-4">
            <i class="fas fa-user-graduate"></i> Apply Now
        </a>
    </nav>

    <!-- HERO -->
    <section id="hero" class="hero" aria-labelledby="hero-title">
        <div id="main-content" class="container z-10 max-w-5xl">
            <h1 id="hero-title" class="mb-6">TRAINING PROGRAMS</h1>
            <p class="text-3xl md:text-5xl font-semibold mb-6">Build. Code. Teach. Lead.</p>
            <p class="text-lg md:text-xl max-w-4xl mx-auto mb-12 leading-relaxed font-medium">
                From intensive bootcamps to teaching programming in schools — we train the next generation of African tech leaders. <strong>85% job placement rate.</strong>
            </p>
            <div class="flex justify-center gap-4 mb-10">
                <form method="post" class="inline"><input type="hidden" name="theme" value="dark"><button type="submit" class="p-3 rounded-full bg-gradient-to-r from-cyan-500 to-blue-600 text-white hover:shadow-lg transition" aria-label="Dark Mode"><i class="fas fa-moon"></i></button></form>
                <form method="post" class="inline"><input type="hidden" name="theme" value="light"><button type="submit" class="p-3 rounded-full bg-gradient-to-r from-yellow-400 to-orange-500 text-white hover:shadow-lg transition" aria-label="Light Mode"><i class="fas fa-sun"></i></button></form>
            </div>
            <div class="flex flex-col sm:flex-row gap-6 justify-center">
                <a href="#programs-grid" class="btn-primary text-lg px-10 py-3">Explore Programs</a>
                <a href="application-form.php" class="border-2 border-cyan-400 text-cyan-400 hover:bg-cyan-400 hover:text-black text-lg px-10 py-3 rounded-full transition font-bold">Apply Now</a>
            </div>
        </div>
    </section>

    <!-- PROGRAMS GRID -->
    <section id="programs" class="py-20" aria-labelledby="programs-title">
        <div class="container">
            <h2 id="programs-title" class="text-5xl md:text-6xl font-display font-bold text-purple-400 mb-16 text-center">Our Programs</h2>
            <p class="text-xl text-center max-w-4xl mx-auto mb-16">Hands-on, project-based learning. Graduate with a portfolio and real-world experience.</p>

            <div class="grid-responsive" id="programs-grid">
                <!-- Full-Stack Web Dev -->
                <div class="glass-card">
                    <div class="program-badge badge-web text-white mb-4">WEB DEV</div>
                    <h3 class="text-2xl font-bold mb-3">Full-Stack Web Development</h3>
                    <p class="text-3xl font-bold text-cyan-400 mb-2">12 Weeks</p>
                    <p class="mb-4">Master HTML, CSS, JavaScript, React, Node.js, MongoDB, Git, and deployment.</p>
                    <ul class="space-y-2 mb-6 text-sm">
                        <li>✅ 5 real-world projects</li>
                        <li>✅ Portfolio + GitHub</li>
                        <li>✅ Job interview prep</li>
                        <li>✅ Live coding sessions</li>
                    </ul>
                    <a href="application-form.php?program=web" class="btn-primary w-full text-center">Apply Now</a>
                </div>

                <!-- Mobile App Mastery -->
                <div class="glass-card">
                    <div class="program-badge badge-mobile text-white mb-4">MOBILE</div>
                    <h3 class="text-2xl font-bold mb-3">Mobile App Mastery</h3>
                    <p class="text-3xl font-bold text-green-400 mb-2">10 Weeks</p>
                    <p class="mb-4">Build 5 apps with Flutter + Firebase. From idea to Play Store.</p>
                    <ul class="space-y-2 mb-6 text-sm">
                        <li>✅ State management</li>
                        <li>✅ Animations & UX</li>
                        <li>✅ API integration</li>
                        <li>✅ App Store submission</li>
                    </ul>
                    <a href="application-form.php?program=mobile" class="btn-primary w-full text-center">Apply Now</a>
                </div>

                <!-- AI & Data Science -->
                <div class="glass-card">
                    <div class="program-badge badge-ai text-white mb-4">AI/ML</div>
                    <h3 class="text-2xl font-bold mb-3">AI & Data Science</h3>
                    <p class="text-3xl font-bold text-purple-400 mb-2">14 Weeks</p>
                    <p class="mb-4">Python, TensorFlow, NLP, computer vision, deployment with FastAPI.</p>
                    <ul class="space-y-2 mb-6 text-sm">
                        <li>✅ Real datasets</li>
                        <li>✅ Kaggle competitions</li>
                        <li>✅ Capstone project</li>
                        <li>✅ ML ops basics</li>
                    </ul>
                    <a href="application-form.php?program=ai" class="btn-primary w-full text-center">Apply Now</a>
                </div>

                <!-- UI/UX Design -->
                <div class="glass-card">
                    <div class="program-badge badge-ux text-white mb-4">DESIGN</div>
                    <h3 class="text-2xl font-bold mb-3">UI/UX Design Mastery</h3>
                    <p class="text-3xl font-bold text-yellow-400 mb-2">8 Weeks</p>
                    <p class="mb-4">Figma, design systems, user research, prototyping, accessibility.</p>
                    <ul class="space-y-2 mb-6 text-sm">
                        <li>✅ 3 client-ready designs</li>
                        <li>✅ User testing</li>
                        <li>✅ Design handoff</li>
                        <li>✅ Portfolio site</li>
                    </ul>
                    <a href="application-form.php?program=ux" class="btn-primary w-full text-center">Apply Now</a>
                </div>

                <!-- School Tech Integration -->
                <div class="glass-card">
                    <div class="program-badge badge-school text-black mb-4">EDUCATION</div>
                    <h3 class="text-2xl font-bold mb-3">School Tech Integration</h3>
                    <p class="text-3xl font-bold text-orange-400 mb-2">6 Months</p>
                    <p class="mb-4">Train teachers to teach coding in schools. Deploy Scratch, Python, and robotics.</p>
                    <ul class="space-y-2 mb-6 text-sm">
                        <li>✅ 50+ schools reached</li>
                        <li>✅ Curriculum + lesson plans</li>
                        <li>✅ Hardware kits</li>
                        <li>✅ Teacher certification</li>
                    </ul>
                    <a href="contact.php?interest=school" class="btn-primary w-full text-center">Partner with Us</a>
                </div>

                <!-- Weekend Bootcamp -->
                <div class="glass-card">
                    <div class="program-badge badge-web text-white mb-4">BOOTCAMP</div>
                    <h3 class="text-2xl font-bold mb-3">Weekend Code Bootcamp</h3>
                    <p class="text-3xl font-bold text-cyan-400 mb-2">Every Saturday</p>
                    <p class="mb-4">Beginner-friendly. HTML, CSS, JS basics. Perfect for students and professionals.</p>
                    <ul class="space-y-2 mb-6 text-sm">
                        <li>✅ 4 hours/session</li>
                        <li>✅ Build 1 project</li>
                        <li>✅ Free for first 50</li>
                        <li>✅ Certificate</li>
                    </ul>
                    <a href="application-form.php?program=bootcamp" class="btn-primary w-full text-center">Register</a>
                </div>
            </div>

            <div class="text-center mt-16">
                <p class="text-2xl font-bold mb-6">All programs include:</p>
                <div class="flex flex-wrap justify-center gap-6 text-sm">
                    <span class="px-4 py-2 bg-cyan-900/30 rounded-full">Mentorship</span>
                    <span class="px-4 py-2 bg-green-900/30 rounded-full">Internship Pipeline</span>
                    <span class="px-4 py-2 bg-purple-900/30 rounded-full">Lifetime Access</span>
                    <span class="px-4 py-2 bg-yellow-900/30 rounded-full">Job Board</span>
                </div>
            </div>
        </div>
    </section>

    <!-- STATS -->
    <section class="py-20 bg-gradient-to-b from-transparent to-cyan-900/5">
        <div class="container text-center">
            <h2 class="text-5xl md:text-6xl font-display font-bold text-green-400 mb-16">By the Numbers</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-8">
                <div>
                    <p class="text-6xl font-bold text-cyan-400">1,200+</p>
                    <p class="text-lg">Graduates</p>
                </div>
                <div>
                    <p class="text-6xl font-bold text-green-400">85%</p>
                    <p class="text-lg">Job Placement</p>
                </div>
                <div>
                    <p class="text-6xl font-bold text-purple-400">50+</p>
                    <p class="text-lg">Partner Schools</p>
                </div>
                <div>
                    <p class="text-6xl font-bold text-yellow-400">4.9/5</p>
                    <p class="text-lg">Student Rating</p>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="py-16 border-t" style="border-color: var(--border);" role="contentinfo">
        <div class="container grid md:grid-cols-4 gap-12 text-sm">
            <div>
                <img src="<?php echo $logo; ?>" alt="NERDSPRING" class="w-16 mb-6" loading="lazy">
                <p class="font-medium">Training the Future of African Tech</p>
            </div>
            <div>
                <h4 class="font-bold mb-6" style="color: var(--primary);">Programs</h4>
                <ul class="space-y-3"><li><a href="#programs-grid" class="hover:text-cyan-400">All Programs</a></li><li><a href="application-form.php" class="hover:text-cyan-400">Apply</a></li></ul>
            </div>
            <div>
                <h4 class="font-bold mb-6" style="color: var(--primary);">Company</h4>
                <ul class="space-y-3"><li><a href="index.php#about" class="hover:text-cyan-400">About</a></li><li><a href="contact.php" class="hover:text-cyan-400">Contact</a></li></ul>
            </div>
            <div>
                <h4 class="font-bold mb-6" style="color: var(--primary);">Follow</h4>
                <div class="flex gap-6 text-2xl">
                    <a href="#" aria-label="Twitter"><i class="fab fa-twitter hover:text-cyan-400"></i></a>
                    <a href="#" aria-label="LinkedIn"><i class="fab fa-linkedin hover:text-cyan-400"></i></a>
                    <a href="#" aria-label="GitHub"><i class="fab fa-github hover:text-cyan-400"></i></a>
                </div>
            </div>
        </div>
        <div class="mt-12 pt-8 border-t text-center text-xs" style="border-color: var(--border);">
            © 2025 NERDSPRING. All rights reserved.
        </div>
    </footer>

    <script>
        // === RAIN DROPS CONVERGE TO FORM "Programs" ===
        const loaderRain = document.getElementById('loaderRain');
        const ctx = loaderRain.getContext('2d');
        const rainContainer = document.getElementById('rainContainer');
        loaderRain.width = window.innerWidth;
        loaderRain.height = window.innerHeight;

        const word = "Programs";
        const fontSize = 48;
        const letterWidth = 44;
        const startX = (loaderRain.width - word.length * letterWidth) / 2;
        const targetY = loaderRain.height / 2;

        const drops = [];
        const chars = '01{}[]<>/\\|PROGRAMSAI';

        for (let i = 0; i < 800; i++) {
            const x = Math.random() * loaderRain.width;
            const y = Math.random() * -loaderRain.height;
            const speed = 1 + Math.random() * 3;
            const char = chars[Math.floor(Math.random() * chars.length)];
            drops.push({ x, y, speed, char, targetX: 0, targetY: 0, isLetter: false });
        }

        word.split('').forEach((char, i) => {
            const targetX = startX + i * letterWidth;
            for (let j = 0; j < 15; j++) {
                const drop = drops[Math.floor(Math.random() * drops.length)];
                if (!drop.isLetter) {
                    drop.char = char;
                    drop.targetX = targetX + (Math.random() - 0.5) * 20;
                    drop.targetY = targetY + (Math.random() - 0.5) * 20;
                    drop.isLetter = true;
                    drop.convergeStart = 3000 + i * 150;
                }
            }
        });

        let frame = 0;
        function drawRain() {
            ctx.fillStyle = 'rgba(0, 0, 0, 0.05)';
            ctx.fillRect(0, 0, loaderRain.width, loaderRain.height);

            drops.forEach(drop => {
                const now = performance.now();
                if (drop.isLetter && now > drop.convergeStart) {
                    drop.x += (drop.targetX - drop.x) * 0.05;
                    drop.y += (drop.targetY - drop.y) * 0.05;
                } else {
                    drop.y += drop.speed;
                    if (drop.y > loaderRain.height) drop.y = -50;
                }

                ctx.font = `${fontSize}px 'JetBrains Mono'`;
                ctx.fillStyle = `hsl(${(frame + drop.x) % 360}, 100%, 70%)`;
                ctx.fillText(drop.char, drop.x, drop.y);
            });
            frame++;
        }

        const rainInterval = setInterval(drawRain, 30);

        setTimeout(() => document.getElementById('loaderLogo').classList.add('morphed'), 600);
        setTimeout(() => document.getElementById('bootText').classList.add('active'), 1200);
        setTimeout(() => document.getElementById('glitchText').style.display = 'none', 2800);

        setTimeout(() => {
            clearInterval(rainInterval);
            const loader = document.getElementById('loader');
            loader.style.opacity = '0';
            setTimeout(() => {
                loader.remove();
                document.querySelector('.bg-logo').style.opacity = (document.body.classList.contains('mode-dark') ? '0.18' : '0.14');
            }, 1500);
        }, 7000);

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
            const w = window.innerWidth, h = window.innerHeight;
            loaderRain.width = subtleCanvas.width = w;
            loaderRain.height = subtleCanvas.height = h;
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

        // GSAP
        window.addEventListener('load', () => {
            gsap.from('.hero > div > *', { y: 60, opacity: 0, stagger: 0.25, duration: 1.2 });
            gsap.utils.toArray('section').forEach(sec => {
                gsap.from(sec.children, {
                    y: 50, opacity: 0, duration: 1, stagger: 0.2,
                    scrollTrigger: { trigger: sec, start: 'top 80%', toggleActions: 'play none none reverse' }
                });
            });
        });
    </script>
</body>
</html>