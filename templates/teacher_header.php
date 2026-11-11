<?php
// templates/teacher_header.php - Fixed Hamburger Menu
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not authenticated as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    // Use absolute path
    $login_url = dirname(dirname($_SERVER['PHP_SELF'])) . '/login.php';
    header("Location: $login_url");
    exit();
}

// Define project root path for consistent includes
$root_path = dirname(dirname(__FILE__)) . '/';  // Points to /edusync/

// Include essential files from root
require_once $root_path . 'config/db.php';

// Initialize DB
try {
    $db = db();
    
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    // Fetch teacher details
    $user_id = $_SESSION['user_id'];
    $user_stmt = $db->prepare("SELECT name, profile_picture FROM users WHERE id = ? AND role = 'teacher'");
    $user_stmt->execute([$user_id]);
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // Invalid teacher - redirect to login
        $login_url = dirname(dirname($_SERVER['PHP_SELF'])) . '/login.php';
        header("Location: $login_url?error=invalid_teacher");
        exit();
    }
    
    // Set defaults if needed
    if (empty($user['profile_picture']) || !file_exists($root_path . $user['profile_picture'])) {
        $user['profile_picture'] = $root_path . 'assets/images/default-profile.png';
    }
    
    // Unread notifications count
    $notif_stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE (user_id = ? OR target_role = 'teacher') AND is_read = 0");
    $notif_stmt->execute([$user_id]);
    $unread_notifications = (int)$notif_stmt->fetchColumn();
    
    // Fetch teacher's classes for dynamic submenu
    $classes_stmt = $db->prepare("SELECT DISTINCT c.id, c.class_name FROM classes c WHERE c.teacher_id = ? OR c.class_teacher_id = ? ORDER BY c.class_name");
    $classes_stmt->execute([$user_id, $user_id]);
    $teacher_classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Teacher header error: " . $e->getMessage());
    // Continue with minimal data
    $user = ['name' => $_SESSION['user_name'] ?? 'Teacher', 'profile_picture' => $root_path . 'assets/images/default-profile.png'];
    $unread_notifications = 0;
    $teacher_classes = [];
}

// Get current page for active menu highlighting
$current_page = basename($_SERVER['PHP_SELF']);

