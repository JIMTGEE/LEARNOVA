<?php
session_start();

// Restrict to teachers or admins
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'], ['teacher', 'admin'])) {
    error_log("Redirect to login: user_id=" . ($_SESSION['user_id'] ?? 'unset') . 
              ", user_role=" . ($_SESSION['user_role'] ?? 'unset') . 
              ", requested_url=" . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
    header("Location: /teacher/login.php");
    exit();
}

// Include database connection and functions
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Set time zone
date_default_timezone_set('Africa/Accra');

// Initialize variables
$teacher_id = $_SESSION['user_id'];
$success_msg = $error_msg = "";
$department_filter = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;

// Fetch terms from academic_calendar
$terms_sql = "SELECT id, term_name FROM academic_calendar ORDER BY term_name";
$terms_stmt = $conn->prepare($terms_sql);
$terms_stmt->execute();
$terms_result = $terms_stmt->get_result();
$terms = $terms_result->fetch_all(MYSQLI_ASSOC);
$terms_stmt->close();

// Fetch teacher’s department
$teacher_sql = "SELECT department_id FROM users WHERE id = ?";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher_result = $teacher_stmt->get_result();
$teacher = $teacher_result->fetch_assoc();
$teacher_department_id = $teacher ? $teacher['department_id'] : null;
$teacher_stmt->close();

if (!$teacher_department_id) {
    $error_msg = "Teacher is not assigned to a department.";
}

// Fetch departments
$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_stmt = $conn->prepare($departments_sql);
$departments_stmt->execute();
$departments_result = $departments_stmt->get_result();
$departments = $departments_result->fetch_all(MYSQLI_ASSOC);
$departments_stmt->close();

