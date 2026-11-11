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
$action = "Accessed View Assignment Page";
$description = "Student accessed view assignment page";
logActivity($conn, $student_id, $action, $description);

// Generate and store CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
error_log("Generated CSRF token: $csrf_token");

// Fetch assignment details
$assignment_id = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT);
if (!$assignment_id) {
    error_log("Invalid or missing assignment_id: " . ($_GET['assignment_id'] ?? 'unset'));
    $_SESSION['error_msg'] = "Invalid assignment ID.";
    header("Location: /student/manage_student_assignments.php");
    exit();
}

$assignment_sql = "SELECT a.id, a.title, a.description, a.type, a.max_points, a.due_date, a.status, 
                          a.is_group_assignment, a.group_id, a.file_path, a.questions, 
                          c.class_name, s.subject_name, g.name as group_name
                   FROM assignments a
                   LEFT JOIN classes c ON a.class_id = c.id
                   LEFT JOIN subjects s ON a.subject_id = s.id
                   LEFT JOIN student_classes sc ON a.class_id = sc.class_id
                   LEFT JOIN group_members gm ON a.group_id = gm.group_id
                   LEFT JOIN groups g ON a.group_id = g.id
                   WHERE a.id = ? 
                   AND a.status = 'published'
                   AND (a.due_date IS NULL OR a.due_date >= CURDATE())
                   AND (sc.user_id = ? OR gm.user_id = ?)";
$assignment_stmt = $conn->prepare($assignment_sql);
$assignment_stmt->bind_param("iii", $assignment_id, $student_id, $student_id);
$assignment_stmt->execute();
$assignment_result = $assignment_stmt->get_result();
if ($assignment_result->num_rows === 0) {
    error_log("Assignment not found or not accessible: assignment_id=$assignment_id, student_id=$student_id");
    $_SESSION['error_msg'] = "Assignment not found or not accessible.";
    header("Location: /student/manage_student_assignments.php");
    exit();
}
$assignment = $assignment_result->fetch_assoc();
$assignment_stmt->close();

// Check for existing submission
$submission_sql = "SELECT id FROM assignment_submissions 
                   WHERE assignment_id = ? 
                   AND (user_id = ? OR group_id = ?)";
$submission_stmt = $conn->prepare($submission_sql);
$submission_stmt->bind_param("iii", $assignment_id, $student_id, $assignment['group_id']);
$submission_stmt->execute();
$submission_result = $submission_stmt->get_result();
$has_submission = $submission_result->num_rows > 0;
$submission_stmt->close();

if ($has_submission) {
    error_log("Submission already exists for assignment_id=$assignment_id, student_id=$student_id");
    $_SESSION['error_msg'] = "You have already submitted this assignment.";
    header("Location: /student/manage_student_assignments.php");
    exit();
}

