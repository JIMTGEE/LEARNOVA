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
    header("Location: subjects.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: add_subject.php");
        exit();
    }

    $subject_name = trim($_POST['subject_name'] ?? '');
    $subject_code = trim($_POST['subject_code'] ?? '');
    $department_id = intval($_POST['department_id'] ?? 0);
    $teacher_id = intval($_POST['teacher_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $status = $_POST['status'] ?? 'Active';

    if (!$subject_name || !$subject_code || $department_id <= 0) {
        $_SESSION['error'] = 'Subject name, code, and department are required.';
        header("Location: add_subject.php");
        exit();
    }

    // Check for unique code
    $stmt = $db->prepare("SELECT COUNT(*) FROM subjects WHERE subject_code = ?");
    $stmt->execute([$subject_code]);
    if ($stmt->fetchColumn() > 0) {
        $_SESSION['error'] = 'Subject code already exists.';
        header("Location: add_subject.php");
        exit();
    }

    $created_by = $_SESSION['user_id'];

    $stmt = $db->prepare("INSERT INTO subjects (subject_name, subject_code, teacher_id, description, status, created_by, department_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$subject_name, $subject_code, $teacher_id ?: null, $description, $status, $created_by, $department_id]);
    $new_id = $db->lastInsertId();

    log_activity($_SESSION['user_id'], 'add_subject', "Added new subject ID: $new_id - $subject_name ($subject_code) in department ID: $department_id");

    $_SESSION['success'] = 'Subject added successfully.';
    header("Location: subjects.php");
    exit();
}

// Fetch departments and teachers for dropdowns
$departments = $db->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$teachers = $db->query("SELECT id, CONCAT(name, ' (ID: ', id, ')') AS name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); // Adjusted to users table for consistency

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

<section class="add-subject">
    <div class="page-header">
        <h1 class="page-title">Add New Subject</h1>
        <a href="subjects.php" class="back-button" aria-label="Back to Subjects List">
            <i class="fas fa-arrow-left" aria-hidden="true"></i> Back to Subjects
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

    <div class="form-card">
        <form method="POST" class="subject-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <div class="form-grid">
                <div class="form-group">
                    <label for="subject_name">Subject Name <span class="required">*</span></label>
                    <input type="text" id="subject_name" name="subject_name" required maxlength="100" placeholder="e.g., Mathematics" aria-required="true">
                </div>

                <div class="form-group">
                    <label for="subject_code">Subject Code <span class="required">*</span></label>
                    <input type="text" id="subject_code" name="subject_code" required maxlength="20" placeholder="e.g., MATH101" aria-required="true">
                </div>

                <div class="form-group full-width">
                    <label for="department_id">Department <span class="required">*</span></label>
                    <select id="department_id" name="department_id" required aria-required="true">
                        <option value="">Select a Department</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group full-width">
                    <label for="teacher_id">Assign Teacher (Optional)</label>
                    <select id="teacher_id" name="teacher_id" aria-label="Assign Teacher">
                        <option value="0">No Teacher Assigned</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group full-width">
                    <label for="description">Description (Optional)</label>
                    <textarea id="description" name="description" rows="4" placeholder="Brief description of the subject..."></textarea>
                </div>

                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="Active" selected>Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="submit-button">
                <i class="fas fa-book-medical" aria-hidden="true"></i> Add Subject
            </button>
        </form>
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

    .add-subject {
        max-width: 900px;
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
        padding: 8px 12px;
        border-radius: 8px;
        transition: all var(--transition);
    }

    .back-button:hover {
        color: var(--accent-soft-cyan);
        background-color: rgba(23, 162, 184, 0.05);
        transform: translateX(-4px);
    }

    .back-button i {
        margin-right: 8px;
    }

    .alert {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
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

    .form-card {
        background: var(--primary-white);
        border-radius: 16px;
        box-shadow: var(--shadow-subtle);
        padding: 40px;
        transition: box-shadow var(--transition);
    }

    .form-card:hover {
        box-shadow: 0 12px 32px rgba(0, 0, 0, 0.15);
    }

    .subject-form {
        display: grid;
        gap: 24px;
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

    .form-group input,
    .form-group select,
    .form-group textarea {
        padding: 14px 16px;
        border: 1px solid var(--accent-slate-gray);
        border-radius: 12px;
        font-family: var(--font-content);
        font-size: 15px;
        background: var(--primary-white);
        transition: all var(--transition);
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: var(--primary-sea-blue);
        box-shadow: 0 0 0 4px rgba(23, 162, 184, 0.15);
    }

    .form-group input::placeholder,
    .form-group textarea::placeholder {
        color: var(--accent-slate-gray);
        opacity: 0.8;
    }

    .form-group textarea {
        resize: vertical;
        min-height: 120px;
    }

    .submit-button {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        background: linear-gradient(135deg, var(--primary-sea-blue), #138496);
        color: var(--primary-white);
        padding: 16px 32px;
        border: none;
        border-radius: 12px;
        font-family: var(--font-ui);
        font-size: 17px;
        font-weight: 600;
        cursor: pointer;
        margin-top: 16px;
        transition: all var(--transition);
        box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
    }

    .submit-button:hover {
        background: linear-gradient(135deg, var(--accent-soft-cyan), #1abc9c);
        transform: translateY(-3px);
        box-shadow: 0 8px 20px rgba(53, 208, 255, 0.4);
    }

    /* Responsive Adjustments */
    @media (max-width: 768px) {
        .add-subject {
            margin: 20px auto;
            padding: 0 16px;
        }

        .page-title {
            font-size: 26px;
        }

        .form-card {
            padding: 24px;
        }

        .form-grid {
            grid-template-columns: 1fr;
        }

        .page-header {
            flex-direction: column;
            align-items: flex-start;
        }
    }

    @media (max-width: 576px) {
        .page-title {
            font-size: 24px;
        }

        .submit-button {
            font-size: 16px;
            padding: 14px 24px;
        }
    }
</style>

<?php include '../templates/admin_footer.php'; ?>