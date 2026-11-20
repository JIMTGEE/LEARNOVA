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

// Handle actions: add, edit, delete with upgraded validation and overlap check
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: manage_schedules.php");
        exit();
    }

    $action = $_POST['action'] ?? '';
    $id = intval($_POST['id'] ?? 0);
    $term_id = intval($_POST['term_id'] ?? 0);
    $class_id = intval($_POST['class_id'] ?? 0);
    $day_of_week = $_POST['day_of_week'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $subject_id = intval($_POST['subject_id'] ?? 0);
    $teacher_id = intval($_POST['teacher_id'] ?? 0);

    // Validate common fields
    if ($term_id <= 0 || $class_id <= 0 || !in_array($day_of_week, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']) || empty($start_time) || empty($end_time) || $subject_id <= 0 || $teacher_id <= 0) {
        $_SESSION['error'] = 'Invalid input data. Please check all fields.';
        header("Location: manage_schedules.php");
        exit();
    }

    // Get department_id from class
    $dept_stmt = $db->prepare("SELECT department_id FROM classes WHERE id = ?");
    $dept_stmt->execute([$class_id]);
    $department_id = $dept_stmt->fetchColumn();
    if (!$department_id) {
        $_SESSION['error'] = 'Invalid class selected.';
        header("Location: manage_schedules.php");
        exit();
    }

    // Validate assignment exists in annual_plans
    $assign_stmt = $db->prepare("SELECT COUNT(*) FROM annual_plans WHERE class_id = ? AND subject_id = ? AND teacher_id = ?");
    $assign_stmt->execute([$class_id, $subject_id, $teacher_id]);
    if ($assign_stmt->fetchColumn() == 0) {
        $_SESSION['error'] = 'Selected subject and teacher not assigned to this class.';
        header("Location: manage_schedules.php");
        exit();
    }

    // Check for time overlap
    $overlap_query = "SELECT COUNT(*) FROM class_schedules WHERE class_id = ? AND day_of_week = ? AND (
        (start_time < ? AND end_time > ?) OR 
        (start_time < ? AND end_time > ?) OR 
        (start_time >= ? AND start_time < ?) OR 
        (end_time > ? AND end_time <= ?)
    )";
    $overlap_params = [$class_id, $day_of_week, $end_time, $start_time, $start_time, $end_time, $start_time, $end_time, $start_time, $end_time];
    if ($action === 'edit' && $id > 0) {
        $overlap_query .= " AND id != ?";
        $overlap_params[] = $id;
    }
    $overlap_stmt = $db->prepare($overlap_query);
    $overlap_stmt->execute($overlap_params);
    if ($overlap_stmt->fetchColumn() > 0) {
        $_SESSION['error'] = 'Time overlap detected for this class on the selected day.';
        header("Location: manage_schedules.php");
        exit();
    }

    switch ($action) {
        case 'add':
            $stmt = $db->prepare("INSERT INTO class_schedules (class_id, day_of_week, start_time, end_time, subject_id, term_id, department_id, teacher_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$class_id, $day_of_week, $start_time, $end_time, $subject_id, $term_id, $department_id, $teacher_id]);
            log_activity($_SESSION['user_id'], 'add_schedule', "Added schedule for class ID: $class_id, subject ID: $subject_id, teacher ID: $teacher_id");
            $_SESSION['success'] = 'Schedule added successfully.';
            break;

        case 'edit':
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE class_schedules SET class_id = ?, day_of_week = ?, start_time = ?, end_time = ?, subject_id = ?, term_id = ?, department_id = ?, teacher_id = ? WHERE id = ?");
                $stmt->execute([$class_id, $day_of_week, $start_time, $end_time, $subject_id, $term_id, $department_id, $teacher_id, $id]);
                log_activity($_SESSION['user_id'], 'edit_schedule', "Edited schedule ID: $id");
                $_SESSION['success'] = 'Schedule updated successfully.';
            } else {
                $_SESSION['error'] = 'Invalid schedule ID.';
            }
            break;

        case 'delete':
            if ($id > 0) {
                $stmt = $db->prepare("DELETE FROM class_schedules WHERE id = ?");
                $stmt->execute([$id]);
                log_activity($_SESSION['user_id'], 'delete_schedule', "Deleted schedule ID: $id");
                $_SESSION['success'] = 'Schedule deleted successfully.';
            } else {
                $_SESSION['error'] = 'Invalid schedule ID.';
            }
            break;
    }
    header("Location: manage_schedules.php");
    exit();
}

// Handle GET for edit
$action = $_GET['action'] ?? '';
$schedule_id = intval($_GET['id'] ?? 0);
$schedule = null;
if ($action === 'edit' && $schedule_id > 0) {
    $stmt = $db->prepare("SELECT * FROM class_schedules WHERE id = ?");
    $stmt->execute([$schedule_id]);
    $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$schedule) {
        $_SESSION['error'] = 'Schedule not found.';
        header("Location: manage_schedules.php");
        exit();
    }
}

