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
require_once __DIR__ . '/../config/db.php';
$db = db();

// Include helpers and functions
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

// Handle actions: add, delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: assign_subjects.php");
        exit();
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $subject_id = intval($_POST['subject_id'] ?? 0);
                $teacher_id = intval($_POST['teacher_id'] ?? 0);
                $class_ids = $_POST['class_ids'] ?? [];

                if ($subject_id <= 0 || $teacher_id <= 0 || empty($class_ids)) {
                    $_SESSION['error'] = 'Invalid input data. Please select subject, teacher, and at least one class.';
                    break;
                }

                foreach ($class_ids as $class_id) {
                    $class_id = intval($class_id);
                    if ($class_id <= 0) continue;

                    $dept_stmt = $db->prepare("SELECT department_id FROM classes WHERE id = ?");
                    $dept_stmt->execute([$class_id]);
                    $department_id = $dept_stmt->fetchColumn() ?? 0;

                    if ($department_id <= 0) continue;

                    $stmt = $db->prepare("INSERT INTO annual_plans (teacher_id, class_id, subject_id, department_id, week, term_1, term_2, term_3, format, created_at, updated_at) VALUES (?, ?, ?, ?, 1, '', '', '', 'oxford', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                    $stmt->execute([$teacher_id, $class_id, $subject_id, $department_id]);
                    log_activity($_SESSION['user_id'], 'assign_subject', "Assigned subject ID: $subject_id to class ID: $class_id by teacher ID: $teacher_id");
                }
                $_SESSION['success'] = 'Subjects assigned to classes successfully.';
                break;

            case 'delete':
                $class_id = intval($_POST['class_id'] ?? 0);
                $subject_id = intval($_POST['subject_id'] ?? 0);
                $teacher_id = intval($_POST['teacher_id'] ?? 0);

                if ($class_id > 0 && $subject_id > 0 && $teacher_id > 0) {
                    $stmt = $db->prepare("DELETE FROM annual_plans WHERE class_id = ? AND subject_id = ? AND teacher_id = ?");
                    $stmt->execute([$class_id, $subject_id, $teacher_id]);
                    log_activity($_SESSION['user_id'], 'unassign_subject', "Unassigned subject ID: $subject_id from class ID: $class_id (teacher ID: $teacher_id)");
                    $_SESSION['success'] = 'Assignment deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid assignment details.';
                }
                break;
        }
        header("Location: assign_subjects.php");
        exit();
    }
}

