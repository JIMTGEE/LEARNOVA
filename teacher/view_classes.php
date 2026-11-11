<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

// Include logging function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    error_log("Log activity file missing: $log_path");
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
} else {
    require_once($log_path);
}

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Validate user_id
$teacher_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'teacher'";
$user_check_stmt = $conn->prepare($user_check_sql);
if ($user_check_stmt) {
    $user_check_stmt->bind_param("i", $teacher_id);
    $user_check_stmt->execute();
    if ($user_check_stmt->get_result()->num_rows === 0) {
        error_log("Invalid teacher_id: $teacher_id");
        $_SESSION['error_msg'] = "Invalid user session.";
        header("Location: /login.php");
        exit();
    }
    $user_check_stmt->close();
} else {
    error_log("Prepare failed for user check: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
logActivity($conn, $teacher_id, "Accessed View Classes", "Teacher accessed view classes page from IP: $ip_address");

// Generate CSRF token
$conn->begin_transaction();
try {
    $csrf_token = bin2hex(random_bytes(32));
    $csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) 
                 VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
    $csrf_stmt = $conn->prepare($csrf_sql);
    if (!$csrf_stmt) {
        throw new Exception("Prepare failed for CSRF token: " . $conn->error);
    }
    $csrf_stmt->bind_param("is", $teacher_id, $csrf_token);
    $csrf_stmt->execute();
    $csrf_stmt->close();
    $conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    error_log("CSRF token error: " . $e->getMessage());
}

// Use provided current date
$today_str = '2025-09-07';

// Fetch current term
$current_term_sql = "
    SELECT id, year, term_name, start_date, end_date
    FROM academic_calendar
    WHERE start_date <= ? AND end_date >= ?
    LIMIT 1";
$current_term_stmt = $conn->prepare($current_term_sql);
$current_term = null;
if ($current_term_stmt) {
    $current_term_stmt->bind_param("ss", $today_str, $today_str);
    $current_term_stmt->execute();
    $current_term = $current_term_stmt->get_result()->fetch_assoc() ?: null;
    $current_term_stmt->close();
} else {
    error_log("Prepare failed for current term: " . $conn->error);
}

$current_academic_year = $current_term['year'] ?? '2025/2026';
$current_term_id = $current_term['id'] ?? null;

// Fetch all academic years
$academic_years_sql = "SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC";
$academic_years_stmt = $conn->prepare($academic_years_sql);
$academic_years = [];
if ($academic_years_stmt) {
    $academic_years_stmt->execute();
    $academic_years = $academic_years_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $academic_years_stmt->close();
} else {
    error_log("Prepare failed for academic years: " . $conn->error);
}

// Validate selected academic year
$selected_academic_year = $_GET['academic_year'] ?? $current_academic_year;
$valid_years = array_column($academic_years, 'year');
if (!in_array($selected_academic_year, $valid_years)) {
    $selected_academic_year = $current_academic_year;
}

// Fetch terms for selected year
$terms_sql = "
    SELECT id, term_name, start_date, end_date
    FROM academic_calendar
    WHERE year = ?
    ORDER BY start_date";
$terms_stmt = $conn->prepare($terms_sql);
$terms = [];
if ($terms_stmt) {
    $terms_stmt->bind_param("s", $selected_academic_year);
    $terms_stmt->execute();
    $terms = $terms_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $terms_stmt->close();
} else {
    error_log("Prepare failed for terms: " . $conn->error);
}

// Determine selected term
$selected_term_id = $_GET['term_id'] ?? null;
if (!$selected_term_id && $current_term && $current_term['year'] === $selected_academic_year) {
    $selected_term_id = $current_term_id;
}
if (!$selected_term_id && !empty($terms)) {
    $selected_term_id = $terms[0]['id'];
}

// Fetch departments
$session_dept_key = "departments_$teacher_id";
if (!isset($_SESSION[$session_dept_key])) {
    $departments_sql = "SELECT id, name FROM departments ORDER BY name";
    $departments_stmt = $conn->prepare($departments_sql);
    $_SESSION[$session_dept_key] = [];
    if ($departments_stmt) {
        $departments_stmt->execute();
        $departments_result = $departments_stmt->get_result();
        while ($row = $departments_result->fetch_assoc()) {
            $_SESSION[$session_dept_key][$row['id']] = $row['name'];
        }
        $departments_stmt->close();
    } else {
        error_log("Prepare failed for departments: " . $conn->error);
    }
}
$departments = $_SESSION[$session_dept_key];

// Fetch classes grouped by department
$classes_sql = "
    SELECT c.id, c.class_name, c.department_id,
           COUNT(DISTINCT sc.user_id) as student_count
    FROM classes c
    LEFT JOIN class_schedules cs ON c.id = cs.class_id
    LEFT JOIN student_classes sc ON c.id = sc.class_id AND sc.academic_year = ?
    WHERE cs.teacher_id = ?
    AND cs.term_id = ?
    GROUP BY c.id
    ORDER BY c.department_id, c.class_name";
$classes_stmt = $conn->prepare($classes_sql);
$classes = [];
if ($classes_stmt) {
    $classes_stmt->bind_param("sii", $selected_academic_year, $teacher_id, $selected_term_id);
    $classes_stmt->execute();
    $classes_result = $classes_stmt->get_result();
    while ($row = $classes_result->fetch_assoc()) {
        $classes[$row['department_id']][] = $row;
    }
    $classes_stmt->close();
} else {
    error_log("Prepare failed for classes: " . $conn->error);
}

// Fetch group counts
$groups_sql = "
    SELECT class_id, COUNT(*) as group_count
    FROM groups
    WHERE class_id IN (SELECT DISTINCT class_id FROM class_schedules WHERE teacher_id = ? AND term_id = ?)
    AND status = 'active'
    GROUP BY class_id";
$groups_stmt = $conn->prepare($groups_sql);
$group_counts = [];
if ($groups_stmt) {
    $groups_stmt->bind_param("ii", $teacher_id, $selected_term_id);
    $groups_stmt->execute();
    $groups_result = $groups_stmt->get_result();
    while ($row = $groups_result->fetch_assoc()) {
        $group_counts[$row['class_id']] = $row['group_count'];
    }
    $groups_stmt->close();
} else {
    error_log("Prepare failed for group counts: " . $conn->error);
}

// Calculate metrics
$total_classes = count(array_merge(...array_values($classes)));
$total_students = array_reduce(array_merge(...array_values($classes)), function($carry, $class) {
    return $carry + (int)$class['student_count'];
}, 0);
$total_groups = array_sum($group_counts);

// Student distribution for chart
$student_distribution_labels = [];
$student_distribution_data = [];
foreach ($classes as $dept_classes) {
    foreach ($dept_classes as $class) {
        $student_distribution_labels[] = $class['class_name'];
        $student_distribution_data[] = (int)$class['student_count'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View and manage assigned classes for QueensPa School teachers.">
    <meta name="keywords" content="QueensPa School, teacher, class management, groups">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="View Classes - QueensPa School">
    <meta property="og:description" content="Teacher interface to view and manage assigned classes, including groups and assignments.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/view_classes.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="View Classes - QueensPa School">
    <meta name="twitter:description" content="Teacher interface to view and manage assigned classes, including groups and assignments.">
    <title>View Classes - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

    <!-- Tailwind Config -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui'],
                        orbitron: ['Orbitron', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#fff7ed',
                            100: '#ffedd5',
                            200: '#fed7aa',
                            300: '#fdba74',
                            400: '#fb923c',
                            500: '#f97316',
                            600: '#ea580c',
                            700: '#c2410c',
                            800: '#9a3412',
                            900: '#7c2d12',
                        },
                        accent: {
                            500: '#1e3a8a',
                        },
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.6s ease-out',
                        'fade-in-up': 'fadeInUp 0.6s ease-out',
                        'zoom-in': 'zoomIn 0.6s ease-out',
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                        'glow': 'glow 2s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        fadeInUp: { '0%': { opacity: '0', transform: 'translateY(30px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
                        zoomIn: { '0%': { opacity: '0', transform: 'scale(0.95)' }, '100%': { opacity: '1', transform: 'scale(1)' } },
                        glow: {
                            '0%, 100%': { boxShadow: '0 0 5px rgba(249, 115, 22, 0.5)' },
                            '50%': { boxShadow: '0 0 20px rgba(249, 115, 22, 0.8)' },
                        },
                    },
                    backdropBlur: { xs: '2px' },
                },
            },
            darkMode: 'class',
        }
    </script>

    <style>
        html { scroll-behavior: smooth; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Roboto', sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.6;
        }
        .glassmorphism {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .dark .glassmorphism {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        h1, h2, h3, h4, h5, h6 { font-family: 'Orbitron', sans-serif; }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            margin: 0 auto;
        }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #f97316; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #ea580c; }
        .dark ::-webkit-scrollbar-track { background: #1f2937; }
        .dark ::-webkit-scrollbar-thumb { background: #f97316; }
        .dark ::-webkit-scrollbar-thumb:hover { background: #ea580c; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-orange-900 dark:bg-gradient-to-br dark:from-gray-900 dark:via-gray-800 dark:to-orange-900">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-black bg-opacity-80 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-orange-500 text-4xl animate-pulse-slow"></i>
    </div>

    <?php
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
    try {
        if (!file_exists($header_path)) {
            throw new Exception("Teacher header file missing: $header_path");
        }
        include $header_path;
    } catch (Exception $e) {
        error_log("Failed to include teacher_header.php: " . $e->getMessage());
        logActivity($conn, $teacher_id, "Error", "Failed to load teacher header: " . $e->getMessage());
        $_SESSION['error_msg'] = "System error: Unable to load page header.";
        header("Location: /login.php");
        exit();
    }
    ?>

    <div class="content" id="content">
        <main class="container mx-auto px-4 py-8 max-w-7xl">
            <!-- Header -->
            <div class="flex flex-col md:flex-row justify-between items-center mb-8 animate-fade-in">
                <h1 class="text-3xl md:text-4xl font-bold text-white dark:text-white text-center flex-grow mb-4 md:mb-0 animate-glow" aria-label="View Classes">
                    <i class="fas fa-chalkboard mr-2"></i> View Classes
                </h1>
                <button class="bg-accent-500 hover:bg-accent-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors duration-300 glassmorphism" id="theme-toggle" aria-label="Toggle dark mode">
                    <i class="fas fa-moon mr-2"></i> Toggle Theme
                </button>
            </div>

            <!-- Academic Year and Term Selection -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl flex justify-between items-center">
                    <h3 class="text-xl font-semibold mb-0">Academic Year and Term</h3>
                    <button class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white px-3 py-1 rounded-lg text-sm font-medium transition-all duration-300 glassmorphism" id="refresh-selection" data-csrf-token="<?= htmlspecialchars($csrf_token) ?>" aria-label="Refresh selection">
                        <i class="fas fa-sync-alt mr-2"></i> Refresh
                    </button>
                </div>
                <div class="p-6 text-white">
                    <div class="grid md:grid-cols-2 gap-6">
                        <div>
                            <label for="academic-year-filter" class="block text-sm font-medium mb-2">Academic Year</label>
                            <select id="academic-year-filter" class="w-full px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 glassmorphism bg-opacity-50 text-white" aria-label="Select academic year">
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?= htmlspecialchars($year['year']) ?>" <?= $selected_academic_year === $year['year'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($year['year']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="term-filter" class="block text-sm font-medium mb-2">Term</label>
                            <select id="term-filter" class="w-full px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 glassmorphism bg-opacity-50 text-white" aria-label="Select term">
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?= $term['id'] ?>" <?= $selected_term_id == $term['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($term['term_name']) ?> (<?= htmlspecialchars($term['start_date']) ?> - <?= htmlspecialchars($term['end_date']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Key Metrics -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl flex justify-between items-center">
                    <h3 class="text-xl font-semibold mb-0">Key Metrics (<?= htmlspecialchars($selected_academic_year) ?>)</h3>
                    <button class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white px-3 py-1 rounded-lg text-sm font-medium transition-all duration-300 glassmorphism" id="refresh-metrics" data-csrf-token="<?= htmlspecialchars($csrf_token) ?>" aria-label="Refresh metrics">
                        <i class="fas fa-sync-alt mr-2"></i> Refresh
                    </button>
                </div>
                <div class="p-6 text-white">
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6">
                        <div class="glassmorphism rounded-xl p-6 text-center hover:shadow-lg transition-all duration-300 hover:-translate-y-1 animate-glow">
                            <i class="fas fa-chalkboard text-3xl mb-3 text-orange-400"></i>
                            <h5 class="text-lg font-semibold mb-1">Classes</h5>
                            <p class="text-2xl font-bold text-orange-400 mb-1"><?= $total_classes ?></p>
                            <small class="text-gray-300">Assigned</small>
                        </div>
                        <div class="glassmorphism rounded-xl p-6 text-center hover:shadow-lg transition-all duration-300 hover:-translate-y-1 animate-glow">
                            <i class="fas fa-users text-3xl mb-3 text-orange-400"></i>
                            <h5 class="text-lg font-semibold mb-1">Students</h5>
                            <p class="text-2xl font-bold text-orange-400 mb-1"><?= $total_students ?></p>
                            <small class="text-gray-300">Enrolled</small>
                        </div>
                        <div class="glassmorphism rounded-xl p-6 text-center hover:shadow-lg transition-all duration-300 hover:-translate-y-1 animate-glow">
                            <i class="fas fa-user-friends text-3xl mb-3 text-orange-400"></i>
                            <h5 class="text-lg font-semibold mb-1">Groups</h5>
                            <p class="text-2xl font-bold text-orange-400 mb-1"><?= $total_groups ?></p>
                            <small class="text-gray-300">Active</small>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Assigned Classes -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Assigned Classes</h3>
                </div>
                <div class="p-6 text-white">
                    <!-- Search Bar -->
                    <div class="max-w-md mx-auto mb-6">
                        <input type="text" class="w-full px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 glassmorphism bg-opacity-50 text-white placeholder-gray-400" id="class-search" placeholder="Search classes..." aria-label="Search classes">
                    </div>
                    <!-- Filters -->
                    <div class="flex flex-col md:flex-row gap-4 mb-6">
                        <select class="px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 glassmorphism bg-opacity-50 text-white" id="department-filter" aria-label="Filter by department">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $id => $name): ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (empty($classes)): ?>
                        <div class="bg-yellow-500 bg-opacity-20 border border-yellow-400 text-yellow-200 px-4 py-3 rounded-lg animate-fade-in" role="alert">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            No classes assigned for the selected term.
                        </div>
                    <?php else: ?>
                        <?php foreach ($classes as $dept_id => $dept_classes): ?>
                            <div class="mb-6" data-department="<?= $dept_id ?>">
                                <div class="bg-gradient-to-r from-gray-700 to-gray-600 px-4 py-3 rounded-t-lg cursor-pointer glassmorphism" onclick="toggleCollapse('dept-<?= $dept_id ?>')">
                                    <div class="flex justify-between items-center">
                                        <h5 class="text-lg font-semibold text-white">
                                            <?= htmlspecialchars($departments[$dept_id] ?? 'Unknown') ?> 
                                            <span class="bg-orange-500 text-white px-2 py-1 rounded-full text-sm ml-2"><?= count($dept_classes) ?> Classes</span>
                                        </h5>
                                        <i class="fas fa-chevron-down transition-transform duration-300 text-orange-400" id="chevron-dept-<?= $dept_id ?>"></i>
                                    </div>
                                </div>
                                <div id="dept-<?= $dept_id ?>" class="bg-transparent border border-gray-500 rounded-b-lg glassmorphism">
                                    <div class="p-4">
                                        <div class="overflow-x-auto">
                                            <table class="w-full table-auto" aria-label="Assigned classes table">
                                                <thead>
                                                    <tr class="bg-gradient-to-r from-orange-500 to-red-500 text-white">
                                                        <th class="px-4 py-3 text-left font-semibold">Class Name</th>
                                                        <th class="px-4 py-3 text-left font-semibold">Students</th>
                                                        <th class="px-4 py-3 text-left font-semibold">Groups</th>
                                                        <th class="px-4 py-3 text-left font-semibold">Actions</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-gray-500">
                                                    <?php foreach ($dept_classes as $class): ?>
                                                        <tr class="class-row hover:bg-gray-700 transition-colors duration-200" 
                                                            data-class-name="<?= htmlspecialchars($class['class_name']) ?>">
                                                            <td class="px-4 py-3 font-medium text-white"><?= htmlspecialchars($class['class_name']) ?></td>
                                                            <td class="px-4 py-3 text-gray-300"><?= (int)$class['student_count'] ?></td>
                                                            <td class="px-4 py-3 text-gray-300"><?= (int)($group_counts[$class['id']] ?? 0) ?></td>
                                                            <td class="px-4 py-3">
                                                                <div class="flex space-x-2">
                                                                    <a href="view_class.php?id=<?= $class['id'] ?>&class_name=<?= urlencode($class['class_name']) ?>" 
                                                                       class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism" 
                                                                       data-tooltip="View Details" aria-label="View Class Details">
                                                                        <i class="fas fa-eye"></i>
                                                                    </a>
                                                                    <a href="submit_grades.php?class_id=<?= $class['id'] ?>&class_name=<?= urlencode($class['class_name']) ?>" 
                                                                       class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism" 
                                                                       data-tooltip="Submit Grades" aria-label="Submit Grades">
                                                                        <i class="fas fa-chart-line"></i>
                                                                    </a>
                                                                    <a href="manage_assignments.php?class_id=<?= $class['id'] ?>&class_name=<?= urlencode($class['class_name']) ?>" 
                                                                       class="bg-purple-500 hover:bg-purple-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism" 
                                                                       data-tooltip="Manage Assignments" aria-label="Manage Assignments">
                                                                        <i class="fas fa-tasks"></i>
                                                                    </a>
                                                                    <a href="create_group.php?class_id=<?= $class['id'] ?>&class_name=<?= urlencode($class['class_name']) ?>" 
                                                                       class="bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism" 
                                                                       data-tooltip="Create Group" aria-label="Create Group">
                                                                        <i class="fas fa-users"></i>
                                                                    </a>
                                                                    <a href="manage_group_members.php?class_id=<?= $class['id'] ?>&class_name=<?= urlencode($class['class_name']) ?>" 
                                                                       class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism" 
                                                                       data-tooltip="Manage Group Members" aria-label="Manage Group Members">
                                                                        <i class="fas fa-user-plus"></i>
                                                                    </a>
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Student Distribution Visualization -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Student Distribution</h3>
                </div>
                <div class="p-6">
                    <div class="chart-container">
                        <canvas id="studentDistributionChart" aria-label="Student distribution chart"></canvas>
                    </div>
                </div>
            </section>

            <!-- Quick Links -->
            <section class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8 animate-fade-in-up">
                <a href="/teacher/dashboard.php" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism" data-tooltip="Back to Dashboard" aria-label="Dashboard">
                    <i class="fas fa-tachometer-alt block text-xl mb-2"></i>
                    <span class="text-sm">Dashboard</span>
                </a>
                <a href="/teacher/submit_grades.php" class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism" data-tooltip="Submit Grades" aria-label="Submit Grades">
                    <i class="fas fa-chart-line block text-xl mb-2"></i>
                    <span class="text-sm">Submit Grades</span>
                </a>
                <a href="/teacher/manage_assignments.php" class="bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism" data-tooltip="Manage Assignments" aria-label="Manage Assignments">
                    <i class="fas fa-tasks block text-xl mb-2"></i>
                    <span class="text-sm">Assignments</span>
                </a>
                <a href="/teacher/manage_attendance.php" class="bg-gradient-to-r from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism" data-tooltip="Manage Attendance" aria-label="Manage Attendance">
                    <i class="fas fa-check-circle block text-xl mb-2"></i>
                    <span class="text-sm">Attendance</span>
                </a>
                <a href="/teacher/view_evaluations.php?teacher_id=<?= $teacher_id ?>" class="bg-gradient-to-r from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism" data-tooltip="View Evaluations" aria-label="View Evaluations">
                    <i class="fas fa-star block text-xl mb-2"></i>
                    <span class="text-sm">Evaluations</span>
                </a>
                <a href="/teacher/edit_user.php?id=<?= $teacher_id ?>" class="bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism" data-tooltip="Edit Profile" aria-label="Edit Profile">
                    <i class="fas fa-edit block text-xl mb-2"></i>
                    <span class="text-sm">Edit Profile</span>
                </a>
            </section>

            <!-- CSRF Token -->
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        </main>
    </div>

    <?php
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
    try {
        if (!file_exists($footer_path)) {
            throw new Exception("Teacher footer file missing: $footer_path");
        }
        include $footer_path;
    } catch (Exception $e) {
        error_log("Failed to include teacher_footer.php: " . $e->getMessage());
        logActivity($conn, $teacher_id, "Error", "Failed to load teacher footer: " . $e->getMessage());
        $_SESSION['error_msg'] = "System error: Unable to load page footer.";
        header("Location: /login.php");
        exit();
    }
    mysqli_close($conn);
    ob_end_flush();
    ?>

    <script>
        // Chart data
        const studentDistributionLabels = <?= json_encode($student_distribution_labels) ?>;
        const studentDistributionData = <?= json_encode($student_distribution_data) ?>;

        // Initialize chart
        let studentDistributionChart;

        function initializeChart() {
            const ctx = document.getElementById('studentDistributionChart').getContext('2d');
            studentDistributionChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: studentDistributionLabels,
                    datasets: [{
                        label: 'Students',
                        data: studentDistributionData,
                        backgroundColor: createGradient(ctx, ['#f97316', '#ea580c']),
                        borderColor: '#f97316',
                        borderWidth: 1,
                        borderRadius: 8,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: 'rgba(0, 0, 0, 0.8)',
                            titleFont: { family: 'Orbitron', size: 14 },
                            bodyFont: { family: 'Inter', size: 12 },
                        },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Students', color: '#fff', font: { family: 'Inter', size: 12 } },
                            ticks: { color: '#fff', font: { family: 'Inter' } },
                            grid: { color: 'rgba(255, 255, 255, 0.1)' },
                        },
                        x: {
                            ticks: { color: '#fff', font: { family: 'Inter' } },
                            grid: { display: false },
                        }
                    }
                }
            });
        }

        function createGradient(ctx, colors) {
            const gradient = ctx.createLinearGradient(0, 0, 0, 300);
            colors.forEach((color, index) => {
                gradient.addColorStop(index / (colors.length - 1), color);
            });
            return gradient;
        }

        function updateChart() {
            studentDistributionChart.data.datasets[0].backgroundColor = createGradient(
                document.getElementById('studentDistributionChart').getContext('2d'),
                document.body.classList.contains('dark') ? ['#f97316', '#ea580c'] : ['#f97316', '#ea580c']
            );
            studentDistributionChart.options.scales.y.grid.color = document.body.classList.contains('dark') ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
            studentDistributionChart.options.scales.y.ticks.color = document.body.classList.contains('dark') ? '#fff' : '#000';
            studentDistributionChart.options.scales.x.ticks.color = document.body.classList.contains('dark') ? '#fff' : '#000';
            studentDistributionChart.options.scales.y.title.color = document.body.classList.contains('dark') ? '#fff' : '#000';
            studentDistributionChart.update();
        }

        document.addEventListener('DOMContentLoaded', () => {
            const loadingOverlay = document.getElementById('loading-overlay');

            // Dark mode toggle
            const themeToggle = document.getElementById('theme-toggle');
            if (localStorage.getItem('theme') === 'dark') {
                document.body.classList.add('dark');
                themeToggle.innerHTML = '<i class="fas fa-sun mr-2"></i> Toggle Theme';
            }
            themeToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark');
                const isDark = document.body.classList.contains('dark');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                themeToggle.innerHTML = isDark 
                    ? '<i class="fas fa-sun mr-2"></i> Toggle Theme' 
                    : '<i class="fas fa-moon mr-2"></i> Toggle Theme';
                updateChart();
            });

            // Search and filter classes
            const searchInput = document.getElementById('class-search');
            const deptFilter = document.getElementById('department-filter');
            const classRows = document.querySelectorAll('.class-row');
            const deptCards = document.querySelectorAll('[data-department]');

            function filterClasses() {
                const searchTerm = searchInput.value.toLowerCase();
                const deptValue = deptFilter.value;

                deptCards.forEach(card => {
                    const deptId = card.getAttribute('data-department');
                    card.style.display = deptValue && deptId !== deptValue ? 'none' : 'block';
                });

                classRows.forEach(row => {
                    const className = row.getAttribute('data-class-name').toLowerCase();
                    const matchesSearch = className.includes(searchTerm);
                    row.style.display = matchesSearch ? '' : 'none';
                });
            }

            searchInput.addEventListener('input', filterClasses);
            deptFilter.addEventListener('change', filterClasses);

            // Academic year and term filters
            const yearFilter = document.getElementById('academic-year-filter');
            const termFilter = document.getElementById('term-filter');
            [yearFilter, termFilter].forEach(filter => {
                filter.addEventListener('change', () => {
                    const year = yearFilter.value;
                    const term = termFilter.value;
                    window.location.href = `?academic_year=${encodeURIComponent(year)}&term_id=${encodeURIComponent(term)}`;
                });
            });

            // Refresh metrics (placeholder, can implement API if needed)
            const refreshMetrics = document.getElementById('refresh-metrics');
            refreshMetrics.addEventListener('click', () => {
                window.location.reload();
            });

            // Initialize chart
            initializeChart();

            // Hide loading overlay
            loadingOverlay.classList.add('hidden');
        });

        // Collapse functionality
        function toggleCollapse(id) {
            const element = document.getElementById(id);
            const chevron = document.getElementById('chevron-' + id);
            if (element.style.display === 'none' || element.style.display === '') {
                element.style.display = 'block';
                chevron.style.transform = 'rotate(0deg)';
            } else {
                element.style.display = 'none';
                chevron.style.transform = 'rotate(180deg)';
            }
        }
    </script>
</body>
</html>