// Check for dark mode preference from cookie or system
$dark_mode = false;
if (isset($_COOKIE['dark_mode'])) {
    $dark_mode = $_COOKIE['dark_mode'] === 'true';
} elseif (isset($_SERVER['HTTP_DARK_MODE'])) {
    $dark_mode = $_SERVER['HTTP_DARK_MODE'] === 'true';
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo $dark_mode ? 'dark-mode' : 'light-mode'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EduSync - Teacher Portal</title>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Source+Sans+3:wght@300;400;500;600&display=swap" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    
    <!-- Custom CSS -->
    <style>
        /* Ultra Modern Design System - Light Theme Default */
        :root {
            /* Light Theme Colors */
            --primary-white: #FFFFFF;
            --primary-sea-blue: #17A2B8;
            --primary-black: #0B0D10;
            --accent-deep-navy: #081F2C;
            --accent-slate-gray: #5E6A79;
            --accent-soft-cyan: #35D0FF;
            
            /* Light Theme Specific */
            --bg-primary: #FFFFFF;
            --bg-secondary: #F8F9FA;
            --bg-sidebar: #FFFFFF;
            --text-primary: #0B0D10;
            --text-secondary: #5E6A79;
            --text-muted: #6C757D;
            --border-color: #E0E0E0;
            --card-bg: #FFFFFF;
            --card-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --hover-bg: rgba(23, 162, 184, 0.08);
            --sidebar-width: 280px;
            --header-height: 70px;
        }
        
        /* Dark Theme Overrides */
        .dark-mode {
            --bg-primary: #0B0D10;
            --bg-secondary: #121212;
            --bg-sidebar: #1A1A1A;
            --text-primary: #FFFFFF;
            --text-secondary: #B0B0B0;
            --text-muted: #8A8A8A;
            --border-color: #2D2D2D;
            --card-bg: #1A1A1A;
            --card-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            --hover-bg: rgba(23, 162, 184, 0.15);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }
        
        body {
            font-family: 'Source Sans 3', sans-serif;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            margin: 0;
            padding: 0;
            overflow-x: hidden;
            min-height: 100vh;
        }
        
        /* Header Styles */
        .app-header {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            padding: 0 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.08);
            position: fixed;
            width: 100%;
            top: 0;
            z-index: 1000;
            height: var(--header-height);
            border-bottom: 4px solid var(--primary-sea-blue);
        }
        
        .header-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .menu-toggle {
            background: none;
            border: none;
            color: var(--primary-sea-blue);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 10px;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 45px;
            height: 45px;
        }
        
        .menu-toggle:hover {
            background: var(--hover-bg);
            transform: rotate(90deg);
        }
        
        .logo-container {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .logo-icon {
            color: var(--primary-sea-blue);
            font-size: 1.8rem;
        }
        
        .logo-text {
            font-family: 'Inter', sans-serif;
            font-weight: 700;
            font-size: 1.5rem;
            color: var(--text-primary);
        }
        
        .logo-subtext {
            color: var(--primary-sea-blue);
            font-weight: 600;
        }
        
        /* Header Right - Theme Toggle & User Info */
        .header-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .theme-toggle {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.3rem;
            cursor: pointer;
            padding: 10px;
            border-radius: 50%;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 45px;
            height: 45px;
        }
        
        .theme-toggle:hover {
            background: var(--hover-bg);
            color: var(--primary-sea-blue);
        }
        
        .theme-toggle .fa-sun {
            display: none;
        }
        
        .dark-mode .theme-toggle .fa-moon {
            display: none;
        }
        
        .dark-mode .theme-toggle .fa-sun {
            display: inline-block;
        }
        
        .notifications-wrapper {
            position: relative;
        }
        
        .notifications-btn {
            background: none;
            border: none;
            color: var(--text-secondary);
            font-size: 1.3rem;
            cursor: pointer;
            padding: 10px;
            border-radius: 50%;
            transition: all 0.3s ease;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 45px;
            height: 45px;
        }
        
        .notifications-btn:hover {
            background: var(--hover-bg);
            color: var(--primary-sea-blue);
        }
        
        .notification-badge {
            position: absolute;
            top: 5px;
            right: 5px;
            background: var(--accent-soft-cyan);
            color: var(--primary-black);
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 16px;
            border-radius: 50px;
            transition: all 0.3s ease;
            cursor: pointer;
            border: 1px solid transparent;
        }
        
        .user-profile:hover {
            background: var(--hover-bg);
            border-color: var(--primary-sea-blue);
        }
        
        .profile-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-sea-blue);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 1.1rem;
            overflow: hidden;
            border: 2px solid var(--primary-sea-blue);
        }
        
        .profile-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-info {
            display: flex;
            flex-direction: column;
        }
        
        .profile-name {
            font-family: 'Inter', sans-serif;
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text-primary);
        }
        
        .profile-role {
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        
        /* Sidebar Navigation - FIXED POSITIONING */
        .sidebar {
            background: var(--bg-sidebar);
            color: var(--text-primary);
            width: var(--sidebar-width);
            height: calc(100vh - var(--header-height));
            position: fixed;
            top: var(--header-height);
            left: -280px; /* Hidden by default */
            transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            padding: 25px 0;
            overflow-y: auto;
            box-shadow: 5px 0 15px rgba(0, 0, 0, 0.1);
            z-index: 999;
            border-right: 1px solid var(--border-color);
        }
        
        .sidebar.open {
            left: 0; /* Visible when open */
        }
        
        .sidebar-menu {
            list-style: none;
        }
        
        .menu-section {
            padding: 15px 25px 10px;
            color: var(--text-muted);
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            border-bottom: 1px solid var(--border-color);
            margin-bottom: 10px;
        }
        
        .menu-item {
            margin-bottom: 5px;
        }
        
        .menu-link {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px 25px;
            text-decoration: none;
            color: var(--text-secondary);
            font-family: 'Inter', sans-serif;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            position: relative;
            overflow: hidden;
        }
        
        .menu-link:hover {
            background: var(--hover-bg);
            color: var(--primary-sea-blue);
            padding-left: 30px;
        }
        
        .menu-link.active {
            background: var(--hover-bg);
            color: var(--primary-sea-blue);
            border-left-color: var(--primary-sea-blue);
        }
        
        .menu-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            height: 100%;
            width: 3px;
            background: var(--primary-sea-blue);
        }
        
        .menu-icon {
            font-size: 1.2rem;
            width: 24px;
            text-align: center;
            color: var(--primary-sea-blue);
        }
        
        .menu-arrow {
            margin-left: auto;
            font-size: 0.8rem;
            transition: transform 0.3s ease;
            color: var(--text-muted);
        }
        
        .menu-arrow.rotated {
            transform: rotate(180deg);
        }
        
        .submenu {
            display: none;
            background: var(--bg-secondary);
            border-left: 2px solid var(--primary-sea-blue);
            margin-left: 25px;
            border-radius: 0 0 8px 8px;
            overflow: hidden;
        }
        
        .submenu.open {
            display: block;
            animation: slideDown 0.3s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .submenu-item {
            margin: 0;
        }
        
        .submenu-link {
            padding: 12px 25px 12px 40px;
            font-size: 0.9rem;
            color: var(--text-secondary);
        }
        
        .submenu-link:hover {
            background: var(--hover-bg);
            color: var(--primary-sea-blue);
        }
        
        .submenu-link.active {
            background: var(--hover-bg);
            color: var(--primary-sea-blue);
        }
        
        /* Main Content Area */
        .main-content {
            margin-left: 0;
            padding: calc(var(--header-height) + 20px) 30px 30px;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: 100vh;
            background: var(--bg-secondary);
        }
        
        .main-content.shifted {
            margin-left: var(--sidebar-width);
        }
        
        /* Role accent strip */
        .accent-strip {
            height: 4px;
            background: linear-gradient(90deg, var(--primary-sea-blue), var(--accent-soft-cyan));
            margin-bottom: 25px;
            border-radius: 2px;
        }
        
        /* Overlay for mobile */
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: var(--header-height);
            left: 0;
            width: 100%;
            height: calc(100vh - var(--header-height));
            background: rgba(0, 0, 0, 0.5);
            z-index: 998;
        }
        
        .sidebar-overlay.active {
            display: block;
        }
        
        /* Responsive Design */
        @media (max-width: 992px) {
            .sidebar {
                width: 100%;
                left: -100%;
            }
            
            .sidebar.open {
                left: 0;
            }
            
            .main-content.shifted {
                margin-left: 0;
            }
            
            .profile-info {
                display: none;
            }
            
            .header-right {
                gap: 15px;
            }
            
            .sidebar-overlay.active {
                display: block;
            }
        }
        
        @media (max-width: 768px) {
            .app-header {
                padding: 0 15px;
            }
            
            .main-content {
                padding: calc(var(--header-height) + 15px) 15px 15px;
            }
            
            .logo-text {
                font-size: 1.2rem;
            }
            
            .theme-toggle,
            .notifications-btn {
                padding: 8px;
                width: 40px;
                height: 40px;
            }
        }
        
        /* Scrollbar Styling */
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        
        .sidebar::-webkit-scrollbar-track {
            background: var(--bg-secondary);
        }
        
        .sidebar::-webkit-scrollbar-thumb {
            background: var(--primary-sea-blue);
            border-radius: 3px;
        }
        
        .sidebar::-webkit-scrollbar-thumb:hover {
            background: var(--accent-soft-cyan);
        }
        
        /* Smooth Transitions */
        .smooth-transition * {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }
    </style>
