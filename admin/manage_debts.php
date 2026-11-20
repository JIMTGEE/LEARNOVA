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

// Arkesel API constants (replace with actual)
define('ARKESEL_API_KEY', 'your_arkesel_api_key_here');
define('ARKESEL_SENDER', 'EduSync');

// Handle actions: edit, delete, bulk, mark_paid
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token.';
        header("Location: manage_debts.php");
        exit();
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'edit':
                $debt_id = intval($_POST['debt_id'] ?? 0);
                $amount_due = floatval($_POST['amount_due'] ?? 0);
                $amount_paid = floatval($_POST['amount_paid'] ?? 0);
                $status = $_POST['status'] ?? 'unpaid';
                $send_sms = isset($_POST['send_sms']) ? true : false;

                if ($debt_id > 0 && $amount_due > 0 && $amount_paid >= 0 && in_array($status, ['unpaid', 'partial', 'paid'])) {
                    $stmt = $db->prepare("UPDATE debts SET amount_due = ?, amount_paid = ?, status = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$amount_due, $amount_paid, $status, $debt_id]);
                    log_activity($_SESSION['user_id'], 'edit_debt', "Edited debt ID: $debt_id");

                    // Send SMS if checked
                    if ($send_sms) {
                        sendDebtSMS($debt_id, 'update');
                    }

                    $_SESSION['success'] = 'Debt updated successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid input.';
                }
                break;

            case 'delete':
                $debt_id = intval($_POST['debt_id'] ?? 0);
                if ($debt_id > 0) {
                    $stmt = $db->prepare("DELETE FROM debts WHERE id = ?");
                    $stmt->execute([$debt_id]);
                    // Also delete related payments? Assuming cascade or manual
                    $db->prepare("DELETE FROM payments WHERE debt_id = ?")->execute([$debt_id]);
                    log_activity($_SESSION['user_id'], 'delete_debt', "Deleted debt ID: $debt_id");
                    $_SESSION['success'] = 'Debt deleted successfully.';
                }
                break;

            case 'bulk_delete':
                $debt_ids = array_filter(array_map('intval', $_POST['debt_ids'] ?? []));
                if (!empty($debt_ids)) {
                    $placeholders = implode(',', array_fill(0, count($debt_ids), '?'));
                    $db->prepare("DELETE FROM payments WHERE debt_id IN ($placeholders)")->execute($debt_ids);
                    $db->prepare("DELETE FROM debts WHERE id IN ($placeholders)")->execute($debt_ids);
                    log_activity($_SESSION['user_id'], 'bulk_delete_debts', "Deleted debts: " . implode(',', $debt_ids));
                    $_SESSION['success'] = 'Selected debts deleted.';
                }
                break;

            case 'bulk_mark_paid':
                $debt_ids = array_filter(array_map('intval', $_POST['debt_ids'] ?? []));
                if (!empty($debt_ids)) {
                    $placeholders = implode(',', array_fill(0, count($debt_ids), '?'));
                    $stmt = $db->prepare("UPDATE debts SET status = 'paid', amount_paid = amount_due, updated_at = NOW() WHERE id IN ($placeholders)");
                    $stmt->execute($debt_ids);
                    log_activity($_SESSION['user_id'], 'bulk_mark_paid_debts', "Marked paid debts: " . implode(',', $debt_ids));

                    // Send SMS for each
                    if (isset($_POST['send_sms']) && $_POST['send_sms'] == '1') {
                        foreach ($debt_ids as $debt_id) {
                            sendDebtSMS($debt_id, 'paid');
                        }
                    }

                    $_SESSION['success'] = 'Selected debts marked as paid.';
                }
                break;
        }
        header("Location: manage_debts.php");
        exit();
    }
}

