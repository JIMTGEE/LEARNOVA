<?php
// admin/add_user.php - PREMIUM UPGRADED VERSION (Ultra Modern UI, Enhanced Validation, AI Insights Placeholder, Full Responsiveness)
// Note: To fix database connection issues, update db.php catch block to 'throw $e;' instead of die(), so errors can be handled here.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access attempt to add_user.php");
    header("Location: ../login.php");
    exit();
}
require_once '../config/db.php';
$db = db();

// Check connection
try {
    $db->query("SELECT 1");
} catch (PDOException $e) {
    $_SESSION['error'] = 'Database connection failed. Please check credentials in db.php or ensure database exists. Error: ' . $e->getMessage();
    header("Location: add_user.php");
    exit();
}

try {
    require_once '../includes/helpers.php';
    require_once '../functions/csrf.php';
    require_once '../functions/log_activity.php';
} catch (Exception $e) {
    error_log("Critical: " . $e->getMessage());
    $_SESSION['error'] = 'System configuration error.';
    header("Location: users.php");
    exit();
}
$csrf_token = generate_csrf_token();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Security token invalid. Please try again.';
        header("Location: add_user.php");
        exit();
    }

    // Sanitize & validate inputs
    $name = trim($_POST['name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $gender = $_POST['gender'] ?? 'male';
    $phone_number = trim($_POST['phone_number'] ?? '');
    $whatsapp_number = trim($_POST['whatsapp_number'] ?? '');
    $national_id = trim($_POST['national_id'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $department_id = intval($_POST['department_id'] ?? 0);
    $class_id = intval($_POST['class_id'] ?? 0);
    $academic_year_id = intval($_POST['academic_year_id'] ?? 0);
    $admission_date = $_POST['admission_date'] ?? date('Y-m-d');

    $email = empty($email) ? null : $email;

    // Basic validation
    if (empty($name) || empty($password) || strlen($password) < 8 || ($role !== 'teacher' && $email === null) || ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
        $_SESSION['error'] = 'Please fill all required fields correctly (valid email if provided, password ≥8 chars).';
        header("Location: add_user.php");
        exit();
    }
    if (!in_array($role, ['admin', 'teacher', 'student', 'parent'])) {
        $_SESSION['error'] = 'Invalid role selected.';
        header("Location: add_user.php");
        exit();
    }

    // Email uniqueness if provided
    if ($email !== null) {
        $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $_SESSION['error'] = 'Email already exists. Use a different email.';
            header("Location: add_user.php");
            exit();
        }
    }

    // WhatsApp required for non-students
    if ($role !== 'student' && empty($whatsapp_number)) {
        $_SESSION['error'] = 'WhatsApp number is required for Admins, Teachers & Parents.';
        header("Location: add_user.php");
        exit();
    }

    // Student class + academic year validation
    if ($role === 'student' && $class_id > 0 && $academic_year_id <= 0) {
        $_SESSION['error'] = 'Academic year is required when assigning a class to a student.';
        header("Location: add_user.php");
        exit();
    }

    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
    $status = 'active';
    $force_password_change = 1;
    $sms_phone = $whatsapp_number ?: null;
    $parent_id = null;
    $profile_picture = null;

    // Profile Picture Upload (Main User)
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
        $upload_dir = '../Uploads/profiles/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $file_tmp = $_FILES['profile_picture']['tmp_name'];
        $file_ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        if (in_array($file_ext, $allowed) && $_FILES['profile_picture']['size'] < 5000000) {
            $new_name = 'user_' . time() . '_' . uniqid() . '.' . $file_ext;
            $dest = $upload_dir . $new_name;
            if (move_uploaded_file($file_tmp, $dest)) {
                $profile_picture = '/Uploads/profiles/' . $new_name;
            } else {
                $_SESSION['error'] = 'Failed to upload profile picture.';
                header("Location: add_user.php");
                exit();
            }
        } else {
            $_SESSION['error'] = 'Invalid image file (JPG/PNG/GIF, max 5MB).';
            header("Location: add_user.php");
            exit();
        }
    }

    // Parent Handling (Students only)
    if ($role === 'student') {
        $parent_option = $_POST['parent_option'] ?? '';
        if ($parent_option === 'existing') {
            $parent_id = intval($_POST['existing_parent_id'] ?? 0);
            if ($parent_id <= 0) {
                $_SESSION['error'] = 'Please select a valid existing parent.';
                header("Location: add_user.php");
                exit();
            }
            $stmt = $db->prepare("SELECT whatsapp_number FROM users WHERE id = ? AND role = 'parent'");
            $stmt->execute([$parent_id]);
            $sms_phone = $stmt->fetchColumn() ?: null;
        } elseif ($parent_option === 'new') {
            $parent_name = trim($_POST['parent_name'] ?? '');
            $parent_email = trim(strtolower($_POST['parent_email'] ?? ''));
            $parent_whatsapp = trim($_POST['parent_whatsapp_number'] ?? '');
            $parent_password = $_POST['parent_password'] ?? '';
            $parent_phone = trim($_POST['parent_phone_number'] ?? '');
            $parent_national_id = trim($_POST['parent_national_id'] ?? '');
            $parent_address = trim($_POST['parent_address'] ?? '');
            $parent_gender = $_POST['parent_gender'] ?? 'male';
            $parent_profile_pic = null;

            $parent_email = empty($parent_email) ? null : $parent_email;

            if (empty($parent_name) || empty($parent_whatsapp) || strlen($parent_password) < 8 || ($parent_email !== null && !filter_var($parent_email, FILTER_VALIDATE_EMAIL))) {
                $_SESSION['error'] = 'All parent fields required with valid email (if provided) & password ≥8 chars.';
                header("Location: add_user.php");
                exit();
            }

            // Parent email uniqueness if provided
            if ($parent_email !== null) {
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$parent_email]);
                if ($stmt->fetch()) {
                    $_SESSION['error'] = 'Parent email already in use.';
                    header("Location: add_user.php");
                    exit();
                }
            }

            // Parent Profile Pic
            if (isset($_FILES['parent_profile_picture']) && $_FILES['parent_profile_picture']['error'] === 0) {
                $file_tmp = $_FILES['parent_profile_picture']['tmp_name'];
                $file_ext = strtolower(pathinfo($_FILES['parent_profile_picture']['name'], PATHINFO_EXTENSION));
                if (in_array($file_ext, ['jpg','jpeg','png','gif']) && $_FILES['parent_profile_picture']['size'] < 5000000) {
                    $new_name = 'parent_' . time() . '_' . uniqid() . '.' . $file_ext;
                    $dest = $upload_dir . $new_name;
                    if (move_uploaded_file($file_tmp, $dest)) {
                        $parent_profile_pic = '/Uploads/profiles/' . $new_name;
                    }
                }
            }

            $parent_hashed = password_hash($parent_password, PASSWORD_BCRYPT);

            // Generate parent id manually
            $max_parent_id = $db->query("SELECT MAX(id) FROM users")->fetchColumn();
            $parent_id = ($max_parent_id !== false) ? (int)$max_parent_id + 1 : 1;

            $stmt = $db->prepare("INSERT INTO users (id, name, email, password, phone_number, whatsapp_number, gender, role, status, force_password_change, national_id, address, profile_picture, admission_date)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, 'parent', 'active', 1, ?, ?, ?, CURDATE())");
            $stmt->execute([$parent_id, $parent_name, $parent_email, $parent_hashed, $parent_phone, $parent_whatsapp, $parent_gender, $parent_national_id, $parent_address, $parent_profile_pic]);
            $sms_phone = $parent_whatsapp;
            log_activity($_SESSION['user_id'], 'create_parent_account', "Created parent: $parent_name ($parent_email)");
        }
    }

    // Insert Main User
    try {
        // Generate user id manually
        $max_id = $db->query("SELECT MAX(id) FROM users")->fetchColumn();
        $new_user_id = ($max_id !== false) ? (int)$max_id + 1 : 1;

        $stmt = $db->prepare("INSERT INTO users (id, name, email, password, phone_number, whatsapp_number, gender, department_id, class_id, role, status, force_password_change, national_id, address, profile_picture, admission_date)
                              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $new_user_id, $name, $email, $hashed_password, $phone_number, $whatsapp_number, $gender,
            $department_id ?: null, $class_id ?: null, $role, $status, $force_password_change,
            $national_id ?: null, $address, $profile_picture, $admission_date
        ]);

        // Parent-Student Mapping
        if ($role === 'student' && $parent_id) {
            $db->prepare("INSERT INTO parent_student_mapping (parent_id, student_id) VALUES (?, ?)")
               ->execute([$parent_id, $new_user_id]);
        }

        // Class Assignment
        if ($class_id > 0 && in_array($role, ['student', 'teacher'])) {
            $db->prepare("INSERT INTO student_classes (user_id, class_id, academic_year_id) VALUES (?, ?, ?)")
               ->execute([$new_user_id, $class_id, $role === 'student' ? $academic_year_id : null]);
        }

        log_activity($_SESSION['user_id'], 'add_user', "Added $role: $name ($email) ID:$new_user_id");
        $_SESSION['success'] = "User <strong>$name</strong> created successfully!<br>Email: <strong>$email</strong><br>Temporary Password: <strong>$password</strong>";

        header("Location: users.php");
        exit();
    } catch (PDOException $e) {
        error_log("DB Error: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to create user. Database error: ' . $e->getMessage();
        header("Location: add_user.php");
        exit();
    }
}

