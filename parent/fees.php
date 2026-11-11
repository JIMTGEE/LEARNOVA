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
$current_academic_year = $current_term['year'];

// Handle student filter with CSRF validation
$selected_student_id = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        error_log("CSRF token validation failed for parent_id: $parent_id");
        http_response_code(403);
        die("System error: Invalid CSRF token.");
    }
    $selected_student_id = isset($_POST['student_id']) && is_numeric($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
}

// Fetch students associated with parent
$parent_id = (int)$_SESSION['user_id'];
$students_sql = "SELECT u.id, u.name, c.class_name, c.academic_year
                 FROM parent_student_mapping psm
                 JOIN users u ON psm.student_id = u.id
                 LEFT JOIN student_classes sc ON u.id = sc.user_id AND sc.academic_year = '" . mysqli_real_escape_string($conn, $current_academic_year) . "'
                 LEFT JOIN classes c ON sc.class_id = c.id
                 WHERE psm.parent_id = $parent_id ORDER BY u.name";
$students_result = mysqli_query($conn, $students_sql);
if (!$students_result) {
    error_log("Error fetching students: " . mysqli_error($conn));
    http_response_code(500);
    die("System error: Unable to fetch students.");
}
$students = [];
$valid_student_ids = [];
while ($student_row = mysqli_fetch_assoc($students_result)) {
    $students[] = $student_row;
    $valid_student_ids[] = $student_row['id'];
}
mysqli_free_result($students_result);

// Validate selected student ID
$student_filter = '';
if ($selected_student_id > 0 && in_array($selected_student_id, $valid_student_ids)) {
    $student_filter = "AND d.user_id = $selected_student_id";
} else {
    $selected_student_id = 0; // Reset to "All Students" if invalid
}

// Fetch debts
$debts_sql = "SELECT d.id, d.user_id, d.amount_due, d.academic_year, f.fee_type, f.amount, f.due_date, f.department, f.target_type, f.is_recurring, u.name AS student_name, c.class_name
              FROM debts d
              JOIN fees f ON d.fee_id = f.id
              JOIN users u ON d.user_id = u.id
              LEFT JOIN classes c ON f.class_id = c.id
              WHERE d.user_id IN (SELECT student_id FROM parent_student_mapping WHERE parent_id = $parent_id)
              AND d.academic_year = '" . mysqli_real_escape_string($conn, $current_academic_year) . "' $student_filter
              ORDER BY f.due_date";
error_log("Debts query: $debts_sql"); // Debug query
$debts_result = mysqli_query($conn, $debts_sql);
if (!$debts_result) {
    error_log("Error fetching debts: " . mysqli_error($conn) . " | Query: $debts_sql");
    http_response_code(500);
    die("System error: Unable to fetch debts.");
}
$debts = [];
$overdue_debts = 0;
while ($debt_row = mysqli_fetch_assoc($debts_result)) {
    $debt_row['is_overdue'] = strtotime($debt_row['due_date']) < time();
    if ($debt_row['is_overdue']) {
        $overdue_debts += $debt_row['amount_due'];
    }
    $debts[] = $debt_row;
}
mysqli_free_result($debts_result);

// Fetch payment history
$payments_sql = "SELECT p.id, p.user_id, p.amount_paid, p.payment_date, p.payment_method, p.receipt_number, f.fee_type, u.name AS student_name, c.class_name
                 FROM payments p
                 JOIN fees f ON p.fee_id = f.id
                 JOIN users u ON p.user_id = u.id
                 LEFT JOIN classes c ON f.class_id = c.id
                 WHERE p.user_id IN (SELECT student_id FROM parent_student_mapping WHERE parent_id = $parent_id)
                 AND f.academic_year = '" . mysqli_real_escape_string($conn, $current_academic_year) . "' $student_filter
                 ORDER BY p.payment_date DESC";
error_log("Payments query: $payments_sql"); // Debug query
$payments_result = mysqli_query($conn, $payments_sql);
if (!$payments_result) {
    error_log("Error fetching payments: " . mysqli_error($conn) . " | Query: $payments_sql");
    http_response_code(500);
    die("System error: Unable to fetch payments.");
}
$payments = [];
while ($payment_row = mysqli_fetch_assoc($payments_result)) {
    $payments[] = $payment_row;
}
mysqli_free_result($payments_result);

// Calculate metrics
$total_debt = array_sum(array_column($debts, 'amount_due'));
$total_paid = array_sum(array_column($payments, 'amount_paid'));

