<?php
// admin/profiles.php - PREMIUM UPGRADED VERSION (Ultra Modern UI, Inline Editing, Secure Uploads, Full Responsiveness)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Unauthorized access to profiles.php");
    header("Location: ../login.php");
    exit();
}
require_once '../config/db.php';
$db = db();
try {
    require_once '../includes/helpers.php';
    require_once '../functions/csrf.php';
    require_once '../functions/log_activity.php';
} catch (Exception $e) {
    error_log("Error loading helpers: " . $e->getMessage());
    $_SESSION['error'] = 'System error.';
    header("Location: users.php");
    exit();
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token.';
        header("Location: profiles.php");
        exit();
    }

    $action = $_POST['action'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);
    if ($user_id <= 0) {
        $_SESSION['error'] = 'Invalid user ID.';
        header("Location: profiles.php");
        exit();
    }

    switch ($action) {
        case 'update_profile':
            $name = trim($_POST['name'] ?? '');
            $phone = trim($_POST['phone_number'] ?? '');
            $whatsapp = trim($_POST['whatsapp_number'] ?? '');
            $gender = $_POST['gender'] ?? 'male';
            $department_id = intval($_POST['department_id'] ?? 0);
            $class_id = intval($_POST['class_id'] ?? 0);
            $academic_year_id = intval($_POST['academic_year_id'] ?? 0);

            if (empty($name)) {
                $_SESSION['error'] = 'Name is required.';
                break;
            }

            $stmt = $db->prepare("UPDATE users SET name = ?, phone_number = ?, whatsapp_number = ?, gender = ?, department_id = ?, class_id = ? WHERE id = ?");
            $stmt->execute([$name, $phone, $whatsapp, $gender, $department_id ?: null, $class_id ?: null, $user_id]);

            if ($class_id > 0) {
                $stmt = $db->prepare("INSERT INTO student_classes (user_id, class_id, academic_year_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE class_id = VALUES(class_id), academic_year_id = VALUES(academic_year_id)");
                $stmt->execute([$user_id, $class_id, $academic_year_id ?: null]);
            }

            log_activity($_SESSION['user_id'], 'update_profile', "Updated profile for user ID: $user_id");
            $_SESSION['success'] = 'Profile updated successfully.';
            break;

        case 'upload_picture':
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
                $upload_dir = '../Uploads/profiles/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                $file_tmp = $_FILES['profile_picture']['tmp_name'];
                $file_ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif'];

                if (in_array($file_ext, $allowed) && $_FILES['profile_picture']['size'] <= 5000000) {
                    $new_name = $user_id . '_' . time() . '_' . uniqid() . '.' . $file_ext;
                    $dest = $upload_dir . $new_name;

                    if (move_uploaded_file($file_tmp, $dest)) {
                        $path = '/Uploads/profiles/' . $new_name;
                        $stmt = $db->prepare("UPDATE users SET profile_picture = ? WHERE id = ?");
                        $stmt->execute([$path, $user_id]);

                        log_activity($_SESSION['user_id'], 'upload_picture', "Uploaded picture for user ID: $user_id");
                        $_SESSION['success'] = 'Profile picture updated.';
                    } else {
                        $_SESSION['error'] = 'Upload failed.';
                    }
                } else {
                    $_SESSION['error'] = 'Invalid file (JPG/PNG/GIF, max 5MB).';
                }
            } else {
                $_SESSION['error'] = 'No file selected.';
            }
            break;
    }
    header("Location: profiles.php?" . http_build_query($_GET));
    exit();
}

// Search & Filters
$search = isset($_GET['search']) ? '%' . trim($_GET['search']) . '%' : '%';
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

$query = "SELECT u.id, u.name, u.phone_number, u.whatsapp_number, u.gender, u.profile_picture, u.role, u.status,
                 d.name AS department_name, c.class_name, ay.year AS academic_year
          FROM users u
          LEFT JOIN departments d ON u.department_id = d.id
          LEFT JOIN classes c ON u.class_id = c.id
          LEFT JOIN student_classes sc ON u.id = sc.user_id
          LEFT JOIN academic_years ay ON sc.academic_year_id = ay.id
          WHERE (u.name LIKE ? OR u.phone_number LIKE ? OR u.whatsapp_number LIKE ?)";
$params = [$search, $search, $search];

if ($role_filter && in_array($role_filter, ['admin','teacher','student','parent'])) {
    $query .= " AND u.role = ?";
    $params[] = $role_filter;
}
if ($status_filter && in_array($status_filter, ['active','inactive','suspended','graduated'])) {
    $query .= " AND u.status = ?";
    $params[] = $status_filter;
}

$query .= " GROUP BY u.id ORDER BY u.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pagination count
$count_query = "SELECT COUNT(DISTINCT u.id) FROM users u WHERE (u.name LIKE ? OR u.phone_number LIKE ? OR u.whatsapp_number LIKE ?)";
$count_params = [$search, $search, $search];
if ($role_filter) {
    $count_query .= " AND u.role = ?";
    $count_params[] = $role_filter;
}
if ($status_filter) {
    $count_query .= " AND u.status = ?";
    $count_params[] = $status_filter;
}
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($count_params);
$total = $count_stmt->fetchColumn();
$total_pages = ceil($total / $limit);

