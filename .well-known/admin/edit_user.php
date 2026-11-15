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
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    $_SESSION['error'] = 'User not found.';
    header("Location: users.php");
    exit();
}

$role = $user['role'];

// Fetch current class assignment if any
$current_class_id = $user['class_id'];
$current_academic_year_id = 0;
if (in_array($role, ['student', 'teacher']) && $current_class_id > 0) {
    $stmt = $db->prepare("SELECT academic_year_id FROM student_classes WHERE user_id = ? AND class_id = ? LIMIT 1");
    $stmt->execute([$user_id, $current_class_id]);
    $current_academic_year_id = $stmt->fetchColumn() ?? 0;
}

// Fetch current parent if student
$current_parent_id = 0;
if ($role === 'student') {
    $stmt = $db->prepare("SELECT parent_id FROM parent_student_mapping WHERE student_id = ? LIMIT 1");
    $stmt->execute([$user_id]);
    $current_parent_id = $stmt->fetchColumn() ?? 0;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: edit_user.php?id=$user_id");
        exit();
    }

    // Common fields
    $name = trim($_POST['name'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $whatsapp_number = trim($_POST['whatsapp_number'] ?? '');
    $gender = $_POST['gender'] ?? 'male';
    $department_id = intval($_POST['department_id'] ?? 0);
    $class_id = intval($_POST['class_id'] ?? 0);
    $academic_year_id = intval($_POST['academic_year_id'] ?? 0);
    $new_password = $_POST['new_password'] ?? '';
    $status = $_POST['status'] ?? 'active';
    $force_password_change = isset($_POST['force_password_change']) ? 1 : 0;

    if (!$name) {
        $_SESSION['error'] = 'Invalid input data. Please check all fields.';
        header("Location: edit_user.php?id=$user_id");
        exit();
    }

    if ($role !== 'student' && !$whatsapp_number) {
        $_SESSION['error'] = 'WhatsApp number required for non-students.';
        header("Location: edit_user.php?id=$user_id");
        exit();
    }

    if ($role === 'parent' && $department_id > 0) {
        $department_id = 0; // Parents don't need department
    }

    if ($class_id > 0 && $academic_year_id <= 0 && in_array($role, ['student', 'teacher'])) {
        $_SESSION['error'] = 'Academic year required when assigning class.';
        header("Location: edit_user.php?id=$user_id");
        exit();
    }

    $update_fields = "name = ?, phone_number = ?, whatsapp_number = ?, gender = ?, department_id = ?, class_id = ?, status = ?, force_password_change = ?";
    $params = [$name, $phone_number, $whatsapp_number, $gender, $department_id, $class_id, $status, $force_password_change];

    $sms_message = '';
    if ($new_password) {
        if (strlen($new_password) < 8) {
            $_SESSION['error'] = 'New password must be at least 8 characters.';
            header("Location: edit_user.php?id=$user_id");
            exit();
        }
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        $update_fields .= ", password = ?";
        $params[] = $hashed_password;
        $sms_message = "Your EduSync password has been updated. New Password: $new_password. Please change it after login.";
    }

    $params[] = $user_id;

    $stmt = $db->prepare("UPDATE users SET $update_fields WHERE id = ?");
    $stmt->execute($params);

    // Update class assignment
    if (in_array($role, ['student', 'teacher'])) {
        if ($class_id > 0 && $academic_year_id > 0) {
            $stmt = $db->prepare("INSERT INTO student_classes (user_id, class_id, academic_year_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE class_id = ?, academic_year_id = ?");
            $stmt->execute([$user_id, $class_id, $academic_year_id, $class_id, $academic_year_id]);
        } elseif ($class_id == 0) {
            $stmt = $db->prepare("DELETE FROM student_classes WHERE user_id = ?");
            $stmt->execute([$user_id]);
        }
    }

    // Update parent if student
    $parent_id = null;
    if ($role === 'student') {
        $parent_option = $_POST['parent_option'] ?? '';
        if ($parent_option === 'existing') {
            $parent_id = intval($_POST['existing_parent_id'] ?? 0);
        } elseif ($parent_option === 'new') {
            $parent_name = trim($_POST['parent_name'] ?? '');
            $parent_phone_number = trim($_POST['parent_phone_number'] ?? '');
            $parent_whatsapp_number = trim($_POST['parent_whatsapp_number'] ?? '');
            $parent_password = $_POST['parent_password'] ?? '';

            if (!$parent_name || !$parent_whatsapp_number || strlen($parent_password) < 8) {
                $_SESSION['error'] = 'Invalid new parent details.';
                header("Location: edit_user.php?id=$user_id");
                exit();
            }

            $parent_hashed_password = password_hash($parent_password, PASSWORD_BCRYPT);

            // Generate parent username
            $parent_base_username = strtolower(str_replace(' ', '_', $parent_name));
            $parent_username = $parent_base_username;
            $suffix = 1;
            while (true) {
                $check_stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                $check_stmt->execute([$parent_username]);
                if ($check_stmt->fetchColumn() == 0) {
                    break;
                }
                $parent_username = $parent_base_username . '_' . $suffix++;
            }

            $stmt = $db->prepare("INSERT INTO users (username, name, phone_number, whatsapp_number, gender, password, role, status, force_password_change) VALUES (?, ?, ?, ?, ?, ?, 'parent', ?, ?)");
            $stmt->execute([$parent_username, $parent_name, $parent_phone_number, $parent_whatsapp_number, $gender, $parent_hashed_password, $status, $force_password_change]);
            $parent_id = $db->lastInsertId();

            send_sms($parent_whatsapp_number, "Your EduSync parent account has been created. Username: $parent_username, Password: $parent_password. Login at [site_url].");

            log_activity($_SESSION['user_id'], 'add_parent', "Added new parent ID: $parent_id for student ID: $user_id");
        } elseif ($parent_option === 'none') {
            $parent_id = 0;
        } else {
            $parent_id = $current_parent_id;
        }

        // Update mapping
        $stmt = $db->prepare("DELETE FROM parent_student_mapping WHERE student_id = ?");
        $stmt->execute([$user_id]);

        if ($parent_id > 0) {
            $stmt = $db->prepare("INSERT INTO parent_student_mapping (parent_id, student_id) VALUES (?, ?)");
            $stmt->execute([$parent_id, $user_id]);
        }
    }

    // Send SMS if password changed
    $sms_phone = ($role === 'student') ? get_parent_whatsapp($parent_id ?? $current_parent_id) : $whatsapp_number;
    if ($sms_message && $sms_phone) {
        send_sms($sms_phone, $sms_message);
    }

    log_activity($_SESSION['user_id'], 'edit_user', "Edited user ID: $user_id");

    $_SESSION['success'] = 'User updated successfully.';
    header("Location: users.php");
    exit();
}

// Fetch dropdown data
$departments = $db->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$classes = $db->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
$academic_years = $db->query("SELECT id, year FROM academic_years ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
$parents = $db->query("SELECT id, name FROM users WHERE role = 'parent' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Function to send SMS via Arkesel
function send_sms($to, $message) {
    $api_key = 'Your_Key';
    $from = 'EduSync'; // Replace with your sender ID
    $url = "https://sms.arkesel.com/sms/api?action=send-sms&api_key=" . urlencode($api_key) . "&to=" . urlencode($to) . "&from=" . urlencode($from) . "&sms=" . urlencode($message);
    $response = file_get_contents($url);
    // Log response if needed
}

// Function to get parent's whatsapp_number
function get_parent_whatsapp($parent_id) {
    global $db;
    $stmt = $db->prepare("SELECT whatsapp_number FROM users WHERE id = ?");
    $stmt->execute([$parent_id]);
    return $stmt->fetchColumn() ?? '';
}

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

<section class="edit-user">
    <h1 class="page-title">Edit User: <?php echo htmlspecialchars($user['name']); ?></h1>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <form method="POST" class="user-form">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

        <label for="name">Name:</label>
        <input type="text" id="name" name="name" value="<?php echo htmlspecialchars($user['name']); ?>" required>

        <div id="phone_section" style="display: <?php echo $role !== 'student' ? 'block' : 'none'; ?>;">
            <label for="phone_number">Phone Number (optional):</label>
            <input type="text" id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($user['phone_number']); ?>">
        </div>

        <div id="whatsapp_section" style="display: <?php echo $role !== 'student' ? 'block' : 'none'; ?>;">
            <label for="whatsapp_number">WhatsApp Number:</label>
            <input type="text" id="whatsapp_number" name="whatsapp_number" value="<?php echo htmlspecialchars($user['whatsapp_number']); ?>" <?php echo $role !== 'student' ? 'required' : ''; ?>>
        </div>

        <label for="gender">Gender:</label>
        <select id="gender" name="gender">
            <option value="male" <?php echo $user['gender'] === 'male' ? 'selected' : ''; ?>>Male</option>
            <option value="female" <?php echo $user['gender'] === 'female' ? 'selected' : ''; ?>>Female</option>
            <option value="other" <?php echo $user['gender'] === 'other' ? 'selected' : ''; ?>>Other</option>
        </select>

        <div id="department_section" style="display: <?php echo $role !== 'parent' ? 'block' : 'none'; ?>;">
            <label for="department_id">Department:</label>
            <select id="department_id" name="department_id">
                <option value="0">No Department</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?php echo $dept['id']; ?>" <?php echo $dept['id'] == $user['department_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($dept['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div id="class_section" style="display: <?php echo in_array($role, ['student', 'teacher']) ? 'block' : 'none'; ?>;">
            <?php if (!empty($classes)): ?>
                <label for="class_id">Assign Class (optional):</label>
                <select id="class_id" name="class_id">
                    <option value="0">No Class</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>" <?php echo $class['id'] == $current_class_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="academic_year_id">Academic Year (required if class assigned):</label>
                <select id="academic_year_id" name="academic_year_id">
                    <option value="0">None</option>
                    <?php foreach ($academic_years as $year): ?>
                        <option value="<?php echo $year['id']; ?>" <?php echo $year['id'] == $current_academic_year_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($year['year']); ?></option>
                    <?php endforeach; ?>
                </select>
            <?php else: ?>
                <p>No classes available. Create classes first to assign.</p>
            <?php endif; ?>
        </div>

        <div id="parent_section" style="display: <?php echo $role === 'student' ? 'block' : 'none'; ?>;">
            <label>Parent:</label>
            <select name="parent_option" onchange="toggleParentFields(this.value)">
                <option value="keep" <?php echo $current_parent_id > 0 ? 'selected' : ''; ?>>Keep Current (<?php echo $current_parent_id > 0 ? get_parent_name($current_parent_id) : 'None'; ?>)</option>
                <option value="none">No Parent</option>
                <option value="existing">Change to Existing Parent</option>
                <option value="new">Create New Parent</option>
            </select>

            <div id="existing_parent" style="display: none;">
                <label for="existing_parent_id">Select Parent:</label>
                <select id="existing_parent_id" name="existing_parent_id">
                    <?php foreach ($parents as $parent): ?>
                        <option value="<?php echo $parent['id']; ?>" <?php echo $parent['id'] == $current_parent_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($parent['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="new_parent" style="display: none;">
                <label for="parent_name">Parent Name:</label>
                <input type="text" id="parent_name" name="parent_name">

                <label for="parent_phone_number">Parent Phone Number (optional):</label>
                <input type="text" id="parent_phone_number" name="parent_phone_number">

                <label for="parent_whatsapp_number">Parent WhatsApp Number:</label>
                <input type="text" id="parent_whatsapp_number" name="parent_whatsapp_number">

                <label for="parent_password">Parent Password:</label>
                <input type="password" id="parent_password" name="parent_password" minlength="8">
            </div>
        </div>

        <label for="new_password">New Password (leave blank to keep current):</label>
        <input type="password" id="new_password" name="new_password" minlength="8">

        <label for="status">Status:</label>
        <select id="status" name="status">
            <option value="active" <?php echo $user['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="inactive" <?php echo $user['status'] === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
            <option value="suspended" <?php echo $user['status'] === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
            <option value="graduated" <?php echo $user['status'] === 'graduated' ? 'selected' : ''; ?>>Graduated</option>
        </select>

        <label>
            <input type="checkbox" name="force_password_change" <?php echo $user['force_password_change'] ? 'checked' : ''; ?>> Force Password Change on Next Login
        </label>

        <button type="submit">Update User</button>
    </form>
</section>

<script>
    function toggleParentFields(option) {
        document.getElementById('existing_parent').style.display = (option === 'existing') ? 'block' : 'none';
        document.getElementById('new_parent').style.display = (option === 'new') ? 'block' : 'none';
    }
</script>

<style>
    .edit-user {
        max-width: 600px;
        margin: 0 auto;
        padding: 20px;
    }
    .user-form label {
        display: block;
        margin-bottom: 8px;
        font-weight: bold;
    }
    .user-form input, .user-form select {
        width: 100%;
        padding: 10px;
        margin-bottom: 16px;
        border: 1px solid #ccc;
        border-radius: 4px;
    }
    .user-form button {
        background-color: var(--primary-sea-blue);
        color: white;
        padding: 12px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        width: 100%;
    }
    .user-form button:hover {
        background-color: var(--accent-soft-cyan);
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

<?php
function get_parent_name($parent_id) {
    global $db;
    $stmt = $db->prepare("SELECT name FROM users WHERE id = ?");
    $stmt->execute([$parent_id]);
    return $stmt->fetchColumn() ?? 'Unknown';
}
?>
