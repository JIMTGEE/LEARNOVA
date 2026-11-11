<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict to students only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a student.";
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
$student_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id, name, profile_picture FROM users WHERE id = ? AND role = 'student'";
$user_check_stmt = $conn->prepare($user_check_sql);
if ($user_check_stmt) {
    $user_check_stmt->bind_param("i", $student_id);
    $user_check_stmt->execute();
    $user_result = $user_check_stmt->get_result();
    if ($user_result->num_rows === 0) {
        error_log("Invalid student_id in session: $student_id");
        $_SESSION['error_msg'] = "Invalid user session.";
        header("Location: /login.php");
        exit();
    }
    $user = $user_result->fetch_assoc();
    $student_name = htmlspecialchars($user['name'] ?? 'Student');
    $profile_picture = $user['profile_picture'] ? htmlspecialchars($user['profile_picture']) : '/assets/default_avatar.png';
    $user_check_stmt->close();
} else {
    error_log("Failed to prepare user check query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$action = "Accessed View Schedule Page";
$description = "Student accessed view schedule page";
logActivity($conn, $student_id, $action, $description);

// Current academic year
$current_academic_year = date('Y') . '/' . (date('Y') + 1);

// Fetch terms for filter
$terms_sql = "SELECT id, term_name FROM academic_calendar WHERE year = ?";
$terms_stmt = $conn->prepare($terms_sql);
if (!$terms_stmt) {
    error_log("Error preparing terms query: " . $conn->error);
}
$terms_stmt->bind_param("s", $current_academic_year);
$terms_stmt->execute();
$terms = $terms_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$terms_stmt->close();

// Days of the week for timetable
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

// Handle filters and view mode
$term_id = filter_input(INPUT_GET, 'term_id', FILTER_VALIDATE_INT) ?: 0;
$day_of_week = filter_input(INPUT_GET, 'day_of_week', FILTER_DEFAULT) ?: '';
$day_of_week = in_array($day_of_week, $days_of_week) ? $day_of_week : '';
$view_mode = filter_input(INPUT_GET, 'view', FILTER_DEFAULT) === 'timetable' ? 'timetable' : 'list';

// Fetch student’s enrolled classes
$classes_sql = "SELECT c.id, c.class_name 
                FROM classes c 
                JOIN student_classes sc ON c.id = sc.class_id 
                WHERE sc.user_id = ? AND sc.academic_year = ?";
$classes_stmt = $conn->prepare($classes_sql);
if (!$classes_stmt) {
    error_log("Error preparing classes query: " . $conn->error);
}
$classes_stmt->bind_param("is", $student_id, $current_academic_year);
$classes_stmt->execute();
$classes = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$classes_stmt->close();
error_log("Fetched " . count($classes) . " enrolled classes for student_id: $student_id");

// Fetch schedules for enrolled classes
$schedules_sql = "
    SELECT cs.id, cs.class_id, cs.subject_id, cs.teacher_id, cs.term_id, cs.day_of_week, 
           cs.start_time, cs.end_time, c.class_name, s.subject_name, 
           CONCAT(t.first_name, ' ', t.last_name) AS teacher_name, ac.term_name
    FROM class_schedules cs
    JOIN classes c ON cs.class_id = c.id
    JOIN subjects s ON cs.subject_id = s.id
    JOIN teachers t ON cs.teacher_id = t.id
    JOIN academic_calendar ac ON cs.term_id = ac.id
    JOIN student_classes sc ON cs.class_id = sc.class_id
    WHERE sc.user_id = ? AND sc.academic_year = ?
";
$params = [$student_id, $current_academic_year];
$types = "is";

if ($term_id > 0) {
    $schedules_sql .= " AND cs.term_id = ?";
    $params[] = $term_id;
    $types .= "i";
}
if (!empty($day_of_week) && $view_mode !== 'timetable') {
    $schedules_sql .= " AND cs.day_of_week = ?";
    $params[] = $day_of_week;
    $types .= "s";
}
$schedules_sql .= " ORDER BY FIELD(cs.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), cs.start_time";

$schedules_stmt = $conn->prepare($schedules_sql);
if (!$schedules_stmt) {
    error_log("Error preparing schedules query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch schedules.";
    header("Location: /student/dashboard.php");
    exit();
}
$schedules_stmt->bind_param($types, ...$params);
$schedules_stmt->execute();
$schedules = $schedules_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$schedules_stmt->close();
error_log("Fetched " . count($schedules) . " schedules for student_id: $student_id, term_id: $term_id, day_of_week: " . ($day_of_week ?: 'all'));

// Group schedules by day for list view
$grouped_schedules = [];
foreach ($schedules as $schedule) {
    $day = $schedule['day_of_week'];
    if (!isset($grouped_schedules[$day])) {
        $grouped_schedules[$day] = [];
    }
    $grouped_schedules[$day][] = $schedule;
}

// Prepare timetable data
$time_slots = [];
$start_hour = 7; // 7:00 AM
$end_hour = 17; // 5:00 PM
for ($hour = $start_hour; $hour < $end_hour; $hour++) {
    $time_slots[] = sprintf("%02d:00", $hour);
}
$timetable = array_fill_keys($days_of_week, array_fill_keys($time_slots, []));
foreach ($schedules as $schedule) {
    $day = $schedule['day_of_week'];
    if (!in_array($day, $days_of_week)) continue;
    $start_time = date('H:00', strtotime($schedule['start_time']));
    $end_time = date('H:00', strtotime($schedule['end_time']));
    // Add to all time slots between start and end to handle multi-hour classes
    for ($hour = (int)date('H', strtotime($start_time)); $hour <= (int)date('H', strtotime($end_time)); $hour++) {
        $slot = sprintf("%02d:00", $hour);
        if (isset($timetable[$day][$slot])) {
            $timetable[$day][$slot][] = $schedule;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View your class schedules at QueensPa School.">
    <meta name="keywords" content="QueensPa School, class schedules, student timetable">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="View Schedule - QueensPa School">
    <meta property="og:description" content="Check your weekly class schedules and stay on top of your studies at QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/student/view_schedule.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="View Schedule - QueensPa School">
    <meta name="twitter:description" content="Check your weekly class schedules and stay on top of your studies at QueensPa School.">
    <title>View Schedule - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer">

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>

    <!-- Tailwind Config -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Inter', 'ui-sans-serif', 'system-ui'],
                    },
                    colors: {
                        primary: {
                            500: '#ff4500',
                            600: '#e03e00',
                        },
                        accent: {
                            500: '#00c4b4',
                            600: '#00a398',
                        },
                    },
                    animation: {
                        'slide-up': 'slideUp 0.3s ease-out',
                        'fade-in': 'fadeIn 0.3s ease-out',
                        'zoom-in': 'zoomIn 0.3s ease-out',
                    },
                    keyframes: {
                        slideUp: {
                            '0%': { transform: 'translateY(20px)', opacity: '0' },
                            '100%': { transform: 'translateY(0)', opacity: '1' },
                        },
                        fadeIn: {
                            '0%': { opacity: '0' },
                            '100%': { opacity: '1' },
                        },
                        zoomIn: {
                            '0%': { transform: 'scale(0.95)', opacity: '0' },
                            '100%': { transform: 'scale(1)', opacity: '1' },
                        },
                    },
                },
                darkMode: 'class',
            },
        };
    </script>

    <style>
        body {
            background: linear-gradient(135deg, #f8f9fa, #fff3e0);
            background-image: url('/assets/images/anime_sparkles.png');
            background-size: cover;
            background-attachment: fixed;
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.6;
            overflow-x: hidden;
        }
        .hero-card {
            background: #ffffff;
            border: 2px solid #ff4500;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }
        .hero-card::before {
            content: '';
            position: absolute;
            background: url('/assets/images/sparkle_effect.png');
            background-size: cover;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            opacity: 0.1;
            z-index: 0;
        }
        .mascot-img {
            position: absolute;
            right: 20px;
            bottom: -10px;
            animation: float 3s ease-in-out infinite;
            z-index: 1;
        }
        .summary-card, .filter-card, .schedule-card {
            background: #ffffff;
            border-radius: 15px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(5px);
        }
        .schedule-card {
            border: 2px solid transparent;
            border-image: linear-gradient(45deg, #ff4500, #00c4b4) 1;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .schedule-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }
        .timetable {
            display: grid;
            grid-template-columns: 100px repeat(5, 1fr);
            gap: 1px;
            background: #e5e7eb;
            border-radius: 8px;
            overflow: hidden;
        }
        .timetable-header, .timetable-cell {
            background: #ffffff;
            padding: 0.5rem;
            text-align: center;
            border: 1px solid #e5e7eb;
        }
        .timetable-header {
            font-weight: 600;
            background: #ffedd5;
        }
        .timetable-cell {
            min-height: 60px;
            position: relative;
        }
        .timetable-cell.schedule {
            background: linear-gradient(135deg, #ffedd5, #fef3c7);
            cursor: pointer;
        }
        .timetable-cell.schedule:hover {
            background: linear-gradient(135deg, #fed7aa, #fde68a);
        }
        .timetable-cell.empty {
            background: #f9fafb;
        }
        .star-accent {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 30px;
            height: 30px;
            background: url('/assets/images/anime_star.png');
            background-size: cover;
            opacity: 0.7;
        }
        .btn-primary {
            background: linear-gradient(45deg, #ff4500, #ff6b00);
            border: none;
            border-radius: 25px;
            padding: 0.5rem 1.5rem;
            transition: transform 0.2s ease;
        }
        .btn-primary:hover {
            transform: scale(1.05);
        }
        .btn-outline-primary {
            border-color: #ff4500;
            color: #ff4500;
            border-radius: 25px;
        }
        .btn-outline-primary:hover {
            background: linear-gradient(45deg, #ff4500, #ff6b00);
            color: white;
        }
        [data-tooltip] {
            position: relative;
        }
        [data-tooltip]::after {
            content: attr(data-tooltip);
            position: absolute;
            background: #ff4500;
            color: white;
            padding: 0.5rem;
            border-radius: 8px;
            font-size: 0.8rem;
            top: -40px;
            left: 50%;
            transform: translateX(-50%);
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
            z-index: 1000;
            white-space: nowrap;
        }
        [data-tooltip]:hover::after {
            opacity: 1;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }
        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
        .ripple span {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.5);
            transform: scale(0);
            animation: ripple 0.6s linear;
            pointer-events: none;
        }
        @media (max-width: 767px) {
            .hero-card h1 {
                font-size: 2rem;
            }
            .mascot-img {
                width: 80px;
                right: 10px;
            }
            .schedule-card {
                margin-bottom: 1rem;
            }
            .timetable {
                grid-template-columns: 80px repeat(5, 1fr);
                font-size: 0.8rem;
            }
            .timetable-cell {
                min-height: 50px;
                padding: 0.3rem;
            }
        }
        .collapsible-header {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .collapsible-header:hover {
            background-color: #f1f5f9;
        }
        .dark .collapsible-header:hover {
            background-color: #374151;
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-primary-500 text-5xl"></i>
    </div>

    <?php
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php';
    try {
        if (!file_exists($header_path)) {
            throw new Exception("Student header file missing: $header_path");
        }
        include $header_path;
    } catch (Exception $e) {
        error_log("Failed to include student_header.php: " . $e->getMessage());
        logActivity($conn, $student_id, "Error", "Failed to load student header template: " . $e->getMessage());
        $_SESSION['error_msg'] = "System error: Unable to load page header.";
        header("Location: /login.php");
        exit();
    }
    ?>

    <main class="container mx-auto px-4 py-12 max-w-7xl">
        <!-- Header Section -->
        <section class="mb-8 text-center animate-slide-up">
            <div class="hero-card relative">
                <div class="card-body p-6">
                    <h1 class="text-4xl font-bold text-gray-800 dark:text-gray-100">Your Schedule, <?= $student_name ?>! 🕒</h1>
                    <p class="text-lg text-gray-600 dark:text-gray-300 mt-2">Plan your week like an anime hero and conquer your classes!</p>
                    <img src="/assets/images/chibi_mascot_schedule.png" alt="Chibi Mascot" class="mascot-img" width="120">
                </div>
            </div>
        </section>

        <!-- Filters -->
        <section class="mb-8">
            <div class="filter-card p-6">
                <form class="grid grid-cols-1 md:grid-cols-4 gap-4" method="GET" action="">
                    <div>
                        <label for="term_id" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Filter by Term</label>
                        <select id="term_id" name="term_id" class="form-select w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-gray-200">
                            <option value="0">All Terms</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?= $term['id'] ?>" <?= $term_id == $term['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($term['term_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="day_of_week" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Filter by Day</label>
                        <select id="day_of_week" name="day_of_week" class="form-select w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-gray-200">
                            <option value="">All Days</option>
                            <?php foreach ($days_of_week as $day): ?>
                                <option value="<?= $day ?>" <?= $day_of_week == $day ? 'selected' : '' ?>>
                                    <?= $day ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="view_mode" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">View Mode</label>
                        <select id="view_mode" name="view" class="form-select w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-gray-200">
                            <option value="list" <?= $view_mode === 'list' ? 'selected' : '' ?>>List View</option>
                            <option value="timetable" <?= $view_mode === 'timetable' ? 'selected' : '' ?>>Timetable View</option>
                        </select>
                    </div>
                    <div class="flex items-end space-x-2">
                        <button type="submit" class="btn-primary text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors" data-tooltip="Apply filters">
                            <i class="fas fa-filter mr-2"></i> Apply Filters
                        </button>
                        <a href="/student/view_schedule.php" class="btn-outline-primary px-4 py-2 rounded-lg text-sm font-medium transition-colors" data-tooltip="Reset filters">
                            <i class="fas fa-undo mr-2"></i> Reset
                        </a>
                        <a href="/student/dashboard.php" class="btn-outline-primary px-4 py-2 rounded-lg text-sm font-medium transition-colors" data-tooltip="Back to Dashboard">
                            <i class="fas fa-arrow-left mr-2"></i> Back
                        </a>
                    </div>
                </form>
            </div>
        </section>

        <!-- Schedules Section -->
        <section>
            <?php if (empty($classes)): ?>
                <div class="text-center">
                    <div class="summary-card p-6 animate-zoom-in">
                        <img src="/assets/images/no_schedule.png" alt="No Classes" width="100" class="mb-3 mx-auto">
                        <h5 class="text-xl font-semibold text-gray-800 dark:text-gray-100">No Classes Enrolled!</h5>
                        <p class="text-gray-600 dark:text-gray-300">You haven’t enrolled in any classes yet. Contact your administrator to get enrolled!</p>
                    </div>
                </div>
            <?php elseif (empty($schedules)): ?>
                <div class="text-center">
                    <div class="summary-card p-6 animate-zoom-in">
                        <img src="/assets/images/no_schedule.png" alt="No Schedule" width="100" class="mb-3 mx-auto">
                        <h5 class="text-xl font-semibold text-gray-800 dark:text-gray-100">No Schedules Found!</h5>
                        <p class="text-gray-600 dark:text-gray-300">Your teachers have added schedules, but none match your current filters or enrollment. Try selecting a different term or resetting the filters.</p>
                    </div>
                </div>
            <?php elseif ($view_mode === 'timetable'): ?>
                <div class="summary-card p-6 animate-zoom-in">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-4">Weekly Timetable</h3>
                    <div class="timetable">
                        <div class="timetable-header"></div>
                        <?php foreach ($days_of_week as $day): ?>
                            <div class="timetable-header"><?= $day ?></div>
                        <?php endforeach; ?>
                        <?php foreach ($time_slots as $time): ?>
                            <div class="timetable-header"><?= date('h:00 A', strtotime($time)) ?></div>
                            <?php foreach ($days_of_week as $day): ?>
                                <div class="timetable-cell <?= empty($timetable[$day][$time]) ? 'empty' : 'schedule' ?>">
                                    <?php foreach ($timetable[$day][$time] as $schedule): ?>
                                        <?php if ($term_id == 0 || $schedule['term_id'] == $term_id): ?>
                                            <div class="text-sm" data-tooltip="<?= htmlspecialchars($schedule['class_name'] . ' - ' . $schedule['subject_name'] . ' (' . $schedule['teacher_name'] . ')') ?>">
                                                <strong><?= htmlspecialchars($schedule['subject_name']) ?></strong><br>
                                                <?= date('h:i A', strtotime($schedule['start_time'])) ?> - <?= date('h:i A', strtotime($schedule['end_time'])) ?><br>
                                                <?= htmlspecialchars($schedule['class_name']) ?><br>
                                                <small><?= htmlspecialchars($schedule['teacher_name']) ?></small>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($days_of_week as $day): ?>
                    <?php if (isset($grouped_schedules[$day])): ?>
                        <div class="summary-card mb-6 p-6 animate-zoom-in">
                            <div class="collapsible-header bg-gray-50 dark:bg-gray-700 px-4 py-2 flex justify-between items-center rounded-t-lg">
                                <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100"><?= $day ?></h3>
                                <i class="fas fa-chevron-down text-gray-500 dark:text-gray-400"></i>
                            </div>
                            <div class="collapsible-content p-4" style="display: none;">
                                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                    <?php foreach ($grouped_schedules[$day] as $schedule): ?>
                                        <div class="schedule-card relative p-4">
                                            <h5 class="text-md font-medium text-gray-800 dark:text-gray-100"><?= htmlspecialchars($schedule['class_name']) ?></h5>
                                            <p class="text-gray-600 dark:text-gray-300"><i class="fas fa-book mr-1"></i> <?= htmlspecialchars($schedule['subject_name']) ?></p>
                                            <p class="text-gray-600 dark:text-gray-300"><i class="fas fa-chalkboard-teacher mr-1"></i> <?= htmlspecialchars($schedule['teacher_name']) ?></p>
                                            <p class="text-gray-600 dark:text-gray-300"><i class="fas fa-clock mr-1"></i> <?= date('h:i A', strtotime($schedule['start_time'])) ?> - <?= date('h:i A', strtotime($schedule['end_time'])) ?></p>
                                            <p class="text-gray-600 dark:text-gray-300"><i class="fas fa-calendar-alt mr-1"></i> Term: <?= htmlspecialchars($schedule['term_name']) ?></p>
                                            <span class="star-accent"></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>

    <?php
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_footer.php';
    try {
        if (!file_exists($footer_path)) {
            throw new Exception("Student footer file missing: $footer_path");
        }
        include $footer_path;
    } catch (Exception $e) {
        error_log("Failed to include student_footer.php: " . $e->getMessage());
        logActivity($conn, $student_id, "Error", "Failed to load student footer template: " . $e->getMessage());
        $_SESSION['error_msg'] = "System error: Unable to load page footer.";
        header("Location: /login.php");
        exit();
    }
    ob_end_flush();
    // Close the connection after all operations
    if (isset($conn) && $conn) {
        mysqli_close($conn);
    }
    ?>

    <script>
        // Cache DOM elements
        const loadingOverlay = document.getElementById('loading-overlay');
        const filterInputs = document.querySelectorAll('#term_id, #day_of_week, #view_mode');

        // Debounce function
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Initialize page
        window.addEventListener('load', () => {
            loadingOverlay.classList.add('hidden');
        });

        // Ripple effects for buttons
        document.querySelectorAll('.btn-primary, .btn-outline-primary').forEach(button => {
            button.addEventListener('click', function(e) {
                const rect = button.getBoundingClientRect();
                const size = Math.max(rect.width, rect.height);
                const x = e.clientX - rect.left - size / 2;
                const y = e.clientY - rect.top - size / 2;

                const ripple = document.createElement('span');
                ripple.style.width = ripple.style.height = `${size}px`;
                ripple.style.left = `${x}px`;
                ripple.style.top = `${y}px`;
                ripple.classList.add('ripple');
                button.style.position = 'relative';
                button.style.overflow = 'hidden';
                button.appendChild(ripple);

                setTimeout(() => ripple.remove(), 600);
            });
        });

        // Filter and view mode handling
        const updateFilters = debounce(() => {
            const termId = document.getElementById('term_id').value;
            const dayOfWeek = document.getElementById('day_of_week').value;
            const viewMode = document.getElementById('view_mode').value;
            window.location.href = `?term_id=${termId}&day_of_week=${encodeURIComponent(dayOfWeek)}&view=${viewMode}`;
        }, 300);
        filterInputs.forEach(input => input.addEventListener('change', updateFilters));

        // Collapsible sections
        document.querySelectorAll('.collapsible-header').forEach(header => {
            header.addEventListener('click', () => {
                const content = header.nextElementSibling;
                const icon = header.querySelector('i');
                content.style.display = content.style.display === 'none' ? 'block' : 'none';
                icon.classList.toggle('fa-chevron-down');
                icon.classList.toggle('fa-chevron-up');
            });
        });
    </script>
</body>
</html>