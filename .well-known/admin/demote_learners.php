<?php
session_start();

// Restrict to admins only and validate session
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Invalid session: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    header("Location: ../login.php");
    exit();
}

// Include database connection
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage());
    die("System error: Unable to connect to database. Please contact the administrator.");
}

if (!$conn || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
    die("System error: Database connection failed. Please contact the administrator.");
}

// Include logging function
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
} catch (Exception $e) {
    error_log("Failed to include log_activity.php: " . $e->getMessage());
    die("System error: Unable to load logging functions. Please contact the administrator.");
}

// Validate user_id exists in users table
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'admin' AND status = 'active'";
if ($user_check_stmt = $conn->prepare($user_check_sql)) {
    $user_check_stmt->bind_param("i", $_SESSION['user_id']);
    $user_check_stmt->execute();
    $user_check_result = $user_check_stmt->get_result();
    if ($user_check_result->num_rows === 0) {
        error_log("Invalid or inactive admin user_id: " . $_SESSION['user_id']);
        header("Location: ../login.php?error=invalid_user");
        exit();
    }
    $user_check_stmt->close();
} else {
    error_log("Failed to prepare user check query: " . $conn->error);
    die("System error: Unable to validate user. Please contact the administrator.");
}

// Fetch classes with grade levels (assuming class_name contains grade info, e.g., "Grade 1")
$classes_sql = "SELECT id, class_name FROM classes ORDER BY class_name";
$classes_result = $conn->query($classes_sql);
if (!$classes_result) {
    error_log("Error fetching classes: " . $conn->error);
    $error_msg = "Unable to fetch classes. Please try again later.";
    $classes = [];
} else {
    $classes = $classes_result->fetch_all(MYSQLI_ASSOC);
    $classes_result->free();
}

// Fetch all active students
$students_sql = "SELECT id, name FROM users WHERE role = 'student' AND status = 'active' ORDER BY name";
$students_result = $conn->query($students_sql);
if (!$students_result) {
    error_log("Error fetching students: " . $conn->error);
    $error_msg = "Unable to fetch students. Please try again later.";
    $students = [];
} else {
    $students = $students_result->fetch_all(MYSQLI_ASSOC);
    $students_result->free();
}

// Initialize variables
$success_msg = $error_msg = "";

// Validate academic year format
function validateAcademicYear($year) {
    return preg_match('/^\d{4}-\d{4}$/', $year) && (int)substr($year, 0, 4) < (int)substr($year, 5, 4);
}

