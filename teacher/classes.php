<?php
// teacher/classes.php - Teacher Classes Management Page

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not authenticated as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    header("Location: ../login.php");
    exit();
}

// Get root path
$root_path = dirname(__DIR__) . '/';

// Include required files
require_once $root_path . 'config/db.php';
require_once $root_path . 'includes/helpers.php';
require_once $root_path . 'functions/csrf.php';
require_once $root_path . 'functions/log_activity.php';

// Get DB connection
$db = db();

// Handle actions: edit class, add/remove student, manage schedules
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token.';
        header("Location: classes.php");
        exit();
    }

    $action = $_POST['action'] ?? '';
    $class_id = intval($_POST['class_id'] ?? 0);
    $teacher_id = $_SESSION['user_id'];

    switch ($action) {
        case 'edit_class':
            // Edit class details (description only, assume)
            $description = trim($_POST['description'] ?? '');
            if ($class_id > 0 && $description) {
                $stmt = $db->prepare("UPDATE classes SET description = ? WHERE id = ? AND (teacher_id = ? OR class_teacher_id = ?)");
                $stmt->execute([$description, $class_id, $teacher_id, $teacher_id]);
                if ($stmt->rowCount() > 0) {
                    log_activity($teacher_id, 'edit_class', "Edited class ID: $class_id");
                    $_SESSION['success'] = 'Class updated successfully.';
                } else {
                    $_SESSION['error'] = 'No changes or unauthorized.';
                }
            }
            break;

        case 'add_student':
            $student_id = intval($_POST['student_id'] ?? 0);
            $academic_year_id = intval($_POST['academic_year_id'] ?? 0); // Assume current year
            if ($class_id > 0 && $student_id > 0 && $academic_year_id > 0) {
                $stmt = $db->prepare("INSERT IGNORE INTO student_classes (user_id, class_id, academic_year_id) VALUES (?, ?, ?)");
                $stmt->execute([$student_id, $class_id, $academic_year_id]);
                if ($stmt->rowCount() > 0) {
                    log_activity($teacher_id, 'add_student_to_class', "Added student ID: $student_id to class ID: $class_id");
                    $_SESSION['success'] = 'Student added to class.';
                }
            }
            break;

        case 'remove_student':
            $student_id = intval($_POST['student_id'] ?? 0);
            if ($class_id > 0 && $student_id > 0) {
                $stmt = $db->prepare("DELETE FROM student_classes WHERE user_id = ? AND class_id = ?");
                $stmt->execute([$student_id, $class_id]);
                if ($stmt->rowCount() > 0) {
                    log_activity($teacher_id, 'remove_student_from_class', "Removed student ID: $student_id from class ID: $class_id");
                    $_SESSION['success'] = 'Student removed from class.';
                }
            }
            break;

        case 'add_schedule':
            $day_of_week = $_POST['day_of_week'] ?? '';
            $start_time = $_POST['start_time'] ?? '';
            $end_time = $_POST['end_time'] ?? '';
            $subject_id = intval($_POST['subject_id'] ?? 0);
            $term_id = intval($_POST['term_id'] ?? 0);
            $department_id = intval($_POST['department_id'] ?? 0);
            if ($class_id > 0 && $day_of_week && $start_time && $end_time && $subject_id && $term_id) {
                $stmt = $db->prepare("INSERT INTO class_schedules (class_id, day_of_week, start_time, end_time, subject_id, term_id, department_id, teacher_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$class_id, $day_of_week, $start_time, $end_time, $subject_id, $term_id, $department_id ?: null, $teacher_id]);
                log_activity($teacher_id, 'add_schedule', "Added schedule for class ID: $class_id");
                $_SESSION['success'] = 'Schedule added.';
            }
            break;

        case 'edit_schedule':
            $schedule_id = intval($_POST['schedule_id'] ?? 0);
            $day_of_week = $_POST['day_of_week'] ?? '';
            $start_time = $_POST['start_time'] ?? '';
            $end_time = $_POST['end_time'] ?? '';
            $subject_id = intval($_POST['subject_id'] ?? 0);
            $term_id = intval($_POST['term_id'] ?? 0);
            if ($schedule_id > 0 && $day_of_week && $start_time && $end_time && $subject_id && $term_id) {
                $stmt = $db->prepare("UPDATE class_schedules SET day_of_week = ?, start_time = ?, end_time = ?, subject_id = ?, term_id = ? WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$day_of_week, $start_time, $end_time, $subject_id, $term_id, $schedule_id, $teacher_id]);
                if ($stmt->rowCount() > 0) {
                    log_activity($teacher_id, 'edit_schedule', "Edited schedule ID: $schedule_id");
                    $_SESSION['success'] = 'Schedule updated.';
                }
            }
            break;

        case 'delete_schedule':
            $schedule_id = intval($_POST['schedule_id'] ?? 0);
            if ($schedule_id > 0) {
                $stmt = $db->prepare("DELETE FROM class_schedules WHERE id = ? AND teacher_id = ?");
                $stmt->execute([$schedule_id, $teacher_id]);
                if ($stmt->rowCount() > 0) {
                    log_activity($teacher_id, 'delete_schedule', "Deleted schedule ID: $schedule_id");
                    $_SESSION['success'] = 'Schedule deleted.';
                }
            }
            break;
    }
    header("Location: classes.php");
    exit();
}

// Fetch teacher's classes
$teacher_id = $_SESSION['user_id'];
$stmt = $db->prepare("SELECT * FROM classes WHERE teacher_id = ? OR class_teacher_id = ? ORDER BY class_name");
$stmt->execute([$teacher_id, $teacher_id]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch additional data: subjects, terms, academic years, departments for forms
$subjects = $db->query("SELECT id, subject_name FROM subjects ORDER BY subject_name")->fetchAll(PDO::FETCH_ASSOC);
$terms = $db->query("SELECT id, name FROM terms ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$academic_years = $db->query("SELECT id, year FROM academic_years ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
$departments = $db->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// For adding students: Fetch potential students not in class (but for simplicity, assume a search/select)
$all_students = $db->query("SELECT id, name FROM users WHERE role = 'student' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// CSRF token
$csrf_token = generate_csrf_token();

// Include header
include $root_path . 'templates/teacher_header.php';
?>

<!-- Classes Management Content -->
<section class="classes-management">
    <div class="dashboard-header">
        <h1 class="page-title">My Classes</h1>
        <div class="dashboard-subtitle">
            <span>Manage students, schedules, and class details</span>
        </div>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="quick-actions-grid">
        <a href="attendance.php" class="quick-action">
            <i class="fas fa-clipboard-check"></i>
            <span>Take Attendance</span>
        </a>
        <a href="assignments.php" class="quick-action">
            <i class="fas fa-tasks"></i>
            <span>Manage Assignments</span>
        </a>
        <a href="reports.php" class="quick-action">
            <i class="fas fa-chart-bar"></i>
            <span>Class Reports</span>
        </a>
    </div>

    <!-- Classes Grid -->
    <div class="classes-grid">
        <?php if (empty($classes)): ?>
            <p class="empty-message">No classes assigned yet.</p>
        <?php else: ?>
            <?php foreach ($classes as $class): ?>
                <?php
                // Fetch student count
                $stmt = $db->prepare("SELECT COUNT(*) FROM student_classes WHERE class_id = ?");
                $stmt->execute([$class['id']]);
                $student_count = $stmt->fetchColumn();

                // Fetch schedules
                $stmt = $db->prepare("SELECT * FROM class_schedules WHERE class_id = ? ORDER BY day_of_week, start_time");
                $stmt->execute([$class['id']]);
                $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Fetch students
                $stmt = $db->prepare("SELECT u.id, u.name FROM users u JOIN student_classes sc ON u.id = sc.user_id WHERE sc.class_id = ? ORDER BY u.name");
                $stmt->execute([$class['id']]);
                $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <div class="class-card">
                    <div class="class-header">
                        <i class="fas fa-chalkboard"></i>
                        <h4><?php echo htmlspecialchars($class['class_name']); ?></h4>
                    </div>
                    <div class="class-stats">
                        <div class="class-stat">
                            <i class="fas fa-users"></i>
                            <span><?php echo $student_count; ?> Students</span>
                        </div>
                        <div class="class-stat">
                            <i class="fas fa-book"></i>
                            <span>Department: <?php echo htmlspecialchars($class['department']); ?></span>
                        </div>
                    </div>

                    <!-- Edit Class Form -->
                    <form method="POST" class="class-edit-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="edit_class">
                        <input type="hidden" name="class_id" value="<?php echo $class['id']; ?>">
                        <label>Description:</label>
                        <textarea name="description"><?php echo htmlspecialchars($class['description'] ?? ''); ?></textarea>
                        <button type="submit">Update Description</button>
                    </form>

                    <!-- Manage Students -->
                    <div class="manage-section">
                        <h5>Students</h5>
                        <ul class="student-list">
                            <?php foreach ($students as $student): ?>
                                <li>
                                    <?php echo htmlspecialchars($student['name']); ?>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Remove student?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="remove_student">
                                        <input type="hidden" name="class_id" value="<?php echo $class['id']; ?>">
                                        <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">
                                        <button type="submit" class="action-icon delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <!-- Add Student -->
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="add_student">
                            <input type="hidden" name="class_id" value="<?php echo $class['id']; ?>">
                            <input type="hidden" name="academic_year_id" value="<?php echo $academic_years[0]['id'] ?? 0; ?>"> <!-- Assume first year -->
                            <select name="student_id">
                                <option value="">Select Student</option>
                                <?php foreach ($all_students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit">Add Student</button>
                        </form>
                    </div>

                    <!-- Manage Schedules -->
                    <div class="manage-section">
                        <h5>Schedules</h5>
                        <div class="schedule-list">
                            <?php foreach ($schedules as $schedule): ?>
                                <div class="schedule-item">
                                    <div class="schedule-time">
                                        <?php echo htmlspecialchars($schedule['day_of_week']); ?>: <?php echo $schedule['start_time'] . ' - ' . $schedule['end_time']; ?>
                                    </div>
                                    <div class="schedule-details">
                                        Subject ID: <?php echo $schedule['subject_id']; ?>
                                    </div>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete schedule?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_schedule">
                                        <input type="hidden" name="schedule_id" value="<?php echo $schedule['id']; ?>">
                                        <button type="submit" class="action-icon delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                    <!-- Edit link or modal -->
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <!-- Add Schedule Form -->
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="action" value="add_schedule">
                            <input type="hidden" name="class_id" value="<?php echo $class['id']; ?>">
                            <select name="day_of_week">
                                <option>Monday</option><option>Tuesday</option><option>Wednesday</option><option>Thursday</option><option>Friday</option>
                            </select>
                            <input type="time" name="start_time">
                            <input type="time" name="end_time">
                            <select name="subject_id">
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="term_id">
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit">Add Schedule</button>
                        </form>
                    </div>

                    <div class="class-actions">
                        <a href="attendance.php?class_id=<?php echo $class['id']; ?>" class="class-btn attendance"><i class="fas fa-clipboard-check"></i> Attendance</a>
                        <a href="assignments.php?class_id=<?php echo $class['id']; ?>" class="class-btn view"><i class="fas fa-tasks"></i> Assignments</a>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</section>

<!-- Styles (matching dashboard) -->
<style>
/* Reuse dashboard styles */
.classes-management {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
}

.classes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.class-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 20px;
    box-shadow: var(--card-shadow);
    border: 1px solid var(--border-color);
}

.manage-section {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid var(--border-color);
}

.student-list {
    max-height: 200px;
    overflow-y: auto;
    list-style: none;
    padding: 0;
}

.student-list li {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid var(--border-color);
}

.class-edit-form textarea {
    width: 100%;
    min-height: 100px;
}

.alert {
    padding: 16px;
    border-radius: 8px;
    margin-bottom: 16px;
}

.alert.success { background: rgba(40,167,69,0.1); color: #28a745; }
.alert.error { background: rgba(220,53,69,0.1); color: #dc3545; }

.action-icon.delete {
    color: #dc3545;
    cursor: pointer;
}
</style>

<?php
// Include footer
include $root_path . 'templates/teacher_footer.php';
?>