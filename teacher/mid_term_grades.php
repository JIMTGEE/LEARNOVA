<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Access denied. You must be a teacher to access this page.";
    header("Location: /login.php");
    exit();
}

// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);

// Check if database connection is valid
if (!isset($conn) || !($conn instanceof mysqli) || !$conn->ping()) {
    error_log("Database connection failed or not established");
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Dynamically determine base URL based on current script
$base_url = dirname($_SERVER['SCRIPT_NAME']) === '/' ? '/' : dirname($_SERVER['SCRIPT_NAME']) . '/';
error_log("Base URL set to: $base_url");

// Verify target file exists for debugging
$target_file = $_SERVER['DOCUMENT_ROOT'] . $base_url . 'mid_term_grades.php';
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

// Function to calculate letter grade and comment
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
    $_SESSION['error_msg'] = "Invalid parameters.";
    error_log("Invalid parameters: student_id=$student_id, class_id=$class_id, term_id=$term_id");
    header("Location: {$base_url}submit_grades.php");
    exit();
}

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

// Fetch term details
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
$term_name = $stmt->fetch() ? "$term_name ($year)" : "Unknown Term";
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
    $_SESSION['error_msg'] = "No class or department found for this student.";
    error_log("No class/department for student_id=$student_id, class_id=$class_id, term_id=$term_id");
    header("Location: {$base_url}submit_grades.php");
    exit();
}

