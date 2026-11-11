<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up for EduSync | Prince Seed × NerdSpring</title>
    <meta name="description" content="Register your school with EduSync – the AI-powered school management system transforming education in Ghana. Start your free trial today.">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- GSAP -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/gsap.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.5/ScrollTrigger.min.js"></script>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    
    <!-- Tailwind Config -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui'] },
                    colors: {
                        prince: {
                            50: '#f0f9ff', 100: '#e0f2fe', 200: '#bae6fd', 300: '#7dd3fc',
                            400: '#38bdf8', 500: '#0ea5e9', 600: '#0284c7', 700: '#0369a1',
                            800: '#1e40af', 900: '#1e3a8a', 950: '#172554'
                        },
                        nerd: {
                            500: '#10b981', 600: '#059669', 700: '#047857'
                        }
                    },
                    animation: {
                        'fade-up': 'fadeUp 0.8s ease-out forwards',
                        'float': 'float 6s ease-in-out infinite',
                        'pulse-slow': 'pulse 4s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'glow': 'glow 2s ease-in-out infinite alternate',
                        'slide-in': 'slideIn 1s ease-out forwards',
                        'bounce': 'bounce 2s infinite',
                        'rotate-slow': 'rotate 20s linear infinite',
                        'scale-pulse': 'scalePulse 2s ease-in-out infinite',
                        'spin-slow': 'spin 3s linear infinite',
                        'wiggle': 'wiggle 1s ease-in-out infinite',
                        'expand': 'expand 0.6s ease-out forwards',
                        'fade-in-left': 'fadeInLeft 1s ease-out forwards',
                        'fade-in-right': 'fadeInRight 1s ease-out forwards'
                    },
                    keyframes: {
                        fadeUp: { '0%': { opacity: 0, transform: 'translateY(30px)' }, '100%': { opacity: 1, transform: 'translateY(0)' } },
                        float: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-20px)' } },
                        glow: { '0%': { boxShadow: '0 0 20px rgba(30, 117, 187, 0.3)' }, '100%': { boxShadow: '0 0 40px rgba(30, 117, 187, 0.6)' } },
                        slideIn: { '0%': { opacity: 0, transform: 'translateX(-50px)' }, '100%': { opacity: 1, transform: 'translateX(0)' } },
                        bounce: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-10px)' } },
                        rotate: { '0%': { transform: 'rotate(0deg)' }, '100%': { transform: 'rotate(360deg)' } },
                        scalePulse: { '0%, 100%': { transform: 'scale(1)' }, '50%': { transform: 'scale(1.05)' } },
                        wiggle: { '0%, 100%': { transform: 'rotate(-3deg)' }, '50%': { transform: 'rotate(3deg)' } },
                        expand: { '0%': { transform: 'scale(0.8)', opacity: 0 }, '100%': { transform: 'scale(1)', opacity: 1 } },
                        fadeInLeft: { '0%': { opacity: 0, transform: 'translateX(-100px)' }, '100%': { opacity: 1, transform: 'translateX(0)' } },
                        fadeInRight: { '0%': { opacity: 0, transform: 'translateX(100px)' }, '100%': { opacity: 1, transform: 'translateX(0)' } }
                    }
                }
            }
        }
    </script>
    
    <style>
        :root { --prince-blue: #1e3a8a; --prince-light: #0ea5e9; --nerd-green: #10b981; }
        body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1f2937; }
        html { scroll-behavior: smooth; }
        ::-webkit-scrollbar { width: 10px; } 
        ::-webkit-scrollbar-track { background: #f1f5f9; } 
        ::-webkit-scrollbar-thumb { background: linear-gradient(#1e3a8a, #0ea5e9); border-radius: 5px; }
        
        .hero-gradient { background: linear-gradient(135deg, #1e3a8a 0%, #0ea5e9 50%, #10b981 100%); }
        .card-hover { transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275); }
        .card-hover:hover { transform: translateY(-12px) scale(1.02); box-shadow: 0 25px 50px -12px rgba(30,58,138,0.25); }
        .btn-glow { position: relative; overflow: hidden; }
        .btn-glow::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent); transition: 0.6s; }
        .btn-glow:hover::before { left: 100%; }
        .section-title { position: relative; display: inline-block; }
        .section-title::after { content: ''; position: absolute; bottom: -8px; left: 50%; transform: translateX(-50%); width: 80px; height: 4px; background: linear-gradient(to right, #1e3a8a, #0ea5e9); border-radius: 2px; }
        .floating-icon { animation: float 5s ease-in-out infinite; }
        .ornament { position: absolute; opacity: 0.1; animation: rotate-slow 30s linear infinite; }
        .pulse-bg { animation: scalePulse 5s ease-in-out infinite; }
        #loader { transition: opacity 0.5s; }
        
        .form-input { @apply w-full px-5 py-4 rounded-xl border border-gray-300 focus:border-prince-500 focus:ring-4 focus:ring-prince-100 transition-all duration-300 outline-none text-gray-800 placeholder-gray-400; }
        .form-label { @apply block text-sm font-semibold text-gray-700 mb-2; }
        .form-select { @apply form-input appearance-none bg-white pr-12; }
        .select-arrow { @apply absolute right-4 top-1/2 transform -translate-y-1/2 pointer-events-none text-gray-500; }
        .checkbox-custom { @apply w-5 h-5 rounded border-2 border-prince-400 checked:bg-prince-600 checked:border-prince-600 focus:ring-4 focus:ring-prince-100; }
        .signup-card { background: white; border-radius: 1.5rem; box-shadow: 0 20px 40px rgba(30,58,138,0.08); }
        .step-indicator { @apply w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm transition-all duration-300; }
        .step-active { @apply bg-prince-600 text-white; }
        .step-completed { @apply bg-emerald-600 text-white; }
        .step-inactive { @apply bg-gray-200 text-gray-500; }
        .progress-line { @apply h-1 flex-1 bg-gray-200 transition-all duration-500; }
        .progress-active { @apply bg-prince-600; }
    </style>
</head>
<body class="overflow-x-hidden">

    <!-- Custom Loader -->
    <div id="loader" class="fixed inset-0 bg-hero-gradient flex items-center justify-center z-50">
        <div class="animate-spin rounded-full h-32 w-32 border-t-4 border-b-4 border-white"></div>
    </div>

    <!-- Header/Navigation -->
    <header class="bg-white shadow-lg sticky top-0 z-40">
        <div class="container mx-auto px-4 py-4">
            <div class="flex justify-between items-center">
                <a href="index.php" class="flex items-center gap-3 group">
                    <div class="w-12 h-12 bg-gradient-to-br from-prince-600 to-prince-800 rounded-xl flex items-center justify-center shadow-lg group-hover:scale-110 transition-transform">
                        <i class="fas fa-seedling text-white text-xl"></i>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-prince-900">Prince Seed</h1>
                        <p class="text-xs text-gray-500 -mt-1">× NerdSpring</p>
                    </div>
                </a>
                <nav class="hidden md:flex items-center gap-8">
                    <a href="index.php" class="text-gray-700 hover:text-prince-600 font-medium transition">Home</a>
                    <a href="about.php" class="text-gray-700 hover:text-prince-600 font-medium transition">About</a>
                    <a href="edusync.php" class="text-prince-600 font-bold">EduSync</a>
                    <a href="contact.php" class="text-gray-700 hover:text-prince-600 font-medium transition">Contact</a>
                    <a href="login.php" class="btn-glow bg-prince-600 text-white px-6 py-2 rounded-full font-bold text-sm hover:bg-prince-700 transition-all duration-300">
                        Login
                    </a>
                </nav>
                <button class="md:hidden text-prince-900" id="mobile-menu-btn">
                    <i class="fas fa-bars text-2xl"></i>
                </button>
            </div>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="py-24 bg-gradient-to-br from-prince-50 to-emerald-50 relative overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="ornament w-96 h-96 bg-prince-600 rounded-full blur-3xl absolute -top-48 -left-48"></div>
            <div class="ornament w-80 h-80 bg-emerald-600 rounded-full blur-3xl absolute -bottom-40 -right-40"></div>
        </div>
        <div class="container mx-auto px-4 relative z-10">
            <div class="text-center max-w-4xl mx-auto">
                <h1 class="text-5xl md:text-6xl font-black mb-6 leading-tight">
                    <span class="text-transparent bg-clip-text bg-gradient-to-r from-prince-900 to-prince-600">Join EduSync</span>
                    <br><span class="text-emerald-600">Transform Your School</span>
                </h1>
                <p class="text-xl text-gray-700 mb-8 max-w-3xl mx-auto">
                    Register your institution today and unlock the future of education management. Streamline operations, engage parents, and empower teachers – all in one intelligent platform.
                </p>
                <div class="flex flex-wrap justify-center gap-4 mb-12">
                    <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-full shadow-md">
                        <i class="fas fa-check text-emerald-600"></i>
                        <span class="text-sm font-medium">Free 30-Day Trial</span>
                    </div>
                    <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-full shadow-md">
                        <i class="fas fa-check text-emerald-600"></i>
                        <span class="text-sm font-medium">No Credit Card Required</span>
                    </div>
                    <div class="flex items-center gap-2 bg-white px-4 py-2 rounded-full shadow-md">
                        <i class="fas fa-check text-emerald-600"></i>
                        <span class="text-sm font-medium">Setup in 5 Minutes</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Multi-Step Signup Form -->
    <section class="py-24 bg-white">
        <div class="container mx-auto px-4">
            <div class="max-w-5xl mx-auto">
                <!-- Progress Indicator -->
                <div class="flex items-center justify-center mb-16">
                    <div class="flex items-center w-full max-w-3xl">
                        <div class="step-indicator step-completed">1</div>
                        <div class="progress-line progress-active mx-2"></div>
                        <div class="step-indicator step-active">2</div>
                        <div class="progress-line mx-2"></div>
                        <div class="step-indicator step-inactive">3</div>
                    </div>
                </div>

                <div class="signup-card p-8 md:p-12">
                    <form id="signupForm" action="process-signup.php" method="POST" class="space-y-8">
                        <!-- Step 1: School Information -->
                        <div class="step" id="step1">
                            <h2 class="text-3xl font-bold mb-8 text-center section-title">School Information</h2>
                            <div class="grid md:grid-cols-2 gap-6">
                                <div>
                                    <label for="schoolName" class="form-label">School Name <span class="text-red-500">*</span></label>
                                    <input type="text" id="schoolName" name="school_name" class="form-input" placeholder="e.g., Accra International School" required>
                                </div>
                                <div>
                                    <label for="schoolType" class="form-label">School Type <span class="text-red-500">*</span></label>
                                    <div class="relative">
                                        <select id="schoolType" name="school_type" class="form-select" required>
                                            <option value="">Select type</option>
                                            <option value="primary">Primary School</option>
                                            <option value="jhs">Junior High School</option>
                                            <option value="shs">Senior High School</option>
                                            <option value="basic">Basic School (Primary + JHS)</option>
                                            <option value="tertiary">Tertiary Institution</option>
                                            <option value="international">International School</option>
                                            <option value="private">Private Institution</option>
                                            <option value="public">Public School</option>
                                        </select>
                                        <i class="fas fa-chevron-down select-arrow"></i>
                                    </div>
                                </div>
                                <div>
                                    <label for="studentCount" class="form-label">Approximate Student Enrollment <span class="text-red-500">*</span></label>
                                    <input type="number" id="studentCount" name="student_count" class="form-input" placeholder="e.g., 450" min="1" required>
                                </div>
                                <div>
                                    <label for="staffCount" class="form-label">Number of Staff <span class="text-red-500">*</span></label>
                                    <input type="number" id="staffCount" name="staff_count" class="form-input" placeholder="e.g., 65" min="1" required>
                                </div>
                                <div class="md:col-span-2">
                                    <label for="schoolAddress" class="form-label">School Address <span class="text-red-500">*</span></label>
                                    <input type="text" id="schoolAddress" name="school_address" class="form-input" placeholder="e.g., GA-143-8977, Spintex Road, Accra" required>
                                </div>
                                <div>
                                    <label for="region" class="form-label">Region in Ghana <span class="text-red-500">*</span></label>
                                    <div class="relative">
                                        <select id="region" name="region" class="form-select" required>
                                            <option value="">Select region</option>
                                            <option value="greater-accra">Greater Accra</option>
                                            <option value="ashanti">Ashanti</option>
                                            <option value="central">Central</option>
                                            <option value="eastern">Eastern</option>
                                            <option value="western">Western</option>
                                            <option value="volta">Volta</option>
                                            <option value="upper-east">Upper East</option>
                                            <option value="upper-west">Upper West</option>
                                            <option value="northern">Northern</option>
                                            <option value="north-east">North East</option>
                                            <option value="savannah">Savannah</option>
                                            <option value="bono">Bono</option>
                                            <option value="bono-east">Bono East</option>
                                            <option value="ahofo">Ahafo</option>
                                            <option value="oti">Oti</option>
                                            <option value="western-north">Western North</option>
                                        </select>
                                        <i class="fas fa-chevron-down select-arrow"></i>
                                    </div>
                                </div>
                                <div>
                                    <label for="schoolPhone" class="form-label">School Contact Number <span class="text-red-500">*</span></label>
                                    <input type="tel" id="schoolPhone" name="school_phone" class="form-input" placeholder="+233 54 289 2450" pattern="[\+]?[0-9\s\-\(\)]{10,15}" required>
                                </div>
                            </div>
                            <div class="flex justify-end mt-10">
                                <button type="button" class="btn-glow bg-prince-600 text-white px-8 py-3 rounded-full font-bold hover:bg-prince-700 transition-all duration-300 flex items-center gap-2" onclick="nextStep(1)">
                                    Next Step <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Step 2: Admin Details -->
                        <div class="step hidden" id="step2">
                            <h2 class="text-3xl font-bold mb-8 text-center section-title">Administrator Details</h2>
                            <div class="grid md:grid-cols-2 gap-6">
                                <div>
                                    <label for="adminFirstName" class="form-label">First Name <span class="text-red-500">*</span></label>
                                    <input type="text" id="adminFirstName" name="admin_first_name" class="form-input" placeholder="e.g., Kwame" required>
                                </div>
                                <div>
                                    <label for="adminLastName" class="form-label">Last Name <span class="text-red-500">*</span></label>
                                    <input type="text" id="adminLastName" name="admin_last_name" class="form-input" placeholder="e.g., Mensah" required>
                                </div>
                                <div>
                                    <label for="adminRole" class="form-label">Your Role <span class="text-red-500">*</span></label>
                                    <div class="relative">
                                        <select id="adminRole" name="admin_role" class="form-select" required>
                                            <option value="">Select role</option>
                                            <option value="headmaster">Headmaster/Headmistress</option>
                                            <option value="principal">Principal</option>
                                            <option value="administrator">School Administrator</option>
                                            <option value="ict-coordinator">ICT Coordinator</option>
                                            <option value="bursar">Bursar/Accountant</option>
                                            <option value="proprietor">Proprietor/Owner</option>
                                            <option value="director">Director</option>
                                            <option value="other">Other</option>
                                        </select>
                                        <i class="fas fa-chevron-down select-arrow"></i>
                                    </div>
                                </div>
                                <div>
                                    <label for="adminPhone" class="form-label">Your Phone Number <span class="text-red-500">*</span></label>
                                    <input type="tel" id="adminPhone" name="admin_phone" class="form-input" placeholder="+233 24 123 4567" pattern="[\+]?[0-9\s\-\(\)]{10,15}" required>
                                </div>
                                <div class="md:col-span-2">
                                    <label for="adminEmail" class="form-label">Email Address <span class="text-red-500">*</span></label>
                                    <input type="email" id="adminEmail" name="admin_email" class="form-input" placeholder="admin@yourschool.edu.gh" required>
                                    <p class="text-xs text-gray-500 mt-2">We'll send login credentials and setup guide to this email.</p>
                                </div>
                                <div>
                                    <label for="password" class="form-label">Create Password <span class="text-red-500">*</span></label>
                                    <div class="relative">
                                        <input type="password" id="password" name="password" class="form-input pr-12" placeholder="••••••••••" minlength="8" required>
                                        <button type="button" class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-prince-600" onclick="togglePassword('password')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                    <p class="text-xs text-gray-500 mt-2">Minimum 8 characters with letters and numbers.</p>
                                </div>
                                <div>
                                    <label for="confirmPassword" class="form-label">Confirm Password <span class="text-red-500">*</span></label>
                                    <div class="relative">
                                        <input type="password" id="confirmPassword" name="confirm_password" class="form-input pr-12" placeholder="••••••••••" minlength="8" required>
                                        <button type="button" class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-prince-600" onclick="togglePassword('confirmPassword')">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="flex justify-between mt-10">
                                <button type="button" class="btn-glow bg-gray-300 text-gray-700 px-8 py-3 rounded-full font-bold hover:bg-gray-400 transition-all duration-300 flex items-center gap-2" onclick="prevStep(2)">
                                    <i class="fas fa-arrow-left"></i> Previous
                                </button>
                                <button type="button" class="btn-glow bg-prince-600 text-white px-8 py-3 rounded-full font-bold hover:bg-prince-700 transition-all duration-300 flex items-center gap-2" onclick="nextStep(2)">
                                    Next Step <i class="fas fa-arrow-right"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Step 3: Preferences & Submission -->
                        <div class="step hidden" id="step3">
                            <h2 class="text-3xl font-bold mb-8 text-center section-title">Final Details & Submit</h2>
                            
                            <div class="bg-prince-50 rounded-2xl p-6 mb-8">
                                <h3 class="font-bold text-prince-900 mb-4 flex items-center gap-2">
                                    <i class="fas fa-cog text-prince-600"></i> System Preferences
                                </h3>
                                <div class="space-y-4">
                                    <div>
                                        <label class="form-label">Which modules are you most interested in? <span class="text-red-500">*</span></label>
                                        <div class="grid md:grid-cols-2 gap-3">
                                            <label class="flex items-center gap-3 p-3 bg-white rounded-lg border border-gray-200 hover:border-prince-400 cursor-pointer transition">
                                                <input type="checkbox" name="modules[]" value="attendance" class="w-5 h-5 text-prince-600 rounded focus:ring-prince-500">
                                                <span>Attendance Tracking</span>
                                            </label>
                                            <label class="flex items-center gap-3 p-3 bg-white rounded-lg border border-gray-200 hover:border-prince-400 cursor-pointer transition">
                                                <input type="checkbox" name="modules[]" value="fees" class="w-5 h-5 text-prince-600 rounded focus:ring-prince-500">
                                                <span>Fees & Billing</span>
                                            </label>
                                            <label class="flex items-center gap-3 p-3 bg-white rounded-lg border border-gray-200 hover:border-prince-400 cursor-pointer transition">
                                                <input type="checkbox" name="modules[]" value="exams" class="w-5 h-5 text-prince-600 rounded focus:ring-prince-500">
                                                <span>Exams & Grades</span>
                                            </label>
                                            <label class="flex items-center gap-3 p-3 bg-white rounded-lg border border-gray-200 hover:border-prince-400 cursor-pointer transition">
                                                <input type="checkbox" name="modules[]" value="parent-portal" class="w-5 h-5 text-prince-600 rounded focus:ring-prince-500">
                                                <span>Parent Portal</span>
                                            </label>
                                            <label class="flex items-center gap-3 p-3 bg-white rounded-lg border border-gray-200 hover:border-prince-400 cursor-pointer transition">
                                                <input type="checkbox" name="modules[]" value="sms" class="w-5 h-5 text-prince-600 rounded focus:ring-prince-500">
                                                <span>SMS Alerts</span>
                                            </label>
                                            <label class="flex items-center gap-3 p-3 bg-white rounded-lg border border-gray-200 hover:border-prince-400 cursor-pointer transition">
                                                <input type="checkbox" name="modules[]" value="analytics" class="w-5 h-5 text-prince-600 rounded focus:ring-prince-500">
                                                <span>Analytics Dashboard</span>
                                            </label>
                                        </div>
                                    </div>
                                    <div>
                                        <label for="heardAbout" class="form-label">How did you hear about EduSync?</label>
                                        <div class="relative">
                                            <select id="heardAbout" name="heard_about" class="form-select">
                                                <option value="">Select source</option>
                                                <option value="website">Prince Seed Website</option>
                                                <option value="facebook">Facebook</option>
                                                <option value="whatsapp">WhatsApp</option>
                                                <option value="school-visit">School Visit</option>
                                                <option value="colleague">Recommendation</option>
                                                <option value="ges">GES Workshop</option>
                                                <option value="advert">Advertisement</option>
                                                <option value="other">Other</option>
                                            </select>
                                            <i class="fas fa-chevron-down select-arrow"></i>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-emerald-50 rounded-2xl p-6 mb-8">
                                <h3 class="font-bold text-emerald-900 mb-4 flex items-center gap-2">
                                    <i class="fas fa-shield-alt text-emerald-600"></i> Terms & Privacy
                                </h3>
                                <label class="flex items-start gap-3 cursor-pointer">
                                    <input type="checkbox" name="terms" class="mt-1 w-5 h-5 text-emerald-600 rounded focus:ring-emerald-500" required>
                                    <span class="text-sm text-gray-700">
                                        I agree to the <a href="terms.php" class="text-prince-600 hover:underline font-medium">Terms of Service</a> and 
                                        <a href="privacy.php" class="text-prince-600 hover:underline font-medium">Privacy Policy</a>. I understand that 
                                        EduSync will process school data securely in compliance with Ghana's Data Protection Act.
                                    </span>
                                </label>
                            </div>

                            <div class="flex justify-between items-center mt-10">
                                <button type="button" class="btn-glow bg-gray-300 text-gray-700 px-8 py-3 rounded-full font-bold hover:bg-gray-400 transition-all duration-300 flex items-center gap-2" onclick="prevStep(3)">
                                    <i class="fas fa-arrow-left"></i> Previous
                                </button>
                                <button type="submit" class="btn-glow bg-gradient-to-r from-prince-600 to-emerald-600 text-white px-10 py-4 rounded-full font-bold text-lg hover:shadow-2xl hover:shadow-emerald-500/30 transition-all duration-300 flex items-center gap-3">
                                    <i class="fas fa-rocket"></i> Complete Registration
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Success Message (Hidden by default) -->
                <div id="successMessage" class="hidden mt-12 text-center">
                    <div class="inline-flex items-center justify-center w-20 h-20 bg-emerald-100 rounded-full mb-6">
                        <i class="fas fa-check text-4xl text-emerald-600"></i>
                    </div>
                    <h3 class="text-3xl font-bold mb-4">Registration Successful!</h3>
                    <p class="text-lg text-gray-600 mb-6 max-w-2xl mx-auto">
                        Thank you for choosing EduSync. We've sent a confirmation email to <span id="emailDisplay" class="font-semibold text-prince-600"></span> 
                        with your login credentials and next steps. Our team will contact you within 2 hours to begin onboarding.
                    </p>
                    <div class="flex flex-wrap justify-center gap-4">
                        <a href="login.php" class="btn-glow bg-prince-600 text-white px-8 py-3 rounded-full font-bold hover:bg-prince-700 transition">
                            Go to Login
                        </a>
                        <a href="edusync.php" class="btn-glow bg-white text-prince-600 border-2 border-prince-600 px-8 py-3 rounded-full font-bold hover:bg-prince-50 transition">
                            Learn More About EduSync
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Trust Badges -->
    <section class="py-16 bg-gray-50">
        <div class="container mx-auto px-4">
            <div class="text-center mb-12">
                <h3 class="text-2xl font-bold mb-2">Trusted by Leading Ghanaian Institutions</h3>
                <p class="text-gray-600">Join 250+ schools already transforming education with EduSync</p>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-5 gap-8 items-center opacity-70">
                <div class="text-center">
                    <div class="bg-gray-200 border-2 border-dashed rounded-xl w-24 h-16 mx-auto"></div>
                    <p class="text-xs mt-2 text-gray-500">School Name</p>
                </div>
                <div class="text-center">
                    <div class="bg-gray-200 border-2 border-dashed rounded-xl w-24 h-16 mx-auto"></div>
                    <p class="text-xs mt-2 text-gray-500">School Name</p>
                </div>
                <div class="text-center">
                    <div class="bg-gray-200 border-2 border-dashed rounded-xl w-24 h-16 mx-auto"></div>
                    <p class="text-xs mt-2 text-gray-500">School Name</p>
                </div>
                <div class="text-center">
                    <div class="bg-gray-200 border-2 border-dashed rounded-xl w-24 h-16 mx-auto"></div>
                    <p class="text-xs mt-2 text-gray-500">School Name</p>
                </div>
                <div class="text-center">
                    <div class="bg-gray-200 border-2 border-dashed rounded-xl w-24 h-16 mx-auto"></div>
                    <p class="text-xs mt-2 text-gray-500">School Name</p>
                </div>
            </div>
        </div>
    </section>

    <!-- WhatsApp Float Button -->
    <a href="https://wa.me/233542892450" target="_blank" class="fixed bottom-6 right-6 z-50 whatsapp-float">
        <div class="bg-emerald-600 text-white rounded-full w-16 h-16 flex items-center justify-center shadow-2xl hover:bg-emerald-700 transition-all duration-300 animate-pulse">
            <i class="fab fa-whatsapp text-3xl"></i>
        </div>
    </a>

    <!-- Footer -->
    <footer class="bg-gray-900 text-white py-16">
        <div class="container mx-auto px-4">
            <div class="grid md:grid-cols-4 gap-8 mb-12">
                <div class="md:col-span-2">
                    <h3 class="text-2xl font-bold mb-4">Prince Seed × NerdSpring</h3>
                    <p class="text-gray-400 mb-6">Sowing seeds of knowledge, coding paths to brilliance.</p>
                    <div class="flex gap-4">
                        <a href="https://wa.me/233542892450" target="_blank" class="w-10 h-10 bg-emerald-600 rounded-full flex items-center justify-center hover:bg-emerald-500 transition"><i class="fab fa-whatsapp"></i></a>
                        <a href="#" class="w-10 h-10 bg-prince-700 rounded-full flex items-center justify-center hover:bg-prince-600 transition"><i class="fab fa-facebook-f"></i></a>
                        <a href="#" class="w-10 h-10 bg-prince-700 rounded-full flex items-center justify-center hover:bg-prince-600 transition"><i class="fab fa-twitter"></i></a>
                        <a href="#" class="w-10 h-10 bg-emerald-600 rounded-full flex items-center justify-center hover:bg-emerald-500 transition"><i class="fab fa-linkedin-in"></i></a>
                    </div>
                </div>
                <div>
                    <h4 class="font-bold mb-4">Platform</h4>
                    <ul class="space-y-2 text-gray-400">
                        <li><a href="edusync.php" class="hover:text-white transition">EduSync</a></li>
                        <li><a href="pricing.php" class="hover:text-white transition">Pricing</a></li>
                        <li><a href="demo.php" class="hover:text-white transition">Request Demo</a></li>
                        <li><a href="login.php" class="hover:text-white transition">Login</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="font-bold mb-4">Company</h4>
                    <ul class="space-y-2 text-gray-400">
                        <li><a href="about.php" class="hover:text-white transition">About Us</a></li>
                        <li><a href="programs.php" class="hover:text-white transition">Programs</a></li>
                        <li><a href="contact.php" class="hover:text-white transition">Contact</a></li>
                        <li><a href="privacy.php" class="hover:text-white transition">Privacy</a></li>
                    </ul>
                </div>
            </div>
            <div class="border-t border-gray-800 pt-8 text-center text-sm text-gray-500">
                © 2025 Prince Seed Educational Foundation × NerdSpring. All rights reserved.
            </div>
        </div>
    </footer>

    <script>
        // Loader
        window.addEventListener('load', () => {
            gsap.to('#loader', { opacity: 0, duration: 0.5, onComplete: () => document.getElementById('loader').style.display = 'none' });
        });

        // GSAP Animations
        gsap.registerPlugin(ScrollTrigger);
        document.querySelectorAll('section').forEach((section) => {
            gsap.from(section.children, {
                y: 60, opacity: 0, duration: 0.8, stagger: 0.2,
                scrollTrigger: { trigger: section, start: "top 80%", toggleActions: "play none none reverse" }
            });
        });

        // Multi-step form logic
        let currentStep = 1;
        const totalSteps = 3;

        function updateProgress() {
            const progressLines = document.querySelectorAll('.progress-line');
            const steps = document.querySelectorAll('.step-indicator');
            
            steps.forEach((step, index) => {
                if (index < currentStep) {
                    step.className = 'step-indicator step-completed';
                } else if (index === currentStep) {
                    step.className = 'step-indicator step-active';
                } else {
                    step.className = 'step-indicator step-inactive';
                }
            });
            
            progressLines.forEach((line, index) => {
                if (index < currentStep - 1) {
                    line.className = 'progress-line progress-active mx-2';
                } else {
                    line.className = 'progress-line mx-2';
                }
            });
        }

        function nextStep(step) {
            if (validateStep(step)) {
                document.getElementById(`step${step}`).classList.add('hidden');
                currentStep++;
                document.getElementById(`step${currentStep}`).classList.remove('hidden');
                updateProgress();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        }

        function prevStep(step) {
            document.getElementById(`step${step}`).classList.add('hidden');
            currentStep--;
            document.getElementById(`step${currentStep}`).classList.remove('hidden');
            updateProgress();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function validateStep(step) {
            const currentStepElement = document.getElementById(`step${step}`);
            const inputs = currentStepElement.querySelectorAll('input[required], select[required]');
            let isValid = true;

            inputs.forEach(input => {
                if (!input.value.trim()) {
                    input.classList.add('border-red-500');
                    isValid = false;
                } else {
                    input.classList.remove('border-red-500');
                }
            });

            // Password match validation
            if (step === 2) {
                const password = document.getElementById('password').value;
                const confirmPassword = document.getElementById('confirmPassword').value;
                if (password !== confirmPassword) {
                    alert('Passwords do not match!');
                    return false;
                }
            }

            return isValid;
        }

        // Toggle password visibility
        function togglePassword(id) {
            const input = document.getElementById(id);
            const icon = input.nextElementSibling.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
            }
        }

        // Form submission
        document.getElementById('signupForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            if (validateStep(3)) {
                // Simulate form submission
                const email = document.getElementById('adminEmail').value;
                document.getElementById('emailDisplay').textContent = email;
                document.querySelector('.signup-card').classList.add('hidden');
                document.getElementById('successMessage').classList.remove('hidden');
                
                // In real implementation, send to: edusync-signups@princeseed.org
                console.log('Form would be sent to: edusync-signups@princeseed.org');
            }
        });

        // Initialize
        updateProgress();
    </script>
</body>
</html>