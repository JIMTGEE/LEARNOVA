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
$user_check_sql = "SELECT id, department_id FROM users WHERE id = ? AND role = 'teacher'";
$user_check_stmt = $conn->prepare($user_check_sql);
if ($user_check_stmt) {
    $user_check_stmt->bind_param("i", $teacher_id);
    $user_check_stmt->execute();
    $user_result = $user_check_stmt->get_result();
    if ($user_result->num_rows === 0) {
        error_log("Invalid teacher_id in session: $teacher_id");
        $_SESSION['error_msg'] = "Invalid user session.";
        header("Location: /login.php");
        exit();
    }
    $user = $user_result->fetch_assoc();
    $teacher_department_id = $user['department_id'];
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
$action = "Accessed Manage Assignments Page";
$description = "Teacher accessed manage assignments page";
logActivity($conn, $teacher_id, $action, $description);

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
error_log("Generated CSRF token: $csrf_token");

// Fetch current term
$current_term_sql = "SELECT id FROM academic_calendar WHERE start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1";
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

// Fetch departments
$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_result = $conn->query($departments_sql);
$departments = [];
if ($departments_result) {
    while ($row = $departments_result->fetch_assoc()) {
        $departments[] = $row;
    }
} else {
    error_log("Failed to fetch departments: " . $conn->error);
}

// Handle AJAX requests for status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid CSRF token.");
        }
        $assignment_id = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
        $status = trim(filter_input(INPUT_POST, 'status', FILTER_UNSAFE_RAW));
        $valid_statuses = ['draft', 'published', 'closed'];
        if (!$assignment_id || !in_array($status, $valid_statuses)) {
            throw new Exception("Invalid assignment ID or status.");
        }
        $update_sql = "UPDATE assignments SET status = ? WHERE id = ? AND teacher_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        if (!$update_stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $update_stmt->bind_param("sii", $status, $assignment_id, $teacher_id);
        $update_stmt->execute();
        if ($update_stmt->affected_rows > 0) {
            logActivity($conn, $teacher_id, "Updated Assignment Status", "Changed status of assignment ID $assignment_id to $status");
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $response['success'] = true;
            $response['message'] = "Status updated to $status.";
        } else {
            throw new Exception("No assignment found or unauthorized.");
        }
        $update_stmt->close();
    } catch (Exception $e) {
        error_log("Status update error: " . $e->getMessage());
        $response['message'] = $e->getMessage();
    }
    echo json_encode($response);
    exit();
}

// Handle filters and search
$department_id = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT) ?: null;
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: null;
$subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_VALIDATE_INT) ?: null;
$search = trim(filter_input(INPUT_GET, 'search', FILTER_UNSAFE_RAW) ?? '');
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?: 1;
$per_page = 10;

// Build query for assignments
$assignments_sql = "
    SELECT a.id, a.title, a.type, a.due_date, a.status, a.max_points, 
           c.class_name, s.subject_name, 
           (SELECT COUNT(*) FROM assignment_submissions subm WHERE subm.assignment_id = a.id) as submission_count
    FROM assignments a
    JOIN classes c ON a.class_id = c.id
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.teacher_id = ? AND a.term_id = ?";
$conditions = [];
$params = [$teacher_id, $current_term_id];
$param_types = "ii";

if ($department_id) {
    $conditions[] = "c.department_id = ?";
    $params[] = $department_id;
    $param_types .= "i";
}
if ($class_id) {
    $conditions[] = "a.class_id = ?";
    $params[] = $class_id;
    $param_types .= "i";
}
if ($subject_id) {
    $conditions[] = "a.subject_id = ?";
    $params[] = $subject_id;
    $param_types .= "i";
}
if ($search) {
    $conditions[] = "(a.title LIKE ? OR a.tags LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $param_types .= "ss";
}
if (!empty($conditions)) {
    $assignments_sql .= " AND " . implode(" AND ", $conditions);
}
$assignments_sql .= " ORDER BY a.created_at DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = ($page - 1) * $per_page;
$param_types .= "ii";

$assignments_stmt = $conn->prepare($assignments_sql);
if ($assignments_stmt) {
    $assignments_stmt->bind_param($param_types, ...$params);
    $assignments_stmt->execute();
    $assignments_result = $assignments_stmt->get_result();
    $assignments = $assignments_result->fetch_all(MYSQLI_ASSOC);
    $assignments_stmt->close();
} else {
    error_log("Prepare failed for assignments query: " . $conn->error);
    $assignments = [];
}

