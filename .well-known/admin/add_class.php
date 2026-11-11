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
    header("Location: classes.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: add_class.php");
        exit();
    }

    $class_name = trim($_POST['class_name'] ?? '');
    $department_id = intval($_POST['department_id'] ?? 0);
    $class_teacher_id = intval($_POST['class_teacher_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');

    if (!$class_name || $department_id <= 0) {
        $_SESSION['error'] = 'Class name and department are required.';
        header("Location: add_class.php");
        exit();
    }

    // Check for uniqueness
    $stmt = $db->prepare("SELECT COUNT(*) FROM classes WHERE class_name = ? AND department_id = ?");
    $stmt->execute([$class_name, $department_id]);
    if ($stmt->fetchColumn() > 0) {
        $_SESSION['error'] = 'Class name already exists in this department.';
        header("Location: add_class.php");
        exit();
    }

    $stmt = $db->prepare("INSERT INTO classes (class_name, class_teacher_id, description, department_id, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->execute([$class_name, $class_teacher_id ?: null, $description, $department_id]);
    $new_id = $db->lastInsertId();

    log_activity($_SESSION['user_id'], 'add_class', "Added new class ID: $new_id - $class_name in department ID: $department_id");

    $_SESSION['success'] = 'Class added successfully.';
    header("Location: classes.php");
    exit();
}

// Fetch departments and teachers for dropdowns
$departments = $db->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Use users table for teachers (role = 'teacher')
$teachers = $db->query("SELECT id, name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Generate CSRF token
$csrf_token = generate_csrf_token();
?>

<?php include '../templates/admin_header.php'; ?>

<section class="add-class-page">
    <div class="page-header">
        <h1 class="page-title">Add New Class</h1>
        <p class="page-subtitle">Create a new class and assign department & teacher</p>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <form method="POST" class="premium-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <div class="form-group">
                <label for="class_name" class="form-label">
                    <i class="fas fa-school"></i> Class Name <span class="required">*</span>
                </label>
                <input 
                    type="text" 
                    id="class_name" 
                    name="class_name" 
                    class="form-input" 
                    placeholder="e.g., Grade 10 Science" 
                    required 
                    maxlength="100"
                    aria-required="true"
                >
                <span class="field-hint">Unique name for the class within the department</span>
            </div>

            <div class="form-group">
                <label for="department_id" class="form-label">
                    <i class="fas fa-building"></i> Department <span class="required">*</span>
                </label>
                <select id="department_id" name="department_id" class="form-select" required aria-required="true">
                    <option value="">Select a department</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="class_teacher_id" class="form-label">
                    <i class="fas fa-chalkboard-teacher"></i> Class Teacher (Optional)
                </label>
                <select id="class_teacher_id" name="class_teacher_id" class="form-select">
                    <option value="0">No teacher assigned yet</option>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="field-hint">You can assign a teacher later if needed</span>
            </div>

            <div class="form-group">
                <label for="description" class="form-label">
                    <i class="fas fa-info-circle"></i> Description (Optional)
                </label>
                <textarea 
                    id="description" 
                    name="description" 
                    class="form-textarea" 
                    rows="4" 
                    placeholder="Brief description about this class, goals, or special notes..."
                ></textarea>
            </div>

            <div class="form-actions">
                <a href="classes.php" class="btn-secondary">
                    <i class="fas fa-arrow-left"></i> Cancel
                </a>
                <button type="submit" class="btn-primary">
                    <i class="fas fa-plus-circle"></i> Create Class
                </button>
            </div>
        </form>
    </div>
</section>

<style>
    /* Premium Add Class Page - Ultra Modern UI */
    .add-class-page {
        max-width: 800px;
        margin: 0 auto;
        padding: 32px 20px;
        display: grid;
        gap: 32px;
    }
    .page-header {
        text-align: center;
    }
    .page-title {
        font-size: 36px;
        font-weight: 700;
        color: var(--primary-black);
        margin: 0;
    }
    .page-subtitle {
        font-size: 18px;
        color: var(--accent-slate-gray);
        margin-top: 12px;
    }
    .alert {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
        border-radius: 12px;
        font-size: 16px;
        font-weight: 500;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
    }
    .alert.success {
        background: rgba(40,167,69,0.15);
        color: #155724;
    }
    .alert.error {
        background: rgba(220,53,69,0.15);
        color: #721c24;
    }
    .alert i {
        font-size: 24px;
    }
    .form-card {
        background: var(--primary-white);
        border-radius: 20px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.12);
        padding: 40px;
        overflow: hidden;
    }
    .premium-form {
        display: grid;
        gap: 28px;
    }
    .form-group {
        display: grid;
        gap: 8px;
    }
    .form-label {
        font-size: 16px;
        font-weight: 600;
        color: var(--primary-black);
        display: flex;
        align-items: center;
        gap: 8px;
    }
    .required {
        color: #dc3545;
    }
    .form-input, .form-select, .form-textarea {
        padding: 16px 20px;
        border: 2px solid transparent;
        border-radius: 12px;
        font-size: 16px;
        background: #f8f9fa;
        transition: all 0.22s ease-out;
        font-family: var(--font-content);
    }
    .form-input:focus, .form-select:focus, .form-textarea:focus {
        outline: none;
        border-color: var(--primary-sea-blue);
        background: var(--primary-white);
        box-shadow: 0 0 0 4px rgba(23,162,184,0.15);
        transform: translateY(-2px);
    }
    .form-select {
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath fill='%235E6A79' d='M1 1l5 5 5-5'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 20px center;
    }
    .field-hint {
        font-size: 14px;
        color: var(--accent-slate-gray);
        margin-top: 4px;
    }
    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 20px;
        margin-top: 16px;
        flex-wrap: wrap;
    }
    .btn-primary, .btn-secondary {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 12px;
        padding: 16px 32px;
        border-radius: 12px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.22s ease-out;
        min-width: 180px;
        box-shadow: 0 6px 16px rgba(0,0,0,0.1);
    }
    .btn-primary {
        background: var(--primary-sea-blue);
        color: var(--primary-white);
        border: none;
    }
    .btn-primary:hover {
        background: var(--accent-soft-cyan);
        transform: translateY(-4px);
        box-shadow: 0 12px 24px rgba(53,208,255,0.3);
    }
    .btn-secondary {
        background: var(--accent-slate-gray);
        color: var(--primary-white);
        text-decoration: none;
    }
    .btn-secondary:hover {
        background: #4a5564;
        transform: translateY(-4px);
    }
    .btn-primary i, .btn-secondary i {
        font-size: 20px;
    }

    /* Responsive */
    @media (max-width: 768px) {
        .add-class-page {
            padding: 20px;
        }
        .form-card {
            padding: 24px;
        }
        .page-title {
            font-size: 28px;
        }
        .form-actions {
            flex-direction: column;
        }
        .btn-primary, .btn-secondary {
            width: 100%;
        }
    }
    @media (max-width: 576px) {
        .page-title {
            font-size: 24px;
        }
        .page-subtitle {
            font-size: 16px;
        }
    }
</style>

<script>
    // Micro-interactions & form enhancement
    document.addEventListener('DOMContentLoaded', () => {
        // Floating label effect (optional enhancement)
        document.querySelectorAll('.form-input, .form-textarea').forEach(input => {
            input.addEventListener('focus', () => input.parentElement.classList.add('focused'));
            input.addEventListener('blur', () => {
                if (!input.value) input.parentElement.classList.remove('focused');
            });
        });

        // Submit button loading state (optional)
        const form = document.querySelector('.premium-form');
        form.addEventListener('submit', () => {
            const btn = form.querySelector('.btn-primary');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating...';
        });
    });
</script>

<?php include '../templates/admin_footer.php'; ?>