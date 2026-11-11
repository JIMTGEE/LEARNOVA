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
    $user_result = $user_check_stmt->get_result();
    if ($user_result->num_rows === 0) {
        error_log("Invalid teacher_id in session: $teacher_id");
        $_SESSION['error_msg'] = "Invalid user session.";
        header("Location: /login.php");
        exit();
    }
    $user_check_stmt->close();
} else {
    error_log("Failed to prepare user check query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}

// Validate assignment_id
$assignment_id = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT);
if (!$assignment_id) {
    error_log("Missing or invalid assignment_id");
    $_SESSION['error_msg'] = "Invalid assignment ID.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}

// Enable debug mode if ?debug=1 is in the URL
$debug_mode = filter_input(INPUT_GET, 'debug', FILTER_VALIDATE_INT) === 1;

// Fetch assignment details
$assignment_sql = "
    SELECT a.title, a.type, a.due_date, a.status, a.max_points, a.is_group_assignment, a.questions,
           c.class_name, s.subject_name
    FROM assignments a
    JOIN classes c ON a.class_id = c.id
    JOIN subjects s ON a.subject_id = s.id
    WHERE a.id = ? AND a.teacher_id = ?";
$assignment_stmt = $conn->prepare($assignment_sql);
$assignment = null;
if ($assignment_stmt) {
    $assignment_stmt->bind_param("ii", $assignment_id, $teacher_id);
    $assignment_stmt->execute();
    $assignment_result = $assignment_stmt->get_result();
    if ($assignment_result->num_rows === 0) {
        error_log("Assignment not found or unauthorized: ID=$assignment_id, teacher_id=$teacher_id");
        $_SESSION['error_msg'] = "Assignment not found or unauthorized.";
        header("Location: /teacher/manage_assignments.php");
        exit();
    }
    $assignment = $assignment_result->fetch_assoc();
    $assignment_stmt->close();
} else {
    error_log("Prepare failed for assignment query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch assignment.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}

// Parse questions (assuming JSON or plain text fallback)
$questions = [];
$questions_raw = $assignment['questions'];
error_log("Raw questions data for assignment_id=$assignment_id: " . (is_null($questions_raw) ? 'NULL' : substr($questions_raw, 0, 500)));

if ($questions_raw) {
    // Try JSON parsing first
    $decoded = json_decode($questions_raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $questions = $decoded;
    } else {
        error_log("JSON parse failed for assignment_id=$assignment_id: " . json_last_error_msg());
        // Fallback: Treat as plain text, split by newlines or semicolons
        $lines = preg_split('/[\n;]/', trim($questions_raw));
        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line) {
                // Extract points if present (e.g., "Question text (10 points)")
                if (preg_match('/^(.*?)\s*\((\d+)\s*points?\)$/i', $line, $matches)) {
                    $questions[] = ['question' => trim($matches[1]), 'points' => (int)$matches[2]];
                } else {
                    $questions[] = ['question' => $line, 'points' => 0];
                }
            }
        }
        if (empty($questions)) {
            $questions = [['question' => 'Error: Invalid question format', 'points' => 0]];
            error_log("No valid questions parsed from raw data for assignment_id=$assignment_id");
        }
    }
} else {
    error_log("No questions data found for assignment_id=$assignment_id");
    $questions = [['question' => 'No questions provided for this assignment', 'points' => 0]];
}

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$action = "Accessed View Submissions Page";
$description = "Teacher accessed submissions for assignment ID $assignment_id";
logActivity($conn, $teacher_id, $action, $description);

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Handle AJAX grading request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'grade_submission') {
    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            throw new Exception("Invalid CSRF token.");
        }
        $submission_id = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
        $score = filter_input(INPUT_POST, 'score', FILTER_VALIDATE_FLOAT);
        $feedback = trim(htmlspecialchars(filter_input(INPUT_POST, 'feedback', FILTER_UNSAFE_RAW) ?? ''));
        if (!$submission_id || $score === false || $score < 0 || $score > $assignment['max_points']) {
            throw new Exception("Invalid submission ID or score out of range (0-{$assignment['max_points']}).");
        }
        $update_sql = "UPDATE assignment_submissions SET score = ?, feedback = ?, status = 'graded', updated_at = NOW(), graded_by = ?, graded_at = NOW() WHERE id = ? AND assignment_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        if (!$update_stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $update_stmt->bind_param("dsiii", $score, $feedback, $teacher_id, $submission_id, $assignment_id);
        $update_stmt->execute();
        if ($update_stmt->affected_rows > 0) {
            logActivity($conn, $teacher_id, "Graded Submission", "Graded submission ID $submission_id for assignment ID $assignment_id with score $score");
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $response['success'] = true;
            $response['message'] = "Submission graded successfully.";
        } else {
            throw new Exception("No submission found or unauthorized.");
        }
        $update_stmt->close();
    } catch (Exception $e) {
        error_log("Grading error: " . $e->getMessage());
        $response['message'] = $e->getMessage();
    }
    echo json_encode($response);
    exit();
}