</head>
<body class="smooth-transition">
    <!-- Header -->
    <header class="app-header">
        <div class="header-left">
            <button class="menu-toggle" id="menuToggle">
                <i class="fas fa-bars"></i>
            </button>
            
            <div class="logo-container">
                <i class="fas fa-graduation-cap logo-icon"></i>
                <div class="logo-text">EduSync<span class="logo-subtext"> Teacher</span></div>
            </div>
        </div>
        
        <div class="header-right">
            <!-- Theme Toggle -->
            <button class="theme-toggle" id="themeToggle">
                <i class="fas fa-moon"></i>
                <i class="fas fa-sun"></i>
            </button>
            
            <!-- Notifications -->
            <div class="notifications-wrapper">
                <button class="notifications-btn" id="notificationsBtn">
                    <i class="fas fa-bell"></i>
                    <?php if ($unread_notifications > 0): ?>
                        <span class="notification-badge"><?php echo $unread_notifications; ?></span>
                    <?php endif; ?>
                </button>
            </div>
            
            <!-- User Profile -->
            <div class="user-profile" id="userProfile">
                <div class="profile-avatar">
                    <?php if (strpos($user['profile_picture'], 'http') === 0 || strpos($user['profile_picture'], '/') === 0): ?>
                        <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" alt="<?php echo htmlspecialchars($user['name']); ?>">
                    <?php else: ?>
                        <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                    <?php endif; ?>
                </div>
                <div class="profile-info">
                    <span class="profile-name"><?php echo htmlspecialchars($user['name']); ?></span>
                    <span class="profile-role">Teacher</span>
                </div>
                <i class="fas fa-chevron-down" style="font-size: 0.9rem;"></i>
            </div>
        </div>
    </header>
    
    <!-- Sidebar Overlay for Mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    
    <!-- Sidebar Navigation -->
    <nav class="sidebar" id="sidebar">
        <ul class="sidebar-menu">
            <!-- Dashboard -->
            <li class="menu-item">
                <a href="dashboard.php" class="menu-link <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>">
                    <i class="fas fa-tachometer-alt menu-icon"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            
            <!-- Teaching Section -->
            <li class="menu-section">Teaching & Planning</li>
            
            <li class="menu-item">
                <a href="classes.php" class="menu-link <?php echo $current_page == 'classes.php' ? 'active' : ''; ?>">
                    <i class="fas fa-chalkboard-teacher menu-icon"></i>
                    <span>My Classes</span>
                </a>
            </li>
            
            <li class="menu-item has-submenu">
                <a href="#" class="menu-link" onclick="toggleSubmenu(event, this)">
                    <i class="fas fa-calendar-alt menu-icon"></i>
                    <span>Lesson Planning</span>
                    <i class="fas fa-chevron-down menu-arrow"></i>
                </a>
                <ul class="submenu">
                    <li class="submenu-item">
                        <a href="lesson_plans.php" class="submenu-link <?php echo $current_page == 'lesson_plans.php' ? 'active' : ''; ?>">
                            <i class="fas fa-book"></i> Lesson Plans
                        </a>
                    </li>
                    <li class="submenu-item">
                        <a href="teacher_notes.php" class="submenu-link <?php echo $current_page == 'teacher_notes.php' ? 'active' : ''; ?>">
                            <i class="fas fa-sticky-note"></i> Teacher Notes
                        </a>
                    </li>
                    <li class="submenu-item">
                        <a href="annual_plans.php" class="submenu-link <?php echo $current_page == 'annual_plans.php' ? 'active' : ''; ?>">
                            <i class="fas fa-calendar"></i> Annual Plans
                        </a>
                    </li>
                </ul>
            </li>
            
            <!-- Assessment Section -->
            <li class="menu-section">Assessment & Grading</li>
            
            <li class="menu-item has-submenu">
                <a href="#" class="menu-link" onclick="toggleSubmenu(event, this)">
                    <i class="fas fa-tasks menu-icon"></i>
                    <span>Assignments</span>
                    <i class="fas fa-chevron-down menu-arrow"></i>
                </a>
                <ul class="submenu">
                    <li class="submenu-item">
                        <a href="assignments.php" class="submenu-link <?php echo $current_page == 'assignments.php' ? 'active' : ''; ?>">
                            <i class="fas fa-plus-circle"></i> Create Assignment
                        </a>
                    </li>
                    <li class="submenu-item">
                        <a href="manage_assignments.php" class="submenu-link <?php echo $current_page == 'manage_assignments.php' ? 'active' : ''; ?>">
                            <i class="fas fa-list"></i> Manage Assignments
                        </a>
                    </li>
                    <li class="submenu-item">
                        <a href="grades.php" class="submenu-link <?php echo $current_page == 'grades.php' ? 'active' : ''; ?>">
                            <i class="fas fa-star"></i> Grade Submissions
                        </a>
                    </li>
                </ul>
            </li>
            
            <li class="menu-item">
                <a href="attendance.php" class="menu-link <?php echo $current_page == 'attendance.php' ? 'active' : ''; ?>">
                    <i class="fas fa-clipboard-check menu-icon"></i>
                    <span>Attendance</span>
                </a>
            </li>
            
            <!-- Resources Section -->
            <li class="menu-section">Resources</li>
            
            <li class="menu-item">
                <a href="resources.php" class="menu-link <?php echo $current_page == 'resources.php' ? 'active' : ''; ?>">
                    <i class="fas fa-folder menu-icon"></i>
                    <span>Teaching Resources</span>
                </a>
            </li>
            
            <li class="menu-item">
                <a href="calendar.php" class="menu-link <?php echo $current_page == 'calendar.php' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar menu-icon"></i>
                    <span>Calendar</span>
                </a>
            </li>
            
            <!-- Reports Section -->
            <li class="menu-section">Reports & Analytics</li>
            
            <li class="menu-item">
                <a href="reports.php" class="menu-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar menu-icon"></i>
                    <span>Reports</span>
                </a>
            </li>
            
            <li class="menu-item">
                <a href="analytics.php" class="menu-link <?php echo $current_page == 'analytics.php' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line menu-icon"></i>
                    <span>Analytics</span>
                </a>
            </li>
            
            <!-- Profile & Settings -->
            <li class="menu-section">Account</li>
            
            <li class="menu-item">
                <a href="profile.php" class="menu-link <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
                    <i class="fas fa-user menu-icon"></i>
                    <span>My Profile</span>
                </a>
            </li>
            
            <li class="menu-item">
                <a href="settings.php" class="menu-link <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
                    <i class="fas fa-cog menu-icon"></i>
                    <span>Settings</span>
                </a>
            </li>
            
            <li class="menu-item">
                <a href="<?php echo $root_path; ?>logout.php" class="menu-link" onclick="return confirm('Are you sure you want to logout?');">
                    <i class="fas fa-sign-out-alt menu-icon"></i>
                    <span>Logout</span>
                </a>
            </li>
        </ul>
    </nav>
    
    <!-- Main Content Area -->
    <main class="main-content" id="mainContent">
        <div class="accent-strip"></div>
        
        <!-- Page content will be inserted here -->
        <div class="content-wrapper">