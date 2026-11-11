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
$db = db();

// Include helpers and functions
try {
    require_once '../includes/helpers.php';
    require_once '../functions/csrf.php';
    require_once '../functions/log_activity.php';
} catch (Exception $e) {
    error_log("Error loading helpers/functions: " . $e->getMessage());
    $_SESSION['error'] = 'System error: Unable to load required files. Please check logs.';
    header("Location: dashboard.php");
    exit();
}

// Handle POST actions: delete, bulk delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: departments.php");
        exit();
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'delete':
                $dept_id = intval($_POST['dept_id'] ?? 0);
                if ($dept_id > 0) {
                    // Check if department is in use (e.g., by classes, users)
                    $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE department_id = ?");
                    $stmt->execute([$dept_id]);
                    $class_count = $stmt->fetchColumn();

                    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE department_id = ?");
                    $stmt->execute([$dept_id]);
                    $user_count = $stmt->fetchColumn();

                    if ($class_count > 0 || $user_count > 0) {
                        $_SESSION['error'] = 'Cannot delete department in use by classes or users.';
                    } else {
                        $stmt = $db->prepare("DELETE FROM departments WHERE id = ?");
                        $stmt->execute([$dept_id]);
                        log_activity($_SESSION['user_id'], 'delete_department', "Deleted department ID: $dept_id");
                        $_SESSION['success'] = 'Department deleted successfully.';
                    }
                } else {
                    $_SESSION['error'] = 'Invalid department ID.';
                }
                break;

            case 'bulk_delete':
                if (isset($_POST['dept_ids']) && is_array($_POST['dept_ids'])) {
                    $dept_ids = array_filter(array_map('intval', $_POST['dept_ids']), fn($id) => $id > 0);
                    if ($dept_ids) {
                        foreach ($dept_ids as $id) {
                            // Check usage per dept
                            $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE department_id = ?");
                            $stmt->execute([$id]);
                            $class_count = $stmt->fetchColumn();

                            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE department_id = ?");
                            $stmt->execute([$id]);
                            $user_count = $stmt->fetchColumn();

                            if ($class_count == 0 && $user_count == 0) {
                                $stmt = $db->prepare("DELETE FROM departments WHERE id = ?");
                                $stmt->execute([$id]);
                            }
                        }
                        log_activity($_SESSION['user_id'], 'bulk_delete_departments', "Deleted departments: " . implode(',', $dept_ids));
                        $_SESSION['success'] = 'Selected departments deleted (if not in use).';
                    }
                }
                break;
        }
        header("Location: departments.php");
        exit();
    }
}