// Handle filters and search
$status_filter = trim(filter_input(INPUT_GET, 'status', FILTER_UNSAFE_RAW) ?? '');
$search = trim(filter_input(INPUT_GET, 'search', FILTER_UNSAFE_RAW) ?? '');
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?: 1;
$per_page = 10;

// Build submissions query
$submissions_sql = "
    SELECT s.id, s.user_id, s.group_id, s.submission_date, s.file_paths, s.score, s.feedback, s.status, s.answers,
           u.name as student_name
    FROM assignment_submissions s
    JOIN users u ON s.user_id = u.id
    WHERE s.assignment_id = ?";
$conditions = [];
$params = [$assignment_id];
$param_types = "i";

if ($status_filter && in_array($status_filter, ['pending', 'submitted', 'graded', 'late', 'returned'])) {
    $conditions[] = "s.status = ?";
    $params[] = $status_filter;
    $param_types .= "s";
}
if ($search) {
    $conditions[] = "u.name LIKE ?";
    $params[] = "%$search%";
    $param_types .= "s";
}
if (!empty($conditions)) {
    $submissions_sql .= " AND " . implode(" AND ", $conditions);
}
$submissions_sql .= " ORDER BY s.submission_date DESC LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = ($page - 1) * $per_page;
$param_types .= "ii";

$submissions_stmt = $conn->prepare($submissions_sql);
if ($submissions_stmt) {
    $submissions_stmt->bind_param($param_types, ...$params);
    $submissions_stmt->execute();
    $submissions_result = $submissions_stmt->get_result();
    $submissions = $submissions_result->fetch_all(MYSQLI_ASSOC);
    $submissions_stmt->close();
} else {
    error_log("Prepare failed for submissions query: " . $conn->error);
    $submissions = [];
}

