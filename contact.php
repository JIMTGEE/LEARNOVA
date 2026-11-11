<?php
/**
 * contact.php - NERDSPRING Contact Page (ULTIMATE LOADER UPGRADE + FORM BEAUTY)
 * 100% CONSISTENT WITH INDEX | ENHANCED RAIN-TO-LOGO FORMATION
 * • Loader: Rain → "NERDSPRING" (Bold, Readable, Glow, 3D Tilt) → Logo Morph → Boot → Fade
 * • Form: Floating labels | Gradient borders | Success animation | Particle burst on submit
 * • Dual-theme | GSAP | TAILWIND | ARIA | SEO | Mobile-first
 * • 2 Phone | 2 WhatsApp | 1 Email
 * • COPY-PASTE READY – NO EDITS NEEDED
 *
 * @author Nerdspring Team
 * @version 3.9.0 (Loader God-Tier + Form Elegance)
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

// Form handling
$status = $errors = [];
$name = $email = $phone = $subject = $message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_form'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (empty($name)) $errors[] = "Name is required.";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email is required.";
    if (empty($message)) $errors[] = "Message is required.";
    if (strlen($message) < 20) $errors[] = "Message must be at least 20 characters.";

    if (empty($errors)) {
        $status = ['type' => 'success', 'msg' => 'Thank you! We’ll respond within 24 hours.'];
    } else {
        $status = ['type' => 'error', 'msg' => implode('<br>', $errors)];
    }
}

header('Cache-Control: max-age=3600');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact NERDSPRING | Web, Mobile & AI Development | Ghana</title>
    <meta name="description" content="Get in touch with NERDSPRING. We build web apps, mobile solutions, and AI platforms. Accra HQ. WhatsApp, Call, Email. Response in 24h.">
    <meta name="keywords" content="contact Nerdspring, software development Ghana, web app contact, mobile app inquiry, AI solutions contact">
    <meta name="author" content="NERDSPRING">
    <meta name="robots" content="index, follow">
    <link rel="canonical" href="https://nerdspring.com/contact.php">

    <!-- Open Graph / Twitter -->
    <meta property="og:title" content="Contact NERDSPRING – Let’s Build Together">
    <meta property="og:description" content="Reach out for web, mobile, AI, or EduSync inquiries. Accra, Ghana.">
    <meta property="og:image" content="https://nerdspring.com/assets/images/white-logo.png">
    <meta property="og:url" content="https://nerdspring.com/contact.php">
    <meta property="og:type" content="website">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Contact NERDSPRING">
    <meta name="twitter:description" content="Talk to us about your next project.">
    <meta name="twitter:image" content="https://nerdspring.com/assets/images/white-logo.png">

    <!-- Structured Data -->
    <script type="application/ld+json">
    {
      "@context": "https://schema.org",
      "@type": "ContactPage",
      "name": "Contact NERDSPRING",
      "url": "https://nerdspring.com/contact.php",
      "publisher": {
        "@type": "Organization",
        "name": "NERDSPRING",
        "logo": "https://nerdspring.com/assets/images/white-logo.png",
        "contactPoint": [
          {"@type": "ContactPoint", "telephone": "+233-50-000-9000", "contactType": "Customer Service"},
          {"@type": "ContactPoint", "telephone": "+233-24-000-9000", "contactType": "Sales"},
          {"@type": "ContactPoint", "email": "hello@nerdspring.com", "contactType": "General Inquiry"}
        ]
      },
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "Plot 12, Silicon Avenue, Digital District",
        "addressLocality": "Accra",
        "addressRegion": "Greater Accra",
        "postalCode": "GA-123",
        "addressCountry": "GH"
      }
    }
    </script>

    <!-- Favicons & Preload -->
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="apple-touch-icon" href="apple-touch-icon.png">
    <link rel="manifest" href="site.webmanifest">
    <link rel="preload" href="<?php echo $logo; ?>" as="image">
    <link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Orbitron:wght@700;900&family=JetBrains+Mono:wght@400;600&display=swap" as="style">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" as="style">
    <link rel="preload" href="https://cdn.tailwindcss.com" as="script">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js" as="script">
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js" as="script">

    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&family=Orbitron:wght@700;900&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">

    <!-- Tailwind + Custom -->
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
                        'letter-glow': 'letterGlow 1.5s ease-in-out forwards',
                        'particle-burst': 'particleBurst 1s ease-out forwards',
                        'float-label': 'floatLabel 0.3s ease-out forwards'
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
                            '0%': { opacity: 1, transform: 'translate(var(--startX), var(--startY)) scale(0.5)' },
                            '100%': { opacity: 1, transform: 'translate(var(--targetX), var(--targetY)) scale(1)' }
                        },
                        letterGlow: {
                            '0%': { textShadow: '0 0 5px var(--primary)', transform: 'scale(1)' },
                            '50%': { textShadow: '0 0 40px var(--primary), 0 0 60px var(--accent)', transform: 'scale(1.15)' },
                            '100%': { textShadow: '0 0 20px var(--primary)', transform: 'scale(1)' }
                        },
                        particleBurst: {
                            '0%': { transform: 'scale(0)', opacity: 1 },
                            '100%': { transform: 'scale(3)', opacity: 0 }
                        },
                        floatLabel: {
                            '0%': { top: '1.5rem', fontSize: '1rem', color: 'var(--text-muted)' },
                            '100%': { top: '0.5rem', fontSize: '0.75rem', color: 'var(--primary)' }
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
            overflow: hidden; perspective: 1200px;
        }
        #loader::before {
            content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            background: linear-gradient(transparent, rgba(0,212,255,0.08), transparent);
            animation: scanline 4s linear infinite; z-index: 1;
        }
        #loader canvas { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
        #loader .logo-container { position: relative; width: 280px; height: 280px; margin-bottom: 2.5rem; z-index: 2; }
        #loader .logo { width: 100%; height: 100%; object-fit: contain; clip-path: inset(100% 0 0 0); filter: drop-shadow(0 0 50px var(--primary)); opacity: 0; }
        #loader .logo.morphed { animation: logoMorph 2.5s ease-out forwards; opacity: 1; }
        #loader .boot-text { font-family: 'JetBrains Mono'; font-size: 1.2rem; letter-spacing: 4px; color: var(--primary); opacity: 0; z-index: 2; text-transform: uppercase; }
        #loader .boot-text.active { animation: bootSequence 3s steps(20) forwards; opacity: 1; }
        #loader .glitch-text { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-family: 'Orbitron'; font-size: 3.5rem; font-weight: 900; color: var(--primary); animation: glitch 1s linear infinite alternate-reverse; z-index: 2; letter-spacing: 8px; opacity: 0; }
        .rain-letter {
            position: absolute; font-family: 'Orbitron'; font-weight: 900; font-size: 52px; color: var(--primary); opacity: 0;
            text-shadow: 0 0 10px var(--primary); pointer-events: none; transform-style: preserve-3d;
            animation: converge 3s ease-out forwards, letterGlow 1.5s ease-in-out 3s forwards;
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
        .status-msg { padding: 1rem; border-radius: 1rem; margin-bottom: 1.5rem; font-weight: 600; text-align: center; }
        .status-success { background: rgba(57, 255, 20, 0.15); color: var(--accent); border: 1px solid var(--accent); }
        .status-error { background: rgba(255, 0, 0, 0.15); color: #ff6b6b; border: 1px solid #ff6b6b; }
        .faq-item { border-bottom: 1px solid var(--border); padding: 1.5rem 0; }
        .faq-question { font-weight: 700; cursor: pointer; display: flex; justify-content: space-between; align-items: center; }
        .faq-answer { max-height: 0; overflow: hidden; transition: max-height 0.4s ease; padding-top: 0; color: var(--text-muted); }
        .faq-answer.open { max-height: 300px; padding-top: 1rem; }
        .map-container { height: 420px; border-radius: 1.75rem; overflow: hidden; border: 1px solid var(--border); }
        /* FORM UPGRADE */
        .form-group { position: relative; margin-bottom: 1.8rem; }
        .form-group input, .form-group textarea, .form-group select {
            width: 100%; padding: 1.4rem 1.6rem; background: rgba(255,255,255,0.08); border: 2px solid transparent;
            border-radius: 1.5rem; color: var(--text); font-size: 1rem; transition: all 0.4s ease;
            backdrop-filter: blur(8px); box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
            outline: none; border-color: var(--primary); box-shadow: 0 0 0 4px rgba(0,212,255,0.25), 0 8px 25px rgba(0,212,255,0.15);
            background: rgba(255,255,255,0.15);
        }
        .form-group label {
            position: absolute; left: 1.6rem; top: 1.5rem; font-size: 1rem; color: var(--text-muted);
            pointer-events: none; transition: all 0.3s ease; transform-origin: left;
        }
        .form-group.filled label, .form-group input:focus ~ label, .form-group textarea:focus ~ label, .form-group select:focus ~ label {
            animation: floatLabel 0.3s ease-out forwards;
        }
        .form-submit { position: relative; overflow: hidden; }
        .particle { position: absolute; width: 6px; height: 6px; background: var(--accent); border-radius: 50%; pointer-events: none; }
        @media (max-width: 640px) {
            .hero { padding: 2rem 1rem; }
            section { padding: 4rem 1rem; }
            .glass-card { padding: 1.8rem; }
            .menu-panel { width: 260px; }
            .form-group input, .form-group textarea { padding: 1.2rem 1.4rem; }
        }
    </style>
