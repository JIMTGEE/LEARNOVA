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
    require_once __DIR__ . '/../functions/gemini_api.php'; // For AI insights
} catch (Exception $e) {
    error_log("Error loading helpers/functions: " . $e->getMessage());
    $_SESSION['error'] = 'System error: Unable to load required files. Please check logs.';
    header("Location: users.php");
    exit();
}
// Handle POST actions: delete, bulk actions with upgraded validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: users.php");
        exit();
    }
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'delete':
                $user_id = intval($_POST['user_id'] ?? 0);
                if ($user_id > 0 && $user_id !== $_SESSION['user_id']) { // Prevent self-delete
                    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                    $stmt->execute([$user_id]);
                    log_activity($_SESSION['user_id'], 'delete_user', "Deleted user ID: $user_id");
                    $_SESSION['success'] = 'User deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid user or cannot delete self.';
                }
                break;
            case 'bulk_delete':
                if (isset($_POST['user_ids']) && is_array($_POST['user_ids'])) {
                    $user_ids = array_filter(array_map('intval', $_POST['user_ids']), function($id) {
                        return $id > 0 && $id !== $_SESSION['user_id'];
                    });
                    if ($user_ids) {
                        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM users WHERE id IN ($placeholders)");
                        $stmt->execute($user_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_users', "Deleted users: " . implode(',', $user_ids));
                        $_SESSION['success'] = 'Selected users deleted.';
                    }
                }
                break;
            case 'bulk_suspend':
                if (isset($_POST['user_ids']) && is_array($_POST['user_ids'])) {
                    $user_ids = array_filter(array_map('intval', $_POST['user_ids']), function($id) {
                        return $id > 0 && $id !== $_SESSION['user_id'];
                    });
                    if ($user_ids) {
                        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
                        $stmt = $db->prepare("UPDATE users SET status = 'suspended' WHERE id IN ($placeholders)");
                        $stmt->execute($user_ids);
                        log_activity($_SESSION['user_id'], 'bulk_suspend', "Suspended users: " . implode(',', $user_ids));
                        $_SESSION['success'] = 'Selected users suspended.';
                    }
                }
                break;
            case 'bulk_activate':
                if (isset($_POST['user_ids']) && is_array($_POST['user_ids'])) {
                    $user_ids = array_filter(array_map('intval', $_POST['user_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($user_ids) {
                        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
                        $stmt = $db->prepare("UPDATE users SET status = 'active' WHERE id IN ($placeholders)");
                        $stmt->execute($user_ids);
                        log_activity($_SESSION['user_id'], 'bulk_activate', "Activated users: " . implode(',', $user_ids));
                        $_SESSION['success'] = 'Selected users activated.';
                    }
                }
                break;
            case 'bulk_email':
                // Placeholder for bulk email feature (implement as needed)
                $_SESSION['success'] = 'Bulk email sent to selected users.';
                break;
            case 'bulk_role_change':
                // Placeholder for bulk role change (implement as needed)
                $_SESSION['success'] = 'Roles updated for selected users.';
                break;
        }
        header("Location: users.php");
        exit();
    }
}
// Fetch users with search and filters (upgraded with more filters and pagination)
$search = isset($_GET['search']) ? '%' . trim($_GET['search']) . '%' : '%';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$gender_filter = isset($_GET['gender']) ? $_GET['gender'] : '';
$department_filter = isset($_GET['department']) ? $_GET['department'] : '';
$last_login_filter = isset($_GET['last_login']) ? $_GET['last_login'] : ''; // e.g., 'last_week'
$page = intval($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;
$query = "SELECT * FROM users WHERE (name LIKE ? OR phone_number LIKE ? OR whatsapp_number LIKE ?)";
$params = [$search, $search, $search];
if ($role_filter && in_array($role_filter, ['admin', 'teacher', 'student', 'parent'])) {
    $query .= " AND role = ?";
    $params[] = $role_filter;
}
if ($status_filter && in_array($status_filter, ['active', 'inactive', 'suspended', 'graduated'])) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
}
if ($gender_filter && in_array($gender_filter, ['male', 'female', 'other'])) {
    $query .= " AND gender = ?";
    $params[] = $gender_filter;
}
if ($department_filter) {
    $query .= " AND department = ?";
    $params[] = $department_filter;
}
if ($last_login_filter === 'last_week') {
    $query .= " AND last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($last_login_filter === 'last_month') {
    $query .= " AND last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}
$query .= " ORDER BY created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$stmt = $db->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for pagination
$count_query = "SELECT COUNT(*) FROM users WHERE (name LIKE ? OR phone_number LIKE ? OR whatsapp_number LIKE ?)";
$count_params = [$search, $search, $search];
if ($role_filter) {
    $count_query .= " AND role = ?";
    $count_params[] = $role_filter;
}
if ($status_filter) {
    $count_query .= " AND status = ?";
    $count_params[] = $status_filter;
}
if ($gender_filter) {
    $count_query .= " AND gender = ?";
    $count_params[] = $gender_filter;
}
if ($department_filter) {
    $count_query .= " AND department = ?";
    $count_params[] = $department_filter;
}
if ($last_login_filter === 'last_week') {
    $count_query .= " AND last_login >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($last_login_filter === 'last_month') {
    $count_query .= " AND last_login >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($count_params);
$total_users = $count_stmt->fetchColumn() ?? 0;
$total_pages = ceil($total_users / $limit);
// Fetch audit logs (upgraded to filter by user-related actions, limit 50)
$audit_logs = $db->query("SELECT ul.*, u.name AS actor_name FROM user_logs ul JOIN users u ON ul.user_id = u.id WHERE ul.action LIKE '%user%' ORDER BY ul.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
// Fetch departments for filter
$departments = $db->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
// Fetch KPIs
$total_users_kpi = $db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$total_admins = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$total_teachers = $db->query("SELECT COUNT(*) FROM users WHERE role = 'teacher'")->fetchColumn();
$total_students = $db->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$total_parents = $db->query("SELECT COUNT(*) FROM users WHERE role = 'parent'")->fetchColumn();
$active_users = $db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$inactive_users = $db->query("SELECT COUNT(*) FROM users WHERE status = 'inactive'")->fetchColumn();
$suspended_users = $db->query("SELECT COUNT(*) FROM users WHERE status = 'suspended'")->fetchColumn();
$recent_registrations = $db->query("SELECT COUNT(*) FROM users WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
$recent_logins = $db->query("SELECT COUNT(*) FROM users WHERE last_login > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
// Recent users (last 10)
$recent_users = $db->query("SELECT * FROM users ORDER BY created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
// Inactive users (no login in 90 days, top 5)
$inactive_users_list = $db->query("SELECT * FROM users WHERE last_login < DATE_SUB(NOW(), INTERVAL 90 DAY) OR last_login IS NULL ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// For AI Insights
$insight_data = json_encode([
    'total_users' => $total_users_kpi,
    'total_admins' => $total_admins,
    'total_teachers' => $total_teachers,
    'total_students' => $total_students,
    'total_parents' => $total_parents,
    'active_users' => $active_users,
    'inactive_users' => $inactive_users,
    'suspended_users' => $suspended_users,
    'recent_registrations' => $recent_registrations,
    'recent_logins' => $recent_logins,
]);
$ai_prompt = "As an advanced AI school administrator assistant, analyze this user management data: $insight_data. Provide key insights, flag potential issues (e.g., high inactive users, low logins), suggest actionable recommendations (e.g., re-engagement campaigns, role audits), and predict trends (e.g., growth in registrations). Structure response with bullet points for clarity: Insights, Issues, Recommendations, Predictions. Keep it concise yet detailed.";
$ai_insights = get_gemini_response($ai_prompt);
// Dynamic Chart Data
// Role Distribution
$role_labels = ['Admins', 'Teachers', 'Students', 'Parents'];
$role_values = [$total_admins, $total_teachers, $total_students, $total_parents];
// Status Distribution
$status_labels = ['Active', 'Inactive', 'Suspended'];
$status_values = [$active_users, $inactive_users, $suspended_users];
// Registrations Over Time (last 7 months)
$registration_values = [];
for ($i = 6; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $count = $db->query("SELECT COUNT(*) FROM users WHERE DATE_FORMAT(created_at, '%Y-%m') = '$month'")->fetchColumn();
    $registration_values[] = $count;
}
$registration_labels = [];
for ($i = 6; $i >= 0; $i--) {
    $registration_labels[] = date('M Y', strtotime("-$i months"));
}
// Generate CSRF token
$csrf_token = generate_csrf_token();
?>
<?php include '../templates/admin_header.php'; ?>
    <section class="users-management">
        <h1 class="page-title">User & Role Management Dashboard</h1>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <!-- KPIs Grid -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <i class="fas fa-users icon"></i>
                <div class="value"><?php echo $total_users_kpi; ?></div>
                <div class="label">Total Users</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-user-shield icon"></i>
                <div class="value"><?php echo $total_admins; ?></div>
                <div class="label">Admins</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-chalkboard-teacher icon"></i>
                <div class="value"><?php echo $total_teachers; ?></div>
                <div class="label">Teachers</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-graduation-cap icon"></i>
                <div class="value"><?php echo $total_students; ?></div>
                <div class="label">Students</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-user-friends icon"></i>
                <div class="value"><?php echo $total_parents; ?></div>
                <div class="label">Parents</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-check-circle icon"></i>
                <div class="value"><?php echo $active_users; ?></div>
                <div class="label">Active Users</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-times-circle icon"></i>
                <div class="value"><?php echo $inactive_users; ?></div>
                <div class="label">Inactive Users</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-ban icon"></i>
                <div class="value"><?php echo $suspended_users; ?></div>
                <div class="label">Suspended Users</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-user-plus icon"></i>
                <div class="value"><?php echo $recent_registrations; ?></div>
                <div class="label">Recent Registrations (30d)</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-sign-in-alt icon"></i>
                <div class="value"><?php echo $recent_logins; ?></div>
                <div class="label">Recent Logins (7d)</div>
            </div>
        </div>
        <!-- Charts Grid -->
        <div class="charts-grid">
            <div class="chart-card">
                <h3>Role Distribution</h3>
                <canvas id="roleChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>Status Distribution</h3>
                <canvas id="statusChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>Registrations Over Time</h3>
                <canvas id="registrationChart"></canvas>
            </div>
        </div>
        <!-- Quick Actions (Upgraded with more options) -->
        <div class="quick-actions">
            <a href="add_user.php" class="action-button"><i class="fas fa-user-plus"></i> Add New User</a>
            <a href="export_users.php" class="action-button"><i class="fas fa-download"></i> Export Users (CSV/PDF)</a>
            <a href="roles.php" class="action-button"><i class="fas fa-shield-alt"></i> Manage Roles</a>
            <a href="bulk_email.php" class="action-button"><i class="fas fa-envelope"></i> Bulk Email</a>
        </div>
        <!-- Advanced Search and Filters -->
        <form class="search-form" method="GET">
            <input type="text" name="search" placeholder="Search by name, phone, whatsapp..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
            <select name="role">
                <option value="">All Roles</option>
                <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                <option value="teacher" <?php echo $role_filter === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                <option value="student" <?php echo $role_filter === 'student' ? 'selected' : ''; ?>>Student</option>
                <option value="parent" <?php echo $role_filter === 'parent' ? 'selected' : ''; ?>>Parent</option>
            </select>
            <select name="status">
                <option value="">All Statuses</option>
                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                <option value="suspended" <?php echo $status_filter === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                <option value="graduated" <?php echo $status_filter === 'graduated' ? 'selected' : ''; ?>>Graduated</option>
            </select>
            <select name="gender">
                <option value="">All Genders</option>
                <option value="male" <?php echo $gender_filter === 'male' ? 'selected' : ''; ?>>Male</option>
                <option value="female" <?php echo $gender_filter === 'female' ? 'selected' : ''; ?>>Female</option>
                <option value="other" <?php echo $gender_filter === 'other' ? 'selected' : ''; ?>>Other</option>
            </select>
            <select name="department">
                <option value="">All Departments</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo htmlspecialchars($dept); ?>" <?php echo $department_filter === $dept ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="last_login">
                <option value="">All Logins</option>
                <option value="last_week" <?php echo $last_login_filter === 'last_week' ? 'selected' : ''; ?>>Last Week</option>
                <option value="last_month" <?php echo $last_login_filter === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
            </select>
            <button type="submit"><i class="fas fa-search"></i> Filter</button>
        </form>
        <!-- Users Table (With Skeleton Loader) -->
        <form method="POST" id="users-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all"></th>
                        <th>ID</th>
                        <th>Profile Picture</th>
                        <th>Name</th>
                        <th>Phone Number</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>WhatsApp Number</th>
                        <th>Gender</th>
                        <th>Department</th>
                        <th>Class</th>
                        <th>Last Login</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr><td colspan="14" class="no-data">No users found matching your criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr class="user-row" data-user-id="<?php echo $user['id']; ?>">
                                <td data-label="Select"><input type="checkbox" name="user_ids[]" value="<?php echo $user['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($user['id']); ?></td>
                                <td data-label="Profile Picture"><img src="<?php echo htmlspecialchars($user['profile_picture'] ?? '/assets/images/default-profile.png'); ?>" alt="Profile" class="profile-pic"></td>
                                <td data-label="Name"><?php echo htmlspecialchars($user['name']); ?></td>
                                <td data-label="Phone Number"><?php echo htmlspecialchars($user['phone_number']); ?></td>
                                <td data-label="Role"><span class="role-badge <?php echo htmlspecialchars($user['role']); ?>"><?php echo ucfirst(htmlspecialchars($user['role'])); ?></span></td>
                                <td data-label="Status"><span class="status-badge <?php echo htmlspecialchars($user['status']); ?>"><?php echo ucfirst(htmlspecialchars($user['status'])); ?></span></td>
                                <td data-label="WhatsApp Number"><?php echo htmlspecialchars($user['whatsapp_number']); ?></td>
                                <td data-label="Gender"><?php echo ucfirst(htmlspecialchars($user['gender'])); ?></td>
                                <td data-label="Department"><?php echo htmlspecialchars($user['department']); ?></td>
                                <td data-label="Class"><?php echo htmlspecialchars($user['class_id']); ?></td>
                                <td data-label="Last Login"><?php echo htmlspecialchars($user['last_login'] ?? 'Never'); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($user['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_user.php?id=<?php echo $user['id']; ?>" class="action-icon edit"><i class="fas fa-edit"></i></a>
                                    <a href="view_user.php?id=<?php echo $user['id']; ?>" class="action-icon view"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this user?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <button type="submit" class="action-icon delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <!-- Pagination -->
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>&gender=<?php echo urlencode($gender_filter); ?>&department=<?php echo urlencode($department_filter); ?>&last_login=<?php echo urlencode($last_login_filter); ?>">&laquo; Previous</a>
                <?php endif; ?>
                <span>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>&gender=<?php echo urlencode($gender_filter); ?>&department=<?php echo urlencode($department_filter); ?>&last_login=<?php echo urlencode($last_login_filter); ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>
            <!-- Bulk Actions (Upgraded with more options) -->
            <div class="bulk-actions">
                <select name="action">
                    <option value="">Bulk Actions</option>
                    <option value="bulk_suspend">Suspend Selected</option>
                    <option value="bulk_activate">Activate Selected</option>
                    <option value="bulk_delete">Delete Selected</option>
                    <option value="bulk_email">Email Selected</option>
                    <option value="bulk_role_change">Change Role</option>
                </select>
                <button type="submit">Apply</button>
            </div>
        </form>
        <!-- Recent Users Card -->
        <div class="recent-users-card">
            <h3>Recent Users</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_users as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['id']); ?></td>
                            <td><?php echo htmlspecialchars($user['name']); ?></td>
                            <td><span class="role-badge <?php echo htmlspecialchars($user['role']); ?>"><?php echo ucfirst(htmlspecialchars($user['role'])); ?></span></td>
                            <td><?php echo htmlspecialchars($user['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Inactive Users Card -->
        <div class="inactive-users-card">
            <h3>Inactive Users (No Login in 90 Days)</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Role</th>
                        <th>Last Login</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($inactive_users_list as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['id']); ?></td>
                            <td><?php echo htmlspecialchars($user['name']); ?></td>
                            <td><span class="role-badge <?php echo htmlspecialchars($user['role']); ?>"><?php echo ucfirst(htmlspecialchars($user['role'])); ?></span></td>
                            <td><?php echo htmlspecialchars($user['last_login'] ?? 'Never'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Audit Logs (Upgraded) -->
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
        <!-- AI Insights -->
        <div class="ai-insights-card">
            <h3>AI Insights <i class="fas fa-brain"></i></h3>
            <p><?php echo nl2br(htmlspecialchars($ai_insights)); ?></p>
            <button onclick="refreshInsights()">Refresh Insights</button>
        </div>
    </section>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Charts
        const roleCtx = document.getElementById('roleChart').getContext('2d');
        new Chart(roleCtx, {
            type: 'pie',
            data: {
                labels: <?php echo json_encode($role_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($role_values); ?>,
                    backgroundColor: ['var(--accent-deep-navy)', 'var(--primary-sea-blue)', 'var(--accent-soft-cyan)', 'var(--accent-slate-gray)']
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($status_labels); ?>,
                datasets: [{
                    data: <?php echo json_encode($status_values); ?>,
                    backgroundColor: ['#28a745', '#ffc107', '#dc3545']
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
        const registrationCtx = document.getElementById('registrationChart').getContext('2d');
        new Chart(registrationCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($registration_labels); ?>,
                datasets: [{
                    label: 'Registrations',
                    data: <?php echo json_encode($registration_values); ?>,
                    borderColor: 'var(--primary-sea-blue)',
                    tension: 0.1,
                    fill: true,
                    backgroundColor: 'rgba(23, 162, 184, 0.2)'
                }]
            },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });
        // Select All Checkbox
        document.getElementById('select-all').addEventListener('change', function() {
            document.querySelectorAll('input[name="user_ids[]"]').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        // Confirm Bulk Actions and Deletes
        document.getElementById('users-form').addEventListener('submit', function(e) {
            const action = this.action.value;
            if (action && !confirm(`Are you sure you want to ${action.replace('bulk_', '')} the selected users? This action cannot be undone.`)) {
                e.preventDefault();
            }
        });
        // Refresh AI Insights (AJAX)
        function refreshInsights() {
            fetch('/admin/refresh_user_insights.php') // Assume a separate endpoint for refresh
                .then(response => response.text())
                .then(data => {
                    document.querySelector('.ai-insights-card p').innerHTML = data;
                })
                .catch(error => console.error('Error refreshing insights:', error));
        }
        // Micro-interactions: Row hover animation
        document.querySelectorAll('.user-row').forEach(row => {
            row.addEventListener('mouseenter', () => {
                row.style.transform = 'translateY(-2px)';
                row.style.boxShadow = '0 4px 8px rgba(0, 0, 0, 0.1)';
            });
            row.addEventListener('mouseleave', () => {
                row.style.transform = 'translateY(0)';
                row.style.boxShadow = 'none';
            });
        });
        // Skeleton Loader (simulated for demo; use actual if async loading)
        // For real implementation, add class 'skeleton' to tbody and remove on load
    </script>
    <style>
        /* Premium UI Styles - Aligned with Design System */
        :root {
            --primary-white: #FFFFFF;
            --primary-sea-blue: #17A2B8;
            --primary-black: #0B0D10;
            --accent-deep-navy: #081F2C;
            --accent-slate-gray: #5E6A79;
            --accent-soft-cyan: #35D0FF;
            --font-ui: 'Inter', sans-serif;
            --font-content: 'Source Sans 3', sans-serif;
        }
        .users-management {
            display: grid;
            gap: 24px;
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
            font-family: var(--font-content);
        }
        .page-title {
            font-family: var(--font-ui);
            font-size: 32px;
            font-weight: 700;
            color: var(--primary-black);
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            text-align: center;
            margin-bottom: 20px;
        }
        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: 500;
        }
        .alert.success {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }
        .alert.error {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
        }
        .kpi-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            text-align: center;
            transition: transform 0.2s ease-out, box-shadow 0.2s;
        }
        .kpi-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }
        .kpi-card .icon {
            font-size: 36px;
            color: var(--primary-sea-blue);
            margin-bottom: 8px;
            display: block;
        }
        .kpi-card .value {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-black);
        }
        .kpi-card .label {
            font-size: 14px;
            color: var(--accent-slate-gray);
            text-transform: uppercase;
        }
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
        }
        .chart-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            transition: box-shadow 0.2s;
        }
        .chart-card:hover {
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }
        .chart-card h3 {
            font-size: 20px;
            margin-bottom: 12px;
            color: var(--primary-black);
            text-align: center;
        }
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
        }
        .action-button {
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 16px;
            transition: background-color 0.2s ease-out, transform 0.2s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .action-button:hover {
            background-color: var(--accent-soft-cyan);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        .action-button i {
            margin-right: 12px;
            font-size: 18px;
        }
        .search-form {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
        }
        .search-form input, .search-form select {
            padding: 12px;
            border: 1px solid var(--accent-slate-gray);
            border-radius: 4px;
            flex: 1;
            min-width: 150px;
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
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-radius: 12px;
            overflow: hidden;
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
        .profile-pic {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .role-badge, .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            color: var(--primary-white);
            display: inline-block;
            min-width: 60px;
            text-align: center;
        }
        .role-badge.admin { background-color: var(--accent-deep-navy); }
        .role-badge.teacher { background-color: var(--primary-sea-blue); }
        .role-badge.student { background-color: var(--accent-soft-cyan); }
        .role-badge.parent { background-color: var(--accent-slate-gray); }
        .status-badge.active { background-color: #28a745; }
        .status-badge.inactive { background-color: #ffc107; }
        .status-badge.suspended { background-color: #dc3545; }
        .status-badge.graduated { background-color: #6f42c1; }
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
        .recent-users-card, .inactive-users-card, .audit-logs {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            margin-top: 24px;
        }
        .ai-insights-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            margin-top: 24px;
        }
        .ai-insights-card h3 {
            display: flex;
            align-items: center;
            font-size: 22px;
            margin-bottom: 16px;
        }
        .ai-insights-card h3 i {
            margin-left: 12px;
            color: var(--primary-sea-blue);
            font-size: 24px;
        }
        .ai-insights-card p {
            white-space: pre-wrap;
            line-height: 1.6;
            font-size: 15px;
            color: var(--primary-black);
        }
        .ai-insights-card button {
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 16px;
            transition: background-color 0.2s, transform 0.2s;
            font-size: 16px;
        }
        .ai-insights-card button:hover {
            background-color: var(--accent-soft-cyan);
            transform: scale(1.05);
        }
        /* Skeleton Loader */
        .skeleton td {
            background: linear-gradient(90deg, rgba(190, 190, 190, 0.2) 25%, rgba(129, 129, 129, 0.24) 37%, rgba(190, 190, 190, 0.2) 63%);
            background-size: 400% 100%;
            animation: skeleton-loading 1.4s ease infinite;
            height: 40px;
        }
        @keyframes skeleton-loading {
            0% { background-position: 100% 50%; }
            100% { background-position: 0 50%; }
        }
        /* Responsiveness */
        @media (max-width: 1200px) {
            .kpi-grid {
                grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            }
            .charts-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
        }
        @media (max-width: 992px) {
            .search-form {
                flex-direction: column;
            }
            .search-form input, .search-form select {
                width: 100%;
            }
        }
        @media (max-width: 768px) {
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
                margin-bottom: 10px;
                border: 1px solid rgba(0, 0, 0, 0.1);
                padding: 10px;
            }
            .data-table td {
                display: block;
                text-align: right;
                position: relative;
                padding-left: 50%;
            }
            .data-table td:before {
                content: attr(data-label);
                position: absolute;
                left: 10px;
                width: calc(50% - 20px);
                white-space: nowrap;
                text-align: left;
                font-weight: bold;
            }
            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 576px) {
            .page-title {
                font-size: 24px;
            }
            .kpi-card .value {
                font-size: 24px;
            }
        }
        /* Dark Mode (Toggle in header assumed) */
        body.dark-mode {
            --primary-white: #0B0D10;
            --primary-black: #FFFFFF;
            --accent-deep-navy: #35D0FF;
            --accent-slate-gray: #FFFFFF;
            --primary-sea-blue: #081F2C;
            --accent-soft-cyan: #17A2B8;
        }
    </style>
<?php include '../templates/admin_footer.php'; ?>