// Fetch lists
$terms = $db->query("SELECT id, name FROM terms ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$classes = $db->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
$subjects = $db->query("SELECT id, subject_name FROM subjects ORDER BY subject_name")->fetchAll(PDO::FETCH_ASSOC);
$teachers = $db->query("SELECT id, name FROM users WHERE role = 'teacher' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch current schedules
$schedules = $db->query("SELECT cs.*, c.class_name, s.subject_name, u.name AS teacher_name, t.name AS term_name 
                         FROM class_schedules cs 
                         JOIN classes c ON cs.class_id = c.id 
                         JOIN subjects s ON cs.subject_id = s.id 
                         JOIN users u ON cs.teacher_id = u.id 
                         JOIN terms t ON cs.term_id = t.id 
                         ORDER BY t.start_date DESC, c.class_name, cs.day_of_week, cs.start_time")->fetchAll(PDO::FETCH_ASSOC);

// Days enum
$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="manage-schedules">
        <h1 class="page-title">Manage Teaching Schedules</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <!-- Quick Actions Grid -->
        <div class="quick-actions-grid">
            <a href="classes.php" class="action-card">
                <i class="fas fa-chalkboard icon"></i>
                <span>Manage Classes</span>
            </a>
            <a href="assign_teachers.php" class="action-card">
                <i class="fas fa-chalkboard-teacher icon"></i>
                <span>Assign Teachers</span>
            </a>
            <a href="assign_subjects.php" class="action-card">
                <i class="fas fa-book icon"></i>
                <span>Assign Subjects</span>
            </a>
            <a href="terms.php" class="action-card">
                <i class="fas fa-calendar-alt icon"></i>
                <span>Manage Terms</span>
            </a>
        </div>

        <!-- Add/Edit Form Card -->
        <div class="form-card">
            <h2><?php echo $action === 'edit' ? 'Edit Schedule' : 'Add New Schedule'; ?></h2>
            <form method="POST" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="<?php echo $action === 'edit' ? 'edit' : 'add'; ?>">
                <input type="hidden" name="id" value="<?php echo $schedule_id; ?>">

                <div class="form-group">
                    <label for="term_id">Term:</label>
                    <select id="term_id" name="term_id" required>
                        <option value="">Select Term</option>
                        <?php foreach ($terms as $term): ?>
                            <option value="<?php echo $term['id']; ?>" <?php echo ($schedule['term_id'] ?? 0) == $term['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($term['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="class_id">Class:</label>
                    <select id="class_id" name="class_id" required>
                        <option value="">Select Class</option>
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo ($schedule['class_id'] ?? 0) == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="day_of_week">Day of Week:</label>
                    <select id="day_of_week" name="day_of_week" required>
                        <option value="">Select Day</option>
                        <?php foreach ($days as $day): ?>
                            <option value="<?php echo $day; ?>" <?php echo ($schedule['day_of_week'] ?? '') === $day ? 'selected' : ''; ?>><?php echo $day; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="start_time">Start Time:</label>
                    <input type="time" id="start_time" name="start_time" value="<?php echo htmlspecialchars($schedule['start_time'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="end_time">End Time:</label>
                    <input type="time" id="end_time" name="end_time" value="<?php echo htmlspecialchars($schedule['end_time'] ?? ''); ?>" required>
                </div>

                <div class="form-group">
                    <label for="subject_id">Subject:</label>
                    <select id="subject_id" name="subject_id" required>
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>" <?php echo ($schedule['subject_id'] ?? 0) == $subject['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="teacher_id">Teacher:</label>
                    <select id="teacher_id" name="teacher_id" required>
                        <option value="">Select Teacher</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo $teacher['id']; ?>" <?php echo ($schedule['teacher_id'] ?? 0) == $teacher['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($teacher['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-actions">
                    <button type="submit" class="save-button">Save Schedule</button>
                    <?php if ($action === 'edit'): ?>
                        <a href="manage_schedules.php" class="cancel-button">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Current Schedules Table Card -->
        <div class="table-card">
            <div class="table-header">
                <h2>Current Schedules</h2>
                <input type="text" id="searchInput" placeholder="Search schedules..." class="search-input">
            </div>
            <div class="table-wrapper">
                <table class="data-table" id="schedulesTable">
                    <thead>
                        <tr>
                            <th>Term</th>
                            <th>Class</th>
                            <th>Day</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Subject</th>
                            <th>Teacher</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($schedules as $sched): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sched['term_name']); ?></td>
                                <td><?php echo htmlspecialchars($sched['class_name']); ?></td>
                                <td><?php echo htmlspecialchars($sched['day_of_week']); ?></td>
                                <td><?php echo htmlspecialchars($sched['start_time']); ?></td>
                                <td><?php echo htmlspecialchars($sched['end_time']); ?></td>
                                <td><?php echo htmlspecialchars($sched['subject_name']); ?></td>
                                <td><?php echo htmlspecialchars($sched['teacher_name']); ?></td>
                                <td>
                                    <a href="manage_schedules.php?action=edit&id=<?php echo $sched['id']; ?>" class="action-icon edit"><i class="fas fa-edit"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this schedule?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $sched['id']; ?>">
                                        <button type="submit" class="action-icon delete"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <script>
        // Simple table search functionality
        const searchInput = document.getElementById('searchInput');
        const table = document.getElementById('schedulesTable');
        const rows = table.querySelectorAll('tbody tr');

        searchInput.addEventListener('keyup', function() {
            const filter = searchInput.value.toLowerCase();
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
    </script>

    <style>
        /* Premium UI Design System Alignment */
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

        .manage-schedules {
            display: grid;
            gap: 24px;
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
            font-family: var(--font-content);
        }

        .page-title {
            font-family: var(--font-ui);
            font-size: 32px;
            font-weight: 700;
            color: var(--primary-black);
            text-align: center;
            margin-bottom: 20px;
        }

        .alert {
            padding: 16px;
            border-radius: 8px;
            font-weight: 500;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            transition: opacity 0.3s ease-out;
        }

        .alert.success {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }

        .alert.error {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .action-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            text-decoration: none;
            color: var(--primary-black);
            transition: transform 0.2s ease-out, box-shadow 0.2s ease-out;
        }

        .action-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }

        .action-card .icon {
            font-size: 32px;
            color: var(--primary-sea-blue);
            margin-bottom: 12px;
        }

        .action-card span {
            font-size: 16px;
            font-weight: 600;
            text-align: center;
        }

        .form-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 24px;
            transition: box-shadow 0.2s ease-out;
        }

        .form-card:hover {
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }

        .form-card h2 {
            font-size: 24px;
            margin-bottom: 20px;
            color: var(--primary-black);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-weight: 500;
            margin-bottom: 8px;
            color: var(--accent-slate-gray);
        }

        .form-group select,
        .form-group input[type="time"] {
            padding: 12px;
            border: 1px solid var(--accent-slate-gray);
            border-radius: 8px;
            background-color: var(--primary-white);
            transition: border-color 0.2s ease-out, box-shadow 0.2s ease-out;
        }

        .form-group select:focus,
        .form-group input[type="time"]:focus {
            border-color: var(--primary-sea-blue);
            box-shadow: 0 0 0 3px rgba(23, 162, 184, 0.2);
            outline: none;
        }

        .form-actions {
            grid-column: 1 / -1;
            display: flex;
            justify-content: flex-start;
            gap: 16px;
            margin-top: 16px;
        }

        .save-button {
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s ease-out, transform 0.2s ease-out;
        }

        .save-button:hover {
            background-color: var(--accent-soft-cyan);
            transform: translateY(-2px);
        }

        .cancel-button {
            background-color: var(--accent-slate-gray);
            color: var(--primary-white);
            padding: 12px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: background-color 0.2s ease-out, transform 0.2s ease-out;
        }

        .cancel-button:hover {
            background-color: var(--accent-deep-navy);
            transform: translateY(-2px);
        }

        .table-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 24px;
            overflow: hidden;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .table-header h2 {
            font-size: 24px;
            color: var(--primary-black);
        }

        .search-input {
            padding: 12px;
            border: 1px solid var(--accent-slate-gray);
            border-radius: 8px;
            width: 250px;
            transition: border-color 0.2s ease-out;
        }

        .search-input:focus {
            border-color: var(--primary-sea-blue);
            outline: none;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }

        .data-table th {
            background-color: var(--accent-deep-navy);
            color: var(--primary-white);
            font-weight: 600;
        }

        .data-table tbody tr {
            transition: background-color 0.2s ease-out;
        }

        .data-table tbody tr:hover {
            background-color: rgba(23, 162, 184, 0.05);
        }

        .action-icon {
            margin-right: 12px;
            color: var(--primary-sea-blue);
            text-decoration: none;
            font-size: 18px;
            transition: color 0.2s ease-out;
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

        /* Responsive Design */
        @media (max-width: 992px) {
            .form-grid {
                grid-template-columns: 1fr;
            }

            .quick-actions-grid {
                grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .manage-schedules {
                padding: 15px;
            }

            .table-header {
                flex-direction: column;
                gap: 12px;
            }

            .search-input {
                width: 100%;
            }

            .data-table th,
            .data-table td {
                padding: 12px;
                font-size: 14px;
            }

            .data-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }

        @media (max-width: 576px) {
            .page-title {
                font-size: 24px;
            }

            .action-card .icon {
                font-size: 28px;
            }

            .action-card span {
                font-size: 14px;
            }
        }
    </style>

<?php include '../templates/admin_footer.php'; ?>