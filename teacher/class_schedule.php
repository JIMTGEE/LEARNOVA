<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Restrict to teachers
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
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
$department_filter = isset($_GET['department_id']) ? (int)$_GET['department_id'] : 0;
$success_msg = $error_msg = "";

// Days of the week (Monday to Friday)
$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];

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

// Handle submit schedules (add new schedules)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_schedules'])) {
    $schedules = $_POST['schedules'] ?? [];
    $errors = [];

    if (empty($schedules)) {
        $error_msg = "No schedules provided.";
    } else {
        $conn->begin_transaction();
        try {
            foreach ($schedules as $index => $schedule) {
                $department_id = (int)($schedule['department_id'] ?? 0);
                $class_id = (int)($schedule['class_id'] ?? 0);
                $subject_id = (int)($schedule['subject_id'] ?? 0);
                $term_id = (int)($schedule['term_id'] ?? 0);
                $day_of_week = $schedule['day_of_week'] ?? '';
                $start_time = $schedule['start_time'] ?? '';
                $end_time = $schedule['end_time'] ?? '';

                // Validate inputs
                if (!$department_id || !$class_id || !$subject_id || !$term_id || 
                    !in_array($day_of_week, $days_of_week) || !$start_time || !$end_time) {
                    $errors[] = "Schedule " . ($index + 1) . ": All fields are required.";
                    continue;
                }
                if (strtotime($end_time) <= strtotime($start_time)) {
                    $errors[] = "Schedule " . ($index + 1) . ": End time must be after start time.";
                    continue;
                }

                // Validate department, class, subject, and term
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
                    $errors[] = "Schedule " . ($index + 1) . ": Selected department does not exist.";
                    continue;
                }
                if ($class_department_id !== $department_id || $subject_department_id !== $department_id) {
                    $errors[] = "Schedule " . ($index + 1) . ": Class or subject does not belong to the selected department.";
                    continue;
                }
                if (!$term_exists) {
                    $errors[] = "Schedule " . ($index + 1) . ": Selected term does not exist.";
                    continue;
                }

                // Check for overlapping schedules
                $overlap_sql = "SELECT id FROM class_schedules 
                                WHERE teacher_id = ? AND class_id = ? AND term_id = ? 
                                AND day_of_week = ? 
                                AND ((start_time < ? AND end_time > ?) 
                                     OR (start_time < ? AND end_time > ?)
                                     OR (start_time >= ? AND end_time <= ?))";
                $overlap_stmt = $conn->prepare($overlap_sql);
                $overlap_stmt->bind_param("iiisssssss", $teacher_id, $class_id, $term_id, $day_of_week, 
                                         $end_time, $start_time, $end_time, $start_time, $start_time, $end_time);
                $overlap_stmt->execute();
                $overlap_result = $overlap_stmt->get_result();
                if ($overlap_result->num_rows > 0) {
                    $errors[] = "Schedule " . ($index + 1) . ": Conflicts with an existing schedule.";
                    continue;
                }
                $overlap_stmt->close();

                // Insert schedule
                $insert_sql = "INSERT INTO class_schedules (class_id, teacher_id, subject_id, term_id, day_of_week, start_time, end_time, department_id)
                               VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("iiiisssi", $class_id, $teacher_id, $subject_id, $term_id, $day_of_week, $start_time, $end_time, $department_id);
                if (!$insert_stmt->execute()) {
                    $errors[] = "Schedule " . ($index + 1) . ": Failed to insert schedule.";
                    error_log("Insert schedule error: " . $insert_stmt->error);
                    continue;
                }
                logActivity($conn, $teacher_id, "Added Schedule", 
                           "Teacher ID: $teacher_id, Class ID: $class_id, Subject ID: $subject_id, Term ID: $term_id, Department ID: $department_id");
                $insert_stmt->close();
            }

            if (!empty($errors)) {
                $error_msg = implode("<br>", $errors);
                $conn->rollback();
            } else {
                $success_msg = "All schedules added successfully.";
                $conn->commit();
                header("Location: /teacher/class_schedule.php?department_id=$department_filter");
                exit();
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error adding schedules: " . $e->getMessage();
            error_log("Schedules add error: " . $e->getMessage());
        }
    }
}

