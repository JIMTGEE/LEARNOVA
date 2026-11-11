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
$user_check_sql = "SELECT id, department_id FROM users WHERE id = ? AND role = 'student'";
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
    $student_department_id = $user['department_id'];
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
$action = "Accessed Manage Student Assignments Page";
$description = "Student accessed manage assignments page";
logActivity($conn, $student_id, $action, $description);

// Generate and store CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
error_log("Generated CSRF token: $csrf_token");

// Fetch current term
$current_term_sql = "SELECT id FROM academic_calendar 
                     WHERE start_date <= CURDATE() AND end_date >= CURDATE() 
                     LIMIT 1";
$current_term_stmt = $conn->prepare($current_term_sql);
$current_term_id = 1;
if ($current_term_stmt) {
    $current_term_stmt->execute();
    $current_term = $current_term_stmt->get_result()->fetch_assoc();
    $current_term_id = $current_term['id'] ?? 1;
    $current_term_stmt->close();
} else {
    error_log("Prepare failed for current term query: " . $conn->error);
}

// Fetch classes the student is enrolled in
$classes_sql = "SELECT DISTINCT c.id, c.class_name 
                FROM classes c 
                JOIN student_classes sc ON c.id = sc.class_id 
                WHERE sc.user_id = ? AND sc.academic_year = '2024/2025'";
$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->bind_param("i", $student_id);
$classes_stmt->execute();
$classes = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$classes_stmt->close();

// Fetch subjects for the student's classes
$subjects_sql = "SELECT DISTINCT s.id, s.subject_name 
                 FROM subjects s 
                 JOIN class_schedules cs ON s.id = cs.subject_id 
                 JOIN student_classes sc ON cs.class_id = sc.class_id 
                 WHERE sc.user_id = ? AND sc.academic_year = '2024/2025'";
$subjects_stmt = $conn->prepare($subjects_sql);
$subjects_stmt->bind_param("i", $student_id);
$subjects_stmt->execute();
$subjects = $subjects_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$subjects_stmt->close();

// Fetch assignments with pagination, filters, and sorting
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$search = trim(htmlspecialchars(filter_input(INPUT_GET, 'search', FILTER_UNSAFE_RAW) ?? ''));
$status_filter = trim(htmlspecialchars(filter_input(INPUT_GET, 'status', FILTER_UNSAFE_RAW) ?? ''));
$class_filter = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
$subject_filter = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT) ?: 0;
$sort_by = trim(htmlspecialchars(filter_input(INPUT_GET, 'sort_by', FILTER_UNSAFE_RAW) ?? 'due_date'));
$sort_order = trim(htmlspecialchars(filter_input(INPUT_GET, 'sort_order', FILTER_UNSAFE_RAW) ?? 'DESC'));

$valid_sort_columns = ['title', 'due_date', 'submission_status', 'type', 'max_points'];
$sort_by = in_array($sort_by, $valid_sort_columns) ? $sort_by : 'due_date';
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

$where_conditions = ["(sc.user_id = ? OR gm.user_id = ?)"];
$params = [$student_id, $student_id];
$types = "ii";

if ($search) {
    $where_conditions[] = "(a.title LIKE ? OR a.description LIKE ? OR a.tags LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($status_filter && in_array($status_filter, ['pending', 'submitted', 'graded', 'late'])) {
    $where_conditions[] = "COALESCE(sub.status, 'pending') = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($class_filter) {
    $where_conditions[] = "a.class_id = ?";
    $params[] = $class_filter;
    $types .= "i";
}

if ($subject_filter) {
    $where_conditions[] = "a.subject_id = ?";
    $params[] = $subject_filter;
    $types .= "i";
}

$where_clause = implode(" AND ", $where_conditions);

$count_sql = "SELECT COUNT(DISTINCT a.id) as total 
              FROM assignments a 
              LEFT JOIN student_classes sc ON a.class_id = sc.class_id 
              LEFT JOIN group_members gm ON a.group_id = gm.group_id 
              LEFT JOIN assignment_submissions sub ON a.id = sub.assignment_id 
              AND (sub.user_id = ? OR sub.group_id = a.group_id)
              WHERE $where_clause";
