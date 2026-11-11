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
    $_SESSION['current_term'] = $term_result->num_rows ? mysqli_fetch_assoc($term_result) : ['id' => 0, 'term_name' => 'N/A', 'year' => '2024/2025'];
    mysqli_free_result($term_result);
}
$current_term = $_SESSION['current_term'];
$current_term_id = $current_term['id'];

// Fetch all academic terms for selection
$terms_sql = "SELECT id, term_name, year FROM academic_calendar ORDER BY start_date DESC";
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

// Handle term selection
$selected_term_id = isset($_POST['term_id']) && is_numeric($_POST['term_id']) ? (int)$_POST['term_id'] : $current_term_id;
if ($selected_term_id == 0) {
    $selected_term_id = $current_term_id;
}

// Fetch selected term details
$selected_term_sql = "SELECT term_name, year FROM academic_calendar WHERE id = " . (int)$selected_term_id;
$selected_term_result = mysqli_query($conn, $selected_term_sql);
if (!$selected_term_result) {
    error_log("Error fetching selected term: " . mysqli_error($conn));
    http_response_code(500);
    die("System error: Unable to fetch selected term.");
}
$selected_term = mysqli_fetch_assoc($selected_term_result) ?: ['term_name' => 'N/A', 'year' => '2024/2025'];
mysqli_free_result($selected_term_result);

// Fetch students associated with parent
$parent_id = (int)$_SESSION['user_id'];
$students_sql = "SELECT u.id, u.name, c.class_name, c.academic_year
                 FROM parent_student_mapping psm
                 JOIN users u ON psm.student_id = u.id
                 LEFT JOIN student_classes sc ON u.id = sc.user_id AND sc.academic_year = '" . mysqli_real_escape_string($conn, $selected_term['year']) . "'
                 LEFT JOIN classes c ON sc.class_id = c.id
                 WHERE psm.parent_id = $parent_id ORDER BY u.name";
$students_result = mysqli_query($conn, $students_sql);
if (!$students_result) {
    error_log("Error fetching students: " . mysqli_error($conn));
    http_response_code(500);
    die("System error: Unable to fetch students.");
}
$students = [];
while ($student_row = mysqli_fetch_assoc($students_result)) {
    $students[] = $student_row;
}
mysqli_free_result($students_result);

// Fetch grades and report data for all students
$grades = [];
$reports = [];
$average_grades = [];
$total_subjects = [];
foreach ($students as $student) {
    $student_id = (int)$student['id'];
    $grades_sql = "SELECT g.id, g.user_id, g.score, g.mid_term_exam, g.end_term_exam, g.total, g.comment, s.subject_name, s.subject_code, a.term_name, a.year
                   FROM grades g
                   JOIN subjects s ON g.subject_id = s.id
                   JOIN academic_calendar a ON g.term_id = a.id
                   WHERE g.user_id = $student_id AND g.term_id = " . (int)$selected_term_id . "
                   ORDER BY s.subject_name";
    error_log("Grades query for student $student_id: $grades_sql"); // Debug query
    $grades_result = mysqli_query($conn, $grades_sql);
    if (!$grades_result) {
        error_log("Error fetching grades: " . mysqli_error($conn) . " | Query: $grades_sql");
        http_response_code(500);
        die("System error: Unable to fetch grades.");
    }
    $student_grades = [];
    while ($grade_row = mysqli_fetch_assoc($grades_result)) {
        $student_grades[] = $grade_row;
    }
    mysqli_free_result($grades_result);

    // Fetch report data from student_reports
    $report_sql = "SELECT sr.attendance, sr.conduct, sr.attitude, sr.interest, sr.recommended_subjects, sr.encouragement, sr.reopening_date, sr.teacher_remarks, sr.headmaster_remarks, sr.promoted_to_class_id, c.class_name AS promoted_class
                   FROM student_reports sr
                   LEFT JOIN classes c ON sr.promoted_to_class_id = c.id
                   WHERE sr.user_id = $student_id AND sr.term_id = " . (int)$selected_term_id;
    error_log("Report query for student $student_id: $report_sql"); // Debug query
    $report_result = mysqli_query($conn, $report_sql);
    if (!$report_result) {
        error_log("Error fetching report: " . mysqli_error($conn) . " | Query: $report_sql");
        http_response_code(500);
        die("System error: Unable to fetch report.");
    }
    $report = mysqli_fetch_assoc($report_result) ?: [
        'attendance' => null, 'conduct' => null, 'attitude' => null, 'interest' => null,
        'recommended_subjects' => null, 'encouragement' => null, 'reopening_date' => null,
        'teacher_remarks' => null, 'headmaster_remarks' => null, 'promoted_class' => null
    ];
    mysqli_free_result($report_result);

    // Store grades and report
    $grades[$student['id']] = $student_grades;
    $reports[$student['id']] = $report;
    $total_subjects[$student['id']] = count($student_grades);
    $average_grades[$student['id']] = $total_subjects[$student['id']] > 0
        ? array_sum(array_column($student_grades, 'total')) / $total_subjects[$student['id']]
        : 0;
}

