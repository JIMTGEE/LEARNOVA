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
$action = "Accessed Manage Resources Page";
$description = "Teacher accessed manage resources page";
logActivity($conn, $teacher_id, $action, $description);

// Generate and store CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Fetch current term
$current_term_sql = "SELECT id FROM academic_calendar WHERE start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1";
$current_term_stmt = $conn->prepare($current_term_sql);
$current_term_id = 1; // Fallback term ID
if ($current_term_stmt) {
    $current_term_stmt->execute();
    $current_term = $current_term_stmt->get_result()->fetch_assoc();
    $current_term_id = $current_term['id'] ?? 1;
    $current_term_stmt->close();
} else {
    error_log("Prepare failed for current term query: " . $conn->error);
}

// Handle actions (delete, publish, unpublish)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            error_log("CSRF token validation failed: Expected " . ($_SESSION['csrf_token'] ?? 'none') . ", Received " . ($_POST['csrf_token'] ?? 'none'));
            throw new Exception("Invalid CSRF token.");
        }

        $action = trim(filter_input(INPUT_POST, 'action', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
        $resource_id = filter_input(INPUT_POST, 'resource_id', FILTER_VALIDATE_INT);

        if (!$resource_id) {
            throw new Exception("Invalid resource ID.");
        }

        $verify_sql = "SELECT id FROM learning_resources WHERE id = ? AND teacher_id = ?";
        $verify_stmt = $conn->prepare($verify_sql);
        $verify_stmt->bind_param("ii", $resource_id, $teacher_id);
        $verify_stmt->execute();
        if ($verify_stmt->get_result()->num_rows === 0) {
            throw new Exception("You do not have permission to modify this resource.");
        }
        $verify_stmt->close();

        if ($action === 'delete') {
            $delete_feedback_sql = "DELETE FROM resource_feedback WHERE resource_id = ?";
            $delete_feedback_stmt = $conn->prepare($delete_feedback_sql);
            $delete_feedback_stmt->bind_param("i", $resource_id);
            $delete_feedback_stmt->execute();
            $delete_feedback_stmt->close();

            $delete_sql = "DELETE FROM learning_resources WHERE id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $resource_id);
            $delete_stmt->execute();
            $delete_stmt->close();

            logActivity($conn, $teacher_id, "Deleted Resource", "Resource ID $resource_id deleted");
            $_SESSION['success_msg'] = "Resource deleted successfully.";
        } elseif ($action === 'publish' || $action === 'unpublish') {
            $is_approved = $action === 'publish' ? 1 : 0;
            $update_sql = "UPDATE learning_resources SET is_approved = ?, approved_by = ?, approved_at = NOW() WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("iii", $is_approved, $teacher_id, $resource_id);
            $update_stmt->execute();
            $update_stmt->close();

            logActivity($conn, $teacher_id, "Updated Resource Status", "Resource ID $resource_id set to " . ($is_approved ? 'published' : 'unpublished'));
            $_SESSION['success_msg'] = "Resource " . ($is_approved ? 'published' : 'unpublished') . " successfully.";
        } else {
            throw new Exception("Invalid action specified.");
        }

        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $conn->commit();
        header("Location: /teacher/manage_resources.php");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Action error: " . $e->getMessage());
        $_SESSION['error_msg'] = $e->getMessage();
    }
}

