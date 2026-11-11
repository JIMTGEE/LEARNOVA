<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Debug: Log access details
error_log("Accessing end_term_grades.php with URL: " . $_SERVER['REQUEST_URI']);
error_log("Session: " . print_r($_SESSION, true));
error_log("GET Parameters: " . print_r($_GET, true));

// Function to check if a file exists in the document root
function checkFileExists($relative_path) {
    $full_path = $_SERVER['DOCUMENT_ROOT'] . $relative_path;
    return file_exists($full_path) ? $relative_path : false;
}

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Access denied. You must be a teacher to access this page.";
    $login_path = checkFileExists('/login.php');
    if ($login_path) {
        error_log("Redirecting to /login.php due to invalid session");
        header("Location: $login_path");
        exit();
    } else {
        die("Error: Login page not found. Please contact the administrator.");
    }
}

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    $login_path = checkFileExists('/login.php');
    if ($login_path) {
        header("Location: $login_path");
        exit();
    } else {
        die("Error: Database configuration and login page not found. Please contact the administrator.");
    }
}
require_once($db_path);

// Check if database connection is valid
if (!isset($conn) || !($conn instanceof mysqli) || !$conn->ping()) {
    error_log("Database connection failed or not established");
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Fallback for close_db_connection if not defined
if (!function_exists('close_db_connection')) {
    function close_db_connection($conn) {
        if ($conn instanceof mysqli && $conn->ping()) {
            $conn->close();
        }
    }
}

// Set base URL for redirects (file is in /teacher/)
$base_url = '/teacher/';
error_log("Base URL set to: $base_url");

// Verify target file exists for debugging
$target_file = $_SERVER['DOCUMENT_ROOT'] . $base_url . 'end_term_grades.php';
if (!file_exists($target_file)) {
    error_log("Target file not found: $target_file");
}

// Fetch teacher details if not in session
if (!isset($_SESSION['name'])) {
    $stmt = $conn->prepare("SELECT name FROM users WHERE id = ? AND role = 'teacher'");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to fetch teacher details.";
        header("Location: /login.php");
        exit();
    }
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $stmt->bind_result($teacher_name);
    $_SESSION['name'] = $stmt->fetch() ? $teacher_name : 'Unknown Teacher';
    $stmt->close();
}

// Function to calculate letter grade and default comment
function calculateLetterGrade($total) {
    if (!is_numeric($total)) return ['grade' => 'N/A', 'comment' => 'N/A'];
    if ($total >= 90) return ['grade' => 'A+', 'comment' => 'Excellent'];
    if ($total >= 85) return ['grade' => 'A', 'comment' => 'Great'];
    if ($total >= 80) return ['grade' => 'A-', 'comment' => 'Very Good'];
    if ($total >= 75) return ['grade' => 'B+', 'comment' => 'Good'];
    if ($total >= 70) return ['grade' => 'B', 'comment' => 'Fair'];
    if ($total >= 65) return ['grade' => 'B-', 'comment' => 'Satisfactory'];
    if ($total >= 60) return ['grade' => 'C+', 'comment' => 'Average'];
    if ($total >= 55) return ['grade' => 'C', 'comment' => 'Pass'];
    if ($total >= 50) return ['grade' => 'C-', 'comment' => 'Borderline'];
    if ($total >= 45) return ['grade' => 'D+', 'comment' => 'Weak'];
    if ($total >= 40) return ['grade' => 'D', 'comment' => 'Poor'];
    return ['grade' => 'F', 'comment' => 'Fail'];
}

// Fetch teacher_id
$teacher_id = $_SESSION['user_id'];

// Validate GET parameters
$student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : 0;
$class_id = isset($_GET['class_id']) ? intval($_GET['class_id']) : 0;
$term_id = isset($_GET['term_id']) ? intval($_GET['term_id']) : 0;

if (!$student_id || !$class_id || !$term_id) {
    error_log("Invalid parameters: student_id=$student_id, class_id=$class_id, term_id=$term_id");
    $_SESSION['error_msg'] = "Invalid parameters provided.";
    $submit_grades_path = checkFileExists($base_url . 'submit_grades.php');
    if ($submit_grades_path) {
        header("Location: $submit_grades_path");
        close_db_connection($conn);
        exit();
    } else {
        die("Error: Grade selection page not found. Please contact the administrator.");
    }
}

// Validate term_id
$stmt = $conn->prepare("SELECT term_name, year FROM academic_calendar WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch term details.";
    header("Location: {$base_url}submit_grades.php");
    exit();
}
$stmt->bind_param("i", $term_id);
$stmt->execute();
$stmt->bind_result($term_name, $year);
if (!$stmt->fetch()) {
    error_log("Invalid term_id: $term_id");
    $_SESSION['error_msg'] = "Invalid academic term.";
    header("Location: {$base_url}submit_grades.php");
    exit();
}
$term_name = "$term_name ($year)";
$stmt->close();

// Fetch student details
$stmt = $conn->prepare("SELECT name, email FROM users WHERE id = ? AND role = 'student'");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch student details.";
    header("Location: {$base_url}submit_grades.php");
    exit();
}
$stmt->bind_param("i", $student_id);
$stmt->execute();
$stmt->bind_result($name, $email);
$student_name = $stmt->fetch() ? ($name ?: $email) : "Unknown Student";
$stmt->close();

