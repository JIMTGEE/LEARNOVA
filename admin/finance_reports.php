<?php
// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Check if the user is logged in and has an admin role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Redirecting to login: user_id not set or role not admin. Role: " . ($_SESSION['user_role'] ?? 'not set'));
    header("Location: ../login.php");
    exit();
}
// Include database connection
require_once '../config/db.php';
$db = db(); // Use the helper function to get PDO instance
// Include helpers and functions
require_once '../includes/helpers.php';
require_once '../functions/csrf.php';
require_once '../functions/log_activity.php';
// Fetch KPIs
$total_collected = $db->query("SELECT SUM(amount_paid) FROM payments")->fetchColumn() ?? 0;

$outstanding_query = "SELECT SUM(d.amount_due - IFNULL(p.total_paid, 0)) 
                      FROM debts d 
                      LEFT JOIN (SELECT user_id, fee_id, SUM(amount_paid) AS total_paid 
                                 FROM payments 
                                 GROUP BY user_id, fee_id) p 
                      ON d.user_id = p.user_id AND d.fee_id = p.fee_id 
                      WHERE d.amount_due > IFNULL(p.total_paid, 0)";
$total_outstanding = $db->query($outstanding_query)->fetchColumn() ?? 0;

$total_fees_billed = $db->query("SELECT SUM(amount_due) FROM debts")->fetchColumn() ?? 0;
$collection_rate = ($total_fees_billed > 0) ? round(($total_collected / $total_fees_billed) * 100, 2) : 0;

$overdue_query = "SELECT COUNT(*) 
                  FROM debts d 
                  JOIN fees f ON d.fee_id = f.id 
                  LEFT JOIN (SELECT user_id, fee_id, SUM(amount_paid) AS total_paid 
                             FROM payments 
                             GROUP BY user_id, fee_id) p 
                  ON d.user_id = p.user_id AND d.fee_id = p.fee_id 
                  WHERE f.due_date < CURDATE() AND d.amount_due > IFNULL(p.total_paid, 0)";
$overdue_debts = $db->query($overdue_query)->fetchColumn() ?? 0;