// Prepare chart data (debt and paid per student)
$chart_data = [];
$chart_labels = $selected_student_id > 0 ? ['Debt', 'Paid'] : array_column($students, 'name');
$chart_debts = [];
$chart_payments = [];
if ($selected_student_id > 0) {
    $chart_debts[] = array_sum(array_column(array_filter($debts, fn($d) => $d['user_id'] == $selected_student_id), 'amount_due'));
    $chart_payments[] = array_sum(array_column(array_filter($payments, fn($p) => $p['user_id'] == $selected_student_id), 'amount_paid'));
} else {
    foreach ($students as $student) {
        $student_id = $student['id'];
        $chart_debts[] = array_sum(array_column(array_filter($debts, fn($d) => $d['user_id'] == $student_id), 'amount_due'));
        $chart_payments[] = array_sum(array_column(array_filter($payments, fn($p) => $p['user_id'] == $student_id), 'amount_paid'));
    }
}

// Log access
if (function_exists('log_activity')) {
    log_activity($parent_id, 'Accessed Fees Page', "Parent viewed fees, debts, and payment history for academic year: $current_academic_year" . ($selected_student_id > 0 ? " for student_id: $selected_student_id" : ""), $conn);
} else {
    error_log("log_activity function undefined in fees.php; logging access for parent_id: $parent_id, academic year: $current_academic_year" . ($selected_student_id > 0 ? ", student_id: $selected_student_id" : ""));
}