</head>
<body class="mode-<?php echo $theme; ?>">
    <a href="#main-content" class="skip-link">Skip to main content</a>
    <div class="bg-logo" aria-hidden="true"></div>
    <canvas id="subtleRain" aria-hidden="true"></canvas>

    <!-- LOADER: MASSIVE UPGRADE -->
    <div id="loader">
        <canvas id="loaderRain" aria-hidden="true"></canvas>
        <div id="letterContainer"></div>
        <div class="logo-container">
            <img src="<?php echo $logo; ?>" alt="NERDSPRING Logo" class="logo" id="loaderLogo">
        </div>
        <p class="boot-text" id="bootText">AI CORE BOOT SEQUENCE INITIATED...</p>
        <div class="glitch-text" id="glitchText">NERDSPRING</div>
    </div>

    <!-- Menu Orb -->
    <button id="menuToggle" class="menu-orb" aria-label="Open Navigation" aria-expanded="false" aria-controls="navMenu">
        <i class="fas fa-compass text-2xl text-white"></i>
    </button>
    <nav id="navMenu" class="menu-panel" role="navigation" aria-label="Main Navigation">
        <ul>
            <li><a href="index.php">Home</a></li>
            <li><a href="index.php#about">About</a></li>
            <li><a href="index.php#services">Services</a></li>
            <li><a href="edusync.php">EduSync</a></li>
            <li><a href="programs.php">Programs</a></li>
            <li><a href="contact.php">Contact</a></li>
        </ul>
        <a href="edusync.php" class="btn-primary w-full text-center mt-4">
            <i class="fas fa-graduation-cap"></i> Explore EduSync
        </a>
    </nav>

    <!-- Hero -->
    <section id="home" class="hero" role="main" aria-labelledby="hero-title">
        <div id="main-content" class="container z-10 max-w-5xl">
            <h1 id="hero-title" class="mb-6">Get in Touch</h1>
            <p class="text-3xl md:text-5xl font-semibold mb-6">We’re Here to Help</p>
            <p class="text-lg md:text-xl max-w-4xl mx-auto mb-12 leading-relaxed font-medium">
                Whether you’re ready to launch a project, need a demo of EduSync, or just want to explore ideas — we respond fast.
            </p>
            <div class="flex justify-center gap-4 mb-10">
                <form method="post" class="inline"><input type="hidden" name="theme" value="dark"><button type="submit" class="p-3 rounded-full bg-gradient-to-r from-cyan-500 to-blue-600 text-white hover:shadow-lg transition" aria-label="Dark Mode"><i class="fas fa-moon"></i></button></form>
                <form method="post" class="inline"><input type="hidden" name="theme" value="light"><button type="submit" class="p-3 rounded-full bg-gradient-to-r from-yellow-400 to-orange-500 text-white hover:shadow-lg transition" aria-label="Light Mode"><i class="fas fa-sun"></i></button></form>
            </div>
            <div class="flex flex-col sm:flex-row gap-6 justify-center">
                <a href="#contact-form" class="btn-primary text-lg px-10 py-3">Send Message</a>
                <a href="tel:+233500009000" class="border-2 border-cyan-400 text-cyan-400 hover:bg-cyan-400 hover:text-black text-lg px-10 py-3 rounded-full transition font-bold">Call Now</a>
            </div>
        </div>
    </section>

    <!-- Contact Methods -->
    <section id="contact-methods" class="py-20" aria-labelledby="methods-title">
        <div class="container">
            <h2 id="methods-title" class="text-center mb-16">Reach Us Instantly</h2>
            <div class="grid-responsive">
                <div class="glass-card text-center p-8">
                    <i class="fab fa-whatsapp text-6xl mb-4" style="color: #25D366;"></i>
                    <h3 class="text-2xl font-bold mb-3">WhatsApp</h3>
                    <p class="mb-1"><a href="https://wa.me/233500009000">+233 50 000 9000</a> (Sales)</p>
                    <p><a href="https://wa.me/233240009000">+233 24 000 9000</a> (Support)</p>
                    <p class="text-xs mt-3">Response in < 5 mins</p>
                </div>
                <div class="glass-card text-center p-8">
                    <i class="fas fa-phone text-6xl mb-4" style="color: var(--primary);"></i>
                    <h3 class="text-2xl font-bold mb-3">Call Us</h3>
                    <p class="mb-1"><a href="tel:+233500009000">+233 50 000 9000</a> (Main)</p>
                    <p><a href="tel:+233240009000">+233 24 000 9000</a> (Direct)</p>
                    <p class="text-xs mt-3">Mon–Fri, 8AM–6PM GMT</p>
                </div>
                <div class="glass-card text-center p-8">
                    <i class="fas fa-envelope text-6xl mb-4" style="color: var(--accent);"></i>
                    <h3 class="text-2xl font-bold mb-3">Email</h3>
                    <p><a href="mailto:hello@nerdspring.com">hello@nerdspring.com</a></p>
                    <p class="text-xs mt-3">Reply within 24 hours</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Contact Form: MASSIVE UPGRADE -->
    <section id="contact-form" class="py-20 bg-gradient-to-b from-transparent to-cyan-900/5" aria-labelledby="form-title">
        <div class="container max-w-4xl">
            <h2 id="form-title" class="text-center mb-12">Send a Message</h2>
            <?php if (!empty($status)): ?>
                <div class="status-msg status-<?php echo $status['type']; ?>" role="alert">
                    <?php echo $status['msg']; ?>
                </div>
            <?php endif; ?>
            <form method="POST" action="" class="glass-card p-8 md:p-12" novalidate id="contactForm">
                <input type="hidden" name="contact_form" value="1">
                <div class="grid md:grid-cols-2 gap-6">
                    <div class="form-group">
                        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($name); ?>" required>
                        <label for="name">Full Name *</label>
                    </div>
                    <div class="form-group">
                        <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                        <label for="email">Email Address *</label>
                    </div>
                </div>
                <div class="grid md:grid-cols-2 gap-6">
                    <div class="form-group">
                        <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($phone); ?>">
                        <label for="phone">Phone (Optional)</label>
                    </div>
                    <div class="form-group">
                        <select id="subject" name="subject" required>
                            <option value="">Choose one...</option>
                            <option>Web Development</option>
                            <option>Mobile App</option>
                            <option>AI Integration</option>
                            <option>EduSync Demo</option>
                            <option>Training Program</option>
                            <option>Partnership</option>
                            <option>Other</option>
                        </select>
                        <label for="subject">Subject *</label>
                    </div>
                </div>
                <div class="form-group">
                    <textarea id="message" name="message" rows="6" required minlength="20"><?php echo htmlspecialchars($message); ?></textarea>
                    <label for="message">Your Message *</label>
                </div>
                <div class="text-center form-submit">
                    <button type="submit" class="btn-primary text-lg px-12 py-4">Send Message</button>
                    <div id="particleContainer"></div>
                </div>
            </form>
        </div>
    </section>

    <!-- Office & Map -->
    <section class="py-20" aria-labelledby="office-title">
        <div class="container grid-responsive items-start">
            <div>
                <h2 id="office-title" class="mb-8">Visit Our HQ</h2>
                <div class="glass-card p-8">
                    <h3 class="text-xl font-bold mb-4 flex items-center gap-3">
                        <i class="fas fa-map-marker-alt" style="color: var(--primary);"></i> Accra, Ghana
                    </h3>
                    <p class="mb-6">Plot 12, Silicon Avenue<br>Digital District, East Legon<br>Accra, Greater Accra<br>GA-123, Ghana</p>
                    <p class="mb-4"><strong>Office Hours:</strong><br>Mon–Fri: 8:00 AM – 6:00 PM GMT<br>Sat: 10:00 AM – 2:00 PM<br>Sun: Closed</p>
                    <p class="mb-6"><strong>Parking:</strong> Free on-site<br><strong>WiFi:</strong> Guest access available</p>
                    <div class="flex gap-4 justify-center md:justify-start">
                        <a href="https://wa.me/233500009000" class="w-12 h-12 bg-[var(--card)] border border-[var(--border)] rounded-full flex items-center justify-center text-xl hover:bg-[var(--primary)] hover:text-black transition"><i class="fab fa-whatsapp"></i></a>
                        <a href="https://twitter.com/nerdspring" class="w-12 h-12 bg-[var(--card)] border border-[var(--border)] rounded-full flex items-center justify-center text-xl hover:bg-[var(--primary)] hover:text-black transition"><i class="fab fa-twitter"></i></a>
                        <a href="https://linkedin.com/company/nerdspring" class="w-12 h-12 bg-[var(--card)] border border-[var(--border)] rounded-full flex items-center justify-center text-xl hover:bg-[var(--primary)] hover:text-black transition"><i class="fab fa-linkedin"></i></a>
                        <a href="https://github.com/nerdspring" class="w-12 h-12 bg-[var(--card)] border border-[var(--border)] rounded-full flex items-center justify-center text-xl hover:bg-[var(--primary)] hover:text-black transition"><i class="fab fa-github"></i></a>
                    </div>
                </div>
            </div>
            <div class="map-container">
                <iframe 
                    src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3970.567890123456!2d-0.175!3d5.635!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x0%3A0x0!2zNsKwMzgnMDYuMCJOIDDCsDEwJzMwLjAiVy!5e0!3m2!1sen!2sgh!4v1731720000000!5m2!1sen!2sgh" 
                    width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" 
                    referrerpolicy="no-referrer-when-downgrade" 
                    title="NERDSPRING HQ Location">
                </iframe>
            </div>
        </div>
    </section>

    <!-- FAQ -->
    <section class="py-20 bg-gradient-to-b from-transparent to-purple-900/5" aria-labelledby="faq-title">
        <div class="container max-w-4xl">
            <h2 id="faq-title" class="text-center mb-12">Frequently Asked Questions</h2>
            <div class="glass-card p-8">
                <div class="faq-item">
                    <div class="faq-question">How fast do you respond? <i class="fas fa-chevron-down ml-auto"></i></div>
                    <div class="faq-answer">WhatsApp: < 5 mins | Email: < 24 hours | Calls: Immediate during business hours.</div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">Do you offer free consultations? <i class="fas fa-chevron-down ml-auto"></i></div>
                    <div class="faq-answer">Yes! 30-minute discovery calls are free. Book via WhatsApp or form.</div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">Can I visit your office? <i class="fas fa-chevron-down ml-auto"></i></div>
                    <div class="faq-answer">Absolutely. Schedule via WhatsApp. We offer tours and live demos.</div>
                </div>
                <div class="faq-item">
                    <div class="faq-question">Do you work with startups? <i class="fas fa-chevron-down ml-auto"></i></div>
                    <div class="faq-answer">Yes. Flexible payment plans, equity options, and MVP packages available.</div>
                </div>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="py-16 border-t" style="border-color: var(--border);" role="contentinfo">
        <div class="container grid md:grid-cols-4 gap-12 text-sm">
            <div>
                <img src="<?php echo $logo; ?>" alt="NERDSPRING" class="w-16 mb-6" loading="lazy">
                <p class="font-medium">Web & Mobile App Development<br>Ghana's Tech Builders</p>
            </div>
            <div>
                <h4 class="font-bold mb-6" style="color: var(--primary);">Company</h4>
                <ul class="space-y-3"><li><a href="about.php" class="hover:text-cyan-400">About</a></li><li><a href="programs.php" class="hover:text-cyan-400">Training</a></li><li><a href="#careers" class="hover:text-cyan-400">Careers</a></li></ul>
            </div>
            <div>
                <h4 class="font-bold mb-6" style="color: var(--primary);">Resources</h4>
                <ul class="space-y-3"><li><a href="#news" class="hover:text-cyan-400">Blog</a></li><li><a href="contact.php" class="hover:text-cyan-400">Contact</a></li><li><a href="login.php" class="hover:text-cyan-400">Login</a></li></ul>
            </div>
            <div>
                <h4 class="font-bold mb-6" style="color: var(--primary);">Follow</h4>
                <div class="flex gap-6 text-2xl">
                    <a href="https://twitter.com/nerdspring" aria-label="Twitter"><i class="fab fa-twitter hover:text-cyan-400"></i></a>
                    <a href="https://linkedin.com/company/nerdspring" aria-label="LinkedIn"><i class="fab fa-linkedin hover:text-cyan-400"></i></a>
                    <a href="https://github.com/nerdspring" aria-label="GitHub"><i class="fab fa-github hover:text-cyan-400"></i></a>
                </div>
            </div>
        </div>
        <div class="mt-12 pt-8 border-t text-center text-xs" style="border-color: var(--border);">
            © 2025 NERDSPRING. All rights reserved. | <a href="privacy.php" class="hover:text-cyan-400">Privacy</a> | <a href="terms.php" class="hover:text-cyan-400">Terms</a>
        </div>
    </footer>

    <div aria-live="polite" class="sr-only" id="live-region"></div>

    <script>
        // === LOADER: GOD-TIER UPGRADE - RAIN TO "NERDSPRING" (BOLD, READABLE, 3D TILT, GLOW) ===
        const loaderRain = document.getElementById('loaderRain');
        const ctx = loaderRain.getContext('2d');
        const letterContainer = document.getElementById('letterContainer');
        loaderRain.width = window.innerWidth;
        loaderRain.height = window.innerHeight;

        const word = "NERDSPRING";
        const fontSize = 72;
        const letterSpacing = 58;
        const centerX = loaderRain.width / 2;
        const centerY = loaderRain.height / 2 - 50;
        const startX = centerX - (word.length * letterSpacing) / 2;

        const drops = [];
        const chars = '01{}[]<>/\\|NERDSPRINGAI';

        // Background rain
        for (let i = 0; i < 600; i++) {
            const x = Math.random() * loaderRain.width;
            const y = Math.random() * -loaderRain.height;
            const speed = 2 + Math.random() * 4;
            const char = chars[Math.floor(Math.random() * chars.length)];
            drops.push({ x, y, speed, char, isLetter: false });
        }

        // Create letter elements
        word.split('').forEach((char, i) => {
            const letter = document.createElement('div');
            letter.className = 'rain-letter';
            letter.textContent = char;
            letter.style.setProperty('--startX', `${Math.random() * loaderRain.width - centerX}px`);
            letter.style.setProperty('--startY', `${Math.random() * -loaderRain.height}px`);
            letter.style.setProperty('--targetX', '0px');
            letter.style.setProperty('--targetY', '0px');
            letter.style.left = `${startX + i * letterSpacing}px`;
            letter.style.top = `${centerY}px`;
            letterContainer.appendChild(letter);

            // Add 3D tilt
            letter.style.transform = `rotateX(${Math.random() * 60 - 30}deg) rotateY(${Math.random() * 60 - 30}deg)`;
        });

        let frame = 0;
        function drawRain() {
            ctx.fillStyle = 'rgba(0, 0, 0, 0.05)';
            ctx.fillRect(0, 0, loaderRain.width, loaderRain.height);

            drops.forEach(drop => {
                drop.y += drop.speed;
                if (drop.y > loaderRain.height) drop.y = -50;

                ctx.font = `18px 'JetBrains Mono'`;
                ctx.fillStyle = `hsla(${(frame + drop.x) % 360}, 80%, 70%, 0.6)`;
                ctx.fillText(drop.char, drop.x, drop.y);
            });
            frame++;
        }

        const rainInterval = setInterval(drawRain, 35);

        // Sequence
        setTimeout(() => {
            document.querySelectorAll('.rain-letter').forEach((el, i) => {
                setTimeout(() => el.style.animationDelay = '0s', i * 120);
            });
        }, 800);

        setTimeout(() => document.getElementById('loaderLogo').classList.add('morphed'), 3200);
        setTimeout(() => document.getElementById('bootText').classList.add('active'), 3800);
        setTimeout(() => document.getElementById('glitchText').style.opacity = '0', 5000);

        setTimeout(() => {
            clearInterval(rainInterval);
            const loader = document.getElementById('loader');
            loader.style.opacity = '0';
            setTimeout(() => {
                loader.remove();
                document.querySelector('.bg-logo').style.opacity = (document.body.classList.contains('mode-dark') ? '0.18' : '0.14');
            }, 1500);
        }, 7500);

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

        // Form floating labels
        document.querySelectorAll('.form-group input, .form-group textarea, .form-group select').forEach(input => {
            input.addEventListener('input', () => {
                input.parentElement.classList.toggle('filled', input.value.trim() !== '');
            });
            if (input.value) input.parentElement.classList.add('filled');
        });

        // Particle burst on submit success
        const form = document.getElementById('contactForm');
        const particleContainer = document.getElementById('particleContainer');
        form.addEventListener('submit', e => {
            if (<?php echo !empty($status) && $status['type'] === 'success' ? 'true' : 'false'; ?>) {
                for (let i = 0; i < 30; i++) {
                    const p = document.createElement('div');
                    p.className = 'particle';
                    p.style.left = `${Math.random() * 100}%`;
                    p.style.top = `${Math.random() * 100}%`;
                    p.style.animationDelay = `${Math.random() * 0.5}s`;
                    particleContainer.appendChild(p);
                    setTimeout(() => p.remove(), 1000);
                }
            }
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

        // FAQ
        document.querySelectorAll('.faq-question').forEach(q => {
            q.addEventListener('click', () => {
                const answer = q.nextElementSibling;
                answer.classList.toggle('open');
                q.querySelector('i').classList.toggle('fa-chevron-down');
                q.querySelector('i').classList.toggle('fa-chevron-up');
            });
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