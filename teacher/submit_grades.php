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

// Validate database connection
if (!isset($conn) || !($conn instanceof mysqli) || !$conn->ping()) {
    error_log("Database connection failed or not established");
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Fetch teacher_id
$teacher_id = $_SESSION['user_id'];

// Fetch academic terms
$terms_query = "SELECT id, term_name, year FROM academic_calendar ORDER BY start_date DESC";
$terms_result = $conn->query($terms_query);
if (!$terms_result) {
    error_log("Terms query failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch terms.";
    header("Location: /login.php");
    exit();
}

// Fetch all classes
$classes_query = "SELECT id, class_name FROM classes ORDER BY class_name";
$classes_result = $conn->query($classes_query);
if (!$classes_result) {
    error_log("Classes query failed: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch classes.";
    header("Location: /login.php");
    exit();
}

// Validate selected term_id
$selected_term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
if ($selected_term_id) {
    $stmt = $conn->prepare("SELECT id, year FROM academic_calendar WHERE id = ?");
    $stmt->bind_param("i", $selected_term_id);
    $stmt->execute();
    $stmt->bind_result($term_id, $academic_year);
    if (!$stmt->fetch()) {
        error_log("Invalid term_id: $selected_term_id");
        $_SESSION['error_msg'] = "Invalid academic term selected.";
        $selected_term_id = 0;
    }
    $stmt->close();
}

// Fetch students for the selected class
$students_result = null;
$selected_class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
$selected_student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
if ($selected_class_id && $selected_term_id) {
    $students_query = "
        SELECT DISTINCT u.id, u.name, u.email 
        FROM users u
        INNER JOIN student_classes sc ON u.id = sc.user_id
        INNER JOIN classes c ON sc.class_id = c.id
        WHERE u.role = 'student' AND c.id = ?
        ORDER BY u.name
    ";
    $stmt = $conn->prepare($students_query);
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to fetch students.";
        header("Location: /login.php");
        exit();
    }
    $stmt->bind_param("i", $selected_class_id);
    $stmt->execute();
    $students_result = $stmt->get_result();
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Grades - QPMS</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">
    <!-- Header -->
    <header class="bg-gradient-to-r from-orange-500/80 to-orange-700/80 text-white p-4 shadow-md backdrop-blur-sm">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-bold">Submit Student Grades</h1>
            <nav>
                <a href="/teacher/dashboard.php" class="hover:underline">Back to Dashboard</a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto p-6 flex-grow">
        <div class="bg-white rounded-lg shadow-lg p-6 max-w-2xl mx-auto">
            <h2 class="text-xl font-semibold mb-4 text-gray-800">Select a Term, Class, and Student</h2>
            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                    <?php echo htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?>
                </div>
            <?php endif; ?>
            <form id="studentForm" method="POST" action="" class="mb-6">
                <div class="relative mb-4">
                    <select name="term_id" id="termSelect" 
                            class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:outline-none appearance-none"
                            onchange="this.form.submit()">
                        <option value="">-- Select a Term --</option>
                        <?php
                        if ($terms_result->num_rows > 0) {
                            while ($row = $terms_result->fetch_assoc()) {
                                $selected = ($row['id'] == $selected_term_id) ? 'selected' : '';
                                echo "<option value='{$row['id']}' $selected>" . htmlspecialchars($row['term_name'] . ' (' . $row['year'] . ')') . "</option>";
                            }
                        } else {
                            echo "<option value=''>No terms found</option>";
                        }
                        ?>
                    </select>
                    <i class="fas fa-chevron-down absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
                </div>
                <div class="relative mb-4">
                    <select name="class_id" id="classSelect" 
                            class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:outline-none appearance-none"
                            onchange="this.form.submit()">
                        <option value="">-- Select a Class --</option>
                        <?php
                        if ($classes_result->num_rows > 0) {
                            while ($row = $classes_result->fetch_assoc()) {
                                $selected = ($row['id'] == $selected_class_id) ? 'selected' : '';
                                echo "<option value='{$row['id']}' $selected>" . htmlspecialchars($row['class_name']) . "</option>";
                            }
                        } else {
                            echo "<option value=''>No classes found</option>";
                        }
                        ?>
                    </select>
                    <i class="fas fa-chevron-down absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
                </div>
                <?php if ($selected_class_id && $selected_term_id): ?>
                    <div class="relative mb-4">
                        <select name="student_id" id="studentSelect" 
                                class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:outline-none appearance-none">
                            <option value="">-- Select a Student --</option>
                            <?php
                            if ($students_result && $students_result->num_rows > 0) {
                                while ($row = $students_result->fetch_assoc()) {
                                    $selected = ($row['id'] == $selected_student_id) ? 'selected' : '';
                                    $display = $row['name'] ?: $row['email'];
                                    echo "<option value='{$row['id']}' $selected>" . htmlspecialchars($display) . "</option>";
                                }
                            } else {
                                echo "<option value=''>No students found</option>";
                            }
                            ?>
                        </select>
                        <i class="fas fa-chevron-down absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
                    </div>
                    <button type="submit" id="submitBtn"
                            class="mt-4 w-full bg-gradient-to-r from-orange-500/80 to-orange-700/80 text-white p-3 rounded-lg hover:from-orange-600/80 hover:to-orange-800/80 transition duration-200 disabled:bg-gray-400 disabled:cursor-not-allowed"
                            disabled>
                        View Grade Options
                    </button>
                <?php endif; ?>
            </form>

            <!-- Grade Options -->
            <?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['student_id']) && $selected_class_id && $selected_term_id): ?>
                <?php
                $student_id = intval($_POST['student_id']);
                $stmt = $conn->prepare("SELECT name, email FROM users WHERE id = ? AND role = 'student'");
                if (!$stmt) {
                    error_log("Prepare failed: " . $conn->error);
                    $_SESSION['error_msg'] = "System error: Unable to fetch student details.";
                    header("Location: /login.php");
                    exit();
                }
                $stmt->bind_param("i", $student_id);
                $stmt->execute();
                $stmt->bind_result($name, $email);
                if ($stmt->fetch()) {
                    $display_name = $name ?: $email;
                } else {
                    $display_name = "Unknown Student";
                }
                $stmt->close();
                ?>
                <div id="gradeOptions" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <a href="view_report_card.php?student_id=<?php echo $student_id; ?>&class_id=<?php echo $selected_class_id; ?>&term_id=<?php echo $selected_term_id; ?>" 
                       class="bg-green-100 p-6 rounded-lg shadow hover:bg-green-200 transition duration-200 text-center">
                        <i class="fas fa-book text-3xl text-green-600 mb-2"></i>
                        <h3 class="text-lg font-medium text-gray-800">View Report Card</h3>
                    </a>
                    <a href="mid_term_grades.php?student_id=<?php echo $student_id; ?>&class_id=<?php echo $selected_class_id; ?>&term_id=<?php echo $selected_term_id; ?>" 
                       class="bg-yellow-100 p-6 rounded-lg shadow hover:bg-yellow-200 transition duration-200 text-center">
                        <i class="fas fa-clipboard-check text-3xl text-yellow-600 mb-2"></i>
                        <h3 class="text-lg font-medium text-gray-800">Mid-Term Grades</h3>
                    </a>
                    <a href="end_term_grades.php?student_id=<?php echo $student_id; ?>&class_id=<?php echo $selected_class_id; ?>&term_id=<?php echo $selected_term_id; ?>" 
                       class="bg-red-100 p-6 rounded-lg shadow hover:bg-red-200 transition duration-200 text-center">
                        <i class="fas fa-graduation-cap text-3xl text-red-600 mb-2"></i>
                        <h3 class="text-lg font-medium text-gray-800">End Of Term Grades</h3>
                    </a>
                </div>
                <p class="mt-4 text-center text-gray-600">
                    Selected Student: <span class="font-semibold"><?php echo htmlspecialchars($display_name); ?></span>
                </p>
            <?php endif; ?>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white p-4 mt-auto">
        <div class="container mx-auto text-center">
            <p>© <?php echo date('Y'); ?> QPMS. All rights reserved.</p>
        </div>
    </footer>

    <script>
        const termSelect = document.getElementById('termSelect');
        const classSelect = document.getElementById('classSelect');
        const studentSelect = document.getElementById('studentSelect');
        const submitBtn = document.getElementById('submitBtn');
        function updateSubmitButton() {
            if (termSelect && classSelect && studentSelect && submitBtn) {
                if (termSelect.value && classSelect.value && studentSelect.value) {
                    submitBtn.disabled = false;
                    studentSelect.classList.add('text-gray-800');
                    studentSelect.classList.remove('text-gray-400');
                } else {
                    submitBtn.disabled = true;
                    studentSelect.classList.add('text-gray-400');
                    studentSelect.classList.remove('text-gray-800');
                }
            }
        }
        if (termSelect && classSelect && studentSelect && submitBtn) {
            termSelect.addEventListener('change', updateSubmitButton);
            classSelect.addEventListener('change', updateSubmitButton);
            studentSelect.addEventListener('change', updateSubmitButton);
            updateSubmitButton(); // Initial check
        }
    </script>
</body>
</html>

<?php
$conn->close();
?>