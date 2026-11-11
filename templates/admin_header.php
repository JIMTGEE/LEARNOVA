<?php
// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Check if the user is logged in and has an admin role - Using specified auth logic
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    // Debug: Log why redirecting (preserved)
    error_log("Redirecting to login: user_id not set or role not admin. Role: " . ($_SESSION['user_role'] ?? 'not set'));
    header("Location: ../login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edusync Admin Portal</title>
    <!-- Google Fonts for Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Sans+3:wght@400;600&display=swap" rel="stylesheet">
    <!-- Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" integrity="sha512-iecdLmaskl7CVkqkXNQ/ZH/XLlvWZOJyj7Yy7tcenmpD1ypASozpmT/E0iPtmFIB46ZmdtAc9eNBvH0H/ZpiBw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-white: #FFFFFF;
            --primary-sea-blue: #17A2B8;
            --primary-black: #0B0D10;
            --accent-deep-navy: #081F2C;
            --accent-slate-gray: #5E6A79;
            --accent-soft-cyan: #35D0FF;
            --font-ui: 'Inter', sans-serif;
            --font-content: 'Source Sans 3', sans-serif;
        }
        body {
            margin: 0;
            font-family: var(--font-content);
            background-color: var(--primary-white);
            color: var(--primary-black);
            overflow-x: hidden;
        }
        /* Top App Bar */
        .top-app-bar {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 64px;
            background-color: var(--accent-deep-navy);
            display: flex;
            align-items: center;
            padding: 0 16px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            z-index: 1000;
        }
        .hamburger {
            font-size: 24px;
            color: var(--primary-white);
            cursor: pointer;
            margin-right: 16px;
        }
        .app-title {
            font-family: var(--font-ui);
            font-size: 20px;
            font-weight: 600;
            color: var(--primary-white);
        }
        /* Collapsible Side Navigation */
        .side-nav {
            position: fixed;
            top: 64px;
            left: 0;
            width: 250px;
            height: calc(100vh - 64px);
            background-color: var(--accent-deep-navy);
            transform: translateX(-100%);
            transition: transform 0.3s ease-out;
            overflow-y: auto;
            z-index: 999;
            box-shadow: 2px 0 4px rgba(0, 0, 0, 0.1);
        }
        .side-nav.open {
            transform: translateX(0);
        }
        .nav-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .nav-item {
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        .nav-link {
            display: flex;
            align-items: center;
            padding: 16px;
            color: var(--primary-white);
            text-decoration: none;
            font-family: var(--font-ui);
            font-size: 16px;
            transition: background-color 0.2s ease-out;
        }
        .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        .nav-icon {
            margin-right: 16px;
            font-size: 20px;
        }
        /* Overlay for Mobile */
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            display: none;
            z-index: 998;
        }
        .overlay.open {
            display: block;
        }
        /* Main Content */
        .main-content {
            margin-top: 64px;
            margin-left: 0;
            padding: 24px;
            transition: margin-left 0.3s ease-out;
        }
        @media (min-width: 768px) {
            .side-nav {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 250px;
            }
            .hamburger {
                display: none;
            }
        }
        /* Floating Action Button */
        .fab {
            position: fixed;
            bottom: 24px;
            right: 24px;
            width: 56px;
            height: 56px;
            background-color: var(--primary-sea-blue);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            cursor: pointer;
            transition: transform 0.2s ease-out;
            z-index: 1001;
        }
        .fab:hover {
            transform: scale(1.1);
        }
        .fab-icon {
            font-size: 24px;
            color: var(--primary-white);
        }
        /* Role-based Accent Strip (Admin: Navy) */
        .accent-strip {
            height: 4px;
            background-color: var(--accent-deep-navy);
            width: 100%;
        }
    </style>
</head>
<body>
    <header class="top-app-bar">
        <i class="fas fa-bars hamburger" onclick="toggleNav()"></i>
        <span class="app-title">Edusync Admin Portal</span>
    </header>
    <nav class="side-nav" id="sideNav">
        <ul class="nav-list">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link">
                    <i class="fas fa-tachometer-alt nav-icon"></i> Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="users.php" class="nav-link">
                    <i class="fas fa-users nav-icon"></i> User & Role Management
                </a>
            </li>
            <li class="nav-item">
                <a href="classes.php" class="nav-link">
                    <i class="fas fa-chalkboard nav-icon"></i> Class & Subject Management
                </a>
            </li>
            <li class="nav-item">
                <a href="attendance.php" class="nav-link">
                    <i class="fas fa-calendar-check nav-icon"></i> Attendance & Progress Tracking
                </a>
            </li>
            <li class="nav-item">
                <a href="fees.php" class="nav-link">
                    <i class="fas fa-money-bill-wave nav-icon"></i> Fees & Finance
                </a>
            </li>
            <li class="nav-item">
                <a href="reports.php" class="nav-link">
                    <i class="fas fa-chart-bar nav-icon"></i> Reports & Evaluations
                </a>
            </li>
            <li class="nav-item">
                <a href="notifications.php" class="nav-link">
                    <i class="fas fa-bell nav-icon"></i> Notifications & Communication
                </a>
            </li>
            <li class="nav-item">
                <a href="calendar.php" class="nav-link">
                    <i class="fas fa-calendar-alt nav-icon"></i> Calendar & Scheduling
                </a>
            </li>
            <li class="nav-item">
                <a href="assignments.php" class="nav-link">
                    <i class="fas fa-tasks nav-icon"></i> Assignments & LMS Integration
                </a>
            </li>
            <li class="nav-item">
                <a href="maintenance.php" class="nav-link">
                    <i class="fas fa-tools nav-icon"></i> Maintenance & Utilities
                </a>
            </li>
            <li class="nav-item">
                <a href="../logout.php" class="nav-link">
                    <i class="fas fa-sign-out-alt nav-icon"></i> Logout
                </a>
            </li>
        </ul>
    </nav>
    <div class="overlay" id="overlay" onclick="toggleNav()"></div>
    <main class="main-content">
        <div class="accent-strip"></div>
        <!-- Page content goes here -->
    <!-- Floating Action Button (FAB) - Example for quick add action; can be customized per page if needed -->
    <div class="fab" onclick="handleFabClick()">
        <i class="fas fa-plus fab-icon"></i>
    </div>
    <script>
        function toggleNav() {
            const sideNav = document.getElementById('sideNav');
            const overlay = document.getElementById('overlay');
            sideNav.classList.toggle('open');
            overlay.classList.toggle('open');
        }
        // Example FAB click handler - Can be overridden or customized in specific pages
        function handleFabClick() {
            alert('Quick Add Action: Customize based on page context (e.g., Add User, Add Class).');
        }
    </script>