$params_count = array_merge([$student_id], $params);
$types_count = "i" . $types;
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types_count, ...$params_count);
$count_stmt->execute();
$total_assignments = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();
$total_pages = ceil($total_assignments / $per_page);

$assignments_sql = "SELECT DISTINCT a.id, a.title, a.description, a.type, a.max_points, a.due_date, a.status, 
                           a.is_group_assignment, a.group_id, a.file_path, a.questions, 
                           c.class_name, s.subject_name, g.name as group_name,
                           sub.id as submission_id, sub.status as submission_status, 
                           sub.score, sub.feedback, sub.file_paths as submission_files,
                           sub.answers as submission_answers
                    FROM assignments a
                    LEFT JOIN classes c ON a.class_id = c.id
                    LEFT JOIN subjects s ON a.subject_id = s.id
                    LEFT JOIN student_classes sc ON a.class_id = sc.class_id
                    LEFT JOIN group_members gm ON a.group_id = gm.group_id
                    LEFT JOIN groups g ON a.group_id = g.id
                    LEFT JOIN assignment_submissions sub ON a.id = sub.assignment_id 
                    AND (sub.user_id = ? OR sub.group_id = a.group_id)
                    WHERE $where_clause
                    ORDER BY a.$sort_by $sort_order
                    LIMIT ? OFFSET ?";
