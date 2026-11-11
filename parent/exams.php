<?php
session_start();

// Restrict to parents only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'parent') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    header("Location: /parent/login.php");
    exit();
}

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', '/tmp/php_errors.log');

// Include database connection and functions
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    http_response_code(500);
    die("System error: Database configuration not found.");
}
require_once($db_path);

$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    error_log("Log activity file missing: $log_path");
}
require_once($log_path);

if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: 'Connection not established'));
    http_response_code(500);
    die("System error: Database connection failed.");
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Clean expired CSRF tokens
$conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");

// Fetch current academic term
if (!isset($_SESSION['current_term'])) {
    $term_sql = "SELECT id, term_name, year, start_date, end_date FROM academic_calendar WHERE start_date <= CURDATE() AND end_date >= CURDATE() LIMIT 1";
    $term_result = mysqli_query($conn, $term_sql);
    if (!$term_result) {
        error_log("Error fetching academic term: " . mysqli_error($conn));
        http_response_code(500);
        die("System error: Unable to fetch academic term.");
    }
    $_SESSION['current_term'] = $term_result->num_rows ? mysqli_fetch_assoc($term_result) : ['id' => 0, 'term_name' => 'N/A', 'year' => '2024/2025', 'start_date' => '2024-09-01', 'end_date' => '2025-07-31'];
    mysqli_free_result($term_result);
}
$current_term = $_SESSION['current_term'];
$current_term_id = $current_term['id'];

// Fetch all academic terms
$terms_sql = "SELECT id, term_name, year, start_date, end_date FROM academic_calendar WHERE year = '2024/2025' ORDER BY start_date";
$terms_result = mysqli_query($conn, $terms_sql);
if (!$terms_result) {
    error_log("Error fetching terms: " . mysqli_error($conn));
    http_response_code(500);
    die("System error: Unable to fetch terms.");
}
$terms = [];
while ($term_row = mysqli_fetch_assoc($terms_result)) {
    $terms[] = $term_row;
}
mysqli_free_result($terms_result);

// Handle term selection with CSRF validation
$selected_term_id = isset($_POST['term_id']) && is_numeric($_POST['term_id']) && isset($_POST['csrf_token']) && $_POST['csrf_token'] === $csrf_token ? (int)$_POST['term_id'] : $current_term_id;
if (!in_array($selected_term_id, array_column($terms, 'id'))) {
    $selected_term_id = $current_term_id;
}

// Fetch selected term details
$selected_term_sql = "SELECT term_name, year, start_date, end_date FROM academic_calendar WHERE id = ?";
$stmt = $conn->prepare($selected_term_sql);
$stmt->bind_param("i", $selected_term_id);
if (!$stmt->execute()) {
    error_log("Error fetching selected term: " . $stmt->error);
    http_response_code(500);
    die("System error: Unable to fetch selected term.");
}
$selected_term_result = $stmt->get_result();
$selected_term = $selected_term_result->num_rows ? $selected_term_result->fetch_assoc() : ['term_name' => 'N/A', 'year' => '2024/2025', 'start_date' => '2024-09-01', 'end_date' => '2025-07-31'];
$selected_term_name = $selected_term['term_name'] . ' ' . $selected_term['year'];
$stmt->close();

// Fetch students associated with parent
$parent_id = (int)$_SESSION['user_id'];
$students_sql = "SELECT u.id, u.name, c.class_name, c.academic_year
                 FROM parent_student_mapping psm
                 JOIN users u ON psm.student_id = u.id
                 LEFT JOIN student_classes sc ON u.id = sc.user_id AND sc.academic_year = ?
                 LEFT JOIN classes c ON sc.class_id = c.id
                 WHERE psm.parent_id = ? ORDER BY u.name";
$stmt = $conn->prepare($students_sql);
$stmt->bind_param("si", $selected_term['year'], $parent_id);
if (!$stmt->execute()) {
    error_log("Error fetching students: " . $stmt->error);
    http_response_code(500);
    die("System error: Unable to fetch students.");
}
$students_result = $stmt->get_result();
$students = [];
while ($student_row = $students_result->fetch_assoc()) {
    $students[] = $student_row;
}
$stmt->close();