// Extract grade level from class name (e.g., "Grade 1" -> 1)
function getGradeLevel($class_name) {
    if (preg_match('/Grade\s*(\d+)/i', $class_name, $matches)) {
        return (int)$matches[1];
    }
    return 0; // Default if grade level not found
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['demote_student'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $new_class_id = (int)($_POST['new_class_id'] ?? 0);
    $academic_year = trim($_POST['academic_year'] ?? '');

    // Validate inputs
    if (empty($user_id)) {
        $error_msg = "Please select a student.";
    } elseif (empty($new_class_id)) {
        $error_msg = "Please select a class.";
    } elseif (empty($academic_year) || !validateAcademicYear($academic_year)) {
        $error_msg = "Invalid academic year format. Use YYYY-YYYY (e.g., 2024-2025).";
    } else {
        // Validate student and class existence
        $student_check_sql = "SELECT name FROM users WHERE id = ? AND role = 'student' AND status = 'active'";
        $class_check_sql = "SELECT id, class_name FROM classes WHERE id = ?";
        $student_check_stmt = $conn->prepare($student_check_sql);
        $class_check_stmt = $conn->prepare($class_check_sql);

        if (!$student_check_stmt || !$class_check_stmt) {
            error_log("Failed to prepare validation queries: " . $conn->error);
            $error_msg = "System error: Unable to validate inputs.";
        } else {
            $student_check_stmt->bind_param("i", $user_id);
            $student_check_stmt->execute();
            $student_result = $student_check_stmt->get_result();
            if ($student_result->num_rows === 0) {
                $error_msg = "Selected student is invalid or not active.";
                $student_check_stmt->close();
            } else {
                $student = $student_result->fetch_assoc();
                $student_name = $student['name'];
                $student_check_stmt->close();

                $class_check_stmt->bind_param("i", $new_class_id);
                $class_check_stmt->execute();
                $class_result = $class_check_stmt->get_result();
                if ($class_result->num_rows === 0) {
                    $error_msg = "Selected class is invalid.";
                    $class_check_stmt->close();
                } else {
                    $new_class = $class_result->fetch_assoc();
                    $new_class_name = $new_class['class_name'];
                    $class_check_stmt->close();

                    // Check current assignment
                    $current_assignment_sql = "SELECT sc.class_id, c.class_name 
                                             FROM student_classes sc 
                                             JOIN classes c ON sc.class_id = c.id 
                                             WHERE sc.user_id = ? AND sc.academic_year = ?";
                    if ($current_stmt = $conn->prepare($current_assignment_sql)) {
                        $current_stmt->bind_param("is", $user_id, $academic_year);
                        $current_stmt->execute();
                        $current_result = $current_stmt->get_result();
                        if ($current_result->num_rows > 0) {
                            $current_assignment = $current_result->fetch_assoc();
                            if ($current_assignment['class_id'] == $new_class_id) {
                                $error_msg = "Student $student_name is already assigned to $new_class_name for $academic_year.";
                            } else {
                                $current_grade = getGradeLevel($current_assignment['class_name']);
                                $new_grade = getGradeLevel($new_class_name);
                                if ($new_grade >= $current_grade && $new_grade != 0) {
                                    $error_msg = "Demotion failed: $new_class_name is not a lower grade than the current class (" . $current_assignment['class_name'] . ").";
                                }
                            }
                        }
                        $current_stmt->close();
                    } else {
                        error_log("Failed to prepare current assignment query: " . $conn->error);
                        $error_msg = "System error: Unable to check current assignment.";
                    }

                    if (empty($error_msg)) {
                        $conn->begin_transaction();
                        try {
                            // Insert new assignment
                            $insert_sql = "INSERT INTO student_classes (user_id, class_id, academic_year, created_at) VALUES (?, ?, ?, NOW())";
                            if ($insert_stmt = $conn->prepare($insert_sql)) {
                                $insert_stmt->bind_param("iis", $user_id, $new_class_id, $academic_year);
                                if ($insert_stmt->execute()) {
                                    // Log action
                                    $admin_id = $_SESSION['user_id'];
                                    $action = "Demoted Student";
                                    $description = "Student ID: $user_id, New Class ID: $new_class_id, Academic Year: $academic_year";
                                    logActivity($conn, $admin_id, $action, $description);
                                    $success_msg = "Student $student_name demoted to $new_class_name for $academic_year successfully.";
                                    $_POST = [];
                                } else {
                                    throw new Exception("Failed to insert new assignment: " . $insert_stmt->error);
                                }
                                $insert_stmt->close();
                            } else {
                                throw new Exception("Failed to prepare insert query: " . $conn->error);
                            }

                            $conn->commit();
                        } catch (Exception $e) {
                            $conn->rollback();
                            $error_msg = "Error demoting student: " . $e->getMessage();
                            error_log("Demotion error for user_id $user_id, class_id $new_class_id, academic_year $academic_year: " . $e->getMessage());
                        }
                    }
                }
            }
        }
    }
}

// Include header
try {
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
} catch (Exception $e) {
    error_log("Failed to include admin_header.php: " . $e->getMessage());
    die("System error: Unable to load page header. Please contact the administrator.");
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Demote students to a lower class in Queen's Park Montessori School management system.">
    <meta name="keywords" content="Queen's Park Montessori, student demotion, admin dashboard, school management">
    <meta name="author" content="Queen's Park Montessori School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Queen's Park Montessori Demote Learners">
    <meta property="og:description" content="Admin interface for demoting students to a lower class in Queen's Park Montessori School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/demote_learners.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Queen's Park Montessori Demote Learners">
    <meta name="twitter:description" content="Admin interface for demoting students to a lower class in Queen's Park Montessori School.">
    <title>Demote Learners - Queen's Park Montessori</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            500: '#f97316',
                            600: '#ea580c',
                        },
                        secondary: '#6b7280',
                        accent: '#10b981',
                        background: '#f4f4f4',
                    },
                },
            },
        };
    </script>
    <style>
        [data-theme="dark"] {
            --primary-500: #fb923c;
            --primary-600: #f97316;
            --secondary: #9ca3af;
            --accent: #34d399;
            --background: #1f2937;
            --text: #d1d5db;
        }
        body {
            background-color: var(--background);
            color: var(--text, #1f2937);
        }
        .dark .bg-background { background-color: var(--background); }
        .dark .text-text { color: var(--text); }
        .student-select-modal {
            max-height: 400px;
            overflow-y: auto;
        }
        .student-checkbox:checked + label {
            background-color: var(--primary-500);
            color: white;
        }
    </style>
</head>
<body class="min-h-screen bg-background text-text">
    <div class="content" id="content">
        <main class="container mx-auto p-4">
            <section class="bg-white dark:bg-gray-800 shadow-lg rounded-lg p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-2xl font-bold text-primary-500">📚 Demote Learners</h3>
                    <div class="space-x-4">
                        <a href="/admin/assign_class.php" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>Assign Class
                        </a>
                        <a href="/admin/promote_learners.php" class="bg-accent hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-arrow-up mr-2"></i>Promote Learners
                        </a>
                        <a href="/admin/manage_student_classes.php" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-list mr-2"></i>View Assignments
                        </a>
                    </div>
                </div>
                <?php if (!empty($success_msg)) { ?>
                    <div class="bg-green-100 text-green-800 p-4 rounded-lg mb-4 animate-pulse" role="alert"><?php echo htmlspecialchars($success_msg); ?></div>
                <?php } ?>
                <?php if (!empty($error_msg)) { ?>
                    <div class="bg-red-100 text-red-800 p-4 rounded-lg mb-4 animate-pulse" role="alert"><?php echo htmlspecialchars($error_msg); ?></div>
                <?php } ?>
                <!-- Demote Student Form -->
                <div class="bg-gray-50 dark:bg-gray-700 p-4 rounded-lg mb-6">
                    <h4 class="text-lg font-semibold text-primary-500 mb-4">Demote Student to Lower Class</h4>
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="student_search" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Student</label>
                            <input type="text" id="student_search" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" placeholder="Click to select a student" readonly>
                            <input type="hidden" name="user_id" id="selected_student_id">
                        </div>
                        <div>
                            <label for="new_class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">New Class</label>
                            <select name="new_class_id" id="new_class_id" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class) { ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo isset($_POST['new_class_id']) && $_POST['new_class_id'] == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['class_name']); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label for="academic_year" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Academic Year</label>
                            <input type="text" name="academic_year" id="academic_year" class="mt-1 block w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" placeholder="e.g. 2024-2025" value="<?php echo isset($_POST['academic_year']) ? htmlspecialchars($_POST['academic_year']) : ''; ?>" required>
                        </div>
                        <div class="md:col-span-3 text-center mt-4">
                            <button type="submit" name="demote_student" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg">
                                <i class="fas fa-arrow-down mr-2"></i>Demote Student
                            </button>
                        </div>
                    </form>
                </div>
                <!-- Student Selection Modal -->
                <div id="studentSelectModal" class="fixed inset-0 bg-gray-900 bg-opacity-50 flex items-center justify-center hidden">
                    <div class="bg-white dark:bg-gray-800 rounded-lg p-6 w-full max-w-lg">
                        <h4 class="text-lg font-semibold text-primary-500 mb-4">Select Student</h4>
                        <div class="mb-4">
                            <input type="text" id="student_search_input" class="w-full border border-gray-300 dark:border-gray-600 rounded-lg p-2 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100" placeholder="Search students...">
                        </div>
                        <div class="student-select-modal">
                            <?php if (empty($students)) { ?>
                                <p class="text-gray-500 dark:text-gray-400">No students available</p>
                            <?php } else { ?>
                                <ul class="divide-y divide-gray-200 dark:divide-gray-600">
                                    <?php foreach ($students as $student) { ?>
                                        <li class="py-2">
                                            <input type="radio" id="student_<?php echo $student['id']; ?>" name="student_radio" class="student-radio hidden" value="<?php echo $student['id']; ?>" data-name="<?php echo htmlspecialchars($student['name']); ?>">
                                            <label for="student_<?php echo $student['id']; ?>" class="block cursor-pointer p-2 rounded hover:bg-gray-100 dark:hover:bg-gray-700"><?php echo htmlspecialchars($student['name']); ?></label>
                                        </li>
                                    <?php } ?>
                                </ul>
                            <?php } ?>
                        </div>
                        <div class="flex justify-end space-x-2 mt-4">
                            <button type="button" onclick="closeStudentSelectModal()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">Cancel</button>
                            <button type="button" onclick="saveSelectedStudent()" class="bg-accent hover:bg-green-600 text-white px-4 py-2 rounded-lg">Save Selection</button>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
    <?php 
    try {
        include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    } catch (Exception $e) {
        error_log("Failed to include admin_footer.php: " . $e->getMessage());
        echo "<footer class='text-center py-4 text-gray-500'>System error: Unable to load page footer. Please contact the administrator.</footer>";
    }
    $conn->close();
    ?>
    <button id="theme-toggle" class="fixed bottom-4 right-4 bg-primary-500 hover:bg-primary-600 text-white p-2 rounded-full">
        <i class="fas fa-moon"></i>
    </button>
    <script>
        // Theme toggle
        const toggleButton = document.getElementById('theme-toggle');
        const htmlElement = document.documentElement;
        const currentTheme = localStorage.getItem('theme') || 'light';
        htmlElement.setAttribute('data-theme', currentTheme);
        if (currentTheme === 'dark') {
            toggleButton.innerHTML = '<i class="fas fa-sun"></i>';
        }
        toggleButton.addEventListener('click', () => {
            const newTheme = htmlElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            htmlElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            toggleButton.innerHTML = newTheme === 'light' ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
        });

        // Student selection modal functions
        function openStudentSelectModal() {
            document.getElementById('studentSelectModal').classList.remove('hidden');
            document.getElementById('student_search_input').focus();
        }

        function closeStudentSelectModal() {
            document.getElementById('studentSelectModal').classList.add('hidden');
            document.getElementById('student_search_input').value = '';
            filterStudents('');
        }

        function saveSelectedStudent() {
            const selectedRadio = document.querySelector('.student-radio:checked');
            if (selectedRadio) {
                document.getElementById('selected_student_id').value = selectedRadio.value;
                document.getElementById('student_search').value = selectedRadio.getAttribute('data-name') || 'Click to select a student';
            }
            closeStudentSelectModal();
        }

        function filterStudents(query) {
            const students = document.querySelectorAll('.student-select-modal li');
            students.forEach(student => {
                const name = student.querySelector('label').textContent.toLowerCase();
                student.style.display = name.includes(query.toLowerCase()) ? 'block' : 'none';
            });
        }

        $(document).ready(function() {
            $('#student_search').on('click', openStudentSelectModal);
            $('#student_search_input').on('input', function() {
                filterStudents($(this).val());
            });
        });
    </script>
</body>
</html>