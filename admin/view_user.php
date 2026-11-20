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
    header("Location: users.php");
    exit();
}

$user_id = intval($_GET['id'] ?? 0);
if ($user_id <= 0) {
    $_SESSION['error'] = 'Invalid user ID.';
    header("Location: users.php");
    exit();
}

// Fetch user details
$stmt = $db->prepare("SELECT u.*, d.name AS department_name, c.class_name, ay.year AS academic_year 
                      FROM users u 
                      LEFT JOIN departments d ON u.department_id = d.id 
                      LEFT JOIN classes c ON u.class_id = c.id 
                      LEFT JOIN academic_years ay ON (SELECT academic_year_id FROM student_classes WHERE user_id = u.id LIMIT 1) = ay.id 
                      WHERE u.id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    $_SESSION['error'] = 'User not found.';
    header("Location: users.php");
    exit();
}

$role = $user['role'];

// Fetch parent if student
$parent = null;
if ($role === 'student') {
    $stmt = $db->prepare("SELECT p.* FROM users p JOIN parent_student_mapping m ON p.id = m.parent_id WHERE m.student_id = ?");
    $stmt->execute([$user_id]);
    $parent = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Generate CSRF token (not needed for view, but if adding forms later)
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

<section class="view-user">
    <h1 class="page-title">View User: <?php echo htmlspecialchars($user['name']); ?></h1>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <div class="user-details">
        <div class="profile-picture">
            <img src="<?php echo htmlspecialchars($user['profile_picture'] ?? '/assets/images/default-profile.png'); ?>" alt="Profile Picture">
        </div>

        <p><strong>Username:</strong> <?php echo htmlspecialchars($user['username'] ?? 'N/A'); ?></p>
        <p><strong>Name:</strong> <?php echo htmlspecialchars($user['name']); ?></p>
        <p><strong>Role:</strong> <?php echo ucfirst(htmlspecialchars($user['role'])); ?></p>
        <p><strong>Status:</strong> <?php echo ucfirst(htmlspecialchars($user['status'])); ?></p>
        <p><strong>Phone Number:</strong> <?php echo htmlspecialchars($user['phone_number'] ?? 'N/A'); ?></p>
        <p><strong>WhatsApp Number:</strong> <?php echo htmlspecialchars($user['whatsapp_number'] ?? 'N/A'); ?></p>
        <p><strong>Gender:</strong> <?php echo ucfirst(htmlspecialchars($user['gender'])); ?></p>
        <p><strong>Department:</strong> <?php echo htmlspecialchars($user['department_name'] ?? 'None'); ?></p>
        <p><strong>Class:</strong> <?php echo htmlspecialchars($user['class_name'] ?? 'None'); ?></p>
        <p><strong>Academic Year:</strong> <?php echo htmlspecialchars($user['academic_year'] ?? 'None'); ?></p>
        <p><strong>Last Login:</strong> <?php echo htmlspecialchars($user['last_login'] ?? 'Never'); ?></p>
        <p><strong>Created At:</strong> <?php echo htmlspecialchars($user['created_at']); ?></p>
        <p><strong>Updated At:</strong> <?php echo htmlspecialchars($user['updated_at'] ?? 'N/A'); ?></p>

        <?php if ($role === 'student' && $parent): ?>
            <h2>Parent Details</h2>
            <p><strong>Name:</strong> <?php echo htmlspecialchars($parent['name']); ?></p>
            <p><strong>Phone Number:</strong> <?php echo htmlspecialchars($parent['phone_number'] ?? 'N/A'); ?></p>
            <p><strong>WhatsApp Number:</strong> <?php echo htmlspecialchars($parent['whatsapp_number'] ?? 'N/A'); ?></p>
        <?php endif; ?>

        <div class="actions">
            <a href="edit_user.php?id=<?php echo $user_id; ?>" class="action-button"><i class="fas fa-edit"></i> Edit</a>
            <a href="users.php" class="action-button"><i class="fas fa-arrow-left"></i> Back to Users</a>
        </div>
    </div>
</section>

<style>
    .view-user {
        max-width: 800px;
        margin: 0 auto;
        padding: 20px;
    }
    .user-details {
        background: #f9f9f9;
        padding: 20px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .profile-picture img {
        width: 150px;
        height: 150px;
        border-radius: 50%;
        display: block;
        margin: 0 auto 20px;
    }
    .user-details p {
        margin: 10px 0;
        font-size: 16px;
    }
    .user-details strong {
        display: inline-block;
        width: 150px;
    }
    .actions {
        margin-top: 20px;
        text-align: center;
    }
    .action-button {
        background: var(--primary-sea-blue);
        color: white;
        padding: 10px 20px;
        border-radius: 4px;
        text-decoration: none;
        margin: 0 10px;
    }
    .action-button:hover {
        opacity: 0.9;
    }
    .alert {
        padding: 10px;
        margin-bottom: 20px;
        border-radius: 4px;
    }
    .alert.success { background: #d4edda; color: #155724; }
    .alert.error { background: #f8d7da; color: #721c24; }
</style>

<?php include '../templates/admin_footer.php'; ?>