// Fetch exam schedules for each student
$exams = [];
$total_exams = 0;
$upcoming_exams = 0;
$chart_data = [];
$current_date = date('Y-m-d');
$upcoming_date = date('Y-m-d', strtotime('+7 days'));
foreach ($students as $student) {
    $student_id = (int)$student['id'];
    $exams_sql = "SELECT es.id, es.exam_date, es.start_time, es.end_time, es.room, es.instructions, 
                         s.subject_name, ac.term_name
                  FROM exam_schedules es
                  JOIN classes c ON es.class_id = c.id
                  JOIN subjects s ON es.subject_id = s.id
                  JOIN academic_calendar ac ON es.term_id = ac.id
                  JOIN student_classes sc ON c.id = sc.class_id AND sc.user_id = ? AND sc.academic_year = ?
                  WHERE es.term_id = ?
                  ORDER BY es.exam_date, es.start_time";
    $stmt = $conn->prepare($exams_sql);
    $stmt->bind_param("isi", $student_id, $selected_term['year'], $selected_term_id);
    if (!$stmt->execute()) {
        error_log("Error fetching exams for student $student_id: " . $stmt->error);
        http_response_code(500);
        die("System error: Unable to fetch exams.");
    }
    $result = $stmt->get_result();
    $student_exams = [];
    $subject_counts = [];
    while ($row = $result->fetch_assoc()) {
        $row['title'] = htmlspecialchars($row['subject_name'] . ' Exam - ' . $row['term_name']);
        $student_exams[] = $row;
        $total_exams++;
        if ($row['exam_date'] >= $current_date && $row['exam_date'] <= $upcoming_date) {
            $upcoming_exams++;
        }
        $subject_counts[$row['subject_name']] = ($subject_counts[$row['subject_name']] ?? 0) + 1;
    }
    $exams[$student['id']] = $student_exams;
    $chart_data[$student['id']] = $subject_counts;
    $stmt->close();
}

// Prepare chart data (exams per subject per student)
$chart_labels = [];
$chart_datasets = [];
foreach ($students as $student) {
    $student_id = $student['id'];
    $dataset = [
        'label' => $student['name'],
        'data' => [],
        'backgroundColor' => sprintf('rgba(%d, %d, %d, 0.6)', rand(0, 255), rand(0, 255), rand(0, 255)),
        'borderColor' => sprintf('rgba(%d, %d, %d, 1)', rand(0, 255), rand(0, 255), rand(0, 255)),
        'borderWidth' => 1
    ];
    $subjects = array_keys($chart_data[$student_id]);
    $dataset['data'] = array_values($chart_data[$student_id]);
    $chart_datasets[] = $dataset;
    if (empty($chart_labels)) {
        $chart_labels = $subjects;
    }
}

// Log access
if (function_exists('log_activity')) {
    log_activity($parent_id, 'Accessed Exam Schedules Page', "Parent viewed exam schedules for term: $selected_term_name", $conn);
} else {
    error_log("log_activity function undefined in exam.php; logging access for parent_id: $parent_id, term: $selected_term_name");
}