// Function to send SMS for debt update/paid
function sendDebtSMS($debt_id, $type) {
    global $db;
    $debt_stmt = $db->prepare("SELECT d.*, f.fee_type, u.name AS student_name, u.phone_number AS student_phone FROM debts d JOIN fees f ON d.fee_id = f.id JOIN users u ON d.user_id = u.id WHERE d.id = ?");
    $debt_stmt->execute([$debt_id]);
    $debt = $debt_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$debt) return;

    $phones = [$debt['student_phone']];
    $parent_stmt = $db->prepare("SELECT u.phone_number FROM parent_student_mapping psm JOIN users u ON psm.parent_id = u.id WHERE psm.student_id = ? AND u.phone_number IS NOT NULL");
    $parent_stmt->execute([$debt['user_id']]);
    $phones = array_merge($phones, array_column($parent_stmt->fetchAll(PDO::FETCH_ASSOC), 'phone_number'));
    $phones = array_unique(array_filter($phones));

    if (!empty($phones)) {
        $sms_message = ($type === 'paid') ? "Debt for {$debt['fee_type']} has been marked as paid for {$debt['student_name']}. Thank you!" : "Debt update for {$debt['fee_type']}: Due {$debt['amount_due']}, Paid {$debt['amount_paid']}, Status: {$debt['status']} for {$debt['student_name']}.";

        $endpoint = 'https://sms.arkesel.com/api/v2/sms/send';
        $data = [
            'sender' => ARKESEL_SENDER,
            'message' => $sms_message,
            'recipients' => $phones
        ];

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'api-key: ' . ARKESEL_API_KEY
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            error_log("SMS failed for debt $debt_id: $response");
        }
    }
}