// Fetch payment methods breakdown
$methods_stmt = $db->query("SELECT payment_method, SUM(amount_paid) AS total FROM payments GROUP BY payment_method");
$methods = $methods_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch monthly collections (last 12 months)
$monthly_stmt = $db->query("SELECT DATE_FORMAT(payment_date, '%Y-%m') AS month, SUM(amount_paid) AS total
                            FROM payments
                            WHERE payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                            GROUP BY month ORDER BY month ASC");
$monthly_collections = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch top 10 payers
$top_payers_stmt = $db->query("SELECT u.name, SUM(p.amount_paid) AS total_paid
                               FROM payments p JOIN users u ON p.user_id = u.id
                               GROUP BY p.user_id ORDER BY total_paid DESC LIMIT 10");
$top_payers = $top_payers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch overdue debts list (top 10)
$overdue_list_query = "SELECT d.id, u.name AS student, f.fee_type, (d.amount_due - IFNULL(p.total_paid, 0)) AS outstanding, f.due_date
                       FROM debts d 
                       JOIN users u ON d.user_id = u.id 
                       JOIN fees f ON d.fee_id = f.id 
                       LEFT JOIN (SELECT user_id, fee_id, SUM(amount_paid) AS total_paid 
                                  FROM payments 
                                  GROUP BY user_id, fee_id) p 
                       ON d.user_id = p.user_id AND d.fee_id = p.fee_id
                       WHERE f.due_date < CURDATE() AND d.amount_due > IFNULL(p.total_paid, 0)
                       ORDER BY f.due_date ASC LIMIT 10";
$overdue_list_stmt = $db->query($overdue_list_query);
$overdue_list = $overdue_list_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent payments (last 10)
$recent_payments_query = "SELECT p.*, u.name AS student, f.fee_type, p.receipt_number
                          FROM payments p 
                          JOIN users u ON p.user_id = u.id 
                          JOIN fees f ON p.fee_id = f.id
                          ORDER BY p.payment_date DESC LIMIT 10";
$recent_payments_stmt = $db->query($recent_payments_query);
$recent_payments = $recent_payments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Log access
log_activity($_SESSION['user_id'], 'view_finance_reports', "Viewed finance reports on " . date('Y-m-d'));
?>
<?php include '../templates/admin_header.php'; ?>
    <section class="finance-reports">
        <h1 class="page-title">Finance Reports</h1>
        <!-- KPIs Grid -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <i class="fas fa-money-bill-wave icon"></i>
                <div class="value">$<?php echo number_format($total_collected, 2); ?></div>
                <div class="label">Total Collected</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-exclamation-triangle icon"></i>
                <div class="value">$<?php echo number_format($total_outstanding, 2); ?></div>
                <div class="label">Total Outstanding</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-chart-pie icon"></i>
                <div class="value"><?php echo $collection_rate; ?>%</div>
                <div class="label">Collection Rate</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-calendar-times icon"></i>
                <div class="value"><?php echo $overdue_debts; ?></div>
                <div class="label">Overdue Debts</div>
            </div>
        </div>
        <!-- Charts Grid -->
        <div class="charts-grid">
            <div class="chart-card">
                <h3>Monthly Collections</h3>
                <canvas id="monthlyChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>Payment Methods</h3>
                <canvas id="methodsChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>Top Payers</h3>
                <canvas id="topPayersChart"></canvas>
            </div>
        </div>
        <!-- Recent Payments Table -->
        <div class="recent-payments-card">
            <h3>Recent Payments</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Fee Type</th>
                        <th>Amount</th>
                        <th>Method</th>
                        <th>Date</th>
                        <th>Receipt Number</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_payments as $payment): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($payment['student']); ?></td>
                            <td><?php echo htmlspecialchars($payment['fee_type']); ?></td>
                            <td>$<?php echo number_format($payment['amount_paid'], 2); ?></td>
                            <td><?php echo ucfirst(htmlspecialchars($payment['payment_method'])); ?></td>
                            <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                            <td><?php echo htmlspecialchars($payment['receipt_number'] ?? 'N/A'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Overdue Debts Table -->
        <div class="overdue-debts-card">
            <h3>Overdue Debts</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Fee Type</th>
                        <th>Outstanding</th>
                        <th>Due Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($overdue_list as $overdue): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($overdue['student']); ?></td>
                            <td><?php echo htmlspecialchars($overdue['fee_type']); ?></td>
                            <td>$<?php echo number_format($overdue['outstanding'], 2); ?></td>
                            <td><?php echo htmlspecialchars($overdue['due_date']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Monthly Collections Chart (Line)
        const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($monthly_collections, 'month')); ?>,
                datasets: [{
                    label: 'Collections',
                    data: <?php echo json_encode(array_column($monthly_collections, 'total')); ?>,
                    borderColor: 'var(--primary-sea-blue)',
                    backgroundColor: 'rgba(23, 162, 184, 0.2)',
                    tension: 0.1,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Amount ($)' } },
                    x: { title: { display: true, text: 'Month' } }
                }
            }
        });
        // Payment Methods Chart (Pie)
        const methodsCtx = document.getElementById('methodsChart').getContext('2d');
        new Chart(methodsCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode(array_column($methods, 'payment_method')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($methods, 'total')); ?>,
                    backgroundColor: ['var(--primary-sea-blue)', 'var(--accent-soft-cyan)', 'var(--accent-deep-navy)', 'var(--accent-slate-gray)']
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });
        // Top Payers Chart (Bar)
        const topPayersCtx = document.getElementById('topPayersChart').getContext('2d');
        new Chart(topPayersCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($top_payers, 'name')); ?>,
                datasets: [{
                    label: 'Total Paid',
                    data: <?php echo json_encode(array_column($top_payers, 'total_paid')); ?>,
                    backgroundColor: 'var(--primary-sea-blue)'
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Amount ($)' } }
                }
            }
        });
    </script>
    <style>
        /* Finance Reports Styles */
        .finance-reports {
            padding: 20px;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .kpi-card {
            background: var(--primary-white);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            text-align: center;
        }
        .kpi-card .icon {
            font-size: 36px;
            color: var(--primary-sea-blue);
            margin-bottom: 8px;
        }
        .kpi-card .value {
            font-size: 28px;
            font-weight: bold;
        }
        .kpi-card .label {
            font-size: 14px;
            color: var(--accent-slate-gray);
        }
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .chart-card {
            background: var(--primary-white);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .recent-payments-card, .overdue-debts-card {
            background: var(--primary-white);
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            margin-bottom: 32px;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        .data-table th, .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--accent-slate-gray);
        }
        .data-table th {
            background: var(--accent-deep-navy);
            color: var(--primary-white);
        }
        .alert {
            padding: 16px;
            border-radius: 4px;
            margin-bottom: 16px;
        }
        .alert.success { background: rgba(40,167,69,0.1); color: #28a745; }
        .alert.error { background: rgba(220,53,69,0.1); color: #dc3545; }
    </style>
<?php include '../templates/admin_footer.php'; ?>