// Close database connection
mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View exam schedules for students in QueensPa School.">
    <meta name="keywords" content="QueensPa School, parent dashboard, student exams, schedules">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Parent Exam Schedules">
    <meta property="og:description" content="Parent interface for viewing student exam schedules.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/parent/exam.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Parent Exam Schedules">
    <meta name="twitter:description" content="Parent interface for viewing student exam schedules.">
    <title>Exam Schedules - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js" defer></script>
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #6c757d;
            --accent-color: #ffc107;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background: var(--bg-color);
            color: var(--text-color);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .container {
            max-width: 1400px;
            padding: 2rem;
        }
        .card {
            border: none;
            border-radius: 12px;
            background: #fff;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 1.5rem;
        }
        .card-header h3 {
            margin: 0;
            font-size: 1.8rem;
        }
        .btn-primary, .btn-print {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-primary:hover, .btn-print:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .table thead {
            background: var(--primary-color);
            color: white;
        }
        .table-hover tbody tr:hover {
            background-color: #f5f5f5;
        }
        .form-select {
            border-radius: 8px;
            padding: 0.75rem;
        }
        .print-section {
            display: none;
        }
        .exam-section {
            margin-top: 1.5rem;
        }
        .exam-section h5 {
            color: var(--primary-color);
        }
        .upcoming-exam {
            background-color: #fff3cd;
        }
        @media (max-width: 767.98px) {
            .container {
                padding: 1rem;
            }
            .table th, .table td {
                font-size: 0.9rem;
            }
        }
        @media print {
            body * {
                visibility: hidden;
            }
            .print-section, .print-section * {
                visibility: visible;
                display: block;
            }
            .print-section {
                position: static;
                width: 100%;
                padding: 0;
                color: #000;
                font-family: Arial, sans-serif;
            }
            .print-header {
                text-align: center;
                margin-bottom: 20px;
                border-bottom: 2px solid var(--primary-color);
                padding-bottom: 10px;
            }
            .print-header img {
                max-width: 120px;
                margin-bottom: 10px;
            }
            .print-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }
            .print-table th, .print-table td {
                border: 1px solid #ddd;
                padding: 8px;
                text-align: left;
            }
            .print-table th {
                background: var(--primary-color);
                color: white;
            }
            .print-footer {
                text-align: center;
                margin-top: 20px;
                font-size: 0.8rem;
                color: var(--secondary-color);
            }
            @page {
                size: A4;
                margin: 15mm;
            }
        }
    </style>