// Fetch debts with filters and pagination
$search = '%' . trim($_GET['search'] ?? '') . '%';
$status_filter = $_GET['status'] ?? '';
$year_filter = intval($_GET['academic_year_id'] ?? 0);
$term_filter = intval($_GET['term_id'] ?? 0);
$class_filter = intval($_GET['class_id'] ?? 0);
$page = intval($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

$query = "SELECT d.*, u.name AS student_name, f.fee_type, ay.year AS academic_year, t.name AS term, c.class_name 
          FROM debts d 
          JOIN users u ON d.user_id = u.id 
          JOIN fees f ON d.fee_id = f.id 
          JOIN academic_years ay ON d.academic_year_id = ay.id 
          LEFT JOIN terms t ON d.term_id = t.id 
          LEFT JOIN classes c ON d.class_id = c.id 
          WHERE (u.name LIKE ? OR f.fee_type LIKE ?) ";
$params = [$search, $search];

if ($status_filter && in_array($status_filter, ['unpaid', 'partial', 'paid'])) {
    $query .= "AND d.status = ? ";
    $params[] = $status_filter;
}
if ($year_filter > 0) {
    $query .= "AND d.academic_year_id = ? ";
    $params[] = $year_filter;
}
if ($term_filter > 0) {
    $query .= "AND d.term_id = ? ";
    $params[] = $term_filter;
}
if ($class_filter > 0) {
    $query .= "AND d.class_id = ? ";
    $params[] = $class_filter;
}

$query .= "ORDER BY d.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($query);
$stmt->execute($params);
$debts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count total
$count_query = "SELECT COUNT(*) FROM debts d 
                JOIN users u ON d.user_id = u.id 
                JOIN fees f ON d.fee_id = f.id 
                WHERE (u.name LIKE ? OR f.fee_type LIKE ?) ";
$count_params = [$search, $search];
if ($status_filter) {
    $count_query .= "AND d.status = ? ";
    $count_params[] = $status_filter;
}
if ($year_filter > 0) {
    $count_query .= "AND d.academic_year_id = ? ";
    $count_params[] = $year_filter;
}
if ($term_filter > 0) {
    $count_query .= "AND d.term_id = ? ";
    $count_params[] = $term_filter;
}
if ($class_filter > 0) {
    $count_query .= "AND d.class_id = ? ";
    $count_params[] = $class_filter;
}
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($count_params);
$total_debts = $count_stmt->fetchColumn() ?? 0;
$total_pages = ceil($total_debts / $limit);

// Fetch filters data
$years_stmt = $db->query("SELECT id, year FROM academic_years ORDER BY year DESC");
$academic_years = $years_stmt->fetchAll(PDO::FETCH_ASSOC);

$terms_stmt = $db->query("SELECT id, name FROM terms ORDER BY start_date ASC");
$terms = $terms_stmt->fetchAll(PDO::FETCH_ASSOC);

$classes_stmt = $db->query("SELECT id, class_name FROM classes ORDER BY class_name ASC");
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="manage-debts">
        <h1 class="page-title">Manage Debts</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <!-- Search and Filters -->
        <form class="search-form" method="GET">
            <input type="text" name="search" placeholder="Search by student or fee type..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
            <select name="status">
                <option value="">All Statuses</option>
                <option value="unpaid" <?php echo $status_filter === 'unpaid' ? 'selected' : ''; ?>>Unpaid</option>
                <option value="partial" <?php echo $status_filter === 'partial' ? 'selected' : ''; ?>>Partial</option>
                <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
            </select>
            <select name="academic_year_id">
                <option value="">All Years</option>
                <?php foreach ($academic_years as $year): ?>
                    <option value="<?php echo $year['id']; ?>" <?php echo $year_filter == $year['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($year['year']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="term_id">
                <option value="">All Terms</option>
                <?php foreach ($terms as $term): ?>
                    <option value="<?php echo $term['id']; ?>" <?php echo $term_filter == $term['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($term['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="class_id">
                <option value="">All Classes</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit"><i class="fas fa-search"></i> Filter</button>
        </form>

        <!-- Debts Table -->
        <form method="POST" id="debts-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all"></th>
                        <th>ID</th>
                        <th>Student</th>
                        <th>Fee Type</th>
                        <th>Amount Due</th>
                        <th>Amount Paid</th>
                        <th>Outstanding</th>
                        <th>Status</th>
                        <th>Academic Year</th>
                        <th>Term</th>
                        <th>Class</th>
                        <th>Created At</th>
                        <th>Updated At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($debts as $debt): ?>
                        <tr>
                            <td><input type="checkbox" name="debt_ids[]" value="<?php echo $debt['id']; ?>"></td>
                            <td><?php echo htmlspecialchars($debt['id']); ?></td>
                            <td><?php echo htmlspecialchars($debt['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($debt['fee_type']); ?></td>
                            <td><?php echo htmlspecialchars($debt['amount_due']); ?></td>
                            <td><?php echo htmlspecialchars($debt['amount_paid']); ?></td>
                            <td><?php echo htmlspecialchars($debt['amount_due'] - $debt['amount_paid']); ?></td>
                            <td><span class="status-badge <?php echo htmlspecialchars($debt['status']); ?>"><?php echo ucfirst(htmlspecialchars($debt['status'])); ?></span></td>
                            <td><?php echo htmlspecialchars($debt['academic_year']); ?></td>
                            <td><?php echo htmlspecialchars($debt['term'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($debt['class_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($debt['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($debt['updated_at']); ?></td>
                            <td>
                                <button type="button" class="action-icon edit" onclick="openEditModal(<?php echo json_encode($debt); ?>)"><i class="fas fa-edit"></i></button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this debt?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="debt_id" value="<?php echo $debt['id']; ?>">
                                    <button type="submit" class="action-icon delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&status=<?php echo urlencode($status_filter); ?>&academic_year_id=<?php echo $year_filter; ?>&term_id=<?php echo $term_filter; ?>&class_id=<?php echo $class_filter; ?>">&laquo; Previous</a>
                <?php endif; ?>
                <span>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&status=<?php echo urlencode($status_filter); ?>&academic_year_id=<?php echo $year_filter; ?>&term_id=<?php echo $term_filter; ?>&class_id=<?php echo $class_filter; ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>

            <!-- Bulk Actions -->
            <div class="bulk-actions">
                <select name="action">
                    <option value="">Bulk Actions</option>
                    <option value="bulk_mark_paid">Mark as Paid</option>
                    <option value="bulk_delete">Delete Selected</option>
                </select>
                <label><input type="checkbox" name="send_sms" value="1"> Send SMS for Bulk Actions</label>
                <button type="submit">Apply</button>
            </div>
        </form>

        <!-- Edit Modal -->
        <div id="edit-modal" class="modal" style="display: none;">
            <div class="modal-content">
                <h2>Edit Debt</h2>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="debt_id" id="edit-debt-id">
                    <label>Amount Due:</label>
                    <input type="number" name="amount_due" id="edit-amount-due" step="0.01" min="0" required>
                    <label>Amount Paid:</label>
                    <input type="number" name="amount_paid" id="edit-amount-paid" step="0.01" min="0" required>
                    <label>Status:</label>
                    <select name="status" id="edit-status">
                        <option value="unpaid">Unpaid</option>
                        <option value="partial">Partial</option>
                        <option value="paid">Paid</option>
                    </select>
                    <label><input type="checkbox" name="send_sms" checked> Send SMS Notification</label>
                    <button type="submit">Save Changes</button>
                    <button type="button" onclick="closeEditModal()">Cancel</button>
                </form>
            </div>
        </div>
    </section>

    <script>
        // Select All
        document.getElementById('select-all').addEventListener('change', function() {
            document.querySelectorAll('input[name="debt_ids[]"]').forEach(cb => cb.checked = this.checked);
        });

        // Edit Modal
        function openEditModal(debt) {
            document.getElementById('edit-debt-id').value = debt.id;
            document.getElementById('edit-amount-due').value = debt.amount_due;
            document.getElementById('edit-amount-paid').value = debt.amount_paid;
            document.getElementById('edit-status').value = debt.status;
            document.getElementById('edit-modal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('edit-modal').style.display = 'none';
        }
    </script>

    <style>
        /* Manage Debts Styles */
        .manage-debts { padding: 20px; }
        .search-form { display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
        .search-form input, .search-form select { flex: 1; min-width: 150px; padding: 12px; border-radius: 4px; border: 1px solid var(--accent-slate-gray); }
        .search-form button { background: var(--primary-sea-blue); color: var(--primary-white); padding: 12px; border: none; border-radius: 4px; cursor: pointer; }
        .data-table { width: 100%; border-collapse: collapse; table-layout: auto; }
        .data-table th, .data-table td { padding: 12px; text-align: left; border-bottom: 1px solid var(--accent-slate-gray); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .status-badge { padding: 4px 8px; border-radius: 4px; color: white; }
        .status-badge.unpaid { background: #dc3545; }
        .status-badge.partial { background: #ffc107; }
        .status-badge.paid { background: #28a745; }
        .action-icon { color: var(--primary-sea-blue); margin-right: 8px; cursor: pointer; }
        .action-icon.delete { color: #dc3545; }
        .pagination { text-align: center; margin: 20px 0; }
        .pagination a { padding: 8px 16px; background: var(--primary-sea-blue); color: white; border-radius: 4px; text-decoration: none; margin: 0 8px; }
        .bulk-actions { display: flex; gap: 16px; align-items: center; }
        .bulk-actions select, .bulk-actions button { padding: 12px; border-radius: 4px; }
        .bulk-actions button { background: var(--primary-sea-blue); color: white; border: none; cursor: pointer; }
        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; }
        .modal-content { background: white; padding: 20px; border-radius: 8px; width: 400px; }
        .alert { padding: 16px; border-radius: 4px; margin-bottom: 16px; }
        .alert.success { background: rgba(40,167,69,0.1); color: #28a745; }
        .alert.error { background: rgba(220,53,69,0.1); color: #dc3545; }
    </style>

<?php include '../templates/admin_footer.php'; ?>