// Prepare chart data
$chart_data = [];
foreach ($students as $student) {
    $student_id = $student['id'];
    $subjects_sql = "SELECT s.subject_name, g.total
                     FROM grades g
                     JOIN subjects s ON g.subject_id = s.id
                     WHERE g.user_id = $student_id AND g.term_id = " . (int)$selected_term_id . "
                     ORDER BY s.subject_name";
    $subjects_result = mysqli_query($conn, $subjects_sql);
    if (!$subjects_result) {
        error_log("Error fetching chart data: " . mysqli_error($conn));
        continue;
    }
    $subjects = [];
    $grades_values = [];
    while ($row = mysqli_fetch_assoc($subjects_result)) {
        $subjects[] = $row['subject_name'];
        $grades_values[] = (float)$row['total'];
    }
    mysqli_free_result($subjects_result);
    $chart_data[$student['id']] = [
        'labels' => $subjects,
        'grades' => $grades_values,
        'student_name' => $student['name']
    ];
}

// Log access
if (function_exists('log_activity')) {
    log_activity($parent_id, 'Accessed Reports Page', "Parent viewed report cards for term: {$selected_term['term_name']} {$selected_term['year']}", $conn);
} else {
    error_log("log_activity function undefined in reports.php; logging access for parent_id: $parent_id, term: {$selected_term['term_name']} {$selected_term['year']}");
}

