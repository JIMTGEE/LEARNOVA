<?php
ob_start();
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and logging function
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error(), 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    die("System error. Please contact support.");
}

// Generate or validate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
    $stmt->bind_param("is", $_SESSION['user_id'], $_SESSION['csrf_token']);
    if (!$stmt->execute()) {
        error_log("CSRF token insertion failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    }
    $stmt->close();
} else {
    $stmt = $conn->prepare("SELECT expires_at FROM csrf_tokens WHERE token = ? AND user_id = ?");
    $stmt->bind_param("si", $_SESSION['csrf_token'], $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0 || strtotime($result->fetch_assoc()['expires_at']) < time()) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
        $stmt->bind_param("is", $_SESSION['user_id'], $_SESSION['csrf_token']);
        if (!$stmt->execute()) {
            error_log("CSRF token insertion failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
        }
    }
    $stmt->close();
}

// Initialize variables
$errors = [];
$success_msg = '';
$parent_id = '';
$student_ids = [];

// Fetch parents
$parents = [];
$stmt = $conn->prepare("SELECT id, name FROM users WHERE role = 'parent' AND status = 'active' ORDER BY name ASC");
if (!$stmt->execute()) {
    error_log("Parent query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    $errors[] = "Failed to fetch parents due to a system error.";
} else {
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        error_log("No active parents found in query.", 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
        $errors[] = "No active parents found.";
    } else {
        while ($row = $result->fetch_assoc()) {
            $parents[] = $row;
        }
    }
    $stmt->close();
}

// Fetch students grouped by class
$students_by_class = [];
$stmt = $conn->prepare("
    SELECT u.id, u.name AS student_name, c.id AS class_id, c.class_name 
    FROM users u 
    LEFT JOIN student_classes sc ON u.id = sc.user_id 
    LEFT JOIN classes c ON sc.class_id = c.id 
    WHERE u.role = 'student' AND u.status = 'active' 
    ORDER BY c.class_name ASC, u.name ASC
");
if (!$stmt) {
    error_log("Student query preparation failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    $errors[] = "Failed to fetch students due to a system error.";
} else {
    if (!$stmt->execute()) {
        error_log("Student query execution failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
        $errors[] = "Failed to fetch students due to a system error.";
    } else {
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            error_log("No active students found in query.", 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
            $errors[] = "No active students found.";
        } else {
            while ($row = $result->fetch_assoc()) {
                $class_name = $row['class_name'] ?: 'Unassigned';
                if (!isset($students_by_class[$class_name])) {
                    $students_by_class[$class_name] = [];
                }
                $students_by_class[$class_name][] = [
                    'id' => $row['id'],
                    'student_name' => $row['student_name']
                ];
            }
        }
        $stmt->close();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_mapping') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = "Invalid CSRF token.";
        error_log("CSRF token validation failed for user ID: " . $_SESSION['user_id'], 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    } else {
        $parent_id = filter_var($_POST['parent_id'], FILTER_VALIDATE_INT);
        $student_ids = isset($_POST['student_ids']) ? array_filter($_POST['student_ids'], 'is_numeric') : [];

        // Validation
        if (!$parent_id || !in_array($parent_id, array_column($parents, 'id'))) {
            $errors[] = "Invalid parent selected.";
        }
        if (empty($student_ids)) {
            $errors[] = "At least one student must be selected.";
        } else {
            $valid_student_ids = [];
            foreach ($students_by_class as $class => $students) {
                $valid_student_ids = array_merge($valid_student_ids, array_column($students, 'id'));
            }
            foreach ($student_ids as $student_id) {
                if (!in_array($student_id, $valid_student_ids)) {
                    $errors[] = "Invalid student selected.";
                    break;
                }
            }
        }

        // Check for existing mappings
        if (empty($errors)) {
            $stmt = $conn->prepare("SELECT student_id FROM parent_student_mapping WHERE parent_id = ?");
            $stmt->bind_param("i", $parent_id);
            if ($stmt->execute()) {
                $result = $stmt->get_result();
                $existing_student_ids = [];
                while ($row = $result->fetch_assoc()) {
                    $existing_student_ids[] = $row['student_id'];
                }
                $stmt->close();
                $duplicate_students = array_intersect($student_ids, $existing_student_ids);
                if (!empty($duplicate_students)) {
                    $errors[] = "Some students are already mapped to this parent.";
                }
            } else {
                $errors[] = "Failed to check existing mappings.";
                error_log("Check existing mappings failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
                $stmt->close();
            }
        }

        // Insert mappings if no errors
        if (empty($errors)) {
            $stmt = $conn->prepare("INSERT INTO parent_student_mapping (parent_id, student_id, created_at) VALUES (?, ?, NOW())");
            foreach ($student_ids as $student_id) {
                $student_id_int = (int)$student_id; // Assign casted value to a variable
                $stmt->bind_param("ii", $parent_id, $student_id_int);
                if ($stmt->execute()) {
                    logActivity($conn, $_SESSION['user_id'], "Added parent-student mapping", "Parent ID: $parent_id, Student ID: $student_id");
                } else {
                    $errors[] = "Failed to add mapping for student ID: $student_id.";
                    error_log("Failed to add mapping: Parent ID: $parent_id, Student ID: $student_id, Error: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
                }
            }
            $stmt->close();
            if (empty($errors)) {
                $success_msg = "Mapping(s) added successfully.";
                header("Location: parent_student.php?success=" . urlencode($success_msg));
                exit();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Add parent-student mappings in QueensPa School.">
    <meta name="keywords" content="QueensPa School, parent-student mapping, admin dashboard">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>Add Parent-Student Mapping - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #ff6b00;
            --accent-color: #ffc107;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .gradient-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
            transition: transform 0.3s;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .btn-primary, .btn-secondary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
        }
        .btn-primary:hover, .btn-secondary:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .form-label {
            font-weight: 500;
        }
        .alert {
            border-radius: 8px;
        }
        .accordion-button {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
        }
        .accordion-button:not(.collapsed) {
            background: linear-gradient(to right, #e03e00, #cc5c00);
            color: white;
        }
        .accordion-button::after {
            filter: invert(1);
        }
        .form-check-label {
            cursor: pointer;
        }
        .student-checkboxes {
            max-height: 300px;
            overflow-y: auto;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }
        .action-links .btn {
            min-width: 100px;
        }
        @media (max-width: 767.98px) {
            .card {
                margin-bottom: 1rem;
            }
            .action-links {
                flex-direction: column;
                gap: 0.5rem;
            }
            .action-links .btn {
                width: 100%;
                min-width: auto;
            }
        }
    </style>
</head>
<body data-bs-theme="light">
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php'; ?>
    <div class="container my-5 animate__animated animate__fadeIn">
        <h1 class="text-center mb-4 gradient-header p-3 rounded">Add Parent-Student Mapping</h1>

        <!-- Action Links -->
        <div class="d-flex justify-content-between mb-4 action-links">
            <a href="/admin/dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            <a href="/admin/parent_student.php" class="btn btn-primary"><i class="fas fa-list"></i> View Mappings</a>
        </div>

        <!-- Error/Success Messages -->
        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
                <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show animate__animated animate__shakeX" role="alert">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Add Mapping Form -->
        <div class="card">
            <div class="card-body">
                <form method="POST" id="mappingForm">
                    <input type="hidden" name="action" value="add_mapping">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="parent_id" class="form-label">Parent</label>
                            <select name="parent_id" id="parent_id" class="form-select" required aria-label="Select parent">
                                <option value="">Select Parent</option>
                                <?php foreach ($parents as $parent): ?>
                                    <option value="<?php echo $parent['id']; ?>" <?php echo $parent_id == $parent['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($parent['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Students</label>
                            <div class="student-checkboxes">
                                <?php if (empty($students_by_class)): ?>
                                    <p class="text-muted">No students available. Please ensure students are registered and active.</p>
                                <?php else: ?>
                                    <div class="accordion" id="studentAccordion">
                                        <?php foreach ($students_by_class as $class_name => $students): ?>
                                            <div class="accordion-item">
                                                <h2 class="accordion-header" id="class-<?php echo htmlspecialchars(str_replace(' ', '-', $class_name)); ?>">
                                                    <button class="accordion-button" type="button" data-bs-toggle="collapse" 
                                                            data-bs-target="#collapse-<?php echo htmlspecialchars(str_replace(' ', '-', $class_name)); ?>" 
                                                            aria-expanded="true" 
                                                            aria-controls="collapse-<?php echo htmlspecialchars(str_replace(' ', '-', $class_name)); ?>">
                                                        <?php echo htmlspecialchars($class_name); ?> (<?php echo count($students); ?>)
                                                    </button>
                                                </h2>
                                                <div id="collapse-<?php echo htmlspecialchars(str_replace(' ', '-', $class_name)); ?>" 
                                                     class="accordion-collapse collapse show" 
                                                     aria-labelledby="class-<?php echo htmlspecialchars(str_replace(' ', '-', $class_name)); ?>" 
                                                     data-bs-parent="#studentAccordion">
                                                    <div class="accordion-body">
                                                        <?php foreach ($students as $student): ?>
                                                            <div class="form-check">
                                                                <input class="form-check-input student-checkbox" 
                                                                       type="checkbox" 
                                                                       name="student_ids[]" 
                                                                       value="<?php echo $student['id']; ?>" 
                                                                       id="student-<?php echo $student['id']; ?>" 
                                                                       <?php echo in_array($student['id'], $student_ids) ? 'checked' : ''; ?>>
                                                                <label class="form-check-label" for="student-<?php echo $student['id']; ?>">
                                                                    <?php echo htmlspecialchars($student['student_name']); ?>
                                                                </label>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Mapping</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php'; ?>
    <?php $conn->close(); ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        // Client-side validation
        document.getElementById('mappingForm').addEventListener('submit', function(e) {
            const parentId = document.getElementById('parent_id').value;
            const studentCheckboxes = document.querySelectorAll('.student-checkbox:checked');
            if (!parentId) {
                e.preventDefault();
                alert('Please select a parent.');
            }
            if (studentCheckboxes.length === 0) {
                e.preventDefault();
                alert('Please select at least one student.');
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>