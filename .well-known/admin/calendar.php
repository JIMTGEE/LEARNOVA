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
// Handle POST actions: delete, bulk actions for calendars, schedules, events, exams with enhanced validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: calendar.php");
        exit();
    }
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'delete_calendar':
                $calendar_id = intval($_POST['calendar_id'] ?? 0);
                if ($calendar_id > 0) {
                    $stmt = $db->prepare("DELETE FROM terms WHERE id = ?");
                    $stmt->execute([$calendar_id]);
                    log_activity($_SESSION['user_id'], 'delete_calendar', "Deleted academic calendar ID: $calendar_id");
                    $_SESSION['success'] = 'Academic calendar entry deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid calendar ID.';
                }
                break;
            case 'bulk_delete_calendars':
                if (isset($_POST['calendar_ids']) && is_array($_POST['calendar_ids'])) {
                    $calendar_ids = array_filter(array_map('intval', $_POST['calendar_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($calendar_ids) {
                        $placeholders = implode(',', array_fill(0, count($calendar_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM terms WHERE id IN ($placeholders)");
                        $stmt->execute($calendar_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_calendars', "Deleted academic calendars: " . implode(',', $calendar_ids));
                        $_SESSION['success'] = 'Selected academic calendar entries deleted.';
                    }
                }
                break;
            case 'delete_schedule':
                $schedule_id = intval($_POST['schedule_id'] ?? 0);
                if ($schedule_id > 0) {
                    $stmt = $db->prepare("DELETE FROM class_schedules WHERE id = ?");
                    $stmt->execute([$schedule_id]);
                    log_activity($_SESSION['user_id'], 'delete_schedule', "Deleted class schedule ID: $schedule_id");
                    $_SESSION['success'] = 'Class schedule deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid schedule ID.';
                }
                break;
            case 'bulk_delete_schedules':
                if (isset($_POST['schedule_ids']) && is_array($_POST['schedule_ids'])) {
                    $schedule_ids = array_filter(array_map('intval', $_POST['schedule_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($schedule_ids) {
                        $placeholders = implode(',', array_fill(0, count($schedule_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM class_schedules WHERE id IN ($placeholders)");
                        $stmt->execute($schedule_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_schedules', "Deleted class schedules: " . implode(',', $schedule_ids));
                        $_SESSION['success'] = 'Selected class schedules deleted.';
                    }
                }
                break;
            case 'delete_event':
                $event_id = intval($_POST['event_id'] ?? 0);
                if ($event_id > 0) {
                    $stmt = $db->prepare("DELETE FROM school_events WHERE id = ?");
                    $stmt->execute([$event_id]);
                    log_activity($_SESSION['user_id'], 'delete_event', "Deleted school event ID: $event_id");
                    $_SESSION['success'] = 'School event deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid event ID.';
                }
                break;
            case 'bulk_delete_events':
                if (isset($_POST['event_ids']) && is_array($_POST['event_ids'])) {
                    $event_ids = array_filter(array_map('intval', $_POST['event_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($event_ids) {
                        $placeholders = implode(',', array_fill(0, count($event_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM school_events WHERE id IN ($placeholders)");
                        $stmt->execute($event_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_events', "Deleted school events: " . implode(',', $event_ids));
                        $_SESSION['success'] = 'Selected school events deleted.';
                    }
                }
                break;
            case 'delete_exam':
                $exam_id = intval($_POST['exam_id'] ?? 0);
                if ($exam_id > 0) {
                    $stmt = $db->prepare("DELETE FROM exam_schedules WHERE id = ?");
                    $stmt->execute([$exam_id]);
                    log_activity($_SESSION['user_id'], 'delete_exam', "Deleted exam schedule ID: $exam_id");
                    $_SESSION['success'] = 'Exam schedule deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid exam ID.';
                }
                break;
            case 'bulk_delete_exams':
                if (isset($_POST['exam_ids']) && is_array($_POST['exam_ids'])) {
                    $exam_ids = array_filter(array_map('intval', $_POST['exam_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($exam_ids) {
                        $placeholders = implode(',', array_fill(0, count($exam_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM exam_schedules WHERE id IN ($placeholders)");
                        $stmt->execute($exam_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_exams', "Deleted exam schedules: " . implode(',', $exam_ids));
                        $_SESSION['success'] = 'Selected exam schedules deleted.';
                    }
                }
                break;
            // Enhanced: Add bulk export actions (simulate with session flag for now; actual export in separate script)
            case 'bulk_export_calendars':
                $_SESSION['export_type'] = 'calendars';
                $_SESSION['export_ids'] = $_POST['calendar_ids'] ?? [];
                header("Location: export_calendar.php");
                exit();
                break;
            case 'bulk_export_schedules':
                $_SESSION['export_type'] = 'schedules';
                $_SESSION['export_ids'] = $_POST['schedule_ids'] ?? [];
                header("Location: export_calendar.php");
                exit();
                break;
            case 'bulk_export_events':
                $_SESSION['export_type'] = 'events';
                $_SESSION['export_ids'] = $_POST['event_ids'] ?? [];
                header("Location: export_calendar.php");
                exit();
                break;
            case 'bulk_export_exams':
                $_SESSION['export_type'] = 'exams';
                $_SESSION['export_ids'] = $_POST['exam_ids'] ?? [];
                header("Location: export_calendar.php");
                exit();
                break;
        }
        header("Location: calendar.php");
        exit();
    }
}
// Define pagination limit
$limit = 20;
// Fetch academic calendars with search, filters, sorting, and pagination
$calendar_search = isset($_GET['calendar_search']) ? '%' . trim($_GET['calendar_search']) . '%' : '%';
$calendar_year_filter = isset($_GET['calendar_year']) ? $_GET['calendar_year'] : '';
$calendar_sort = isset($_GET['calendar_sort']) ? $_GET['calendar_sort'] : 'start_date';
$calendar_order = isset($_GET['calendar_order']) ? strtoupper($_GET['calendar_order']) : 'DESC';
$calendar_page = max(1, intval($_GET['calendar_page'] ?? 1));
$calendar_offset = ($calendar_page - 1) * $limit;
$calendar_query = "SELECT t.id, ay.year, t.name AS term_name, t.start_date, t.end_date, t.created_at 
                   FROM terms t 
                   JOIN academic_years ay ON t.academic_year_id = ay.id 
                   WHERE (ay.year LIKE ? OR t.name LIKE ?)";
$calendar_params = [$calendar_search, $calendar_search];
if ($calendar_year_filter) {
    $calendar_query .= " AND ay.year = ?";
    $calendar_params[] = $calendar_year_filter;
}
$calendar_query .= " ORDER BY $calendar_sort $calendar_order LIMIT ? OFFSET ?";
$calendar_params[] = $limit;
$calendar_params[] = $calendar_offset;
$stmt = $db->prepare($calendar_query);
$stmt->execute($calendar_params);
$calendars = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for calendars pagination
$calendar_count_query = "SELECT COUNT(*) 
                         FROM terms t 
                         JOIN academic_years ay ON t.academic_year_id = ay.id 
                         WHERE (ay.year LIKE ? OR t.name LIKE ?)";
$calendar_count_params = [$calendar_search, $calendar_search];
if ($calendar_year_filter) {
    $calendar_count_query .= " AND ay.year = ?";
    $calendar_count_params[] = $calendar_year_filter;
}
$calendar_count_stmt = $db->prepare($calendar_count_query);
$calendar_count_stmt->execute($calendar_count_params);
$total_calendars = $calendar_count_stmt->fetchColumn() ?? 0;
$total_calendar_pages = ceil($total_calendars / $limit);
// Fetch class schedules with search, filters, sorting, pagination
$schedule_search = isset($_GET['schedule_search']) ? '%' . trim($_GET['schedule_search']) . '%' : '%';
$schedule_class_filter = isset($_GET['schedule_class']) ? intval($_GET['schedule_class']) : 0;
$schedule_sort = isset($_GET['schedule_sort']) ? $_GET['schedule_sort'] : 'day_of_week';
$schedule_order = isset($_GET['schedule_order']) ? strtoupper($_GET['schedule_order']) : 'ASC';
$schedule_page = max(1, intval($_GET['schedule_page'] ?? 1));
$schedule_offset = ($schedule_page - 1) * $limit;
$schedule_query = "SELECT cs.*, c.class_name, s.subject_name, u.name AS teacher_name FROM class_schedules cs JOIN classes c ON cs.class_id = c.id JOIN subjects s ON cs.subject_id = s.id JOIN users u ON cs.teacher_id = u.id WHERE (c.class_name LIKE ? OR s.subject_name LIKE ?)";
$schedule_params = [$schedule_search, $schedule_search];
if ($schedule_class_filter > 0) {
    $schedule_query .= " AND cs.class_id = ?";
    $schedule_params[] = $schedule_class_filter;
}
$schedule_query .= " ORDER BY $schedule_sort $schedule_order LIMIT ? OFFSET ?";
$schedule_params[] = $limit;
$schedule_params[] = $schedule_offset;
$stmt = $db->prepare($schedule_query);
$stmt->execute($schedule_params);
$schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for schedules
$schedule_count_query = "SELECT COUNT(*) FROM class_schedules cs JOIN classes c ON cs.class_id = c.id JOIN subjects s ON cs.subject_id = s.id WHERE (c.class_name LIKE ? OR s.subject_name LIKE ?)";
$schedule_count_params = [$schedule_search, $schedule_search];
if ($schedule_class_filter) {
    $schedule_count_query .= " AND cs.class_id = ?";
    $schedule_count_params[] = $schedule_class_filter;
}
$schedule_count_stmt = $db->prepare($schedule_count_query);
$schedule_count_stmt->execute($schedule_count_params);
$total_schedules = $schedule_count_stmt->fetchColumn() ?? 0;
$total_schedule_pages = ceil($total_schedules / $limit);
// Fetch school events with search, filters, sorting, pagination
$event_search = isset($_GET['event_search']) ? '%' . trim($_GET['event_search']) . '%' : '%';
$event_type_filter = isset($_GET['event_type']) ? $_GET['event_type'] : '';
$event_sort = isset($_GET['event_sort']) ? $_GET['event_sort'] : 'event_date';
$event_order = isset($_GET['event_order']) ? strtoupper($_GET['event_order']) : 'DESC';
$event_page = max(1, intval($_GET['event_page'] ?? 1));
$event_offset = ($event_page - 1) * $limit;
$event_query = "SELECT * FROM school_events WHERE (title LIKE ? OR description LIKE ?)";
$event_params = [$event_search, $event_search];
if ($event_type_filter) {
    $event_query .= " AND event_type = ?";
    $event_params[] = $event_type_filter;
}
$event_query .= " ORDER BY $event_sort $event_order LIMIT ? OFFSET ?";
$event_params[] = $limit;
$event_params[] = $event_offset;
$stmt = $db->prepare($event_query);
$stmt->execute($event_params);
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for events
$event_count_query = "SELECT COUNT(*) FROM school_events WHERE (title LIKE ? OR description LIKE ?)";
$event_count_params = [$event_search, $event_search];
if ($event_type_filter) {
    $event_count_query .= " AND event_type = ?";
    $event_count_params[] = $event_type_filter;
}
$event_count_stmt = $db->prepare($event_count_query);
$event_count_stmt->execute($event_count_params);
$total_events = $event_count_stmt->fetchColumn() ?? 0;
$total_event_pages = ceil($total_events / $limit);
// Fetch exam schedules with search, filters, sorting, pagination
$exam_search = isset($_GET['exam_search']) ? '%' . trim($_GET['exam_search']) . '%' : '%';
$exam_class_filter = isset($_GET['exam_class']) ? intval($_GET['exam_class']) : 0;
$exam_sort = isset($_GET['exam_sort']) ? $_GET['exam_sort'] : 'exam_date';
$exam_order = isset($_GET['exam_order']) ? strtoupper($_GET['exam_order']) : 'DESC';
$exam_page = max(1, intval($_GET['exam_page'] ?? 1));
$exam_offset = ($exam_page - 1) * $limit;
$exam_query = "SELECT es.*, c.class_name, s.subject_name FROM exam_schedules es LEFT JOIN classes c ON es.class_id = c.id LEFT JOIN subjects s ON es.subject_id = s.id WHERE (c.class_name LIKE ? OR s.subject_name LIKE ?)";
$exam_params = [$exam_search, $exam_search];
if ($exam_class_filter > 0) {
    $exam_query .= " AND es.class_id = ?";
    $exam_params[] = $exam_class_filter;
}
$exam_query .= " ORDER BY $exam_sort $exam_order LIMIT ? OFFSET ?";
$exam_params[] = $limit;
$exam_params[] = $exam_offset;
$stmt = $db->prepare($exam_query);
$stmt->execute($exam_params);
$exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for exams
$exam_count_query = "SELECT COUNT(*) FROM exam_schedules es LEFT JOIN classes c ON es.class_id = c.id LEFT JOIN subjects s ON es.subject_id = s.id WHERE (c.class_name LIKE ? OR s.subject_name LIKE ?)";
$exam_count_params = [$exam_search, $exam_search];
if ($exam_class_filter) {
    $exam_count_query .= " AND es.class_id = ?";
    $exam_count_params[] = $exam_class_filter;
}
$exam_count_stmt = $db->prepare($exam_count_query);
$exam_count_stmt->execute($exam_count_params);
$total_exams = $exam_count_stmt->fetchColumn() ?? 0;
$total_exam_pages = ceil($total_exams / $limit);
// Fetch classes for filters
$classes = $db->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
// Fetch unique years for calendar filter
$years = $db->query("SELECT DISTINCT ay.year 
                     FROM academic_years ay 
                     JOIN terms t ON ay.id = t.academic_year_id 
                     ORDER BY ay.year DESC")->fetchAll(PDO::FETCH_COLUMN);
// Fetch unique event types
$event_types = $db->query("SELECT DISTINCT event_type FROM school_events")->fetchAll(PDO::FETCH_COLUMN);
// Fetch enhanced KPIs
$upcoming_events = $db->query("SELECT COUNT(*) FROM school_events WHERE event_date > CURDATE()")->fetchColumn() ?? 0;
$upcoming_exams = $db->query("SELECT COUNT(*) FROM exam_schedules WHERE exam_date > CURDATE()")->fetchColumn() ?? 0;
$current_term = $db->query("SELECT t.name AS term_name FROM terms t WHERE CURDATE() BETWEEN t.start_date AND t.end_date LIMIT 1")->fetchColumn() ?? 'No Current Term';
// Fetch audit logs (filtered by calendar-related actions, with pagination if needed)
$audit_logs = $db->query("SELECT ul.*, u.name AS actor_name FROM user_logs ul JOIN users u ON ul.user_id = u.id WHERE ul.action LIKE '%calendar%' OR ul.action LIKE '%schedule%' OR ul.action LIKE '%event%' OR ul.action LIKE '%exam%' ORDER BY ul.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
// Generate CSRF token
$csrf_token = generate_csrf_token();
?>
<?php include '../templates/admin_header.php'; ?>
    <section class="calendar-management">
        <h1 class="page-title">Calendar & Scheduling Dashboard</h1>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success" role="alert"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error" role="alert"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <!-- Enhanced KPIs -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <i class="fas fa-calendar-check icon"></i>
                <div class="value"><?php echo $upcoming_events; ?></div>
                <div class="label">Upcoming Events</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-pencil-alt icon"></i>
                <div class="value"><?php echo $upcoming_exams; ?></div>
                <div class="label">Upcoming Exams</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-calendar icon"></i>
                <div class="value"><?php echo htmlspecialchars($current_term); ?></div>
                <div class="label">Current Term</div>
            </div>
        </div>
        <!-- Quick Actions (Grid layout with enhanced buttons) -->
        <div class="quick-actions">
            <a href="add_calendar.php" class="action-button"><i class="fas fa-calendar-plus"></i> Add Calendar Entry</a>
            <a href="manage_schedules.php" class="action-button"><i class="fas fa-clock"></i> Manage Schedule</a>
            <a href="add_event.php" class="action-button"><i class="fas fa-calendar-day"></i> Add School Event</a>
            <a href="add_exam.php" class="action-button"><i class="fas fa-pencil-alt"></i> Add Exam Schedule</a>
            <a href="export_calendar.php" class="action-button"><i class="fas fa-download"></i> Export Calendar</a>
        </div>
        <!-- Academic Calendar Section -->
        <div class="section">
            <h2>Academic Calendar</h2>
            <!-- Enhanced Search and Filters for Calendar -->
            <form class="search-form" method="GET">
                <input type="text" name="calendar_search" placeholder="Search by year or term..." value="<?php echo htmlspecialchars($_GET['calendar_search'] ?? ''); ?>" aria-label="Search Calendar">
                <select name="calendar_year" aria-label="Filter by Year">
                    <option value="">All Years</option>
                    <?php foreach ($years as $year): ?>
                        <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $calendar_year_filter === $year ? 'selected' : ''; ?>><?php echo htmlspecialchars($year); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="calendar_sort" aria-label="Sort By">
                    <option value="start_date" <?php echo $calendar_sort === 'start_date' ? 'selected' : ''; ?>>Start Date</option>
                    <option value="year" <?php echo $calendar_sort === 'year' ? 'selected' : ''; ?>>Year</option>
                    <option value="term_name" <?php echo $calendar_sort === 'term_name' ? 'selected' : ''; ?>>Term Name</option>
                </select>
                <select name="calendar_order" aria-label="Order">
                    <option value="DESC" <?php echo $calendar_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $calendar_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Academic Calendar Table -->
            <form method="POST" id="calendars-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-calendars" aria-label="Select All Calendars"></th>
                            <th>ID</th>
                            <th>Year</th>
                            <th>Term Name</th>
                            <th>Start Date</th>
                            <th>End Date</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($calendars as $calendar): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="calendar_ids[]" value="<?php echo $calendar['id']; ?>" aria-label="Select Calendar <?php echo $calendar['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($calendar['id']); ?></td>
                                <td data-label="Year"><?php echo htmlspecialchars($calendar['year']); ?></td>
                                <td data-label="Term Name"><?php echo htmlspecialchars($calendar['term_name']); ?></td>
                                <td data-label="Start Date"><?php echo htmlspecialchars($calendar['start_date']); ?></td>
                                <td data-label="End Date"><?php echo htmlspecialchars($calendar['end_date']); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($calendar['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_calendar.php?id=<?php echo $calendar['id']; ?>" class="action-icon edit" aria-label="Edit Calendar"><i class="fas fa-edit"></i></a>
                                    <a href="view_calendar.php?id=<?php echo $calendar['id']; ?>" class="action-icon view" aria-label="View Calendar"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this calendar entry?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_calendar">
                                        <input type="hidden" name="calendar_id" value="<?php echo $calendar['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Calendar"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Calendar -->
                <div class="pagination">
                    <?php if ($calendar_page > 1): ?>
                        <a href="?calendar_page=<?php echo $calendar_page - 1; ?>&calendar_search=<?php echo urlencode($_GET['calendar_search'] ?? ''); ?>&calendar_year=<?php echo urlencode($calendar_year_filter); ?>&calendar_sort=<?php echo $calendar_sort; ?>&calendar_order=<?php echo $calendar_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $calendar_page; ?> of <?php echo $total_calendar_pages; ?></span>
                    <?php if ($calendar_page < $total_calendar_pages): ?>
                        <a href="?calendar_page=<?php echo $calendar_page + 1; ?>&calendar_search=<?php echo urlencode($_GET['calendar_search'] ?? ''); ?>&calendar_year=<?php echo urlencode($calendar_year_filter); ?>&calendar_sort=<?php echo $calendar_sort; ?>&calendar_order=<?php echo $calendar_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Enhanced Bulk Actions for Calendar -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Calendars">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_calendars">Delete Selected</option>
                        <option value="bulk_export_calendars">Export Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Class Schedules Section -->
        <div class="section">
            <h2>Class Schedules</h2>
            <!-- Enhanced Search and Filters for Schedules -->
            <form class="search-form" method="GET">
                <input type="text" name="schedule_search" placeholder="Search by class or subject..." value="<?php echo htmlspecialchars($_GET['schedule_search'] ?? ''); ?>" aria-label="Search Schedules">
                <select name="schedule_class" aria-label="Filter by Class">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo $schedule_class_filter == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="schedule_sort" aria-label="Sort By">
                    <option value="day_of_week" <?php echo $schedule_sort === 'day_of_week' ? 'selected' : ''; ?>>Day of Week</option>
                    <option value="start_time" <?php echo $schedule_sort === 'start_time' ? 'selected' : ''; ?>>Start Time</option>
                    <option value="class_name" <?php echo $schedule_sort === 'class_name' ? 'selected' : ''; ?>>Class Name</option>
                </select>
                <select name="schedule_order" aria-label="Order">
                    <option value="ASC" <?php echo $schedule_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                    <option value="DESC" <?php echo $schedule_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Class Schedules Table -->
            <form method="POST" id="schedules-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-schedules" aria-label="Select All Schedules"></th>
                            <th>ID</th>
                            <th>Class Name</th>
                            <th>Subject Name</th>
                            <th>Teacher Name</th>
                            <th>Day of Week</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedules as $schedule): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="schedule_ids[]" value="<?php echo $schedule['id']; ?>" aria-label="Select Schedule <?php echo $schedule['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($schedule['id']); ?></td>
                                <td data-label="Class Name"><?php echo htmlspecialchars($schedule['class_name']); ?></td>
                                <td data-label="Subject Name"><?php echo htmlspecialchars($schedule['subject_name']); ?></td>
                                <td data-label="Teacher Name"><?php echo htmlspecialchars($schedule['teacher_name']); ?></td>
                                <td data-label="Day of Week"><?php echo htmlspecialchars($schedule['day_of_week']); ?></td>
                                <td data-label="Start Time"><?php echo htmlspecialchars($schedule['start_time']); ?></td>
                                <td data-label="End Time"><?php echo htmlspecialchars($schedule['end_time']); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($schedule['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_schedule.php?id=<?php echo $schedule['id']; ?>" class="action-icon edit" aria-label="Edit Schedule"><i class="fas fa-edit"></i></a>
                                    <a href="view_schedule.php?id=<?php echo $schedule['id']; ?>" class="action-icon view" aria-label="View Schedule"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this schedule?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_schedule">
                                        <input type="hidden" name="schedule_id" value="<?php echo $schedule['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Schedule"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Schedules -->
                <div class="pagination">
                    <?php if ($schedule_page > 1): ?>
                        <a href="?schedule_page=<?php echo $schedule_page - 1; ?>&schedule_search=<?php echo urlencode($_GET['schedule_search'] ?? ''); ?>&schedule_class=<?php echo $schedule_class_filter; ?>&schedule_sort=<?php echo $schedule_sort; ?>&schedule_order=<?php echo $schedule_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $schedule_page; ?> of <?php echo $total_schedule_pages; ?></span>
                    <?php if ($schedule_page < $total_schedule_pages): ?>
                        <a href="?schedule_page=<?php echo $schedule_page + 1; ?>&schedule_search=<?php echo urlencode($_GET['schedule_search'] ?? ''); ?>&schedule_class=<?php echo $schedule_class_filter; ?>&schedule_sort=<?php echo $schedule_sort; ?>&schedule_order=<?php echo $schedule_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Enhanced Bulk Actions for Schedules -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Schedules">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_schedules">Delete Selected</option>
                        <option value="bulk_export_schedules">Export Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- School Events Section -->
        <div class="section">
            <h2>School Events</h2>
            <!-- Enhanced Search and Filters for Events -->
            <form class="search-form" method="GET">
                <input type="text" name="event_search" placeholder="Search by title or description..." value="<?php echo htmlspecialchars($_GET['event_search'] ?? ''); ?>" aria-label="Search Events">
                <select name="event_type" aria-label="Filter by Type">
                    <option value="">All Types</option>
                    <?php foreach ($event_types as $type): ?>
                        <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $event_type_filter === $type ? 'selected' : ''; ?>><?php echo ucfirst(htmlspecialchars($type)); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="event_sort" aria-label="Sort By">
                    <option value="event_date" <?php echo $event_sort === 'event_date' ? 'selected' : ''; ?>>Event Date</option>
                    <option value="title" <?php echo $event_sort === 'title' ? 'selected' : ''; ?>>Title</option>
                    <option value="event_type" <?php echo $event_sort === 'event_type' ? 'selected' : ''; ?>>Event Type</option>
                </select>
                <select name="event_order" aria-label="Order">
                    <option value="DESC" <?php echo $event_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $event_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- School Events Table -->
            <form method="POST" id="events-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-events" aria-label="Select All Events"></th>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Description</th>
                            <th>Event Type</th>
                            <th>Event Date</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Location</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="event_ids[]" value="<?php echo $event['id']; ?>" aria-label="Select Event <?php echo $event['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($event['id']); ?></td>
                                <td data-label="Title"><?php echo htmlspecialchars($event['title']); ?></td>
                                <td data-label="Description"><?php echo htmlspecialchars(substr($event['description'] ?? '', 0, 100)); ?>...</td>
                                <td data-label="Event Type"><?php echo ucfirst(htmlspecialchars($event['event_type'])); ?></td>
                                <td data-label="Event Date"><?php echo htmlspecialchars($event['event_date']); ?></td>
                                <td data-label="Start Time"><?php echo htmlspecialchars($event['start_time']); ?></td>
                                <td data-label="End Time"><?php echo htmlspecialchars($event['end_time']); ?></td>
                                <td data-label="Location"><?php echo htmlspecialchars($event['location']); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($event['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_event.php?id=<?php echo $event['id']; ?>" class="action-icon edit" aria-label="Edit Event"><i class="fas fa-edit"></i></a>
                                    <a href="view_event.php?id=<?php echo $event['id']; ?>" class="action-icon view" aria-label="View Event"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this event?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_event">
                                        <input type="hidden" name="event_id" value="<?php echo $event['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Event"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Events -->
                <div class="pagination">
                    <?php if ($event_page > 1): ?>
                        <a href="?event_page=<?php echo $event_page - 1; ?>&event_search=<?php echo urlencode($_GET['event_search'] ?? ''); ?>&event_type=<?php echo urlencode($event_type_filter); ?>&event_sort=<?php echo $event_sort; ?>&event_order=<?php echo $event_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $event_page; ?> of <?php echo $total_event_pages; ?></span>
                    <?php if ($event_page < $total_event_pages): ?>
                        <a href="?event_page=<?php echo $event_page + 1; ?>&event_search=<?php echo urlencode($_GET['event_search'] ?? ''); ?>&event_type=<?php echo urlencode($event_type_filter); ?>&event_sort=<?php echo $event_sort; ?>&event_order=<?php echo $event_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Enhanced Bulk Actions for Events -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Events">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_events">Delete Selected</option>
                        <option value="bulk_export_events">Export Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Exam Schedules Section -->
        <div class="section">
            <h2>Exam Schedules</h2>
            <!-- Enhanced Search and Filters for Exams -->
            <form class="search-form" method="GET">
                <input type="text" name="exam_search" placeholder="Search by class or subject..." value="<?php echo htmlspecialchars($_GET['exam_search'] ?? ''); ?>" aria-label="Search Exams">
                <select name="exam_class" aria-label="Filter by Class">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo $exam_class_filter == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="exam_sort" aria-label="Sort By">
                    <option value="exam_date" <?php echo $exam_sort === 'exam_date' ? 'selected' : ''; ?>>Exam Date</option>
                    <option value="class_name" <?php echo $exam_sort === 'class_name' ? 'selected' : ''; ?>>Class Name</option>
                    <option value="subject_name" <?php echo $exam_sort === 'subject_name' ? 'selected' : ''; ?>>Subject Name</option>
                </select>
                <select name="exam_order" aria-label="Order">
                    <option value="DESC" <?php echo $exam_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $exam_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Exam Schedules Table -->
            <form method="POST" id="exams-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-exams" aria-label="Select All Exams"></th>
                            <th>ID</th>
                            <th>Class Name</th>
                            <th>Subject Name</th>
                            <th>Term ID</th>
                            <th>Exam Date</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Room</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($exams as $exam): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="exam_ids[]" value="<?php echo $exam['id']; ?>" aria-label="Select Exam <?php echo $exam['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($exam['id']); ?></td>
                                <td data-label="Class Name"><?php echo htmlspecialchars($exam['class_name'] ?? 'N/A'); ?></td>
                                <td data-label="Subject Name"><?php echo htmlspecialchars($exam['subject_name'] ?? 'N/A'); ?></td>
                                <td data-label="Term ID"><?php echo htmlspecialchars($exam['term_id']); ?></td>
                                <td data-label="Exam Date"><?php echo htmlspecialchars($exam['exam_date']); ?></td>
                                <td data-label="Start Time"><?php echo htmlspecialchars($exam['start_time']); ?></td>
                                <td data-label="End Time"><?php echo htmlspecialchars($exam['end_time']); ?></td>
                                <td data-label="Room"><?php echo htmlspecialchars($exam['room']); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($exam['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_exam.php?id=<?php echo $exam['id']; ?>" class="action-icon edit" aria-label="Edit Exam"><i class="fas fa-edit"></i></a>
                                    <a href="view_exam.php?id=<?php echo $exam['id']; ?>" class="action-icon view" aria-label="View Exam"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this exam schedule?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_exam">
                                        <input type="hidden" name="exam_id" value="<?php echo $exam['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Exam"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Exams -->
                <div class="pagination">
                    <?php if ($exam_page > 1): ?>
                        <a href="?exam_page=<?php echo $exam_page - 1; ?>&exam_search=<?php echo urlencode($_GET['exam_search'] ?? ''); ?>&exam_class=<?php echo $exam_class_filter; ?>&exam_sort=<?php echo $exam_sort; ?>&exam_order=<?php echo $exam_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $exam_page; ?> of <?php echo $total_exam_pages; ?></span>
                    <?php if ($exam_page < $total_exam_pages): ?>
                        <a href="?exam_page=<?php echo $exam_page + 1; ?>&exam_search=<?php echo urlencode($_GET['exam_search'] ?? ''); ?>&exam_class=<?php echo $exam_class_filter; ?>&exam_sort=<?php echo $exam_sort; ?>&exam_order=<?php echo $exam_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Enhanced Bulk Actions for Exams -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Exams">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_exams">Delete Selected</option>
                        <option value="bulk_export_exams">Export Selected</option>
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
        // Enhanced Select All Checkbox for Calendars
        document.getElementById('select-all-calendars').addEventListener('change', function() {
            document.querySelectorAll('input[name="calendar_ids[]"]').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        // Select All Checkbox for Schedules
        document.getElementById('select-all-schedules').addEventListener('change', function() {
            document.querySelectorAll('input[name="schedule_ids[]"]').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        // Select All Checkbox for Events
        document.getElementById('select-all-events').addEventListener('change', function() {
            document.querySelectorAll('input[name="event_ids[]"]').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        // Select All Checkbox for Exams
        document.getElementById('select-all-exams').addEventListener('change', function() {
            document.querySelectorAll('input[name="exam_ids[]"]').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        // Confirm Bulk Actions and Deletes with enhanced messages
        document.getElementById('calendars-form').addEventListener('submit', function(e) {
            const action = this.action.value;
            if (action && !confirm(`Are you sure you want to ${action.replace('bulk_', '').replace('_', ' ')} the selected calendar entries? This action cannot be undone.`)) {
                e.preventDefault();
            }
        });
        document.getElementById('schedules-form').addEventListener('submit', function(e) {
            const action = this.action.value;
            if (action && !confirm(`Are you sure you want to ${action.replace('bulk_', '').replace('_', ' ')} the selected schedules? This action cannot be undone.`)) {
                e.preventDefault();
            }
        });
        document.getElementById('events-form').addEventListener('submit', function(e) {
            const action = this.action.value;
            if (action && !confirm(`Are you sure you want to ${action.replace('bulk_', '').replace('_', ' ')} the selected events? This action cannot be undone.`)) {
                e.preventDefault();
            }
        });
        document.getElementById('exams-form').addEventListener('submit', function(e) {
            const action = this.action.value;
            if (action && !confirm(`Are you sure you want to ${action.replace('bulk_', '').replace('_', ' ')} the selected exams? This action cannot be undone.`)) {
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
        .calendar-management {
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
            .calendar-management {
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