$params[] = $student_id;
$params[] = $per_page;
$params[] = $offset;
$types .= "iii";
$assignments_stmt = $conn->prepare($assignments_sql);
if (!$assignments_stmt) {
    error_log("Prepare failed for assignments query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch assignments.";
    header("Location: /student/dashboard.php");
    exit();
}
$assignments_stmt->bind_param($types, ...$params);
$assignments_stmt->execute();
$assignments_result = $assignments_stmt->get_result();
$assignments = $assignments_result->fetch_all(MYSQLI_ASSOC);
$assignments_stmt->close();

// Pre-process assignments to cache JSON parsing
$assignment_cache = [];
foreach ($assignments as &$assignment) {
    $assignment['questions_parsed'] = $assignment['questions'] ? json_decode($assignment['questions'], true) : null;
    $assignment['answers_parsed'] = $assignment['submission_answers'] ? json_decode($assignment['submission_answers'], true) : null;
    $assignment_cache[$assignment['id']] = $assignment;
}
unset($assignment);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage assignments and submissions for QueensPa School students.">
    <meta name="keywords" content="QueensPa School, manage assignments, student dashboard, assignment submission">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Manage Assignments - QueensPa School">
    <meta property="og:description" content="Central hub for students to manage assignments and submit work at QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/student/manage_student_assignments.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Manage Assignments - QueensPa School">
    <meta name="twitter:description" content="Central hub for students to manage assignments and submit work at QueensPa School.">
    <title>Manage Assignments - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script defer src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" defer>

    <!-- jQuery -->
    <script defer src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>

    <!-- Tailwind Config -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Poppins', 'ui-sans-serif', 'system-ui'],
                    },
                    colors: {
                        primary: {
                            50: '#e6f4fa',
                            100: '#b3e0f2',
                            200: '#80cbea',
                            300: '#4db6e2',
                            400: '#1aa2da',
                            500: '#0288d1',
                            600: '#0270a8',
                            700: '#01587f',
                            800: '#014056',
                            900: '#00282d',
                        },
                        accent: {
                            500: '#4caf50',
                            600: '#43a047',
                        },
                    },
                    animation: {
                        'slide-up': 'slideUp 0.3s ease-out',
                        'fade-in': 'fadeIn 0.3s ease-out',
                        'scale-in': 'scaleIn 0.2s ease-out',
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
                        scaleIn: {
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
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.6;
        }

        ::-webkit-scrollbar {
            width: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f5f9;
        }

        ::-webkit-scrollbar-thumb {
            background: #94a3b8;
            border-radius: 3px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #64748b;
        }

        .dark ::-webkit-scrollbar-track {
            background: #1f2937;
        }

        .dark ::-webkit-scrollbar-thumb {
            background: #6b7280;
        }

        .dark ::-webkit-scrollbar-thumb:hover {
            background: #9ca3af;
        }

        .invalid-feedback {
            display: none;
            color: #dc3545;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }

        .is-invalid ~ .invalid-feedback {
            display: block;
        }

        .form-control:focus,
        .form-select:focus {
            box-shadow: 0 0 0 3px rgba(2, 136, 209, 0.1);
            border-color: #0288d1;
        }

        .btn-primary, .btn-accent {
            transition: transform 0.2s, background-color 0.3s;
        }

        .btn-primary:hover, .btn-accent:hover {
            transform: translateY(-1px);
        }

        input:invalid, select:invalid {
            border-color: #dc3545;
        }

        input:valid, select:valid {
            border-color: #4caf50;
        }

        .table-row:hover {
            background-color: #f1f5f9;
            transition: background-color 0.2s;
        }

        .dark .table-row:hover {
            background-color: #374151;
        }

        .modal-content {
            max-height: 80vh;
            overflow-y: auto;
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

        .file-icon::before {
            font-family: 'Font Awesome 5 Free';
            font-weight: 900;
            margin-right: 0.5rem;
        }

        .file-pdf::before { content: '\f1c1'; }
        .file-doc::before { content: '\f1c2'; }
        .file-img::before { content: '\f1c5'; }
        .file-other::before { content: '\f15b'; }
    </style>
</head>
<body class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-primary-500 text-5xl"></i>
    </div>

    <!-- Details Modal -->
    <div id="details-modal" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-4xl w-full modal-content animate-scale-in">
            <div class="bg-primary-600 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
                <h3 class="text-lg font-semibold" id="modal-title">Assignment Details</h3>
                <button id="close-details" class="text-white hover:text-gray-200" aria-label="Close details modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div id="modal-loading" class="p-6 flex justify-center items-center">
                <i class="fas fa-spinner fa-spin text-primary-500 text-2xl"></i>
            </div>
            <div id="modal-content" class="p-6 space-y-4 hidden">
                <div class="border border-gray-200 dark:border-gray-600 rounded-lg">
                    <div class="collapsible-header bg-gray-50 dark:bg-gray-700 px-4 py-2 flex justify-between items-center">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-200">Description</h4>
                        <i class="fas fa-chevron-down text-gray-500 dark:text-gray-400"></i>
                    </div>
                    <div class="collapsible-content p-4">
                        <p id="modal-description" class="text-gray-600 dark:text-gray-300"></p>
                    </div>
                </div>
                <div class="border border-gray-200 dark:border-gray-600 rounded-lg">
                    <div class="collapsible-header bg-gray-50 dark:bg-gray-700 px-4 py-2 flex justify-between items-center">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-200">Questions</h4>
                        <i class="fas fa-chevron-down text-gray-500 dark:text-gray-400"></i>
                    </div>
                    <div class="collapsible-content p-4 space-y-2" id="modal-questions"></div>
                </div>
                <div id="modal-submission" class="border border-gray-200 dark:border-gray-600 rounded-lg hidden">
                    <div class="collapsible-header bg-gray-50 dark:bg-gray-700 px-4 py-2 flex justify-between items-center">
                        <h4 class="text-sm font-medium text-gray-700 dark:text-gray-200">Your Submission</h4>
                        <i class="fas fa-chevron-down text-gray-500 dark:text-gray-400"></i>
                    </div>
                    <div class="collapsible-content p-4 space-y-2">
                        <div id="modal-answers"></div>
                        <div id="modal-submission-files" class="mt-2"></div>
                        <div id="modal-score" class="mt-2 font-medium"></div>
                        <div id="modal-feedback" class="mt-2 flex items-center">
                            <span class="flex-1"></span>
                            <button id="copy-feedback" class="text-primary-500 hover:text-primary-600 text-sm hidden" aria-label="Copy feedback">
                                <i class="fas fa-copy mr-1"></i> Copy
                            </button>
                        </div>
                    </div>
                </div>
                <div id="modal-assignment-file" class="border border-gray-200 dark:border-gray-600 rounded-lg p-4"></div>
            </div>
            <div class="flex justify-end space-x-3 p-6 border-t border-gray-200 dark:border-gray-600">
                <button id="cancel-details" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-primary" aria-label="Close">
                    <i class="fas fa-times mr-2"></i> Close
                </button>
                <a id="modal-submit-btn" href="#" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-primary hidden" aria-label="Submit assignment">
                    <i class="fas fa-edit mr-2"></i> Submit
                </a>
            </div>
        </div>
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

    <div class="content">
        <main class="container mx-auto px-4 py-12 max-w-7xl">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-center mb-10 animate-slide-up">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100 flex items-center">
                    <i class="fas fa-clipboard-list text-primary-500 mr-3"></i> Manage Assignments
                </h1>
                <a href="/student/dashboard.php" class="mt-4 sm:mt-0 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center btn-primary" aria-label="Back to dashboard">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Dashboard
                </a>
            </div>

            <!-- Messages -->
            <?php if (isset($error_msg)): ?>
                <div class="bg-red-50 dark:bg-red-900/50 border border-red-200 dark:border-red-700 text-red-700 dark:text-red-200 px-4 py-3 rounded-lg mb-6 flex items-center animate-slide-up" role="alert">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($error_msg) ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success_msg'])): ?>
                <div class="bg-green-50 dark:bg-green-900/50 border border-green-200 dark:border-green-700 text-green-700 dark:text-green-200 px-4 py-3 rounded-lg mb-6 flex items-center animate-slide-up" role="alert">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?= htmlspecialchars($_SESSION['success_msg']) ?>
                    <?php unset($_SESSION['success_msg']); ?>
                </div>
            <?php endif; ?>

            <!-- Filters and Search -->
            <section class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 mb-8 animate-slide-up">
                <div class="flex flex-col sm:flex-row gap-4">
                    <div class="flex-1">
                        <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Search</label>
                        <input type="text" id="search" class="form-control w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-gray-200" placeholder="Search by title, description, or tags" value="<?= htmlspecialchars($search) ?>" aria-label="Search assignments">
                    </div>
                    <div>
                        <label for="status_filter" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Status</label>
                        <select id="status_filter" class="form-select w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-gray-200" aria-label="Filter by status">
                            <option value="">All Statuses</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="submitted" <?= $status_filter === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                            <option value="graded" <?= $status_filter === 'graded' ? 'selected' : '' ?>>Graded</option>
                            <option value="late" <?= $status_filter === 'late' ? 'selected' : '' ?>>Late</option>
                        </select>
                    </div>
                    <div>
                        <label for="class_filter" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Class</label>
                        <select id="class_filter" class="form-select w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-gray-200" aria-label="Filter by class">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $class_filter == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="subject_filter" class="block text-sm font-medium text-gray-700 dark:text-gray-200 mb-1">Subject</label>
                        <select id="subject_filter" class="form-select w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:bg-gray-700 dark:text-gray-200" aria-label="Filter by subject">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= $subject['id'] ?>" <?= $subject_filter == $subject['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($subject['subject_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </section>

            <!-- Assignments Table -->
            <section class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 animate-slide-up">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-600">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer" data-sort="title">
                                    Title <?= $sort_by === 'title' ? ($sort_order === 'ASC' ? '<i class="fas fa-sort-up ml-1"></i>' : '<i class="fas fa-sort-down ml-1"></i>') : '<i class="fas fa-sort ml-1"></i>' ?>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Class</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subject</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer" data-sort="max_points">
                                    Points <?= $sort_by === 'max_points' ? ($sort_order === 'ASC' ? '<i class="fas fa-sort-up ml-1"></i>' : '<i class="fas fa-sort-down ml-1"></i>') : '<i class="fas fa-sort ml-1"></i>' ?>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer" data-sort="due_date">
                                    Due Date <?= $sort_by === 'due_date' ? ($sort_order === 'ASC' ? '<i class="fas fa-sort-up ml-1"></i>' : '<i class="fas fa-sort-down ml-1"></i>') : '<i class="fas fa-sort ml-1"></i>' ?>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Group</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider cursor-pointer" data-sort="submission_status">
                                    Status <?= $sort_by === 'submission_status' ? ($sort_order === 'ASC' ? '<i class="fas fa-sort-up ml-1"></i>' : '<i class="fas fa-sort-down ml-1"></i>') : '<i class="fas fa-sort ml-1"></i>' ?>
                                </th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Score</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-700 divide-y divide-gray-200 dark:divide-gray-600">
                            <?php if (empty($assignments)): ?>
                                <tr>
                                    <td colspan="10" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No assignments found. Try adjusting your filters or search terms.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($assignments as $assignment): ?>
                                    <tr class="table-row">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-gray-100">
                                            <?= htmlspecialchars($assignment['title']) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <?= htmlspecialchars($assignment['class_name'] ?? 'N/A') ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <?= htmlspecialchars($assignment['subject_name'] ?? 'N/A') ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <?= ucfirst($assignment['type']) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <?= number_format($assignment['max_points'], 2) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <?= $assignment['due_date'] ? date('Y-m-d', strtotime($assignment['due_date'])) : 'Not set' ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <?= $assignment['is_group_assignment'] ? htmlspecialchars($assignment['group_name'] ?? 'N/A') : 'Individual' ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                <?= $assignment['submission_status'] === 'graded' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 
                                                    ($assignment['submission_status'] === 'submitted' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 
                                                    ($assignment['submission_status'] === 'late' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200')) ?>" 
                                                title="Status: <?= ucfirst($assignment['submission_status'] ?? 'pending') ?>">
                                                <?= $assignment['submission_status'] ? ucfirst($assignment['submission_status']) : 'Pending' ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <?= $assignment['score'] !== null ? number_format($assignment['score'], 2) . ' / ' . number_format($assignment['max_points'], 2) : '-' ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                            <?php if ($assignment['file_path']): ?>
                                                <a href="<?= htmlspecialchars($assignment['file_path']) ?>" 
                                                   class="text-blue-500 hover:text-blue-600" 
                                                   aria-label="Download assignment file" 
                                                   target="_blank">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($assignment['submission_files']): ?>
                                                <?php foreach (explode(',', $assignment['submission_files']) as $file): ?>
                                                    <a href="<?= htmlspecialchars($file) ?>" 
                                                       class="text-blue-500 hover:text-blue-600" 
                                                       aria-label="Download submission file" 
                                                       target="_blank">
                                                        <i class="fas fa-file-download"></i>
                                                    </a>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                            <a href="#" 
                                               class="text-primary-500 hover:text-primary-600 view-details" 
                                               data-assignment-id="<?= $assignment['id'] ?>" 
                                               aria-label="View assignment details">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <?php if ($assignment['status'] === 'published' && !$assignment['submission_id'] && (!$assignment['due_date'] || strtotime($assignment['due_date']) >= time())): ?>
                                                <a href="/student/view_assignments.php?assignment_id=<?= $assignment['id'] ?>" 
                                                   class="text-accent-500 hover:text-accent-600" 
                                                   aria-label="Submit assignment">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                    <div class="mt-6 flex justify-between items-center">
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            Showing <?= $offset + 1 ?> to <?= min($offset + $per_page, $total_assignments) ?> of <?= $total_assignments ?> assignments
                        </div>
                        <div class="flex space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&class_id=<?= $class_filter ?>&subject_id=<?= $subject_filter ?>&sort_by=<?= $sort_by ?>&sort_order=<?= $sort_order ?>" 
                                   class="bg-primary-500 hover:bg-primary-600 text-white px-3 py-1 rounded-lg text-sm font-medium transition-colors btn-primary" 
                                   aria-label="Previous page">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&class_id=<?= $class_filter ?>&subject_id=<?= $subject_filter ?>&sort_by=<?= $sort_by ?>&sort_order=<?= $sort_order ?>" 
                                   class="px-3 py-1 rounded-lg text-sm font-medium transition-colors <?= $i == $page ? 'bg-primary-500 text-white' : 'bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-200 hover:bg-gray-300 dark:hover:bg-gray-500' ?>" 
                                   aria-label="Page <?= $i ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&class_id=<?= $class_filter ?>&subject_id=<?= $subject_filter ?>&sort_by=<?= $sort_by ?>&sort_order=<?= $sort_order ?>" 
                                   class="bg-primary-500 hover:bg-primary-600 text-white px-3 py-1 rounded-lg text-sm font-medium transition-colors btn-primary" 
                                   aria-label="Next page">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        </main>
    </div>

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
    mysqli_close($conn);
    ob_end_flush();
    ?>

    <script>
        // Cache DOM elements
        const loadingOverlay = document.getElementById('loading-overlay');
        const detailsModal = document.getElementById('details-modal');
        const modalLoading = document.getElementById('modal-loading');
        const modalContent = document.getElementById('modal-content');
        const closeDetails = document.getElementById('close-details');
        const cancelDetails = document.getElementById('cancel-details');
        const modalTitle = document.getElementById('modal-title');
        const modalDescription = document.getElementById('modal-description');
        const modalQuestions = document.getElementById('modal-questions');
        const modalSubmission = document.getElementById('modal-submission');
        const modalAnswers = document.getElementById('modal-answers');
        const modalSubmissionFiles = document.getElementById('modal-submission-files');
        const modalScore = document.getElementById('modal-score');
        const modalFeedback = document.getElementById('modal-feedback').querySelector('span');
        const copyFeedback = document.getElementById('copy-feedback');
        const modalAssignmentFile = document.getElementById('modal-assignment-file');
        const modalSubmitBtn = document.getElementById('modal-submit-btn');

        // Assignment cache from PHP
        const assignmentCache = <?php echo json_encode($assignment_cache, JSON_HEX_QUOT | JSON_HEX_APOS); ?>;

        // Utility to escape HTML
        function escapeHTML(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // Get file type class
        function getFileTypeClass(file) {
            const ext = file.split('.').pop().toLowerCase();
            if (ext === 'pdf') return 'file-pdf';
            if (['doc', 'docx'].includes(ext)) return 'file-doc';
            if (['png', 'jpg', 'jpeg', 'gif'].includes(ext)) return 'file-img';
            return 'file-other';
        }

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

        // Auto-dismiss alerts
        document.querySelectorAll('[role="alert"]').forEach(alert => {
            setTimeout(() => {
                alert.classList.add('opacity-0');
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        });

        // Filter and search
        const filterInputs = document.querySelectorAll('#search, #status_filter, #class_filter, #subject_filter');
        const updateFilters = debounce(() => {
            const search = document.getElementById('search').value;
            const status = document.getElementById('status_filter').value;
            const classId = document.getElementById('class_filter').value;
            const subjectId = document.getElementById('subject_filter').value;
            const sortBy = '<?= $sort_by ?>';
            const sortOrder = '<?= $sort_order ?>';
            window.location.href = `?search=${encodeURIComponent(search)}&status=${encodeURIComponent(status)}&class_id=${classId}&subject_id=${subjectId}&sort_by=${sortBy}&sort_order=${sortOrder}`;
        }, 300);
        filterInputs.forEach(input => input.addEventListener('input', updateFilters));
        filterInputs.forEach(input => input.addEventListener('change', updateFilters));

        // Sort table
        document.querySelectorAll('th[data-sort]').forEach(th => {
            th.addEventListener('click', () => {
                const sortBy = th.dataset.sort;
                const currentSortBy = '<?= $sort_by ?>';
                const currentSortOrder = '<?= $sort_order ?>';
                const sortOrder = (sortBy === currentSortBy && currentSortOrder === 'ASC') ? 'DESC' : 'ASC';
                const search = '<?= urlencode($search) ?>';
                const status = '<?= urlencode($status_filter) ?>';
                const classId = '<?= $class_filter ?>';
                const subjectId = '<?= $subject_filter ?>';
                window.location.href = `?search=${search}&status=${status}&class_id=${classId}&subject_id=${subjectId}&sort_by=${sortBy}&sort_order=${sortOrder}`;
            });
        });

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

        // View details
        document.querySelectorAll('.view-details').forEach(button => {
            button.addEventListener('click', (e) => {
                e.preventDefault();
                modalLoading.classList.remove('hidden');
                modalContent.classList.add('hidden');
                detailsModal.classList.remove('hidden');

                const assignmentId = button.dataset.assignmentId;
                const assignment = assignmentCache[assignmentId];

                requestAnimationFrame(() => {
                    // Populate modal
                    modalTitle.textContent = assignment.title || 'Untitled Assignment';
                    modalDescription.textContent = assignment.description || 'No description provided.';

                    // Questions
                    const questionsFragment = document.createDocumentFragment();
                    if (assignment.questions_parsed && Array.isArray(assignment.questions_parsed) && assignment.questions_parsed.length > 0) {
                        let questionsHTML = '';
                        assignment.questions_parsed.forEach((q, index) => {
                            questionsHTML += `
                                <div class="border border-gray-200 dark:border-gray-600 p-3 rounded-lg">
                                    <p class="font-medium text-gray-700 dark:text-gray-200">Question ${index + 1}: ${escapeHTML(q.question || 'N/A')}</p>
                                    <p class="text-gray-500 dark:text-gray-400 text-sm">Type: ${q.type || 'Unknown'}</p>
                            `;
                            if (q.type === 'multiple_choice' && Array.isArray(q.options)) {
                                questionsHTML += `
                                    <ul class="list-disc pl-5 text-gray-600 dark:text-gray-300">
                                        ${q.options.map(opt => `<li>${escapeHTML(opt)}${q.correct_answer === opt ? ' <span class="text-green-500 dark:text-green-400">(Correct)</span>' : ''}</li>`).join('')}
                                    </ul>
                                `;
                            }
                            questionsHTML += '</div>';
                        });
                        modalQuestions.innerHTML = questionsHTML;
                    } else {
                        modalQuestions.innerHTML = '<p class="text-gray-500 dark:text-gray-400">No questions provided by the teacher.</p>';
                    }

                    // Submission details
                    if (assignment.submission_id) {
                        modalSubmission.classList.remove('hidden');
                        let answersHTML = '';
                        if (assignment.answers_parsed && Array.isArray(assignment.answers_parsed) && assignment.answers_parsed.length > 0) {
                            assignment.answers_parsed.forEach((a, index) => {
                                let isCorrect = '';
                                if (assignment.questions_parsed && assignment.questions_parsed[index] && assignment.questions_parsed[index].type === 'multiple_choice' && assignment.questions_parsed[index].correct_answer) {
                                    isCorrect = a.answer === assignment.questions_parsed[index].correct_answer ? 
                                        '<span class="text-green-500 dark:text-green-400 ml-2"><i class="fas fa-check"></i> Correct</span>' : 
                                        '<span class="text-red-500 dark:text-red-400 ml-2"><i class="fas fa-times"></i> Incorrect</span>';
                                }
                                answersHTML += `
                                    <div class="border border-gray-200 dark:border-gray-600 p-3 rounded-lg">
                                        <p class="font-medium text-gray-700 dark:text-gray-200">Answer ${index + 1}: ${escapeHTML(a.answer || 'N/A')} ${isCorrect}</p>
                                    </div>
                                `;
                            });
                        } else {
                            answersHTML = '<p class="text-gray-500 dark:text-gray-400">No answers submitted.</p>';
                        }
                        modalAnswers.innerHTML = answersHTML;

                        let filesHTML = '';
                        if (assignment.submission_files) {
                            const files = assignment.submission_files.split(',');
                            if (files.length > 1) {
                                filesHTML += `
                                    <button class="download-all text-blue-500 hover:text-blue-600 text-sm mb-2" data-files='${JSON.stringify(files)}' aria-label="Download all submission files">
                                        <i class="fas fa-download mr-1"></i> Download All Files
                                    </button>
                                `;
                            }
                            files.forEach(file => {
                                const fileClass = getFileTypeClass(file);
                                filesHTML += `
                                    <div>
                                        <a href="${escapeHTML(file)}" class="${fileClass} text-blue-500 hover:text-blue-600" target="_blank" aria-label="Download ${file.split('/').pop()}">
                                            ${escapeHTML(file.split('/').pop())}
                                        </a>
                                    </div>
                                `;
                            });
                        } else {
                            filesHTML = '<p class="text-gray-500 dark:text-gray-400">No files submitted.</p>';
                        }
                        modalSubmissionFiles.innerHTML = filesHTML;

                        modalScore.textContent = assignment.score !== null ? 
                            `Score: ${Number(assignment.score).toFixed(2)} / ${Number(assignment.max_points).toFixed(2)}` : 
                            'Score: Not graded';
                        if (assignment.feedback) {
                            modalFeedback.textContent = `Feedback: ${escapeHTML(assignment.feedback)}`;
                            copyFeedback.classList.remove('hidden');
                        } else {
                            modalFeedback.textContent = 'Feedback: None provided';
                            copyFeedback.classList.add('hidden');
                        }
                    } else {
                        modalSubmission.classList.add('hidden');
                    }

                    // Assignment file
                    if (assignment.file_path) {
                        const fileClass = getFileTypeClass(assignment.file_path);
                        modalAssignmentFile.innerHTML = `
                            <a href="${escapeHTML(assignment.file_path)}" class="${fileClass} text-blue-500 hover:text-blue-600" target="_blank" aria-label="Download assignment file">
                                Download Assignment File
                            </a>
                        `;
                    } else {
                        modalAssignmentFile.innerHTML = '<p class="text-gray-500 dark:text-gray-400">No assignment file provided.</p>';
                    }

                    // Submit button
                    if (assignment.status === 'published' && !assignment.submission_id && (!assignment.due_date || new Date(assignment.due_date) >= new Date())) {
                        modalSubmitBtn.href = `/student/view_assignments.php?assignment_id=${assignment.id}`;
                        modalSubmitBtn.classList.remove('hidden');
                    } else {
                        modalSubmitBtn.classList.add('hidden');
                    }

                    modalLoading.classList.add('hidden');
                    modalContent.classList.remove('hidden');
                });
            });
        });

        // Copy feedback
        copyFeedback.addEventListener('click', () => {
            const feedbackText = modalFeedback.textContent.replace('Feedback: ', '');
            navigator.clipboard.writeText(feedbackText).then(() => {
                copyFeedback.innerHTML = '<i class="fas fa-check mr-1"></i> Copied';
                copyFeedback.classList.add('text-green-500', 'dark:text-green-400');
                setTimeout(() => {
                    copyFeedback.innerHTML = '<i class="fas fa-copy mr-1"></i> Copy';
                    copyFeedback.classList.remove('text-green-500', 'dark:text-green-400');
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy feedback:', err);
                alert('Failed to copy feedback. Please try again.');
            });
        });

        // Download all files (simulated ZIP)
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('download-all')) {
                const files = JSON.parse(e.target.dataset.files);
                alert('Downloading multiple files is not supported in this browser. Please download each file individually.');
            }
        });

        // Close modal
        [closeDetails, cancelDetails].forEach(btn => {
            btn.addEventListener('click', () => {
                detailsModal.classList.add('hidden');
                modalLoading.classList.remove('hidden');
                modalContent.classList.add('hidden');
                document.querySelectorAll('.collapsible-content').forEach(content => content.style.display = 'block');
                document.querySelectorAll('.collapsible-header i').forEach(icon => {
                    icon.classList.remove('fa-chevron-up');
                    icon.classList.add('fa-chevron-down');
                });
            });
        });
    </script>
</body>
</html>