// Close database connection
mysqli_close($conn);
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View fees, debts, and payment history for students in QueensPa School.">
    <meta name="keywords" content="QueensPa School, parent dashboard, fees, debts, payments">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Parent Fees">
    <meta property="og:description" content="Parent interface for viewing fees, debts, and payment history.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/parent/fees.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Parent Fees">
    <meta name="twitter:description" content="Parent interface for viewing fees, debts, and payment history.">
    <title>Fees - QueensPa School</title>
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
        .btn-primary, .btn-success, .btn-print {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        .btn-primary:hover, .btn-success:hover, .btn-print:hover {
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
        .overdue {
            color: var(--danger-color);
            font-weight: bold;
        }
        .recurring {
            color: var(--accent-color);
            font-style: italic;
        }
        .print-section {
            display: none;
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
                        <h3 class="mb-0">💰 Fees Dashboard</h3>
                        <a href="/parent/dashboard.php" class="btn btn-primary"><i class="fas fa-home"></i> Home</a>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Student Filter -->
                    <form method="POST" class="mb-4">
                        <div class="mb-3">
                            <label for="student_id" class="form-label">Filter by Student</label>
                            <select name="student_id" id="student_id" class="form-select" onchange="this.form.submit()" aria-label="Select student">
                                <option value="0" <?php echo $selected_student_id == 0 ? 'selected' : ''; ?>>All Students</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>" <?php echo $selected_student_id == $student['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['name'] . ($student['class_name'] ? ' (' . $student['class_name'] . ')' : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    </form>

                    <!-- Metrics -->
                    <div class="row g-3 mb-4">
                        <div class="col-md-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <h5 class="card-title">Total Students</h5>
                                    <p class="card-text text-primary fs-3"><?php echo count($students); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <h5 class="card-title">Total Debt</h5>
                                    <p class="card-text text-primary fs-3">GH₵ <?php echo number_format($total_debt, 2); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <h5 class="card-title">Overdue Debt</h5>
                                    <p class="card-text text-danger fs-3">GH₵ <?php echo number_format($overdue_debts, 2); ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card h-100">
                                <div class="card-body text-center">
                                    <h5 class="card-title">Total Paid</h5>
                                    <p class="card-text text-success fs-3">GH₵ <?php echo number_format($total_paid, 2); ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Current Debts -->
                    <h4 class="mb-3">Current Debts</h4>
                    <div class="table-responsive mb-4">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Fee Type</th>
                                    <th>Department</th>
                                    <th>Target</th>
                                    <th>Recurring</th>
                                    <th>Amount Due</th>
                                    <th>Due Date</th>
                                    <th class="no-print">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($debts)): ?>
                                    <tr><td colspan="9" class="text-center">No outstanding debts.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($debts as $debt): ?>
                                        <tr class="<?php echo $debt['is_overdue'] ? 'overdue' : ''; ?>">
                                            <td><?php echo htmlspecialchars($debt['student_name']); ?></td>
                                            <td><?php echo htmlspecialchars($debt['class_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($debt['fee_type']); ?></td>
                                            <td><?php echo htmlspecialchars($debt['department']); ?></td>
                                            <td><?php echo htmlspecialchars($debt['target_type']); ?></td>
                                            <td class="<?php echo $debt['is_recurring'] ? 'recurring' : ''; ?>">
                                                <?php echo $debt['is_recurring'] ? 'Yes' : 'No'; ?>
                                            </td>
                                            <td>GH₵ <?php echo number_format($debt['amount_due'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($debt['due_date']); ?></td>
                                            <td class="no-print">
                                                <a href="/parent/pay_fee.php?debt_id=<?php echo $debt['id']; ?>" class="btn btn-sm btn-success">Pay Now</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Payment History -->
                    <h4 class="mb-3">Payment History</h4>
                    <div class="table-responsive mb-4">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Fee Type</th>
                                    <th>Amount Paid</th>
                                    <th>Payment Date</th>
                                    <th>Method</th>
                                    <th>Receipt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payments)): ?>
                                    <tr><td colspan="7" class="text-center">No payment history.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($payments as $payment): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($payment['student_name']); ?></td>
                                            <td><?php echo htmlspecialchars($payment['class_name'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($payment['fee_type']); ?></td>
                                            <td>GH₵ <?php echo number_format($payment['amount_paid'], 2); ?></td>
                                            <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                                            <td><?php echo htmlspecialchars($payment['receipt_number'] ?? 'N/A'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Debt vs. Paid Chart -->
                    <h4 class="mb-3">Debt vs. Paid Overview</h4>
                    <div class="card mb-4">
                        <div class="card-body">
                            <canvas id="debtChart" style="max-height: 300px;"></canvas>
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
                            <p>Fees Report - Academic Year: <?php echo htmlspecialchars($current_academic_year); ?> - <?php echo date('F j, Y'); ?></p>
                        </div>
                        <h4>Current Debts</h4>
                        <table class="print-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Fee Type</th>
                                    <th>Department</th>
                                    <th>Target</th>
                                    <th>Recurring</th>
                                    <th>Amount Due</th>
                                    <th>Due Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($debts as $debt): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($debt['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($debt['class_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($debt['fee_type']); ?></td>
                                        <td><?php echo htmlspecialchars($debt['department']); ?></td>
                                        <td><?php echo htmlspecialchars($debt['target_type']); ?></td>
                                        <td><?php echo $debt['is_recurring'] ? 'Yes' : 'No'; ?></td>
                                        <td>GH₵ <?php echo number_format($debt['amount_due'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($debt['due_date']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <h4 class="mt-4">Payment History</h4>
                        <table class="print-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Fee Type</th>
                                    <th>Amount Paid</th>
                                    <th>Payment Date</th>
                                    <th>Method</th>
                                    <th>Receipt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payments as $payment): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($payment['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['class_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($payment['fee_type']); ?></td>
                                        <td>GH₵ <?php echo number_format($payment['amount_paid'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                                        <td><?php echo htmlspecialchars($payment['receipt_number'] ?? 'N/A'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <h4 class="mt-4">Summary</h4>
                        <table class="print-table">
                            <tr><th>Total Debt</th><td>GH₵ <?php echo number_format($total_debt, 2); ?></td></tr>
                            <tr><th>Overdue Debt</th><td>GH₵ <?php echo number_format($overdue_debts, 2); ?></td></tr>
                            <tr><th>Total Paid</th><td>GH₵ <?php echo number_format($total_paid, 2); ?></td></tr>
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
        // Chart.js for Debt vs. Paid
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('debtChart').getContext('2d');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($chart_labels); ?>,
                    datasets: [
                        {
                            label: 'Debt (GH₵)',
                            data: <?php echo json_encode($chart_debts); ?>,
                            backgroundColor: 'rgba(220, 53, 69, 0.6)',
                            borderColor: 'rgba(220, 53, 69, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Paid (GH₵)',
                            data: <?php echo json_encode($chart_payments); ?>,
                            backgroundColor: 'rgba(40, 167, 69, 0.6)',
                            borderColor: 'rgba(40, 167, 69, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true,
                            title: { display: true, text: 'Amount (GH₵)' }
                        },
                        x: {
                            title: { display: true, text: '<?php echo $selected_student_id > 0 ? "Status" : "Students"; ?>' }
                        }
                    },
                    plugins: {
                        legend: { position: 'top' },
                        title: {
                            display: true,
                            text: 'Debt vs. Paid by <?php echo $selected_student_id > 0 ? "Student" : "Students"; ?>'
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `${context.dataset.label}: GH₵ ${context.raw.toFixed(2)}`;
                                }
                            }
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>