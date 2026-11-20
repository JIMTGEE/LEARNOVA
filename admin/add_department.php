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
    header("Location: departments.php"); // Assume departments.php exists for listing
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: add_department.php");
        exit();
    }

    $name = trim($_POST['name'] ?? '');

    if (!$name) {
        $_SESSION['error'] = 'Department name is required.';
        header("Location: add_department.php");
        exit();
    }

    // Check for uniqueness
    $stmt = $db->prepare("SELECT COUNT(*) FROM departments WHERE name = ?");
    $stmt->execute([$name]);
    if ($stmt->fetchColumn() > 0) {
        $_SESSION['error'] = 'Department name already exists.';
        header("Location: add_department.php");
        exit();
    }

    // Fix for non-auto-increment ID: Manually generate next ID to avoid duplicate '0' or null issues
    $stmt = $db->query("SELECT MAX(id) FROM departments");
    $max_id = $stmt->fetchColumn() ?? 0;
    $new_id = $max_id + 1;

    // Insert with explicit ID
    $stmt = $db->prepare("INSERT INTO departments (id, name) VALUES (?, ?)");
    $stmt->execute([$new_id, $name]);

    log_activity($_SESSION['user_id'], 'add_department', "Added new department ID: $new_id - $name");

    $_SESSION['success'] = 'Department added successfully.';
    header("Location: departments.php"); // Redirect to list page
    exit();
}

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

<section class="add-department">
    <div class="page-header">
        <h1 class="page-title">Add New Department</h1>
        <a href="departments.php" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Departments
        </a>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success" role="alert">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error" role="alert">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="department-form">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

        <div class="form-group">
            <label for="name">Department Name:</label>
            <input type="text" id="name" name="name" required maxlength="100" placeholder="Enter department name...">
        </div>

        <button type="submit" class="submit-button">
            <i class="fas fa-plus"></i> Add Department
        </button>
    </form>
</section>

<style>
    /* Premium UI Design Upgrade: Ultra Modern with Micro-Interactions, Shadows, and Responsiveness */
    :root {
        --primary-white: #FFFFFF;
        --primary-sea-blue: #17A2B8;
        --primary-black: #0B0D10;
        --accent-deep-navy: #081F2C;
        --accent-slate-gray: #5E6A79;
        --accent-soft-cyan: #35D0FF;
        --font-ui: 'Inter', sans-serif;
        --font-content: 'Source Sans 3', sans-serif;
        --shadow-subtle: 0 4px 12px rgba(0, 0, 0, 0.1);
        --transition-ease: 0.2s ease-out;
    }

    .add-department {
        max-width: 600px;
        margin: 40px auto;
        padding: 32px;
        background-color: var(--primary-white);
        border-radius: 16px;
        box-shadow: var(--shadow-subtle);
        transition: box-shadow var(--transition-ease);
    }

    .add-department:hover {
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 32px;
    }

    .page-title {
        font-family: var(--font-ui);
        font-size: 28px;
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
        transition: color var(--transition-ease), transform var(--transition-ease);
    }

    .back-button:hover {
        color: var(--accent-soft-cyan);
        transform: translateX(-4px);
    }

    .back-button i {
        margin-right: 8px;
    }

    .alert {
        display: flex;
        align-items: center;
        padding: 16px;
        margin-bottom: 24px;
        border-radius: 8px;
        font-family: var(--font-content);
        font-size: 15px;
        transition: opacity var(--transition-ease);
    }

    .alert.success {
        background: rgba(212, 237, 218, 0.9); /* Soft green */
        color: #155724;
    }

    .alert.error {
        background: rgba(248, 215, 218, 0.9); /* Soft red */
        color: #721c24;
    }

    .alert i {
        margin-right: 12px;
        font-size: 18px;
    }

    .department-form {
        display: grid;
        gap: 24px;
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

    .form-group input {
        padding: 14px 16px;
        border: 1px solid var(--accent-slate-gray);
        border-radius: 8px;
        font-family: var(--font-content);
        font-size: 15px;
        transition: border-color var(--transition-ease), box-shadow var(--transition-ease);
    }

    .form-group input:focus {
        border-color: var(--primary-sea-blue);
        box-shadow: 0 0 0 3px rgba(23, 162, 184, 0.2);
        outline: none;
    }

    .form-group input::placeholder {
        color: var(--accent-slate-gray);
    }

    .submit-button {
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: var(--primary-sea-blue);
        color: var(--primary-white);
        padding: 16px;
        border: none;
        border-radius: 8px;
        font-family: var(--font-ui);
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: background-color var(--transition-ease), transform var(--transition-ease), box-shadow var(--transition-ease);
    }

    .submit-button:hover {
        background-color: var(--accent-soft-cyan);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(53, 208, 255, 0.3);
    }

    .submit-button i {
        margin-right: 12px;
    }

    /* Mobile Responsiveness */
    @media (max-width: 768px) {
        .add-department {
            padding: 24px;
            margin: 20px;
        }

        .page-title {
            font-size: 24px;
        }

        .back-button {
            font-size: 14px;
        }

        .alert {
            font-size: 14px;
        }

        .form-group label {
            font-size: 14px;
        }

        .form-group input {
            font-size: 14px;
            padding: 12px 14px;
        }

        .submit-button {
            font-size: 15px;
            padding: 14px;
        }
    }

    /* Accessibility Enhancements */
    .add-department {
        animation: fadeIn 0.5s ease-out;
    }

    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
    }
</style>

<?php include '../templates/admin_footer.php'; ?>