// Handle delete schedule
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_schedule'])) {
    $schedule_id = (int)($_POST['schedule_id'] ?? 0);
    if ($schedule_id <= 0) {
        $error_msg = "Invalid schedule ID.";
    } else {
        $conn->begin_transaction();
        try {
            $delete_sql = "DELETE FROM class_schedules WHERE id = ? AND teacher_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("ii", $schedule_id, $teacher_id);
            $delete_stmt->execute();
            if ($delete_stmt->affected_rows > 0) {
                logActivity($conn, $teacher_id, "Deleted Schedule", "Schedule ID: $schedule_id, Teacher ID: $teacher_id");
                $success_msg = "Schedule deleted successfully.";
                $conn->commit();
                header("Location: /teacher/class_schedule.php?department_id=$department_filter");
                exit();
            } else {
                $error_msg = "Schedule not found or you do not have permission to delete it.";
                $conn->rollback();
            }
            $delete_stmt->close();
        } catch (Exception $e) {
            $conn->rollback();
            $error_msg = "Error deleting schedule: " . $e->getMessage();
            error_log("Schedule delete error: " . $e->getMessage());
        }
    }
}

// Fetch departments (for filter and form)
$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_stmt = $conn->prepare($departments_sql);
$departments_stmt->execute();
$departments_result = $departments_stmt->get_result();
$departments = $departments_result->fetch_all(MYSQLI_ASSOC);
$departments_stmt->close();

// Fetch schedules for the teacher
$schedules_sql = "SELECT cs.id, cs.day_of_week, cs.start_time, cs.end_time, 
                        c.class_name, u.name AS teacher_name, s.subject_name, 
                        cs.term_id, d.name AS department_name, cs.class_id, 
                        cs.subject_id, cs.department_id
                 FROM class_schedules cs
                 JOIN classes c ON cs.class_id = c.id
                 JOIN users u ON cs.teacher_id = u.id
                 JOIN subjects s ON cs.subject_id = s.id
                 LEFT JOIN departments d ON cs.department_id = d.id
                 WHERE cs.teacher_id = ?";
if ($department_filter > 0) {
    $schedules_sql .= " AND cs.department_id = ?";
}
$schedules_sql .= " ORDER BY cs.day_of_week, cs.start_time";
if ($department_filter > 0) {
    $schedules_stmt = $conn->prepare($schedules_sql);
    $schedules_stmt->bind_param("ii", $teacher_id, $department_filter);
} else {
    $schedules_stmt = $conn->prepare($schedules_sql);
    $schedules_stmt->bind_param("i", $teacher_id);
}
$schedules_stmt->execute();
$schedules_result = $schedules_stmt->get_result();
$schedules = $schedules_result->fetch_all(MYSQLI_ASSOC);
$schedules_stmt->close();

// Calculate max periods for timetable grid
$max_periods = 0;
$schedules_by_day = [];
foreach ($schedules as $schedule) {
    $day = $schedule['day_of_week'];
    if (!isset($schedules_by_day[$day])) {
        $schedules_by_day[$day] = [];
    }
    $schedules_by_day[$day][] = $schedule;
    $max_periods = max($max_periods, count($schedules_by_day[$day]));
}
foreach ($schedules_by_day as $day => &$day_schedules) {
    usort($day_schedules, function($a, $b) {
        return strcmp($a['start_time'], $b['start_time']);
    });
}

// Create term lookup array for display
$term_lookup = [];
foreach ($terms as $term) {
    $term_lookup[$term['id']] = $term['term_name'];
}

