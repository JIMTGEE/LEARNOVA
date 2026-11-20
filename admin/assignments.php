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
// Handle POST actions: delete, bulk actions for assignments, quizzes, lesson plans, etc. with enhanced validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: assignments.php");
        exit();
    }
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'delete_assignment':
                $assignment_id = intval($_POST['assignment_id'] ?? 0);
                if ($assignment_id > 0) {
                    $stmt = $db->prepare("DELETE FROM assignments WHERE id = ?");
                    $stmt->execute([$assignment_id]);
                    log_activity($_SESSION['user_id'], 'delete_assignment', "Deleted assignment ID: $assignment_id");
                    $_SESSION['success'] = 'Assignment deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid assignment ID.';
                }
                break;
            case 'bulk_delete_assignments':
                if (isset($_POST['assignment_ids']) && is_array($_POST['assignment_ids'])) {
                    $assignment_ids = array_filter(array_map('intval', $_POST['assignment_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($assignment_ids) {
                        $placeholders = implode(',', array_fill(0, count($assignment_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM assignments WHERE id IN ($placeholders)");
                        $stmt->execute($assignment_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_assignments', "Deleted assignments: " . implode(',', $assignment_ids));
                        $_SESSION['success'] = 'Selected assignments deleted.';
                    }
                }
                break;
            case 'delete_quiz':
                $quiz_id = intval($_POST['quiz_id'] ?? 0);
                if ($quiz_id > 0) {
                    $stmt = $db->prepare("DELETE FROM quizzes WHERE id = ?");
                    $stmt->execute([$quiz_id]);
                    log_activity($_SESSION['user_id'], 'delete_quiz', "Deleted quiz ID: $quiz_id");
                    $_SESSION['success'] = 'Quiz deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid quiz ID.';
                }
                break;
            case 'bulk_delete_quizzes':
                if (isset($_POST['quiz_ids']) && is_array($_POST['quiz_ids'])) {
                    $quiz_ids = array_filter(array_map('intval', $_POST['quiz_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($quiz_ids) {
                        $placeholders = implode(',', array_fill(0, count($quiz_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM quizzes WHERE id IN ($placeholders)");
                        $stmt->execute($quiz_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_quizzes', "Deleted quizzes: " . implode(',', $quiz_ids));
                        $_SESSION['success'] = 'Selected quizzes deleted.';
                    }
                }
                break;
            case 'delete_lesson_plan':
                $plan_id = intval($_POST['plan_id'] ?? 0);
                if ($plan_id > 0) {
                    $stmt = $db->prepare("DELETE FROM lesson_plans WHERE id = ?");
                    $stmt->execute([$plan_id]);
                    log_activity($_SESSION['user_id'], 'delete_lesson_plan', "Deleted lesson plan ID: $plan_id");
                    $_SESSION['success'] = 'Lesson plan deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid lesson plan ID.';
                }
                break;
            case 'bulk_delete_lesson_plans':
                if (isset($_POST['plan_ids']) && is_array($_POST['plan_ids'])) {
                    $plan_ids = array_filter(array_map('intval', $_POST['plan_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($plan_ids) {
                        $placeholders = implode(',', array_fill(0, count($plan_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM lesson_plans WHERE id IN ($placeholders)");
                        $stmt->execute($plan_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_lesson_plans', "Deleted lesson plans: " . implode(',', $plan_ids));
                        $_SESSION['success'] = 'Selected lesson plans deleted.';
                    }
                }
                break;
            // Add similar cases for teacher_notes, termly_plans, annual_plans, learning_resources, resource_feedback, forum_threads, forum_posts
            case 'delete_teacher_note':
                $note_id = intval($_POST['note_id'] ?? 0);
                if ($note_id > 0) {
                    $stmt = $db->prepare("DELETE FROM teacher_notes WHERE id = ?");
                    $stmt->execute([$note_id]);
                    log_activity($_SESSION['user_id'], 'delete_teacher_note', "Deleted teacher note ID: $note_id");
                    $_SESSION['success'] = 'Teacher note deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid teacher note ID.';
                }
                break;
            case 'bulk_delete_teacher_notes':
                if (isset($_POST['note_ids']) && is_array($_POST['note_ids'])) {
                    $note_ids = array_filter(array_map('intval', $_POST['note_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($note_ids) {
                        $placeholders = implode(',', array_fill(0, count($note_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM teacher_notes WHERE id IN ($placeholders)");
                        $stmt->execute($note_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_teacher_notes', "Deleted teacher notes: " . implode(',', $note_ids));
                        $_SESSION['success'] = 'Selected teacher notes deleted.';
                    }
                }
                break;
            case 'delete_termly_plan':
                $termly_id = intval($_POST['termly_id'] ?? 0);
                if ($termly_id > 0) {
                    $stmt = $db->prepare("DELETE FROM termly_plans WHERE id = ?");
                    $stmt->execute([$termly_id]);
                    log_activity($_SESSION['user_id'], 'delete_termly_plan', "Deleted termly plan ID: $termly_id");
                    $_SESSION['success'] = 'Termly plan deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid termly plan ID.';
                }
                break;
            case 'bulk_delete_termly_plans':
                if (isset($_POST['termly_ids']) && is_array($_POST['termly_ids'])) {
                    $termly_ids = array_filter(array_map('intval', $_POST['termly_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($termly_ids) {
                        $placeholders = implode(',', array_fill(0, count($termly_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM termly_plans WHERE id IN ($placeholders)");
                        $stmt->execute($termly_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_termly_plans', "Deleted termly plans: " . implode(',', $termly_ids));
                        $_SESSION['success'] = 'Selected termly plans deleted.';
                    }
                }
                break;
            case 'delete_annual_plan':
                $annual_id = intval($_POST['annual_id'] ?? 0);
                if ($annual_id > 0) {
                    $stmt = $db->prepare("DELETE FROM annual_plans WHERE id = ?");
                    $stmt->execute([$annual_id]);
                    log_activity($_SESSION['user_id'], 'delete_annual_plan', "Deleted annual plan ID: $annual_id");
                    $_SESSION['success'] = 'Annual plan deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid annual plan ID.';
                }
                break;
            case 'bulk_delete_annual_plans':
                if (isset($_POST['annual_ids']) && is_array($_POST['annual_ids'])) {
                    $annual_ids = array_filter(array_map('intval', $_POST['annual_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($annual_ids) {
                        $placeholders = implode(',', array_fill(0, count($annual_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM annual_plans WHERE id IN ($placeholders)");
                        $stmt->execute($annual_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_annual_plans', "Deleted annual plans: " . implode(',', $annual_ids));
                        $_SESSION['success'] = 'Selected annual plans deleted.';
                    }
                }
                break;
            case 'delete_resource':
                $resource_id = intval($_POST['resource_id'] ?? 0);
                if ($resource_id > 0) {
                    $stmt = $db->prepare("DELETE FROM learning_resources WHERE id = ?");
                    $stmt->execute([$resource_id]);
                    log_activity($_SESSION['user_id'], 'delete_resource', "Deleted learning resource ID: $resource_id");
                    $_SESSION['success'] = 'Learning resource deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid resource ID.';
                }
                break;
            case 'bulk_delete_resources':
                if (isset($_POST['resource_ids']) && is_array($_POST['resource_ids'])) {
                    $resource_ids = array_filter(array_map('intval', $_POST['resource_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($resource_ids) {
                        $placeholders = implode(',', array_fill(0, count($resource_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM learning_resources WHERE id IN ($placeholders)");
                        $stmt->execute($resource_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_resources', "Deleted learning resources: " . implode(',', $resource_ids));
                        $_SESSION['success'] = 'Selected learning resources deleted.';
                    }
                }
                break;
            // Add for resource_feedback, forum_threads, forum_posts if needed
            // Enhanced: Add bulk export actions (simulate with session flag for now; actual export in separate script)
            case 'bulk_export_assignments':
                $_SESSION['export_type'] = 'assignments';
                $_SESSION['export_ids'] = $_POST['assignment_ids'] ?? [];
                header("Location: export_lms_data.php");
                exit();
                break;
            case 'bulk_export_quizzes':
                $_SESSION['export_type'] = 'quizzes';
                $_SESSION['export_ids'] = $_POST['quiz_ids'] ?? [];
                header("Location: export_lms_data.php");
                exit();
                break;
            case 'bulk_export_lesson_plans':
                $_SESSION['export_type'] = 'lesson_plans';
                $_SESSION['export_ids'] = $_POST['plan_ids'] ?? [];
                header("Location: export_lms_data.php");
                exit();
                break;
            // Add similar for other sections if needed
        }
        header("Location: assignments.php");
        exit();
    }
}
// Define pagination limit
$limit = 20;
// Fetch assignments with search, filters, sorting, and pagination
$assignment_search = isset($_GET['assignment_search']) ? '%' . trim($_GET['assignment_search']) . '%' : '%';
$assignment_class_filter = isset($_GET['assignment_class']) ? intval($_GET['assignment_class']) : 0;
$assignment_status_filter = isset($_GET['assignment_status']) ? $_GET['assignment_status'] : '';
$assignment_sort = isset($_GET['assignment_sort']) ? $_GET['assignment_sort'] : 'created_at';
$assignment_order = isset($_GET['assignment_order']) ? strtoupper($_GET['assignment_order']) : 'DESC';
$assignment_page = max(1, intval($_GET['assignment_page'] ?? 1));
$assignment_offset = ($assignment_page - 1) * $limit;
$assignment_query = "SELECT a.*, c.class_name, s.subject_name, u.name AS teacher_name FROM assignments a JOIN classes c ON a.class_id = c.id JOIN subjects s ON a.subject_id = s.id JOIN users u ON a.teacher_id = u.id WHERE a.title LIKE ?";
$assignment_params = [$assignment_search];
if ($assignment_class_filter > 0) {
    $assignment_query .= " AND a.class_id = ?";
    $assignment_params[] = $assignment_class_filter;
}
if ($assignment_status_filter && in_array($assignment_status_filter, ['draft', 'published', 'closed'])) {
    $assignment_query .= " AND a.status = ?";
    $assignment_params[] = $assignment_status_filter;
}
$assignment_query .= " ORDER BY $assignment_sort $assignment_order LIMIT ? OFFSET ?";
$assignment_params[] = $limit;
$assignment_params[] = $assignment_offset;
$stmt = $db->prepare($assignment_query);
$stmt->execute($assignment_params);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for assignments pagination
$assignment_count_query = "SELECT COUNT(*) FROM assignments WHERE title LIKE ?";
$assignment_count_params = [$assignment_search];
if ($assignment_class_filter > 0) {
    $assignment_count_query .= " AND class_id = ?";
    $assignment_count_params[] = $assignment_class_filter;
}
if ($assignment_status_filter) {
    $assignment_count_query .= " AND status = ?";
    $assignment_count_params[] = $assignment_status_filter;
}
$assignment_count_stmt = $db->prepare($assignment_count_query);
$assignment_count_stmt->execute($assignment_count_params);
$total_assignments = $assignment_count_stmt->fetchColumn() ?? 0;
$total_assignment_pages = ceil($total_assignments / $limit);
// Fetch quizzes with search, filters, sorting, pagination
$quiz_search = isset($_GET['quiz_search']) ? '%' . trim($_GET['quiz_search']) . '%' : '%';
$quiz_class_filter = isset($_GET['quiz_class']) ? intval($_GET['quiz_class']) : 0;
$quiz_status_filter = isset($_GET['quiz_status']) ? $_GET['quiz_status'] : '';
$quiz_sort = isset($_GET['quiz_sort']) ? $_GET['quiz_sort'] : 'created_at';
$quiz_order = isset($_GET['quiz_order']) ? strtoupper($_GET['quiz_order']) : 'DESC';
$quiz_page = max(1, intval($_GET['quiz_page'] ?? 1));
$quiz_offset = ($quiz_page - 1) * $limit;
$quiz_query = "SELECT q.*, c.class_name, s.subject_name, u.name AS teacher_name FROM quizzes q JOIN classes c ON q.class_id = c.id JOIN subjects s ON q.subject_id = s.id JOIN users u ON q.teacher_id = u.id WHERE q.title LIKE ?";
$quiz_params = [$quiz_search];
if ($quiz_class_filter > 0) {
    $quiz_query .= " AND q.class_id = ?";
    $quiz_params[] = $quiz_class_filter;
}
if ($quiz_status_filter && in_array($quiz_status_filter, ['draft', 'active', 'closed'])) {
    $quiz_query .= " AND q.status = ?";
    $quiz_params[] = $quiz_status_filter;
}
$quiz_query .= " ORDER BY $quiz_sort $quiz_order LIMIT ? OFFSET ?";
$quiz_params[] = $limit;
$quiz_params[] = $quiz_offset;
$stmt = $db->prepare($quiz_query);
$stmt->execute($quiz_params);
$quizzes = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for quizzes
$quiz_count_query = "SELECT COUNT(*) FROM quizzes WHERE title LIKE ?";
$quiz_count_params = [$quiz_search];
if ($quiz_class_filter > 0) {
    $quiz_count_query .= " AND class_id = ?";
    $quiz_count_params[] = $quiz_class_filter;
}
if ($quiz_status_filter) {
    $quiz_count_query .= " AND status = ?";
    $quiz_count_params[] = $quiz_status_filter;
}
$quiz_count_stmt = $db->prepare($quiz_count_query);
$quiz_count_stmt->execute($quiz_count_params);
$total_quizzes = $quiz_count_stmt->fetchColumn() ?? 0;
$total_quiz_pages = ceil($total_quizzes / $limit);
// Fetch lesson plans with search, filters, sorting, pagination
$plan_search = isset($_GET['plan_search']) ? '%' . trim($_GET['plan_search']) . '%' : '%';
$plan_class_filter = isset($_GET['plan_class']) ? intval($_GET['plan_class']) : 0;
$plan_status_filter = isset($_GET['plan_status']) ? $_GET['plan_status'] : '';
$plan_sort = isset($_GET['plan_sort']) ? $_GET['plan_sort'] : 'created_at';
$plan_order = isset($_GET['plan_order']) ? strtoupper($_GET['plan_order']) : 'DESC';
$plan_page = max(1, intval($_GET['plan_page'] ?? 1));
$plan_offset = ($plan_page - 1) * $limit;
$plan_query = "SELECT lp.*, u.name AS teacher_name, c.class_name, s.subject_name FROM lesson_plans lp JOIN users u ON lp.teacher_id = u.id JOIN classes c ON lp.class_id = c.id JOIN subjects s ON lp.subject_id = s.id WHERE lp.topic LIKE ?";
$plan_params = [$plan_search];
if ($plan_class_filter > 0) {
    $plan_query .= " AND lp.class_id = ?";
    $plan_params[] = $plan_class_filter;
}
if ($plan_status_filter && in_array($plan_status_filter, ['draft', 'published'])) {
    $plan_query .= " AND lp.status = ?";
    $plan_params[] = $plan_status_filter;
}
$plan_query .= " ORDER BY $plan_sort $plan_order LIMIT ? OFFSET ?";
$plan_params[] = $limit;
$plan_params[] = $plan_offset;
$stmt = $db->prepare($plan_query);
$stmt->execute($plan_params);
$lesson_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for lesson plans
$plan_count_query = "SELECT COUNT(*) FROM lesson_plans WHERE topic LIKE ?";
$plan_count_params = [$plan_search];
if ($plan_class_filter > 0) {
    $plan_count_query .= " AND class_id = ?";
    $plan_count_params[] = $plan_class_filter;
}
if ($plan_status_filter) {
    $plan_count_query .= " AND status = ?";
    $plan_count_params[] = $plan_status_filter;
}
$plan_count_stmt = $db->prepare($plan_count_query);
$plan_count_stmt->execute($plan_count_params);
$total_plans = $plan_count_stmt->fetchColumn() ?? 0;
$total_plan_pages = ceil($total_plans / $limit);
// Fetch teacher notes with search, sorting, pagination (enhanced)
$note_search = isset($_GET['note_search']) ? '%' . trim($_GET['note_search']) . '%' : '%';
$note_sort = isset($_GET['note_sort']) ? $_GET['note_sort'] : 'created_at';
$note_order = isset($_GET['note_order']) ? strtoupper($_GET['note_order']) : 'DESC';
$note_page = max(1, intval($_GET['note_page'] ?? 1));
$note_offset = ($note_page - 1) * $limit;
$note_query = "SELECT tn.*, u.name AS teacher_name FROM teacher_notes tn JOIN users u ON tn.teacher_id = u.id WHERE (tn.week LIKE ? OR tn.format LIKE ?)";
$note_params = [$note_search, $note_search];
$note_query .= " ORDER BY $note_sort $note_order LIMIT ? OFFSET ?";
$note_params[] = $limit;
$note_params[] = $note_offset;
$stmt = $db->prepare($note_query);
$stmt->execute($note_params);
$teacher_notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for teacher notes
$note_count_query = "SELECT COUNT(*) FROM teacher_notes WHERE (week LIKE ? OR format LIKE ?)";
$note_count_params = [$note_search, $note_search];
$note_count_stmt = $db->prepare($note_count_query);
$note_count_stmt->execute($note_count_params);
$total_notes = $note_count_stmt->fetchColumn() ?? 0;
$total_note_pages = ceil($total_notes / $limit);
// Fetch termly plans with search, sorting, pagination
$termly_search = isset($_GET['termly_search']) ? '%' . trim($_GET['termly_search']) . '%' : '%';
$termly_sort = isset($_GET['termly_sort']) ? $_GET['termly_sort'] : 'created_at';
$termly_order = isset($_GET['termly_order']) ? strtoupper($_GET['termly_order']) : 'DESC';
$termly_page = max(1, intval($_GET['termly_page'] ?? 1));
$termly_offset = ($termly_page - 1) * $limit;
$termly_query = "SELECT tp.*, u.name AS teacher_name FROM termly_plans tp JOIN users u ON tp.teacher_id = u.id WHERE (tp.term_number LIKE ? OR tp.week LIKE ?)";
$termly_params = [$termly_search, $termly_search];
$termly_query .= " ORDER BY $termly_sort $termly_order LIMIT ? OFFSET ?";
$termly_params[] = $limit;
$termly_params[] = $termly_offset;
$stmt = $db->prepare($termly_query);
$stmt->execute($termly_params);
$termly_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for termly plans
$termly_count_query = "SELECT COUNT(*) FROM termly_plans WHERE (term_number LIKE ? OR week LIKE ?)";
$termly_count_params = [$termly_search, $termly_search];
$termly_count_stmt = $db->prepare($termly_count_query);
$termly_count_stmt->execute($termly_count_params);
$total_termly = $termly_count_stmt->fetchColumn() ?? 0;
$total_termly_pages = ceil($total_termly / $limit);
// Fetch annual plans with search, sorting, pagination
$annual_search = isset($_GET['annual_search']) ? '%' . trim($_GET['annual_search']) . '%' : '%';
$annual_sort = isset($_GET['annual_sort']) ? $_GET['annual_sort'] : 'created_at';
$annual_order = isset($_GET['annual_order']) ? strtoupper($_GET['annual_order']) : 'DESC';
$annual_page = max(1, intval($_GET['annual_page'] ?? 1));
$annual_offset = ($annual_page - 1) * $limit;
$annual_query = "SELECT ap.*, u.name AS teacher_name FROM annual_plans ap JOIN users u ON ap.teacher_id = u.id WHERE ap.week LIKE ?";
$annual_params = [$annual_search];
$annual_query .= " ORDER BY $annual_sort $annual_order LIMIT ? OFFSET ?";
$annual_params[] = $limit;
$annual_params[] = $annual_offset;
$stmt = $db->prepare($annual_query);
$stmt->execute($annual_params);
$annual_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for annual plans
$annual_count_query = "SELECT COUNT(*) FROM annual_plans WHERE week LIKE ?";
$annual_count_params = [$annual_search];
$annual_count_stmt = $db->prepare($annual_count_query);
$annual_count_stmt->execute($annual_count_params);
$total_annual = $annual_count_stmt->fetchColumn() ?? 0;
$total_annual_pages = ceil($total_annual / $limit);
// Fetch learning resources with search, sorting, pagination
$resource_search = isset($_GET['resource_search']) ? '%' . trim($_GET['resource_search']) . '%' : '%';
$resource_sort = isset($_GET['resource_sort']) ? $_GET['resource_sort'] : 'created_at';
$resource_order = isset($_GET['resource_order']) ? strtoupper($_GET['resource_order']) : 'DESC';
$resource_page = max(1, intval($_GET['resource_page'] ?? 1));
$resource_offset = ($resource_page - 1) * $limit;
$resource_query = "SELECT lr.*, u.name AS teacher_name FROM learning_resources lr JOIN users u ON lr.teacher_id = u.id WHERE lr.title LIKE ?";
$resource_params = [$resource_search];
$resource_query .= " ORDER BY $resource_sort $resource_order LIMIT ? OFFSET ?";
$resource_params[] = $limit;
$resource_params[] = $resource_offset;
$stmt = $db->prepare($resource_query);
$stmt->execute($resource_params);
$resources = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for resources
$resource_count_query = "SELECT COUNT(*) FROM learning_resources WHERE title LIKE ?";
$resource_count_params = [$resource_search];
$resource_count_stmt = $db->prepare($resource_count_query);
$resource_count_stmt->execute($resource_count_params);
$total_resources = $resource_count_stmt->fetchColumn() ?? 0;
$total_resource_pages = ceil($total_resources / $limit);
// Fetch forum threads with search, sorting, pagination
$forum_search = isset($_GET['forum_search']) ? '%' . trim($_GET['forum_search']) . '%' : '%';
$forum_sort = isset($_GET['forum_sort']) ? $_GET['forum_sort'] : 'created_at';
$forum_order = isset($_GET['forum_order']) ? strtoupper($_GET['forum_order']) : 'DESC';
$forum_page = max(1, intval($_GET['forum_page'] ?? 1));
$forum_offset = ($forum_page - 1) * $limit;
$forum_query = "SELECT ft.*, u.name AS user_name FROM forum_threads ft JOIN users u ON ft.user_id = u.id WHERE ft.title LIKE ?";
$forum_params = [$forum_search];
$forum_query .= " ORDER BY $forum_sort $forum_order LIMIT ? OFFSET ?";
$forum_params[] = $limit;
$forum_params[] = $forum_offset;
$stmt = $db->prepare($forum_query);
$stmt->execute($forum_params);
$forum_threads = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for forum threads
$forum_count_query = "SELECT COUNT(*) FROM forum_threads WHERE title LIKE ?";
$forum_count_params = [$forum_search];
$forum_count_stmt = $db->prepare($forum_count_query);
$forum_count_stmt->execute($forum_count_params);
$total_forums = $forum_count_stmt->fetchColumn() ?? 0;
$total_forum_pages = ceil($total_forums / $limit);
// Fetch enhanced KPIs
$pending_assignments = $db->query("SELECT COUNT(*) FROM assignments WHERE status = 'published'")->fetchColumn() ?? 0;
$pending_quizzes = $db->query("SELECT COUNT(*) FROM quizzes WHERE status = 'active'")->fetchColumn() ?? 0;
$avg_resource_rating = $db->query("SELECT AVG(rating) FROM resource_feedback")->fetchColumn() ?? 0;
$total_lesson_plans = $db->query("SELECT COUNT(*) FROM lesson_plans")->fetchColumn() ?? 0;
// Fetch classes for filters
$classes = $db->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
// Fetch audit logs (filtered by assignment and lms-related actions, with pagination if needed)
$audit_logs = $db->query("SELECT ul.*, u.name AS actor_name FROM user_logs ul JOIN users u ON ul.user_id = u.id WHERE ul.action LIKE '%assignment%' OR ul.action LIKE '%quiz%' OR ul.action LIKE '%plan%' OR ul.action LIKE '%note%' OR ul.action LIKE '%resource%' OR ul.action LIKE '%forum%' ORDER BY ul.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
// Generate CSRF token
$csrf_token = generate_csrf_token();
?>
<?php include '../templates/admin_header.php'; ?>
    <section class="assignments-management">
        <h1 class="page-title">Assignments & LMS Integration Dashboard</h1>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success" role="alert"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error" role="alert"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <!-- Enhanced KPIs -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <i class="fas fa-tasks icon"></i>
                <div class="value"><?php echo $pending_assignments; ?></div>
                <div class="label">Pending Assignments</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-question-circle icon"></i>
                <div class="value"><?php echo $pending_quizzes; ?></div>
                <div class="label">Pending Quizzes</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-star-half-alt icon"></i>
                <div class="value"><?php echo round($avg_resource_rating, 2); ?></div>
                <div class="label">Avg Resource Rating</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-book-open icon"></i>
                <div class="value"><?php echo $total_lesson_plans; ?></div>
                <div class="label">Total Lesson Plans</div>
            </div>
        </div>
        <!-- Quick Actions (Grid layout with enhanced buttons) -->
        <div class="quick-actions">
            <a href="add_assignment.php" class="action-button"><i class="fas fa-tasks"></i> Add Assignment</a>
            <a href="add_quiz.php" class="action-button"><i class="fas fa-question-circle"></i> Add Quiz</a>
            <a href="add_lesson_plan.php" class="action-button"><i class="fas fa-book"></i> Add Lesson Plan</a>
            <a href="upload_resource.php" class="action-button"><i class="fas fa-folder-open"></i> Upload Resource</a>
            <a href="create_forum_thread.php" class="action-button"><i class="fas fa-comment-dots"></i> Create Forum Thread</a>
            <a href="export_lms_data.php" class="action-button"><i class="fas fa-download"></i> Export Data</a>
        </div>
        <!-- Assignments Section -->
        <div class="section">
            <h2>Assignments</h2>
            <!-- Enhanced Search and Filters for Assignments -->
            <form class="search-form" method="GET">
                <input type="text" name="assignment_search" placeholder="Search by title..." value="<?php echo htmlspecialchars($_GET['assignment_search'] ?? ''); ?>" aria-label="Search Assignments">
                <select name="assignment_class" aria-label="Filter by Class">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo $assignment_class_filter == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="assignment_status" aria-label="Filter by Status">
                    <option value="">All Statuses</option>
                    <option value="draft" <?php echo $assignment_status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="published" <?php echo $assignment_status_filter === 'published' ? 'selected' : ''; ?>>Published</option>
                    <option value="closed" <?php echo $assignment_status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
                <select name="assignment_sort" aria-label="Sort By">
                    <option value="created_at" <?php echo $assignment_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                    <option value="due_date" <?php echo $assignment_sort === 'due_date' ? 'selected' : ''; ?>>Due Date</option>
                    <option value="title" <?php echo $assignment_sort === 'title' ? 'selected' : ''; ?>>Title</option>
                </select>
                <select name="assignment_order" aria-label="Order">
                    <option value="DESC" <?php echo $assignment_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $assignment_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Assignments Table -->
            <form method="POST" id="assignments-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-assignments" aria-label="Select All Assignments"></th>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Class Name</th>
                            <th>Subject Name</th>
                            <th>Teacher Name</th>
                            <th>Status</th>
                            <th>Due Date</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignments as $assignment): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="assignment_ids[]" value="<?php echo $assignment['id']; ?>" aria-label="Select Assignment <?php echo $assignment['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($assignment['id']); ?></td>
                                <td data-label="Title"><?php echo htmlspecialchars($assignment['title']); ?></td>
                                <td data-label="Class Name"><?php echo htmlspecialchars($assignment['class_name']); ?></td>
                                <td data-label="Subject Name"><?php echo htmlspecialchars($assignment['subject_name']); ?></td>
                                <td data-label="Teacher Name"><?php echo htmlspecialchars($assignment['teacher_name']); ?></td>
                                <td data-label="Status"><span class="status-badge <?php echo htmlspecialchars($assignment['status']); ?>"><?php echo ucfirst(htmlspecialchars($assignment['status'])); ?></span></td>
                                <td data-label="Due Date"><?php echo htmlspecialchars($assignment['due_date'] ?? 'N/A'); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($assignment['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_assignment.php?id=<?php echo $assignment['id']; ?>" class="action-icon edit" aria-label="Edit Assignment"><i class="fas fa-edit"></i></a>
                                    <a href="view_assignment.php?id=<?php echo $assignment['id']; ?>" class="action-icon view" aria-label="View Assignment"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this assignment?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_assignment">
                                        <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Assignment"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Assignments -->
                <div class="pagination">
                    <?php if ($assignment_page > 1): ?>
                        <a href="?assignment_page=<?php echo $assignment_page - 1; ?>&assignment_search=<?php echo urlencode($_GET['assignment_search'] ?? ''); ?>&assignment_class=<?php echo $assignment_class_filter; ?>&assignment_status=<?php echo urlencode($assignment_status_filter); ?>&assignment_sort=<?php echo $assignment_sort; ?>&assignment_order=<?php echo $assignment_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $assignment_page; ?> of <?php echo $total_assignment_pages; ?></span>
                    <?php if ($assignment_page < $total_assignment_pages): ?>
                        <a href="?assignment_page=<?php echo $assignment_page + 1; ?>&assignment_search=<?php echo urlencode($_GET['assignment_search'] ?? ''); ?>&assignment_class=<?php echo $assignment_class_filter; ?>&assignment_status=<?php echo urlencode($assignment_status_filter); ?>&assignment_sort=<?php echo $assignment_sort; ?>&assignment_order=<?php echo $assignment_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Enhanced Bulk Actions for Assignments -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Assignments">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_assignments">Delete Selected</option>
                        <option value="bulk_export_assignments">Export Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Quizzes Section -->
        <div class="section">
            <h2>Quizzes</h2>
            <!-- Enhanced Search and Filters for Quizzes -->
            <form class="search-form" method="GET">
                <input type="text" name="quiz_search" placeholder="Search by title..." value="<?php echo htmlspecialchars($_GET['quiz_search'] ?? ''); ?>" aria-label="Search Quizzes">
                <select name="quiz_class" aria-label="Filter by Class">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo $quiz_class_filter == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="quiz_status" aria-label="Filter by Status">
                    <option value="">All Statuses</option>
                    <option value="draft" <?php echo $quiz_status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="active" <?php echo $quiz_status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="closed" <?php echo $quiz_status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
                <select name="quiz_sort" aria-label="Sort By">
                    <option value="created_at" <?php echo $quiz_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                    <option value="start_time" <?php echo $quiz_sort === 'start_time' ? 'selected' : ''; ?>>Start Time</option>
                    <option value="title" <?php echo $quiz_sort === 'title' ? 'selected' : ''; ?>>Title</option>
                </select>
                <select name="quiz_order" aria-label="Order">
                    <option value="DESC" <?php echo $quiz_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $quiz_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Quizzes Table -->
            <form method="POST" id="quizzes-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-quizzes" aria-label="Select All Quizzes"></th>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Class Name</th>
                            <th>Subject Name</th>
                            <th>Teacher Name</th>
                            <th>Status</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($quizzes as $quiz): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="quiz_ids[]" value="<?php echo $quiz['id']; ?>" aria-label="Select Quiz <?php echo $quiz['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($quiz['id']); ?></td>
                                <td data-label="Title"><?php echo htmlspecialchars($quiz['title']); ?></td>
                                <td data-label="Class Name"><?php echo htmlspecialchars($quiz['class_name']); ?></td>
                                <td data-label="Subject Name"><?php echo htmlspecialchars($quiz['subject_name']); ?></td>
                                <td data-label="Teacher Name"><?php echo htmlspecialchars($quiz['teacher_name']); ?></td>
                                <td data-label="Status"><span class="status-badge <?php echo htmlspecialchars($quiz['status']); ?>"><?php echo ucfirst(htmlspecialchars($quiz['status'])); ?></span></td>
                                <td data-label="Start Time"><?php echo htmlspecialchars($quiz['start_time'] ?? 'N/A'); ?></td>
                                <td data-label="End Time"><?php echo htmlspecialchars($quiz['end_time'] ?? 'N/A'); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($quiz['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_quiz.php?id=<?php echo $quiz['id']; ?>" class="action-icon edit" aria-label="Edit Quiz"><i class="fas fa-edit"></i></a>
                                    <a href="view_quiz.php?id=<?php echo $quiz['id']; ?>" class="action-icon view" aria-label="View Quiz"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this quiz?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_quiz">
                                        <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Quiz"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Quizzes -->
                <div class="pagination">
                    <?php if ($quiz_page > 1): ?>
                        <a href="?quiz_page=<?php echo $quiz_page - 1; ?>&quiz_search=<?php echo urlencode($_GET['quiz_search'] ?? ''); ?>&quiz_class=<?php echo $quiz_class_filter; ?>&quiz_status=<?php echo urlencode($quiz_status_filter); ?>&quiz_sort=<?php echo $quiz_sort; ?>&quiz_order=<?php echo $quiz_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $quiz_page; ?> of <?php echo $total_quiz_pages; ?></span>
                    <?php if ($quiz_page < $total_quiz_pages): ?>
                        <a href="?quiz_page=<?php echo $quiz_page + 1; ?>&quiz_search=<?php echo urlencode($_GET['quiz_search'] ?? ''); ?>&quiz_class=<?php echo $quiz_class_filter; ?>&quiz_status=<?php echo urlencode($quiz_status_filter); ?>&quiz_sort=<?php echo $quiz_sort; ?>&quiz_order=<?php echo $quiz_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Enhanced Bulk Actions for Quizzes -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Quizzes">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_quizzes">Delete Selected</option>
                        <option value="bulk_export_quizzes">Export Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Lesson Plans Section -->
        <div class="section">
            <h2>Lesson Plans</h2>
            <!-- Enhanced Search and Filters for Lesson Plans -->
            <form class="search-form" method="GET">
                <input type="text" name="plan_search" placeholder="Search by topic..." value="<?php echo htmlspecialchars($_GET['plan_search'] ?? ''); ?>" aria-label="Search Lesson Plans">
                <select name="plan_class" aria-label="Filter by Class">
                    <option value="">All Classes</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo $plan_class_filter == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="plan_status" aria-label="Filter by Status">
                    <option value="">All Statuses</option>
                    <option value="draft" <?php echo $plan_status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                    <option value="published" <?php echo $plan_status_filter === 'published' ? 'selected' : ''; ?>>Published</option>
                </select>
                <select name="plan_sort" aria-label="Sort By">
                    <option value="created_at" <?php echo $plan_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                    <option value="lesson_date" <?php echo $plan_sort === 'lesson_date' ? 'selected' : ''; ?>>Lesson Date</option>
                    <option value="topic" <?php echo $plan_sort === 'topic' ? 'selected' : ''; ?>>Topic</option>
                </select>
                <select name="plan_order" aria-label="Order">
                    <option value="DESC" <?php echo $plan_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $plan_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Lesson Plans Table -->
            <form method="POST" id="plans-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-plans" aria-label="Select All Lesson Plans"></th>
                            <th>ID</th>
                            <th>Teacher Name</th>
                            <th>Class Name</th>
                            <th>Subject Name</th>
                            <th>Topic</th>
                            <th>Status</th>
                            <th>Lesson Date</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lesson_plans as $plan): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="plan_ids[]" value="<?php echo $plan['id']; ?>" aria-label="Select Lesson Plan <?php echo $plan['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($plan['id']); ?></td>
                                <td data-label="Teacher Name"><?php echo htmlspecialchars($plan['teacher_name']); ?></td>
                                <td data-label="Class Name"><?php echo htmlspecialchars($plan['class_name']); ?></td>
                                <td data-label="Subject Name"><?php echo htmlspecialchars($plan['subject_name']); ?></td>
                                <td data-label="Topic"><?php echo htmlspecialchars($plan['topic']); ?></td>
                                <td data-label="Status"><span class="status-badge <?php echo htmlspecialchars($plan['status']); ?>"><?php echo ucfirst(htmlspecialchars($plan['status'])); ?></span></td>
                                <td data-label="Lesson Date"><?php echo htmlspecialchars($plan['lesson_date']); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($plan['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_lesson_plan.php?id=<?php echo $plan['id']; ?>" class="action-icon edit" aria-label="Edit Lesson Plan"><i class="fas fa-edit"></i></a>
                                    <a href="view_lesson_plan.php?id=<?php echo $plan['id']; ?>" class="action-icon view" aria-label="View Lesson Plan"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this lesson plan?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_lesson_plan">
                                        <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Lesson Plan"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Lesson Plans -->
                <div class="pagination">
                    <?php if ($plan_page > 1): ?>
                        <a href="?plan_page=<?php echo $plan_page - 1; ?>&plan_search=<?php echo urlencode($_GET['plan_search'] ?? ''); ?>&plan_class=<?php echo $plan_class_filter; ?>&plan_status=<?php echo urlencode($plan_status_filter); ?>&plan_sort=<?php echo $plan_sort; ?>&plan_order=<?php echo $plan_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $plan_page; ?> of <?php echo $total_plan_pages; ?></span>
                    <?php if ($plan_page < $total_plan_pages): ?>
                        <a href="?plan_page=<?php echo $plan_page + 1; ?>&plan_search=<?php echo urlencode($_GET['plan_search'] ?? ''); ?>&plan_class=<?php echo $plan_class_filter; ?>&plan_status=<?php echo urlencode($plan_status_filter); ?>&plan_sort=<?php echo $plan_sort; ?>&plan_order=<?php echo $plan_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Enhanced Bulk Actions for Lesson Plans -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Lesson Plans">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_lesson_plans">Delete Selected</option>
                        <option value="bulk_export_lesson_plans">Export Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Teacher Notes Section -->
        <div class="section">
            <h2>Teacher Notes</h2>
            <!-- Search and Sort for Teacher Notes -->
            <form class="search-form" method="GET">
                <input type="text" name="note_search" placeholder="Search by week or format..." value="<?php echo htmlspecialchars($_GET['note_search'] ?? ''); ?>" aria-label="Search Teacher Notes">
                <select name="note_sort" aria-label="Sort By">
                    <option value="created_at" <?php echo $note_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                    <option value="week" <?php echo $note_sort === 'week' ? 'selected' : ''; ?>>Week</option>
                </select>
                <select name="note_order" aria-label="Order">
                    <option value="DESC" <?php echo $note_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $note_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Teacher Notes Table -->
            <form method="POST" id="notes-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-notes" aria-label="Select All Teacher Notes"></th>
                            <th>ID</th>
                            <th>Teacher Name</th>
                            <th>Week</th>
                            <th>Class ID</th>
                            <th>Term ID</th>
                            <th>Subject ID</th>
                            <th>Format</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teacher_notes as $note): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="note_ids[]" value="<?php echo $note['id']; ?>" aria-label="Select Teacher Note <?php echo $note['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($note['id']); ?></td>
                                <td data-label="Teacher Name"><?php echo htmlspecialchars($note['teacher_name']); ?></td>
                                <td data-label="Week"><?php echo htmlspecialchars($note['week']); ?></td>
                                <td data-label="Class ID"><?php echo htmlspecialchars($note['class_id']); ?></td>
                                <td data-label="Term ID"><?php echo htmlspecialchars($note['term_id']); ?></td>
                                <td data-label="Subject ID"><?php echo htmlspecialchars($note['subject_id']); ?></td>
                                <td data-label="Format"><?php echo htmlspecialchars($note['format']); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($note['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_teacher_note.php?id=<?php echo $note['id']; ?>" class="action-icon edit" aria-label="Edit Teacher Note"><i class="fas fa-edit"></i></a>
                                    <a href="view_teacher_note.php?id=<?php echo $note['id']; ?>" class="action-icon view" aria-label="View Teacher Note"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this teacher note?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_teacher_note">
                                        <input type="hidden" name="note_id" value="<?php echo $note['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Teacher Note"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Teacher Notes -->
                <div class="pagination">
                    <?php if ($note_page > 1): ?>
                        <a href="?note_page=<?php echo $note_page - 1; ?>&note_search=<?php echo urlencode($_GET['note_search'] ?? ''); ?>&note_sort=<?php echo $note_sort; ?>&note_order=<?php echo $note_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $note_page; ?> of <?php echo $total_note_pages; ?></span>
                    <?php if ($note_page < $total_note_pages): ?>
                        <a href="?note_page=<?php echo $note_page + 1; ?>&note_search=<?php echo urlencode($_GET['note_search'] ?? ''); ?>&note_sort=<?php echo $note_sort; ?>&note_order=<?php echo $note_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Bulk Actions for Teacher Notes -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Teacher Notes">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_teacher_notes">Delete Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Termly Plans Section -->
        <div class="section">
            <h2>Termly Plans</h2>
            <!-- Search and Sort for Termly Plans -->
            <form class="search-form" method="GET">
                <input type="text" name="termly_search" placeholder="Search by term or week..." value="<?php echo htmlspecialchars($_GET['termly_search'] ?? ''); ?>" aria-label="Search Termly Plans">
                <select name="termly_sort" aria-label="Sort By">
                    <option value="created_at" <?php echo $termly_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                    <option value="term_number" <?php echo $termly_sort === 'term_number' ? 'selected' : ''; ?>>Term Number</option>
                    <option value="week" <?php echo $termly_sort === 'week' ? 'selected' : ''; ?>>Week</option>
                </select>
                <select name="termly_order" aria-label="Order">
                    <option value="DESC" <?php echo $termly_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $termly_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Termly Plans Table -->
            <form method="POST" id="termly-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-termly" aria-label="Select All Termly Plans"></th>
                            <th>ID</th>
                            <th>Teacher Name</th>
                            <th>Class ID</th>
                            <th>Subject ID</th>
                            <th>Term Number</th>
                            <th>Week</th>
                            <th>Format</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($termly_plans as $plan): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="termly_ids[]" value="<?php echo $plan['id']; ?>" aria-label="Select Termly Plan <?php echo $plan['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($plan['id']); ?></td>
                                <td data-label="Teacher Name"><?php echo htmlspecialchars($plan['teacher_name']); ?></td>
                                <td data-label="Class ID"><?php echo htmlspecialchars($plan['class_id']); ?></td>
                                <td data-label="Subject ID"><?php echo htmlspecialchars($plan['subject_id']); ?></td>
                                <td data-label="Term Number"><?php echo htmlspecialchars($plan['term_number']); ?></td>
                                <td data-label="Week"><?php echo htmlspecialchars($plan['week']); ?></td>
                                <td data-label="Format"><?php echo htmlspecialchars($plan['format']); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($plan['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_termly_plan.php?id=<?php echo $plan['id']; ?>" class="action-icon edit" aria-label="Edit Termly Plan"><i class="fas fa-edit"></i></a>
                                    <a href="view_termly_plan.php?id=<?php echo $plan['id']; ?>" class="action-icon view" aria-label="View Termly Plan"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this termly plan?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_termly_plan">
                                        <input type="hidden" name="termly_id" value="<?php echo $plan['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Termly Plan"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Termly Plans -->
                <div class="pagination">
                    <?php if ($termly_page > 1): ?>
                        <a href="?termly_page=<?php echo $termly_page - 1; ?>&termly_search=<?php echo urlencode($_GET['termly_search'] ?? ''); ?>&termly_sort=<?php echo $termly_sort; ?>&termly_order=<?php echo $termly_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $termly_page; ?> of <?php echo $total_termly_pages; ?></span>
                    <?php if ($termly_page < $total_termly_pages): ?>
                        <a href="?termly_page=<?php echo $termly_page + 1; ?>&termly_search=<?php echo urlencode($_GET['termly_search'] ?? ''); ?>&termly_sort=<?php echo $termly_sort; ?>&termly_order=<?php echo $termly_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Bulk Actions for Termly Plans -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Termly Plans">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_termly_plans">Delete Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Annual Plans Section -->
        <div class="section">
            <h2>Annual Plans</h2>
            <!-- Search and Sort for Annual Plans -->
            <form class="search-form" method="GET">
                <input type="text" name="annual_search" placeholder="Search by week..." value="<?php echo htmlspecialchars($_GET['annual_search'] ?? ''); ?>" aria-label="Search Annual Plans">
                <select name="annual_sort" aria-label="Sort By">
                    <option value="created_at" <?php echo $annual_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                    <option value="week" <?php echo $annual_sort === 'week' ? 'selected' : ''; ?>>Week</option>
                </select>
                <select name="annual_order" aria-label="Order">
                    <option value="DESC" <?php echo $annual_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $annual_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Annual Plans Table -->
            <form method="POST" id="annual-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-annual" aria-label="Select All Annual Plans"></th>
                            <th>ID</th>
                            <th>Teacher Name</th>
                            <th>Class ID</th>
                            <th>Subject ID</th>
                            <th>Week</th>
                            <th>Format</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($annual_plans as $plan): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="annual_ids[]" value="<?php echo $plan['id']; ?>" aria-label="Select Annual Plan <?php echo $plan['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($plan['id']); ?></td>
                                <td data-label="Teacher Name"><?php echo htmlspecialchars($plan['teacher_name']); ?></td>
                                <td data-label="Class ID"><?php echo htmlspecialchars($plan['class_id']); ?></td>
                                <td data-label="Subject ID"><?php echo htmlspecialchars($plan['subject_id']); ?></td>
                                <td data-label="Week"><?php echo htmlspecialchars($plan['week']); ?></td>
                                <td data-label="Format"><?php echo htmlspecialchars($plan['format']); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($plan['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_annual_plan.php?id=<?php echo $plan['id']; ?>" class="action-icon edit" aria-label="Edit Annual Plan"><i class="fas fa-edit"></i></a>
                                    <a href="view_annual_plan.php?id=<?php echo $plan['id']; ?>" class="action-icon view" aria-label="View Annual Plan"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this annual plan?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_annual_plan">
                                        <input type="hidden" name="annual_id" value="<?php echo $plan['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Annual Plan"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Annual Plans -->
                <div class="pagination">
                    <?php if ($annual_page > 1): ?>
                        <a href="?annual_page=<?php echo $annual_page - 1; ?>&annual_search=<?php echo urlencode($_GET['annual_search'] ?? ''); ?>&annual_sort=<?php echo $annual_sort; ?>&annual_order=<?php echo $annual_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $annual_page; ?> of <?php echo $total_annual_pages; ?></span>
                    <?php if ($annual_page < $total_annual_pages): ?>
                        <a href="?annual_page=<?php echo $annual_page + 1; ?>&annual_search=<?php echo urlencode($_GET['annual_search'] ?? ''); ?>&annual_sort=<?php echo $annual_sort; ?>&annual_order=<?php echo $annual_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Bulk Actions for Annual Plans -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Annual Plans">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_annual_plans">Delete Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Learning Resources Section -->
        <div class="section">
            <h2>Learning Resources</h2>
            <!-- Search and Sort for Learning Resources -->
            <form class="search-form" method="GET">
                <input type="text" name="resource_search" placeholder="Search by title..." value="<?php echo htmlspecialchars($_GET['resource_search'] ?? ''); ?>" aria-label="Search Resources">
                <select name="resource_sort" aria-label="Sort By">
                    <option value="created_at" <?php echo $resource_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                    <option value="rating" <?php echo $resource_sort === 'rating' ? 'selected' : ''; ?>>Rating</option>
                    <option value="views_count" <?php echo $resource_sort === 'views_count' ? 'selected' : ''; ?>>Views</option>
                </select>
                <select name="resource_order" aria-label="Order">
                    <option value="DESC" <?php echo $resource_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $resource_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Learning Resources Table -->
            <form method="POST" id="resources-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-resources" aria-label="Select All Resources"></th>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Teacher Name</th>
                            <th>Resource Type</th>
                            <th>Status</th>
                            <th>Views</th>
                            <th>Downloads</th>
                            <th>Rating</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resources as $resource): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="resource_ids[]" value="<?php echo $resource['id']; ?>" aria-label="Select Resource <?php echo $resource['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($resource['id']); ?></td>
                                <td data-label="Title"><?php echo htmlspecialchars($resource['title']); ?></td>
                                <td data-label="Teacher Name"><?php echo htmlspecialchars($resource['teacher_name']); ?></td>
                                <td data-label="Resource Type"><?php echo ucfirst(htmlspecialchars($resource['resource_type'])); ?></td>
                                <td data-label="Status"><?php echo $resource['is_approved'] ? 'Approved' : 'Pending'; ?></td>
                                <td data-label="Views"><?php echo htmlspecialchars($resource['views_count']); ?></td>
                                <td data-label="Downloads"><?php echo htmlspecialchars($resource['downloads_count']); ?></td>
                                <td data-label="Rating"><?php echo round($resource['rating'], 2); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($resource['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_resource.php?id=<?php echo $resource['id']; ?>" class="action-icon edit" aria-label="Edit Resource"><i class="fas fa-edit"></i></a>
                                    <a href="view_resource.php?id=<?php echo $resource['id']; ?>" class="action-icon view" aria-label="View Resource"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this resource?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_resource">
                                        <input type="hidden" name="resource_id" value="<?php echo $resource['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Resource"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Learning Resources -->
                <div class="pagination">
                    <?php if ($resource_page > 1): ?>
                        <a href="?resource_page=<?php echo $resource_page - 1; ?>&resource_search=<?php echo urlencode($_GET['resource_search'] ?? ''); ?>&resource_sort=<?php echo $resource_sort; ?>&resource_order=<?php echo $resource_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $resource_page; ?> of <?php echo $total_resource_pages; ?></span>
                    <?php if ($resource_page < $total_resource_pages): ?>
                        <a href="?resource_page=<?php echo $resource_page + 1; ?>&resource_search=<?php echo urlencode($_GET['resource_search'] ?? ''); ?>&resource_sort=<?php echo $resource_sort; ?>&resource_order=<?php echo $resource_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Bulk Actions for Learning Resources -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Resources">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_resources">Delete Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Forum Threads Section -->
        <div class="section">
            <h2>Forum Threads</h2>
            <!-- Search and Sort for Forum Threads -->
            <form class="search-form" method="GET">
                <input type="text" name="forum_search" placeholder="Search by title..." value="<?php echo htmlspecialchars($_GET['forum_search'] ?? ''); ?>" aria-label="Search Forum Threads">
                <select name="forum_sort" aria-label="Sort By">
                    <option value="created_at" <?php echo $forum_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                    <option value="title" <?php echo $forum_sort === 'title' ? 'selected' : ''; ?>>Title</option>
                </select>
                <select name="forum_order" aria-label="Order">
                    <option value="DESC" <?php echo $forum_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $forum_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Forum Threads Table -->
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>User Name</th>
                        <th>Class ID</th>
                        <th>Subject ID</th>
                        <th>Is Pinned</th>
                        <th>Is Locked</th>
                        <th>Created At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($forum_threads as $thread): ?>
                        <tr>
                            <td data-label="ID"><?php echo htmlspecialchars($thread['id']); ?></td>
                            <td data-label="Title"><?php echo htmlspecialchars($thread['title']); ?></td>
                            <td data-label="User Name"><?php echo htmlspecialchars($thread['user_name']); ?></td>
                            <td data-label="Class ID"><?php echo htmlspecialchars($thread['class_id'] ?? 'N/A'); ?></td>
                            <td data-label="Subject ID"><?php echo htmlspecialchars($thread['subject_id'] ?? 'N/A'); ?></td>
                            <td data-label="Is Pinned"><?php echo $thread['is_pinned'] ? 'Yes' : 'No'; ?></td>
                            <td data-label="Is Locked"><?php echo $thread['is_locked'] ? 'Yes' : 'No'; ?></td>
                            <td data-label="Created At"><?php echo htmlspecialchars($thread['created_at']); ?></td>
                            <td data-label="Actions">
                                <a href="edit_forum_thread.php?id=<?php echo $thread['id']; ?>" class="action-icon edit" aria-label="Edit Forum Thread"><i class="fas fa-edit"></i></a>
                                <a href="view_forum_thread.php?id=<?php echo $thread['id']; ?>" class="action-icon view" aria-label="View Forum Thread"><i class="fas fa-eye"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <!-- Pagination for Forum Threads -->
            <div class="pagination">
                <?php if ($forum_page > 1): ?>
                    <a href="?forum_page=<?php echo $forum_page - 1; ?>&forum_search=<?php echo urlencode($_GET['forum_search'] ?? ''); ?>&forum_sort=<?php echo $forum_sort; ?>&forum_order=<?php echo $forum_order; ?>">&laquo; Previous</a>
                <?php endif; ?>
                <span>Page <?php echo $forum_page; ?> of <?php echo $total_forum_pages; ?></span>
                <?php if ($forum_page < $total_forum_pages): ?>
                    <a href="?forum_page=<?php echo $forum_page + 1; ?>&forum_search=<?php echo urlencode($_GET['forum_search'] ?? ''); ?>&forum_sort=<?php echo $forum_sort; ?>&forum_order=<?php echo $forum_order; ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>
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
        .assignments-management {
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
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            color: var(--primary-white);
            display: inline-block;
            min-width: 60px;
            text-align: center;
        }
        .status-badge.draft { background-color: #ffc107; }
        .status-badge.published, .status-badge.active { background-color: #28a745; }
        .status-badge.closed { background-color: #dc3545; }
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
            .assignments-management {
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