// Fetch class and department for the student
$stmt = $conn->prepare("
    SELECT c.id AS class_id, c.class_name, d.id AS department_id, d.name AS department_name 
    FROM student_classes sc 
    JOIN classes c ON sc.class_id = c.id 
    JOIN departments d ON c.department_id = d.id 
    WHERE sc.user_id = ? AND c.id = ? AND sc.academic_year = (SELECT year FROM academic_calendar WHERE id = ?)
");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch class details.";
    header("Location: {$base_url}submit_grades.php");
    exit();
}
$stmt->bind_param("iii", $student_id, $class_id, $term_id);
$stmt->execute();
$stmt->bind_result($class_id_result, $class_name, $department_id, $department_name);
$class_data = $stmt->fetch() ? ['class_id' => $class_id_result, 'class_name' => $class_name, 'department_id' => $department_id, 'department_name' => $department_name] : null;
$stmt->close();

if (!$class_data) {
    error_log("No class or department found for student_id=$student_id, class_id=$class_id, term_id=$term_id");
    $_SESSION['error_msg'] = "No class or department found for this student.";
    $submit_grades_path = checkFileExists($base_url . 'submit_grades.php');
    if ($submit_grades_path) {
        header("Location: $submit_grades_path");
        close_db_connection($conn);
        exit();
    } else {
        die("Error: Grade selection page not found. Please contact the administrator.");
    }
}

// Fetch all subjects for the class or department with active status
$subjects = [];
$stmt = $conn->prepare("
    SELECT s.id AS subject_id, s.subject_name, s.subject_code 
    FROM subjects s 
    WHERE s.department_id = ? AND s.status = 'Active' 
    UNION 
    SELECT s.id AS subject_id, s.subject_name, s.subject_code 
    FROM subjects s 
    JOIN class_subjects cs ON s.id = cs.subject_id 
    JOIN classes c ON cs.class_id = c.id 
    WHERE c.id = ? AND s.status = 'Active'
");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch subjects.";
    header("Location: {$base_url}submit_grades.php");
    exit();
}
$stmt->bind_param("ii", $class_data['department_id'], $class_data['class_id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $subjects[] = $row;
}
$stmt->close();

// Fetch all available classes for the promoted_to_class_id dropdown
$classes = [];
$stmt = $conn->prepare("SELECT id, class_name FROM classes ORDER BY class_name");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch classes.";
    header("Location: {$base_url}submit_grades.php");
    exit();
}
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $classes[] = $row;
}
$stmt->close();

// Handle form submission for grade entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_grades']) && isset($_POST['subject_id']) && isset($_POST['grade'])) {
    $success = true;
    $conn->begin_transaction();
    try {
        foreach ($_POST['subject_id'] as $subject_id) {
            $grade = trim($_POST['grade'][$subject_id] ?? '');
            $comment = trim($_POST['comment'][$subject_id] ?? '');
            if (empty($grade) && $grade !== '0') {
                continue; // Skip empty grades
            }

            $grade = floatval($grade);
            if ($grade < 0 || $grade > 100) {
                throw new Exception("Grade must be between 0 and 100 for subject ID $subject_id.");
            }

            // Use provided comment or calculate default if empty
            $comment = !empty($comment) ? htmlspecialchars($comment) : calculateLetterGrade($grade)['comment'];

            $stmt = $conn->prepare("
                INSERT INTO grades (user_id, class_id, subject_id, term_id, end_term_exam, comment, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE end_term_exam = ?, comment = ?, updated_at = NOW()
            ");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("iiiidssd", $student_id, $class_data['class_id'], $subject_id, $term_id, $grade, $comment, $grade, $comment);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
        $_SESSION['success_msg'] = "Grades submitted successfully.";
        $redirect_url = "{$base_url}end_term_grades.php?student_id=" . urlencode($student_id) . "&class_id=" . urlencode($class_id) . "&term_id=" . urlencode($term_id);
        error_log("Redirecting to: $redirect_url");
        header("Location: $redirect_url");
        close_db_connection($conn);
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_msg'] = "Failed to submit grades: " . $e->getMessage();
        error_log("Grade submission failed: " . $e->getMessage());
        $success = false;
    }
}

// Handle grade deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_grade']) && isset($_POST['grade_id'])) {
    $grade_id = intval($_POST['grade_id']);
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM grades WHERE id = ? AND user_id = ? AND class_id = ? AND term_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("iiii", $grade_id, $student_id, $class_data['class_id'], $term_id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        $_SESSION['success_msg'] = "Grade deleted successfully.";
        $redirect_url = "{$base_url}end_term_grades.php?student_id=" . urlencode($student_id) . "&class_id=" . urlencode($class_id) . "&term_id=" . urlencode($term_id);
        error_log("Redirecting to: $redirect_url");
        header("Location: $redirect_url");
        close_db_connection($conn);
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_msg'] = "Failed to delete grade: " . $e->getMessage();
        error_log("Grade deletion failed: " . $e->getMessage());
    }
}

// Handle report card deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_report'])) {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("DELETE FROM student_reports WHERE user_id = ? AND class_id = ? AND term_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("iii", $student_id, $class_data['class_id'], $term_id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        $_SESSION['success_msg'] = "Report card deleted successfully.";
        $redirect_url = "{$base_url}end_term_grades.php?student_id=" . urlencode($student_id) . "&class_id=" . urlencode($class_id) . "&term_id=" . urlencode($term_id);
        error_log("Redirecting to: $redirect_url");
        header("Location: $redirect_url");
        close_db_connection($conn);
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['error_msg'] = "Failed to delete report card: " . $e->getMessage();
        error_log("Report card deletion failed: " . $e->getMessage());
    }
}