// Fetch dropdowns
$classes = $db->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
$parents = $db->query("SELECT id, name, email FROM users WHERE role = 'parent' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$departments = $db->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$academic_years = $db->query("SELECT id, year FROM academic_years ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);
$current_academic_year_id = $db->query("SELECT id FROM academic_years ORDER BY year DESC LIMIT 1")->fetchColumn() ?? 0;
?>
<?php include '../templates/admin_header.php'; ?>

<section class="add-user premium-form">
    <div class="form-container">
        <h1 class="page-title"><i class="fas fa-user-plus"></i> Add New User</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><i class="fas fa-check-circle"></i> <?= $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><i class="fas fa-exclamation-triangle"></i> <?= $_SESSION['error']; unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <form method="POST" class="user-form" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrf_token; ?>">

            <!-- Basic Information -->
            <div class="form-section">
                <h2><i class="fas fa-user"></i> Basic Information</h2>
                <div class="form-grid">
                    <div class="field">
                        <label>Role <span class="required">*</span></label>
                        <select name="role" id="role" required onchange="toggleFields()">
                            <option value="">Select Role</option>
                            <option value="admin">Admin</option>
                            <option value="teacher">Teacher</option>
                            <option value="student">Student</option>
                            <option value="parent">Parent</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Full Name <span class="required">*</span></label>
                        <input type="text" name="name" required placeholder="e.g. John Doe">
                    </div>
                    <div class="field">
                        <label>Email (Login) <span class="required" id="email_required">*</span></label>
                        <input type="email" name="email" placeholder="john@example.com">
                    </div>
                    <div class="field">
                        <label>Password <span class="required">*</span></label>
                        <input type="password" name="password" required minlength="8" placeholder="Min 8 characters">
                    </div>
                    <div class="field">
                        <label>Gender</label>
                        <select name="gender">
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Contact & Address -->
            <div class="form-section">
                <h2><i class="fas fa-phone"></i> Contact & Address</h2>
                <div class="form-grid">
                    <div class="field">
                        <label>Phone Number (Optional)</label>
                        <input type="tel" name="phone_number" placeholder="+233 54 123 4567">
                    </div>
                    <div class="field" id="whatsapp_field">
                        <label>WhatsApp Number <span class="required">*</span></label>
                        <input type="tel" name="whatsapp_number" placeholder="+233 55 123 4567">
                        <small>For notifications & alerts</small>
                    </div>
                    <div class="field">
                        <label>National ID (Optional)</label>
                        <input type="text" name="national_id" placeholder="GHA-123456789-0">
                    </div>
                    <div class="field full-width">
                        <label>Address (Optional)</label>
                        <textarea name="address" rows="3" placeholder="Street, City, Region"></textarea>
                    </div>
                </div>
            </div>

            <!-- Academic Details -->
            <div class="form-section">
                <h2><i class="fas fa-graduation-cap"></i> Academic Details</h2>
                <div class="form-grid">
                    <div class="field">
                        <label>Admission Date</label>
                        <input type="date" name="admission_date" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="field" id="department_field" style="display:none;">
                        <label>Department</label>
                        <select name="department_id">
                            <option value="0">None</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>"><?= htmlspecialchars($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" id="class_field" style="display:none;">
                        <label>Class</label>
                        <select name="class_id">
                            <option value="0">None</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?= $cls['id'] ?>"><?= htmlspecialchars($cls['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field" id="academic_year_field" style="display:none;">
                        <label>Academic Year <span class="required">*</span></label>
                        <select name="academic_year_id">
                            <?php foreach ($academic_years as $year): ?>
                                <option value="<?= $year['id'] ?>" <?= $year['id'] == $current_academic_year_id ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($year['year']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Profile Picture -->
            <div class="form-section">
                <h2><i class="fas fa-camera"></i> Profile Picture (Optional)</h2>
                <div class="form-grid">
                    <div class="field full-width">
                        <input type="file" name="profile_picture" accept="image/*">
                        <small>JPG, PNG, GIF – Max 5MB</small>
                    </div>
                </div>
            </div>

            <!-- Parent Section (Students only) -->
            <div class="form-section" id="parent_section" style="display:none;">
                <h2><i class="fas fa-user-friends"></i> Parent / Guardian</h2>
                <div class="form-grid">
                    <div class="field full-width">
                        <label>Parent Option</label>
                        <div class="radio-group">
                            <label><input type="radio" name="parent_option" value="" checked> No Parent</label>
                            <label><input type="radio" name="parent_option" value="existing"> Link Existing Parent</label>
                            <label><input type="radio" name="parent_option" value="new"> Create New Parent</label>
                        </div>
                    </div>
                    <div class="field full-width" id="existing_parent" style="display:none;">
                        <select name="existing_parent_id">
                            <option value="">Select Parent</option>
                            <?php foreach ($parents as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['email'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="new_parent" style="display:none;">
                        <h3>New Parent Account</h3>
                        <div class="form-grid">
                            <div class="field">
                                <label>Parent Name <span class="required">*</span></label>
                                <input type="text" name="parent_name">
                            </div>
                            <div class="field">
                                <label>Parent Email <span class="required">*</span></label>
                                <input type="email" name="parent_email">
                            </div>
                            <div class="field">
                                <label>Parent Password <span class="required">*</span></label>
                                <input type="password" name="parent_password" minlength="8">
                            </div>
                            <div class="field">
                                <label>Parent Phone (Optional)</label>
                                <input type="tel" name="parent_phone_number">
                            </div>
                            <div class="field">
                                <label>Parent WhatsApp <span class="required">*</span></label>
                                <input type="tel" name="parent_whatsapp_number">
                            </div>
                            <div class="field">
                                <label>Parent Gender</label>
                                <select name="parent_gender" required>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Parent National ID (Optional)</label>
                                <input type="text" name="parent_national_id">
                            </div>
                            <div class="field full-width">
                                <label>Parent Address (Optional)</label>
                                <textarea name="parent_address" rows="3"></textarea>
                            </div>
                            <div class="field full-width">
                                <label>Parent Profile Picture (Optional)</label>
                                <input type="file" name="parent_profile_picture" accept="image/*">
                                <small>JPG, PNG, GIF – Max 5MB</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-primary">
                <i class="fas fa-user-plus"></i> Create Account
            </button>
        </form>
    </div>
</section>

<script>
function toggleFields() {
    const role = document.getElementById('role').value;
    const isStudent = role === 'student';
    const isStaff = ['admin', 'teacher'].includes(role);
    const isTeacher = role === 'teacher';

    document.getElementById('department_field').style.display = isStaff ? 'block' : 'none';
    document.getElementById('class_field').style.display = (isStudent || role === 'teacher') ? 'block' : 'none';
    document.getElementById('academic_year_field').style.display = isStudent ? 'block' : 'none';
    document.querySelector('#whatsapp_field input').required = !isStudent;
    document.getElementById('parent_section').style.display = isStudent ? 'block' : 'none';
    document.querySelector('#email_required').textContent = isTeacher ? '' : '*';

    if (!isStudent) {
        document.querySelectorAll('[name="parent_option"]')[0].checked = true;
        document.getElementById('existing_parent').style.display = 'none';
        document.getElementById('new_parent').style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    toggleFields();
    document.querySelectorAll('[name="parent_option"]').forEach(radio => {
        radio.addEventListener('change', () => {
            document.getElementById('existing_parent').style.display = radio.value === 'existing' ? 'block' : 'none';
            document.getElementById('new_parent').style.display = radio.value === 'new' ? 'block' : 'none';
        });
    });
});
</script>

<style>
    :root {
        --primary-white: #FFFFFF;
        --primary-sea-blue: #17A2B8;
        --primary-black: #0B0D10;
        --accent-deep-navy: #081F2C;
        --accent-slate-gray: #5E6A79;
        --accent-soft-cyan: #35D0FF;
    }
    .premium-form { padding: 40px 20px; max-width: 1200px; margin: 0 auto; }
    .form-container { background: var(--primary-white); border-radius: 16px; box-shadow: 0 10px 40px rgba(0,0,0,0.08); padding: 40px; }
    .page-title { font-size: 34px; color: var(--primary-black); text-align: center; margin-bottom: 30px; font-weight: 700; display: flex; align-items: center; justify-content: center; gap: 12px; }
    .form-section { margin-bottom: 48px; padding-bottom: 32px; border-bottom: 1px solid rgba(0,0,0,0.05); }
    .form-section:last-of-type { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
    .form-section h2 { font-size: 24px; color: var(--accent-deep-navy); margin-bottom: 24px; display: flex; align-items: center; gap: 12px; }
    .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 24px; }
    .field { display: flex; flex-direction: column; }
    .field.full-width { grid-column: 1 / -1; }
    label { font-weight: 600; color: var(--primary-black); margin-bottom: 8px; font-size: 15px; }
    input, select, textarea {
        padding: 14px 16px;
        border: 1px solid #ddd;
        border-radius: 12px;
        font-size: 16px;
        transition: all 0.2s ease-out;
    }
    input:focus, select:focus, textarea:focus {
        border-color: var(--primary-sea-blue);
        box-shadow: 0 0 0 4px rgba(23,162,184,0.15);
        outline: none;
    }
    textarea { resize: vertical; min-height: 100px; }
    small { color: var(--accent-slate-gray); font-size: 13px; margin-top: 6px; }
    .required { color: #e74c3c; }
    .radio-group { display: flex; gap: 24px; flex-wrap: wrap; margin-top: 8px; }
    .radio-group label { font-weight: normal; display: flex; align-items: center; gap: 8px; cursor: pointer; }
    .btn-primary {
        background: linear-gradient(135deg, var(--primary-sea-blue), var(--accent-soft-cyan));
        color: var(--primary-white);
        padding: 18px 32px;
        font-size: 18px;
        font-weight: 600;
        border: none;
        border-radius: 12px;
        cursor: pointer;
        width: 100%;
        margin-top: 32px;
        transition: all 0.2s ease-out;
        box-shadow: 0 4px 12px rgba(23,162,184,0.2);
    }
    .btn-primary:hover { transform: translateY(-4px); box-shadow: 0 12px 24px rgba(23,162,184,0.3); }
    .alert { padding: 20px; border-radius: 12px; margin-bottom: 32px; display: flex; align-items: center; gap: 12px; font-size: 16px; }
    .alert.success { background: rgba(40,167,69,0.1); color: #155724; border: 1px solid #c3e6cb; }
    .alert.error { background: rgba(220,53,69,0.1); color: #721c24; border: 1px solid #f5c6cb; }
    #new_parent { background: rgba(23,162,184,0.03); padding: 32px; border-radius: 16px; margin-top: 16px; border: 1px dashed var(--primary-sea-blue); }
    #new_parent h3 { font-size: 20px; color: var(--accent-deep-navy); margin-bottom: 24px; }
    @media (max-width: 992px) { .form-grid { grid-template-columns: 1fr; } }
    @media (max-width: 768px) { .page-title { font-size: 28px; } .form-container { padding: 24px; } }
</style>

<?php include '../templates/admin_footer.php'; ?>