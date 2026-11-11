<?php
// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Check if the user is logged in and has an admin role - Using specified auth logic
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    // Debug: Log why redirecting (preserved)
    error_log("Redirecting to login: user_id not set or role not admin. Role: " . ($_SESSION['user_role'] ?? 'not set'));
    header("Location: ../login.php");
    exit();
}
// Include database connection first to avoid dependency issues in helpers
require_once __DIR__ . '/../config/db.php';
$db = db(); // Use the helper function to get PDO instance
// Include helpers and functions with upgraded error handling
try {
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../functions/csrf.php';
    require_once __DIR__ . '/../functions/log_activity.php';
} catch (Exception $e) {
    error_log("Error loading helpers/functions: " . $e->getMessage());
    $_SESSION['error'] = 'System error: Unable to load required files. Please check logs.';
    header("Location: dashboard.php");
    exit();
}
// Handle POST actions: delete, bulk actions for fees, payments, debts with enhanced validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: fees.php");
        exit();
    }
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'delete_fee':
                $fee_id = intval($_POST['fee_id'] ?? 0);
                if ($fee_id > 0) {
                    $stmt = $db->prepare("DELETE FROM fees WHERE id = ?");
                    $stmt->execute([$fee_id]);
                    log_activity($_SESSION['user_id'], 'delete_fee', "Deleted fee ID: $fee_id");
                    $_SESSION['success'] = 'Fee deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid fee ID.';
                }
                break;
            case 'bulk_delete_fees':
                if (isset($_POST['fee_ids']) && is_array($_POST['fee_ids'])) {
                    $fee_ids = array_filter(array_map('intval', $_POST['fee_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($fee_ids) {
                        $placeholders = implode(',', array_fill(0, count($fee_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM fees WHERE id IN ($placeholders)");
                        $stmt->execute($fee_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_fees', "Deleted fees: " . implode(',', $fee_ids));
                        $_SESSION['success'] = 'Selected fees deleted.';
                    }
                }
                break;
            case 'delete_payment':
                $payment_id = intval($_POST['payment_id'] ?? 0);
                if ($payment_id > 0) {
                    $stmt = $db->prepare("DELETE FROM payments WHERE id = ?");
                    $stmt->execute([$payment_id]);
                    log_activity($_SESSION['user_id'], 'delete_payment', "Deleted payment ID: $payment_id");
                    $_SESSION['success'] = 'Payment deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid payment ID.';
                }
                break;
            case 'bulk_delete_payments':
                if (isset($_POST['payment_ids']) && is_array($_POST['payment_ids'])) {
                    $payment_ids = array_filter(array_map('intval', $_POST['payment_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($payment_ids) {
                        $placeholders = implode(',', array_fill(0, count($payment_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM payments WHERE id IN ($placeholders)");
                        $stmt->execute($payment_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_payments', "Deleted payments: " . implode(',', $payment_ids));
                        $_SESSION['success'] = 'Selected payments deleted.';
                    }
                }
                break;
            case 'delete_debt':
                $debt_id = intval($_POST['debt_id'] ?? 0);
                if ($debt_id > 0) {
                    $stmt = $db->prepare("DELETE FROM debts WHERE id = ?");
                    $stmt->execute([$debt_id]);
                    log_activity($_SESSION['user_id'], 'delete_debt', "Deleted debt ID: $debt_id");
                    $_SESSION['success'] = 'Debt deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid debt ID.';
                }
                break;
            case 'bulk_delete_debts':
                if (isset($_POST['debt_ids']) && is_array($_POST['debt_ids'])) {
                    $debt_ids = array_filter(array_map('intval', $_POST['debt_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($debt_ids) {
                        $placeholders = implode(',', array_fill(0, count($debt_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM debts WHERE id IN ($placeholders)");
                        $stmt->execute($debt_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_debts', "Deleted debts: " . implode(',', $debt_ids));
                        $_SESSION['success'] = 'Selected debts deleted.';
                    }
                }
                break;
            // Enhanced: Add bulk export actions (simulate with session flag for now; actual export in separate script)
            case 'bulk_export_fees':
                $_SESSION['export_type'] = 'fees';
                $_SESSION['export_ids'] = $_POST['fee_ids'] ?? [];
                header("Location: export_finance.php");
                exit();
                break;
            // Similar for payments and debts
            case 'bulk_export_payments':
                $_SESSION['export_type'] = 'payments';
                $_SESSION['export_ids'] = $_POST['payment_ids'] ?? [];
                header("Location: export_finance.php");
                exit();
                break;
            case 'bulk_export_debts':
                $_SESSION['export_type'] = 'debts';
                $_SESSION['export_ids'] = $_POST['debt_ids'] ?? [];
                header("Location: export_finance.php");
                exit();
                break;
        }
        header("Location: fees.php");
        exit();
    }
}
// Define pagination limit
$limit = 20;
// Fetch fees with search, filters, sorting, and pagination
$fee_search = isset($_GET['fee_search']) ? '%' . trim($_GET['fee_search']) . '%' : '%';
$fee_department_filter = isset($_GET['fee_department']) ? $_GET['fee_department'] : '';
$fee_type_filter = isset($_GET['fee_type']) ? $_GET['fee_type'] : '';
$fee_sort = isset($_GET['fee_sort']) ? $_GET['fee_sort'] : 'created_at';
$fee_order = isset($_GET['fee_order']) ? strtoupper($_GET['fee_order']) : 'DESC';
$fee_page = max(1, intval($_GET['fee_page'] ?? 1));
$fee_offset = ($fee_page - 1) * $limit;
$fee_query = "SELECT f.*, c.class_name, d.name AS department_name, u.name AS user_name FROM fees f LEFT JOIN classes c ON f.class_id = c.id LEFT JOIN departments d ON f.department = d.name LEFT JOIN users u ON f.user_id = u.id WHERE (f.fee_type LIKE ? OR f.amount LIKE ?)";
$fee_params = [$fee_search, $fee_search];
if ($fee_department_filter) {
    $fee_query .= " AND f.department = ?";
    $fee_params[] = $fee_department_filter;
}
if ($fee_type_filter) {
    $fee_query .= " AND f.target_type = ?";
    $fee_params[] = $fee_type_filter;
}
$fee_query .= " ORDER BY $fee_sort $fee_order LIMIT ? OFFSET ?";
$fee_params[] = $limit;
$fee_params[] = $fee_offset;
$stmt = $db->prepare($fee_query);
$stmt->execute($fee_params);
$fees = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for fees pagination
$fee_count_query = "SELECT COUNT(*) FROM fees WHERE (fee_type LIKE ? OR amount LIKE ?)";
$fee_count_params = [$fee_search, $fee_search];
if ($fee_department_filter) {
    $fee_count_query .= " AND department = ?";
    $fee_count_params[] = $fee_department_filter;
}
if ($fee_type_filter) {
    $fee_count_query .= " AND target_type = ?";
    $fee_count_params[] = $fee_type_filter;
}
$fee_count_stmt = $db->prepare($fee_count_query);
$fee_count_stmt->execute($fee_count_params);
$total_fees = $fee_count_stmt->fetchColumn() ?? 0;
$total_fee_pages = ceil($total_fees / $limit);
// Fetch payments with search, sorting, pagination
$payment_search = isset($_GET['payment_search']) ? '%' . trim($_GET['payment_search']) . '%' : '%';
$payment_sort = isset($_GET['payment_sort']) ? $_GET['payment_sort'] : 'payment_date';
$payment_order = isset($_GET['payment_order']) ? strtoupper($_GET['payment_order']) : 'DESC';
$payment_page = max(1, intval($_GET['payment_page'] ?? 1));
$payment_offset = ($payment_page - 1) * $limit;
$payment_query = "SELECT p.*, u.name AS user_name, f.fee_type, c.class_name FROM payments p JOIN users u ON p.user_id = u.id JOIN fees f ON p.fee_id = f.id JOIN classes c ON p.class_id = c.id WHERE (u.name LIKE ? OR p.amount_paid LIKE ? OR p.payment_method LIKE ?)";
$payment_params = [$payment_search, $payment_search, $payment_search];
$payment_query .= " ORDER BY $payment_sort $payment_order LIMIT ? OFFSET ?";
$payment_params[] = $limit;
$payment_params[] = $payment_offset;
$stmt = $db->prepare($payment_query);
$stmt->execute($payment_params);
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for payments
$payment_count_query = "SELECT COUNT(*) FROM payments p JOIN users u ON p.user_id = u.id WHERE (u.name LIKE ? OR p.amount_paid LIKE ? OR p.payment_method LIKE ?)";
$payment_count_params = [$payment_search, $payment_search, $payment_search];
$payment_count_stmt = $db->prepare($payment_count_query);
$payment_count_stmt->execute($payment_count_params);
$total_payments = $payment_count_stmt->fetchColumn() ?? 0;
$total_payment_pages = ceil($total_payments / $limit);
// Fetch debts with search, sorting, pagination
$debt_search = isset($_GET['debt_search']) ? '%' . trim($_GET['debt_search']) . '%' : '%';
$debt_sort = isset($_GET['debt_sort']) ? $_GET['debt_sort'] : 'created_at';
$debt_order = isset($_GET['debt_order']) ? strtoupper($_GET['debt_order']) : 'DESC';
$debt_page = max(1, intval($_GET['debt_page'] ?? 1));
$debt_offset = ($debt_page - 1) * $limit;
$debt_query = "SELECT d.*, u.name AS user_name, f.fee_type, c.class_name FROM debts d JOIN users u ON d.user_id = u.id JOIN fees f ON d.fee_id = f.id JOIN classes c ON d.class_id = c.id WHERE (u.name LIKE ? OR d.amount_due LIKE ?)";
$debt_params = [$debt_search, $debt_search];
$debt_query .= " ORDER BY $debt_sort $debt_order LIMIT ? OFFSET ?";
$debt_params[] = $limit;
$debt_params[] = $debt_offset;
$stmt = $db->prepare($debt_query);
$stmt->execute($debt_params);
$debts = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for debts
$debt_count_query = "SELECT COUNT(*) FROM debts d JOIN users u ON d.user_id = u.id WHERE (u.name LIKE ? OR d.amount_due LIKE ?)";
$debt_count_params = [$debt_search, $debt_search];
$debt_count_stmt = $db->prepare($debt_count_query);
$debt_count_stmt->execute($debt_count_params);
$total_debts = $debt_count_stmt->fetchColumn() ?? 0;
$total_debt_pages = ceil($total_debts / $limit);
// Fetch enhanced KPIs
$fees_collected = $db->query("SELECT SUM(amount_paid) FROM payments")->fetchColumn() ?? 0;
$outstanding_fees = $db->query("SELECT SUM(amount_due) FROM debts")->fetchColumn() ?? 0;
$total_fees_issued = $db->query("SELECT SUM(amount) FROM fees")->fetchColumn() ?? 0;
$collection_rate = ($total_fees_issued > 0) ? ($fees_collected / $total_fees_issued) * 100 : 0;
// Fetch departments for filters
$departments = $db->query("SELECT DISTINCT department FROM fees ORDER BY department")->fetchAll(PDO::FETCH_COLUMN);
// Fetch audit logs (filtered by fees-related actions, with pagination if needed)
$audit_logs = $db->query("SELECT ul.*, u.name AS actor_name FROM user_logs ul JOIN users u ON ul.user_id = u.id WHERE ul.action LIKE '%fee%' OR ul.action LIKE '%payment%' OR ul.action LIKE '%debt%' ORDER BY ul.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
// Generate CSRF token
$csrf_token = generate_csrf_token();
?>
<?php include '../templates/admin_header.php'; ?>
    <section class="fees-management">
        <h1 class="page-title">Fees & Finance Management Dashboard</h1>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success" role="alert"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error" role="alert"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <!-- Enhanced KPIs with progress bar for collection rate -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <i class="fas fa-money-bill-wave icon"></i>
                <div class="value">$<?php echo number_format($fees_collected, 2); ?></div>
                <div class="label">Fees Collected</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-exclamation-triangle icon"></i>
                <div class="value">$<?php echo number_format($outstanding_fees, 2); ?></div>
                <div class="label">Outstanding Fees</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-chart-line icon"></i>
                <div class="value">$<?php echo number_format($total_fees_issued, 2); ?></div>
                <div class="label">Total Fees Issued</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-percentage icon"></i>
                <div class="value"><?php echo number_format($collection_rate, 2); ?>%</div>
                <div class="label">Collection Rate</div>
                <div class="progress-bar">
                    <div class="progress" style="width: <?php echo $collection_rate; ?>%;"></div>
                </div>
            </div>
        </div>
        <!-- Quick Actions (Grid layout with enhanced buttons) -->
        <div class="quick-actions">
            <a href="add_fee.php" class="action-button"><i class="fas fa-plus"></i> Add New Fee</a>
            <a href="process_payment.php" class="action-button"><i class="fas fa-money-check"></i> Process Payment</a>
            <a href="manage_debts.php" class="action-button"><i class="fas fa-exclamation-circle"></i> Manage Debts</a>
            <a href="finance_reports.php" class="action-button"><i class="fas fa-chart-pie"></i> View Reports</a>
            <a href="export_finance.php" class="action-button"><i class="fas fa-download"></i> Export Data</a>
        </div>
        <!-- Fees Section -->
        <div class="section">
            <h2>Fees</h2>
            <!-- Enhanced Search and Filters for Fees -->
            <form class="search-form" method="GET">
                <input type="text" name="fee_search" placeholder="Search by fee type or amount..." value="<?php echo htmlspecialchars($_GET['fee_search'] ?? ''); ?>" aria-label="Search Fees">
                <select name="fee_department" aria-label="Filter by Department">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $fee_department_filter === $dept ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="fee_type" aria-label="Filter by Type">
                    <option value="">All Types</option>
                    <option value="Class" <?php echo $fee_type_filter === 'Class' ? 'selected' : ''; ?>>Class</option>
                    <option value="Learner" <?php echo $fee_type_filter === 'Learner' ? 'selected' : ''; ?>>Learner</option>
                    <option value="School" <?php echo $fee_type_filter === 'School' ? 'selected' : ''; ?>>School</option>
                </select>
                <select name="fee_sort" aria-label="Sort By">
                    <option value="created_at" <?php echo $fee_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                    <option value="amount" <?php echo $fee_sort === 'amount' ? 'selected' : ''; ?>>Amount</option>
                    <option value="due_date" <?php echo $fee_sort === 'due_date' ? 'selected' : ''; ?>>Due Date</option>
                </select>
                <select name="fee_order" aria-label="Order">
                    <option value="DESC" <?php echo $fee_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $fee_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Fees Table with sorting links in headers -->
            <form method="POST" id="fees-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-fees" aria-label="Select All Fees"></th>
                            <th>ID</th>
                            <th>Fee Type</th>
                            <th>Amount</th>
                            <th>Due Date</th>
                            <th>Academic Year</th>
                            <th>Target Type</th>
                            <th>Class/Department/User</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fees as $fee): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="fee_ids[]" value="<?php echo $fee['id']; ?>" aria-label="Select Fee <?php echo $fee['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($fee['id']); ?></td>
                                <td data-label="Fee Type"><?php echo htmlspecialchars($fee['fee_type']); ?></td>
                                <td data-label="Amount">$<?php echo number_format($fee['amount'], 2); ?></td>
                                <td data-label="Due Date"><?php echo htmlspecialchars($fee['due_date']); ?></td>
                                <td data-label="Academic Year"><?php echo htmlspecialchars($fee['academic_year']); ?></td>
                                <td data-label="Target Type"><?php echo htmlspecialchars($fee['target_type']); ?></td>
                                <td data-label="Class/Department/User"><?php echo htmlspecialchars($fee['class_name'] ?? $fee['department_name'] ?? $fee['user_name'] ?? 'N/A'); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($fee['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_fee.php?id=<?php echo $fee['id']; ?>" class="action-icon edit" aria-label="Edit Fee"><i class="fas fa-edit"></i></a>
                                    <a href="view_fee.php?id=<?php echo $fee['id']; ?>" class="action-icon view" aria-label="View Fee"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this fee?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_fee">
                                        <input type="hidden" name="fee_id" value="<?php echo $fee['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Fee"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Enhanced Pagination for Fees -->
                <div class="pagination">
                    <?php if ($fee_page > 1): ?>
                        <a href="?fee_page=<?php echo $fee_page - 1; ?>&fee_search=<?php echo urlencode($_GET['fee_search'] ?? ''); ?>&fee_department=<?php echo urlencode($fee_department_filter); ?>&fee_type=<?php echo urlencode($fee_type_filter); ?>&fee_sort=<?php echo $fee_sort; ?>&fee_order=<?php echo $fee_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $fee_page; ?> of <?php echo $total_fee_pages; ?></span>
                    <?php if ($fee_page < $total_fee_pages): ?>
                        <a href="?fee_page=<?php echo $fee_page + 1; ?>&fee_search=<?php echo urlencode($_GET['fee_search'] ?? ''); ?>&fee_department=<?php echo urlencode($fee_department_filter); ?>&fee_type=<?php echo urlencode($fee_type_filter); ?>&fee_sort=<?php echo $fee_sort; ?>&fee_order=<?php echo $fee_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Enhanced Bulk Actions for Fees -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Fees">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_fees">Delete Selected</option>
                        <option value="bulk_export_fees">Export Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Payments Section -->
        <div class="section">
            <h2>Payments</h2>
            <!-- Enhanced Search and Filters for Payments -->
            <form class="search-form" method="GET">
                <input type="text" name="payment_search" placeholder="Search by user, amount, or method..." value="<?php echo htmlspecialchars($_GET['payment_search'] ?? ''); ?>" aria-label="Search Payments">
                <select name="payment_sort" aria-label="Sort By">
                    <option value="payment_date" <?php echo $payment_sort === 'payment_date' ? 'selected' : ''; ?>>Payment Date</option>
                    <option value="amount_paid" <?php echo $payment_sort === 'amount_paid' ? 'selected' : ''; ?>>Amount Paid</option>
                    <option value="created_at" <?php echo $payment_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                </select>
                <select name="payment_order" aria-label="Order">
                    <option value="DESC" <?php echo $payment_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $payment_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Payments Table -->
            <form method="POST" id="payments-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-payments" aria-label="Select All Payments"></th>
                            <th>ID</th>
                            <th>User Name</th>
                            <th>Fee Type</th>
                            <th>Amount Paid</th>
                            <th>Payment Date</th>
                            <th>Method</th>
                            <th>Receipt Number</th>
                            <th>Class</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="payment_ids[]" value="<?php echo $payment['id']; ?>" aria-label="Select Payment <?php echo $payment['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($payment['id']); ?></td>
                                <td data-label="User Name"><?php echo htmlspecialchars($payment['user_name']); ?></td>
                                <td data-label="Fee Type"><?php echo htmlspecialchars($payment['fee_type']); ?></td>
                                <td data-label="Amount Paid">$<?php echo number_format($payment['amount_paid'], 2); ?></td>
                                <td data-label="Payment Date"><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                                <td data-label="Method"><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                                <td data-label="Receipt Number"><?php echo htmlspecialchars($payment['receipt_number']); ?></td>
                                <td data-label="Class"><?php echo htmlspecialchars($payment['class_name']); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($payment['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_payment.php?id=<?php echo $payment['id']; ?>" class="action-icon edit" aria-label="Edit Payment"><i class="fas fa-edit"></i></a>
                                    <a href="view_payment.php?id=<?php echo $payment['id']; ?>" class="action-icon view" aria-label="View Payment"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this payment?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_payment">
                                        <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Payment"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Payments -->
                <div class="pagination">
                    <?php if ($payment_page > 1): ?>
                        <a href="?payment_page=<?php echo $payment_page - 1; ?>&payment_search=<?php echo urlencode($_GET['payment_search'] ?? ''); ?>&payment_sort=<?php echo $payment_sort; ?>&payment_order=<?php echo $payment_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $payment_page; ?> of <?php echo $total_payment_pages; ?></span>
                    <?php if ($payment_page < $total_payment_pages): ?>
                        <a href="?payment_page=<?php echo $payment_page + 1; ?>&payment_search=<?php echo urlencode($_GET['payment_search'] ?? ''); ?>&payment_sort=<?php echo $payment_sort; ?>&payment_order=<?php echo $payment_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Bulk Actions for Payments -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Payments">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_payments">Delete Selected</option>
                        <option value="bulk_export_payments">Export Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Debts Section -->
        <div class="section">
            <h2>Debts</h2>
            <!-- Enhanced Search and Filters for Debts -->
            <form class="search-form" method="GET">
                <input type="text" name="debt_search" placeholder="Search by user or amount..." value="<?php echo htmlspecialchars($_GET['debt_search'] ?? ''); ?>" aria-label="Search Debts">
                <select name="debt_sort" aria-label="Sort By">
                    <option value="created_at" <?php echo $debt_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                    <option value="amount_due" <?php echo $debt_sort === 'amount_due' ? 'selected' : ''; ?>>Amount Due</option>
                </select>
                <select name="debt_order" aria-label="Order">
                    <option value="DESC" <?php echo $debt_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $debt_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Debts Table -->
            <form method="POST" id="debts-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-debts" aria-label="Select All Debts"></th>
                            <th>ID</th>
                            <th>User Name</th>
                            <th>Fee Type</th>
                            <th>Amount Due</th>
                            <th>Academic Year</th>
                            <th>Term ID</th>
                            <th>Class</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($debts as $debt): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="debt_ids[]" value="<?php echo $debt['id']; ?>" aria-label="Select Debt <?php echo $debt['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($debt['id']); ?></td>
                                <td data-label="User Name"><?php echo htmlspecialchars($debt['user_name']); ?></td>
                                <td data-label="Fee Type"><?php echo htmlspecialchars($debt['fee_type']); ?></td>
                                <td data-label="Amount Due">$<?php echo number_format($debt['amount_due'], 2); ?></td>
                                <td data-label="Academic Year"><?php echo htmlspecialchars($debt['academic_year']); ?></td>
                                <td data-label="Term ID"><?php echo htmlspecialchars($debt['term_id']); ?></td>
                                <td data-label="Class"><?php echo htmlspecialchars($debt['class_name']); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($debt['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_debt.php?id=<?php echo $debt['id']; ?>" class="action-icon edit" aria-label="Edit Debt"><i class="fas fa-edit"></i></a>
                                    <a href="view_debt.php?id=<?php echo $debt['id']; ?>" class="action-icon view" aria-label="View Debt"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this debt?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_debt">
                                        <input type="hidden" name="debt_id" value="<?php echo $debt['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Debt"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Debts -->
                <div class="pagination">
                    <?php if ($debt_page > 1): ?>
                        <a href="?debt_page=<?php echo $debt_page - 1; ?>&debt_search=<?php echo urlencode($_GET['debt_search'] ?? ''); ?>&debt_sort=<?php echo $debt_sort; ?>&debt_order=<?php echo $debt_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $debt_page; ?> of <?php echo $total_debt_pages; ?></span>
                    <?php if ($debt_page < $total_debt_pages): ?>
                        <a href="?debt_page=<?php echo $debt_page + 1; ?>&debt_search=<?php echo urlencode($_GET['debt_search'] ?? ''); ?>&debt_sort=<?php echo $debt_sort; ?>&debt_order=<?php echo $debt_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Bulk Actions for Debts -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Debts">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_debts">Delete Selected</option>
                        <option value="bulk_export_debts">Export Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Audit Logs -->
        <div class="section audit-logs">
            <h2>Audit Logs</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audit_logs as $log): ?>
                        <tr>
                            <td data-label="Time"><?php echo htmlspecialchars($log['created_at']); ?></td>
                            <td data-label="Actor"><?php echo htmlspecialchars($log['actor_name']); ?></td>
                            <td data-label="Action"><?php echo htmlspecialchars($log['action']); ?></td>
                            <td data-label="Description"><?php echo htmlspecialchars($log['description']); ?></td>
                            <td data-label="IP Address"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <script>
        // Enhanced Select All Checkbox for Fees
        document.getElementById('select-all-fees').addEventListener('change', function() {
            document.querySelectorAll('input[name="fee_ids[]"]').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        // Select All Checkbox for Payments
        document.getElementById('select-all-payments').addEventListener('change', function() {
            document.querySelectorAll('input[name="payment_ids[]"]').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        // Select All Checkbox for Debts
        document.getElementById('select-all-debts').addEventListener('change', function() {
            document.querySelectorAll('input[name="debt_ids[]"]').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        // Confirm Bulk Actions and Deletes with enhanced messages
        document.getElementById('fees-form').addEventListener('submit', function(e) {
            const action = this.action.value;
            if (action && !confirm(`Are you sure you want to ${action.replace('bulk_', '').replace('_', ' ')} the selected fees? This action cannot be undone.`)) {
                e.preventDefault();
            }
        });
        document.getElementById('payments-form').addEventListener('submit', function(e) {
            const action = this.action.value;
            if (action && !confirm(`Are you sure you want to ${action.replace('bulk_', '').replace('_', ' ')} the selected payments? This action cannot be undone.`)) {
                e.preventDefault();
            }
        });
        document.getElementById('debts-form').addEventListener('submit', function(e) {
            const action = this.action.value;
            if (action && !confirm(`Are you sure you want to ${action.replace('bulk_', '').replace('_', ' ')} the selected debts? This action cannot be undone.`)) {
                e.preventDefault();
            }
        });
        // Add tooltips (requires tippy.js or similar; assume included in header)
        // For example: tippy('.action-icon', {content: 'Tooltip text'});
    </script>
    <style>
        /* Premium Modern Styles - Enhanced for International Standards (Accessibility, Responsiveness) */
        :root {
            --primary-sea-blue: #007bff;
            --accent-soft-cyan: #17a2b8;
            --accent-deep-navy: #343a40;
            --accent-slate-gray: #6c757d;
            --primary-white: #ffffff;
            --primary-black: #000000;
            --success-green: #28a745;
            --error-red: #dc3545;
            --warning-yellow: #ffc107;
            --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        body {
            font-family: var(--font-family);
            line-height: 1.6;
            color: var(--primary-black);
            background-color: #f8f9fa;
        }
        .fees-management {
            display: grid;
            gap: 32px;
            padding: 32px;
            max-width: 1800px;
            margin: 0 auto;
        }
        .page-title {
            font-size: 32px;
            font-weight: 700;
            color: var(--accent-deep-navy);
            text-align: center;
        }
        .section {
            background-color: var(--primary-white);
            border-radius: 16px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            padding: 24px;
            transition: box-shadow 0.3s;
        }
        .section:hover {
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
        }
        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: 500;
            transition: opacity 0.3s;
        }
        .alert.success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-green);
            border: 1px solid var(--success-green);
        }
        .alert.error {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--error-red);
            border: 1px solid var(--error-red);
        }
        .search-form {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .search-form input, .search-form select {
            padding: 12px 16px;
            border: 1px solid var(--accent-slate-gray);
            border-radius: 8px;
            flex: 1;
            min-width: 180px;
            transition: border-color 0.2s;
        }
        .search-form input:focus, .search-form select:focus {
            border-color: var(--primary-sea-blue);
            outline: none;
        }
        .search-form button {
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
        }
        .search-form button:hover {
            background-color: var(--accent-soft-cyan);
            transform: translateY(-2px);
        }
        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            font-size: 14px;
            margin-bottom: 24px;
        }
        .data-table th, .data-table td {
            padding: 16px;
            text-align: left;
            background-color: var(--primary-white);
        }
        .data-table th {
            background-color: var(--accent-deep-navy);
            color: var(--primary-white);
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .data-table tbody tr {
            transition: background-color 0.2s;
        }
        .data-table tbody tr:hover {
            background-color: #f1f3f5;
        }
        .data-table td {
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        .action-icon {
            margin-right: 12px;
            color: var(--primary-sea-blue);
            text-decoration: none;
            font-size: 18px;
            transition: color 0.2s, transform 0.1s;
        }
        .action-icon:hover {
            color: var(--accent-soft-cyan);
            transform: scale(1.1);
        }
        .action-icon.delete {
            color: var(--error-red);
            background: none;
            border: none;
            cursor: pointer;
        }
        .action-icon.delete:hover {
            color: #c82333;
        }
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 16px;
            margin-top: 16px;
        }
        .pagination a {
            padding: 10px 20px;
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            border-radius: 8px;
            text-decoration: none;
            transition: background-color 0.2s, transform 0.1s;
        }
        .pagination a:hover {
            background-color: var(--accent-soft-cyan);
            transform: translateY(-2px);
        }
        .pagination span {
            font-weight: 600;
            color: var(--accent-deep-navy);
        }
        .bulk-actions {
            display: flex;
            gap: 16px;
            margin-top: 16px;
            align-items: center;
        }
        .bulk-actions select, .bulk-actions button {
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid var(--accent-slate-gray);
            transition: border-color 0.2s;
        }
        .bulk-actions select:focus, .bulk-actions button:focus {
            border-color: var(--primary-sea-blue);
        }
        .bulk-actions button {
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            border: none;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
        }
        .bulk-actions button:hover {
            background-color: var(--accent-soft-cyan);
            transform: translateY(-2px);
        }
        .audit-logs {
            margin-top: 32px;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 24px;
        }
        .kpi-card {
            background-color: var(--primary-white);
            border-radius: 16px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            padding: 24px;
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .kpi-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
        }
        .kpi-card .icon {
            font-size: 40px;
            color: var(--primary-sea-blue);
            margin-bottom: 12px;
            display: block;
        }
        .kpi-card .value {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary-black);
        }
        .kpi-card .label {
            font-size: 16px;
            color: var(--accent-slate-gray);
            text-transform: uppercase;
        }
        .progress-bar {
            background-color: #e9ecef;
            border-radius: 4px;
            height: 8px;
            margin-top: 8px;
            overflow: hidden;
        }
        .progress {
            background-color: var(--success-green);
            height: 100%;
            transition: width 0.5s ease-in-out;
        }
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .action-button {
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 16px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background-color 0.2s, transform 0.1s;
        }
        .action-button:hover {
            background-color: var(--accent-soft-cyan);
            transform: translateY(-4px);
        }
        /* Enhanced Responsiveness for Mobile/Tablet */
        @media (max-width: 1200px) {
            .fees-management {
                padding: 24px;
            }
            .kpi-grid {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
        }
        @media (max-width: 992px) {
            .quick-actions {
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            }
            .kpi-grid {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            }
        }
        @media (max-width: 768px) {
            .search-form {
                flex-direction: column;
                align-items: stretch;
            }
            .search-form input, .search-form select, .search-form button {
                width: 100%;
                min-width: auto;
                margin-bottom: 8px;
            }
            .data-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            .data-table thead {
                display: none;
            }
            .data-table tbody tr {
                display: block;
                margin-bottom: 16px;
                border: 1px solid rgba(0, 0, 0, 0.1);
                border-radius: 8px;
                padding: 16px;
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
            }
            .data-table td {
                display: flex;
                justify-content: space-between;
                text-align: right;
                position: relative;
                padding: 8px 0;
                border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            }
            .data-table td:before {
                content: attr(data-label);
                font-weight: bold;
                flex: 1;
                text-align: left;
            }
            .data-table td:last-child {
                border-bottom: none;
            }
            .bulk-actions {
                flex-direction: column;
            }
            .bulk-actions select, .bulk-actions button {
                width: 100%;
                margin-bottom: 8px;
            }
            .pagination {
                flex-direction: column;
                gap: 8px;
            }
        }
        @media (max-width: 576px) {
            .page-title {
                font-size: 28px;
            }
            .action-icon {
                font-size: 16px;
            }
            .kpi-card .value {
                font-size: 28px;
            }
            .kpi-card .icon {
                font-size: 36px;
            }
            .section {
                padding: 16px;
            }
        }
        /* Accessibility Enhancements */
        [role="alert"] {
            animation: fadeIn 0.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        /* Dark Mode Support (Optional, toggle via JS or preference) */
        @media (prefers-color-scheme: dark) {
            body {
                background-color: #121212;
                color: #e0e0e0;
            }
            .section, .kpi-card {
                background-color: #1e1e1e;
            }
            .data-table th {
                background-color: #333;
            }
            .data-table tbody tr:hover {
                background-color: #2a2a2a;
            }
            .alert.success {
                background-color: rgba(40, 167, 69, 0.2);
            }
            .alert.error {
                background-color: rgba(220, 53, 69, 0.2);
            }
        }
    </style>
<?php include '../templates/admin_footer.php'; ?>