// Handle grade edit
$edit_grade_id = isset($_POST['edit_grade_id']) ? intval($_POST['edit_grade_id']) : (isset($_GET['edit_grade_id']) ? intval($_GET['edit_grade_id']) : 0);
$edit_grade = null;
if ($edit_grade_id) {
    $stmt = $conn->prepare("SELECT end_term_exam, subject_id, comment FROM grades WHERE id = ? AND user_id = ? AND class_id = ? AND term_id = ?");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to fetch grade for editing.";
        header("Location: {$base_url}submit_grades.php");
        exit();
    }
    $stmt->bind_param("iiii", $edit_grade_id, $student_id, $class_data['class_id'], $term_id);
    $stmt->execute();
    $stmt->bind_result($end_term_exam, $edit_subject_id, $comment);
    $edit_grade = $stmt->fetch() ? ['mark' => floatval($end_term_exam), 'subject_id' => $edit_subject_id, 'comment' => $comment] : null;
    $stmt->close();
}

// Handle edit grade submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_grade']) && isset($_POST['grade']) && $edit_grade_id) {
    $subject_id = $edit_grade['subject_id'];
    $grade = trim($_POST['grade'][$subject_id] ?? '');
    $comment = trim($_POST['comment'][$subject_id] ?? '');
    if (!empty($grade) || $grade === '0') {
        $grade = floatval($grade);
        if ($grade >= 0 && $grade <= 100) {
            $conn->begin_transaction();
            try {
                $comment = !empty($comment) ? htmlspecialchars($comment) : calculateLetterGrade($grade)['comment'];
                $stmt = $conn->prepare("UPDATE grades SET end_term_exam = ?, comment = ?, updated_at = NOW() WHERE id = ?");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param("dsi", $grade, $comment, $edit_grade_id);
                $stmt->execute();
                $stmt->close();
                $conn->commit();
                $_SESSION['success_msg'] = "Grade updated successfully.";
                $redirect_url = "{$base_url}end_term_grades.php?student_id=" . urlencode($student_id) . "&class_id=" . urlencode($class_id) . "&term_id=" . urlencode($term_id);
                error_log("Redirecting to: $redirect_url");
                header("Location: $redirect_url");
                close_db_connection($conn);
                exit();
            } catch (Exception $e) {
                $conn->rollback();
                $_SESSION['error_msg'] = "Failed to update grade: " . $e->getMessage();
                error_log("Grade update failed: " . $e->getMessage());
            }
        } else {
            $_SESSION['error_msg'] = "Grade must be between 0 and 100.";
        }
    } else {
        $_SESSION['error_msg'] = "Grade cannot be empty.";
    }
}

// Fetch existing grades and auto-calculate comments
$existing_grades = [];
foreach ($subjects as $subject) {
    $stmt = $conn->prepare("
        SELECT id, end_term_exam, comment
        FROM grades 
        WHERE user_id = ? AND class_id = ? AND subject_id = ? AND term_id = ?
    ");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to fetch existing grades.";
        header("Location: {$base_url}submit_grades.php");
        exit();
    }
    $stmt->bind_param("iiii", $student_id, $class_data['class_id'], $subject['subject_id'], $term_id);
    $stmt->execute();
    $stmt->bind_result($id, $end_term_exam, $comment);
    if ($stmt->fetch()) {
        $existing_grades[$subject['subject_id']] = [
            'id' => $id,
            'mark' => $end_term_exam !== null ? floatval($end_term_exam) : null,
            'comment' => $comment ?: calculateLetterGrade($end_term_exam)['comment']
        ];
    } else {
        $existing_grades[$subject['subject_id']] = null;
    }
    $stmt->close();
}

// Calculate overall statistics
$total_marks = 0;
$subject_count = 0;
foreach ($existing_grades as $grade) {
    if ($grade && $grade['mark'] !== null) {
        $total_marks += $grade['mark'];
        $subject_count++;
    }
}
$average = $subject_count > 0 ? $total_marks / $subject_count : 0;
$overall_grade_info = calculateLetterGrade($average);

// Define total days for the term
$total_days = 56;

