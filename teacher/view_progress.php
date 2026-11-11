```php
<?php
// Disable error reporting for production
error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 60);
ini_set('post_max_size', '16M');
ini_set('upload_max_filesize', '16M');

// Start output buffering and session
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
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

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Validate user_id
$teacher_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'teacher'";
$user_check_stmt = $conn->prepare($user_check_sql);
if (!$user_check_stmt) {
    error_log("Prepare failed for user check: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}
$user_check_stmt->bind_param("i", $teacher_id);
$user_check_stmt->execute();
if ($user_check_stmt->get_result()->num_rows === 0) {
    error_log("Invalid teacher_id: $teacher_id");
    $_SESSION['error_msg'] = "Invalid user session.";
    header("Location: /login.php");
    exit();
}
$user_check_stmt->close();

// CSRF Token Management
function generateCsrfToken($conn, $user_id) {
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $insert_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)";
    $stmt = $conn->prepare($insert_sql);
    if ($stmt) {
        $stmt->bind_param("iss", $user_id, $token, $expires_at);
        if ($stmt->execute()) {
            $stmt->close();
            return $token;
        }
        $stmt->close();
    }
    error_log("Failed to generate CSRF token for user_id $user_id");
    return bin2hex(random_bytes(32)); // Fallback
}

function validateCsrfToken($conn, $user_id, $token) {
    if (empty($token)) return false;
    $sql = "SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $user_id, $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $valid = $result->num_rows > 0;
        if ($valid) {
            $delete_sql = "DELETE FROM csrf_tokens WHERE user_id = ? AND token = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            if ($delete_stmt) {
                $delete_stmt->bind_param("is", $user_id, $token);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
        }
        $stmt->close();
        return $valid;
    }
    error_log("CSRF validation failed for user_id $user_id");
    return false;
}

// Generate CSRF token for GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['csrf_token'] = generateCsrfToken($conn, $teacher_id);
}

// Prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Handle AJAX requests
if (isset($_GET['action']) || isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? $_POST['action'];

    if ($action === 'get_subjects') {
        $subjects_query = "SELECT DISTINCT s.id, s.subject_name 
                          FROM subjects s 
                          JOIN academic_progress_entries ape ON s.id = ape.subject_id 
                          WHERE ape.teacher_id = ? 
                          ORDER BY s.subject_name ASC";
        $stmt = $conn->prepare($subjects_query);
        if (!$stmt) {
            error_log("Prepare failed for get_subjects: " . $conn->error);
            echo json_encode(['success' => false, 'error' => 'Database query error']);
            exit();
        }
        $stmt->bind_param("i", $teacher_id);
        if (!$stmt->execute()) {
            error_log("Execute failed for get_subjects: " . $stmt->error);
            echo json_encode(['success' => false, 'error' => 'Database execution error']);
            $stmt->close();
            exit();
        }
        $result = $stmt->get_result();
        $subjects = [];
        while ($row = $result->fetch_assoc()) {
            $subjects[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'subjects' => $subjects]);
        exit();
    } elseif ($action === 'get_classes') {
        $classes_sql = "SELECT id, class_name 
                        FROM classes 
                        WHERE teacher_id = ? 
                        ORDER BY class_name ASC";
        $stmt = $conn->prepare($classes_sql);
        if (!$stmt) {
            error_log("Prepare failed for get_classes: " . $conn->error);
            echo json_encode(['success' => false, 'error' => 'Database query error']);
            exit();
        }
        $stmt->bind_param("i", $teacher_id);
        if (!$stmt->execute()) {
            error_log("Execute failed for get_classes: " . $stmt->error);
            echo json_encode(['success' => false, 'error' => 'Database execution error']);
            $stmt->close();
            exit();
        }
        $result = $stmt->get_result();
        $classes = [];
        while ($row = $result->fetch_assoc()) {
            $classes[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'classes' => $classes]);
        exit();
    } elseif ($action === 'get_terms') {
        $terms_sql = "SELECT id, term_name, year, start_date, end_date 
                      FROM academic_calendar 
                      ORDER BY year DESC, start_date DESC";
        $result = $conn->query($terms_sql);
        if (!$result) {
            error_log("Query failed for get_terms: " . $conn->error);
            echo json_encode(['success' => false, 'error' => 'Database query error']);
            exit();
        }
        $terms = [];
        while ($row = $result->fetch_assoc()) {
            $terms[] = $row;
        }
        echo json_encode(['success' => true, 'terms' => $terms]);
        exit();
    } elseif ($action === 'get_students' && isset($_GET['class_id'])) {
        $class_id = intval($_GET['class_id']);
        $students_sql = "SELECT DISTINCT u.id, u.name 
                         FROM users u 
                         JOIN student_classes sc ON u.id = sc.user_id 
                         WHERE sc.class_id = ? AND u.role = 'student' 
                         ORDER BY u.name ASC";
        $stmt = $conn->prepare($students_sql);
        if (!$stmt) {
            error_log("Prepare failed for get_students: " . $conn->error);
            echo json_encode(['success' => false, 'error' => 'Database query error']);
            exit();
        }
        $stmt->bind_param("i", $class_id);
        if (!$stmt->execute()) {
            error_log("Execute failed for get_students: " . $stmt->error);
            echo json_encode(['success' => false, 'error' => 'Database execution error']);
            $stmt->close();
            exit();
        }
        $result = $stmt->get_result();
        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'students' => $students]);
        exit();
    } elseif ($action === 'get_periods' && isset($_GET['term_id']) && isset($_GET['entry_period'])) {
        $term_id = intval($_GET['term_id']);
        $entry_period = $_GET['entry_period'];
        $periods_query = "SELECT DISTINCT period_start_date, period_end_date 
                          FROM academic_progress_entries 
                          WHERE term_id = ? AND entry_period = ? AND teacher_id = ? 
                          ORDER BY period_start_date";
        $stmt = $conn->prepare($periods_query);
        if (!$stmt) {
            error_log("Prepare failed for get_periods: " . $conn->error);
            echo json_encode(['success' => false, 'error' => 'Database query error']);
            exit();
        }
        $stmt->bind_param("isi", $term_id, $entry_period, $teacher_id);
        if (!$stmt->execute()) {
            error_log("Execute failed for get_periods: " . $stmt->error);
            echo json_encode(['success' => false, 'error' => 'Database execution error']);
            $stmt->close();
            exit();
        }
        $result = $stmt->get_result();
        $periods = [];
        $index = 1;
        while ($row = $result->fetch_assoc()) {
            $periods[] = [
                'start' => $row['period_start_date'],
                'end' => $row['period_end_date'],
                'label' => $entry_period === 'weekly' ? "Week $index" : "Bi-Week $index"
            ];
            $index++;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'periods' => $periods]);
        exit();
    } elseif ($action === 'get_subject_progress' && isset($_GET['subject_id']) && isset($_GET['entry_period'])) {
        $subject_id = intval($_GET['subject_id']);
        $entry_period = $_GET['entry_period'];
        $query = "SELECT u.name AS student_name, ape.total_scaled, ape.period_start_date 
                  FROM academic_progress_entries ape 
                  JOIN users u ON ape.student_id = u.id 
                  WHERE ape.subject_id = ? AND ape.teacher_id = ? AND ape.entry_period = ? 
                  ORDER BY ape.period_start_date, u.name";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("Prepare failed for get_subject_progress: " . $conn->error);
            echo json_encode(['success' => false, 'error' => 'Database query error']);
            exit();
        }
        $stmt->bind_param("iis", $subject_id, $teacher_id, $entry_period);
        if (!$stmt->execute()) {
            error_log("Execute failed for get_subject_progress: " . $stmt->error);
            echo json_encode(['success' => false, 'error' => 'Database execution error']);
            $stmt->close();
            exit();
        }
        $result = $stmt->get_result();
        $progress = [];
        while ($row = $result->fetch_assoc()) {
            $progress[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'progress' => $progress]);
        exit();
    } elseif ($action === 'get_filtered_progress') {
        $class_id = intval($_POST['class_id']);
        $term_id = intval($_POST['term_id']);
        $entry_period = $_POST['entry_period'];
        $period_start = $_POST['period_start'];
        $period_end = $_POST['period_end'];
        $student_id = intval($_POST['student_id'] ?? 0);

        // Validate CSRF token for POST
        $csrf_token = $_POST['csrf_token'] ?? '';
        if (!validateCsrfToken($conn, $teacher_id, $csrf_token)) {
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit();
        }

        $query = "SELECT u.name AS student_name, s.subject_name, ape.total_scaled 
                  FROM academic_progress_entries ape 
                  JOIN users u ON ape.student_id = u.id 
                  JOIN subjects s ON ape.subject_id = s.id 
                  WHERE ape.class_id = ? AND ape.term_id = ? AND ape.teacher_id = ? 
                  AND ape.entry_period = ? AND ape.period_start_date = ? AND ape.period_end_date = ?";
        $params = "iiisss";
        $values = [$class_id, $term_id, $teacher_id, $entry_period, $period_start, $period_end];

        if ($student_id > 0) {
            $query .= " AND ape.student_id = ?";
            $params .= "i";
            $values[] = $student_id;
        }

        $query .= " ORDER BY u.name, s.subject_name";
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            error_log("Prepare failed for get_filtered_progress: " . $conn->error);
            echo json_encode(['success' => false, 'error' => 'Database query error']);
            exit();
        }
        $stmt->bind_param($params, ...$values);
        if (!$stmt->execute()) {
            error_log("Execute failed for get_filtered_progress: " . $stmt->error);
            echo json_encode(['success' => false, 'error' => 'Database execution error']);
            $stmt->close();
            exit();
        }
        $result = $stmt->get_result();
        $progress = [];
        while ($row = $result->fetch_assoc()) {
            $progress[] = $row;
        }
        $stmt->close();
        echo json_encode(['success' => true, 'progress' => $progress]);
        exit();
    }
    echo json_encode(['success' => false, 'error' => 'Invalid action']);
    exit();
}

// Fetch initial data
$subjects_sql = "SELECT DISTINCT s.id, s.subject_name 
                 FROM subjects s 
                 JOIN academic_progress_entries ape ON s.id = ape.subject_id 
                 WHERE ape.teacher_id = ? 
                 ORDER BY s.subject_name ASC";
$subjects_stmt = $conn->prepare($subjects_sql);
if ($subjects_stmt) {
    $subjects_stmt->bind_param("i", $teacher_id);
    $subjects_stmt->execute();
    $subjects_result = $subjects_stmt->get_result();
    $subjects = [];
    while ($row = $subjects_result->fetch_assoc()) {
        $subjects[] = $row;
    }
    $subjects_stmt->close();
} else {
    $subjects = [];
}

// Include header
include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Teacher progress view for QueensPa School Management System">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>View Progress - QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(to bottom right, #1a202c, #2d3748);
            color: #e5e7eb;
            min-height: 100vh;
        }
        .glassmorphism {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .glassmorphism:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
        }
        .dark .glassmorphism {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        h1, h2, h3 { 
            font-family: 'Orbitron', sans-serif; 
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }
        .form-select {
            background-color: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: #ffffff;
            border-radius: 8px;
            padding: 0.5rem;
            transition: border-color 0.3s, box-shadow 0.3s;
        }
        .form-select:focus {
            border-color: #f97316;
            box-shadow: 0 0 0 0.25rem rgba(249, 115, 22, 0.25);
            background-color: rgba(255, 255, 255, 0.15);
        }
        .form-select option {
            background-color: #1a202c;
            color: #ffffff;
        }
        .error-message {
            color: #ef4444;
            font-size: 0.875rem;
            margin-top: 0.25rem;
            animation: fadeIn 0.5s ease;
        }
        .chart-container {
            position: relative;
            height: 300px;
            width: 100%;
            margin: 1rem 0;
        }
        ::-webkit-scrollbar { 
            width: 8px; 
        }
        ::-webkit-scrollbar-track { 
            background: #1f2937; 
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb { 
            background: #f97316; 
            border-radius: 4px; 
        }
        ::-webkit-scrollbar-thumb:hover { 
            background: #ea580c; 
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-zoom-in {
            animation: zoomIn 0.5s ease forwards;
        }
        @keyframes zoomIn {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
        .mode-button {
            transition: all 0.3s ease;
        }
        .mode-button.active {
            background-color: #f97316;
            transform: scale(1.05);
        }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-900 to-orange-900 dark:bg-gradient-to-br dark:from-gray-900 dark:to-orange-900">
    <div class="content">
        <main class="container mx-auto px-4 py-8 max-w-7xl">
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold text-white"><i class="fas fa-chart-line mr-2"></i> View Progress</h1>
                <button class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg glassmorphism" id="theme-toggle">
                    <i class="fas fa-moon mr-2"></i> Toggle Theme
                </button>
            </div>
            
            <!-- Add Progress Buttons -->
            <section class="glassmorphism rounded-2xl shadow-lg mb-8 animate-zoom-in p-6" aria-labelledby="add-progress-heading">
                <h3 id="add-progress-heading" class="text-xl font-semibold mb-4 text-white">Add New Progress</h3>
                <div class="flex flex-wrap gap-4">
                    <a href="input_academic_scores.php?mode=scores" id="scores-mode-btn" class="mode-button bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-all duration-300 flex items-center justify-center glassmorphism <?= ($_SESSION['grading_mode'] ?? 'scores') === 'scores' ? 'active' : '' ?>" aria-label="Add Scores">
                        <i class="fas fa-calculator mr-2"></i> Use Scores
                    </a>
                    <a href="input_academic_comments.php?mode=comments" id="comments-mode-btn" class="mode-button bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium transition-all duration-300 flex items-center justify-center glassmorphism <?= ($_SESSION['grading_mode'] ?? 'scores') === 'comments' ? 'active' : '' ?>" aria-label="Add Comments">
                        <i class="fas fa-comment-alt mr-2"></i> Use Comments
                    </a>
                    <a href="input_mock_scores.php" class="mode-button bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-medium transition-all duration-300 flex items-center justify-center glassmorphism" aria-label="Add Mock Scores (Majesty & Makeda 2 only)">
                        <i class="fas fa-graduation-cap mr-2"></i> Mock Scores
                    </a>
                </div>
                <p class="text-sm text-gray-300 mt-2">Mock Scores are available only for Majesty and Makeda 2 classes, covering BECE-style subjects and grading.</p>
            </section>

            <!-- Filter Section -->
            <section class="glassmorphism rounded-2xl p-6 mb-8" aria-labelledby="filter-heading">
                <h3 id="filter-heading" class="text-xl font-semibold mb-4 text-white">Filters</h3>
                <form id="filter-form" class="grid md:grid-cols-5 gap-4">
                    <div>
                        <label for="subject_id_line" class="block text-sm font-medium text-gray-300">Subject (Line Graph)</label>
                        <select id="subject_id_line" class="form-select w-full" aria-describedby="subject_id_line_error">
                            <option value="">Select Subject</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?= htmlspecialchars($subject['id']) ?>"><?= htmlspecialchars($subject['subject_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p id="subject_id_line_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="entry_period_line" class="block text-sm font-medium text-gray-300">Period Type (Line Graph)</label>
                        <select id="entry_period_line" class="form-select w-full" disabled aria-describedby="entry_period_line_error">
                            <option value="">Select Period Type</option>
                            <option value="weekly">Weekly</option>
                            <option value="bi-weekly">Bi-Weekly</option>
                        </select>
                        <p id="entry_period_line_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="class_id" class="block text-sm font-medium text-gray-300">Class</label>
                        <select id="class_id" class="form-select w-full" aria-describedby="class_id_error">
                            <option value="">Select Class</option>
                        </select>
                        <p id="class_id_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="term_id" class="block text-sm font-medium text-gray-300">Term</label>
                        <select id="term_id" class="form-select w-full" aria-describedby="term_id_error">
                            <option value="">Select Term</option>
                        </select>
                        <p id="term_id_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="entry_period" class="block text-sm font-medium text-gray-300">Entry Period</label>
                        <select id="entry_period" class="form-select w-full" disabled aria-describedby="entry_period_error">
                            <option value="">Select Entry Period</option>
                            <option value="weekly">Weekly</option>
                            <option value="bi-weekly">Bi-Weekly</option>
                        </select>
                        <p id="entry_period_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="period" class="block text-sm font-medium text-gray-300">Exact Period</label>
                        <select id="period" class="form-select w-full" disabled aria-describedby="period_error">
                            <option value="">Select Exact Period</option>
                        </select>
                        <p id="period_error" class="error-message hidden"></p>
                    </div>
                    <div>
                        <label for="student_id" class="block text-sm font-medium text-gray-300">Student</label>
                        <select id="student_id" class="form-select w-full" disabled aria-describedby="student_id_error">
                            <option value="0">All Students</option>
                        </select>
                        <p id="student_id_error" class="error-message hidden"></p>
                    </div>
                    <input type="hidden" id="csrf_token" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                </form>
            </section>

            <!-- Graphs Section -->
            <section class="glassmorphism rounded-2xl p-6 mb-8" aria-labelledby="graphs-heading">
                <h3 id="graphs-heading" class="text-xl font-semibold mb-4 text-white">Progress Graphs</h3>
                <div class="chart-container mb-6" id="line-graph-container">
                    <canvas id="line-chart" aria-label="Subject progress over time"></canvas>
                </div>
                <div class="chart-container hidden" id="bar-graph-container">
                    <canvas id="bar-chart" aria-label="Filtered progress"></canvas>
                </div>
                <div id="progress-message" class="text-center text-gray-300"></div>
            </section>
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <?php mysqli_close($conn); ob_end_flush(); ?>

    <script>
        // Theme toggle
        const themeToggle = document.getElementById('theme-toggle');
        if (localStorage.getItem('theme') === 'dark') {
            document.body.classList.add('dark');
            themeToggle.innerHTML = '<i class="fas fa-sun mr-2"></i> Toggle Theme';
        }
        themeToggle.addEventListener('click', () => {
            document.body.classList.toggle('dark');
            const isDark = document.body.classList.contains('dark');
            localStorage.setItem('theme', isDark ? 'dark' : 'light');
            themeToggle.innerHTML = isDark ? '<i class="fas fa-sun mr-2"></i> Toggle Theme' : '<i class="fas fa-moon mr-2"></i> Toggle Theme';
        });

        // Form elements
        const subjectIdLineInput = document.getElementById('subject_id_line');
        const entryPeriodLineInput = document.getElementById('entry_period_line');
        const classIdInput = document.getElementById('class_id');
        const termIdInput = document.getElementById('term_id');
        const entryPeriodInput = document.getElementById('entry_period');
        const periodInput = document.getElementById('period');
        const studentIdInput = document.getElementById('student_id');
        const csrfTokenInput = document.getElementById('csrf_token');
        const lineGraphContainer = document.getElementById('line-graph-container');
        const barGraphContainer = document.getElementById('bar-graph-container');
        const progressMessage = document.getElementById('progress-message');
        let lineChart = null;
        let barChart = null;

        // Load initial classes and terms
        Promise.all([
            fetch('?action=get_classes').then(res => res.json()).catch(() => ({success: false, error: 'Network error'})),
            fetch('?action=get_terms').then(res => res.json()).catch(() => ({success: false, error: 'Network error'}))
        ]).then(([classesData, termsData]) => {
            if (!classesData.success) {
                showError('progress-message', classesData.error || 'Failed to load classes');
            } else if (classesData.classes && classesData.classes.length > 0) {
                classesData.classes.forEach(cls => {
                    const option = document.createElement('option');
                    option.value = cls.id;
                    option.textContent = cls.class_name;
                    classIdInput.appendChild(option);
                });
            } else {
                showError('progress-message', 'No classes found for this teacher');
            }
            if (!termsData.success) {
                showError('progress-message', termsData.error || 'Failed to load terms');
            } else if (termsData.terms && termsData.terms.length > 0) {
                termsData.terms.forEach(term => {
                    const option = document.createElement('option');
                    option.value = term.id;
                    option.textContent = `${term.term_name} (${term.year})`;
                    termIdInput.appendChild(option);
                });
            } else {
                showError('progress-message', 'No terms found');
            }
        }).catch(() => showError('progress-message', 'Error loading initial data'));

        // Load students on class change
        classIdInput.addEventListener('change', () => {
            hideError('class_id_error');
            studentIdInput.innerHTML = '<option value="0">All Students</option>';
            studentIdInput.disabled = true;
            if (classIdInput.value) {
                showLoading('progress-message');
                fetch(`?action=get_students&class_id=${encodeURIComponent(classIdInput.value)}`)
                    .then(res => res.json())
                    .then(data => {
                        hideLoading('progress-message');
                        if (data.success) {
                            data.students.forEach(student => {
                                const option = document.createElement('option');
                                option.value = student.id;
                                option.textContent = student.name;
                                studentIdInput.appendChild(option);
                            });
                            studentIdInput.disabled = data.students.length === 0;
                        } else {
                            showError('student_id_error', data.error || 'Failed to load students');
                        }
                    })
                    .catch(() => {
                        hideLoading('progress-message');
                        showError('student_id_error', 'Error loading students');
                    });
            }
            updateBarChart();
        });

        // Enable entry period on term change
        termIdInput.addEventListener('change', () => {
            hideError('term_id_error');
            entryPeriodInput.disabled = !termIdInput.value;
            periodInput.innerHTML = '<option value="">Select Exact Period</option>';
            periodInput.disabled = true;
            updateBarChart();
        });

        // Load periods on entry period change
        entryPeriodInput.addEventListener('change', () => {
            hideError('entry_period_error');
            periodInput.innerHTML = '<option value="">Select Exact Period</option>';
            periodInput.disabled = true;
            if (termIdInput.value && entryPeriodInput.value) {
                showLoading('progress-message');
                fetch(`?action=get_periods&term_id=${encodeURIComponent(termIdInput.value)}&entry_period=${encodeURIComponent(entryPeriodInput.value)}`)
                    .then(res => res.json())
                    .then(data => {
                        hideLoading('progress-message');
                        if (data.success) {
                            data.periods.forEach(period => {
                                const option = document.createElement('option');
                                option.value = JSON.stringify(period);
                                option.textContent = `${period.label}: ${period.start} to ${period.end}`;
                                periodInput.appendChild(option);
                            });
                            periodInput.disabled = data.periods.length === 0;
                        } else {
                            showError('period_error', data.error || 'Failed to load periods');
                        }
                    })
                    .catch(() => {
                        hideLoading('progress-message');
                        showError('period_error', 'Error loading periods');
                    });
            }
            updateBarChart();
        });

        // Update bar chart on period or student change
        periodInput.addEventListener('change', () => {
            hideError('period_error');
            updateBarChart();
        });
        studentIdInput.addEventListener('change', () => {
            hideError('student_id_error');
            updateBarChart();
        });

        // Enable period type on subject selection for line chart
        subjectIdLineInput.addEventListener('change', () => {
            hideError('subject_id_line_error');
            entryPeriodLineInput.disabled = !subjectIdLineInput.value;
            updateLineChart();
        });

        // Update line chart on period type change
        entryPeriodLineInput.addEventListener('change', () => {
            hideError('entry_period_line_error');
            updateLineChart();
        });

        // Update line chart
        function updateLineChart() {
            const subjectId = subjectIdLineInput.value;
            const entryPeriod = entryPeriodLineInput.value;
            if (!subjectId || !entryPeriod) {
                lineGraphContainer.classList.add('hidden');
                progressMessage.textContent = subjectId ? 'Select period type to view line graph' : 'Select a subject to view line graph';
                if (lineChart) lineChart.destroy();
                return;
            }

            showLoading('progress-message');
            fetch(`?action=get_subject_progress&subject_id=${encodeURIComponent(subjectId)}&entry_period=${encodeURIComponent(entryPeriod)}`)
                .then(res => res.json())
                .then(data => {
                    hideLoading('progress-message');
                    if (data.success && data.progress.length > 0) {
                        lineGraphContainer.classList.remove('hidden');
                        renderLineChart(data.progress);
                        progressMessage.textContent = '';
                    } else {
                        lineGraphContainer.classList.add('hidden');
                        progressMessage.textContent = 'No progress data for selected subject and period';
                        if (lineChart) lineChart.destroy();
                    }
                })
                .catch(() => {
                    hideLoading('progress-message');
                    showError('progress-message', 'Error loading line chart data');
                    lineGraphContainer.classList.add('hidden');
                });
        }

        // Render line chart
        function renderLineChart(progress) {
            const ctx = document.getElementById('line-chart').getContext('2d');
            if (lineChart) lineChart.destroy();

            const students = [...new Set(progress.map(p => p.student_name))];
            const periods = [...new Set(progress.map(p => p.period_start_date))].sort();
            const datasets = students.map((student, index) => ({
                label: student,
                data: periods.map(period => {
                    const entry = progress.find(p => p.student_name === student && p.period_start_date === period);
                    return entry ? parseFloat(entry.total_scaled) : 0;
                }),
                borderColor: getRandomColor(index),
                backgroundColor: getRandomColor(index) + '33',
                fill: false,
                tension: 0.4
            }));

            // Class average
            datasets.push({
                label: 'Class Average',
                data: periods.map(period => {
                    const entries = progress.filter(p => p.period_start_date === period);
                    const sum = entries.reduce((acc, p) => acc + parseFloat(p.total_scaled), 0);
                    return entries.length ? sum / entries.length : 0;
                }),
                borderColor: '#ffffff',
                backgroundColor: '#ffffff33',
                borderDash: [5, 5],
                fill: false
            });

            // Class highest
            datasets.push({
                label: 'Class Highest',
                data: periods.map(period => {
                    const scores = progress.filter(p => p.period_start_date === period).map(p => parseFloat(p.total_scaled));
                    return scores.length ? Math.max(...scores) : 0;
                }),
                borderColor: '#ffd700',
                backgroundColor: '#ffd70033',
                fill: false
            });

            lineChart = new Chart(ctx, {
                type: 'line',
                data: { labels: periods.map(d => new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })), datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        title: { display: true, text: 'Subject Progress Over Time', color: '#ffffff' }
                    },
                    scales: {
                        y: { beginAtZero: true, max: 100, title: { display: true, text: 'Scaled Score (%)', color: '#ffffff' } },
                        x: { title: { display: true, text: 'Period', color: '#ffffff' } }
                    }
                }
            });
        }

        // Update bar chart
        function updateBarChart() {
            const classId = classIdInput.value;
            const termId = termIdInput.value;
            const entryPeriod = entryPeriodInput.value;
            const periodJson = periodInput.value;
            const studentId = studentIdInput.value;

            if (!classId || !termId || !entryPeriod || !periodJson) {
                barGraphContainer.classList.add('hidden');
                if (!subjectIdLineInput.value) {
                    progressMessage.textContent = 'Select filters to view bar graph';
                }
                if (barChart) barChart.destroy();
                return;
            }

            const period = JSON.parse(periodJson);
            showLoading('progress-message');
            const formData = new FormData();
            formData.append('action', 'get_filtered_progress');
            formData.append('class_id', classId);
            formData.append('term_id', termId);
            formData.append('entry_period', entryPeriod);
            formData.append('period_start', period.start);
            formData.append('period_end', period.end);
            formData.append('student_id', studentId);
            formData.append('csrf_token', csrfTokenInput.value);

            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(res => res.json())
                .then(data => {
                    hideLoading('progress-message');
                    if (data.success && data.progress.length > 0) {
                        barGraphContainer.classList.remove('hidden');
                        renderBarChart(data.progress, studentId);
                        progressMessage.textContent = '';
                    } else {
                        barGraphContainer.classList.add('hidden');
                        progressMessage.textContent = 'No progress data for selected filters';
                        if (barChart) barChart.destroy();
                    }
                })
                .catch(() => {
                    hideLoading('progress-message');
                    showError('progress-message', 'Error loading bar chart data');
                    barGraphContainer.classList.add('hidden');
                });
        }

        // Render bar chart
        function renderBarChart(progress, studentId) {
            const ctx = document.getElementById('bar-chart').getContext('2d');
            if (barChart) barChart.destroy();

            const subjects = [...new Set(progress.map(p => p.subject_name))];
            let labels, datasets;

            if (studentId !== '0') {
                // Individual learner: bars for each subject
                labels = subjects;
                datasets = [{
                    label: 'Student Score',
                    data: subjects.map(subject => {
                        const entry = progress.find(p => p.subject_name === subject);
                        return entry ? parseFloat(entry.total_scaled) : 0;
                    }),
                    backgroundColor: '#f9731699',
                    borderColor: '#f97316',
                    borderWidth: 1,
                    barThickness: 30
                }];
            } else {
                // Whole class: bars for each learner, grouped by subject
                const students = [...new Set(progress.map(p => p.student_name))];
                labels = students;
                datasets = subjects.map((subject, index) => ({
                    label: subject,
                    data: students.map(student => {
                        const entry = progress.find(p => p.student_name == student && p.subject_name === subject);
                        return entry ? parseFloat(entry.total_scaled) : 0;
                    }),
                    backgroundColor: getRandomColor(index) + '99',
                    borderColor: getRandomColor(index),
                    borderWidth: 1,
                    barThickness: 30
                }));
            }

            barChart = new Chart(ctx, {
                type: 'bar',
                data: { labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top' },
                        title: { 
                            display: true, 
                            text: studentId !== '0' ? 'Student Progress by Subject' : 'Class Progress by Learner', 
                            color: '#ffffff' 
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, max: 100, title: { display: true, text: 'Scaled Score (%)', color: '#ffffff' } },
                        x: { title: { display: true, text: studentId !== '0' ? 'Subject' : 'Learner', color: '#ffffff' } }
                    }
                }
            });
        }

        // Get random color
        function getRandomColor(index) {
            const colors = ['#f97316', '#1e3a8a', '#dc2626', '#16a34a', '#a21caf', '#eab308', '#0284c7'];
            return colors[index % colors.length];
        }

        // Loading and error handling
        function showLoading(elementId) {
            const element = document.getElementById(elementId);
            element.innerHTML = '<div class="flex justify-center"><span class="inline-block w-6 h-6 border-2 border-white border-t-orange-500 rounded-full animate-spin"></span></div>';
        }

        function hideLoading(elementId) {
            const element = document.getElementById(elementId);
            if (element.innerHTML.includes('animate-spin')) {
                element.innerHTML = '';
            }
        }

        function showError(elementId, message) {
            const element = document.getElementById(elementId);
            element.innerHTML = `<p class="error-message">${message}</p>`;
            element.classList.remove('hidden');
            element.focus();
        }

        function hideError(elementId) {
            const element = document.getElementById(elementId);
            element.classList.add('hidden');
            element.innerHTML = '';
        }

        // Handle grading mode buttons
        const scoresModeBtn = document.getElementById('scores-mode-btn');
        const commentsModeBtn = document.getElementById('comments-mode-btn');
        let gradingMode = '<?= $_SESSION['grading_mode'] ?? 'scores' ?>';

        scoresModeBtn.addEventListener('click', () => {
            gradingMode = 'scores';
            scoresModeBtn.classList.add('active');
            commentsModeBtn.classList.remove('active');
            window.location.href = 'input_academic_scores.php?mode=scores';
        });

        commentsModeBtn.addEventListener('click', () => {
            gradingMode = 'comments';
            commentsModeBtn.classList.add('active');
            scoresModeBtn.classList.remove('active');
            window.location.href = 'input_academic_comments.php?mode=comments';
        });
    </script>
</body>
</html>
```