// Fetch departments with search and pagination
$search = isset($_GET['search']) ? '%' . trim($_GET['search']) . '%' : '%';
$page = intval($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

$query = "SELECT * FROM departments WHERE name LIKE ? ORDER BY name ASC LIMIT ? OFFSET ?";
$params = [$search, $limit, $offset];

$stmt = $db->prepare($query);
$stmt->execute($params);
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Total count for pagination
$count_query = "SELECT COUNT(*) FROM departments WHERE name LIKE ?";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute([$search]);
$total_depts = $count_stmt->fetchColumn() ?? 0;
$total_pages = ceil($total_depts / $limit);

// Fetch audit logs for departments
$audit_logs = $db->query("SELECT ul.*, u.name AS actor_name FROM user_logs ul JOIN users u ON ul.user_id = u.id WHERE ul.action LIKE '%department%' ORDER BY ul.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

<section class="departments-management">
    <h1 class="page-title">Departments Management</h1>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <a href="add_department.php" class="action-button"><i class="fas fa-plus"></i> Add New Department</a>
    </div>

    <!-- Search -->
    <form class="search-form" method="GET">
        <input type="text" name="search" placeholder="Search by department name..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
        <button type="submit"><i class="fas fa-search"></i> Search</button>
    </form>

    <!-- Departments Table -->
    <form method="POST" id="depts-form">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <table class="data-table">
            <thead>
                <tr>
                    <th><input type="checkbox" id="select-all"></th>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Created At</th>
                    <th>Updated At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($departments as $dept): ?>
                    <tr>
                        <td><input type="checkbox" name="dept_ids[]" value="<?php echo $dept['id']; ?>"></td>
                        <td><?php echo htmlspecialchars($dept['id']); ?></td>
                        <td><?php echo htmlspecialchars($dept['name']); ?></td>
                        <td><?php echo htmlspecialchars($dept['created_at'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($dept['updated_at'] ?? 'N/A'); ?></td>
                        <td>
                            <a href="edit_department.php?id=<?php echo $dept['id']; ?>" class="action-icon edit"><i class="fas fa-edit"></i></a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this department?');">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="dept_id" value="<?php echo $dept['id']; ?>">
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
                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>">&laquo; Previous</a>
            <?php endif; ?>
            <span>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>

        <!-- Bulk Actions -->
        <div class="bulk-actions">
            <select name="action">
                <option value="">Bulk Actions</option>
                <option value="bulk_delete">Delete Selected</option>
            </select>
            <button type="submit">Apply</button>
        </div>
    </form>

    <!-- Audit Logs -->
    <div class="audit-logs">
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
                        <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                        <td><?php echo htmlspecialchars($log['actor_name']); ?></td>
                        <td><?php echo htmlspecialchars($log['action']); ?></td>
                        <td><?php echo htmlspecialchars($log['description']); ?></td>
                        <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
    // Select All Checkbox
    document.getElementById('select-all').addEventListener('change', function() {
        document.querySelectorAll('input[name="dept_ids[]"]').forEach(checkbox => {
            checkbox.checked = this.checked;
        });
    });
    // Confirm Bulk Actions
    document.getElementById('depts-form').addEventListener('submit', function(e) {
        const action = this.action.value;
        if (action && !confirm(`Are you sure you want to ${action.replace('bulk_', '')} the selected departments?`)) {
            e.preventDefault();
        }
    });
</script>

<style>
    /* Styles similar to users.php */
    .departments-management {
        display: grid;
        gap: 24px;
        padding: 20px;
        max-width: 1200px;
        margin: 0 auto;
    }
    .alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 16px;
        font-weight: 500;
    }
    .alert.success { background-color: rgba(40, 167, 69, 0.1); color: #28a745; }
    .alert.error { background-color: rgba(220, 53, 69, 0.1); color: #dc3545; }
    .search-form {
        display: flex;
        gap: 16px;
        align-items: center;
        flex-wrap: wrap;
    }
    .search-form input {
        padding: 12px;
        border: 1px solid var(--accent-slate-gray);
        border-radius: 4px;
        flex: 1;
        min-width: 200px;
    }
    .search-form button {
        background-color: var(--primary-sea-blue);
        color: var(--primary-white);
        padding: 12px 24px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .search-form button:hover {
        background-color: var(--accent-soft-cyan);
    }
    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }
    .data-table th, .data-table td {
        padding: 12px;
        text-align: left;
        border-bottom: 1px solid rgba(0, 0, 0, 0.1);
    }
    .data-table th {
        background-color: var(--accent-deep-navy);
        color: var(--primary-white);
        font-weight: 600;
    }
    .action-icon {
        margin-right: 8px;
        color: var(--primary-sea-blue);
        text-decoration: none;
        font-size: 18px;
        transition: color 0.2s;
    }
    .action-icon:hover {
        color: var(--accent-soft-cyan);
    }
    .action-icon.delete {
        color: #dc3545;
        background: none;
        border: none;
        cursor: pointer;
    }
    .action-icon.delete:hover {
        color: #ff0000;
    }
    .pagination {
        display: flex;
        justify-content: center;
        gap: 16px;
        margin-top: 16px;
    }
    .pagination a {
        padding: 8px 16px;
        background-color: var(--primary-sea-blue);
        color: var(--primary-white);
        border-radius: 4px;
        text-decoration: none;
        transition: background-color 0.2s;
    }
    .pagination a:hover {
        background-color: var(--accent-soft-cyan);
    }
    .bulk-actions {
        display: flex;
        gap: 16px;
        margin-top: 16px;
        align-items: center;
    }
    .bulk-actions select, .bulk-actions button {
        padding: 12px;
        border-radius: 4px;
        border: 1px solid var(--accent-slate-gray);
    }
    .bulk-actions button {
        background-color: var(--primary-sea-blue);
        color: var(--primary-white);
        border: none;
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .bulk-actions button:hover {
        background-color: var(--accent-soft-cyan);
    }
    .audit-logs {
        margin-top: 32px;
    }
    /* Responsiveness */
    @media (max-width: 768px) {
        .data-table {
            font-size: 12px;
        }
        .action-icon {
            font-size: 16px;
        }
    }
</style>

<?php include '../templates/admin_footer.php'; ?>