// Parse questions
$questions = [];
if ($assignment['questions']) {
    $decoded = json_decode($assignment['questions'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        foreach ($decoded as $q) {
            if (isset($q['question_text']) && !empty(trim($q['question_text']))) {
                $questions[] = ['question_text' => $q['question_text'], 'type' => 'text'];
            } else {
                error_log("Invalid question structure for assignment_id=$assignment_id: " . json_encode($q));
            }
        }
    } else {
        error_log("Failed to decode questions JSON for assignment_id=$assignment_id: " . json_last_error_msg());
    }
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

        // Collect answers
        $answers = [];
        foreach ($questions as $index => $question) {
            $answer_key = "answer_$index";
            $answer = trim(filter_input(INPUT_POST, $answer_key, FILTER_UNSAFE_RAW) ?? '');
            if (empty($answer)) {
                throw new Exception("Answer for question " . ($index + 1) . " is required.");
            }
            $answers[] = [
                'question_text' => $question['question_text'],
                'type' => $question['type'],
                'answer' => $answer
            ];
        }
        $answers_json = json_encode($answers);
        if ($answers_json === false) {
            throw new Exception("Failed to encode answers.");
        }

        // Handle file upload
        $file_paths = [];
        $allowed_extensions = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        $max_file_size = 5 * 1024 * 1024; // 5MB
        $max_total_length = 500; // Assumed VARCHAR(500) for file_paths
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/Submissions/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        if (!empty($_FILES['files']['name'][0])) {
            foreach ($_FILES['files']['name'] as $key => $name) {
                if ($_FILES['files']['error'][$key] === UPLOAD_ERR_OK) {
                    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    if (!in_array($ext, $allowed_extensions)) {
                        throw new Exception("Invalid file type for $name. Allowed: pdf, doc, docx, jpg, png.");
                    }
                    if ($_FILES['files']['size'][$key] > $max_file_size) {
                        throw new Exception("File $name exceeds 5MB limit.");
                    }
                    $file_name = time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $file_path = '/Uploads/Submissions/' . $file_name;
                    if (!move_uploaded_file($_FILES['files']['tmp_name'][$key], $_SERVER['DOCUMENT_ROOT'] . $file_path)) {
                        throw new Exception("Failed to upload file: $name");
                    }
                    $file_paths[] = $file_path;
                } elseif ($_FILES['files']['error'][$key] !== UPLOAD_ERR_NO_FILE) {
                    throw new Exception("Error uploading file: $name (Error code: {$_FILES['files']['error'][$key]}).");
                }
            }
        }
        $file_paths_str = !empty($file_paths) ? implode(',', $file_paths) : null;
        if ($file_paths_str && strlen($file_paths_str) > $max_total_length) {
            throw new Exception("Total file paths length exceeds limit.");
        }

        // Insert submission
        $insert_sql = "INSERT INTO assignment_submissions (assignment_id, user_id, group_id, answers, file_paths, status, submission_date, created_at) 
                       VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())";
        $status = $assignment['due_date'] && strtotime($assignment['due_date']) < time() ? 'late' : 'submitted';
        $insert_stmt = $conn->prepare($insert_sql);
        if (!$insert_stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $group_id = $assignment['is_group_assignment'] ? $assignment['group_id'] : null;
        $user_id = $assignment['is_group_assignment'] ? null : $student_id;
        $insert_stmt->bind_param("iiisss", $assignment_id, $user_id, $group_id, $answers_json, $file_paths_str, $status);
        $insert_stmt->execute();
        $insert_stmt->close();

        // Log activity
        logActivity($conn, $student_id, "Submitted Assignment", "Assignment ID $assignment_id submitted" . ($assignment['is_group_assignment'] ? " for group ID $group_id" : ""));

        // Regenerate CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        error_log("Regenerated CSRF token: " . $_SESSION['csrf_token']);

        $conn->commit();
        $_SESSION['success_msg'] = "Assignment submitted successfully.";
        header("Location: /student/manage_student_assignments.php");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Submission error: " . $e->getMessage());
        $error_msg = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View and submit assignments for QueensPa School students.">
    <meta name="keywords" content="QueensPa School, view assignments, submit assignments, student dashboard">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="View Assignment - QueensPa School">
    <meta property="og:description" content="View assignment details and submit answers at QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/student/view_assignments.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="View Assignment - QueensPa School">
    <meta name="twitter:description" content="View assignment details and submit answers at QueensPa School.">
    <title>View Assignment - QueensPa School</title>

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

        .question-card {
            transition: opacity 0.3s ease;
        }

        .question-card.hidden {
            display: none;
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50">
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

    <div class="content">
        <main class="container mx-auto px-4 py-12 max-w-7xl">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row justify-between items-center mb-10 animate-slide-up">
                <h1 class="text-3xl font-bold text-gray-800 flex items-center">
                    <i class="fas fa-clipboard-list text-primary-500 mr-3"></i> <?= htmlspecialchars($assignment['title']) ?>
                </h1>
                <a href="/student/manage_student_assignments.php" class="mt-4 sm:mt-0 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center btn-primary" aria-label="Back to assignments">
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
            <section class="bg-white rounded-xl shadow-md p-6 mb-8 animate-slide-up">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Assignment Details</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm font-medium text-gray-700">Class</p>
                        <p class="text-gray-600"><?= htmlspecialchars($assignment['class_name']) ?></p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-700">Subject</p>
                        <p class="text-gray-600"><?= htmlspecialchars($assignment['subject_name']) ?></p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-700">Type</p>
                        <p class="text-gray-600"><?= ucfirst($assignment['type']) ?></p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-700">Max Points</p>
                        <p class="text-gray-600"><?= number_format($assignment['max_points'], 2) ?></p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-700">Due Date</p>
                        <p class="text-gray-600"><?= $assignment['due_date'] ? date('Y-m-d', strtotime($assignment['due_date'])) : 'Not set' ?></p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-700">Assignment Type</p>
                        <p class="text-gray-600"><?= $assignment['is_group_assignment'] ? 'Group (' . htmlspecialchars($assignment['group_name'] ?? 'N/A') . ')' : 'Individual' ?></p>
                    </div>
                    <?php if ($assignment['file_path']): ?>
                        <div>
                            <p class="text-sm font-medium text-gray-700">Attachment</p>
                            <a href="<?= htmlspecialchars($assignment['file_path']) ?>" class="text-blue-500 hover:text-blue-600" target="_blank" aria-label="Download assignment file">
                                <i class="fas fa-download mr-1"></i> Download File
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="mt-4">
                    <p class="text-sm font-medium text-gray-700">Description</p>
                    <p class="text-gray-600"><?= htmlspecialchars($assignment['description'] ?: 'No description provided.') ?></p>
                </div>
            </section>

            <!-- Questions and Submission -->
            <section class="bg-white rounded-xl shadow-md p-6 animate-slide-up">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Questions</h2>
                <form id="submission-form" action="view_assignments.php?assignment_id=<?= $assignment_id ?>" method="POST" enctype="multipart/form-data" class="space-y-6" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <?php if (empty($questions)): ?>
                        <p class="text-gray-500">No questions available for this assignment.</p>
                        <div id="file-upload">
                            <label for="files" class="block text-sm font-medium text-gray-700 mb-1">Upload Files (Optional)</label>
                            <input type="file" id="files" name="files[]" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" multiple aria-label="Upload files">
                            <p class="text-sm text-gray-500 mt-1">Supported formats: PDF, DOC, DOCX, JPG, PNG (Max 5MB each)</p>
                        </div>
                        <div class="flex justify-end mt-6">
                            <button type="submit" id="submit-btn" class="bg-accent-500 hover:bg-accent-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-accent" aria-label="Submit assignment">
                                <i class="fas fa-save mr-2"></i> Submit
                            </button>
                        </div>
                    <?php else: ?>
                        <div id="questions-container">
                            <?php foreach ($questions as $index => $question): ?>
                                <div class="question-card <?= $index > 0 ? 'hidden' : '' ?>" data-index="<?= $index ?>">
                                    <h3 class="text-lg font-medium text-gray-800 mb-2">Question <?= $index + 1 ?> of <?= count($questions) ?></h3>
                                    <p class="text-gray-600 mb-4"><?= htmlspecialchars($question['question_text']) ?></p>
                                    <textarea name="answer_<?= $index ?>" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" rows="4" placeholder="Enter your answer here" required></textarea>
                                    <p class="invalid-feedback">This field is required.</p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="file-upload" class="mt-6 hidden">
                            <label for="files" class="block text-sm font-medium text-gray-700 mb-1">Upload Files (Optional)</label>
                            <input type="file" id="files" name="files[]" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" multiple aria-label="Upload files">
                            <p class="text-sm text-gray-500 mt-1">Supported formats: PDF, DOC, DOCX, JPG, PNG (Max 5MB each)</p>
                        </div>
                        <div class="flex justify-between mt-6">
                            <button type="button" id="prev-question" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-primary hidden" aria-label="Previous question">
                                <i class="fas fa-chevron-left mr-2"></i> Previous
                            </button>
                            <button type="button" id="next-question" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-primary" aria-label="Next question">
                                Next <i class="fas fa-chevron-right ml-2"></i>
                            </button>
                            <button type="submit" id="submit-btn" class="bg-accent-500 hover:bg-accent-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors btn-accent hidden" aria-label="Submit assignment">
                                <i class="fas fa-save mr-2"></i> Submit
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
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
        $(document).ready(function() {
            const loadingOverlay = $('#loading-overlay');
            const questions = $('.question-card');
            const prevBtn = $('#prev-question');
            const nextBtn = $('#next-question');
            const submitBtn = $('#submit-btn');
            const fileUpload = $('#file-upload');
            let currentQuestion = 0;

            // Show loading overlay
            loadingOverlay.removeClass('hidden');

            // Auto-dismiss alerts
            $('[role="alert"]').each(function() {
                setTimeout(() => {
                    $(this).addClass('opacity-0').slideUp(500, () => $(this).remove());
                }, 5000);
            });

            // Show/hide questions
            function showQuestion(index) {
                questions.addClass('hidden').eq(index).removeClass('hidden');
                prevBtn.toggleClass('hidden', index === 0);
                nextBtn.toggleClass('hidden', index === questions.length - 1);
                submitBtn.toggleClass('hidden', index !== questions.length - 1);
                fileUpload.toggleClass('hidden', index !== questions.length - 1);
                currentQuestion = index;
            }

            // Navigation
            prevBtn.on('click', function() {
                if (currentQuestion > 0) {
                    showQuestion(currentQuestion - 1);
                }
            });

            nextBtn.on('click', function() {
                if (validateCurrentQuestion() && currentQuestion < questions.length - 1) {
                    showQuestion(currentQuestion + 1);
                }
            });

            // Validation
            function validateCurrentQuestion() {
                const current = questions.eq(currentQuestion);
                const textarea = current.find('textarea');
                const isValid = textarea.val().trim() !== '';
                textarea.toggleClass('is-invalid', !isValid);
                return isValid;
            }

            // Form submission
            $('#submission-form').on('submit', function(e) {
                if (questions.length > 0 && !validateCurrentQuestion()) {
                    e.preventDefault();
                    return false;
                }
                loadingOverlay.removeClass('hidden');
            });

            // Real-time validation
            questions.find('textarea').on('input', function() {
                if ($(this).val().trim()) {
                    $(this).removeClass('is-invalid');
                }
            });

            // Initialize
            if (questions.length === 0) {
                fileUpload.removeClass('hidden');
                submitBtn.removeClass('hidden');
                nextBtn.addClass('hidden');
            } else {
                showQuestion(0);
            }

            // Hide loading overlay
            setTimeout(() => loadingOverlay.addClass('hidden'), 500);
        });
    </script>
</body>
</html>