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
// Handle POST actions: delete, bulk actions for reports and evaluations with enhanced validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: reports.php");
        exit();
    }
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'delete_report':
                $report_id = intval($_POST['report_id'] ?? 0);
                if ($report_id > 0) {
                    $stmt = $db->prepare("DELETE FROM student_reports WHERE id = ?");
                    $stmt->execute([$report_id]);
                    log_activity($_SESSION['user_id'], 'delete_report', "Deleted student report ID: $report_id");
                    $_SESSION['success'] = 'Student report deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid report ID.';
                }
                break;
            case 'bulk_delete_reports':
                if (isset($_POST['report_ids']) && is_array($_POST['report_ids'])) {
                    $report_ids = array_filter(array_map('intval', $_POST['report_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($report_ids) {
                        $placeholders = implode(',', array_fill(0, count($report_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM student_reports WHERE id IN ($placeholders)");
                        $stmt->execute($report_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_reports', "Deleted student reports: " . implode(',', $report_ids));
                        $_SESSION['success'] = 'Selected student reports deleted.';
                    }
                }
                break;
            case 'delete_evaluation':
                $evaluation_id = intval($_POST['evaluation_id'] ?? 0);
                if ($evaluation_id > 0) {
                    $stmt = $db->prepare("DELETE FROM teacher_evaluations WHERE id = ?");
                    $stmt->execute([$evaluation_id]);
                    log_activity($_SESSION['user_id'], 'delete_evaluation', "Deleted teacher evaluation ID: $evaluation_id");
                    $_SESSION['success'] = 'Teacher evaluation deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid evaluation ID.';
                }
                break;
            case 'bulk_delete_evaluations':
                if (isset($_POST['evaluation_ids']) && is_array($_POST['evaluation_ids'])) {
                    $evaluation_ids = array_filter(array_map('intval', $_POST['evaluation_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($evaluation_ids) {
                        $placeholders = implode(',', array_fill(0, count($evaluation_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM teacher_evaluations WHERE id IN ($placeholders)");
                        $stmt->execute($evaluation_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_evaluations', "Deleted teacher evaluations: " . implode(',', $evaluation_ids));
                        $_SESSION['success'] = 'Selected teacher evaluations deleted.';
                    }
                }
                break;
            // Enhanced: Add bulk export actions (simulate with session flag for now; actual export in separate script)
            case 'bulk_export_reports':
                $_SESSION['export_type'] = 'reports';
                $_SESSION['export_ids'] = $_POST['report_ids'] ?? [];
                header("Location: export_reports.php");
                exit();
                break;
            case 'bulk_export_evaluations':
                $_SESSION['export_type'] = 'evaluations';
                $_SESSION['export_ids'] = $_POST['evaluation_ids'] ?? [];
                header("Location: export_reports.php");
                exit();
                break;
        }
        header("Location: reports.php");
        exit();
    }
}
// Define pagination limit
$limit = 20;
// Fetch student reports with search, filters, sorting, and pagination
$report_search = isset($_GET['report_search']) ? '%' . trim($_GET['report_search']) . '%' : '%';
$report_class_filter = isset($_GET['report_class']) ? intval($_GET['report_class']) : 0;
$report_term_filter = isset($_GET['report_term']) ? intval($_GET['report_term']) : 0;
$report_sort = isset($_GET['report_sort']) ? $_GET['report_sort'] : 'created_at';
$report_order = isset($_GET['report_order']) ? strtoupper($_GET['report_order']) : 'DESC';
$report_page = max(1, intval($_GET['report_page'] ?? 1));
$report_offset = ($report_page - 1) * $limit;
$report_query = "SELECT sr.*, u.name AS student_name, c.class_name FROM student_reports sr JOIN users u ON sr.user_id = u.id JOIN classes c ON sr.class_id = c.id WHERE u.name LIKE ?";
$report_params = [$report_search];
if ($report_class_filter > 0) {
    $report_query .= " AND sr.class_id = ?";
    $report_params[] = $report_class_filter;
}
if ($report_term_filter > 0) {
    $report_query .= " AND sr.term_id = ?";
    $report_params[] = $report_term_filter;
}
$report_query .= " ORDER BY $report_sort $report_order LIMIT ? OFFSET ?";
$report_params[] = $limit;
$report_params[] = $report_offset;
$stmt = $db->prepare($report_query);
$stmt->execute($report_params);
$student_reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for student reports pagination
$report_count_query = "SELECT COUNT(*) FROM student_reports sr JOIN users u ON sr.user_id = u.id WHERE u.name LIKE ?";
$report_count_params = [$report_search];
if ($report_class_filter > 0) {
    $report_count_query .= " AND sr.class_id = ?";
    $report_count_params[] = $report_class_filter;
}
if ($report_term_filter > 0) {
    $report_count_query .= " AND sr.term_id = ?";
    $report_count_params[] = $report_term_filter;
}
$report_count_stmt = $db->prepare($report_count_query);
$report_count_stmt->execute($report_count_params);
$total_reports = $report_count_stmt->fetchColumn() ?? 0;
$total_report_pages = ceil($total_reports / $limit);
// Fetch teacher evaluations with search, filters, sorting, pagination
$eval_search = isset($_GET['eval_search']) ? '%' . trim($_GET['eval_search']) . '%' : '%';
$eval_class_filter = isset($_GET['eval_class']) ? intval($_GET['eval_class']) : 0;
$eval_term_filter = isset($_GET['eval_term']) ? intval($_GET['eval_term']) : 0;
$eval_sort = isset($_GET['eval_sort']) ? $_GET['eval_sort'] : 'created_at';
$eval_order = isset($_GET['eval_order']) ? strtoupper($_GET['eval_order']) : 'DESC';
$eval_page = max(1, intval($_GET['eval_page'] ?? 1));
$eval_offset = ($eval_page - 1) * $limit;
$eval_query = "SELECT te.*, u.name AS teacher_name, c.class_name, s.subject_name FROM teacher_evaluations te JOIN users u ON te.teacher_id = u.id JOIN classes c ON te.class_id = c.id LEFT JOIN subjects s ON te.subject_id = s.id WHERE u.name LIKE ?";
$eval_params = [$eval_search];
if ($eval_class_filter > 0) {
    $eval_query .= " AND te.class_id = ?";
    $eval_params[] = $eval_class_filter;
}
if ($eval_term_filter > 0) {
    $eval_query .= " AND te.term_id = ?";
    $eval_params[] = $eval_term_filter;
}
$eval_query .= " ORDER BY $eval_sort $eval_order LIMIT ? OFFSET ?";
$eval_params[] = $limit;
$eval_params[] = $eval_offset;
$stmt = $db->prepare($eval_query);
$stmt->execute($eval_params);
$teacher_evaluations = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for teacher evaluations
$eval_count_query = "SELECT COUNT(*) FROM teacher_evaluations te JOIN users u ON te.teacher_id = u.id WHERE u.name LIKE ?";
$eval_count_params = [$eval_search];
if ($eval_class_filter > 0) {
    $eval_count_query .= " AND te.class_id = ?";
    $eval_count_params[] = $eval_class_filter;
}
if ($eval_term_filter > 0) {
    $eval_count_query .= " AND te.term_id = ?";
    $eval_count_params[] = $eval_term_filter;
}
$eval_count_stmt = $db->prepare($eval_count_query);
$eval_count_stmt->execute($eval_count_params);
$total_evals = $eval_count_stmt->fetchColumn() ?? 0;
$total_eval_pages = ceil($total_evals / $limit);
// Fetch classes and terms for filters
$classes = $db->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
$terms = $db->query("SELECT DISTINCT term_id FROM student_reports ORDER BY term_id")->fetchAll(PDO::FETCH_COLUMN);
// Fetch enhanced KPIs
$avg_attendance = $db->query("SELECT AVG(attendance) FROM student_reports")->fetchColumn() ?? 0;
$avg_score = $db->query("SELECT AVG(avg_scaled_score) FROM progress_aggregates")->fetchColumn() ?? 0;
$avg_teacher_eval = $db->query("SELECT AVG((score_1 + score_2 + score_3 + score_4 + score_5 + score_6 + score_7 + score_8 + score_9 + score_10 + score_11 + score_12 + score_13 + score_14 + score_15 + score_16 + score_17)/17) FROM teacher_evaluations")->fetchColumn() ?? 0;
$total_reports_generated = $db->query("SELECT COUNT(*) FROM student_reports")->fetchColumn() ?? 0;
// Fetch audit logs (filtered by report and evaluation-related actions, with pagination if needed)
$audit_logs = $db->query("SELECT ul.*, u.name AS actor_name FROM user_logs ul JOIN users u ON ul.user_id = u.id WHERE ul.action LIKE '%report%' OR ul.action LIKE '%evaluation%' ORDER BY ul.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
// Generate CSRF token
$csrf_token = generate_csrf_token();
?>
<?php include '../templates/admin_header.php'; ?>
    <section class="reports-management">
        <h1 class="page-title">Reports & Evaluations Management Dashboard</h1>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success" role="alert"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error" role="alert"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <!-- Enhanced KPIs with progress bar for attendance -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <i class="fas fa-percentage icon"></i>
                <div class="value"><?php echo round($avg_attendance, 2); ?>%</div>
                <div class="label">Avg Attendance</div>
                <div class="progress-bar">
                    <div class="progress" style="width: <?php echo $avg_attendance; ?>%;"></div>
                </div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-star icon"></i>
                <div class="value"><?php echo round($avg_score, 2); ?></div>
                <div class="label">Avg Student Score</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-chart-line icon"></i>
                <div class="value"><?php echo round($avg_teacher_eval, 2); ?></div>
                <div class="label">Avg Teacher Eval</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-file-alt icon"></i>
                <div class="value"><?php echo $total_reports_generated; ?></div>
                <div class="label">Total Reports</div>
            </div>
        </div>
        <!-- Quick Actions (Grid layout with enhanced buttons) -->
        <div class="quick-actions">
            <a href="generate_report.php" class="action-button"><i class="fas fa-file-pdf"></i> Generate Student Report</a>
            <a href="add_evaluation.php" class="action-button"><i class="fas fa-clipboard-check"></i> Add Teacher Evaluation</a>
            <a href="view_analytics.php" class="action-button"><i class="fas fa-chart-bar"></i> View Analytics</a>
            <a href="export_reports.php" class="action-button"><i class="fas fa-download"></i> Export Reports</a>
        </div>
        <!-- Student Reports Section -->
        <div class="section">
            <h2>Student Terminal Reports</h2>
            <!-- Enhanced Search and Filters for Reports -->
            <form class="search-form" method="GET">
                <input type="text" name="report_search" placeholder="Search by student name..." value="<?php echo htmlspecialchars($_GET['report_search'] ?? ''); ?>" aria-label="Search Reports">
                <select name="report_class" aria-label="Filter by Class">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo $report_class_filter == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="report_term" aria-label="Filter by Term">
                    <option value="">All Terms</option>
                    <?php foreach ($terms as $term): ?>
                        <option value="<?php echo $term; ?>" <?php echo $report_term_filter == $term ? 'selected' : ''; ?>>Term <?php echo $term; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="report_sort" aria-label="Sort By">
                    <option value="created_at" <?php echo $report_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                    <option value="attendance" <?php echo $report_sort === 'attendance' ? 'selected' : ''; ?>>Attendance</option>
                    <option value="term_id" <?php echo $report_sort === 'term_id' ? 'selected' : ''; ?>>Term ID</option>
                </select>
                <select name="report_order" aria-label="Order">
                    <option value="DESC" <?php echo $report_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $report_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Student Reports Table -->
            <form method="POST" id="reports-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-reports" aria-label="Select All Reports"></th>
                            <th>ID</th>
                            <th>Student Name</th>
                            <th>Class Name</th>
                            <th>Term ID</th>
                            <th>Attendance</th>
                            <th>Conduct</th>
                            <th>Attitude</th>
                            <th>Interest</th>
                            <th>Teacher Remarks</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($student_reports as $report): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="report_ids[]" value="<?php echo $report['id']; ?>" aria-label="Select Report <?php echo $report['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($report['id']); ?></td>
                                <td data-label="Student Name"><?php echo htmlspecialchars($report['student_name']); ?></td>
                                <td data-label="Class Name"><?php echo htmlspecialchars($report['class_name']); ?></td>
                                <td data-label="Term ID"><?php echo htmlspecialchars($report['term_id']); ?></td>
                                <td data-label="Attendance"><?php echo htmlspecialchars($report['attendance']); ?>%</td>
                                <td data-label="Conduct"><?php echo htmlspecialchars(substr($report['conduct'] ?? '', 0, 50)); ?>...</td>
                                <td data-label="Attitude"><?php echo htmlspecialchars(substr($report['attitude'] ?? '', 0, 50)); ?>...</td>
                                <td data-label="Interest"><?php echo htmlspecialchars(substr($report['interest'] ?? '', 0, 50)); ?>...</td>
                                <td data-label="Teacher Remarks"><?php echo htmlspecialchars(substr($report['teacher_remarks'] ?? '', 0, 50)); ?>...</td>
                                <td data-label="Created At"><?php echo htmlspecialchars($report['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_report.php?id=<?php echo $report['id']; ?>" class="action-icon edit" aria-label="Edit Report"><i class="fas fa-edit"></i></a>
                                    <a href="view_report.php?id=<?php echo $report['id']; ?>" class="action-icon view" aria-label="View Report"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this report?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_report">
                                        <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Report"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Enhanced Pagination for Reports -->
                <div class="pagination">
                    <?php if ($report_page > 1): ?>
                        <a href="?report_page=<?php echo $report_page - 1; ?>&report_search=<?php echo urlencode($_GET['report_search'] ?? ''); ?>&report_class=<?php echo $report_class_filter; ?>&report_term=<?php echo $report_term_filter; ?>&report_sort=<?php echo $report_sort; ?>&report_order=<?php echo $report_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $report_page; ?> of <?php echo $total_report_pages; ?></span>
                    <?php if ($report_page < $total_report_pages): ?>
                        <a href="?report_page=<?php echo $report_page + 1; ?>&report_search=<?php echo urlencode($_GET['report_search'] ?? ''); ?>&report_class=<?php echo $report_class_filter; ?>&report_term=<?php echo $report_term_filter; ?>&report_sort=<?php echo $report_sort; ?>&report_order=<?php echo $report_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Enhanced Bulk Actions for Reports -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Reports">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_reports">Delete Selected</option>
                        <option value="bulk_export_reports">Export Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Teacher Evaluations Section -->
        <div class="section">
            <h2>Teacher Evaluations</h2>
            <!-- Enhanced Search and Filters for Evaluations -->
            <form class="search-form" method="GET">
                <input type="text" name="eval_search" placeholder="Search by teacher name..." value="<?php echo htmlspecialchars($_GET['eval_search'] ?? ''); ?>" aria-label="Search Evaluations">
                <select name="eval_class" aria-label="Filter by Class">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo $eval_class_filter == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="eval_term" aria-label="Filter by Term">
                    <option value="">All Terms</option>
                    <?php foreach ($terms as $term): ?>
                        <option value="<?php echo $term; ?>" <?php echo $eval_term_filter == $term ? 'selected' : ''; ?>>Term <?php echo $term; ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="eval_sort" aria-label="Sort By">
                    <option value="created_at" <?php echo $eval_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                    <option value="term_id" <?php echo $eval_sort === 'term_id' ? 'selected' : ''; ?>>Term ID</option>
                </select>
                <select name="eval_order" aria-label="Order">
                    <option value="DESC" <?php echo $eval_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $eval_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Teacher Evaluations Table -->
            <form method="POST" id="evaluations-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-evals" aria-label="Select All Evaluations"></th>
                            <th>ID</th>
                            <th>Teacher Name</th>
                            <th>Class Name</th>
                            <th>Subject Name</th>
                            <th>Term ID</th>
                            <th>Avg Score</th>
                            <th>Positive Notes</th>
                            <th>Negative Notes</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teacher_evaluations as $eval): 
                            $avg_eval_score = ($eval['score_1'] + $eval['score_2'] + $eval['score_3'] + $eval['score_4'] + $eval['score_5'] + $eval['score_6'] + $eval['score_7'] + $eval['score_8'] + $eval['score_9'] + $eval['score_10'] + $eval['score_11'] + $eval['score_12'] + $eval['score_13'] + $eval['score_14'] + $eval['score_15'] + $eval['score_16'] + $eval['score_17']) / 17;
                        ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="evaluation_ids[]" value="<?php echo $eval['id']; ?>" aria-label="Select Evaluation <?php echo $eval['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($eval['id']); ?></td>
                                <td data-label="Teacher Name"><?php echo htmlspecialchars($eval['teacher_name']); ?></td>
                                <td data-label="Class Name"><?php echo htmlspecialchars($eval['class_name']); ?></td>
                                <td data-label="Subject Name"><?php echo htmlspecialchars($eval['subject_name'] ?? 'N/A'); ?></td>
                                <td data-label="Term ID"><?php echo htmlspecialchars($eval['term_id']); ?></td>
                                <td data-label="Avg Score"><?php echo round($avg_eval_score, 2); ?></td>
                                <td data-label="Positive Notes"><?php echo htmlspecialchars(substr($eval['positive_notes'] ?? '', 0, 50)); ?>...</td>
                                <td data-label="Negative Notes"><?php echo htmlspecialchars(substr($eval['negative_notes'] ?? '', 0, 50)); ?>...</td>
                                <td data-label="Created At"><?php echo htmlspecialchars($eval['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_evaluation.php?id=<?php echo $eval['id']; ?>" class="action-icon edit" aria-label="Edit Evaluation"><i class="fas fa-edit"></i></a>
                                    <a href="view_evaluation.php?id=<?php echo $eval['id']; ?>" class="action-icon view" aria-label="View Evaluation"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this evaluation?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_evaluation">
                                        <input type="hidden" name="evaluation_id" value="<?php echo $eval['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Evaluation"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Evaluations -->
                <div class="pagination">
                    <?php if ($eval_page > 1): ?>
                        <a href="?eval_page=<?php echo $eval_page - 1; ?>&eval_search=<?php echo urlencode($_GET['eval_search'] ?? ''); ?>&eval_class=<?php echo $eval_class_filter; ?>&eval_term=<?php echo $eval_term_filter; ?>&eval_sort=<?php echo $eval_sort; ?>&eval_order=<?php echo $eval_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $eval_page; ?> of <?php echo $total_eval_pages; ?></span>
                    <?php if ($eval_page < $total_eval_pages): ?>
                        <a href="?eval_page=<?php echo $eval_page + 1; ?>&eval_search=<?php echo urlencode($_GET['eval_search'] ?? ''); ?>&eval_class=<?php echo $eval_class_filter; ?>&eval_term=<?php echo $eval_term_filter; ?>&eval_sort=<?php echo $eval_sort; ?>&eval_order=<?php echo $eval_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Bulk Actions for Evaluations -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Evaluations">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_evaluations">Delete Selected</option>
                        <option value="bulk_export_evaluations">Export Selected</option>
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
        // Enhanced Select All Checkbox for Reports
        document.getElementById('select-all-reports').addEventListener('change', function() {
            document.querySelectorAll('input[name="report_ids[]"]').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        // Select All Checkbox for Evaluations
        document.getElementById('select-all-evals').addEventListener('change', function() {
            document.querySelectorAll('input[name="evaluation_ids[]"]').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        // Confirm Bulk Actions and Deletes with enhanced messages
        document.getElementById('reports-form').addEventListener('submit', function(e) {
            const action = this.action.value;
            if (action && !confirm(`Are you sure you want to ${action.replace('bulk_', '').replace('_', ' ')} the selected reports? This action cannot be undone.`)) {
                e.preventDefault();
            }
        });
        document.getElementById('evaluations-form').addEventListener('submit', function(e) {
            const action = this.action.value;
            if (action && !confirm(`Are you sure you want to ${action.replace('bulk_', '').replace('_', ' ')} the selected evaluations? This action cannot be undone.`)) {
                e.preventDefault();
            }
        });
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
        .reports-management {
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
            .reports-management {
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