// Fetch resources with pagination, filters, and sorting
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]) ?: 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;
$search = trim(filter_input(INPUT_GET, 'search', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$status_filter = trim(filter_input(INPUT_GET, 'status', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$class_filter = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT) ?: 0;
$sort_by = trim(filter_input(INPUT_GET, 'sort_by', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'created_at');
$sort_order = trim(filter_input(INPUT_GET, 'sort_order', FILTER_SANITIZE_SPECIAL_CHARS) ?? 'DESC');

$valid_sort_columns = ['title', 'created_at', 'resource_type', 'is_approved', 'rating'];
$sort_by = in_array($sort_by, $valid_sort_columns) ? $sort_by : 'created_at';
$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

$where_conditions = ["lr.teacher_id = ?"];
$params = [$teacher_id];
$types = "i";

if ($search !== '') {
    $where_conditions[] = "(lr.title LIKE ? OR lr.description LIKE ? OR lr.tags LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if ($status_filter && in_array($status_filter, ['published', 'unpublished'])) {
    $is_approved = $status_filter === 'published' ? 1 : 0;
    $where_conditions[] = "lr.is_approved = ?";
    $params[] = $is_approved;
    $types .= "i";
}

if ($class_filter) {
    $where_conditions[] = "lr.class_id = ?";
    $params[] = $class_filter;
    $types .= "i";
}

$where_clause = implode(" AND ", $where_conditions);

$count_sql = "SELECT COUNT(*) as total FROM learning_resources lr WHERE $where_clause";
$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_resources = $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();
$total_pages = max(1, ceil($total_resources / $per_page));

$resources_sql = "SELECT lr.id, lr.title, lr.description, lr.resource_type, lr.is_approved, lr.file_path, lr.url, 
                         lr.created_at, lr.views_count, lr.downloads_count, lr.rating, lr.rating_count, lr.ghanaian_content,
                         lr.access_level, c.class_name, s.subject_name
                  FROM learning_resources lr
                  LEFT JOIN classes c ON lr.class_id = c.id
                  LEFT JOIN subjects s ON lr.subject_id = s.id
                  WHERE $where_clause
                  ORDER BY lr.$sort_by $sort_order
                  LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types .= "ii";

$resources_stmt = $conn->prepare($resources_sql);
if (!$resources_stmt) {
    error_log("Prepare failed for resources query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch resources.";
    header("Location: /teacher/dashboard.php");
    exit();
}
$resources_stmt->bind_param($types, ...$params);
$resources_stmt->execute();
$resources_result = $resources_stmt->get_result();
$resources = $resources_result->fetch_all(MYSQLI_ASSOC);
$resources_stmt->close();

// Fetch classes for filter
$classes_sql = "SELECT DISTINCT c.id, c.class_name 
                FROM classes c 
                LEFT JOIN class_schedules cs ON c.id = cs.class_id 
                WHERE c.department_id = ? 
                AND (c.teacher_id = ? OR cs.teacher_id = ?)";
$classes_stmt = $conn->prepare($classes_sql);
if (!$classes_stmt) {
    error_log("Prepare failed for classes query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch classes.";
    header("Location: /teacher/dashboard.php");
    exit();
}
$classes_stmt->bind_param("iii", $teacher_department_id, $teacher_id, $teacher_id);
$classes_stmt->execute();
$classes = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$classes_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage learning resources for QueensPa School.">
    <meta name="keywords" content="QueensPa School, manage resources, teacher dashboard, learning materials">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>Manage Resources - QueensPa School</title>

    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>

    <!-- Fonts -->
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
                            500: '#0288d1',
                            600: '#0270a8',
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
            font-family: 'Poppins', sans-serif;
            line-height: 1.6;
        }
        .form-control:focus, .form-select:focus {
            box-shadow: 0 0 0 3px rgba(2, 136, 209, 0.1);
            border-color: #0288d1;
        }
        .btn-primary, .btn-accent {
            transition: transform 0.2s, background-color 0.3s;
        }
        .btn-primary:hover, .btn-accent:hover {
            transform: translateY(-1px);
        }
        .table-row:hover {
            background-color: #f1f5f9;
            transition: background-color 0.2s;
        }
        .modal-content {
            max-height: 80vh;
            overflow-y: auto;
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50">
    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-primary-500 text-5xl"></i>
    </div>

    <!-- Edit Modal -->
    <div id="edit-modal" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <div class="bg-white rounded-xl shadow-2xl max-w-3xl w-full modal-content animate-scale-in">
            <div class="bg-primary-600 text-white px-6 py-4 rounded-t-xl flex justify-between items-center">
                <h3 class="text-lg font-semibold">Edit Resource</h3>
                <button id="close-edit" class="text-white hover:text-gray-200" aria-label="Close edit modal">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="edit-form" action="/teacher/create_resource.php" method="POST" enctype="multipart/form-data" class="p-6 space-y-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="resource_id" id="edit_resource_id">
                <div>
                    <label for="edit_title" class="block text-sm font-medium text-gray-700 mb-1">Resource Title <span class="text-red-500">*</span></label>
                    <input type="text" id="edit_title" name="title" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg" required>
                </div>
                <div>
                    <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea id="edit_description" name="description" rows="4" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg"></textarea>
                </div>
                <div>
                    <label for="edit_resource_type" class="block text-sm font-medium text-gray-700 mb-1">Resource Type <span class="text-red-500">*</span></label>
                    <select id="edit_resource_type" name="resource_type" class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg" required>
                        <option value="">Select Type</option>
                        <option value="document">Document</option>
                        <option value="video">Video</option>
                        <option value="audio">Audio</option>
                        <option value="link">Link</option>
                        <option value="interactive">Interactive</option>
                        <option value="image">Image</option>
                        <option value="pdf">PDF</option>
                        <option value="worksheet">Worksheet</option>
                        <option value="presentation">Presentation</option>
                        <option value="quiz">Quiz</option>
                    </select>
                </div>
                <div>
                    <label for="edit_url" class="block text-sm font-medium text-gray-700 mb-1">URL (Optional)</label>
                    <input type="url" id="edit_url" name="url" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label for="edit_file" class="block text-sm font-medium text-gray-700 mb-1">Upload File (Optional)</label>
                    <input type="file" id="edit_file" name="file" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label for="edit_tags" class="block text-sm font-medium text-gray-700 mb-1">Tags (Optional)</label>
                    <input type="text" id="edit_tags" name="tags" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label for="edit_access_level" class="block text-sm font-medium text-gray-700 mb-1">Access Level</label>
                    <select id="edit_access_level" name="access_level" class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="public">Public</option>
                        <option value="class">Class Only</option>
                        <option value="private">Private</option>
                    </select>
                </div>
                <div class="flex items-center">
                    <input type="checkbox" id="edit_ghanaian_content" name="ghanaian_content" class="h-4 w-4 text-primary-500 border-gray-300 rounded">
                    <label for="edit_ghanaian_content" class="ml-2 text-sm text-gray-700">Contains Ghanaian Content</label>
                </div>
                <div class="flex justify-end space-x-3">
                    <button type="button" id="cancel-edit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium btn-primary">Cancel</button>
                    <button type="submit" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg text-sm font-medium btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

    <div class="container mx-auto px-4 py-12 max-w-7xl">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-center mb-10">
            <h1 class="text-3xl font-bold text-gray-800 flex items-center">
                <i class="fas fa-book-open text-primary-500 mr-3"></i> Manage Resources
            </h1>
            <a href="/teacher/create_resource.php" class="mt-4 sm:mt-0 bg-accent-500 hover:bg-accent-600 text-white px-4 py-2 rounded-lg text-sm font-medium btn-accent">
                <i class="fas fa-plus mr-2"></i> Create New Resource
            </a>
        </div>

        <!-- Messages -->
        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center">
                <i class="fas fa-exclamation-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['error_msg'], ENT_QUOTES, 'UTF-8') ?>
                <?php unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center">
                <i class="fas fa-check-circle mr-2"></i>
                <?= htmlspecialchars($_SESSION['success_msg'], ENT_QUOTES, 'UTF-8') ?>
                <?php unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>

        <!-- Filters and Search -->
        <div class="bg-white rounded-xl shadow-md p-6 mb-8">
            <form id="filter-form" method="GET" class="flex flex-col sm:flex-row gap-4">
                <div class="flex-1">
                    <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Search</label>
                    <input type="text" id="search" name="search" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div>
                    <label for="status_filter" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                    <select id="status_filter" name="status" class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="">All Statuses</option>
                        <option value="published" <?= $status_filter === 'published' ? 'selected' : '' ?>>Published</option>
                        <option value="unpublished" <?= $status_filter === 'unpublished' ? 'selected' : '' ?>>Unpublished</option>
                    </select>
                </div>
                <div>
                    <label for="class_filter" class="block text-sm font-medium text-gray-700 mb-1">Class</label>
                    <select id="class_filter" name="class_id" class="form-select w-full px-3 py-2 border border-gray-300 rounded-lg">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?= htmlspecialchars($class['id'], ENT_QUOTES, 'UTF-8') ?>" <?= $class_filter == $class['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($class['class_name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </form>
        </div>

        <!-- Resources Table -->
        <div class="bg-white rounded-xl shadow-md p-6">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 uppercase tracking-wider cursor-pointer" data-sort="title">Title</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">Class</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">Subject</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 uppercase tracking-wider cursor-pointer" data-sort="resource_type">Type</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 uppercase tracking-wider cursor-pointer" data-sort="rating">Rating</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">Views</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">Downloads</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 uppercase tracking-wider cursor-pointer" data-sort="created_at">Created At</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 uppercase tracking-wider cursor-pointer" data-sort="is_approved">Status</th>
                            <th class="px-6 py-3 text-left text-sm font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($resources)): ?>
                            <tr>
                                <td colspan="10" class="px-6 py-4 text-center text-gray-500">No resources found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($resources as $resource): ?>
                                <tr class="table-row">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?= htmlspecialchars($resource['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        <?php if ($resource['ghanaian_content']): ?>
                                            <span class="ml-2 text-yellow-500" title="Contains Ghanaian Content">🇬🇭</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($resource['class_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars($resource['subject_name'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= htmlspecialchars(ucfirst($resource['resource_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= number_format($resource['rating'] ?? 0, 2) ?> (<?= $resource['rating_count'] ?? 0 ?>)</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $resource['views_count'] ?? 0 ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= $resource['downloads_count'] ?? 0 ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?= date('Y-m-d', strtotime($resource['created_at'] ?? 'now')) ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= $resource['is_approved'] ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800' ?>">
                                            <?= $resource['is_approved'] ? 'Published' : 'Unpublished' ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                        <?php if (!empty($resource['file_path']) || !empty($resource['url'])): ?>
                                            <a href="<?= htmlspecialchars($resource['file_path'] ?: $resource['url'], ENT_QUOTES, 'UTF-8') ?>" class="text-blue-500 hover:text-blue-600" target="_blank" title="View Resource">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        <?php endif; ?>
                                        <button class="edit-resource text-primary-500 hover:text-primary-600" 
                                                data-resource-id="<?= htmlspecialchars($resource['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>" 
                                                data-title="<?= htmlspecialchars($resource['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>" 
                                                data-description="<?= htmlspecialchars($resource['description'] ?? '', ENT_QUOTES, 'UTF-8') ?>" 
                                                data-resource-type="<?= htmlspecialchars($resource['resource_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>" 
                                                data-url="<?= htmlspecialchars($resource['url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" 
                                                data-tags="<?= htmlspecialchars($resource['tags'] ?? '', ENT_QUOTES, 'UTF-8') ?>" 
                                                data-access-level="<?= htmlspecialchars($resource['access_level'] ?? 'public', ENT_QUOTES, 'UTF-8') ?>" 
                                                data-ghanaian-content="<?= (int)($resource['ghanaian_content'] ?? 0) ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form action="/teacher/manage_resources.php" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to <?= $resource['is_approved'] ? 'unpublish' : 'publish' ?> this resource?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="<?= $resource['is_approved'] ? 'unpublish' : 'publish' ?>">
                                            <input type="hidden" name="resource_id" value="<?= htmlspecialchars($resource['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="text-green-500 hover:text-green-600" title="<?= $resource['is_approved'] ? 'Unpublish' : 'Publish' ?> Resource">
                                                <i class="fas fa-<?= $resource['is_approved'] ? 'lock' : 'paper-plane' ?>"></i>
                                            </button>
                                        </form>
                                        <form action="/teacher/manage_resources.php" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this resource?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="resource_id" value="<?= htmlspecialchars($resource['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="text-red-500 hover:text-red-600" title="Delete Resource">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
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
                    <div class="text-sm text-gray-600">
                        Showing <?= $offset + 1 ?> to <?= min($offset + $per_page, $total_resources) ?> of <?= $total_resources ?> resources
                    </div>
                    <div class="flex space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&class_id=<?= $class_filter ?>&sort_by=<?= $sort_by ?>&sort_order=<?= $sort_order ?>" 
                               class="bg-primary-500 hover:bg-primary-600 text-white px-3 py-1 rounded-lg text-sm font-medium btn-primary">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&class_id=<?= $class_filter ?>&sort_by=<?= $sort_by ?>&sort_order=<?= $sort_order ?>" 
                               class="px-3 py-1 rounded-lg text-sm font-medium <?= $i == $page ? 'bg-primary-500 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300' ?>">
                               <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status_filter) ?>&class_id=<?= $class_filter ?>&sort_by=<?= $sort_by ?>&sort_order=<?= $sort_order ?>" 
                               class="bg-primary-500 hover:bg-primary-600 text-white px-3 py-1 rounded-lg text-sm font-medium btn-primary">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>

    <script>
        $(document).ready(function() {
            const loadingOverlay = $('#loading-overlay');
            const editModal = $('#edit-modal');
            const editForm = $('#edit-form');
            const closeEdit = $('#close-edit');
            const cancelEdit = $('#cancel-edit');
            const filterForm = $('#filter-form');

            // Filter and search with debouncing
            let filterTimeout;
            $('#search, #status_filter, #class_filter').on('input change', function() {
                clearTimeout(filterTimeout);
                filterTimeout = setTimeout(() => {
                    filterForm.submit();
                }, 300);
            });

            // Sort table
            $('th[data-sort]').on('click', function() {
                const sortBy = $(this).data('sort');
                const currentSortBy = '<?= htmlspecialchars($sort_by, ENT_QUOTES, 'UTF-8') ?>';
                const currentSortOrder = '<?= htmlspecialchars($sort_order, ENT_QUOTES, 'UTF-8') ?>';
                const sortOrder = (sortBy === currentSortBy && currentSortOrder === 'ASC') ? 'DESC' : 'ASC';
                const search = '<?= urlencode($search) ?>';
                const status = '<?= urlencode($status_filter) ?>';
                const classId = '<?= $class_filter ?>';
                window.location.href = `?search=${search}&status=${status}&class_id=${classId}&sort_by=${sortBy}&sort_order=${sortOrder}`;
            });

            // Edit resource
            $('.edit-resource').on('click', function() {
                $('#edit_resource_id').val($(this).data('resource-id'));
                $('#edit_title').val($(this).data('title'));
                $('#edit_description').val($(this).data('description'));
                $('#edit_resource_type').val($(this).data('resource-type'));
                $('#edit_url').val($(this).data('url'));
                $('#edit_tags').val($(this).data('tags'));
                $('#edit_access_level').val($(this).data('access-level'));
                $('#edit_ghanaian_content').prop('checked', $(this).data('ghanaian-content') === 1);
                editModal.removeClass('hidden');
            });

            // Close edit modal
            closeEdit.on('click', () => editModal.addClass('hidden'));
            cancelEdit.on('click', () => editModal.addClass('hidden'));

            // Form submission
            editForm.on('submit', () => loadingOverlay.removeClass('hidden'));

            // Hide loading overlay
            loadingOverlay.addClass('hidden');
        });
    </script>
</body>
</html>
<?php
mysqli_close($conn);
ob_end_flush();
?>