// Count total assignments for pagination
$count_sql = "SELECT COUNT(*) as total FROM assignments a JOIN classes c ON a.class_id = c.id WHERE a.teacher_id = ? AND a.term_id = ?";
$count_params = [$teacher_id, $current_term_id];
$count_param_types = "ii";
if ($department_id) {
    $count_sql .= " AND c.department_id = ?";
    $count_params[] = $department_id;
    $count_param_types .= "i";
}
if ($class_id) {
    $count_sql .= " AND a.class_id = ?";
    $count_params[] = $class_id;
    $count_param_types .= "i";
}
if ($subject_id) {
    $count_sql .= " AND a.subject_id = ?";
    $count_params[] = $subject_id;
    $count_param_types .= "i";
}
if ($search) {
    $count_sql .= " AND (a.title LIKE ? OR a.tags LIKE ?)";
    $count_params[] = "%$search%";
    $count_params[] = "%$search%";
    $count_param_types .= "ss";
}
$count_stmt = $conn->prepare($count_sql);
if ($count_stmt) {
    $count_stmt->bind_param($count_param_types, ...$count_params);
    $count_stmt->execute();
    $total_assignments = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    error_log("Prepare failed for count query: " . $conn->error);
    $total_assignments = 0;
}
$total_pages = ceil($total_assignments / $per_page);

// Summary metrics
$metrics_sql = "
    SELECT 
        (SELECT COUNT(*) FROM assignments WHERE teacher_id = ? AND term_id = ?) as total_assignments,
        (SELECT COUNT(*) FROM assignments WHERE teacher_id = ? AND term_id = ? AND status = 'published') as published_assignments,
        (SELECT COUNT(*) FROM assignment_submissions subm JOIN assignments a ON subm.assignment_id = a.id WHERE a.teacher_id = ? AND a.term_id = ?) as total_submissions,
        (SELECT COUNT(*) FROM assignments WHERE teacher_id = ? AND term_id = ? AND due_date < CURDATE() AND status != 'closed') as overdue_assignments";