// Handle AJAX request for classes by department
if (isset($_GET['action']) && $_GET['action'] === 'get_classes_by_department') {
    $department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
    $response = ['classes' => [], 'error' => '', 'debug' => ''];
    if ($department_id <= 0) {
        $response['error'] = 'Invalid department ID.';
        $response['debug'] = 'Department ID: ' . $department_id;
    } else {
        $classes_sql = "SELECT id, class_name FROM classes WHERE department_id = ? ORDER BY class_name";
        $classes_stmt = $conn->prepare($classes_sql);
        if (!$classes_stmt) {
            $response['error'] = 'Database query preparation failed.';
            $response['debug'] = 'SQL Error: ' . $conn->error;
            error_log("Classes query error: " . $conn->error);
        } else {
            $classes_stmt->bind_param("i", $department_id);
            if (!$classes_stmt->execute()) {
                $response['error'] = 'Query execution failed.';
                $response['debug'] = 'SQL Error: ' . $classes_stmt->error;
                error_log("Classes query execution error: " . $classes_stmt->error);
            } else {
                $classes_result = $classes_stmt->get_result();
                while ($class = $classes_result->fetch_assoc()) {
                    $response['classes'][] = $class;
                }
                $response['debug'] = 'Classes found: ' . count($response['classes']) . ' for department_id=' . $department_id;
                if (empty($response['classes'])) {
                    $response['error'] = 'No classes available for this department.';
                }
            }
            $classes_stmt->close();
        }
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Handle AJAX request for subjects by department
if (isset($_GET['action']) && $_GET['action'] === 'get_subjects_by_department') {
    $department_id = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
    $response = ['subjects' => [], 'error' => '', 'debug' => ''];
    if ($department_id <= 0) {
        $response['error'] = 'Invalid department ID.';
        $response['debug'] = 'Department ID: ' . $department_id;
    } else {
        $subjects_sql = "SELECT id, subject_name FROM subjects WHERE department_id = ? ORDER BY subject_name";
        $subjects_stmt = $conn->prepare($subjects_sql);
        if (!$subjects_stmt) {
            $response['error'] = 'Database query preparation failed.';
            $response['debug'] = 'SQL Error: ' . $conn->error;
            error_log("Subjects query error: " . $conn->error);
        } else {
            $subjects_stmt->bind_param("i", $department_id);
            if (!$subjects_stmt->execute()) {
                $response['error'] = 'Query execution failed.';
                $response['debug'] = 'SQL Error: ' . $subjects_stmt->error;
                error_log("Subjects query execution error: " . $subjects_stmt->error);
            } else {
                $subjects_result = $subjects_stmt->get_result();
                while ($subject = $subjects_result->fetch_assoc()) {
                    $response['subjects'][] = $subject;
                }
                $response['debug'] = 'Subjects found: ' . count($response['subjects']) . ' for department_id=' . $department_id;
                if (empty($response['subjects'])) {
                    $response['error'] = 'No subjects available for this department.';
                }
            }
            $subjects_stmt->close();
        }
    }
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_resource'])) {
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $resource_type = $_POST['resource_type'] ?? '';
    $url = $_POST['url'] ?? '';
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $class_id = (int)($_POST['class_id'] ?? 0);
    $department_id = (int)($_POST['department_id'] ?? 0);
    $term_id = (int)($_POST['term_id'] ?? 0);
    $academic_year = $_POST['academic_year'] ?? '';
    $access_level = $_POST['access_level'] ?? 'public';
    $tags = $_POST['tags'] ?? '';
    $ghanaian_content = isset($_POST['ghanaian_content']) ? 1 : 0;
    $is_approved = ($_SESSION['user_role'] === 'admin') ? 1 : 0;
    $approved_by = ($_SESSION['user_role'] === 'admin') ? $teacher_id : null;
    $approved_at = ($_SESSION['user_role'] === 'admin') ? date('Y-m-d H:i:s') : null;
    $created_by = $teacher_id;
    $created_at = date('Y-m-d H:i:s');

    // Validate inputs
    if (!$title || !$resource_type || !$subject_id || !$class_id || !$department_id || !$term_id || !$academic_year) {
        $error_msg = "All required fields must be filled.";
    } elseif ($department_id && ($subject_id || $class_id)) {
        // Validate department, subject, and class
        $dept_sql = "SELECT id FROM departments WHERE id = ?";
        $dept_stmt = $conn->prepare($dept_sql);
        $dept_stmt->bind_param("i", $department_id);
        $dept_stmt->execute();
        $dept_result = $dept_stmt->get_result();
        $dept_exists = $dept_result->num_rows > 0;
        $dept_stmt->close();

        $class_sql = "SELECT department_id FROM classes WHERE id = ?";
        $class_stmt = $conn->prepare($class_sql);
        $class_stmt->bind_param("i", $class_id);
        $class_stmt->execute();
        $class_result = $class_stmt->get_result();
        $class = $class_result->fetch_assoc();
        $class_department_id = $class ? $class['department_id'] : null;
        $class_stmt->close();

        $subject_sql = "SELECT department_id FROM subjects WHERE id = ?";
        $subject_stmt = $conn->prepare($subject_sql);
        $subject_stmt->bind_param("i", $subject_id);
        $subject_stmt->execute();
        $subject_result = $subject_stmt->get_result();
        $subject = $subject_result->fetch_assoc();
        $subject_department_id = $subject ? $subject['department_id'] : null;
        $subject_stmt->close();

        $term_sql = "SELECT id FROM academic_calendar WHERE id = ?";
        $term_stmt = $conn->prepare($term_sql);
        $term_stmt->bind_param("i", $term_id);
        $term_stmt->execute();
        $term_result = $term_stmt->get_result();
        $term_exists = $term_result->num_rows > 0;
        $term_stmt->close();

        if (!$dept_exists) {
            $error_msg = "Selected department does not exist.";
        } elseif ($class_department_id !== $department_id || $subject_department_id !== $department_id) {
            $error_msg = "Class or subject does not belong to the selected department.";
        } elseif (!$term_exists) {
            $error_msg = "Selected term does not exist.";
        } else {
            // Handle file upload
            $file_path = '';
            if (isset($_FILES['resource_file']) && $_FILES['resource_file']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/resources/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                $file_name = time() . '_' . basename($_FILES['resource_file']['name']);
                $file_path = '/uploads/resources/' . $file_name;
                if (!move_uploaded_file($_FILES['resource_file']['tmp_name'], $upload_dir . $file_name)) {
                    $error_msg = "Failed to upload file.";
                    $file_path = '';
                }
            }

            if (!$error_msg) {
                $conn->begin_transaction();
                try {
                    $insert_sql = "INSERT INTO learning_resources (
                        title, description, resource_type, file_path, url, subject_id, class_id, 
                        teacher_id, term_id, academic_year, access_level, tags, is_approved, 
                        approved_by, approved_at, created_by, created_at, updated_at, 
                        views_count, downloads_count, rating, rating_count, ghanaian_content
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, 0, ?)";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param(
                        "sssssiisssssiissssi",
                        $title, $description, $resource_type, $file_path, $url, $subject_id, $class_id, 
                        $teacher_id, $term_id, $academic_year, $access_level, $tags, $is_approved, 
                        $approved_by, $approved_at, $created_by, $created_at, $created_at, $ghanaian_content
                    );
                    $insert_stmt->execute();
                    logActivity($conn, $teacher_id, "Added Resource", 
                               "Title: $title, Subject ID: $subject_id, Class ID: $class_id, Term ID: $term_id, Department ID: $department_id");
                    $insert_stmt->close();
                    $conn->commit();
                    $success_msg = "Resource created successfully.";
                    header("Location: /teacher/manage_resources.php?department_id=$department_filter");
                    exit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_msg = "Error creating resource: " . $e->getMessage();
                    error_log("Resource creation error: " . $e->getMessage());
                }
            }
        }
    }
}