include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Teacher schedule management for QueensPa School.">
    <meta name="keywords" content="QueensPa School, teacher schedules, timetable">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Teacher Schedules">
    <meta property="og:description" content="Teacher interface for managing class schedules in QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/class_schedule.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Teacher Schedules">
    <meta name="twitter:description" content="Teacher interface for managing class schedules in QueensPa School.">
    <title>Manage Schedules - QueensPa School Teacher Portal</title>
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
        .timetable {
            display: grid;
            grid-template-columns: 120px repeat(<?php echo $max_periods; ?>, 1fr);
            gap: 2px;
            background: #e9ecef;
            border-radius: 10px;
            padding: 5px;
        }
        .timetable .time-slot {
            background: #fff;
            border: 1px solid #ced4da;
            padding: 10px;
            min-height: 100px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .timetable .time-slot.header {
            background: linear-gradient(135deg, var(--primary-color), #ff6b00);
            color: #fff;
            font-weight: bold;
            text-align: center;
            padding: 10px;
        }
        .timetable .time-slot.period {
            background: #f8f9fa;
            font-weight: 500;
            text-align: center;
            padding: 10px;
        }
        .timetable .schedule-item {
            background: #fff3e0;
            border: 1px solid var(--primary-color);
            border-radius: 5px;
            padding: 5px;
            margin-bottom: 5px;
            font-size: 0.85rem;
            line-height: 1.4;
        }
        .schedule-item .action-buttons {
            margin-top: 5px;
            display: flex;
            gap: 5px;
        }
        .modal-content {
            border-radius: 16px;
            box-shadow: var(--shadow);
        }
        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), #ff6b00);
            color: #fff;
            border-radius: 16px 16px 0 0;
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
        .schedule-form {
            border: 1px solid #ced4da;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            background: #f8f9fa;
        }
        .schedule-form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        .remove-schedule {
            color: #dc3545;
            cursor: pointer;
            font-size: 1.2rem;
        }
        .remove-schedule:hover {
            color: #c82333;
        }
        @media (max-width: 991.98px) {
            .timetable {
                grid-template-columns: 100px repeat(<?php echo $max_periods; ?>, 1fr);
                font-size: 0.9rem;
            }
            .timetable .schedule-item {
                font-size: 0.75rem;
            }
            .schedule-item .action-buttons .btn-sm {
                padding: 0.4rem 0.6rem;
                font-size: 0.75rem;
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
            .timetable {
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <div class="content" id="content">
        <main class="container my-5">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Manage Class Schedules</h3>
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

                    <!-- Department Filter -->
                    <form class="mb-4 animate__animated animate__fadeInUp" method="GET" id="filterForm">
                        <div class="row g-3 align-items-center">
                            <div class="col-auto">
                                <label for="department_filter" class="form-label">Filter by Department</label>
                            </div>
                            <div class="col-auto">
                                <select class="form-select custom-select" id="department_filter" name="department_id" data-bs-toggle="tooltip" title="Filter schedules by department">
                                    <option value="0" <?php echo $department_filter == 0 ? 'selected' : ''; ?>>All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>" 
                                                <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary" data-bs-toggle="tooltip" title="Apply filter">
                                    <i class="fas fa-filter me-2"></i>Filter
                                </button>
                            </div>
                        </div>
                    </form>

                    <!-- Add Schedules Form -->
                    <h5 class="mt-4">Add New Schedules</h5>
                    <form method="POST" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" id="schedulesForm" class="animate__animated animate__fadeInUp mb-4" onsubmit="return validateSchedulesForm(event)" novalidate>
                        <div id="scheduleForms">
                            <div class="schedule-form" data-index="1">
                                <div class="schedule-form-header">
                                    <h6>Schedule 1</h6>
                                    <i class="fas fa-times remove-schedule" title="Remove Schedule" style="display: none;"></i>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label for="department_id_1" class="form-label">Department <span class="text-danger">*</span></label>
                                        <select class="form-select custom-select department-select" id="department_id_1" name="schedules[1][department_id]" required data-bs-toggle="tooltip" title="Select the department">
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
                                        <label for="class_id_1" class="form-label">Class <span class="text-danger">*</span></label>
                                        <select class="form-select custom-select class-select" id="class_id_1" name="schedules[1][class_id]" required data-bs-toggle="tooltip" title="Select the class">
                                            <option value="">Select Class</option>
                                        </select>
                                        <span class="loading-spinner class-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                        <div class="invalid-feedback">Please select a class.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="subject_id_1" class="form-label">Subject <span class="text-danger">*</span></label>
                                        <select class="form-select custom-select subject-select" id="subject_id_1" name="schedules[1][subject_id]" required data-bs-toggle="tooltip" title="Select the subject">
                                            <option value="">Select Subject</option>
                                        </select>
                                        <span class="loading-spinner subject-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                        <div class="invalid-feedback">Please select a subject.</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label for="term_id_1" class="form-label">Term <span class="text-danger">*</span></label>
                                        <select class="form-select custom-select" id="term_id_1" name="schedules[1][term_id]" required data-bs-toggle="tooltip" title="Select the term">
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
                                        <label for="day_of_week_1" class="form-label">Day of Week <span class="text-danger">*</span></label>
                                        <select class="form-select custom-select" id="day_of_week_1" name="schedules[1][day_of_week]" required data-bs-toggle="tooltip" title="Select the day">
                                            <option value="">Select Day</option>
                                            <?php foreach ($days_of_week as $day): ?>
                                                <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="invalid-feedback">Please select a day.</div>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="start_time_1" class="form-label">Start Time <span class="text-danger">*</span></label>
                                        <input type="time" class="form-control" id="start_time_1" name="schedules[1][start_time]" required data-bs-toggle="tooltip" title="Select start time">
                                        <div class="invalid-feedback">Please select a start time.</div>
                                    </div>
                                    <div class="col-md-2">
                                        <label for="end_time_1" class="form-label">End Time <span class="text-danger">*</span></label>
                                        <input type="time" class="form-control" id="end_time_1" name="schedules[1][end_time]" required data-bs-toggle="tooltip" title="Select end time">
                                        <div class="invalid-feedback">Please select an end time.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="mt-4">
                            <button type="button" id="addAnotherSchedule" class="btn btn-success me-2" data-bs-toggle="tooltip" title="Add another schedule form">
                                <i class="fas fa-plus me-2"></i>Add Another Schedule
                            </button>
                            <button type="submit" name="submit_schedules" class="btn btn-primary" id="submitSchedulesBtn" data-bs-toggle="tooltip" title="Save all schedules">
                                <i class="fas fa-check me-2"></i>Submit Schedules
                            </button>
                        </div>
                    </form>

                    <!-- Schedules Display (Timetable Only) -->
                    <h5 class="mt-4">My Schedules</h5>
                    <div class="timetable animate__animated animate__fadeInUp">
                        <!-- Header row: Periods -->
                        <div class="time-slot header"></div>
                        <?php for ($period = 1; $period <= $max_periods; $period++): ?>
                            <div class="time-slot header">Period <?php echo $period; ?></div>
                        <?php endfor; ?>
                        <!-- Rows: Days with schedules -->
                        <?php foreach ($days_of_week as $day): ?>
                            <div class="time-slot period"><?php echo $day; ?></div>
                            <?php for ($period = 1; $period <= $max_periods; $period++): ?>
                                <div class="time-slot" data-day="<?php echo $day; ?>" data-period="<?php echo $period; ?>">
                                    <?php
                                    // Display schedule for this day and period
                                    if (isset($schedules_by_day[$day]) && isset($schedules_by_day[$day][$period - 1])) {
                                        $schedule = $schedules_by_day[$day][$period - 1];
                                        ?>
                                        <div class="schedule-item">
                                            <strong><?php echo htmlspecialchars($schedule['class_name']); ?></strong><br>
                                            <?php echo htmlspecialchars($schedule['subject_name']); ?><br>
                                            <?php echo htmlspecialchars($schedule['start_time'] . ' - ' . $schedule['end_time']); ?>
                                            <div class="action-buttons">
                                                <a href="/teacher/edit_schedule.php?schedule_id=<?php echo $schedule['id']; ?>" class="btn btn-primary btn-sm" title="Edit Schedule">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this schedule?');">
                                                    <input type="hidden" name="schedule_id" value="<?php echo $schedule['id']; ?>">
                                                    <button type="submit" name="delete_schedule" class="btn btn-danger btn-sm" title="Delete Schedule">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                        <?php
                                    }
                                    ?>
                                </div>
                            <?php endfor; ?>
                        <?php endforeach; ?>
                    </div>
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

            // Trigger department change for first schedule form
            const firstDepartmentSelect = $('#department_id_1');
            if (firstDepartmentSelect.val()) {
                firstDepartmentSelect.trigger('change');
            }
        });

        function validateSchedulesForm(event) {
            event.preventDefault();
            console.log('Validating schedules form...');
            const forms = document.querySelectorAll('.schedule-form');
            let isValid = true;
            let errorMessages = [];

            forms.forEach((form, index) => {
                const formIndex = form.getAttribute('data-index');
                const formElement = form.querySelectorAll('select, input');
                console.log(`Validating Schedule ${formIndex}`);

                // Check HTML5 validation
                formElement.forEach(input => {
                    if (!input.checkValidity()) {
                        isValid = false;
                        form.classList.add('was-validated');
                        errorMessages.push(`Schedule ${formIndex}: ${input.closest('.form-label')?.textContent || 'Field'} is required.`);
                        console.log(`Validation failed for ${input.id}: ${input.validationMessage}`);
                    }
                });

                // Validate time constraints
                const startTime = document.getElementById(`start_time_${formIndex}`).value;
                const endTime = document.getElementById(`end_time_${formIndex}`).value;
                if (startTime && endTime && new Date(`1970-01-01T${endTime}`) <= new Date(`1970-01-01T${startTime}`)) {
                    isValid = false;
                    errorMessages.push(`Schedule ${formIndex}: End time must be after start time.`);
                    console.log(`Time validation failed for Schedule ${formIndex}: Start=${startTime}, End=${endTime}`);
                    document.getElementById(`end_time_${formIndex}`).focus();
                }
            });

            if (!isValid) {
                alert('Please correct the following errors:\n' + errorMessages.join('\n'));
                $('#submitSchedulesBtn').prop('disabled', false);
                console.log('Form submission prevented due to validation errors.');
                return false;
            }

            if (confirm('Are you sure you want to submit all schedules?')) {
                console.log('Form validated, submitting...');
                submitSchedulesForm();
            } else {
                console.log('Form submission cancelled by user.');
                $('#submitSchedulesBtn').prop('disabled', false);
            }
            return false;
        }

        function submitSchedulesForm() {
            $('#submitSchedulesBtn').prop('disabled', true).text('Submitting...');
            $.ajax({
                url: $('#schedulesForm').attr('action') || window.location.href,
                type: 'POST',
                data: $('#schedulesForm').serialize() + '&submit_schedules=1',
                success: function(response) {
                    console.log('Form submission successful:', response);
                    window.location.href = window.location.pathname + '?department_id=<?php echo $department_filter; ?>';
                },
                error: function(xhr, status, error) {
                    console.error('Form submission error:', status, error, xhr.responseText);
                    alert('Failed to submit schedules: ' + (xhr.responseText || error) + '. Please try again or contact support.');
                    $('#submitSchedulesBtn').prop('disabled', false).text('Submit Schedules');
                }
            });
        }

        $(document).ready(function() {
            let scheduleIndex = 1;

            // Cache for AJAX results
            const ajaxCache = {};

            // Add another schedule form
            $('#addAnotherSchedule').click(function() {
                scheduleIndex++;
                console.log(`Adding new schedule form: Schedule ${scheduleIndex}`);
                const newForm = `
                    <div class="schedule-form animate__animated animate__fadeIn" data-index="${scheduleIndex}">
                        <div class="schedule-form-header">
                            <h6>Schedule ${scheduleIndex}</h6>
                            <i class="fas fa-times remove-schedule" title="Remove Schedule"></i>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="department_id_${scheduleIndex}" class="form-label">Department <span class="text-danger">*</span></label>
                                <select class="form-select custom-select department-select" id="department_id_${scheduleIndex}" name="schedules[${scheduleIndex}][department_id]" required data-bs-toggle="tooltip" title="Select the department">
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
                                <label for="class_id_${scheduleIndex}" class="form-label">Class <span class="text-danger">*</span></label>
                                <select class="form-select custom-select class-select" id="class_id_${scheduleIndex}" name="schedules[${scheduleIndex}][class_id]" required data-bs-toggle="tooltip" title="Select the class">
                                    <option value="">Select Class</option>
                                </select>
                                <span class="loading-spinner class-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                <div class="invalid-feedback">Please select a class.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="subject_id_${scheduleIndex}" class="form-label">Subject <span class="text-danger">*</span></label>
                                <select class="form-select custom-select subject-select" id="subject_id_${scheduleIndex}" name="schedules[${scheduleIndex}][subject_id]" required data-bs-toggle="tooltip" title="Select the subject">
                                    <option value="">Select Subject</option>
                                </select>
                                <span class="loading-spinner subject-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                <div class="invalid-feedback">Please select a subject.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="term_id_${scheduleIndex}" class="form-label">Term <span class="text-danger">*</span></label>
                                <select class="form-select custom-select" id="term_id_${scheduleIndex}" name="schedules[${scheduleIndex}][term_id]" required data-bs-toggle="tooltip" title="Select the term">
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
                                <label for="day_of_week_${scheduleIndex}" class="form-label">Day of Week <span class="text-danger">*</span></label>
                                <select class="form-select custom-select" id="day_of_week_${scheduleIndex}" name="schedules[${scheduleIndex}][day_of_week]" required data-bs-toggle="tooltip" title="Select the day">
                                    <option value="">Select Day</option>
                                    <?php foreach ($days_of_week as $day): ?>
                                        <option value="<?php echo $day; ?>"><?php echo $day; ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select a day.</div>
                            </div>
                            <div class="col-md-2">
                                <label for="start_time_${scheduleIndex}" class="form-label">Start Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="start_time_${scheduleIndex}" name="schedules[${scheduleIndex}][start_time]" required data-bs-toggle="tooltip" title="Select start time">
                                <div class="invalid-feedback">Please select a start time.</div>
                            </div>
                            <div class="col-md-2">
                                <label for="end_time_${scheduleIndex}" class="form-label">End Time <span class="text-danger">*</span></label>
                                <input type="time" class="form-control" id="end_time_${scheduleIndex}" name="schedules[${scheduleIndex}][end_time]" required data-bs-toggle="tooltip" title="Select end time">
                                <div class="invalid-feedback">Please select an end time.</div>
                            </div>
                        </div>
                    </div>`;
                $('#scheduleForms').append(newForm);
                // Initialize tooltips for new form
                $('[data-bs-toggle="tooltip"]').tooltip();
                // Trigger department change for new form
                $(`#department_id_${scheduleIndex}`).trigger('change');
            });

            // Remove schedule form
            $(document).on('click', '.remove-schedule', function() {
                if ($('.schedule-form').length > 1) {
                    console.log(`Removing schedule form: ${$(this).closest('.schedule-form').attr('data-index')}`);
                    $(this).closest('.schedule-form').remove();
                    // Update schedule indices
                    $('.schedule-form').each(function(index) {
                        const newIndex = index + 1;
                        $(this).attr('data-index', newIndex);
                        $(this).find('.schedule-form-header h6').text(`Schedule ${newIndex}`);
                        $(this).find('select, input').each(function() {
                            const oldId = $(this).attr('id');
                            const fieldName = oldId.split('_').slice(0, -1).join('_');
                            $(this).attr('id', `${fieldName}_${newIndex}`);
                            if ($(this).is('select') || $(this).is('input')) {
                                const oldName = $(this).attr('name');
                                const nameBase = oldName.match(/schedules\[\d+\]\[(\w+)\]/)[1];
                                $(this).attr('name', `schedules[${newIndex}][${nameBase}]`);
                            }
                        });
                    });
                }
            });

            // Handle department change for dynamic forms
            $(document).on('change', '.department-select', function(e) {
                e.preventDefault();
                const departmentId = $(this).val();
                const formIndex = $(this).closest('.schedule-form').attr('data-index');
                const classSelect = $(`#class_id_${formIndex}`);
                const subjectSelect = $(`#subject_id_${formIndex}`);
                const classSpinner = classSelect.siblings('.class-spinner');
                const subjectSpinner = subjectSelect.siblings('.subject-spinner');

                classSelect.empty().append('<option value="">Select Class</option>');
                subjectSelect.empty().append('<option value="">Select Subject</option>');

                if (!departmentId) {
                    console.log(`No department selected for form ${formIndex}`);
                    return;
                }

                console.log(`Fetching data for department ${departmentId} (form ${formIndex})`);

                // Load classes
                const classesCacheKey = `classes_${departmentId}`;
                if (ajaxCache[classesCacheKey]) {
                    populateSelect(classSelect, ajaxCache[classesCacheKey], 'id', 'class_name', null);
                    classSpinner.hide();
                } else {
                    classSpinner.show();
                    $.ajax({
                        url: '<?php echo $_SERVER['PHP_SELF']; ?>?action=get_classes_by_department',
                        type: 'GET',
                        data: { department_id: departmentId },
                        dataType: 'json',
                        timeout: 5000,
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
                                populateSelect(classSelect, response.classes, 'id', 'class_name', null);
                            }
                        },
                        error: function(xhr, status, error) {
                            classSpinner.hide();
                            console.error('Classes AJAX error:', status, error, xhr.responseText);
                            alert(`Failed to fetch classes: ${error}. Please try again or contact support.`);
                            classSelect.append('<option value="">Select Class</option>');
                        }
                    });
                }

                // Load subjects
                const subjectsCacheKey = `subjects_${departmentId}`;
                if (ajaxCache[subjectsCacheKey]) {
                    populateSelect(subjectSelect, ajaxCache[subjectsCacheKey], 'id', 'subject_name', null);
                    subjectSpinner.hide();
                } else {
                    subjectSpinner.show();
                    $.ajax({
                        url: '<?php echo $_SERVER['PHP_SELF']; ?>?action=get_subjects_by_department',
                        type: 'GET',
                        data: { department_id: departmentId },
                        dataType: 'json',
                        timeout: 5000,
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
                                populateSelect(subjectSelect, response.subjects, 'id', 'subject_name', null);
                            }
                        },
                        error: function(xhr, status, error) {
                            subjectSpinner.hide();
                            console.error('Subjects AJAX error:', status, error, xhr.responseText);
                            alert(`Failed to fetch subjects: ${error}. Please try again or contact support.`);
                            subjectSelect.append('<option value="">Select Subject</option>');
                        }
                    });
                }
            });

            // Helper function to populate select options
            function populateSelect(select, items, valueKey, textKey, selectedValue) {
                $.each(items, function(index, item) {
                    select.append(
                        `<option value="${item[valueKey]}" ${item[valueKey] == selectedValue ? 'selected' : ''}>${item[textKey]}</option>`
                    );
                });
            }
        });
    </script>
</body>
</html>