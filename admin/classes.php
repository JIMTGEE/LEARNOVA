<?php
// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Check if the user is logged in and has an admin role - Using specified auth logic
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Redirecting to login: user_id not set or role not admin. Role: " . ($_SESSION['user_role'] ?? 'not set'));
    header("Location: ../login.php");
    exit();
}
// Include database connection
require_once __DIR__ . '/../config/db.php';
$db = db();
// Include helpers and functions
try {
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../functions/csrf.php';
    require_once __DIR__ . '/../functions/log_activity.php';
    require_once __DIR__ . '/../functions/gemini_api.php';
} catch (Exception $e) {
    error_log("Error loading helpers/functions: " . $e->getMessage());
    $_SESSION['error'] = 'System error: Unable to load required files. Please check logs.';
    header("Location: dashboard.php");
    exit();
}
// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: classes.php");
        exit();
    }
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'delete_class':
                $class_id = intval($_POST['class_id'] ?? 0);
                if ($class_id > 0) {
                    $stmt = $db->prepare("DELETE FROM classes WHERE id = ?");
                    $stmt->execute([$class_id]);
                    log_activity($_SESSION['user_id'], 'delete_class', "Deleted class ID: $class_id");
                    $_SESSION['success'] = 'Class deleted successfully.';
                }
                break;
            case 'bulk_delete_classes':
                if (isset($_POST['class_ids']) && is_array($_POST['class_ids'])) {
                    $class_ids = array_filter(array_map('intval', $_POST['class_ids']), fn($id) => $id > 0);
                    if ($class_ids) {
                        $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM classes WHERE id IN ($placeholders)");
                        $stmt->execute($class_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_classes', "Deleted classes: " . implode(',', $class_ids));
                        $_SESSION['success'] = 'Selected classes deleted.';
                    }
                }
                break;
            case 'bulk_activate_classes':
                if (isset($_POST['class_ids']) && is_array($_POST['class_ids'])) {
                    $class_ids = array_filter(array_map('intval', $_POST['class_ids']), fn($id) => $id > 0);
                    if ($class_ids) {
                        $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
                        $stmt = $db->prepare("UPDATE classes SET status = 'active' WHERE id IN ($placeholders)");
                        $stmt->execute($class_ids);
                        log_activity($_SESSION['user_id'], 'bulk_activate_classes', "Activated classes: " . implode(',', $class_ids));
                        $_SESSION['success'] = 'Selected classes activated.';
                    }
                }
                break;
            case 'bulk_deactivate_classes':
                if (isset($_POST['class_ids']) && is_array($_POST['class_ids'])) {
                    $class_ids = array_filter(array_map('intval', $_POST['class_ids']), fn($id) => $id > 0);
                    if ($class_ids) {
                        $placeholders = implode(',', array_fill(0, count($class_ids), '?'));
                        $stmt = $db->prepare("UPDATE classes SET status = 'inactive' WHERE id IN ($placeholders)");
                        $stmt->execute($class_ids);
                        log_activity($_SESSION['user_id'], 'bulk_deactivate_classes', "Deactivated classes: " . implode(',', $class_ids));
                        $_SESSION['success'] = 'Selected classes deactivated.';
                    }
                }
                break;
            case 'delete_subject':
                $subject_id = intval($_POST['subject_id'] ?? 0);
                if ($subject_id > 0) {
                    $stmt = $db->prepare("DELETE FROM subjects WHERE id = ?");
                    $stmt->execute([$subject_id]);
                    log_activity($_SESSION['user_id'], 'delete_subject', "Deleted subject ID: $subject_id");
                    $_SESSION['success'] = 'Subject deleted successfully.';
                }
                break;
            case 'bulk_delete_subjects':
                if (isset($_POST['subject_ids']) && is_array($_POST['subject_ids'])) {
                    $subject_ids = array_filter(array_map('intval', $_POST['subject_ids']), fn($id) => $id > 0);
                    if ($subject_ids) {
                        $placeholders = implode(',', array_fill(0, count($subject_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM subjects WHERE id IN ($placeholders)");
                        $stmt->execute($subject_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_subjects', "Deleted subjects: " . implode(',', $subject_ids));
                        $_SESSION['success'] = 'Selected subjects deleted.';
                    }
                }
                break;
            case 'bulk_activate_subjects':
                if (isset($_POST['subject_ids']) && is_array($_POST['subject_ids'])) {
                    $subject_ids = array_filter(array_map('intval', $_POST['subject_ids']), fn($id) => $id > 0);
                    if ($subject_ids) {
                        $placeholders = implode(',', array_fill(0, count($subject_ids), '?'));
                        $stmt = $db->prepare("UPDATE subjects SET status = 'Active' WHERE id IN ($placeholders)");
                        $stmt->execute($subject_ids);
                        log_activity($_SESSION['user_id'], 'bulk_activate_subjects', "Activated subjects: " . implode(',', $subject_ids));
                        $_SESSION['success'] = 'Selected subjects activated.';
                    }
                }
                break;
            case 'bulk_deactivate_subjects':
                if (isset($_POST['subject_ids']) && is_array($_POST['subject_ids'])) {
                    $subject_ids = array_filter(array_map('intval', $_POST['subject_ids']), fn($id) => $id > 0);
                    if ($subject_ids) {
                        $placeholders = implode(',', array_fill(0, count($subject_ids), '?'));
                        $stmt = $db->prepare("UPDATE subjects SET status = 'Inactive' WHERE id IN ($placeholders)");
                        $stmt->execute($subject_ids);
                        log_activity($_SESSION['user_id'], 'bulk_deactivate_subjects', "Deactivated subjects: " . implode(',', $subject_ids));
                        $_SESSION['success'] = 'Selected subjects deactivated.';
                    }
                }
                break;
        }
        header("Location: classes.php");
        exit();
    }
}
// KPIs
$total_classes = $db->query("SELECT COUNT(*) FROM classes")->fetchColumn();
$total_subjects = $db->query("SELECT COUNT(*) FROM subjects")->fetchColumn();
$active_subjects = $db->query("SELECT COUNT(*) FROM subjects WHERE status = 'Active'")->fetchColumn();
$classes_without_teacher = $db->query("SELECT COUNT(*) FROM classes WHERE teacher_id IS NULL")->fetchColumn();
$avg_students_per_class = $db->query("SELECT AVG(student_count) FROM (SELECT COUNT(u.id) AS student_count FROM classes c LEFT JOIN users u ON c.id = u.class_id WHERE u.role = 'student' GROUP BY c.id) AS counts")->fetchColumn() ?? 0;

// Chart data
$classes_per_dept = $db->query("SELECT d.name, COUNT(c.id) AS count FROM departments d LEFT JOIN classes c ON d.id = c.department_id GROUP BY d.id")->fetchAll(PDO::FETCH_ASSOC);
$dept_labels = array_column($classes_per_dept, 'name');
$dept_values = array_column($classes_per_dept, 'count');

$subjects_status = $db->query("SELECT status, COUNT(*) AS count FROM subjects GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
$status_labels = array_column($subjects_status, 'status');
$status_values = array_column($subjects_status, 'count');

// AI Insights
$insight_data = json_encode([
    'total_classes' => $total_classes,
    'total_subjects' => $total_subjects,
    'active_subjects' => $active_subjects,
    'classes_without_teacher' => $classes_without_teacher,
    'avg_students_per_class' => $avg_students_per_class,
]);
$ai_prompt = "As an AI school management assistant, analyze this class and subject data: $insight_data. Provide insights on distribution, flag issues (e.g., classes without teachers, low active subjects), suggest actions (e.g., assign teachers, activate subjects), and predict trends. Structure with bullet points: Insights, Issues, Recommendations, Predictions. Keep concise.";
$ai_insights = get_gemini_response($ai_prompt);

// Classes fetch
$search = isset($_GET['search']) ? '%' . trim($_GET['search']) . '%' : '%';
$department_filter = isset($_GET['department']) ? intval($_GET['department']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$page = intval($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

$class_query = "SELECT c.*, u.name AS teacher_name, s.subject_name AS subject_name, d.name AS department_name, 
                (SELECT COUNT(*) FROM users WHERE class_id = c.id AND role = 'student') AS student_count 
                FROM classes c 
                LEFT JOIN users u ON c.teacher_id = u.id 
                LEFT JOIN subjects s ON c.subject_id = s.id 
                LEFT JOIN departments d ON c.department_id = d.id 
                WHERE c.class_name LIKE ?";
$class_params = [$search];
if ($department_filter > 0) {
    $class_query .= " AND c.department_id = ?";
    $class_params[] = $department_filter;
}
if ($status_filter && in_array($status_filter, ['active', 'inactive'])) {
    $class_query .= " AND c.status = ?";
    $class_params[] = $status_filter;
}
$class_query .= " ORDER BY c.created_at DESC LIMIT ? OFFSET ?";
$class_params[] = $limit;
$class_params[] = $offset;

$stmt = $db->prepare($class_query);
$stmt->execute($class_params);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_classes_calc = $db->prepare("SELECT COUNT(*) FROM classes WHERE class_name LIKE ?" . ($department_filter > 0 ? " AND department_id = ?" : "") . ($status_filter ? " AND status = ?" : ""));
$calc_params = [$search];
if ($department_filter > 0) $calc_params[] = $department_filter;
if ($status_filter) $calc_params[] = $status_filter;
$total_classes_calc->execute($calc_params);
$total_classes = $total_classes_calc->fetchColumn() ?? 0;
$total_class_pages = ceil($total_classes / $limit);

// Subjects fetch (similar pattern)
$subject_search = isset($_GET['subject_search']) ? '%' . trim($_GET['subject_search']) . '%' : '%';
$subject_department_filter = isset($_GET['subject_department']) ? intval($_GET['subject_department']) : 0;
$subject_status_filter = isset($_GET['subject_status']) ? $_GET['subject_status'] : '';
$subject_page = intval($_GET['subject_page'] ?? 1);
$subject_offset = ($subject_page - 1) * $limit;

$subject_query = "SELECT s.*, u.name AS teacher_name, d.name AS department_name FROM subjects s LEFT JOIN users u ON s.teacher_id = u.id LEFT JOIN departments d ON s.department_id = d.id WHERE s.subject_name LIKE ? OR s.subject_code LIKE ?";
$subject_params = [$subject_search, $subject_search];
if ($subject_department_filter > 0) {
    $subject_query .= " AND s.department_id = ?";
    $subject_params[] = $subject_department_filter;
}
if ($subject_status_filter && in_array($subject_status_filter, ['Active', 'Inactive'])) {
    $subject_query .= " AND s.status = ?";
    $subject_params[] = $subject_status_filter;
}
$subject_query .= " ORDER BY s.created_at DESC LIMIT ? OFFSET ?";
$subject_params[] = $limit;
$subject_params[] = $subject_offset;

$stmt = $db->prepare($subject_query);
$stmt->execute($subject_params);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_subjects_calc = $db->prepare("SELECT COUNT(*) FROM subjects WHERE subject_name LIKE ? OR subject_code LIKE ?" . ($subject_department_filter > 0 ? " AND department_id = ?" : "") . ($subject_status_filter ? " AND status = ?" : ""));
$calc_sub_params = [$subject_search, $subject_search];
if ($subject_department_filter > 0) $calc_sub_params[] = $subject_department_filter;
if ($subject_status_filter) $calc_sub_params[] = $subject_status_filter;
$total_subjects_calc->execute($calc_sub_params);
$total_subjects = $total_subjects_calc->fetchColumn() ?? 0;
$total_subject_pages = ceil($total_subjects / $limit);

// Departments & logs
$departments = $db->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$audit_logs = $db->query("SELECT ul.*, u.name AS actor_name FROM user_logs ul JOIN users u ON ul.user_id = u.id WHERE ul.action LIKE '%class%' OR ul.action LIKE '%subject%' ORDER BY ul.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
$recent_activities = $db->query("SELECT ul.*, u.name AS actor_name FROM user_logs ul JOIN users u ON ul.user_id = u.id WHERE ul.action LIKE '%class%' OR ul.action LIKE '%subject%' ORDER BY ul.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$csrf_token = generate_csrf_token();
?>
<?php include '../templates/admin_header.php'; ?>
    <section class="classes-management">
        <h1 class="page-title">Classes & Subjects Management Dashboard</h1>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <!-- KPIs -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <i class="fas fa-school icon"></i>
                <div class="value"><?php echo $total_classes; ?></div>
                <div class="label">Total Classes</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-book icon"></i>
                <div class="value"><?php echo $total_subjects; ?></div>
                <div class="label">Total Subjects</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-check-circle icon"></i>
                <div class="value"><?php echo $active_subjects; ?></div>
                <div class="label">Active Subjects</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-exclamation-triangle icon"></i>
                <div class="value"><?php echo $classes_without_teacher; ?></div>
                <div class="label">Classes Without Teacher</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-users icon"></i>
                <div class="value"><?php echo round($avg_students_per_class, 1); ?></div>
                <div class="label">Avg Students/Class</div>
            </div>
        </div>

        <!-- Charts -->
        <div class="charts-grid">
            <div class="chart-card">
                <h3>Classes per Department</h3>
                <canvas id="classesPerDeptChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>Subjects Status Distribution</h3>
                <canvas id="subjectsStatusChart"></canvas>
            </div>
        </div>

        <!-- Quick Actions - Updated Buttons (larger, glowing hover, icons first, subtle shadow) -->
        <div class="quick-actions">
            <a href="add_class.php" class="action-button primary">
                <i class="fas fa-plus"></i>
                <span>Add New Class</span>
            </a>
            <a href="add_subject.php" class="action-button primary">
                <i class="fas fa-book-open"></i>
                <span>Add New Subject</span>
            </a>
            <a href="assign_subjects.php" class="action-button secondary">
                <i class="fas fa-book"></i>
                <span>Assign Subjects</span>
            </a>
            <a href="assign_teachers.php" class="action-button secondary">
                <i class="fas fa-chalkboard-teacher"></i>
                <span>Assign Teachers</span>
            </a>
            <a href="subject_management.php" class="action-button secondary">
                <i class="fas fa-calendar-alt"></i>
                <span>Manage Subjects</span>
            </a>
            <a href="departments.php" class="action-button secondary">
                <i class="fas fa-building"></i>
                <span>Departments</span>
            </a>
            <a href="export_classes.php" class="action-button accent">
                <i class="fas fa-file-export"></i>
                <span>Export Data</span>
            </a>
        </div>

        <!-- Classes Section -->
        <div class="section classes-section">
            <h2>Classes Overview</h2>
            <form class="search-form" method="GET">
                <input type="text" name="search" placeholder="Search by class name..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                <select name="department">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>" <?php echo $department_filter == $dept['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
                <button type="submit" class="filter-btn"><i class="fas fa-search"></i> Filter</button>
            </form>

            <form method="POST" id="classes-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table sortable">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-classes"></th>
                            <th>ID</th>
                            <th>Class Name</th>
                            <th>Teacher</th>
                            <th>Subject</th>
                            <th>Students</th>
                            <th>Description</th>
                            <th>Department</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classes as $class): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="class_ids[]" value="<?php echo $class['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($class['id']); ?></td>
                                <td data-label="Class Name"><?php echo htmlspecialchars($class['class_name']); ?></td>
                                <td data-label="Teacher"><?php echo htmlspecialchars($class['teacher_name'] ?? 'N/A'); ?></td>
                                <td data-label="Subject"><?php echo htmlspecialchars($class['subject_name'] ?? 'N/A'); ?></td>
                                <td data-label="Students"><?php echo htmlspecialchars($class['student_count']); ?></td>
                                <td data-label="Description"><?php echo htmlspecialchars(substr($class['description'] ?? '', 0, 100)); ?>...</td>
                                <td data-label="Department"><?php echo htmlspecialchars($class['department_name'] ?? 'N/A'); ?></td>
                                <td data-label="Status"><span class="status-badge <?php echo htmlspecialchars(strtolower($class['status'] ?? 'active')); ?>"><?php echo htmlspecialchars($class['status'] ?? 'Active'); ?></span></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($class['created_at']); ?></td>
                                <td data-label="Actions" class="actions-cell">
                                    <a href="edit_class.php?id=<?php echo $class['id']; ?>" class="btn-icon edit" aria-label="Edit"><i class="fas fa-edit"></i></a>
                                    <a href="view_class.php?id=<?php echo $class['id']; ?>" class="btn-icon view" aria-label="View"><i class="fas fa-eye"></i></a>
                                    <button type="submit" name="action" value="delete_class" name="class_id" value="<?php echo $class['id']; ?>" class="btn-icon delete" aria-label="Delete" onclick="return confirm('Delete this class?')"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&department=<?php echo $department_filter; ?>&status=<?php echo urlencode($status_filter); ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $page; ?> of <?php echo $total_class_pages; ?></span>
                    <?php if ($page < $total_class_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&department=<?php echo $department_filter; ?>&status=<?php echo urlencode($status_filter); ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>

                <div class="bulk-actions">
                    <select name="action">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_classes">Delete Selected</option>
                        <option value="bulk_activate_classes">Activate Selected</option>
                        <option value="bulk_deactivate_classes">Deactivate Selected</option>
                    </select>
                    <button type="submit" class="apply-btn">Apply</button>
                </div>
            </form>
        </div>

        <!-- Subjects Section (similar button updates) -->
        <div class="section subjects-section">
            <h2>Subjects Overview</h2>
            <form class="search-form" method="GET">
                <input type="text" name="subject_search" placeholder="Search by subject name or code..." value="<?php echo htmlspecialchars($_GET['subject_search'] ?? ''); ?>">
                <select name="subject_department">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>" <?php echo $subject_department_filter == $dept['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="subject_status">
                    <option value="">All Statuses</option>
                    <option value="Active" <?php echo $subject_status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
                    <option value="Inactive" <?php echo $subject_status_filter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
                <button type="submit" class="filter-btn"><i class="fas fa-search"></i> Filter</button>
            </form>

            <form method="POST" id="subjects-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table sortable">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-subjects"></th>
                            <th>ID</th>
                            <th>Subject Name</th>
                            <th>Subject Code</th>
                            <th>Teacher</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Department</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $subject): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="subject_ids[]" value="<?php echo $subject['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($subject['id']); ?></td>
                                <td data-label="Subject Name"><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                <td data-label="Subject Code"><?php echo htmlspecialchars($subject['subject_code']); ?></td>
                                <td data-label="Teacher"><?php echo htmlspecialchars($subject['teacher_name'] ?? 'N/A'); ?></td>
                                <td data-label="Description"><?php echo htmlspecialchars(substr($subject['description'] ?? '', 0, 100)); ?>...</td>
                                <td data-label="Status"><span class="status-badge <?php echo htmlspecialchars(strtolower($subject['status'])); ?>"><?php echo htmlspecialchars($subject['status']); ?></span></td>
                                <td data-label="Department"><?php echo htmlspecialchars($subject['department_name'] ?? 'N/A'); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($subject['created_at']); ?></td>
                                <td data-label="Actions" class="actions-cell">
                                    <a href="edit_subject.php?id=<?php echo $subject['id']; ?>" class="btn-icon edit" aria-label="Edit"><i class="fas fa-edit"></i></a>
                                    <a href="view_subject.php?id=<?php echo $subject['id']; ?>" class="btn-icon view" aria-label="View"><i class="fas fa-eye"></i></a>
                                    <button type="submit" name="action" value="delete_subject" name="subject_id" value="<?php echo $subject['id']; ?>" class="btn-icon delete" aria-label="Delete" onclick="return confirm('Delete this subject?')"><i class="fas fa-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="pagination">
                    <?php if ($subject_page > 1): ?>
                        <a href="?subject_page=<?php echo $subject_page - 1; ?>&subject_search=<?php echo urlencode($_GET['subject_search'] ?? ''); ?>&subject_department=<?php echo $subject_department_filter; ?>&subject_status=<?php echo urlencode($subject_status_filter); ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $subject_page; ?> of <?php echo $total_subject_pages; ?></span>
                    <?php if ($subject_page < $total_subject_pages): ?>
                        <a href="?subject_page=<?php echo $subject_page + 1; ?>&subject_search=<?php echo urlencode($_GET['subject_search'] ?? ''); ?>&subject_department=<?php echo $subject_department_filter; ?>&subject_status=<?php echo urlencode($subject_status_filter); ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>

                <div class="bulk-actions">
                    <select name="action">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_subjects">Delete Selected</option>
                        <option value="bulk_activate_subjects">Activate Selected</option>
                        <option value="bulk_deactivate_subjects">Deactivate Selected</option>
                    </select>
                    <button type="submit" class="apply-btn">Apply</button>
                </div>
            </form>
        </div>

        <!-- Activity & Logs -->
        <div class="activity-alerts-grid">
            <div class="activity-card">
                <h3>Recent Activities</h3>
                <ul class="activity-list">
                    <?php foreach ($recent_activities as $activity): ?>
                        <li>
                            <span class="user"><?php echo htmlspecialchars($activity['actor_name']); ?></span> 
                            <span class="action"><?php echo htmlspecialchars($activity['action']); ?></span>: 
                            <?php echo htmlspecialchars(substr($activity['description'], 0, 50)); ?>...
                            <span class="time"><?php echo htmlspecialchars($activity['created_at']); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="audit-logs-card">
                <h3>Audit Logs</h3>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>Description</th>
                            <th>IP</th>
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
        </div>

        <!-- AI Insights -->
        <div class="ai-insights-card">
            <h3>AI Management Insights <i class="fas fa-robot"></i></h3>
            <p><?php echo nl2br(htmlspecialchars($ai_insights)); ?></p>
            <button type="button" onclick="refreshInsights()" class="refresh-btn">Refresh Insights</button>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Charts (unchanged)
        new Chart(document.getElementById('classesPerDeptChart').getContext('2d'), {
            type: 'bar',
            data: { labels: <?php echo json_encode($dept_labels); ?>, datasets: [{ label: 'Classes', data: <?php echo json_encode($dept_values); ?>, backgroundColor: 'var(--primary-sea-blue)' }] },
            options: { responsive: true, scales: { y: { beginAtZero: true } } }
        });
        new Chart(document.getElementById('subjectsStatusChart').getContext('2d'), {
            type: 'pie',
            data: { labels: <?php echo json_encode($status_labels); ?>, datasets: [{ data: <?php echo json_encode($status_values); ?>, backgroundColor: ['var(--primary-sea-blue)', 'var(--accent-slate-gray)'] }] },
            options: { responsive: true }
        });

        // Checkbox select all
        document.getElementById('select-all-classes')?.addEventListener('change', function() {
            document.querySelectorAll('input[name="class_ids[]"]').forEach(cb => cb.checked = this.checked);
        });
        document.getElementById('select-all-subjects')?.addEventListener('change', function() {
            document.querySelectorAll('input[name="subject_ids[]"]').forEach(cb => cb.checked = this.checked);
        });

        // Bulk confirm
        ['classes-form', 'subjects-form'].forEach(id => {
            document.getElementById(id)?.addEventListener('submit', function(e) {
                const action = this.elements.action.value;
                if (action && !confirm(`Confirm ${action.replace('bulk_', '').replace('_', ' ')} on selected items?`)) e.preventDefault();
            });
        });

        function refreshInsights() {
            fetch('refresh_insights.php?type=classes')
                .then(r => r.text())
                .then(data => document.querySelector('.ai-insights-card p').innerHTML = data);
        }

        // Skeleton & micro-interactions
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.data-table').forEach(t => {
                t.classList.add('loading');
                setTimeout(() => t.classList.remove('loading'), 800);
            });
        });
        document.querySelectorAll('.action-button, .btn-icon, .apply-btn, .filter-btn, .refresh-btn').forEach(el => {
            el.addEventListener('mouseenter', () => el.style.transform = 'translateY(-2px)');
            el.addEventListener('mouseleave', () => el.style.transform = 'translateY(0)');
        });
    </script>

    <style>
        /* Updated Button Styles - Premium, glowing hover, larger touch targets */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin: 32px 0;
        }
        .action-button {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
            border-radius: 16px;
            text-decoration: none;
            font-size: 16px;
            font-weight: 600;
            color: var(--primary-white);
            background: var(--primary-sea-blue);
            box-shadow: 0 6px 12px rgba(23,162,184,0.3);
            transition: all 0.22s ease-out;
            min-height: 100px;
        }
        .action-button:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 24px rgba(53,208,255,0.4);
            background: var(--accent-soft-cyan);
        }
        .action-button i {
            font-size: 32px;
            margin-bottom: 12px;
        }
        .action-button.primary { background: var(--primary-sea-blue); }
        .action-button.secondary { background: var(--accent-deep-navy); }
        .action-button.accent { background: #5E6A79; }

        /* Icon buttons in tables */
        .actions-cell {
            display: flex;
            gap: 12px;
            justify-content: center;
        }
        .btn-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            transition: all 0.18s ease-out;
            cursor: pointer;
            border: none;
        }
        .btn-icon.edit { background: rgba(23,162,184,0.15); color: var(--primary-sea-blue); }
        .btn-icon.view { background: rgba(40,167,69,0.15); color: #28a745; }
        .btn-icon.delete { background: rgba(220,53,69,0.15); color: #dc3545; }
        .btn-icon:hover {
            transform: scale(1.15);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .btn-icon.edit:hover { background: var(--primary-sea-blue); color: white; }
        .btn-icon.view:hover { background: #28a745; color: white; }
        .btn-icon.delete:hover { background: #dc3545; color: white; }

        /* Filter & Apply buttons */
        .filter-btn, .apply-btn, .refresh-btn {
            background: var(--primary-sea-blue);
            color: white;
            border: none;
            padding: 12px 28px;
            border-radius: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.22s ease-out;
            box-shadow: 0 4px 8px rgba(23,162,184,0.2);
        }
        .filter-btn:hover, .apply-btn:hover, .refresh-btn:hover {
            background: var(--accent-soft-cyan);
            transform: translateY(-2px);
            box-shadow: 0 8px 16px rgba(53,208,255,0.3);
        }

        /* Rest of styles remain premium as before */
        .classes-management { display: grid; gap: 24px; padding: 20px; max-width: 1600px; margin: 0 auto; }
        .page-title { font-size: 32px; font-weight: 700; color: var(--primary-black); text-align: center; }
        .kpi-grid, .charts-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; }
        .kpi-card, .chart-card { background: var(--primary-white); border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); padding: 20px; text-align: center; transition: all 0.2s; }
        .kpi-card:hover, .chart-card:hover { transform: translateY(-4px); box-shadow: 0 12px 20px rgba(0,0,0,0.15); }
        .section { background: var(--primary-white); border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); padding: 24px; }
        .search-form { display: flex; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; align-items: center; }
        .search-form input, .search-form select { padding: 14px; border-radius: 8px; border: 1px solid var(--accent-slate-gray); flex: 1; min-width: 200px; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 14.5px; }
        .data-table th { background: var(--accent-deep-navy); color: white; padding: 16px; }
        .data-table td { padding: 14px; border-bottom: 1px solid rgba(0,0,0,0.08); }
        .status-badge { padding: 6px 12px; border-radius: 20px; font-weight: 600; }
        .pagination { display: flex; justify-content: center; gap: 20px; margin: 24px 0; }
        .pagination a { padding: 10px 20px; background: var(--primary-sea-blue); color: white; border-radius: 8px; text-decoration: none; }
        .bulk-actions { display: flex; gap: 16px; align-items: center; margin-top: 20px; }
        .ai-insights-card { background: var(--primary-white); border-radius: 16px; padding: 28px; box-shadow: 0 6px 16px rgba(0,0,0,0.1); }
        @media (max-width: 768px) {
            .quick-actions { grid-template-columns: 1fr; }
            .search-form { flex-direction: column; }
            .data-table { overflow-x: auto; display: block; }
        }
    </style>
<?php include '../templates/admin_footer.php'; ?>