// Handle report card submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_report'])) {
    $attendance = isset($_POST['attendance']) ? floatval($_POST['attendance']) : 0;
    $conduct = isset($_POST['conduct']) ? htmlspecialchars($_POST['conduct']) : '';
    $attitude = isset($_POST['attitude']) ? htmlspecialchars($_POST['attitude']) : '';
    $interest = isset($_POST['interest']) ? htmlspecialchars($_POST['interest']) : '';
    $reopening_date = isset($_POST['reopening_date']) ? htmlspecialchars($_POST['reopening_date']) : '';
    $promoted_to_class_id = isset($_POST['promoted_to_class_id']) && !empty($_POST['promoted_to_class_id']) ? intval($_POST['promoted_to_class_id']) : null;
    $teacher_remarks = isset($_POST['teacher_remarks']) ? htmlspecialchars($_POST['teacher_remarks']) : '';
    $headmaster_remarks = isset($_POST['headmaster_remarks']) ? htmlspecialchars($_POST['headmaster_remarks']) : '';

    // Handle custom attitude and interest if "Other" is selected
    if (isset($_POST['attitude_other']) && !empty($_POST['attitude_other']) && $_POST['attitude'] === 'other') {
        $attitude = htmlspecialchars($_POST['attitude_other']);
    }
    if (isset($_POST['interest_other']) && !empty($_POST['interest_other']) && $_POST['interest'] === 'other') {
        $interest = htmlspecialchars($_POST['interest_other']);
    }

    // Validate inputs
    $today = date('Y-m-d');
    if ($attendance < 0 || $attendance > $total_days) {
        $_SESSION['error_msg'] = "Attendance must be a number between 0 and $total_days.";
    } elseif (empty($conduct)) {
        $_SESSION['error_msg'] = "Conduct is required.";
    } elseif (empty($attitude)) {
        $_SESSION['error_msg'] = "Attitude is required.";
    } elseif (empty($interest)) {
        $_SESSION['error_msg'] = "Interest is required.";
    } elseif (!empty($reopening_date) && $reopening_date < $today) {
        $_SESSION['error_msg'] = "Reopening date must be a future date.";
    } elseif (strlen($teacher_remarks) > 500 || strlen($headmaster_remarks) > 500 || strlen($attitude) > 50 || strlen($interest) > 50) {
        $_SESSION['error_msg'] = "Input exceeds maximum length (Remarks: 500, Attitude/Interest: 50).";
    } else {
        $conn->begin_transaction();
        try {
            // Insert or update student report
            $stmt = $conn->prepare("
                INSERT INTO student_reports (user_id, class_id, term_id, attendance, conduct, attitude, interest, reopening_date, promoted_to_class_id, teacher_remarks, headmaster_remarks, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE attendance = ?, conduct = ?, attitude = ?, interest = ?, reopening_date = ?, promoted_to_class_id = ?, teacher_remarks = ?, headmaster_remarks = ?, updated_at = NOW()
            ");
            if (!$stmt) {
                throw new Exception("Prepare failed: " . $conn->error);
            }
            $stmt->bind_param("iiidssssissssssdiss", 
                $student_id, $class_id, $term_id, $attendance, $conduct, $attitude, $interest, $reopening_date, $promoted_to_class_id, $teacher_remarks, $headmaster_remarks,
                $attendance, $conduct, $attitude, $interest, $reopening_date, $promoted_to_class_id, $teacher_remarks, $headmaster_remarks
            );
            $stmt->execute();
            $stmt->close();

            // Update comments with report summary only if empty
            $report_summary = "Att: $attendance/$total_days, Cond: $conduct, Avg: " . number_format($average, 2) . "% (" . $overall_grade_info['grade'] . ")";
            foreach ($subjects as $subject) {
                $subject_id = $subject['subject_id'];
                if (isset($existing_grades[$subject_id]) && $existing_grades[$subject_id]) {
                    $grade_id = $existing_grades[$subject_id]['id'];
                    $mark = $existing_grades[$subject_id]['mark'];
                    $current_comment = $existing_grades[$subject_id]['comment'];
                    if (empty($current_comment)) {
                        $combined_comment = calculateLetterGrade($mark)['comment'] . " | $report_summary";
                        $stmt = $conn->prepare("UPDATE grades SET comment = ?, updated_at = NOW() WHERE id = ?");
                        if (!$stmt) {
                            throw new Exception("Prepare failed: " . $conn->error);
                        }
                        $stmt->bind_param("si", $combined_comment, $grade_id);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }

            $conn->commit();
            $_SESSION['success_msg'] = "Report card and grades updated successfully.";
            $redirect_url = "{$base_url}end_term_grades.php?student_id=" . urlencode($student_id) . "&class_id=" . urlencode($class_id) . "&term_id=" . urlencode($term_id);
            error_log("Redirecting to: $redirect_url");
            header("Location: $redirect_url");
            close_db_connection($conn);
            exit();
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error_msg'] = "Failed to save report card: " . $e->getMessage();
            error_log("Report card save failed: " . $e->getMessage());
        }
    }
}

// Fetch existing report data
$stmt = $conn->prepare("SELECT attendance, conduct, attitude, interest, reopening_date, promoted_to_class_id, teacher_remarks, headmaster_remarks FROM student_reports WHERE user_id = ? AND class_id = ? AND term_id = ?");
if (!$stmt) {
    error_log("Prepare failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch report data: " . $conn->error;
    header("Location: {$base_url}submit_grades.php");
    exit();
}
$stmt->bind_param("iii", $student_id, $class_data['class_id'], $term_id);
$stmt->execute();
$stmt->bind_result($attendance, $conduct, $attitude, $interest, $reopening_date, $promoted_to_class_id, $teacher_remarks, $headmaster_remarks);
$report_data = $stmt->fetch() ? ['attendance' => $attendance, 'conduct' => $conduct, 'attitude' => $attitude, 'interest' => $interest, 'reopening_date' => $reopening_date, 'promoted_to_class_id' => $promoted_to_class_id, 'teacher_remarks' => $teacher_remarks, 'headmaster_remarks' => $headmaster_remarks] : null;
$stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>End-Term Grades - QPMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .gradient-text {
            background: linear-gradient(to right, #f97316, #ea580c);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen flex flex-col font-sans text-gray-800">
    <header class="bg-gradient-to-r from-orange-600 to-orange-800 text-white p-6 shadow-lg">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-3xl font-bold gradient-text">End-Term Grades</h1>
            <nav>
                <?php
                $submit_grades_path = checkFileExists($base_url . 'submit_grades.php');
                if ($submit_grades_path) {
                    echo '<a href="' . htmlspecialchars($submit_grades_path . '?term_id=' . urlencode($term_id)) . '" class="text-white hover:text-orange-200 underline transition duration-200">Back to Grade Selection</a>';
                } else {
                    echo '<span class="text-gray-300 cursor-not-allowed" title="Grade selection page not found">Back to Grade Selection</span>';
                }
                ?>
            </nav>
        </div>
    </header>

    <main class="container mx-auto p-6 flex-grow">
        <div class="bg-white rounded-xl shadow-xl p-8 max-w-5xl mx-auto">
            <h2 class="text-2xl font-semibold mb-6 gradient-text">
                End-Term Grade Profile - <?php echo htmlspecialchars($student_name); ?> [<?php echo htmlspecialchars($class_data['class_name']); ?>]
            </h2>
            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 p-4 rounded-lg mb-6 text-center"><?php echo htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success_msg'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 p-4 rounded-lg mb-6 text-center"><?php echo htmlspecialchars($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?></div>
            <?php endif; ?>

            <!-- Grade Entry Form -->
            <?php if (!$edit_grade_id): ?>
                <form method="POST" action="" class="space-y-6" id="gradeForm" onsubmit="return validateGradeForm()">
                    <div class="bg-gray-50 p-4 rounded-lg">
                        <h3 class="text-xl font-medium mb-4 gradient-text">Enter End-Term Marks</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-separate border-spacing-0">
                                <thead class="bg-orange-600 text-white">
                                    <tr>
                                        <th class="p-3 text-left">Subject/Course</th>
                                        <th class="p-3 text-left">End-Term Mark</th>
                                        <th class="p-3 text-left">Grade</th>
                                        <th class="p-3 text-left">Comment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $entry_date = date('Y-m-d H:i:s');
                                    foreach ($subjects as $subject) {
                                        $subject_id = $subject['subject_id'];
                                        $mark = $existing_grades[$subject_id] !== null ? $existing_grades[$subject_id]['mark'] : '';
                                        $comment = $existing_grades[$subject_id] !== null ? $existing_grades[$subject_id]['comment'] : '';
                                        $grade_info = $mark !== '' ? calculateLetterGrade($mark) : ['grade' => 'N/A', 'comment' => 'N/A'];
                                    ?>
                                    <tr class="border-b border-gray-200 hover:bg-gray-100 transition duration-200">
                                        <td class="p-3"><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                        <td class="p-3">
                                            <input type="number" name="grade[<?php echo $subject_id; ?>]" value="<?php echo htmlspecialchars($mark); ?>" 
                                                   class="w-24 p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent"
                                                   min="0" max="100" step="0.01" onchange="updateGrade(this, '<?php echo $subject_id; ?>')">
                                        </td>
                                        <td class="p-3">
                                            <span id="grade_<?php echo $subject_id; ?>" class="text-lg font-medium"><?php echo htmlspecialchars($grade_info['grade']); ?></span>
                                            <input type="hidden" name="subject_id[<?php echo $subject_id; ?>]" value="<?php echo $subject_id; ?>">
                                        </td>
                                        <td class="p-3">
                                            <input type="text" name="comment[<?php echo $subject_id; ?>]" value="<?php echo htmlspecialchars($comment); ?>" 
                                                   class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent"
                                                   maxlength="255" placeholder="Enter comment or leave blank for default">
                                        </td>
                                    </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="text-center">
                        <input type="hidden" name="term_id" value="<?php echo $term_id; ?>">
                        <input type="hidden" name="save_grades" value="1">
                        <button type="submit" class="bg-gradient-to-r from-orange-600 to-orange-800 text-white py-2 px-6 rounded-lg hover:from-orange-700 hover:to-orange-900 transition duration-200 shadow-md">
                            Save Grades
                        </button>
                    </div>
                </form>
            <?php endif; ?>

            <!-- Report Card Section -->
            <div class="mt-8 border-t border-gray-200 pt-6">
                <h3 class="text-2xl font-semibold mb-6 gradient-text">Report Card - <?php echo htmlspecialchars($term_name); ?></h3>
                <form method="POST" action="" class="space-y-6" id="reportForm" onsubmit="return validateReportForm()">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Academic Period</label>
                            <select name="academic_period" class="mt-1 block w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent" disabled>
                                <option value="<?php echo $term_id; ?>" selected><?php echo htmlspecialchars($term_name); ?></option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Number of Days Attended (out of <?php echo $total_days; ?>)</label>
                            <input type="number" name="attendance" value="<?php echo $report_data ? htmlspecialchars($report_data['attendance']) : ''; ?>" 
                                   class="mt-1 block w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent" 
                                   min="0" max="<?php echo $total_days; ?>" step="1" placeholder="Enter number of days">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Conduct</label>
                            <select name="conduct" class="mt-1 block w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                                <option value="" <?php echo !$report_data || !$report_data['conduct'] ? 'selected' : ''; ?>>Select Conduct</option>
                                <option value="Excellent" <?php echo $report_data && $report_data['conduct'] === 'Excellent' ? 'selected' : ''; ?>>Excellent</option>
                                <option value="Good" <?php echo $report_data && $report_data['conduct'] === 'Good' ? 'selected' : ''; ?>>Good</option>
                                <option value="Fair" <?php echo $report_data && $report_data['conduct'] === 'Fair' ? 'selected' : ''; ?>>Fair</option>
                                <option value="Poor" <?php echo $report_data && $report_data['conduct'] === 'Poor' ? 'selected' : ''; ?>>Poor</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Attitude</label>
                            <div class="relative">
                                <select name="attitude" id="attitude_select" class="mt-1 block w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent" onchange="toggleAttitudeOther(this)">
                                    <option value="" <?php echo !$report_data || !$report_data['attitude'] ? 'selected' : ''; ?>>Select Attitude</option>
                                    <option value="Positive" <?php echo $report_data && $report_data['attitude'] === 'Positive' ? 'selected' : ''; ?>>Positive</option>
                                    <option value="Neutral" <?php echo $report_data && $report_data['attitude'] === 'Neutral' ? 'selected' : ''; ?>>Neutral</option>
                                    <option value="Negative" <?php echo $report_data && $report_data['attitude'] === 'Negative' ? 'selected' : ''; ?>>Negative</option>
                                    <option value="other" <?php echo $report_data && !in_array($report_data['attitude'], ['Positive', 'Neutral', 'Negative']) ? 'selected' : ''; ?>>Other</option>
                                </select>
                                <input type="text" name="attitude_other" id="attitude_other" class="mt-1 block w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent hidden" 
                                       value="<?php echo $report_data && !in_array($report_data['attitude'], ['Positive', 'Neutral', 'Negative']) ? htmlspecialchars($report_data['attitude']) : ''; ?>" 
                                       placeholder="Enter custom attitude" maxlength="50">
                            </div>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Interest</label>
                            <div class="relative">
                                <select name="interest" id="interest_select" class="mt-1 block w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent" onchange="toggleInterestOther(this)">
                                    <option value="" <?php echo !$report_data || !$report_data['interest'] ? 'selected' : ''; ?>>Select Interest</option>
                                    <option value="High" <?php echo $report_data && $report_data['interest'] === 'High' ? 'selected' : ''; ?>>High</option>
                                    <option value="Moderate" <?php echo $report_data && $report_data['interest'] === 'Moderate' ? 'selected' : ''; ?>>Moderate</option>
                                    <option value="Low" <?php echo $report_data && $report_data['interest'] === 'Low' ? 'selected' : ''; ?>>Low</option>
                                    <option value="other" <?php echo $report_data && !in_array($report_data['interest'], ['High', 'Moderate', 'Low']) ? 'selected' : ''; ?>>Other</option>
                                </select>
                                <input type="text" name="interest_other" id="interest_other" class="mt-1 block w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent hidden" 
                                       value="<?php echo $report_data && !in_array($report_data['interest'], ['High', 'Moderate', 'Low']) ? htmlspecialchars($report_data['interest']) : ''; ?>" 
                                       placeholder="Enter custom interest" maxlength="50">
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Reopening Date</label>
                            <input type="date" name="reopening_date" value="<?php echo $report_data ? htmlspecialchars($report_data['reopening_date']) : date('Y-m-d'); ?>" 
                                   class="mt-1 block w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Promoted to (Optional)</label>
                            <select name="promoted_to_class_id" class="mt-1 block w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent">
                                <option value="">Not Promoted</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $report_data && $report_data['promoted_to_class_id'] == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Teacher Remarks</label>
                        <textarea name="teacher_remarks" class="mt-1 block w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent" rows="4" maxlength="500"><?php echo $report_data ? htmlspecialchars($report_data['teacher_remarks']) : ''; ?></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Headmaster Remarks</label>
                        <textarea name="headmaster_remarks" class="mt-1 block w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent" rows="4" maxlength="500"><?php echo $report_data ? htmlspecialchars($report_data['headmaster_remarks']) : ''; ?></textarea>
                    </div>
                    <div class="text-center">
                        <input type="hidden" name="term_id" value="<?php echo $term_id; ?>">
                        <input type="hidden" name="save_report" value="1">
                        <button type="submit" class="bg-gradient-to-r from-green-600 to-green-800 text-white py-2 px-6 rounded-lg hover:from-green-700 hover:to-green-900 transition duration-200 shadow-md">
                            Save Report Card
                        </button>
                    </div>
                </form>

                <!-- Display Report Card Summary -->
                <?php if ($report_data): ?>
                    <div class="mt-6 bg-gray-50 p-6 rounded-lg shadow-inner">
                        <h4 class="text-xl font-medium mb-4 gradient-text">Saved Report Card Summary</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <p><strong>Academic Period:</strong> <?php echo htmlspecialchars($term_name); ?></p>
                            <p><strong>Attendance:</strong> <?php echo htmlspecialchars($report_data['attendance']); ?> / <?php echo $total_days; ?></p>
                            <p><strong>Conduct:</strong> <?php echo htmlspecialchars($report_data['conduct']); ?></p>
                            <p><strong>Attitude:</strong> <?php echo htmlspecialchars($report_data['attitude']); ?></p>
                            <p><strong>Interest:</strong> <?php echo htmlspecialchars($report_data['interest']); ?></p>
                            <p><strong>Reopening Date:</strong> <?php echo htmlspecialchars($report_data['reopening_date']); ?></p>
                            <p><strong>Promoted to:</strong> <?php echo $report_data['promoted_to_class_id'] ? htmlspecialchars(array_column($classes, 'class_name', 'id')[$report_data['promoted_to_class_id']] ?? 'N/A') : 'Not Promoted'; ?></p>
                            <p><strong>Overall Average:</strong> <?php echo number_format($average, 2); ?>% (<?php echo htmlspecialchars($overall_grade_info['grade']); ?> - <?php echo htmlspecialchars($overall_grade_info['comment']); ?>)</p>
                        </div>
                        <div class="mt-4">
                            <p><strong>Teacher Remarks:</strong> <?php echo htmlspecialchars($report_data['teacher_remarks']); ?></p>
                            <p><strong>Headmaster Remarks:</strong> <?php echo htmlspecialchars($report_data['headmaster_remarks']); ?></p>
                        </div>
                        <div class="text-center mt-6">
                            <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this report card?');">
                                <input type="hidden" name="delete_report" value="1">
                                <button type="submit" class="bg-red-600 text-white py-2 px-4 rounded-lg hover:bg-red-700 transition duration-200 shadow-md">
                                    Delete Report Card
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Display Grades Section -->
            <h3 class="text-2xl font-semibold mt-8 mb-6 gradient-text"><?php echo htmlspecialchars($term_name); ?> End-Term Grades</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-separate border-spacing-0">
                    <thead class="bg-orange-600 text-white">
                        <tr>
                            <th class="p-3 text-left">Entry Date</th>
                            <th class="p-3 text-left">Course Name</th>
                            <th class="p-3 text-left">Mark %</th>
                            <th class="p-3 text-left">Grade</th>
                            <th class="p-3 text-left">Comment</th>
                            <th class="p-3 text-left">Exams</th>
                            <th class="p-3 text-left">Inputter</th>
                            <th class="p-3 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($subjects as $subject) {
                            $subject_id = $subject['subject_id'];
                            if ($existing_grades[$subject_id] && $existing_grades[$subject_id]['mark'] !== null) {
                                $grade_id = $existing_grades[$subject_id]['id'];
                                $mark = $existing_grades[$subject_id]['mark'];
                                $comment = $existing_grades[$subject_id]['comment'] ?? '-';
                                $grade_info = calculateLetterGrade($mark);
                        ?>
                        <tr class="border-b border-gray-200 hover:bg-gray-100 transition duration-200">
                            <td class="p-3"><?php echo htmlspecialchars($entry_date); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($mark); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($grade_info['grade']); ?></td>
                            <td class="p-3"><?php echo htmlspecialchars($comment); ?></td>
                            <td class="p-3">End-Term Examination [<?php echo htmlspecialchars($term_name); ?>]</td>
                            <td class="p-3"><?php echo htmlspecialchars($_SESSION['name']); ?></td>
                            <td class="p-3">
                                <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this grade?');">
                                    <input type="hidden" name="delete_grade" value="1">
                                    <input type="hidden" name="grade_id" value="<?php echo $grade_id; ?>">
                                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                    <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                                    <input type="hidden" name="term_id" value="<?php echo $term_id; ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700 transition duration-200">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <a href="?student_id=<?php echo urlencode($student_id); ?>&class_id=<?php echo urlencode($class_id); ?>&term_id=<?php echo urlencode($term_id); ?>&edit_grade_id=<?php echo urlencode($grade_id); ?>" class="text-blue-500 hover:text-blue-700 ml-2 transition duration-200">
                                    <i class="fas fa-edit"></i>
                                </a>
                            </td>
                        </tr>
                        <?php
                            }
                        }
                        ?>
                    </tbody>
                </table>
            </div>

            <!-- Edit Grade Form -->
            <?php if ($edit_grade_id && $edit_grade): ?>
                <div class="mt-8">
                    <h3 class="text-2xl font-semibold mb-6 gradient-text">Edit End-Term Grade</h3>
                    <form method="POST" action="" class="space-y-6" id="editGradeForm" onsubmit="return validateEditGradeForm()">
                        <div class="bg-gray-50 p-4 rounded-lg">
                            <div class="overflow-x-auto">
                                <table class="w-full text-left border-separate border-spacing-0">
                                    <thead class="bg-orange-600 text-white">
                                        <tr>
                                            <th class="p-3 text-left">Subject/Course</th>
                                            <th class="p-3 text-left">End-Term Mark</th>
                                            <th class="p-3 text-left">Grade</th>
                                            <th class="p-3 text-left">Comment</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        foreach ($subjects as $subject) {
                                            if ($subject['subject_id'] == $edit_grade['subject_id']) {
                                                $subject_id = $subject['subject_id'];
                                                $mark = $edit_grade['mark'];
                                                $comment = $edit_grade['comment'];
                                                $grade_info = calculateLetterGrade($mark);
                                        ?>
                                        <tr class="border-b border-gray-200 hover:bg-gray-100 transition duration-200">
                                            <td class="p-3"><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                            <td class="p-3">
                                                <input type="number" name="grade[<?php echo $subject_id; ?>]" value="<?php echo htmlspecialchars($mark); ?>" 
                                                       class="w-24 p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent"
                                                       min="0" max="100" step="0.01" onchange="updateGrade(this, '<?php echo $subject_id; ?>')">
                                            </td>
                                            <td class="p-3">
                                                <span id="grade_<?php echo $subject_id; ?>" class="text-lg font-medium"><?php echo htmlspecialchars($grade_info['grade']); ?></span>
                                                <input type="hidden" name="subject_id[<?php echo $subject_id; ?>]" value="<?php echo $subject_id; ?>">
                                            </td>
                                            <td class="p-3">
                                                <input type="text" name="comment[<?php echo $subject_id; ?>]" value="<?php echo htmlspecialchars($comment); ?>" 
                                                       class="w-full p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-transparent"
                                                       maxlength="255" placeholder="Enter comment or leave blank for default">
                                            </td>
                                        </tr>
                                        <?php break; } } ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="text-center">
                            <input type="hidden" name="term_id" value="<?php echo $term_id; ?>">
                            <input type="hidden" name="edit_grade_id" value="<?php echo $edit_grade_id; ?>">
                            <input type="hidden" name="update_grade" value="1">
                            <button type="submit" class="bg-gradient-to-r from-orange-600 to-orange-800 text-white py-2 px-6 rounded-lg hover:from-orange-700 hover:to-orange-900 transition duration-200 shadow-md">
                                Update Grade
                            </button>
                            <a href="?student_id=<?php echo urlencode($student_id); ?>&class_id=<?php echo urlencode($class_id); ?>&term_id=<?php echo urlencode($term_id); ?>" class="ml-4 text-gray-600 hover:text-gray-800 transition duration-200">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <footer class="bg-gray-800 text-white p-4 mt-auto">
        <div class="container mx-auto text-center">
            <p>© <?php echo date('Y'); ?> QPMS. All rights reserved.</p>
        </div>
    </footer>

    <script>
        function updateGrade(input, subjectId) {
            const mark = parseFloat(input.value) || 0;
            let grade = 'N/A';
            if (mark >= 90) grade = 'A+';
            else if (mark >= 85) grade = 'A';
            else if (mark >= 80) grade = 'A-';
            else if (mark >= 75) grade = 'B+';
            else if (mark >= 70) grade = 'B';
            else if (mark >= 65) grade = 'B-';
            else if (mark >= 60) grade = 'C+';
            else if (mark >= 55) grade = 'C';
            else if (mark >= 50) grade = 'C-';
            else if (mark >= 45) grade = 'D+';
            else if (mark >= 40) grade = 'D';
            else if (mark < 40) grade = 'F';
            document.getElementById('grade_' + subjectId).textContent = mark ? grade : 'N/A';
        }

        function toggleAttitudeOther(select) {
            const otherInput = document.getElementById('attitude_other');
            if (select.value === 'other') {
                otherInput.classList.remove('hidden');
                otherInput.focus();
            } else {
                otherInput.classList.add('hidden');
                otherInput.value = '';
            }
        }

        function toggleInterestOther(select) {
            const otherInput = document.getElementById('interest_other');
            if (select.value === 'other') {
                otherInput.classList.remove('hidden');
                otherInput.focus();
            } else {
                otherInput.classList.add('hidden');
                otherInput.value = '';
            }
        }

        function validateGradeForm() {
            const inputs = document.querySelectorAll('#gradeForm input[type="number"]');
            for (let input of inputs) {
                const value = parseFloat(input.value);
                if (input.value !== '' && (isNaN(value) || value < 0 || value > 100)) {
                    alert('Grades must be between 0 and 100.');
                    input.focus();
                    return false;
                }
            }
            return true;
        }

        function validateEditGradeForm() {
            const input = document.querySelector('#editGradeForm input[type="number"]');
            const value = parseFloat(input.value);
            if (input.value === '' || isNaN(value) || value < 0 || value > 100) {
                alert('Grade must be between 0 and 100.');
                input.focus();
                return false;
            }
            return true;
        }

        function validateReportForm() {
            const attendance = document.querySelector('input[name="attendance"]');
            const conduct = document.querySelector('select[name="conduct"]');
            const attitude = document.querySelector('select[name="attitude"]');
            const interest = document.querySelector('select[name="interest"]');
            const attitudeOther = document.querySelector('input[name="attitude_other"]');
            const interestOther = document.querySelector('input[name="interest_other"]');
            const reopeningDate = document.querySelector('input[name="reopening_date"]');
            const teacherRemarks = document.querySelector('textarea[name="teacher_remarks"]');
            const headmasterRemarks = document.querySelector('textarea[name="headmaster_remarks"]');
            const today = new Date().toISOString().split('T')[0];

            if (attendance.value !== '' && (parseFloat(attendance.value) < 0 || parseFloat(attendance.value) > <?php echo $total_days; ?>)) {
                alert('Attendance must be a number between 0 and <?php echo $total_days; ?>.');
                attendance.focus();
                return false;
            }
            if (!conduct.value) {
                alert('Please select a conduct option.');
                conduct.focus();
                return false;
            }
            if (!attitude.value || (attitude.value === 'other' && !attitudeOther.value)) {
                alert('Please select an attitude option or enter a custom value.');
                if (attitude.value === 'other') attitudeOther.focus();
                else attitude.focus();
                return false;
            }
            if (!interest.value || (interest.value === 'other' && !interestOther.value)) {
                alert('Please select an interest option or enter a custom value.');
                if (interest.value === 'other') interestOther.focus();
                else interest.focus();
                return false;
            }
            if (reopeningDate.value && reopeningDate.value < today) {
                alert('Reopening date must be a future date.');
                reopeningDate.focus();
                return false;
            }
            if (attitudeOther.value && attitudeOther.value.length > 50) {
                alert('Custom attitude must not exceed 50 characters.');
                attitudeOther.focus();
                return false;
            }
            if (interestOther.value && interestOther.value.length > 50) {
                alert('Custom interest must not exceed 50 characters.');
                interestOther.focus();
                return false;
            }
            if (teacherRemarks.value.length > 500) {
                alert('Teacher remarks must not exceed 500 characters.');
                teacherRemarks.focus();
                return false;
            }
            if (headmasterRemarks.value.length > 500) {
                alert('Headmaster remarks must not exceed 500 characters.');
                headmasterRemarks.focus();
                return false;
            }
            return true;
        }

        // Initialize based on existing data
        document.addEventListener('DOMContentLoaded', function() {
            const attitudeSelect = document.getElementById('attitude_select');
            const interestSelect = document.getElementById('interest_select');
            if (attitudeSelect && attitudeSelect.value === 'other') toggleAttitudeOther(attitudeSelect);
            if (interestSelect && interestSelect.value === 'other') toggleInterestOther(interestSelect);
        });
    </script>
</body>
</html>

<?php
// Ensure the connection is closed at the end of the script
if (isset($conn) && is_object($conn)) {
    close_db_connection($conn);
}
?>