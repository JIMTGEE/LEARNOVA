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
// Handle POST actions: bulk actions with upgraded validation (e.g., bulk mark present/absent)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: attendance.php");
        exit();
    }
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'bulk_mark':
                $status = $_POST['status'] ?? '';
                if (!in_array($status, ['present', 'absent', 'late'])) {
                    $_SESSION['error'] = 'Invalid status.';
                    break;
                }
                if (isset($_POST['attendance_ids']) && is_array($_POST['attendance_ids'])) {
                    $attendance_ids = array_filter(array_map('intval', $_POST['attendance_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($attendance_ids) {
                        $placeholders = implode(',', array_fill(0, count($attendance_ids), '?'));
                        $stmt = $db->prepare("UPDATE attendance SET status = ? WHERE id IN ($placeholders)");
                        array_unshift($attendance_ids, $status);
                        $stmt->execute($attendance_ids);
                        log_activity($_SESSION['user_id'], 'bulk_mark_attendance', "Marked attendance as $status for IDs: " . implode(',', array_slice($attendance_ids, 1)));
                        $_SESSION['success'] = "Selected attendances marked as $status.";
                    }
                }
                break;
            // Add more bulk actions if needed, e.g., bulk_delete
        }
        header("Location: attendance.php");
        exit();
    }
}
// Fetch attendances with search and filters (upgraded with pagination)
$search_user = isset($_GET['search_user']) ? '%' . trim($_GET['search_user']) . '%' : '%';
$class_filter = isset($_GET['class']) ? intval($_GET['class']) : 0;
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$page = intval($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;
$query = "SELECT a.*, u.name AS user_name, c.class_name FROM attendance a JOIN users u ON a.user_id = u.id JOIN classes c ON a.class_id = c.id WHERE u.name LIKE ?";
$params = [$search_user];
if ($class_filter > 0) {
    $query .= " AND a.class_id = ?";
    $params[] = $class_filter;
}
if ($date_filter) {
    $query .= " AND a.date = ?";
    $params[] = $date_filter;
}
if ($status_filter && in_array($status_filter, ['present', 'absent', 'late'])) {
    $query .= " AND a.status = ?";
    $params[] = $status_filter;
}
$query .= " ORDER BY a.date DESC, a.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$stmt = $db->prepare($query);
$stmt->execute($params);
$attendances = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for pagination
$count_query = "SELECT COUNT(*) FROM attendance a JOIN users u ON a.user_id = u.id WHERE u.name LIKE ?";
$count_params = [$search_user];
if ($class_filter > 0) {
    $count_query .= " AND a.class_id = ?";
    $count_params[] = $class_filter;
}
if ($date_filter) {
    $count_query .= " AND a.date = ?";
    $count_params[] = $date_filter;
}
if ($status_filter) {
    $count_query .= " AND a.status = ?";
    $count_params[] = $status_filter;
}
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($count_params);
$total_attendances = $count_stmt->fetchColumn() ?? 0;
$total_pages = ceil($total_attendances / $limit);
// Fetch classes for filter
$classes = $db->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
// Fetch audit logs (upgraded to filter by attendance-related actions)
$audit_logs = $db->query("SELECT ul.*, u.name AS actor_name FROM user_logs ul JOIN users u ON ul.user_id = u.id WHERE ul.action LIKE '%attendance%' ORDER BY ul.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
// Fetch progress data for tracking
$avg_attendance = $db->query("SELECT AVG(attendance) FROM student_reports")->fetchColumn() ?? 0;
$avg_score = $db->query("SELECT AVG(avg_scaled_score) FROM progress_aggregates")->fetchColumn() ?? 0;
$recent_progress = $db->query("SELECT sp.*, u.name AS student_name FROM student_progress sp JOIN users u ON sp.user_id = u.id ORDER BY sp.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
$low_attendance = $db->query("SELECT u.name, sr.attendance FROM student_reports sr JOIN users u ON sr.user_id = u.id WHERE sr.attendance < 80 ORDER BY sr.attendance ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Generate CSRF token
$csrf_token = generate_csrf_token();
?>
<?php include '../templates/admin_header.php'; ?>
    <section class="attendance-management">
        <h1 class="page-title">Attendance & Progress Tracking Dashboard</h1>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <!-- Quick Actions (Styled buttons) -->
        <div class="quick-actions">
            <button class="action-button" onclick="location.href='mark_attendance.php'"><i class="fas fa-check-circle"></i> Mark Attendance</button>
            <button class="action-button" onclick="location.href='attendance_reports.php'"><i class="fas fa-chart-bar"></i> View Reports</button>
            <button class="action-button" onclick="location.href='export_attendance.php'"><i class="fas fa-download"></i> Export Attendance</button>
        </div>
        <!-- KPIs for Progress Tracking -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <i class="fas fa-percentage icon"></i>
                <div class="value"><?php echo round($avg_attendance, 2); ?>%</div>
                <div class="label">Avg Attendance</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-star icon"></i>
                <div class="value"><?php echo round($avg_score, 2); ?></div>
                <div class="label">Avg Student Score</div>
            </div>
        </div>
        <!-- Search and Filters -->
        <form class="search-form" method="GET">
            <input type="text" name="search_user" placeholder="Search by student name..." value="<?php echo htmlspecialchars($_GET['search_user'] ?? ''); ?>">
            <select name="class">
                <option value="">All Classes</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date" value="<?php echo htmlspecialchars($date_filter); ?>">
            <select name="status">
                <option value="">All Statuses</option>
                <option value="present" <?php echo $status_filter === 'present' ? 'selected' : ''; ?>>Present</option>
                <option value="absent" <?php echo $status_filter === 'absent' ? 'selected' : ''; ?>>Absent</option>
                <option value="late" <?php echo $status_filter === 'late' ? 'selected' : ''; ?>>Late</option>
            </select>
            <button type="submit"><i class="fas fa-search"></i> Filter</button>
        </form>
        <!-- Attendance Table -->
        <form method="POST" id="attendance-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all"></th>
                        <th>ID</th>
                        <th>Student Name</th>
                        <th>Class Name</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attendances as $attendance): ?>
                        <tr>
                            <td data-label="Select"><input type="checkbox" name="attendance_ids[]" value="<?php echo $attendance['id']; ?>"></td>
                            <td data-label="ID"><?php echo htmlspecialchars($attendance['id']); ?></td>
                            <td data-label="Student Name"><?php echo htmlspecialchars($attendance['user_name']); ?></td>
                            <td data-label="Class Name"><?php echo htmlspecialchars($attendance['class_name']); ?></td>
                            <td data-label="Date"><?php echo htmlspecialchars($attendance['date']); ?></td>
                            <td data-label="Status"><span class="status-badge <?php echo htmlspecialchars($attendance['status']); ?>"><?php echo ucfirst(htmlspecialchars($attendance['status'])); ?></span></td>
                            <td data-label="Type"><?php echo ucfirst(htmlspecialchars($attendance['type'])); ?></td>
                            <td data-label="Created At"><?php echo htmlspecialchars($attendance['created_at']); ?></td>
                            <td data-label="Actions">
                                <a href="edit_attendance.php?id=<?php echo $attendance['id']; ?>" class="action-icon edit"><i class="fas fa-edit"></i></a>
                                <a href="view_attendance.php?id=<?php echo $attendance['id']; ?>" class="action-icon view"><i class="fas fa-eye"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <!-- Pagination -->
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search_user=<?php echo urlencode($_GET['search_user'] ?? ''); ?>&class=<?php echo $class_filter; ?>&date=<?php echo urlencode($date_filter); ?>&status=<?php echo urlencode($status_filter); ?>">&laquo; Previous</a>
                <?php endif; ?>
                <span>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search_user=<?php echo urlencode($_GET['search_user'] ?? ''); ?>&class=<?php echo $class_filter; ?>&date=<?php echo urlencode($date_filter); ?>&status=<?php echo urlencode($status_filter); ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>
            <!-- Bulk Actions -->
            <div class="bulk-actions">
                <select name="action">
                    <option value="">Bulk Actions</option>
                    <option value="bulk_mark">Mark Selected As</option>
                </select>
                <select name="status" id="bulk-status" style="display:none;">
                    <option value="present">Present</option>
                    <option value="absent">Absent</option>
                    <option value="late">Late</option>
                </select>
                <button type="submit">Apply</button>
            </div>
        </form>
        <!-- Progress Tracking Sections -->
        <div class="progress-tracking">
            <h2>Progress Tracking</h2>
            <div class="recent-progress-card">
                <h3>Recent Student Progress Updates</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student Name</th>
                            <th>Points Earned</th>
                            <th>Badge ID</th>
                            <th>Goal Description</th>
                            <th>Created At</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_progress as $progress): ?>
                            <tr>
                                <td data-label="Student Name"><?php echo htmlspecialchars($progress['student_name']); ?></td>
                                <td data-label="Points Earned"><?php echo htmlspecialchars($progress['points_earned']); ?></td>
                                <td data-label="Badge ID"><?php echo htmlspecialchars($progress['badge_id']); ?></td>
                                <td data-label="Goal Description"><?php echo htmlspecialchars($progress['goal_description']); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($progress['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="low-attendance-card">
                <h3>Students with Low Attendance</h3>
                <ul class="list">
                    <?php foreach ($low_attendance as $student): ?>
                        <li><?php echo htmlspecialchars($student['name']); ?> - <?php echo $student['attendance']; ?>%</li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
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
        // Select All Checkbox
        document.getElementById('select-all').addEventListener('change', function() {
            document.querySelectorAll('input[name="attendance_ids[]"]').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        // Show status select for bulk mark
        document.querySelector('select[name="action"]').addEventListener('change', function() {
            const statusSelect = document.getElementById('bulk-status');
            statusSelect.style.display = this.value === 'bulk_mark' ? 'inline-block' : 'none';
        });
        // Confirm Bulk Actions
        document.getElementById('attendance-form').addEventListener('submit', function(e) {
            const action = this.action.value;
            if (action && !confirm(`Are you sure you want to perform this action on the selected attendances?`)) {
                e.preventDefault();
            }
        });
    </script>
    <style>
        /* Attendance Management Styles - Adapted from Previous */
        .attendance-management {
            display: grid;
            gap: 24px;
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
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
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            color: var(--primary-white);
            display: inline-block;
            min-width: 60px;
            text-align: center;
        }
        .status-badge.present { background-color: #28a745; }
        .status-badge.absent { background-color: #dc3545; }
        .status-badge.late { background-color: #ffc107; }
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
        .audit-logs, .progress-tracking {
            margin-top: 32px;
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
        .recent-progress-card, .low-attendance-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            overflow-x: auto;
        }
        .list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .list li {
            padding: 8px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            font-size: 14px;
        }
        /* Enhanced Responsiveness */
        @media (max-width: 1200px) {
            .attendance-management {
                padding: 15px;
            }
        }
        @media (max-width: 992px) {
            .quick-actions {
                grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            }
        }
        @media (max-width: 768px) {
            .search-form {
                flex-direction: column;
            }
            .search-form input, .search-form select, .search-form button {
                width: 100%;
                min-width: auto;
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
            .bulk-actions {
                flex-direction: column;
            }
            .bulk-actions select, .bulk-actions button {
                width: 100%;
            }
            .kpi-grid {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            }
        }
        @media (max-width: 576px) {
            .page-title {
                font-size: 24px;
            }
            .action-icon {
                font-size: 16px;
            }
        }
    </style>
<?php include '../templates/admin_footer.php'; ?>