// Fetch data
$subjects = $db->query("SELECT id, subject_name FROM subjects ORDER BY subject_name")->fetchAll(PDO::FETCH_ASSOC);
$teachers = $db->query("SELECT id, name FROM users WHERE role = 'teacher' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$classes = $db->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);

$current_assignments = $db->query("SELECT MIN(ap.id) as id, c.class_name, s.subject_name, u.name as teacher_name, ap.class_id, ap.subject_id, ap.teacher_id 
                                   FROM annual_plans ap 
                                   JOIN classes c ON ap.class_id = c.id 
                                   JOIN subjects s ON ap.subject_id = s.id 
                                   JOIN users u ON ap.teacher_id = u.id 
                                   GROUP BY ap.class_id, ap.subject_id, ap.teacher_id 
                                   ORDER BY c.class_name, s.subject_name")->fetchAll(PDO::FETCH_ASSOC);

$csrf_token = generate_csrf_token();
?>

<?php include '../templates/admin_header.php'; ?>

<section class="assign-subjects">
    <div class="page-header">
        <h1 class="page-title">Assign Subjects to Classes</h1>
        <a href="dashboard.php" class="back-button" aria-label="Back to Dashboard">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Dashboard
        </a>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success" role="alert" aria-live="polite">
            <i class="fas fa-check-circle" aria-hidden="true"></i>
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error" role="alert" aria-live="assertive">
            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <div class="quick-actions-grid">
        <a href="subjects.php" class="action-card">
            <i class="fas fa-book"></i>
            <span>Manage Subjects</span>
        </a>
        <a href="classes.php" class="action-card">
            <i class="fas fa-chalkboard"></i>
            <span>Manage Classes</span>
        </a>
        <a href="users.php?role=teacher" class="action-card">
            <i class="fas fa-chalkboard-teacher"></i>
            <span>Manage Teachers</span>
        </a>
    </div>

    <div class="form-card">
        <h2 class="card-title">New Assignment</h2>
        <form method="POST" class="assignment-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="add">

            <div class="form-grid">
                <div class="form-group">
                    <label for="subject_id">Subject <span class="required">*</span></label>
                    <select id="subject_id" name="subject_id" required aria-required="true">
                        <option value="">Select Subject</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="teacher_id">Teacher <span class="required">*</span></label>
                    <select id="teacher_id" name="teacher_id" required aria-required="true">
                        <option value="">Select Teacher</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group full-width">
                    <label for="class_ids">Classes (Hold Ctrl/Cmd to select multiple) <span class="required">*</span></label>
                    <select id="class_ids" name="class_ids[]" multiple required aria-required="true" size="8">
                        <?php foreach ($classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="submit-button">
                <i class="fas fa-plus-circle" aria-hidden="true"></i> Assign Subjects
            </button>
        </form>
    </div>

    <div class="table-card">
        <h2 class="card-title">Current Assignments</h2>
        <?php if (empty($current_assignments)): ?>
            <p class="empty-message">No assignments yet. Create one above.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table" aria-label="Current Subject Assignments">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Subject</th>
                            <th>Teacher</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($current_assignments as $assignment): ?>
                            <tr>
                                <td data-label="Class"><?php echo htmlspecialchars($assignment['class_name']); ?></td>
                                <td data-label="Subject"><?php echo htmlspecialchars($assignment['subject_name']); ?></td>
                                <td data-label="Teacher"><?php echo htmlspecialchars($assignment['teacher_name']); ?></td>
                                <td data-label="Actions">
                                    <form method="POST" class="delete-form" onsubmit="return confirm('Are you sure you want to remove this assignment?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="class_id" value="<?php echo $assignment['class_id']; ?>">
                                        <input type="hidden" name="subject_id" value="<?php echo $assignment['subject_id']; ?>">
                                        <input type="hidden" name="teacher_id" value="<?php echo $assignment['teacher_id']; ?>">
                                        <button type="submit" class="delete-button" aria-label="Delete assignment">
                                            <i class="fas fa-trash-alt" aria-hidden="true"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<style>
    :root {
        --primary-white: #FFFFFF;
        --primary-sea-blue: #17A2B8;
        --primary-black: #0B0D10;
        --accent-deep-navy: #081F2C;
        --accent-slate-gray: #5E6A79;
        --accent-soft-cyan: #35D0FF;
        --font-ui: 'Inter', sans-serif;
        --font-content: 'Source Sans 3', sans-serif;
        --shadow-subtle: 0 4px 16px rgba(0, 0, 0, 0.1);
        --transition: 220ms ease-out;
    }

    .assign-subjects {
        max-width: 1200px;
        margin: 40px auto;
        padding: 0 20px;
        animation: fadeIn 0.5s ease-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 32px;
        flex-wrap: wrap;
        gap: 16px;
    }

    .page-title {
        font-family: var(--font-ui);
        font-size: 32px;
        font-weight: 700;
        color: var(--primary-black);
        margin: 0;
    }

    .back-button {
        display: flex;
        align-items: center;
        font-family: var(--font-ui);
        font-size: 16px;
        color: var(--primary-sea-blue);
        text-decoration: none;
        padding: 8px 16px;
        border-radius: 12px;
        transition: all var(--transition);
    }

    .back-button:hover {
        color: var(--accent-soft-cyan);
        background: rgba(23, 162, 184, 0.05);
        transform: translateX(-4px);
    }

    .back-button i {
        margin-right: 8px;
    }

    .alert {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 24px;
        margin-bottom: 32px;
        border-radius: 12px;
        font-family: var(--font-content);
        font-size: 15px;
        box-shadow: var(--shadow-subtle);
        animation: slideIn 0.3s ease-out;
    }

    @keyframes slideIn {
        from { opacity: 0; transform: translateX(-20px); }
        to { opacity: 1; transform: translateX(0); }
    }

    .alert.success {
        background: rgba(212, 237, 218, 0.95);
        color: #155724;
        border-left: 5px solid #28a745;
    }

    .alert.error {
        background: rgba(248, 215, 218, 0.95);
        color: #721c24;
        border-left: 5px solid #dc3545;
    }

    .quick-actions-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin-bottom: 40px;
    }

    .action-card {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        background: var(--primary-white);
        padding: 24px;
        border-radius: 16px;
        box-shadow: var(--shadow-subtle);
        text-decoration: none;
        color: var(--primary-black);
        transition: all var(--transition);
        text-align: center;
    }

    .action-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 12px 32px rgba(0, 0, 0, 0.15);
    }

    .action-card i {
        font-size: 40px;
        color: var(--primary-sea-blue);
        margin-bottom: 16px;
    }

    .action-card span {
        font-family: var(--font-ui);
        font-size: 16px;
        font-weight: 600;
    }

    .form-card, .table-card {
        background: var(--primary-white);
        border-radius: 16px;
        box-shadow: var(--shadow-subtle);
        padding: 40px;
        margin-bottom: 40px;
        transition: box-shadow var(--transition);
    }

    .form-card:hover, .table-card:hover {
        box-shadow: 0 12px 32px rgba(0, 0, 0, 0.15);
    }

    .card-title {
        font-family: var(--font-ui);
        font-size: 24px;
        font-weight: 700;
        color: var(--primary-black);
        margin-bottom: 32px;
        text-align: center;
    }

    .assignment-form {
        display: grid;
        gap: 32px;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 24px;
    }

    .form-group.full-width {
        grid-column: 1 / -1;
    }

    .form-group {
        display: flex;
        flex-direction: column;
    }

    .form-group label {
        font-family: var(--font-ui);
        font-weight: 600;
        color: var(--accent-deep-navy);
        margin-bottom: 8px;
        font-size: 15px;
    }

    .required {
        color: #e74c3c;
    }

    .form-group select {
        padding: 14px 16px;
        border: 1px solid var(--accent-slate-gray);
        border-radius: 12px;
        font-family: var(--font-content);
        font-size: 15px;
        background: var(--primary-white);
        transition: all var(--transition);
    }

    .form-group select:focus {
        outline: none;
        border-color: var(--primary-sea-blue);
        box-shadow: 0 0 0 4px rgba(23, 162, 184, 0.15);
    }

    .form-group select[multiple] {
        height: 200px;
        padding: 12px;
    }

    .submit-button {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        background: linear-gradient(135deg, var(--primary-sea-blue), #138496);
        color: var(--primary-white);
        padding: 16px 40px;
        border: none;
        border-radius: 12px;
        font-family: var(--font-ui);
        font-size: 17px;
        font-weight: 600;
        cursor: pointer;
        margin: 0 auto;
        transition: all var(--transition);
        box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
    }

    .submit-button:hover {
        background: linear-gradient(135deg, var(--accent-soft-cyan), #1abc9c);
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(53, 208, 255, 0.4);
    }

    .table-wrapper {
        overflow-x: auto;
    }

    .data-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0 8px;
        font-family: var(--font-content);
    }

    .data-table thead th {
        background: var(--accent-deep-navy);
        color: var(--primary-white);
        padding: 16px;
        text-align: left;
        font-weight: 600;
        font-size: 15px;
    }

    .data-table tbody tr {
        background: var(--primary-white);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        transition: all var(--transition);
    }

    .data-table tbody tr:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
    }

    .data-table td {
        padding: 20px 16px;
    }

    .delete-form {
        display: inline;
    }

    .delete-button {
        background: none;
        border: none;
        color: #dc3545;
        font-size: 20px;
        cursor: pointer;
        padding: 8px;
        border-radius: 8px;
        transition: all var(--transition);
    }

    .delete-button:hover {
        background: rgba(220, 53, 69, 0.1);
        color: #c82333;
        transform: scale(1.1);
    }

    .empty-message {
        text-align: center;
        color: var(--accent-slate-gray);
        font-size: 16px;
        padding: 40px;
    }

    @media (max-width: 992px) {
        .form-grid {
            grid-template-columns: 1fr;
        }
    }

    @media (max-width: 768px) {
        .assign-subjects {
            margin: 20px auto;
            padding: 0 16px;
        }

        .page-title {
            font-size: 26px;
        }

        .form-card, .table-card {
            padding: 24px;
        }

        .quick-actions-grid {
            grid-template-columns: 1fr;
        }

        .data-table thead {
            display: none;
        }

        .data-table tbody tr {
            display: block;
            margin-bottom: 16px;
            border-radius: 12px;
        }

        .data-table td {
            display: block;
            text-align: right;
            padding: 12px 16px;
            position: relative;
        }

        .data-table td:before {
            content: attr(data-label);
            position: absolute;
            left: 16px;
            font-weight: 600;
            color: var(--accent-deep-navy);
        }

        .data-table td:last-child {
            text-align: center;
        }
    }

    @media (max-width: 576px) {
        .page-title {
            font-size: 24px;
        }

        .submit-button {
            width: 100%;
            padding: 16px;
        }
    }
</style>

<?php include '../templates/admin_footer.php'; ?>