$metrics_stmt = $conn->prepare($metrics_sql);
$metrics = ['total_assignments' => 0, 'published_assignments' => 0, 'total_submissions' => 0, 'overdue_assignments' => 0];
if ($metrics_stmt) {
    $metrics_stmt->bind_param("iiiiiiii", $teacher_id, $current_term_id, $teacher_id, $current_term_id, $teacher_id, $current_term_id, $teacher_id, $current_term_id);
    $metrics_stmt->execute();
    $metrics = $metrics_stmt->get_result()->fetch_assoc();
    $metrics_stmt->close();
} else {
    error_log("Prepare failed for metrics query: " . $conn->error);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage assignments for QueensPa School, view submissions, edit, and update statuses.">
    <meta name="keywords" content="QueensPa School, manage assignments, teacher dashboard, assignment management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Manage Assignments - QueensPa School">
    <meta property="og:description" content="Manage assignments, view submissions, and update statuses at QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/manage_assignments.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Manage Assignments - QueensPa School">
    <meta name="twitter:description" content="Manage assignments, view submissions, and update statuses at QueensPa School.">
    <title>Manage Assignments - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>

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
                        'slide-up': 'slideUp 0.5s ease-out',
                        'fade-in': 'fadeIn 0.5s ease-out',
                        'scale-in': 'scaleIn 0.3s ease-out',
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
            },
            darkMode: 'class',
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

        .loading-spinner {
            display: none;
            color: #0288d1;
            margin-left: 8px;
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

        .dark .bg-gray-50 { background-color: #1f2937; }
        .dark .bg-white { background-color: #374151; }
        .dark .text-gray-800 { color: #e5e7eb; }
        .dark .text-gray-700 { color: #d1d5db; }
        .dark .border-gray-300 { border-color: #4b5563; }
        .dark .bg-gray-500 { background-color: #6b7280; }
        .dark .bg-gray-600 { background-color: #4b5563; }
        .dark .bg-primary-500 { background-color: #1aa2da; }
        .dark .bg-primary-600 { background-color: #0270a8; }
        .dark .bg-accent-500 { background-color: #43a047; }
        .dark .bg-accent-600 { background-color: #388e3c; }
        .dark ::-webkit-scrollbar-track { background: #374151; }
        .dark ::-webkit-scrollbar-thumb { background: #9ca3af; }
        .dark ::-webkit-scrollbar-thumb:hover { background: #6b7280; }
    </style>
</head>
<body class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-primary-500 text-5xl"></i>
    </div>

    <!-- Confirmation Modal -->
    <div id="status-modal" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-md w-full animate-scale-in">
            <div class="bg-primary-600 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
                <h3 class="text-lg font-semibold">Confirm Status Change</h3>
                <button id="close-modal" class="text-white hover:text-gray-200" aria-label="Close modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <p id="modal-message" class="text-gray-700 dark:text-gray-300"></p>
                <input type="hidden" id="modal-assignment-id">
                <input type="hidden" id="modal-status">
                <div class="flex justify-end space-x-3">
                    <button id="cancel-modal" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-primary" aria-label="Cancel">
                        Cancel
                    </button>
                    <button id="confirm-modal" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-primary" aria-label="Confirm">
                        Confirm
                    </button>
                </div>
            </div>
        </div>
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
        logActivity($conn, $teacher_id, "Error", "Failed to load teacher header template: " . $e->getMessage());
        $_SESSION['error_msg'] = "System error: Unable to load page header.";
        header("Location: /login.php");
        exit();
    }
    ?>

    <div class="content">
        <main class="container mx-auto px-4 py-12 max-w-6xl">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-center mb-10 animate-slide-up">
                <h1 class="text-3xl font-bold text-gray-800 dark:text-gray-100 flex items-center">
                    <i class="fas fa-tasks text-primary-500 mr-3"></i> Manage Assignments
                </h1>
                <div class="flex space-x-3 mt-4 sm:mt-0">
                    <button id="export-csv" class="bg-accent-500 hover:bg-accent-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center btn-accent" aria-label="Export to CSV">
                        <i class="fas fa-download mr-2"></i> Export CSV
                    </button>
                    <a href="/teacher/create_assignment.php" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center btn-primary" aria-label="Create new assignment">
                        <i class="fas fa-plus mr-2"></i> Create Assignment
                    </a>
                </div>
            </div>

            <!-- Messages -->
            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center animate-slide-up dark:bg-red-900 dark:border-red-700 dark:text-red-200" role="alert">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($_SESSION['error_msg']) ?>
                    <?php unset($_SESSION['error_msg']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success_msg'])): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center animate-slide-up dark:bg-green-900 dark:border-green-700 dark:text-green-200" role="alert">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?= htmlspecialchars($_SESSION['success_msg']) ?>
                    <?php unset($_SESSION['success_msg']); ?>
                </div>
            <?php endif; ?>

            <!-- Summary Cards -->
            <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8 animate-slide-up">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 flex items-center space-x-4">
                    <i class="fas fa-clipboard-list text-primary-500 text-3xl"></i>
                    <div>
                        <h3 class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Assignments</h3>
                        <p class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?= $metrics['total_assignments'] ?></p>
                    </div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 flex items-center space-x-4">
                    <i class="fas fa-check-circle text-accent-500 text-3xl"></i>
                    <div>
                        <h3 class="text-sm font-medium text-gray-600 dark:text-gray-400">Published Assignments</h3>
                        <p class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?= $metrics['published_assignments'] ?></p>
                    </div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 flex items-center space-x-4">
                    <i class="fas fa-upload text-primary-500 text-3xl"></i>
                    <div>
                        <h3 class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Submissions</h3>
                        <p class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?= $metrics['total_submissions'] ?></p>
                    </div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 flex items-center space-x-4">
                    <i class="fas fa-exclamation-triangle text-red-500 text-3xl"></i>
                    <div>
                        <h3 class="text-sm font-medium text-gray-600 dark:text-gray-400">Overdue Assignments</h3>
                        <p class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?= $metrics['overdue_assignments'] ?></p>
                    </div>
                </div>
            </section>

            <!-- Filters and Search -->
            <section class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 mb-8 animate-slide-up">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-4">Filter Assignments</h2>
                <form id="filter-form" class="grid sm:grid-cols-4 gap-4">
                    <div class="relative">
                        <label for="department_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Department</label>
                        <select id="department_id" name="department_id" class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200" aria-label="Select department">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $department): ?>
                                <option value="<?= $department['id'] ?>" <?= $department_id == $department['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($department['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="loading-spinner absolute right-2 top-10"><i class="fas fa-spinner fa-spin"></i></span>
                    </div>
                    <div class="relative">
                        <label for="class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Class</label>
                        <select id="class_id" name="class_id" class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200" aria-label="Select class">
                            <option value="">All Classes</option>
                        </select>
                        <span class="loading-spinner absolute right-2 top-10"><i class="fas fa-spinner fa-spin"></i></span>
                    </div>
                    <div class="relative">
                        <label for="subject_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Subject</label>
                        <select id="subject_id" name="subject_id" class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200" aria-label="Select subject">
                            <option value="">All Subjects</option>
                        </select>
                        <span class="loading-spinner absolute right-2 top-10"><i class="fas fa-spinner fa-spin"></i></span>
                    </div>
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Search</label>
                        <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200" placeholder="Search by title or tags" aria-label="Search assignments">
                    </div>
                </form>
            </section>

            <!-- Assignments Table -->
            <section class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 animate-slide-up">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-4">Assignments</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Title</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Class</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subject</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Type</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Due Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Submissions</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php if (empty($assignments)): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No assignments found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($assignments as $assignment): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300"><?= htmlspecialchars($assignment['title']) ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300"><?= htmlspecialchars($assignment['class_name']) ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300"><?= htmlspecialchars($assignment['subject_name']) ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300"><?= htmlspecialchars(ucfirst($assignment['type'])) ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300"><?= $assignment['due_date'] ? htmlspecialchars($assignment['due_date']) : 'Not set' ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300"><?= $assignment['submission_count'] ?></td>
                                        <td class="px-6 py-4 text-sm">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                <?= $assignment['status'] === 'published' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 
                                                    ($assignment['status'] === 'draft' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' : 
                                                    'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200') ?>">
                                                <?= htmlspecialchars(ucfirst($assignment['status'])) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm">
                                            <div class="flex space-x-2">
                                                <a href="/teacher/view_submissions.php?assignment_id=<?= $assignment['id'] ?>" 
                                                   class="text-primary-500 hover:text-primary-600" 
                                                   title="View Submissions" 
                                                   aria-label="View submissions for <?= htmlspecialchars($assignment['title']) ?>">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="/teacher/edit_assignment.php?assignment_id=<?= $assignment['id'] ?>" 
                                                   class="text-accent-500 hover:text-accent-600" 
                                                   title="Edit Assignment" 
                                                   aria-label="Edit assignment <?= htmlspecialchars($assignment['title']) ?>">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <button class="status-toggle text-gray-500 hover:text-gray-600 dark:text-gray-400 dark:hover:text-gray-300" 
                                                        data-assignment-id="<?= $assignment['id'] ?>" 
                                                        data-current-status="<?= $assignment['status'] ?>" 
                                                        title="Change Status" 
                                                        aria-label="Change status for <?= htmlspecialchars($assignment['title']) ?>">
                                                    <i class="fas fa-sync-alt"></i>
                                                </button>
                                            </div>
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
                            Showing <?= count($assignments) ?> of <?= $total_assignments ?> assignments
                        </div>
                        <div class="flex space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>&department_id=<?= $department_id ?>&class_id=<?= $class_id ?>&subject_id=<?= $subject_id ?>&search=<?= urlencode($search) ?>" 
                                   class="px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded-lg text-sm dark:bg-gray-700 dark:hover:bg-gray-600">Previous</a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?page=<?= $i ?>&department_id=<?= $department_id ?>&class_id=<?= $class_id ?>&subject_id=<?= $subject_id ?>&search=<?= urlencode($search) ?>" 
                                   class="px-3 py-1 rounded-lg text-sm <?= $i == $page ? 'bg-primary-500 text-white' : 'bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?= $page + 1 ?>&department_id=<?= $department_id ?>&class_id=<?= $class_id ?>&subject_id=<?= $subject_id ?>&search=<?= urlencode($search) ?>" 
                                   class="px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded-lg text-sm dark:bg-gray-700 dark:hover:bg-gray-600">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
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
        logActivity($conn, $teacher_id, "Error", "Failed to load teacher footer template: " . $e->getMessage());
        $_SESSION['error_msg'] = "System error: Unable to load page footer.";
        header("Location: /login.php");
        exit();
    }
    mysqli_close($conn);
    ob_end_flush();
    ?>

    <script>
        $(document).ready(function() {
            const loadingOverlay = $('#loading-overlay');
            const statusModal = $('#status-modal');
            const closeModal = $('#close-modal');
            const cancelModal = $('#cancel-modal');
            const confirmModal = $('#confirm-modal');
            const modalMessage = $('#modal-message');
            const modalAssignmentId = $('#modal-assignment-id');
            const modalStatus = $('#modal-status');
            const departmentSelect = $('#department_id');
            const classSelect = $('#class_id');
            const subjectSelect = $('#subject_id');
            const filterForm = $('#filter-form');
            const searchInput = $('#search');
            const exportCsvButton = $('#export-csv');

            // Load dark mode preference
            if (localStorage.getItem('darkMode') === 'enabled') {
                $('html').addClass('dark');
            }

            // Auto-dismiss alerts
            $('[role="alert"]').each(function() {
                setTimeout(() => {
                    $(this).addClass('opacity-0').slideUp(500, () => $(this).remove());
                }, 5000);
            });

            // Handle department change
            departmentSelect.on('change', function() {
                const departmentId = $(this).val();
                const classSpinner = classSelect.next('.loading-spinner');
                const subjectSpinner = subjectSelect.next('.loading-spinner');
                classSelect.empty().append('<option value="">All Classes</option>').prop('disabled', true);
                subjectSelect.empty().append('<option value="">All Subjects</option>').prop('disabled', true);

                if (!departmentId) {
                    classSelect.prop('disabled', false);
                    subjectSelect.prop('disabled', false);
                    return;
                }

                classSpinner.show();
                subjectSpinner.show();
                $.ajax({
                    url: '/teacher/create_assignment.php?action=get_options',
                    type: 'GET',
                    data: { department_id: departmentId },
                    dataType: 'json',
                    success: function(response) {
                        classSpinner.hide();
                        subjectSpinner.hide();
                        if (response.error) {
                            alert(`Error: ${response.error}`);
                            return;
                        }
                        $.each(response.classes, function(index, item) {
                            classSelect.append(`<option value="${item.id}">${item.class_name}</option>`);
                        });
                        $.each(response.subjects, function(index, item) {
                            subjectSelect.append(`<option value="${item.id}">${item.subject_name}</option>`);
                        });
                        classSelect.prop('disabled', false);
                        subjectSelect.prop('disabled', false);
                    },
                    error: function(xhr, status, error) {
                        classSpinner.hide();
                        subjectSpinner.hide();
                        console.error('AJAX error:', status, error, xhr.responseText);
                        alert('Failed to fetch options. Please try again.');
                    }
                });
            });

            // Submit filters
            filterForm.on('change', 'select', function() {
                filterForm.submit();
            });
            searchInput.on('input', function() {
                clearTimeout(searchInput.data('timeout'));
                searchInput.data('timeout', setTimeout(() => filterForm.submit(), 500));
            });

            // Status toggle
            $('.status-toggle').on('click', function() {
                const assignmentId = $(this).data('assignment-id');
                const currentStatus = $(this).data('current-status');
                const nextStatus = currentStatus === 'draft' ? 'published' : 
                                  currentStatus === 'published' ? 'closed' : 'draft';
                modalAssignmentId.val(assignmentId);
                modalStatus.val(nextStatus);
                modalMessage.text(`Are you sure you want to change the status to "${nextStatus}"?`);
                statusModal.removeClass('hidden');
            });

            // Close modal
            closeModal.on('click', () => statusModal.addClass('hidden'));
            cancelModal.on('click', () => statusModal.addClass('hidden'));

            // Confirm status change
            confirmModal.on('click', function() {
                const assignmentId = modalAssignmentId.val();
                const status = modalStatus.val();
                loadingOverlay.removeClass('hidden');
                $.ajax({
                    url: '<?php echo $_SERVER['PHP_SELF']; ?>',
                    type: 'POST',
                    data: {
                        action: 'update_status',
                        assignment_id: assignmentId,
                        status: status,
                        csrf_token: '<?= $csrf_token ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        loadingOverlay.addClass('hidden');
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(`Error: ${response.message}`);
                        }
                    },
                    error: function(xhr, status, error) {
                        loadingOverlay.addClass('hidden');
                        console.error('Status update error:', status, error, xhr.responseText);
                        alert('Failed to update status. Please try again.');
                    }
                });
                statusModal.addClass('hidden');
            });

            // Export CSV
            exportCsvButton.on('click', function() {
                const data = [
                    ['Title', 'Class', 'Subject', 'Type', 'Due Date', 'Submissions', 'Status'],
                    <?php foreach ($assignments as $assignment): ?>
                        [
                            `"${<?= json_encode($assignment['title']) ?>}"`,
                            `"${<?= json_encode($assignment['class_name']) ?>}"`,
                            `"${<?= json_encode($assignment['subject_name']) ?>}"`,
                            `"${<?= json_encode($assignment['type']) ?>}"`,
                            `"${<?= json_encode($assignment['due_date'] ?: 'Not set') ?>}"`,
                            <?= $assignment['submission_count'] ?>,
                            `"${<?= json_encode($assignment['status']) ?>}"`
                        ],
                    <?php endforeach; ?>
                ];
                const csv = data.map(row => row.join(',')).join('\n');
                const blob = new Blob([csv], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.setAttribute('href', url);
                a.setAttribute('download', 'assignments_export.csv');
                a.click();
                window.URL.revokeObjectURL(url);
            });

            // Trigger department change on load
            if (departmentSelect.val()) {
                departmentSelect.trigger('change');
            }

            // Hide loading overlay
            setTimeout(() => loadingOverlay.addClass('hidden'), 500);
        });
    </script>
</body>
</html>