<?php
ob_start();
session_start();

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

// Include database connection and logging
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Fetch teacher details
$teacher_id = (int)$_SESSION['user_id'];
$role = 'teacher';
$teacher_sql = "SELECT name, profile_picture FROM users WHERE id = ? AND role = ?";
$teacher_stmt = $conn->prepare($teacher_sql);
if (!$teacher_stmt) {
    error_log("Teacher query preparation failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}
$teacher_stmt->bind_param("is", $teacher_id, $role);
$teacher_stmt->execute();
$teacher = $teacher_stmt->get_result()->fetch_assoc();
$teacher_stmt->close();
if (!$teacher) {
    error_log("Teacher not found: user_id=$teacher_id");
    $_SESSION['error_msg'] = "Invalid user session.";
    header("Location: /login.php");
    exit();
}

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
logActivity($conn, $teacher_id, "Accessed Manage Students", "Teacher accessed manage students page from IP: $ip_address");

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
$current_academic_year = '2025/2026';
$year_start = '2025-01-01';
$year_end = '2025-12-31';

// Fetch current term
$current_term_sql = "
    SELECT id, term_name, start_date, end_date
    FROM academic_calendar
    WHERE start_date <= ? AND end_date >= ?
    LIMIT 1";
$current_term_stmt = $conn->prepare($current_term_sql);
$current_term = null;
if ($current_term_stmt) {
    $current_term_stmt->bind_param("ss", $today_str, $today_str);
    $current_term_stmt->execute();
    $current_term = $current_term_stmt->get_result()->fetch_assoc() ?: ['id' => null, 'term_name' => 'N/A'];
    $current_term_stmt->close();
} else {
    error_log("Prepare failed for current term: " . $conn->error);
}

// Validate selected academic year and term
$selected_academic_year = $_GET['academic_year'] ?? $current_academic_year;
$selected_term_id = $_GET['term_id'] ?? ($current_term['id'] ?? null);

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

// Validate academic year
$valid_years = array_column($academic_years, 'year');
if (!in_array($selected_academic_year, $valid_years)) {
    $selected_academic_year = $current_academic_year;
}

// Fetch terms for selected year
$terms_sql = "SELECT id, term_name, start_date, end_date FROM academic_calendar WHERE year = ? ORDER BY start_date";
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
if (!$selected_term_id && !empty($terms)) {
    $selected_term_id = $terms[0]['id'];
}

// Cache departments
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

// Fetch classes with department info
$classes_sql = "
    SELECT c.id, c.class_name, c.department_id
    FROM classes c
    JOIN class_schedules cs ON c.id = cs.class_id
    WHERE cs.teacher_id = ? AND cs.term_id = ?
    ORDER BY c.department_id, c.class_name";
$classes_stmt = $conn->prepare($classes_sql);
$classes = [];
if ($classes_stmt) {
    $classes_stmt->bind_param("ii", $teacher_id, $selected_term_id);
    $classes_stmt->execute();
    $classes_result = $classes_stmt->get_result();
    while ($row = $classes_result->fetch_assoc()) {
        $classes[$row['id']] = $row;
    }
    $classes_stmt->close();
} else {
    error_log("Prepare failed for classes: " . $conn->error);
}

// Cache student counts
$session_student_counts_key = "student_counts_{$teacher_id}_{$selected_term_id}";
if (!isset($_SESSION[$session_student_counts_key])) {
    $counts_sql = "
        SELECT sc.class_id, COUNT(DISTINCT sc.user_id) as student_count
        FROM student_classes sc
        JOIN class_schedules cs ON sc.class_id = cs.class_id
        WHERE cs.teacher_id = ? AND cs.term_id = ? AND sc.academic_year = ?
        GROUP BY sc.class_id";
    $counts_stmt = $conn->prepare($counts_sql);
    $_SESSION[$session_student_counts_key] = [];
    if ($counts_stmt) {
        $counts_stmt->bind_param("iis", $teacher_id, $selected_term_id, $selected_academic_year);
        $counts_stmt->execute();
        $counts_result = $counts_stmt->get_result();
        while ($row = $counts_result->fetch_assoc()) {
            $_SESSION[$session_student_counts_key][$row['class_id']] = $row['student_count'];
        }
        $counts_stmt->close();
    } else {
        error_log("Prepare failed for student counts: " . $conn->error);
    }
}
$student_counts = $_SESSION[$session_student_counts_key];

// Fetch students and their details
$grouped_students = [];
$print_students = [];
$attendance_data = [];
foreach ($classes as $class_id => $class) {
    $students_sql = "
        SELECT sc.id, sc.user_id, sc.academic_year, u.name AS student_name, u.email, u.phone, u.gender, 
               c.class_name, u.profile_picture, d.id AS department_id
        FROM student_classes sc 
        JOIN users u ON sc.user_id = u.id 
        JOIN classes c ON sc.class_id = c.id 
        JOIN departments d ON c.department_id = d.id
        WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student'
        ORDER BY u.name";
    $students_stmt = $conn->prepare($students_sql);
    if (!$students_stmt) {
        error_log("Students query preparation failed for class $class_id: " . $conn->error);
        continue;
    }
    $students_stmt->bind_param("is", $class_id, $selected_academic_year);
    $students_stmt->execute();
    $students_result = $students_stmt->get_result();
    
    $grouped_students[$class['class_name']] = [];
    $print_students[$class['class_name']] = [];
    
    while ($student_row = $students_result->fetch_assoc()) {
        $student_id = $student_row['user_id'];
        
        // Cache attendance
        $attendance_key = "attendance_{$student_id}_{$class_id}_{$selected_term_id}";
        if (!isset($_SESSION[$attendance_key])) {
            $attendance_sql = "
                SELECT SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present_count,
                       COUNT(*) AS total_sessions
                FROM attendance 
                WHERE user_id = ? AND class_id = ? AND date BETWEEN ? AND ?";
            $attendance_stmt = $conn->prepare($attendance_sql);
            if ($attendance_stmt) {
                $attendance_stmt->bind_param("iiss", $student_id, $class_id, $year_start, $year_end);
                $attendance_stmt->execute();
                $attendance = $attendance_stmt->get_result()->fetch_assoc();
                $_SESSION[$attendance_key] = [
                    'present_count' => $attendance['present_count'] ?? 0,
                    'total_sessions' => $attendance['total_sessions'] ?? 0
                ];
                $attendance_stmt->close();
            } else {
                error_log("Attendance query preparation failed for student $student_id: " . $conn->error);
                $_SESSION[$attendance_key] = ['present_count' => 0, 'total_sessions' => 0];
            }
        }
        $student_row['present_count'] = $_SESSION[$attendance_key]['present_count'];
        $student_row['total_sessions'] = $_SESSION[$attendance_key]['total_sessions'];
        $attendance_data[] = [
            'name' => $student_row['student_name'],
            'class' => $class['class_name'],
            'attendance_rate' => $student_row['total_sessions'] > 0 
                ? round(($student_row['present_count'] / $student_row['total_sessions']) * 100, 1) 
                : 0
        ];
        
        // Fetch recent assignments
        $assignments_sql = "
            SELECT a.id, a.title, a.due_date, s.subject_name, 
                   COALESCE(ass.status, 'pending') AS submission_status
            FROM assignments a
            JOIN subjects s ON a.subject_id = s.id
            JOIN student_classes sc ON a.class_id = sc.class_id
            LEFT JOIN assignment_submissions ass ON a.id = ass.assignment_id AND ass.user_id = sc.user_id
            WHERE sc.user_id = ? AND sc.class_id = ? AND sc.academic_year = ?
            ORDER BY a.due_date ASC
            LIMIT 3";
        $assignments_stmt = $conn->prepare($assignments_sql);
        if ($assignments_stmt) {
            $assignments_stmt->bind_param("iis", $student_id, $class_id, $selected_academic_year);
            $assignments_stmt->execute();
            $student_row['assignments'] = $assignments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $assignments_stmt->close();
        } else {
            error_log("Assignments query preparation failed for student $student_id: " . $conn->error);
            $student_row['assignments'] = [];
        }
        
        // Fetch unread notification count
        $notifications_sql = "
            SELECT COUNT(*) as unread_count
            FROM notifications 
            WHERE user_id = ? AND is_read = 0";
        $notifications_stmt = $conn->prepare($notifications_sql);
        if ($notifications_stmt) {
            $notifications_stmt->bind_param("i", $student_id);
            $notifications_stmt->execute();
            $notifications = $notifications_stmt->get_result()->fetch_assoc();
            $student_row['unread_notifications'] = $notifications['unread_count'] ?? 0;
            $notifications_stmt->close();
        } else {
            error_log("Notifications query preparation failed for student $student_id: " . $conn->error);
            $student_row['unread_notifications'] = 0;
        }
        
        $grouped_students[$class['class_name']][] = $student_row;
        $print_students[$class['class_name']][] = $student_row;
    }
    $students_stmt->close();
}

// Calculate metrics
$total_students = array_sum(array_values($student_counts));
$total_classes = count($classes);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Monitor students in classes assigned to the teacher in QueensPa School management system.">
    <meta name="keywords" content="QueensPa School, teacher students, class students, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Manage Students - QueensPa School">
    <meta property="og:description" content="Teacher interface for monitoring students in assigned classes in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/manage_students.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Manage Students - QueensPa School">
    <meta name="twitter:description" content="Teacher interface for monitoring students in assigned classes in QueensPa School.">
    <title>Manage Students - QueensPa School</title>

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
        @media print {
            body * { display: none !important; }
            .print-section, .print-section * { display: block !important; visibility: visible !important; }
            .print-section { position: static; font-size: 12pt; color: #000; }
            .print-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #f97316; padding-bottom: 10px; }
            .print-header img { max-width: 100px; }
            .print-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            .print-table th, .print-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            .print-table th { background: linear-gradient(to right, #f97316, #ea580c); color: white; }
            .print-group-header { font-size: 14pt; font-weight: bold; margin: 20px 0 10px; color: #f97316; page-break-before: always; }
            .print-footer { text-align: center; margin-top: 20px; font-size: 10pt; color: #555; }
            @page { size: A4; margin: 20mm; }
        }
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
                <h1 class="text-3xl md:text-4xl font-bold text-white dark:text-white text-center flex-grow mb-4 md:mb-0 animate-glow" aria-label="Manage Students">
                    <i class="fas fa-user-graduate mr-2"></i> Manage Students
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
                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-2 gap-6">
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
                    </div>
                </div>
            </section>

            <!-- Student List -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl flex justify-between items-center">
                    <h3 class="text-xl font-semibold mb-0">Student List</h3>
                    <div class="flex space-x-2">
                        <button class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white px-3 py-1 rounded-lg text-sm font-medium transition-all duration-300 glassmorphism" onclick="printContent()" aria-label="Print student list">
                            <i class="fas fa-print mr-2"></i> Print List
                        </button>
                        <button class="bg-white bg-opacity-20 hover:bg-opacity-30 text-white px-3 py-1 rounded-lg text-sm font-medium transition-all duration-300 glassmorphism" id="refresh-notifications" data-csrf-token="<?= htmlspecialchars($csrf_token) ?>" aria-label="Refresh notifications">
                            <i class="fas fa-bell mr-2"></i> Refresh Notifications
                        </button>
                    </div>
                </div>
                <div class="p-6 text-white">
                    <!-- Search and Filters -->
                    <div class="flex flex-col md:flex-row gap-4 mb-6">
                        <input type="text" class="px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 glassmorphism bg-opacity-50 text-white placeholder-gray-400 w-full md:w-1/3" id="student-search" placeholder="Search students..." aria-label="Search students">
                        <select class="px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 glassmorphism bg-opacity-50 text-white" id="class-filter" aria-label="Filter by class">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= htmlspecialchars($class['class_name']) ?>"><?= htmlspecialchars($class['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 glassmorphism bg-opacity-50 text-white" id="department-filter" aria-label="Filter by department">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $id => $name): ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (empty($grouped_students)): ?>
                        <div class="bg-yellow-500 bg-opacity-20 border border-yellow-400 text-yellow-200 px-4 py-3 rounded-lg animate-fade-in" role="alert">
                            <i class="fas fa-exclamation-triangle mr-2"></i>
                            No students assigned for the selected term.
                        </div>
                    <?php else: ?>
                        <?php foreach ($grouped_students as $class_name => $students): ?>
                            <div class="mb-6" data-class="<?= htmlspecialchars($class_name) ?>" data-department="<?= $classes[array_key_first($students)]['department_id'] ?>">
                                <div class="bg-gradient-to-r from-gray-700 to-gray-600 px-4 py-3 rounded-t-lg cursor-pointer glassmorphism" onclick="toggleCollapse('class-<?= htmlspecialchars(str_replace(' ', '-', $class_name)) ?>')">
                                    <div class="flex justify-between items-center">
                                        <h5 class="text-lg font-semibold text-white">
                                            <?= htmlspecialchars($class_name) ?>
                                            <span class="bg-orange-500 text-white px-2 py-1 rounded-full text-sm ml-2"><?= count($students) ?> Students</span>
                                        </h5>
                                        <i class="fas fa-chevron-down transition-transform duration-300 text-orange-400" id="chevron-class-<?= htmlspecialchars(str_replace(' ', '-', $class_name)) ?>"></i>
                                    </div>
                                </div>
                                <div id="class-<?= htmlspecialchars(str_replace(' ', '-', $class_name)) ?>" class="bg-transparent border border-gray-500 rounded-b-lg glassmorphism">
                                    <div class="p-4">
                                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                            <?php foreach ($students as $student): ?>
                                                <div class="student-card bg-white bg-opacity-10 rounded-lg shadow-md hover:shadow-lg transform hover:-translate-y-1 transition duration-300 glassmorphism" 
                                                     data-student-name="<?= htmlspecialchars($student['student_name']) ?>" 
                                                     data-class="<?= htmlspecialchars($class_name) ?>" 
                                                     data-department="<?= $student['department_id'] ?>">
                                                    <div class="p-4">
                                                        <div class="flex items-center gap-4 mb-4">
                                                            <img src="<?= !empty($student['profile_picture']) ? htmlspecialchars($student['profile_picture']) : '/assets/images/default-profile.jpg' ?>" 
                                                                 class="w-16 h-16 rounded-full object-cover border-2 border-orange-500" 
                                                                 alt="<?= htmlspecialchars($student['student_name']) ?>'s Profile">
                                                            <div class="student-info">
                                                                <h6 class="text-lg font-semibold text-white"><?= htmlspecialchars($student['student_name']) ?></h6>
                                                                <p class="text-sm text-gray-300"><?= htmlspecialchars($student['academic_year']) ?></p>
                                                                <span class="bg-gradient-to-r from-blue-500 to-blue-600 text-white text-sm px-3 py-1 rounded-full">
                                                                    <?= $student['present_count'] ?> / <?= $student['total_sessions'] ?> Present
                                                                </span>
                                                                <?php if ($student['unread_notifications'] > 0): ?>
                                                                    <span class="bg-red-500 text-white text-sm px-3 py-1 rounded-full ml-2 animate-pulse-slow unread-badge">
                                                                        <?= $student['unread_notifications'] ?> Unread
                                                                    </span>
                                                                <?php endif; ?>
                                                            </div>
                                                        </div>
                                                        <div class="mb-4 text-gray-300">
                                                            <p class="text-sm"><strong>Email:</strong> <?= htmlspecialchars($student['email']) ?></p>
                                                            <p class="text-sm"><strong>Phone:</strong> <?= htmlspecialchars($student['phone']) ?></p>
                                                            <p class="text-sm"><strong>Gender:</strong> <?= htmlspecialchars($student['gender']) ?></p>
                                                        </div>
                                                        <div class="mb-4">
                                                            <h6 class="text-sm font-semibold text-white mb-2">Recent Assignments</h6>
                                                            <?php if (empty($student['assignments'])): ?>
                                                                <p class="text-sm text-gray-400">No recent assignments</p>
                                                            <?php else: ?>
                                                                <ul class="text-sm text-gray-300 space-y-1">
                                                                    <?php foreach ($student['assignments'] as $assignment): ?>
                                                                        <li>
                                                                            <span class="font-medium"><?= htmlspecialchars($assignment['title']) ?></span>
                                                                            (<?= htmlspecialchars($assignment['subject_name']) ?>) - 
                                                                            <span class="<?= $assignment['submission_status'] === 'submitted' ? 'text-green-400' : 'text-red-400' ?>">
                                                                                <?= ucfirst($assignment['submission_status']) ?>
                                                                            </span>
                                                                            <br>
                                                                            <small>Due: <?= htmlspecialchars($assignment['due_date']) ?></small>
                                                                        </li>
                                                                    <?php endforeach; ?>
                                                                </ul>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="flex flex-wrap gap-2">
                                                            <a href="/teacher/view_profile.php?id=<?= $student['user_id'] ?>" 
                                                               class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism" 
                                                               data-tooltip="View Profile" aria-label="View Profile">
                                                                <i class="fas fa-user"></i>
                                                            </a>
                                                            <a href="/teacher/view_assignments.php?id=<?= $student['user_id'] ?>" 
                                                               class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism" 
                                                               data-tooltip="View Assignments" aria-label="View Assignments">
                                                                <i class="fas fa-book"></i>
                                                            </a>
                                                            <a href="/teacher/view_notifications.php?id=<?= $student['user_id'] ?>" 
                                                               class="bg-purple-500 hover:bg-purple-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism" 
                                                               data-tooltip="View Notifications" aria-label="View Notifications">
                                                                <i class="fas fa-bell"></i>
                                                            </a>
                                                            <a href="/teacher/message_student.php?id=<?= $student['user_id'] ?>&csrf_token=<?= htmlspecialchars($csrf_token) ?>" 
                                                               class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism" 
                                                               data-tooltip="Send Message" aria-label="Send Message">
                                                                <i class="fas fa-envelope"></i>
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Attendance Visualization -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in">
                <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                    <h3 class="text-xl font-semibold mb-0">Attendance Overview</h3>
                </div>
                <div class="p-6">
                    <div class="chart-container">
                        <canvas id="attendanceChart" aria-label="Attendance overview chart"></canvas>
                    </div>
                </div>
            </section>

            <!-- Quick Links -->
            <section class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8 animate-fade-in-up">
                <a href="/teacher/dashboard.php" class="bg-gradient-to-r from-blue-500 to-blue-600 hover:from-blue-600 hover:to-blue-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism" data-tooltip="Back to Dashboard" aria-label="Dashboard">
                    <i class="fas fa-tachometer-alt block text-xl mb-2"></i>
                    <span class="text-sm">Dashboard</span>
                </a>
                <a href="/teacher/view_classes.php" class="bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism" data-tooltip="View Classes" aria-label="View Classes">
                    <i class="fas fa-chalkboard block text-xl mb-2"></i>
                    <span class="text-sm">Classes</span>
                </a>
                <a href="/teacher/submit_grades.php" class="bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism" data-tooltip="Submit Grades" aria-label="Submit Grades">
                    <i class="fas fa-chart-line block text-xl mb-2"></i>
                    <span class="text-sm">Grades</span>
                </a>
                <a href="/teacher/manage_assignments.php" class="bg-gradient-to-r from-orange-500 to-orange-600 hover:from-orange-600 hover:to-orange-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism" data-tooltip="Manage Assignments" aria-label="Manage Assignments">
                    <i class="fas fa-tasks block text-xl mb-2"></i>
                    <span class="text-sm">Assignments</span>
                </a>
                <a href="/teacher/manage_attendance.php" class="bg-gradient-to-r from-yellow-500 to-yellow-600 hover:from-yellow-600 hover:to-yellow-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism" data-tooltip="Manage Attendance" aria-label="Manage Attendance">
                    <i class="fas fa-check-circle block text-xl mb-2"></i>
                    <span class="text-sm">Attendance</span>
                </a>
                <a href="/teacher/edit_user.php?id=<?= $teacher_id ?>" class="bg-gradient-to-r from-red-500 to-red-600 hover:from-red-600 hover:to-red-700 text-white px-4 py-3 rounded-xl font-medium text-center transition-all duration-300 hover:shadow-lg hover:-translate-y-1 glassmorphism" data-tooltip="Edit Profile" aria-label="Edit Profile">
                    <i class="fas fa-edit block text-xl mb-2"></i>
                    <span class="text-sm">Edit Profile</span>
                </a>
            </section>

            <!-- Print Section -->
            <div class="print-section">
                <div class="print-header">
                    <img src="/assets/images/logo.png" alt="QueensPa School Logo">
                    <h2>QueensPa School</h2>
                    <p>P.O. Box 123, Accra, Ghana | info@qpmsedu.com</p>
                    <p>Teacher Student List - <?= date('F j, Y') ?> | Term: <?= htmlspecialchars($current_term['term_name'] ?? 'N/A') ?> | Academic Year: <?= htmlspecialchars($selected_academic_year) ?></p>
                </div>
                <?php foreach ($print_students as $class_name => $class_students): ?>
                    <h3 class="print-group-header"><?= htmlspecialchars($class_name) ?> (<?= count($class_students) ?> Students)</h3>
                    <table class="print-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Academic Year</th>
                                <th>Attendance</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Gender</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($class_students as $student): ?>
                                <tr>
                                    <td><?= htmlspecialchars($student['student_name']) ?></td>
                                    <td><?= htmlspecialchars($student['academic_year']) ?></td>
                                    <td><?= $student['present_count'] ?> / <?= $student['total_sessions'] ?></td>
                                    <td><?= htmlspecialchars($student['email']) ?></td>
                                    <td><?= htmlspecialchars($student['phone']) ?></td>
                                    <td><?= htmlspecialchars($student['gender']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endforeach; ?>
                <div class="print-footer">
                    <p>Generated by QueensPa School Management System</p>
                </div>
            </div>

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
        const attendanceData = <?= json_encode($attendance_data) ?>;
        const attendanceLabels = attendanceData.map(item => `${item.name} (${item.class})`);
        const attendanceValues = attendanceData.map(item => item.attendance_rate);

        // Initialize chart
        let attendanceChart;

        function initializeChart() {
            const ctx = document.getElementById('attendanceChart').getContext('2d');
            attendanceChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: attendanceLabels,
                    datasets: [{
                        label: 'Attendance Rate (%)',
                        data: attendanceValues,
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
                            max: 100,
                            title: { display: true, text: 'Attendance Rate (%)', color: '#fff', font: { family: 'Inter', size: 12 } },
                            ticks: { color: '#fff', font: { family: 'Inter' } },
                            grid: { color: 'rgba(255, 255, 255, 0.1)' },
                        },
                        x: {
                            ticks: { color: '#fff', font: { family: 'Inter' }, maxRotation: 45, minRotation: 45 },
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
            attendanceChart.data.datasets[0].backgroundColor = createGradient(
                document.getElementById('attendanceChart').getContext('2d'),
                document.body.classList.contains('dark') ? ['#f97316', '#ea580c'] : ['#f97316', '#ea580c']
            );
            attendanceChart.options.scales.y.grid.color = document.body.classList.contains('dark') ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
            attendanceChart.options.scales.y.ticks.color = document.body.classList.contains('dark') ? '#fff' : '#000';
            attendanceChart.options.scales.x.ticks.color = document.body.classList.contains('dark') ? '#fff' : '#000';
            attendanceChart.options.scales.y.title.color = document.body.classList.contains('dark') ? '#fff' : '#000';
            attendanceChart.update();
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

            // Search and filter students
            const searchInput = document.getElementById('student-search');
            const classFilter = document.getElementById('class-filter');
            const deptFilter = document.getElementById('department-filter');
            const studentCards = document.querySelectorAll('.student-card');
            const classSections = document.querySelectorAll('[data-class]');

            function filterStudents() {
                const searchTerm = searchInput.value.toLowerCase();
                const classValue = classFilter.value;
                const deptValue = deptFilter.value;

                classSections.forEach(section => {
                    const className = section.getAttribute('data-class');
                    const deptId = section.getAttribute('data-department');
                    const matchesClass = !classValue || className === classValue;
                    const matchesDept = !deptValue || deptId === deptValue;
                    section.style.display = (matchesClass && matchesDept) ? 'block' : 'none';
                });

                studentCards.forEach(card => {
                    const studentName = card.getAttribute('data-student-name').toLowerCase();
                    const cardClass = card.getAttribute('data-class');
                    const cardDept = card.getAttribute('data-department');
                    const matchesSearch = studentName.includes(searchTerm);
                    const matchesClass = !classValue || cardClass === classValue;
                    const matchesDept = !deptValue || cardDept === deptValue;
                    card.style.display = (matchesSearch && matchesClass && matchesDept) ? 'block' : 'none';
                });
            }

            searchInput.addEventListener('input', filterStudents);
            classFilter.addEventListener('change', filterStudents);
            deptFilter.addEventListener('change', filterStudents);

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

            // Refresh metrics and notifications
            const refreshMetrics = document.getElementById('refresh-metrics');
            const refreshNotifications = document.getElementById('refresh-notifications');
            [refreshMetrics, refreshNotifications].forEach(button => {
                button.addEventListener('click', () => {
                    window.location.reload();
                });
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

        function printContent() {
            window.print();
        }
    </script>
</body>
</html>