// Dropdowns
$departments = $db->query("SELECT id, name FROM departments ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$classes = $db->query("SELECT id, class_name FROM classes ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
$academic_years = $db->query("SELECT id, year FROM academic_years ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);

$csrf_token = generate_csrf_token();
?>
<?php include '../templates/admin_header.php'; ?>

<section class="profiles-management">
    <h1 class="page-title"><i class="fas fa-user-edit"></i> Profile Management</h1>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
    <?php endif; ?>

    <!-- Search & Filters -->
    <form class="search-form" method="GET">
        <input type="text" name="search" placeholder="Search name / phone / whatsapp..." value="<?= htmlspecialchars($_GET['search'] ?? '') ?>">
        <select name="role">
            <option value="">All Roles</option>
            <option value="admin" <?= $role_filter === 'admin' ? 'selected' : '' ?>>Admin</option>
            <option value="teacher" <?= $role_filter === 'teacher' ? 'selected' : '' ?>>Teacher</option>
            <option value="student" <?= $role_filter === 'student' ? 'selected' : '' ?>>Student</option>
            <option value="parent" <?= $role_filter === 'parent' ? 'selected' : '' ?>>Parent</option>
        </select>
        <select name="status">
            <option value="">All Statuses</option>
            <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="suspended" <?= $status_filter === 'suspended' ? 'selected' : '' ?>>Suspended</option>
            <option value="graduated" <?= $status_filter === 'graduated' ? 'selected' : '' ?>>Graduated</option>
        </select>
        <button type="submit"><i class="fas fa-search"></i> Filter</button>
    </form>

    <!-- Profiles Grid / Table -->
    <div class="profiles-grid">
        <?php foreach ($users as $user): ?>
            <div class="profile-card">
                <div class="profile-header">
                    <div class="picture-wrapper">
                        <img src="<?= htmlspecialchars($user['profile_picture'] ?? '/assets/images/default-profile.png') ?>" alt="Profile" class="profile-img">
                        <form method="POST" enctype="multipart/form-data" class="upload-overlay">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <input type="hidden" name="action" value="upload_picture">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <label class="upload-label"><i class="fas fa-camera"></i><input type="file" name="profile_picture" accept="image/*"></label>
                        </form>
                    </div>
                    <div class="info">
                        <h3><?= htmlspecialchars($user['name']) ?></h3>
                        <span class="role-badge <?= htmlspecialchars($user['role']) ?>"><?= ucfirst($user['role']) ?></span>
                        <span class="status-badge <?= htmlspecialchars($user['status']) ?>"><?= ucfirst($user['status']) ?></span>
                    </div>
                </div>

                <div class="profile-details">
                    <p><strong>Phone:</strong> <?= htmlspecialchars($user['phone_number'] ?: '—') ?></p>
                    <p><strong>WhatsApp:</strong> <?= htmlspecialchars($user['whatsapp_number'] ?: '—') ?></p>
                    <p><strong>Gender:</strong> <?= ucfirst(htmlspecialchars($user['gender'])) ?></p>
                    <p><strong>Department:</strong> <?= htmlspecialchars($user['department_name'] ?? '—') ?></p>
                    <p><strong>Class:</strong> <?= htmlspecialchars($user['class_name'] ?? '—') ?></p>
                    <p><strong>Academic Year:</strong> <?= htmlspecialchars($user['academic_year'] ?? '—') ?></p>
                </div>

                <button class="edit-btn" onclick="toggleEdit(<?= $user['id'] ?>)"><i class="fas fa-edit"></i> Edit Profile</button>

                <div class="edit-section" id="edit-<?= $user['id'] ?>">
                    <form method="POST" class="edit-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">

                        <label>Name</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required>

                        <label>Phone</label>
                        <input type="tel" name="phone_number" value="<?= htmlspecialchars($user['phone_number']) ?>">

                        <label>WhatsApp</label>
                        <input type="tel" name="whatsapp_number" value="<?= htmlspecialchars($user['whatsapp_number']) ?>">

                        <label>Gender</label>
                        <select name="gender">
                            <option value="male" <?= $user['gender'] === 'male' ? 'selected' : '' ?>>Male</option>
                            <option value="female" <?= $user['gender'] === 'female' ? 'selected' : '' ?>>Female</option>
                            <option value="other" <?= $user['gender'] === 'other' ? 'selected' : '' ?>>Other</option>
                        </select>

                        <?php if (in_array($user['role'], ['admin','teacher'])): ?>
                            <label>Department</label>
                            <select name="department_id">
                                <option value="0">None</option>
                                <?php foreach ($departments as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $d['id'] == $user['department_id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>

                        <?php if (in_array($user['role'], ['student','teacher'])): ?>
                            <label>Class</label>
                            <select name="class_id">
                                <option value="0">None</option>
                                <?php foreach ($classes as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= $c['id'] == $user['class_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <?php if ($user['role'] === 'student'): ?>
                                <label>Academic Year</label>
                                <select name="academic_year_id">
                                    <option value="0">None</option>
                                    <?php foreach ($academic_years as $y): ?>
                                        <option value="<?= $y['id'] ?>" <?= $y['year'] == $user['academic_year'] ? 'selected' : '' ?>><?= htmlspecialchars($y['year']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        <?php endif; ?>

                        <button type="submit" class="btn-primary">Save Changes</button>
                        <button type="button" onclick="toggleEdit(<?= $user['id'] ?>)">Cancel</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page-1 ?>&search=<?= urlencode($_GET['search'] ?? '') ?>&role=<?= urlencode($role_filter) ?>&status=<?= urlencode($status_filter) ?>">&laquo; Previous</a>
            <?php endif; ?>
            <span>Page <?= $page ?> of <?= $total_pages ?></span>
            <?php if ($page < $total_pages): ?>
                <a href="?page=<?= $page+1 ?>&search=<?= urlencode($_GET['search'] ?? '') ?>&role=<?= urlencode($role_filter) ?>&status=<?= urlencode($status_filter) ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<script>
function toggleEdit(id) {
    const section = document.getElementById('edit-' + id);
    section.style.display = section.style.display === 'block' ? 'none' : 'block';
}
// Auto-submit picture upload
document.querySelectorAll('.upload-overlay input[type=file]').forEach(input => {
    input.addEventListener('change', () => input.closest('form').submit());
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
    .profiles-management { padding: 40px 20px; max-width: 1600px; margin: 0 auto; }
    .page-title { font-size: 34px; color: var(--primary-black); text-align: center; margin-bottom: 32px; display: flex; align-items: center; justify-content: center; gap: 12px; font-weight: 700; }
    .search-form { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 32px; justify-content: center; }
    .search-form input, .search-form select { padding: 14px; border: 1px solid #ddd; border-radius: 12px; min-width: 200px; }
    .search-form button { background: var(--primary-sea-blue); color: white; padding: 14px 28px; border: none; border-radius: 12px; cursor: pointer; }
    .profiles-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; }
    .profile-card { background: var(--primary-white); border-radius: 16px; box-shadow: 0 8px 24px rgba(0,0,0,0.08); overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; }
    .profile-card:hover { transform: translateY(-8px); box-shadow: 0 16px 32px rgba(0,0,0,0.12); }
    .profile-header { display: flex; align-items: center; padding: 24px; background: linear-gradient(135deg, var(--primary-sea-blue), var(--accent-soft-cyan)); color: white; }
    .picture-wrapper { position: relative; margin-right: 20px; }
    .profile-img { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 4px solid white; }
    .upload-overlay { position: absolute; inset: 0; background: rgba(0,0,0,0.4); border-radius: 50%; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.2s; cursor: pointer; }
    .picture-wrapper:hover .upload-overlay { opacity: 1; }
    .upload-label { color: white; font-size: 24px; cursor: pointer; }
    .upload-label input { display: none; }
    .info h3 { margin: 0; font-size: 20px; }
    .role-badge, .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; margin-top: 8px; display: inline-block; }
    .role-badge.admin { background: var(--accent-deep-navy); }
    .role-badge.teacher { background: var(--primary-sea-blue); }
    .role-badge.student { background: var(--accent-soft-cyan); color: var(--primary-black); }
    .role-badge.parent { background: var(--accent-slate-gray); }
    .status-badge.active { background: #28a745; }
    .status-badge.inactive { background: #ffc107; color: black; }
    .status-badge.suspended { background: #dc3545; }
    .status-badge.graduated { background: #6f42c1; }
    .profile-details { padding: 20px; line-height: 1.8; color: var(--accent-slate-gray); }
    .edit-btn { width: 100%; background: var(--primary-sea-blue); color: white; border: none; padding: 14px; font-size: 16px; cursor: pointer; transition: background 0.2s; }
    .edit-btn:hover { background: var(--accent-soft-cyan); }
    .edit-section { padding: 24px; background: rgba(23,162,184,0.05); display: none; }
    .edit-form label { display: block; margin: 12px 0 6px; font-weight: 600; color: var(--primary-black); }
    .edit-form input, .edit-form select { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 10px; margin-bottom: 16px; }
    .edit-form .btn-primary { background: var(--primary-sea-blue); color: white; padding: 12px; border: none; border-radius: 10px; cursor: pointer; margin-right: 12px; }
    .alert { padding: 20px; border-radius: 12px; margin-bottom: 32px; display: flex; align-items: center; gap: 12px; font-size: 16px; }
    .alert.success { background: rgba(40,167,69,0.1); color: #155724; border: 1px solid #c3e6cb; }
    .alert.error { background: rgba(220,53,69,0.1); color: #721c24; border: 1px solid #f5c6cb; }
    .pagination { text-align: center; margin: 40px 0; }
    .pagination a { padding: 10px 20px; background: var(--primary-sea-blue); color: white; margin: 0 8px; border-radius: 8px; text-decoration: none; }
    @media (max-width: 768px) { .profiles-grid { grid-template-columns: 1fr; } .search-form { flex-direction: column; } }
</style>

<?php include '../templates/admin_footer.php'; ?>