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
$action = "Accessed Edit Assignment Page";
$description = "Teacher accessed edit assignment page";
logActivity($conn, $teacher_id, $action, $description);

// Generate and store CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
error_log("Generated CSRF token: $csrf_token");

// Fetch assignment details with department, class, and subject names
$assignment_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$assignment_id) {
    $_SESSION['error_msg'] = "Invalid assignment ID.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}
$select_sql = "SELECT a.*, d.name AS department_name, c.class_name, s.subject_name 
               FROM assignments a 
               JOIN classes c ON a.class_id = c.id 
               JOIN subjects s ON a.subject_id = s.id 
               JOIN departments d ON c.department_id = d.id 
               WHERE a.id = ? AND a.teacher_id = ?";
$select_stmt = $conn->prepare($select_sql);
if ($select_stmt) {
    $select_stmt->bind_param("ii", $assignment_id, $teacher_id);
    $select_stmt->execute();
    $assignment_result = $select_stmt->get_result();
    if ($assignment_result->num_rows === 0) {
        error_log("Assignment not found or unauthorized: ID $assignment_id, teacher_id $teacher_id");
        $_SESSION['error_msg'] = "Assignment not found or you do not have permission to edit it.";
        header("Location: /teacher/manage_assignments.php");
        exit();
    }
    $assignment = $assignment_result->fetch_assoc();
    $select_stmt->close();
} else {
    error_log("Failed to prepare assignment query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch assignment.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}

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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            error_log("CSRF token validation failed: Expected " . ($_SESSION['csrf_token'] ?? 'none') . ", Received " . ($_POST['csrf_token'] ?? 'none'));
            throw new Exception("Invalid CSRF token.");
        }

        // Sanitize and validate inputs
        $assignment_id = filter_input(INPUT_POST, 'assignment_id', FILTER_VALIDATE_INT);
        $questions_json = trim(filter_input(INPUT_POST, 'questions_json', FILTER_UNSAFE_RAW) ?? '') ?: null;
        $max_points = filter_input(INPUT_POST, 'max_points', FILTER_VALIDATE_FLOAT) ?: 100.00;
        $due_date = trim(htmlspecialchars(filter_input(INPUT_POST, 'due_date', FILTER_UNSAFE_RAW) ?? '')) ?: null;
        $is_group_assignment = isset($_POST['is_group_assignment']) ? 1 : 0;
        $group_id = filter_input(INPUT_POST, 'group_id', FILTER_VALIDATE_INT) ?: null;
        $status = trim(htmlspecialchars(filter_input(INPUT_POST, 'status', FILTER_UNSAFE_RAW) ?? 'draft'));
        $tags = trim(htmlspecialchars(filter_input(INPUT_POST, 'tags', FILTER_UNSAFE_RAW) ?? '')) ?: null;

        // Validate required fields
        if (!$assignment_id) {
            throw new Exception("Invalid assignment ID.");
        }

        // Validate status
        $valid_statuses = ['draft', 'published', 'closed'];
        if (!in_array($status, $valid_statuses)) {
            throw new Exception("Invalid assignment status.");
        }

        // Validate due date
        if ($due_date && !DateTime::createFromFormat('Y-m-d', $due_date)) {
            throw new Exception("Invalid due date format. Use YYYY-MM-DD.");
        }

        // Validate questions JSON
        if ($questions_json) {
            $questions = json_decode($questions_json, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($questions)) {
                error_log("Invalid JSON: " . json_last_error_msg() . " - Input: " . $questions_json);
                throw new Exception("Invalid questions JSON format.");
            }
            foreach ($questions as $q) {
                if (!isset($q['question_text']) || trim($q['question_text']) === '') {
                    throw new Exception("Each question must have a non-empty question text.");
                }
            }
        }

        // Validate group_id
        if ($is_group_assignment && !$group_id) {
            throw new Exception("Group ID is required for group assignments.");
        }

        // Handle file upload
        $file_path = null;
        if (!empty($_FILES['file']['name'])) {
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/Assignments/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $file_name = time() . '_' . basename($_FILES['file']['name']);
            $file_path = '/Uploads/Assignments/' . $file_name;
            if (!move_uploaded_file($_FILES['file']['tmp_name'], $_SERVER['DOCUMENT_ROOT'] . $file_path)) {
                throw new Exception("Failed to upload file.");
            }
        } else {
            // Retain existing file_path if no new file is uploaded
            $file_path = $assignment['file_path'];
        }

        // Update assignment
        $update_sql = "UPDATE assignments SET questions = ?, max_points = ?, file_path = ?, due_date = ?, is_group_assignment = ?, group_id = ?, status = ?, tags = ?, updated_at = NOW() WHERE id = ?";
        $update_stmt = $conn->prepare($update_sql);
        if (!$update_stmt) {
            throw new Exception("Prepare failed for assignment update: " . $conn->error);
        }
        $update_stmt->bind_param("sdssiissi", $questions_json, $max_points, $file_path, $due_date, $is_group_assignment, $group_id, $status, $tags, $assignment_id);
        $update_stmt->execute();
        $update_stmt->close();

        // Log activity
        logActivity($conn, $teacher_id, "Updated Assignment", "Assignment ID $assignment_id updated");

        // Regenerate CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        error_log("Regenerated CSRF token: " . $_SESSION['csrf_token']);

        $conn->commit();
        $_SESSION['success_msg'] = "Assignment updated successfully.";
        header("Location: /teacher/manage_assignments.php");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Assignment update error: " . $e->getMessage());
        $error_msg = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Edit assignments for QueensPa School, manage class tasks and submissions.">
    <meta name="keywords" content="QueensPa School, edit assignment, teacher dashboard, assignment management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Edit Assignment - QueensPa School">
    <meta property="og:description" content="Edit and manage assignments for classes at QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/edit_assignment.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Edit Assignment - QueensPa School">
    <meta name="twitter:description" content="Edit and manage assignments for classes at QueensPa School.">
    <title>Edit Assignment - QueensPa School</title>

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

        .read-only-field {
            background-color: #f3f4f6;
            border: 1px solid #d1d5db;
            padding: 8px 12px;
            border-radius: 8px;
            color: #1f2937;
            font-size: 0.875rem;
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-primary-500 text-5xl"></i>
    </div>

    <!-- Preview Modal -->
    <div id="previewModal" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <div id="preview-modal" class="bg-white rounded-xl p-2xl shadow-2xl max-w-3xl w-full max-h-[85vh] overflow-y-auto animate-scale-in">
            <div class="bg-primary-600 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
                <h3 class="text-lg font-semibold">Assignment Preview</h3>
                <button id="close-preview" class="text-white hover:text-gray-200" aria-label="Close preview">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="p-6 space-y-4">
                <div id="preview-content" class="space-y-3 text-gray-700 text-sm"></div>
                <div class="flex justify-end space-x-3 mt-6">
                    <button id="edit-preview" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-primary" aria-label="Edit assignment">
                        <i class="fas fa-edit mr-2"></i> Edit
                    </button>
                    <button id="submit-preview" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-primary" aria-label="Submit assignment">
                        <i class="fas fa-save mr-2"></i> Submit
                    </button>
                </div>
            </div>
        </div>
    </div>

    <?php
    $header_path = './templates/teacher_header.php';
    try {
        if (!file_exists($header_path)) {
            throw new Exception("Teacher header file missing: $header_path");
        }
        include($header_path);
    } catch (Exception $e) {
        error_log("Failed to include teacher_header.php: " . $e->getMessage());
        logActivity($conn, $activity, "Error", "Failed to load teacher header template: " . $e->getMessage());
        $_SESSION['error_msg'] = "System error: Unable to load page header.";
        header("Location: /teacher/login.php");
        exit();
    }
    ?>

    <div class="content">
        <main class="container mx-auto px-4 py-12 max-w-5xl">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-center mb-10 animate-slide-up">
                <h1 class="text-3xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-clipboard-list text-primary-500 mr-3"></i> Edit Assignment
                </h1>
                <a href="/teacher/manage_assignments.php" class="mt-4 sm:mt-0 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center btn-primary" aria-label="Back to assignments">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Assignments
                </a>
            </div>

            <!-- Messages -->
            <?php if (isset($error_msg)): ?>
                <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center animate-slide-up" role="alert">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($error_msg) ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success_msg'])): ?>
                <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center animate-slide-up" role="alert">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?= htmlspecialchars($_SESSION['success_msg']) ?>
                    <?php unset($_SESSION['success_msg']); ?>
                </div>
            <?php endif; ?>

            <!-- Assignment Details -->
            <section class="bg-white rounded-xl shadow-md p-8 mb-10 animate-slide-up">
                <h2 class="text-xl font-semibold text-gray-800 mb-6">Assignment Details</h2>

                <!-- Non-Editable Fields -->
                <div class="space-y-6">
                    <!-- Department, Class, Subject -->
                    <div class="grid sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Department</label>
                            <div class="read-only-field"><?= htmlspecialchars($assignment['department_name']) ?></div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Class</label>
                            <div class="read-only-field"><?= htmlspecialchars($assignment['class_name']) ?></div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Subject</label>
                            <div class="read-only-field"><?= htmlspecialchars($assignment['subject_name']) ?></div>
                        </div>
                    </div>

                    <!-- Title and Type -->
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Assignment Title</label>
                            <div class="read-only-field"><?= htmlspecialchars($assignment['title']) ?></div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Assignment Type</label>
                            <div class="read-only-field"><?= htmlspecialchars(ucfirst($assignment['type'])) ?></div>
                        </div>
                    </div>

                    <!-- Description -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                        <div class="read-only-field h-32 overflow-y-auto"><?= htmlspecialchars($assignment['description'] ?? 'N/A') ?></div>
                    </div>
                </div>
            </section>

            <!-- Editable Fields Form -->
            <section class="bg-white rounded-xl shadow-md p-8 mb-8 animate-slide-up">
                <h2 class="text-xl font-semibold text-gray-800 mb-6">Edit Assignment Fields</h2>
                <form id="assignment-form" action="edit_assignment.php" method="POST" enctype="multipart/form-data" class="space-y-6" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="assignment_id" value="<?= htmlspecialchars($assignment_id) ?>">
                    <input type="hidden" id="questions_json" name="questions_json">

                    <!-- Questions -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Questions (Optional)</label>
                        <div id="questions-container" class="space-y-3">
                            <?php
                            $questions = json_decode($assignment['questions'] ?? '', true);
                            if (is_array($questions) && !empty($questions)) {
                                foreach ($questions as $index => $question) {
                                    if (isset($question['question_text'])) {
                                        echo '<div class="question-item flex items-center space-x-2 animate-slide-up">';
                                        echo '<input type="text" name="question_' . ($index + 1) . '" class="question-input form-control flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" placeholder="Enter question text" value="' . htmlspecialchars($question['question_text']) . '" aria-label="Question ' . ($index + 1) . '">';
                                        echo '<button type="button" class="remove-question bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-lg" aria-label="Remove question"><i class="fas fa-trash"></i></button>';
                                        echo '</div>';
                                    }
                                }
                            }
                        </div>
                        <button type="button" id="add-question" class="mt-3 bg-accent-500 hover:bg-accent-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center btn-accent">
                            <i class="fas fa-plus mr-2"></i> Add Question
                        </button>
                    </div>

                    <!-- Max Points and Due Date -->
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label for="max_points" class="block text-sm font-medium text-gray-700 mb-1">Maximum Points</label>
                            <input type="number" id="max_points" name="max_points" step="0.01" min="0" value="<?= htmlspecialchars($assignment['max_points'] ?? '100.00') ?>" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" aria-label="Maximum points">
                            <div class="invalid-feedback">Please enter a valid number.</div>
                        </div>
                        <div>
                            <label for="due_date" class="block text-sm font-medium text-gray-700 mb-1">Due Date</label>
                            <input type="date" id="due_date" name="due_date" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" aria-label="Due date" value="<?= htmlspecialchars($assignment['due_date'] ?? '') ?>">
                            <div class="invalid-feedback">Please enter a valid date (YYYY-MM-DD).</div>
                        </div>
                    </div>

                    <!-- File Upload -->
                    <div>
                        <label for="file" class="block text-sm font-medium text-gray-700 mb-1">Upload File (Optional)</label>
                        <input type="file" id="file" name="file" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" aria-label="Upload file">
                        <?php if ($assignment['file_path']): ?>
                            <p class="text-sm text-gray-500 mt-1">Current file: <?= htmlspecialchars(basename($assignment['file_path'])) ?></p>
                        <?php endif; ?>
                        <p class="text-sm text-gray-500 mt-1">Supported formats: PDF, DOC, DOCX, JPG, PNG (Max 5MB)</p>
                    </div>

                    <!-- Group Assignment -->
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label for="is_group_assignment" class="block text-sm font-medium text-gray-700 mb-1">Group Assignment</label>
                            <div class="flex items-center">
                                <input type="checkbox" id="is_group_assignment" name="is_group_assignment" class="h-4 w-4 text-primary-500 focus:ring-primary-500 border-gray-300 rounded" aria-label="Group assignment" <?= $assignment['is_group_assignment'] ? 'checked' : '' ?>>
                                <label for="is_group_assignment" class="ml-2 text-sm text-gray-700">This is a group assignment</label>
                            </div>
                        </div>
                        <div class="relative">
                            <label for="group_id" class="block text-sm font-medium text-gray-700 mb-1">Group</label>
                            <select id="group_id" name="group_id" class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" disabled aria-label="Select group">
                                <option value="">Select Group</option>
                                <!-- Populated via AJAX -->
                            </select>
                            <span class="loading-spinner absolute right-2 top-10"><i class="fas fa-spinner fa-spin"></i></span>
                            <div class="invalid-feedback">Please select a group.</div>
                        </div>
                    </div>

                    <!-- Status and Tags -->
                    <div class="grid sm:grid-cols-2 gap-4">
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select id="status" name="status" class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" aria-label="Select status">
                                <option value="draft" <?= $assignment['status'] === 'draft' ? 'selected' : '' ?>>Draft</option>
                                <option value="published" <?= $assignment['status'] === 'published' ? 'selected' : '' ?>>Published</option>
                                <option value="closed" <?= $assignment['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                            </select>
                            <div class="invalid-feedback">Please select a status.</div>
                        </div>
                        <div>
                            <label for="tags" class="block text-sm font-medium text-gray-700 mb-1">Tags (Optional)</label>
                            <input type="text" id="tags" name="tags" placeholder="e.g., math, algebra" aria-label="Tags" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" value="<?= htmlspecialchars($assignment['tags'] ?? '') ?>">
                            <p class="text-sm text-gray-500 mt-1">Separate tags with commas</p>
                        </div>
                    </div>

                    <!-- Actions -->
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="window.location.href='/teacher/manage_assignments.php'" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-primary" aria-label="Cancel">
                            <i class="fas fa-times mr-2"></i> Cancel
                        </button>
                        <button type="button" id="preview-button" class="bg-accent-500 hover:bg-accent-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-accent" aria-label="Preview">
                            <i class="fas fa-eye mr-2"></i> Preview
                        </button>
                        <button type="submit" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-primary" aria-label="Update assignment">
                            <i class="fas fa-save mr-2"></i> Update
                        </button>
                    </div>
                </form>
            </section>
        </main>
    </div>

    <?php
    $footer_path = './templates/teacher_footer.php';
    try {
        if (!file_exists($footer_path)) {
            throw new Exception("Teacher footer template: $footer_path");
        }
        include($footer_path);
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
            const groupSelect = $('#group_id');
            const isGroupAssignment = $('#is_group_assignment');
            const form = $('#assignment-form');
            const questionsContainer = $('#questions-container');
            const addQuestionButton = $('#add-question');
            const questionsJsonInput = $('#questions_json');
            const previewButton = $('#preview-button');
            const previewModal = $('#preview-modal');
            const closePreview = $('#close-preview');
            const editPreview = $('#edit-preview');
            const submitPreview = $('#submit-preview');
            const previewContent = $('#preview-content');

            let questionCount = <?= count(json_decode($assignment['questions'] ?? '', true) ?: []) ?>;
            const ajaxCache = {};

            // Show loading overlay
            loadingOverlay.removeClass('hidden');

            // Auto-dismiss alerts
            $('[role="alert"]').each(function() {
                setTimeout(() => {
                    $(this).addClass('opacity-0').slideUp(300, () => $(this).remove());
                }, 5000);
            });

            // Add question input
            function addQuestion(text = '') {
                questionCount++;
                const questionDiv = $(`
                    <div class="question-item flex items-center space-x-2 animate-slide-up">
                        <input type="text" name="question_${questionCount}" class="question-input form-control flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" placeholder="Enter question text" value="${text.replace(/"/g, '&quot;')}" aria-label="Question ${questionCount}">
                        <button type="button" class="remove-question bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-lg" aria-label="Remove question">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                `);
                questionsContainer.append(questionDiv);

                questionDiv.find('.remove-question').on('click', () => {
                    questionDiv.hide();
                });
            }

            // Add question button
            addQuestionButton.on('click', () => addQuestion());

            // Toggle group select
            isGroupAssignment.on('change', function() {
                groupSelect.prop('disabled', !this.checked);
                if (!this.checked) groupSelect.val('');
                validateGroup();
            });

            // Validate group field
            function validateGroup() {
                if (isGroupAssignment.is(':checked') && !groupSelect.val()) {
                    groupSelect.addClass('is-invalid');
                    return false;
                } else {
                    groupSelect.removeClass('is-invalid');
                    return true;
                }
            }

            // Fetch groups on load
            const classId = <?= $assignment['class_id'] ?>;
            if (classId) {
                const groupSpinner = groupSelect.next('.loading-spinner');
                groupSpinner.show();
                $.ajax({
                    url: '/api/get_groups.php',
                    type: 'GET',
                    data: { class_id: classId },
                    dataType: 'json',
                    success: function(response) {
                        groupSpinner.hide();
                        if (response.error) {
                            alert(`Error: ${response.error}`);
                            return;
                        }
                        $.each(response.groups, function(index, group) {
                            const selected = group.id == <?= $assignment['group_id'] ?? 'null' ?> ? 'selected' : '';
                            groupSelect.append(`<option value="${group.id}" ${selected}>${group.name}</option>`);
                        });
                        groupSelect.prop('disabled', !isGroupAssignment.is(':checked'));
                        validateGroup();
                    },
                    error: function(xhr, status, error) {
                        groupSpinner.hide();
                        console.error('Groups AJAX error:', status, error, xhr.responseText);
                        alert('Failed to fetch groups. Please try again.');
                    }
                });
            }

            // Form validation
            function validateForm() {
                let isValid = true;
                form.find('input[required], select[required]').each(function() {
                    if (!$(this).val()) {
                        $(this).addClass('is-invalid');
                        isValid = false;
                    } else {
                        $(this).removeClass('is-invalid');
                    }
                });

                const dueDate = $('#due_date').val();
                if (dueDate) {
                    const today = new Date().toISOString().split('T')[0];
                    if (dueDate < today) {
                        isValid = false;
                        $('#due_date').addClass('is-invalid');
                        alert('Due date cannot be in the past.');
                        $('#due_date').focus();
                    } else {
                        $('#due_date').removeClass('is-invalid');
                    }
                }

                if (!validateGroup()) {
                    isValid = false;
                    groupSelect.focus();
                }

                // Generate questions JSON
                const questions = [];
                $('.question-input').each(function() {
                    const text = $(this).val().trim();
                    if (text) {
                        questions.push({ question_text: text });
                    }
                });
                questionsJsonInput.val(questions.length ? JSON.stringify(questions) : '');

                return isValid;
            }

            // Form submission
            form.on('submit', function(e) {
                if (!validateForm()) {
                    e.preventDefault();
                    loadingOverlay.addClass('hidden');
                } else {
                    loadingOverlay.removeClass('hidden');
                }
            });

            // Preview button
            previewButton.on('click', function() {
                if (!validateForm()) {
                    return;
                }

                const formData = new FormData(form[0]);
                const questions = [];
                $('.question-input').each(function() {
                    const text = $(this).val().trim();
                    if (text) {
                        questions.push({ question_text: text });
                    }
                });
                formData.set('questions_json', questions.length ? JSON.stringify(questions) : '');

                // Build preview
                let previewHtml = `
                    <div class="border-b pb-2"><strong>Department:</strong> <?= htmlspecialchars($assignment['department_name']) ?></div>
                    <div class="border-b pb-2"><strong>Class:</strong> <?= htmlspecialchars($assignment['class_name']) ?></div>
                    <div class="border-b pb-2"><strong>Subject:</strong> <?= htmlspecialchars($assignment['subject_name']) ?></div>
                    <div class="border-b pb-2"><strong>Title:</strong> <?= htmlspecialchars($assignment['title']) ?></div>
                    <div class="border-b pb-2"><strong>Type:</strong> <?= htmlspecialchars(ucfirst($assignment['type'])) ?></div>
                    <div class="border-b pb-2"><strong>Description:</strong> <?= htmlspecialchars($assignment['description'] ?? 'N/A') ?></div>
                    <div class="border-b pb-2"><strong>Questions:</strong>
                        ${questions.length ? 
                            `<ol class="list-decimal ml-5 mt-2">${questions.map(q => `<li>${q.question_text}</li>`).join('')}</ol>` 
                            : 'None'}
                    </div>
                    <div class="border-b pb-2"><strong>Maximum Points:</strong> ${formData.get('max_points') || '100.00'}</div>
                    <div class="border-b pb-2"><strong>Due:</strong> : ${formData.get('due_date') || 'Not setdue_date') ? 'null' ?> 
                    <div class="border-b pb-2"><strong>File:</strong> ${formData.get('file').name || '<?= htmlspecialchars($assignment['file_path'] ? basename($assignment['file_path']) : 'None') ?>'}</div>
                    <div class="border-b pb-2"><strong>Group Assignment:</strong> ${formData.get('is-group_assignment') ? 'Yes' : 'No'}</div>
                    ${formData.get('is_group_assignment') ? `<div class="border-b pb-2"><strong>Group:</strong> ${groupSelect.find('option:selected').text() || 'N/A'}</div>` : ''}
                    <div class="border-b pb-2"><strong>Status:</strong> ${formData.get('status')}</div>
                    <div><strong>Tags:</strong> ${formData.get('tags') || 'None'}</div>
                `;
                previewContent.html(previewHtml);
                previewModal.removeClass('hidden');
            });

            // Close modal preview
            closePreview.on('click', () => previewModal.addClass('hidden'));

            // Edit modal preview
            editPreview.on('click', () => previewModal.addClass('hidden'));

            // Submit preview modal
            submitPreview.on('click', () => {
                if (validateForm()) {
                    loadingOverlay.removeClass('hidden');
                    form.submit();
                }
            });

            // Hide loading state overlay
            setTimeout(() => loadingOverlay.addClass('hidden'), 500);

            // Real-time validation
            form.find('input, select').on('input change', function() {
                if ($(this).prop('required') && !$(this).val()) {
                    $(this).addClass('is-invalid');
                } else {
                    $(this).removeClass('is-invalid');
                }
            });
        });
    </script>
</body>
</html>