include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Create learning resources for QueensPa School.">
    <meta name="keywords" content="QueensPa School, learning resources, teacher portal">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Create Resources">
    <meta property="og:description" content="Teacher interface for creating learning resources in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/create_new_resources.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Create Resources">
    <meta name="twitter:description" content="Teacher interface for creating learning resources in QueensPa School.">
    <title>Create Learning Resource - QueensPa School Teacher Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --primary-hover: #e03e00;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 6px 20px rgba(0, 0, 0, 0.15);
        }
        body {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            color: var(--text-color);
        }
        .container {
            max-width: 1400px;
        }
        .card {
            border: none;
            border-radius: 16px;
            background: #fff;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), #ff6b00);
            border-radius: 16px 16px 0 0;
            padding: 1.5rem;
        }
        .btn-primary, .btn-outline-primary, .btn-success, .btn-danger {
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover) !important;
            border-color: var(--primary-hover) !important;
            animation: animate__pulse 0.5s;
        }
        .btn-success {
            background-color: #28a745 !important;
            border-color: #28a745 !important;
        }
        .btn-success:hover {
            background-color: #218838 !important;
            border-color: #218838 !important;
        }
        .btn-danger {
            background-color: #dc3545 !important;
            border-color: #dc3545 !important;
        }
        .btn-danger:hover {
            background-color: #c82333 !important;
            border-color: #c82333 !important;
        }
        .btn-sm {
            padding: 0.5rem 0.75rem;
            font-size: 0.85rem;
        }
        .form-control, .form-select {
            border-radius: 10px;
            border: 1px solid #ced4da;
            padding: 0.75rem;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 69, 0, 0.2);
            outline: none;
        }
        .alert {
            border-radius: 10px;
            padding: 1rem;
            display: flex;
            align-items: center;
            transition: opacity 0.3s ease;
        }
        .table {
            border-radius: 10px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        .loading-spinner {
            display: none;
            color: var(--primary-color);
            margin-left: 10px;
        }
        .invalid-feedback {
            display: none;
        }
        .was-validated .form-control:invalid ~ .invalid-feedback,
        .was-validated .form-select:invalid ~ .invalid-feedback {
            display: block;
        }
        .resource-form {
            border: 1px solid #ced4da;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            background: #f8f9fa;
        }
        @media (max-width: 991.98px) {
            .container {
                padding: 15px;
            }
            .btn {
                font-size: 0.9rem;
                padding: 0.6rem 1rem;
            }
            .table {
                font-size: 0.85rem;
            }
        }
        @media (max-width: 767.98px) {
            .container {
                padding: 15px;
            }
            .btn {
                font-size: 0.9rem;
                padding: 0.6rem 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-5">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="mb-0"><i class="fas fa-book-open me-2"></i>Create Learning Resource</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (empty($terms)): ?>
                        <div class="alert alert-warning alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i>No terms found in the academic calendar. Please contact an administrator.
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Resource Form -->
                    <form method="POST" enctype="multipart/form-data" id="resourceForm" class="animate__animated animate__fadeInUp resource-form" onsubmit="return validateResourceForm()" novalidate>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="title" class="form-label">Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="title" name="title" required data-bs-toggle="tooltip" title="Enter resource title">
                                <div class="invalid-feedback">Please enter a title.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="resource_type" class="form-label">Resource Type <span class="text-danger">*</span></label>
                                <select class="form-select custom-select" id="resource_type" name="resource_type" required data-bs-toggle="tooltip" title="Select resource type">
                                    <option value="">Select Type</option>
                                    <option value="document">Document</option>
                                    <option value="video">Video</option>
                                    <option value="link">Link</option>
                                    <option value="quiz">Quiz</option>
                                </select>
                                <div class="invalid-feedback">Please select a resource type.</div>
                            </div>
                            <div class="col-md-12">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="4" data-bs-toggle="tooltip" title="Enter resource description"></textarea>
                            </div>
                            <div class="col-md-6">
                                <label for="resource_file" class="form-label">File Upload (Optional)</label>
                                <input type="file" class="form-control" id="resource_file" name="resource_file" data-bs-toggle="tooltip" title="Upload a resource file">
                            </div>
                            <div class="col-md-6">
                                <label for="url" class="form-label">URL (Optional)</label>
                                <input type="url" class="form-control" id="url" name="url" data-bs-toggle="tooltip" title="Enter a resource URL">
                            </div>
                            <div class="col-md-4">
                                <label for="department_id" class="form-label">Department <span class="text-danger">*</span></label>
                                <select class="form-select custom-select department-select" id="department_id" name="department_id" required data-bs-toggle="tooltip" title="Select the department">
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>" 
                                                <?php echo $dept['id'] == $teacher_department_id ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select a department.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="class_id" class="form-label">Class <span class="text-danger">*</span></label>
                                <select class="form-select custom-select class-select" id="class_id" name="class_id" required data-bs-toggle="tooltip" title="Select the class">
                                    <option value="">Select Class</option>
                                </select>
                                <span class="loading-spinner class-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                <div class="invalid-feedback">Please select a class.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="subject_id" class="form-label">Subject <span class="text-danger">*</span></label>
                                <select class="form-select custom-select subject-select" id="subject_id" name="subject_id" required data-bs-toggle="tooltip" title="Select the subject">
                                    <option value="">Select Subject</option>
                                </select>
                                <span class="loading-spinner subject-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                <div class="invalid-feedback">Please select a subject.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="term_id" class="form-label">Term <span class="text-danger">*</span></label>
                                <select class="form-select custom-select" id="term_id" name="term_id" required data-bs-toggle="tooltip" title="Select the term">
                                    <option value="">Select Term</option>
                                    <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo $term['id']; ?>">
                                            <?php echo htmlspecialchars($term['term_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select a term.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="academic_year" class="form-label">Academic Year <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="academic_year" name="academic_year" required data-bs-toggle="tooltip" title="Enter academic year (e.g., 2024-2025)" placeholder="e.g., 2024-2025">
                                <div class="invalid-feedback">Please enter the academic year.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="access_level" class="form-label">Access Level</label>
                                <select class="form-select custom-select" id="access_level" name="access_level" data-bs-toggle="tooltip" title="Select access level">
                                    <option value="public">Public</option>
                                    <option value="private">Private</option>
                                    <option value="class">Class Only</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="tags" class="form-label">Tags (comma-separated)</label>
                                <input type="text" class="form-control" id="tags" name="tags" data-bs-toggle="tooltip" title="Enter tags (e.g., math, algebra, lesson)" placeholder="e.g., math, algebra, lesson">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">
                                    <input type="checkbox" id="ghanaian_content" name="ghanaian_content" value="1" data-bs-toggle="tooltip" title="Check if resource contains Ghanaian content"> Ghanaian Content
                                </label>
                            </div>
                        </div>
                        <div class="mt-4">
                            <button type="submit" name="submit_resource" class="btn btn-primary" data-bs-toggle="tooltip" title="Create resource">
                                <i class="fas fa-check me-2"></i>Create Resource
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </main>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; mysqli_close($conn); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            // Initialize tooltips
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));

            // Auto-dismiss alerts after 5 seconds
            setTimeout(() => {
                document.querySelectorAll('.alert').forEach(alert => {
                    bootstrap.Alert.getOrCreateInstance(alert).close();
                });
            }, 5000);

            // Trigger department change on page load
            const departmentSelect = $('#department_id');
            if (departmentSelect.val()) {
                departmentSelect.trigger('change');
            }
        });

        function validateResourceForm() {
            const form = document.getElementById('resourceForm');
            let isValid = true;
            form.querySelectorAll('select, input:not([type="checkbox"]), textarea').forEach(input => {
                if (!input.checkValidity()) {
                    isValid = false;
                    form.classList.add('was-validated');
                }
            });
            return isValid && confirm('Are you sure you want to create this resource?');
        }

        $(document).ready(function() {
            // Cache for AJAX results
            const ajaxCache = {};

            // Handle department change
            $('#department_id').on('change', function(e) {
                e.preventDefault();
                const departmentId = $(this).val();
                const classSelect = $('#class_id');
                const subjectSelect = $('#subject_id');
                const classSpinner = classSelect.siblings('.class-spinner');
                const subjectSpinner = subjectSelect.siblings('.subject-spinner');

                classSelect.empty().append('<option value="">Select Class</option>');
                subjectSelect.empty().append('<option value="">Select Subject</option>');

                if (!departmentId) {
                    console.log('No department selected');
                    return;
                }

                console.log(`Fetching data for department ${departmentId}`);

                // Load classes
                const classesCacheKey = `classes_${departmentId}`;
                if (ajaxCache[classesCacheKey]) {
                    populateSelect(classSelect, ajaxCache[classesCacheKey], 'id', 'class_name');
                    classSpinner.hide();
                } else {
                    classSpinner.show();
                    $.ajax({
                        url: '<?php echo $_SERVER['PHP_SELF']; ?>?action=get_classes_by_department',
                        type: 'GET',
                        data: { department_id: departmentId },
                        dataType: 'json',
                        success: function(response) {
                            classSpinner.hide();
                            console.log('Classes response:', response);
                            if (response.error) {
                                alert(`Error fetching classes: ${response.error} (${response.debug})`);
                                classSelect.append('<option value="">No classes available</option>');
                            } else if (response.classes.length === 0) {
                                alert(`No classes found for this department. (${response.debug})`);
                                classSelect.append('<option value="">No classes available</option>');
                            } else {
                                ajaxCache[classesCacheKey] = response.classes;
                                populateSelect(classSelect, response.classes, 'id', 'class_name');
                            }
                        },
                        error: function(xhr, status, error) {
                            classSpinner.hide();
                            console.error('Classes AJAX error:', status, error, xhr.responseText);
                            alert(`Failed to fetch classes: ${error}. Check console for details.`);
                            classSelect.append('<option value="">Select Class</option>');
                        }
                    });
                }

                // Load subjects
                const subjectsCacheKey = `subjects_${departmentId}`;
                if (ajaxCache[subjectsCacheKey]) {
                    populateSelect(subjectSelect, ajaxCache[subjectsCacheKey], 'id', 'subject_name');
                    subjectSpinner.hide();
                } else {
                    subjectSpinner.show();
                    $.ajax({
                        url: '<?php echo $_SERVER['PHP_SELF']; ?>?action=get_subjects_by_department',
                        type: 'GET',
                        data: { department_id: departmentId },
                        dataType: 'json',
                        success: function(response) {
                            subjectSpinner.hide();
                            console.log('Subjects response:', response);
                            if (response.error) {
                                alert(`Error fetching subjects: ${response.error} (${response.debug})`);
                                subjectSelect.append('<option value="">No subjects available</option>');
                            } else if (response.subjects.length === 0) {
                                alert(`No subjects found for this department. (${response.debug})`);
                                subjectSelect.append('<option value="">No subjects available</option>');
                            } else {
                                ajaxCache[subjectsCacheKey] = response.subjects;
                                populateSelect(subjectSelect, response.subjects, 'id', 'subject_name');
                            }
                        },
                        error: function(xhr, status, error) {
                            subjectSpinner.hide();
                            console.error('Subjects AJAX error:', status, error, xhr.responseText);
                            alert(`Failed to fetch subjects: ${error}. Check console for details.`);
                            subjectSelect.append('<option value="">Select Subject</option>');
                        }
                    });
                }
            });

            // Helper function to populate select options
            function populateSelect(select, items, valueKey, textKey) {
                $.each(items, function(index, item) {
                    select.append(`<option value="${item[valueKey]}">${item[textKey]}</option>`);
                });
            }
        });
    </script>
</body>
</html>