// Close database connection
mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View report cards for students in QueensPa School.">
    <meta name="keywords" content="QueensPa School, parent dashboard, report card, grades">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Parent Reports">
    <meta property="og:description" content="Parent interface for viewing student report cards and academic performance.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/parent/reports.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Parent Reports">
    <meta name="twitter:description" content="Parent interface for viewing student report cards and academic performance.">
    <title>Report Cards - QueensPa School</title>
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
        .grade-excellent { color: var(--success-color); font-weight: bold; }
        .grade-good { color: #17a2b8; }
        .grade-average { color: var(--accent-color); }
        .grade-poor { color: var(--danger-color); }
        .report-section { margin-top: 1.5rem; }
        .report-section h5 { color: var(--primary-color); }
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
                        <h3 class="mb-0">📊 Report Cards</h3>
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
                                    <h5 class="card-title">Average Grade</h5>
                                    <p class="card-text text-primary fs-3">
                                        <?php
                                        $overall_avg = count($average_grades) > 0 ? array_sum($average_grades) / count($average_grades) : 0;
                                        echo number_format($overall_avg, 2);
                                        ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <h5 class="card-title">Total Subjects</h5>
                                    <p class="card-text text-primary fs-3"><?php echo array_sum($total_subjects); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Grades and Report by Student -->
                    <?php foreach ($students as $student): ?>
                        <h4 class="mb-3"><?php echo htmlspecialchars($student['name']); ?> - Report Card</h4>
                        <p><strong>Class:</strong> <?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?> | <strong>Academic Year:</strong> <?php echo htmlspecialchars($student['academic_year'] ?? $selected_term['year']); ?></p>
                        
                        <!-- Grades Table -->
                        <h5 class="mb-3">Academic Performance</h5>
                        <div class="table-responsive mb-4">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Subject Code</th>
                                        <th>Mid-Term</th>
                                        <th>End-Term</th>
                                        <th>Total</th>
                                        <th>Comment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($grades[$student['id']])): ?>
                                        <tr><td colspan="6" class="text-center">No grades available for this term.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($grades[$student['id']] as $grade): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($grade['subject_name']); ?></td>
                                                <td><?php echo htmlspecialchars($grade['subject_code']); ?></td>
                                                <td class="<?php
                                                    if ($grade['mid_term_exam'] >= 80) echo 'grade-excellent';
                                                    elseif ($grade['mid_term_exam'] >= 60) echo 'grade-good';
                                                    elseif ($grade['mid_term_exam'] >= 40) echo 'grade-average';
                                                    else echo 'grade-poor';
                                                ?>">
                                                    <?php echo $grade['mid_term_exam'] !== null ? number_format($grade['mid_term_exam'], 2) : 'N/A'; ?>
                                                </td>
                                                <td class="<?php
                                                    if ($grade['end_term_exam'] >= 80) echo 'grade-excellent';
                                                    elseif ($grade['end_term_exam'] >= 60) echo 'grade-good';
                                                    elseif ($grade['end_term_exam'] >= 40) echo 'grade-average';
                                                    else echo 'grade-poor';
                                                ?>">
                                                    <?php echo $grade['end_term_exam'] !== null ? number_format($grade['end_term_exam'], 2) : 'N/A'; ?>
                                                </td>
                                                <td class="<?php
                                                    if ($grade['total'] >= 80) echo 'grade-excellent';
                                                    elseif ($grade['total'] >= 60) echo 'grade-good';
                                                    elseif ($grade['total'] >= 40) echo 'grade-average';
                                                    else echo 'grade-poor';
                                                ?>">
                                                    <?php echo $grade['total'] !== null ? number_format($grade['total'], 2) : 'N/A'; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($grade['comment'] ?? 'N/A'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Report Details -->
                        <div class="report-section">
                            <h5>Additional Report Details</h5>
                            <ul class="list-group mb-4">
                                <li class="list-group-item"><strong>Attendance:</strong> <?php echo $reports[$student['id']]['attendance'] !== null ? htmlspecialchars($reports[$student['id']]['attendance']) : 'N/A'; ?> days</li>
                                <li class="list-group-item"><strong>Conduct:</strong> <?php echo htmlspecialchars($reports[$student['id']]['conduct'] ?? 'N/A'); ?></li>
                                <li class="list-group-item"><strong>Attitude:</strong> <?php echo htmlspecialchars($reports[$student['id']]['attitude'] ?? 'N/A'); ?></li>
                                <li class="list-group-item"><strong>Interest:</strong> <?php echo htmlspecialchars($reports[$student['id']]['interest'] ?? 'N/A'); ?></li>
                                <li class="list-group-item"><strong>Recommended Subjects:</strong> <?php echo htmlspecialchars($reports[$student['id']]['recommended_subjects'] ?? 'N/A'); ?></li>
                                <li class="list-group-item"><strong>Encouragement:</strong> <?php echo htmlspecialchars($reports[$student['id']]['encouragement'] ?? 'N/A'); ?></li>
                                <li class="list-group-item"><strong>Reopening Date:</strong> <?php echo htmlspecialchars($reports[$student['id']]['reopening_date'] ?? 'N/A'); ?></li>
                                <li class="list-group-item"><strong>Teacher Remarks:</strong> <?php echo htmlspecialchars($reports[$student['id']]['teacher_remarks'] ?? 'N/A'); ?></li>
                                <li class="list-group-item"><strong>Headmaster Remarks:</strong> <?php echo htmlspecialchars($reports[$student['id']]['headmaster_remarks'] ?? 'N/A'); ?></li>
                                <li class="list-group-item"><strong>Promoted to:</strong> <?php echo htmlspecialchars($reports[$student['id']]['promoted_class'] ?? 'N/A'); ?></li>
                            </ul>
                        </div>

                        <!-- Performance Chart -->
                        <h5 class="mb-3">Subject Performance</h5>
                        <div class="card mb-4">
                            <div class="card-body">
                                <canvas id="gradeChart-<?php echo $student['id']; ?>" style="max-height: 300px;"></canvas>
                            </div>
                        </div>
                    <?php endforeach; ?>

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
                            <p>Report Card - <?php echo htmlspecialchars($selected_term['term_name'] . ' ' . $selected_term['year']); ?> - <?php echo date('F j, Y'); ?></p>
                        </div>
                        <?php foreach ($students as $student): ?>
                            <h4><?php echo htmlspecialchars($student['name']); ?></h4>
                            <p><strong>Class:</strong> <?php echo htmlspecialchars($student['class_name'] ?? 'N/A'); ?> | <strong>Academic Year:</strong> <?php echo htmlspecialchars($student['academic_year'] ?? $selected_term['year']); ?></p>
                            <h5>Academic Performance</h5>
                            <table class="print-table">
                                <thead>
                                    <tr>
                                        <th>Subject</th>
                                        <th>Subject Code</th>
                                        <th>Mid-Term</th>
                                        <th>End-Term</th>
                                        <th>Total</th>
                                        <th>Comment</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($grades[$student['id']] as $grade): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($grade['subject_name']); ?></td>
                                            <td><?php echo htmlspecialchars($grade['subject_code']); ?></td>
                                            <td><?php echo $grade['mid_term_exam'] !== null ? number_format($grade['mid_term_exam'], 2) : 'N/A'; ?></td>
                                            <td><?php echo $grade['end_term_exam'] !== null ? number_format($grade['end_term_exam'], 2) : 'N/A'; ?></td>
                                            <td><?php echo $grade['total'] !== null ? number_format($grade['total'], 2) : 'N/A'; ?></td>
                                            <td><?php echo htmlspecialchars($grade['comment'] ?? 'N/A'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <h5 class="mt-4">Additional Report Details</h5>
                            <table class="print-table">
                                <tr><th>Attendance</th><td><?php echo $reports[$student['id']]['attendance'] !== null ? htmlspecialchars($reports[$student['id']]['attendance']) : 'N/A'; ?> days</td></tr>
                                <tr><th>Conduct</th><td><?php echo htmlspecialchars($reports[$student['id']]['conduct'] ?? 'N/A'); ?></td></tr>
                                <tr><th>Attitude</th><td><?php echo htmlspecialchars($reports[$student['id']]['attitude'] ?? 'N/A'); ?></td></tr>
                                <tr><th>Interest</th><td><?php echo htmlspecialchars($reports[$student['id']]['interest'] ?? 'N/A'); ?></td></tr>
                                <tr><th>Recommended Subjects</th><td><?php echo htmlspecialchars($reports[$student['id']]['recommended_subjects'] ?? 'N/A'); ?></td></tr>
                                <tr><th>Encouragement</th><td><?php echo htmlspecialchars($reports[$student['id']]['encouragement'] ?? 'N/A'); ?></td></tr>
                                <tr><th>Reopening Date</th><td><?php echo htmlspecialchars($reports[$student['id']]['reopening_date'] ?? 'N/A'); ?></td></tr>
                                <tr><th>Teacher Remarks</th><td><?php echo htmlspecialchars($reports[$student['id']]['teacher_remarks'] ?? 'N/A'); ?></td></tr>
                                <tr><th>Headmaster Remarks</th><td><?php echo htmlspecialchars($reports[$student['id']]['headmaster_remarks'] ?? 'N/A'); ?></td></tr>
                                <tr><th>Promoted to</th><td><?php echo htmlspecialchars($reports[$student['id']]['promoted_class'] ?? 'N/A'); ?></td></tr>
                            </table>
                            <p><strong>Average Grade:</strong> <?php echo number_format($average_grades[$student['id']], 2); ?></p>
                        <?php endforeach; ?>
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
        // Chart.js for Subject Performance
        document.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($students as $student): ?>
                const ctx<?php echo $student['id']; ?> = document.getElementById('gradeChart-<?php echo $student['id']; ?>').getContext('2d');
                new Chart(ctx<?php echo $student['id']; ?>, {
                    type: 'bar',
                    data: {
                        labels: <?php echo json_encode($chart_data[$student['id']]['labels']); ?>,
                        datasets: [{
                            label: 'Grades for <?php echo addslashes($chart_data[$student['id']]['student_name']); ?>',
                            data: <?php echo json_encode($chart_data[$student['id']]['grades']); ?>,
                            backgroundColor: 'rgba(255, 69, 0, 0.6)',
                            borderColor: 'rgba(255, 69, 0, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                max: 100,
                                title: { display: true, text: 'Grade (%)' }
                            },
                            x: {
                                title: { display: true, text: 'Subjects' }
                            }
                        },
                        plugins: {
                            legend: { position: 'top' },
                            title: {
                                display: true,
                                text: 'Subject Performance - <?php echo addslashes($chart_data[$student['id']]['student_name']); ?>'
                            }
                        }
                    }
                });
            <?php endforeach; ?>
        });
    </script>
</body>
</html>