</head>
<body>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/parent_header.php'; ?>
    <div class="content" id="content">
        <main class="container my-4">
            <section class="card mb-4 animate__animated animate__fadeIn">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="mb-0">📅 Exam Schedules</h3>
                        <a href="/parent/dashboard.php" class="btn btn-primary"><i class="fas fa-home"></i> Home</a>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Term Selection -->
                    <form method="POST" class="mb-4">
                        <div class="mb-3">
                            <label for="term_id" class="form-label">Select Term</label>
                            <select name="term_id" id="term_id" class="form-select" onchange="this.form.submit()" aria-label="Select term">
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?php echo $term['id']; ?>" <?php echo $selected_term_id == $term['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($term['term_name'] . ' ' . $term['year']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    </form>

                    <!-- Metrics -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <h5 class="card-title">Total Students</h5>
                                    <p class="card-text text-primary fs-3"><?php echo count($students); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <h5 class="card-title">Total Exams</h5>
                                    <p class="card-text text-primary fs-3"><?php echo $total_exams; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <h5 class="card-title">Upcoming Exams</h5>
                                    <p class="card-text text-primary fs-3"><?php echo $upcoming_exams; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Exams by Student -->
                    <?php foreach ($students as $student): ?>
                        <h4 class="mb-3"><?php echo htmlspecialchars($student['name']); ?> - Exam Schedules</h4>
                        <p><strong>Class:</strong> <?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?> | <strong>Academic Year:</strong> <?php echo htmlspecialchars($student['academic_year'] ?? $selected_term['year']); ?></p>
                        
                        <!-- Exams Table -->
                        <div class="exam-section">
                            <h5>Exam Schedule</h5>
                            <div class="table-responsive mb-4">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Title</th>
                                            <th>Subject</th>
                                            <th>Date & Time</th>
                                            <th>Location</th>
                                            <th>Instructions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($exams[$student['id']])): ?>
                                            <tr><td colspan="5" class="text-center">No exams found for this term.</td></tr>
                                        <?php else: ?>
                                            <?php foreach ($exams[$student['id']] as $exam): ?>
                                                <tr class="<?php echo ($exam['exam_date'] >= $current_date && $exam['exam_date'] <= $upcoming_date) ? 'upcoming-exam' : ''; ?>">
                                                    <td><?php echo htmlspecialchars($exam['title']); ?></td>
                                                    <td><?php echo htmlspecialchars($exam['subject_name']); ?></td>
                                                    <td>
                                                        <?php 
                                                        $date_time = $exam['exam_date'] ? date('d M Y', strtotime($exam['exam_date'])) : 'Not set';
                                                        if ($exam['start_time'] && $exam['end_time']) {
                                                            $date_time .= ' (' . date('H:i', strtotime($exam['start_time'])) . ' - ' . date('H:i', strtotime($exam['end_time'])) . ')';
                                                        }
                                                        echo $date_time;
                                                        ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($exam['room'] ?? 'N/A'); ?></td>
                                                    <td><?php echo htmlspecialchars($exam['instructions'] ?? 'N/A'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Exam Chart -->
                    <h4 class="mb-3">Exams by Subject</h4>
                    <div class="card mb-4">
                        <div class="card-body">
                            <canvas id="examChart" style="max-height: 300px;"></canvas>
                        </div>
                    </div>

                    <!-- Print Section -->
                    <div class="print-section">
                        <div class="print-header">
                            <?php
                            $logo_path = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/logo.png';
                            if (file_exists($logo_path)) {
                                echo '<img src="/assets/images/logo.png" alt="QueensPa School Logo">';
                            } else {
                                echo '<div style="font-size: 1rem; color: #555;">[School Logo]</div>';
                            }
                            ?>
                            <h1>QueensPa School</h1>
                            <p>Student Exam Schedule Report - <?php echo htmlspecialchars($selected_term_name); ?> - <?php echo date('F j, Y'); ?></p>
                        </div>
                        <?php foreach ($students as $student): ?>
                            <h4><?php echo htmlspecialchars($student['name']); ?></h4>
                            <p><strong>Class:</strong> <?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?> | <strong>Academic Year:</strong> <?php echo htmlspecialchars($student['academic_year'] ?? $selected_term['year']); ?></p>
                            <h5>Exam Schedule</h5>
                            <table class="print-table">
                                <thead>
                                    <tr>
                                        <th>Title</th>
                                        <th>Subject</th>
                                        <th>Date & Time</th>
                                        <th>Location</th>
                                        <th>Instructions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($exams[$student['id']])): ?>
                                        <tr><td colspan="5" class="text-center">No exams found for this term.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($exams[$student['id']] as $exam): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($exam['title']); ?></td>
                                                <td><?php echo htmlspecialchars($exam['subject_name']); ?></td>
                                                <td>
                                                    <?php 
                                                    $date_time = $exam['exam_date'] ? date('d M Y', strtotime($exam['exam_date'])) : 'Not set';
                                                    if ($exam['start_time'] && $exam['end_time']) {
                                                        $date_time .= ' (' . date('H:i', strtotime($exam['start_time'])) . ' - ' . date('H:i', strtotime($exam['end_time'])) . ')';
                                                    }
                                                    echo $date_time;
                                                    ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($exam['room'] ?? 'N/A'); ?></td>
                                                <td><?php echo htmlspecialchars($exam['instructions'] ?? 'N/A'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        <?php endforeach; ?>
                        <h4 class="mt-4">Summary</h4>
                        <table class="print-table">
                            <tr><th>Total Students</th><td><?php echo count($students); ?></td></tr>
                            <tr><th>Total Exams</th><td><?php echo $total_exams; ?></td></tr>
                            <tr><th>Upcoming Exams</th><td><?php echo $upcoming_exams; ?></td></tr>
                        </table>
                        <div class="print-footer">
                            <p>Generated by QueensPa School Management System</p>
                        </div>
                    </div>
                    <button class="btn btn-print mb-4 no-print" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
                </div>
            </section>
        </main>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/parent_footer.php'; ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
    <script>
        // Chart.js for Exams by Subject
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('examChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: <?php echo json_encode($chart_datasets); ?>
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Number of Exams' }
                        },
                        x: {
                            title: { display: true, text: 'Subjects' }
                        }
                    },
                    plugins: {
                        legend: { position: 'top' },
                        title: {
                            display: true,
                            text: 'Exams by Subject per Student (<?php echo htmlspecialchars($selected_term_name); ?>)'
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>