// Fetch all subjects for the class or department
$subjects = [];
$stmt = $conn->prepare("
    SELECT s.id AS subject_id, s.subject_name, s.subject_code 
    FROM subjects s 
    WHERE s.department_id = ? AND s.status = 'Active' 
    UNION 
    SELECT s.id AS subject_id, s.subject_name, s.subject_code 
    FROM subjects s 
    JOIN classes c ON s.id = c.subject_id 
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

// Handle form submission for grade entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subject_id']) && isset($_POST['grade'])) {
    $success = false;

    foreach ($_POST['subject_id'] as $subject_id) {
        $grade = trim($_POST['grade'][$subject_id]);
        if ($grade === '') {
            continue; // Skip empty grades
        }

        $grade = floatval($grade);
        if ($grade < 0 || $grade > 100) {
            $_SESSION['error_msg'] = "Grade must be between 0 and 100.";
            error_log("Invalid grade value: $grade for subject_id=$subject_id");
            break;
        } else {
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare("
                    INSERT INTO grades (user_id, class_id, subject_id, term_id, mid_term_exam, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE mid_term_exam = ?, updated_at = NOW()
                ");
                if (!$stmt) {
                    throw new Exception("Prepare failed: " . $conn->error);
                }
                $stmt->bind_param("iiiidd", $student_id, $class_data['class_id'], $subject_id, $term_id, $grade, $grade);
                $stmt->execute();
                $stmt->close();

                $conn->commit();
                $success = true;
            } catch (Exception $e) {
                $conn->rollback();
                error_log("Grade submission error: " . $e->getMessage());
                $_SESSION['error_msg'] = "Failed to submit grade: " . $e->getMessage();
                break;
            }
        }
    }

    if ($success) {
        $_SESSION['success_msg'] = "Grades submitted successfully.";
        $redirect_url = "{$base_url}mid_term_grades.php?student_id=" . urlencode($student_id) . "&class_id=" . urlencode($class_id) . "&term_id=" . urlencode($term_id);
        error_log("Redirecting to: $redirect_url");
        header("Location: $redirect_url");
        exit();
    } else {
        $_SESSION['error_msg'] = $_SESSION['error_msg'] ?? "No valid grades submitted.";
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

        $stmt = $conn->prepare("DELETE FROM student_reports WHERE user_id = ? AND class_id = ? AND term_id = ?");
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $stmt->bind_param("iii", $student_id, $class_data['class_id'], $term_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        $_SESSION['success_msg'] = "Grade deleted successfully.";
        $redirect_url = "{$base_url}mid_term_grades.php?student_id=" . urlencode($student_id) . "&class_id=" . urlencode($class_id) . "&term_id=" . urlencode($term_id);
        error_log("Redirecting to: $redirect_url");
        header("Location: $redirect_url");
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Grade deletion error: " . $e->getMessage());
        $_SESSION['error_msg'] = "Failed to delete grade: " . $e->getMessage();
    }
}

// Handle grade edit (fetch existing grade for editing)
$edit_grade_id = isset($_GET['edit_grade_id']) ? intval($_GET['edit_grade_id']) : 0;
$edit_grade = null;
if ($edit_grade_id) {
    $stmt = $conn->prepare("SELECT mid_term_exam FROM grades WHERE id = ? AND user_id = ? AND class_id = ? AND term_id = ?");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to fetch grade for editing.";
        header("Location: {$base_url}submit_grades.php");
        exit();
    }
    $stmt->bind_param("iiii", $edit_grade_id, $student_id, $class_data['class_id'], $term_id);
    $stmt->execute();
    $stmt->bind_result($mid_term_exam);
    $edit_grade = $stmt->fetch() ? floatval($mid_term_exam) : null;
    $stmt->close();
}

// Fetch existing grades and auto-calculate comments
$existing_grades = [];
foreach ($subjects as $subject) {
    $stmt = $conn->prepare("SELECT id, mid_term_exam FROM grades WHERE user_id = ? AND class_id = ? AND subject_id = ? AND term_id = ?");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to fetch existing grades.";
        header("Location: {$base_url}submit_grades.php");
        exit();
    }
    $stmt->bind_param("iiii", $student_id, $class_data['class_id'], $subject['subject_id'], $term_id);
    $stmt->execute();
    $stmt->bind_result($id, $mid_term_exam);
    if ($stmt->fetch() && $mid_term_exam !== null) {
        $existing_grades[$subject['subject_id']] = ['id' => $id, 'mark' => floatval($mid_term_exam)];
    } else {
        $existing_grades[$subject['subject_id']] = null;
    }
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mid-Term Grades - QPMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">
    <header class="bg-gradient-to-r from-orange-500/80 to-orange-700/80 text-white p-4 shadow-md backdrop-blur-sm">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-bold">Mid-Term Grades</h1>
            <nav>
                <a href="<?php echo htmlspecialchars($base_url . 'submit_grades.php?term_id=' . urlencode($term_id)); ?>" class="hover:underline">Back to Grade Selection</a>
            </nav>
        </div>
    </header>

    <main class="container mx-auto p-6 flex-grow">
        <div class="bg-white rounded-lg shadow-lg p-6 max-w-4xl mx-auto">
            <h2 class="text-xl font-semibold mb-4 text-gray-800">
                Mid-Term Grade Profile - <?php echo htmlspecialchars($student_name); ?> [<?php echo htmlspecialchars($class_data['class_name']); ?>]
            </h2>
            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="bg-red-100 text-red-700 p-3 rounded-lg mb-4"><?php echo htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?></div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success_msg'])): ?>
                <div class="bg-green-100 text-green-700 p-3 rounded-lg mb-4"><?php echo htmlspecialchars($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?></div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-4">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-green-500 text-white">
                        <tr>
                            <th class="p-2">Subject/Course</th>
                            <th class="p-2">Mark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $entry_date = date('Y-m-d H:i:s', time());
                        foreach ($subjects as $subject) {
                            $subject_id = $subject['subject_id'];
                            $mark = $existing_grades[$subject_id] !== null ? $existing_grades[$subject_id]['mark'] : '';
                            $grade_info = $mark !== '' && $mark !== null ? calculateLetterGrade($mark) : ['grade' => 'N/A', 'comment' => 'N/A'];
                        ?>
                        <tr class="border-t">
                            <td class="p-2"><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                            <td class="p-2">
                                <input type="number" name="grade[<?php echo $subject_id; ?>]" value="<?php echo htmlspecialchars($mark); ?>" 
                                       class="w-20 p-1 border border-gray-300 rounded focus:ring-2 focus:ring-orange-500 focus:outline-none"
                                       onchange="updateGrade(this, '<?php echo $subject_id; ?>')">
                                <span id="grade_<?php echo $subject_id; ?>" class="ml-2"><?php echo htmlspecialchars($grade_info['grade']); ?></span>
                                <input type="hidden" name="subject_id[<?php echo $subject_id; ?>]" value="<?php echo $subject_id; ?>">
                                <input type="hidden" name="class_id" value="<?php echo $class_data['class_id']; ?>">
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
                <div class="text-center">
                    <input type="hidden" name="term_id" value="<?php echo $term_id; ?>">
                    <button type="submit" class="bg-gradient-to-r from-orange-500/80 to-orange-700/80 text-white p-2 rounded-lg hover:from-orange-600/80 hover:to-orange-800/80 transition duration-200">
                        Save Grade
                    </button>
                </div>
            </form>

            <!-- Display Grades Section -->
            <h3 class="text-lg font-semibold mt-6 mb-4 text-gray-700"><?php echo htmlspecialchars($term_name); ?> Mid-Term Grades</h3>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-green-500 text-white">
                        <tr>
                            <th class="p-2">Entry Date</th>
                            <th class="p-2">Course Name</th>
                            <th class="p-2">Mark %</th>
                            <th class="p-2">Grade</th>
                            <th class="p-2">Exams</th>
                            <th class="p-2">Comment</th>
                            <th class="p-2">Inputter</th>
                            <th class="p-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        foreach ($subjects as $subject) {
                            $subject_id = $subject['subject_id'];
                            if ($existing_grades[$subject_id] && $existing_grades[$subject_id]['mark'] !== null && $existing_grades[$subject_id]['mark'] !== '') {
                                $grade_id = $existing_grades[$subject_id]['id'];
                                $mark = $existing_grades[$subject_id]['mark'];
                                $grade_info = calculateLetterGrade($mark);
                        ?>
                        <tr class="border-t">
                            <td class="p-2"><?php echo htmlspecialchars($entry_date); ?></td>
                            <td class="p-2"><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                            <td class="p-2"><?php echo htmlspecialchars($mark); ?></td>
                            <td class="p-2"><?php echo htmlspecialchars($grade_info['grade']); ?></td>
                            <td class="p-2">Mid-Term Examination [<?php echo htmlspecialchars($term_name); ?>]</td>
                            <td class="p-2"><?php echo htmlspecialchars($grade_info['comment']); ?></td>
                            <td class="p-2"><?php echo htmlspecialchars($_SESSION['name']); ?></td>
                            <td class="p-2">
                                <form method="POST" action="" style="display:inline;">
                                    <input type="hidden" name="delete_grade" value="1">
                                    <input type="hidden" name="grade_id" value="<?php echo $grade_id; ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700" onclick="return confirm('Are you sure you want to delete this grade?');">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                                <a href="?student_id=<?php echo urlencode($student_id); ?>&class_id=<?php echo urlencode($class_id); ?>&term_id=<?php echo urlencode($term_id); ?>&edit_grade_id=<?php echo urlencode($grade_id); ?>" class="text-blue-500 hover:text-blue-700 ml-2">
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
            <?php if ($edit_grade_id): ?>
                <div class="mt-6">
                    <h3 class="text-lg font-semibold mb-4 text-gray-700">Edit Mid-Term Grade</h3>
                    <form method="POST" action="" class="space-y-4">
                        <input type="hidden" name="edit_grade_id" value="<?php echo $edit_grade_id; ?>">
                        <table class="w-full text-left border-collapse">
                            <thead class="bg-green-500 text-white">
                                <tr>
                                    <th class="p-2">Subject/Course</th>
                                    <th class="p-2">Mark</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subjects as $subject) {
                                    if ($existing_grades[$subject['subject_id']] && $existing_grades[$subject['subject_id']]['id'] == $edit_grade_id) {
                                        $subject_id = $subject['subject_id'];
                                        $mark = $edit_grade !== null ? $edit_grade : '';
                                        $grade_info = $mark !== '' && $mark !== null ? calculateLetterGrade($mark) : ['grade' => 'N/A', 'comment' => 'N/A'];
                                ?>
                                <tr class="border-t">
                                    <td class="p-2"><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                    <td class="p-2">
                                        <input type="number" name="grade[<?php echo $subject_id; ?>]" value="<?php echo htmlspecialchars($mark); ?>" 
                                               class="w-20 p-1 border border-gray-300 rounded focus:ring-2 focus:ring-orange-500 focus:outline-none"
                                               onchange="updateGrade(this, '<?php echo $subject_id; ?>')">
                                        <span id="grade_<?php echo $subject_id; ?>" class="ml-2"><?php echo htmlspecialchars($grade_info['grade']); ?></span>
                                        <input type="hidden" name="subject_id[<?php echo $subject_id; ?>]" value="<?php echo $subject_id; ?>">
                                        <input type="hidden" name="class_id" value="<?php echo $class_data['class_id']; ?>">
                                    </td>
                                </tr>
                                <?php break; } } ?>
                            </tbody>
                        </table>
                        <div class="text-center">
                            <input type="hidden" name="term_id" value="<?php echo $term_id; ?>">
                            <button type="submit" class="bg-gradient-to-r from-orange-500/80 to-orange-700/80 text-white p-2 rounded-lg hover:from-orange-600/80 hover:to-orange-800/80 transition duration-200">
                                Update Grade
                            </button>
                            <a href="?student_id=<?php echo urlencode($student_id); ?>&class_id=<?php echo urlencode($class_id); ?>&term_id=<?php echo urlencode($term_id); ?>" class="ml-4 text-gray-500 hover:text-gray-700">Cancel</a>
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
    </script>
</body>
</html>

<?php
$conn->close();
?>