// Parse answers for each submission
foreach ($submissions as &$submission) {
    $submission['parsed_answers'] = [];
    if ($submission['answers']) {
        $decoded = json_decode($submission['answers'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $submission['parsed_answers'] = $decoded;
        } else {
            error_log("Failed to parse answers JSON for submission_id={$submission['id']}: " . json_last_error_msg());
            $submission['parsed_answers'] = [['answer' => 'Error: Invalid answer format']];
        }
    }
    // Parse file_paths
    $submission['parsed_file_paths'] = [];
    if ($submission['file_paths']) {
        $decoded = json_decode($submission['file_paths'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $submission['parsed_file_paths'] = $decoded;
        } else {
            $submission['parsed_file_paths'] = array_filter(explode(',', $submission['file_paths']));
        }
    }
}
unset($submission);

// Count total submissions for pagination
$count_sql = "SELECT COUNT(*) as total FROM assignment_submissions s JOIN users u ON s.user_id = u.id WHERE s.assignment_id = ?";
$count_params = [$assignment_id];
$count_param_types = "i";
if ($status_filter && in_array($status_filter, ['pending', 'submitted', 'graded', 'late', 'returned'])) {
    $count_sql .= " AND s.status = ?";
    $count_params[] = $status_filter;
    $count_param_types .= "s";
}
if ($search) {
    $count_sql .= " AND u.name LIKE ?";
    $count_params[] = $search;
    $count_param_types .= "s";
}
$count_stmt = $conn->prepare($count_sql);
if ($count_stmt) {
    $count_stmt->bind_param($count_param_types, ...$count_params);
    $count_stmt->execute();
    $total_submissions = $count_stmt->get_result()->fetch_assoc()['total'];
    $count_stmt->close();
} else {
    error_log("Prepare failed for count query: " . $conn->error);
    $total_submissions = 0;
}
$total_pages = ceil($total_submissions / $per_page);

// Summary metrics
$metrics_sql = "
    SELECT 
        (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = ?) as total_submissions,
        (SELECT COUNT(*) FROM assignment_submissions WHERE assignment_id = ? AND status = 'graded') as graded_submissions";
$metrics_stmt = $conn->prepare($metrics_sql);
$metrics = ['total_submissions' => 0, 'graded_submissions' => 0];
if ($metrics_stmt) {
    $metrics_stmt->bind_param("ii", $assignment_id, $assignment_id);
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
    <meta name="description" content="View and grade assignment submissions for QueensPa School.">
    <meta name="keywords" content="QueensPa School, view submissions, grade assignments, teacher dashboard">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="View Submissions - QueensPa School">
    <meta property="og:description" content="View and grade student submissions for assignments at QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/view_submissions.php?assignment_id=<?= $assignment_id ?>">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="View Submissions - QueensPa School">
    <meta name="twitter:description" content="View and grade student submissions for assignments at QueensPa School.">
    <title>View Submissions - QueensPa School</title>

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

        ::-webkitプレゼント

        ::-webkit-scrollbar-thumb:hover {
            background: #64748b;
        }

        .loading-spinner {
            display: none;
            color: #0288d1;
            margin-left: 8px;
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

        .toggle-details i { transition: transform 0.3s; }
        .toggle-details.active i { transform: rotate(180deg); }
    </style>
</head>
<body class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-primary-500 text-5xl"></i>
    </div>

    <!-- Grading Modal -->
    <div id="grading-modal" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-lg w-full animate-scale-in">
            <div class="bg-primary-600 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
                <h3 class="text-lg font-semibold">Grade Submission</h3>
                <button id="close-grading-modal" class="text-white hover:text-gray-200" aria-label="Close modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6 space-y-4 max-h-[70vh] overflow-y-auto">
                <div id="questions-answers" class="space-y-4">
                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">Questions & Answers</h4>
                    <?php foreach ($questions as $index => $question): ?>
                        <div class="border-l-4 border-primary-500 pl-4">
                            <p class="text-sm font-semibold text-gray-800 dark:text-gray-100">Q<?= $index + 1 ?>: <?= htmlspecialchars($question['question'] ?? 'N/A') ?> (<?= $question['points'] ?? 0 ?> points)</p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Answer: <span id="answer-<?= $index ?>"></span></p>
                        </div>
                    <?php endforeach; ?>
                </div>
                <form id="grading-form" class="space-y-4">
                    <input type="hidden" id="submission_id" name="submission_id">
                    <div>
                        <label for="score" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Score (0 - <?= $assignment['max_points'] ?>)</label>
                        <input type="number" id="score" name="score" step="0.01" min="0" max="<?= $assignment['max_points'] ?>" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200" required aria-label="Submission score">
                        <div class="invalid-feedback">Please enter a valid score between 0 and <?= $assignment['max_points'] ?>.</div>
                    </div>
                    <div>
                        <label for="feedback" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Feedback</label>
                        <textarea id="feedback" name="feedback" rows="4" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200" placeholder="Enter feedback" aria-label="Submission feedback"></textarea>
                    </div>
                    <div class="flex justify-end space-x-3">
                        <button type="button" id="cancel-grading" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-primary" aria-label="Cancel">
                            Cancel
                        </button>
                        <button type="submit" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-primary" aria-label="Save grade">
                            Save
                        </button>
                    </div>
                </form>
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
                    <i class="fas fa-upload text-primary-500 mr-3"></i> View Submissions
                </h1>
                <div class="flex space-x-3 mt-4 sm:mt-0">
                    <button id="export-csv" class="bg-accent-500 hover:bg-accent-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center btn-accent" aria-label="Export to CSV">
                        <i class="fas fa-download mr-2"></i> Export CSV
                    </button>
                    <a href="/teacher/manage_assignments.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center btn-primary" aria-label="Back to assignments">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Assignments
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

            <!-- Assignment Details -->
            <section class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 mb-8 animate-slide-up">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-4">Assignment Details</h2>
                <div class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <p><strong>Title:</strong> <?= htmlspecialchars($assignment['title']) ?></p>
                        <p><strong>Class:</strong> <?= htmlspecialchars($assignment['class_name']) ?></p>
                        <p><strong>Subject:</strong> <?= htmlspecialchars($assignment['subject_name']) ?></p>
                    </div>
                    <div>
                        <p><strong>Type:</strong> <?= htmlspecialchars(ucfirst($assignment['type'])) ?></p>
                        <p><strong>Due Date:</strong> <?= $assignment['due_date'] ? htmlspecialchars($assignment['due_date']) : 'Not set' ?></p>
                        <p><strong>Status:</strong> 
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                <?= $assignment['status'] === 'published' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 
                                    ($assignment['status'] === 'draft' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' : 
                                    'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200') ?>">
                                <?= htmlspecialchars(ucfirst($assignment['status'])) ?>
                            </span>
                        </p>
                    </div>
                </div>
                <div class="mt-4">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-2">Questions</h3>
                    <?php if (!empty($questions) && !isset($questions[0]['question']) || $questions[0]['question'] !== 'No questions provided for this assignment' && $questions[0]['question'] !== 'Error: Invalid question format'): ?>
                        <ul class="list-decimal pl-5 space-y-2">
                            <?php foreach ($questions as $index => $question): ?>
                                <li class="text-sm text-gray-700 dark:text-gray-300">
                                    <?= htmlspecialchars($question['question'] ?? 'Question not available') ?> 
                                    <?php if (isset($question['points']) && $question['points'] > 0): ?>
                                        (<?= htmlspecialchars($question['points']) ?> points)
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($debug_mode): ?>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                <strong>Raw Questions Data:</strong> <?= htmlspecialchars(is_null($questions_raw) ? 'NULL' : substr($questions_raw, 0, 200)) ?>
                            </p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            No questions available for this assignment. 
                            <a href="/teacher/edit_assignment.php?assignment_id=<?= $assignment_id ?>" class="text-primary-500 hover:text-primary-600">Edit assignment to add questions</a>.
                        </p>
                        <?php if ($debug_mode && $questions_raw): ?>
                            <p class="text-xs text-red-500 dark:text-red-400 mt-2">
                                <strong>Raw Questions Data:</strong> <?= htmlspecialchars(substr($questions_raw, 0, 200)) ?>...
                            </p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Summary Cards -->
            <section class="grid sm:grid-cols-2 gap-6 mb-8 animate-slide-up">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 flex items-center space-x-4">
                    <i class="fas fa-upload text-primary-500 text-3xl"></i>
                    <div>
                        <h3 class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Submissions</h3>
                        <p class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?= $metrics['total_submissions'] ?></p>
                    </div>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 flex items-center space-x-4">
                    <i class="fas fa-check-circle text-accent-500 text-3xl"></i>
                    <div>
                        <h3 class="text-sm font-medium text-gray-600 dark:text-gray-400">Graded Submissions</h3>
                        <p class="text-2xl font-bold text-gray-800 dark:text-gray-100"><?= $metrics['graded_submissions'] ?></p>
                    </div>
                </div>
            </section>

            <!-- Filters -->
            <section class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 mb-8 animate-slide-up">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-4">Filter Submissions</h2>
                <form id="filter-form" class="grid sm:grid-cols-2 gap-4">
                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Status</label>
                        <select id="status" name="status" class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200" aria-label="Select status">
                            <option value="">All Statuses</option>
                            <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="submitted" <?= $status_filter === 'submitted' ? 'selected' : '' ?>>Submitted</option>
                            <option value="graded" <?= $status_filter === 'graded' ? 'selected' : '' ?>>Graded</option>
                            <option value="late" <?= $status_filter === 'late' ? 'selected' : '' ?>>Late</option>
                            <option value="returned" <?= $status_filter === 'returned' ? 'selected' : '' ?>>Returned</option>
                        </select>
                    </div>
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Search by Student</label>
                        <input type="text" id="search" name="search" value="<?= htmlspecialchars($search) ?>" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200" placeholder="Search by student name" aria-label="Search submissions">
                    </div>
                </form>
            </section>

            <!-- Submissions Table -->
            <section class="bg-white dark:bg-gray-800 rounded-xl shadow-md p-6 animate-slide-up">
                <h2 class="text-xl font-semibold text-gray-800 dark:text-gray-100 mb-4">Submissions</h2>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Submission Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Files</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Score</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Feedback</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php if (empty($submissions)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No submissions found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($submissions as $submission): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300"><?= htmlspecialchars($submission['student_name']) ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300"><?= htmlspecialchars($submission['submission_date'] ?? '-') ?></td>
                                        <td class="px-6 py-4 text-sm">
                                            <?php if (!empty($submission['parsed_file_paths'])): ?>
                                                <?php foreach ($submission['parsed_file_paths'] as $index => $file_path): ?>
                                                    <a href="<?= htmlspecialchars(trim($file_path)) ?>" target="_blank" class="text-primary-500 hover:text-primary-600" title="View File <?= $index + 1 ?>" aria-label="View submission file <?= $index + 1 ?>">
                                                        <i class="fas fa-file-download mr-1"></i>
                                                    </a>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-gray-500 dark:text-gray-400">None</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300"><?= $submission['score'] !== null ? number_format($submission['score'], 2) : '-' ?></td>
                                        <td class="px-6 py-4 text-sm text-gray-700 dark:text-gray-300"><?= htmlspecialchars($submission['feedback'] ?: '-') ?></td>
                                        <td class="px-6 py-4 text-sm">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium 
                                                <?= $submission['status'] === 'graded' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 
                                                    ($submission['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' : 
                                                    ($submission['status'] === 'late' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 
                                                    'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200')) ?>">
                                                <?= htmlspecialchars(ucfirst($submission['status'])) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm">
                                            <button class="grade-submission text-primary-500 hover:text-primary-600 mr-2" 
                                                    data-submission-id="<?= $submission['id'] ?>" 
                                                    data-score="<?= $submission['score'] !== null ? $submission['score'] : '' ?>" 
                                                    data-feedback="<?= htmlspecialchars($submission['feedback'] ?? '') ?>" 
                                                    data-answers='<?= htmlspecialchars(json_encode($submission['parsed_answers'])) ?>'
                                                    title="Grade Submission" 
                                                    aria-label="Grade submission for <?= htmlspecialchars($submission['student_name']) ?>">
                                                <i class="fas fa-pen"></i>
                                            </button>
                                            <button class="toggle-details text-primary-500 hover:text-primary-600" 
                                                    data-submission-id="<?= $submission['id'] ?>" 
                                                    title="View Details" 
                                                    aria-label="View submission details for <?= htmlspecialchars($submission['student_name']) ?>">
                                                <i class="fas fa-chevron-down"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <tr id="details-<?= $submission['id'] ?>" class="hidden bg-gray-50 dark:bg-gray-700">
                                        <td colspan="7" class="px-6 py-4">
                                            <div class="space-y-4">
                                                <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300">Answers</h4>
                                                <?php if (!empty($submission['parsed_answers'])): ?>
                                                    <ul class="list-decimal pl-5 space-y-2">
                                                        <?php foreach ($submission['parsed_answers'] as $index => $answer): ?>
                                                            <li class="text-sm text-gray-600 dark:text-gray-400">
                                                                <?= htmlspecialchars($answer['answer'] ?? 'No answer provided') ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php else: ?>
                                                    <p class="text-sm text-gray-500 dark:text-gray-400">No answers provided.</p>
                                                <?php endif; ?>
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
                            Showing <?= count($submissions) ?> of <?= $total_submissions ?> submissions
                        </div>
                        <div class="flex space-x-2">
                            <?php if ($page > 1): ?>
                                <a href="?assignment_id=<?= $assignment_id ?>&page=<?= $page - 1 ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>" 
                                   class="px-3 py-1 bg-gray-200 hover:bg-gray-300 rounded-lg text-sm dark:bg-gray-700 dark:hover:bg-gray-600">Previous</a>
                            <?php endif; ?>
                            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                <a href="?assignment_id=<?= $assignment_id ?>&page=<?= $i ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>" 
                                   class="px-3 py-1 rounded-lg text-sm <?= $i == $page ? 'bg-primary-500 text-white' : 'bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                            <?php if ($page < $total_pages): ?>
                                <a href="?assignment_id=<?= $assignment_id ?>&page=<?= $page + 1 ?>&status=<?= urlencode($status_filter) ?>&search=<?= urlencode($search) ?>" 
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
            const gradingModal = $('#grading-modal');
            const closeGradingModal = $('#close-grading-modal');
            const cancelGrading = $('#cancel-grading');
            const gradingForm = $('#grading-form');
            const submissionIdInput = $('#submission_id');
            const scoreInput = $('#score');
            const feedbackInput = $('#feedback');
            const filterForm = $('#filter-form');
            const statusSelect = $('#status');
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

            // Toggle details
            $('.toggle-details').on('click', function() {
                const submissionId = $(this).data('submission-id');
                const detailsRow = $(`#details-${submissionId}`);
                const isVisible = !detailsRow.hasClass('hidden');
                $('.toggle-details').removeClass('active');
                $('.toggle-details i').css('transform', 'rotate(0deg)');
                $('tr[id^="details-"]').addClass('hidden');
                if (!isVisible) {
                    detailsRow.removeClass('hidden');
                    $(this).addClass('active');
                    $(this).find('i').css('transform', 'rotate(180deg)');
                }
            });

            // Open grading modal
            $('.grade-submission').on('click', function() {
                const submissionId = $(this).data('submission-id');
                const score = $(this).data('score');
                const feedback = $(this).data('feedback');
                const answers = $(this).data('answers') || [];
                submissionIdInput.val(submissionId);
                scoreInput.val(score);
                feedbackInput.val(feedback);
                // Populate answers in modal
                answers.forEach((answer, index) => {
                    $(`#answer-${index}`).text(answer.answer || 'No answer provided');
                });
                gradingModal.removeClass('hidden');
                scoreInput.focus();
            });

            // Close grading modal
            closeGradingModal.on('click', () => gradingModal.addClass('hidden'));
            cancelGrading.on('click', () => gradingModal.addClass('hidden'));

            // Validate and submit grading form
            gradingForm.on('submit', function(e) {
                e.preventDefault();
                const submissionId = submissionIdInput.val();
                const score = parseFloat(scoreInput.val());
                const maxPoints = <?= $assignment['max_points'] ?>;
                if (!score || score < 0 || score > maxPoints) {
                    scoreInput.addClass('is-invalid');
                    scoreInput.focus();
                    return;
                }
                scoreInput.removeClass('is-invalid');
                loadingOverlay.removeClass('hidden');
                $.ajax({
                    url: '<?php echo $_SERVER['PHP_SELF']; ?>?assignment_id=<?= $assignment_id ?>',
                    type: 'POST',
                    data: {
                        action: 'grade_submission',
                        submission_id: submissionId,
                        score: score,
                        feedback: feedbackInput.val(),
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
                        console.error('Grading error:', status, error, xhr.responseText);
                        alert('Failed to save grade. Please try again.');
                    }
                });
                gradingModal.addClass('hidden');
            });

            // Real-time score validation
            scoreInput.on('input', function() {
                const score = parseFloat($(this).val());
                const maxPoints = <?= $assignment['max_points'] ?>;
                if (score < 0 || score > maxPoints || isNaN(score)) {
                    $(this).addClass('is-invalid');
                } else {
                    $(this).removeClass('is-invalid');
                }
            });

            // Submit filters
            statusSelect.on('change', () => filterForm.submit());
            searchInput.on('input', function() {
                clearTimeout($(this).data('timeout'));
                $(this).data('timeout', setTimeout(() => filterForm.submit(), 500));
            });

            // Export CSV
            exportCsvButton.on('click', function() {
                const data = [
                    ['Student', 'Submission Date', 'Score', 'Feedback', 'Status', 'Answers'],
                    <?php foreach ($submissions as $submission): ?>
                        [
                            `"${<?= json_encode($submission['student_name']) ?>}"`,
                            `"${<?= json_encode($submission['submission_date'] ?? '-') ?>}"`,
                            `"${<?= json_encode($submission['score'] !== null ? number_format($submission['score'], 2) : '-') ?>}"`,
                            `"${<?= json_encode($submission['feedback'] ?: '-') ?>}"`,
                            `"${<?= json_encode($submission['status']) ?>}"`,
                            `"${<?= json_encode(implode('; ', array_map(fn($a) => $a['answer'] ?? 'N/A', $submission['parsed_answers']))) ?>}"`
                        ],
                    <?php endforeach; ?>
                ];
                const csv = data.map(row => row.join(',')).join('\n');
                const blob = new Blob([csv], { type: 'text/csv' });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.setAttribute('href', url);
                a.setAttribute('download', 'submissions_export_<?= $assignment_id ?>.csv');
                a.click();
                window.URL.revokeObjectURL(url);
            });

            // Hide loading overlay
            setTimeout(() => loadingOverlay.addClass('hidden'), 500);
        });
    </script>
</body>
</html>