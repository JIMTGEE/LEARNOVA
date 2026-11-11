<?php
// Start output buffering
ob_start();

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once __DIR__ . '/../config/db.php';

try {
    // Get database connection
    $db = db();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    // Check and create permissions column if it doesn't exist
    $check = $db->query("SHOW COLUMNS FROM users LIKE 'permissions'")->fetch();
    if (!$check) {
        $db->exec("ALTER TABLE users ADD COLUMN permissions TEXT DEFAULT NULL AFTER role");
    }
    
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// Define available permissions
$permissions_list = [
    'Admin Permissions' => [
        'manage_users' => ['label' => 'Manage Users', 'desc' => 'Add, edit, delete users and manage profiles'],
        'manage_roles' => ['label' => 'Manage Roles', 'desc' => 'Configure roles and assign permissions'],
        'manage_classes' => ['label' => 'Manage Classes', 'desc' => 'Create and manage classes, subjects, schedules'],
        'manage_attendance' => ['label' => 'Manage Attendance', 'desc' => 'Track and manage student attendance'],
        'manage_fees' => ['label' => 'Manage Finance', 'desc' => 'Handle fees, payments, and financial records'],
        'manage_reports' => ['label' => 'Manage Reports', 'desc' => 'Generate and view system reports'],
        'manage_notifications' => ['label' => 'Manage Communications', 'desc' => 'Send notifications and announcements'],
        'manage_calendar' => ['label' => 'Manage Calendar', 'desc' => 'Manage events, holidays, and schedules'],
        'manage_assignments' => ['label' => 'Manage Assignments', 'desc' => 'Create and grade assignments, tests'],
        'manage_system' => ['label' => 'System Maintenance', 'desc' => 'Backup, restore, and system settings'],
    ],
    'Teacher Permissions' => [
        'manage_lessons' => ['label' => 'Manage Lessons', 'desc' => 'Create and manage lesson plans'],
        'take_attendance' => ['label' => 'Take Attendance', 'desc' => 'Mark student attendance for classes'],
        'create_assignments' => ['label' => 'Create Assignments', 'desc' => 'Create and publish assignments'],
        'grade_submissions' => ['label' => 'Grade Work', 'desc' => 'Grade student assignments and tests'],
        'view_student_progress' => ['label' => 'View Progress', 'desc' => 'View student academic progress'],
        'communicate_parents' => ['label' => 'Communicate', 'desc' => 'Communicate with students and parents'],
    ],
    'Student Permissions' => [
        'view_courses' => ['label' => 'View Courses', 'desc' => 'Access course materials and resources'],
        'submit_work' => ['label' => 'Submit Work', 'desc' => 'Submit assignments and projects'],
        'view_grades' => ['label' => 'View Grades', 'desc' => 'View grades and feedback'],
        'view_schedule' => ['label' => 'View Schedule', 'desc' => 'Access class schedule and calendar'],
        'access_resources' => ['label' => 'Access Resources', 'desc' => 'Access learning materials'],
        'edit_profile' => ['label' => 'Edit Profile', 'desc' => 'Update personal information'],
    ],
    'Parent Permissions' => [
        'view_child_progress' => ['label' => 'View Child Progress', 'desc' => 'Monitor child academic progress'],
        'view_attendance' => ['label' => 'View Attendance', 'desc' => 'Check child attendance records'],
        'view_grades' => ['label' => 'View Grades', 'desc' => 'View child grades and reports'],
        'pay_fees' => ['label' => 'Pay Fees', 'desc' => 'View and pay school fees'],
        'receive_notifications' => ['label' => 'Receive Notifications', 'desc' => 'Get updates about child'],
        'communicate_teachers' => ['label' => 'Communicate', 'desc' => 'Message teachers and staff'],
    ],
    'Sub-Admin Permissions' => [
        'view_users' => ['label' => 'View Users', 'desc' => 'View all users and profiles'],
        'manage_limited_users' => ['label' => 'Manage Limited Users', 'desc' => 'Manage non-admin users'],
        'generate_reports' => ['label' => 'Generate Reports', 'desc' => 'Generate and export reports'],
        'manage_content' => ['label' => 'Manage Content', 'desc' => 'Manage website content'],
        'view_analytics' => ['label' => 'View Analytics', 'desc' => 'Access system analytics'],
    ],
];

// Default permissions per role
$default_permissions = [
    'admin' => array_keys(array_merge(
        $permissions_list['Admin Permissions'],
        $permissions_list['Teacher Permissions'],
        $permissions_list['Student Permissions'],
        $permissions_list['Parent Permissions'],
        $permissions_list['Sub-Admin Permissions']
    )),
    'subadmin' => array_keys(array_merge(
        $permissions_list['Sub-Admin Permissions'],
        $permissions_list['Teacher Permissions']
    )),
    'teacher' => array_keys($permissions_list['Teacher Permissions']),
    'student' => array_keys($permissions_list['Student Permissions']),
    'parent' => array_keys($permissions_list['Parent Permissions']),
];

// CSRF functions
function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: roles.php");
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'update_role_permissions':
            $user_id = intval($_POST['user_id'] ?? 0);
            $new_role = $_POST['role'] ?? '';
            
            // Validate
            if ($user_id <= 0) {
                $_SESSION['error'] = 'Invalid user selected.';
                break;
            }
            
            if (!in_array($new_role, ['admin', 'subadmin', 'teacher', 'student', 'parent'])) {
                $_SESSION['error'] = 'Invalid role selected.';
                break;
            }
            
            // Prevent self-modification
            if ($user_id === $_SESSION['user_id']) {
                $_SESSION['error'] = 'You cannot modify your own role/permissions.';
                break;
            }
            
            // Get selected permissions
            $selected_permissions = [];
            if (isset($_POST['permissions']) && is_array($_POST['permissions'])) {
                $selected_permissions = $_POST['permissions'];
            }
            
            // If no permissions selected, use defaults
            if (empty($selected_permissions) && isset($default_permissions[$new_role])) {
                $selected_permissions = $default_permissions[$new_role];
            }
            
            // Update database
            try {
                $stmt = $db->prepare("UPDATE users SET role = ?, permissions = ? WHERE id = ?");
                $stmt->execute([$new_role, json_encode($selected_permissions), $user_id]);
                
                $_SESSION['success'] = 'Role and permissions updated successfully.';
            } catch (PDOException $e) {
                $_SESSION['error'] = 'Database error occurred. Please try again.';
            }
            break;
    }
    
    header("Location: roles.php");
    exit();
}

// Fetch users with pagination and filters
$search = isset($_GET['search']) ? '%' . trim($_GET['search']) . '%' : '%';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// Build WHERE conditions
$where_conditions = [];
$where_params = [];

// Add search condition
$where_conditions[] = "(name LIKE ? OR email LIKE ? OR phone_number LIKE ?)";
$where_params = array_merge($where_params, [$search, $search, $search]);

// Add role filter if set
if ($role_filter && in_array($role_filter, ['admin', 'subadmin', 'teacher', 'student', 'parent'])) {
    $where_conditions[] = "role = ?";
    $where_params[] = $role_filter;
}

// Add status filter if set
if ($status_filter && in_array($status_filter, ['active', 'inactive', 'suspended'])) {
    $where_conditions[] = "status = ?";
    $where_params[] = $status_filter;
}

// Build the WHERE clause
$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Count total users with filters
try {
    $count_query = "SELECT COUNT(*) as total FROM users $where_clause";
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute($where_params);
    $total_result = $count_stmt->fetch();
    $total_users = $total_result['total'] ?? 0;
    $total_pages = ceil($total_users / $limit);
} catch (PDOException $e) {
    $total_users = 0;
    $total_pages = 1;
}

// Get paginated users
try {
    $query = "SELECT id, name, email, role, created_at, status, profile_picture, 
                     phone_number, department, gender, last_login, permissions 
              FROM users $where_clause 
              ORDER BY role, name ASC 
              LIMIT ? OFFSET ?";
    
    $query_params = array_merge($where_params, [$limit, $offset]);
    
    $stmt = $db->prepare($query);
    $stmt->execute($query_params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Parse permissions
    foreach ($users as &$user) {
        $user['permissions'] = json_decode($user['permissions'] ?? '[]', true) ?: [];
        $user['permission_count'] = count($user['permissions']);
    }
    unset($user); // Break reference
    
} catch (PDOException $e) {
    $users = [];
    $_SESSION['error'] = 'Error loading users. Please try again.';
}

// Get role statistics
$role_stats = [];
$role_colors = [
    'admin' => '#dc3545',
    'subadmin' => '#17a2b8',
    'teacher' => '#28a745',
    'student' => '#ffc107',
    'parent' => '#6f42c1'
];

foreach (['admin', 'subadmin', 'teacher', 'student', 'parent'] as $role) {
    try {
        $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
        $stmt->execute([$role]);
        $role_stats[$role] = $stmt->fetchColumn();
    } catch (PDOException $e) {
        $role_stats[$role] = 0;
    }
}

// Check if we're in edit mode
$edit_mode = isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['id']);
$edit_user = null;

if ($edit_mode) {
    $edit_id = intval($_GET['id']);
    try {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$edit_id]);
        $edit_user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($edit_user) {
            $edit_user['permissions'] = json_decode($edit_user['permissions'] ?? '[]', true) ?: [];
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Error loading user data.';
        $edit_user = null;
    }
}

// Generate CSRF token
$csrf_token = generate_csrf_token();

// Clear output buffer
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Role Management - EduSync</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: #333;
        }
        
        .roles-management {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .page-header {
            margin-bottom: 30px;
            padding: 20px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .page-title {
            font-size: 24px;
            color: #2c3e50;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .page-subtitle {
            color: #7f8c8d;
            font-size: 14px;
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: 6px;
            margin: 15px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-info {
            background-color: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            border-left: 4px solid;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .stat-icon {
            font-size: 24px;
            color: #7f8c8d;
        }
        
        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #2c3e50;
        }
        
        .stat-label {
            font-size: 12px;
            color: #7f8c8d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .content-grid {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 20px;
        }
        
        @media (max-width: 1200px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .users-list-section {
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .section-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .section-header h3 {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #2c3e50;
        }
        
        .header-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .search-box {
            display: flex;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .search-box input {
            border: none;
            padding: 8px 12px;
            width: 200px;
        }
        
        .search-box button {
            background: #f8f9fa;
            border: none;
            padding: 8px 12px;
            cursor: pointer;
            color: #666;
        }
        
        .filter-btn {
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 8px 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th {
            background: #f8f9fa;
            padding: 12px 15px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            font-size: 13px;
        }
        
        .data-table td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
        }
        
        .data-table tr:hover {
            background: #f8f9fa;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
            background: #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .user-name {
            font-weight: 600;
        }
        
        .user-email {
            font-size: 12px;
            color: #666;
        }
        
        .role-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: white;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-active {
            background: #d4edda;
            color: #155724;
        }
        
        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-suspended {
            background: #fff3cd;
            color: #856404;
        }
        
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        
        .btn-action {
            width: 32px;
            height: 32px;
            border-radius: 5px;
            border: 1px solid #ddd;
            background: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #333;
        }
        
        .btn-action:hover {
            background: #f8f9fa;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
        }
        
        .empty-state i {
            font-size: 48px;
            color: #ddd;
            margin-bottom: 10px;
        }
        
        .table-footer {
            padding: 15px 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .pagination {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .page-link {
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            text-decoration: none;
            color: #007bff;
        }
        
        .page-link:hover {
            background: #f8f9fa;
        }
        
        .page-link.current {
            background: #007bff;
            color: white;
            border-color: #007bff;
        }
        
        .info-panel {
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .info-panel h3 {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .instructions {
            list-style: none;
            margin-bottom: 20px;
        }
        
        .instructions li {
            padding: 8px 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .form-group select,
        .form-group input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }
        
        .btn-primary {
            background: #007bff;
            color: white;
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-danger {
            background: #dc3545;
            color: white;
        }
        
        .permissions-container {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 5px;
            padding: 15px;
        }
        
        .permission-category {
            margin-bottom: 15px;
        }
        
        .permission-category h5 {
            margin-bottom: 10px;
            color: #495057;
            font-size: 14px;
            padding-bottom: 5px;
            border-bottom: 1px solid #eee;
        }
        
        .permission-item {
            display: flex;
            align-items: flex-start;
            margin-bottom: 8px;
            padding: 5px;
            border-radius: 4px;
        }
        
        .permission-item:hover {
            background: #f8f9fa;
        }
        
        .permission-item label {
            flex: 1;
            cursor: pointer;
            margin-left: 8px;
        }
        
        .permission-title {
            font-weight: 500;
            font-size: 13px;
            display: block;
        }
        
        .permission-desc {
            font-size: 11px;
            color: #666;
            display: block;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-content {
            background: white;
            border-radius: 10px;
            width: 400px;
            max-width: 90%;
            padding: 20px;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .modal-footer {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .close-modal {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #666;
        }
        
        .select-all-btn {
            background: none;
            border: none;
            color: #007bff;
            cursor: pointer;
            font-size: 12px;
            padding: 5px;
        }
        
        .select-all-btn:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <section class="roles-management">
        <!-- Header Section -->
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-user-shield"></i> Role & Permissions Management
            </h1>
            <p class="page-subtitle">Manage user roles, permissions, and access controls</p>
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success']); ?>
                    <?php unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error']); ?>
                    <?php unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            
            <?php if (empty($users) && !empty($_GET['search'])): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> No users found matching your search criteria.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Quick Stats -->
        <div class="quick-stats">
            <?php foreach ($role_stats as $role => $count): ?>
                <div class="stat-card" style="border-left-color: <?php echo $role_colors[$role]; ?>">
                    <div class="stat-icon">
                        <?php switch($role): 
                            case 'admin': ?>
                                <i class="fas fa-user-shield"></i>
                                <?php break; 
                            case 'subadmin': ?>
                                <i class="fas fa-user-cog"></i>
                                <?php break; 
                            case 'teacher': ?>
                                <i class="fas fa-chalkboard-teacher"></i>
                                <?php break; 
                            case 'student': ?>
                                <i class="fas fa-graduation-cap"></i>
                                <?php break; 
                            case 'parent': ?>
                                <i class="fas fa-user-friends"></i>
                                <?php break; 
                        endswitch; ?>
                    </div>
                    <div class="stat-content">
                        <div class="stat-number"><?php echo $count; ?></div>
                        <div class="stat-label"><?php echo ucfirst($role) . 's'; ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Main Content Area -->
        <div class="content-grid">
            <!-- Left Column: Users List -->
            <div class="users-list-section">
                <div class="section-header">
                    <h3><i class="fas fa-users"></i> Users (<?php echo $total_users; ?>)</h3>
                    <div class="header-actions">
                        <form method="GET" class="search-box">
                            <input type="text" name="search" placeholder="Search users..." 
                                   value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                            <button type="submit"><i class="fas fa-search"></i></button>
                        </form>
                        
                        <select name="role" onchange="applyFilter(this)" style="padding: 8px; border-radius: 5px; border: 1px solid #ddd;">
                            <option value="">All Roles</option>
                            <?php foreach (['admin', 'subadmin', 'teacher', 'student', 'parent'] as $role): ?>
                                <option value="<?php echo $role; ?>" 
                                    <?php echo ($role_filter === $role) ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($role); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Users Table -->
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="4" class="empty-state">
                                        <i class="fas fa-users-slash"></i>
                                        <p>No users found</p>
                                        <?php if (!empty($_GET['search']) || !empty($role_filter)): ?>
                                            <small><a href="?" style="color: #007bff;">Clear filters</a></small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <div class="user-info">
                                            <div class="user-avatar">
                                                <?php if ($user['profile_picture']): ?>
                                                    <img src="<?php echo htmlspecialchars($user['profile_picture']); ?>" 
                                                         alt="<?php echo htmlspecialchars($user['name']); ?>" 
                                                         style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                                <?php else: ?>
                                                    <i class="fas fa-user" style="color: #666;"></i>
                                                <?php endif; ?>
                                            </div>
                                            <div>
                                                <div class="user-name"><?php echo htmlspecialchars($user['name']); ?></div>
                                                <div class="user-email"><?php echo htmlspecialchars($user['email']); ?></div>
                                                <?php if ($user['department']): ?>
                                                    <div style="font-size: 11px; color: #666;"><?php echo htmlspecialchars($user['department']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="role-badge" style="background-color: <?php echo $role_colors[$user['role']] ?? '#6c757d'; ?>">
                                            <?php echo ucfirst($user['role']); ?>
                                            <?php if ($user['permission_count'] > 0): ?>
                                                <span title="<?php echo $user['permission_count']; ?> permissions"> (<?php echo $user['permission_count']; ?>)</span>
                                            <?php endif; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $user['status']; ?>">
                                            <?php echo ucfirst($user['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <a href="?action=edit&id=<?php echo $user['id']; ?>" 
                                               class="btn-action" 
                                               title="Edit Role & Permissions">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <button class="btn-action" 
                                                    onclick="quickRoleChange(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars(addslashes($user['name'])); ?>', '<?php echo $user['role']; ?>')"
                                                    title="Quick Role Change">
                                                <i class="fas fa-exchange-alt"></i>
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="table-footer">
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" class="page-link">
                                <i class="fas fa-chevron-left"></i> Previous
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= min(5, $total_pages); $i++): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                               class="page-link <?php echo $i == $page ? 'current' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($total_pages > 5 && $page < $total_pages - 2): ?>
                            <span>...</span>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="page-link">
                                <?php echo $total_pages; ?>
                            </a>
                        <?php endif; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" class="page-link">
                                Next <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- Right Column: Edit Form or Instructions -->
            <div class="right-column">
                <?php if ($edit_mode && $edit_user): ?>
                    <!-- Edit Role Form -->
                    <div class="info-panel">
                        <h3><i class="fas fa-user-edit"></i> Edit Role & Permissions</h3>
                        
                        <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee;">
                            <div class="user-avatar" style="width: 50px; height: 50px;">
                                <?php if ($edit_user['profile_picture']): ?>
                                    <img src="<?php echo htmlspecialchars($edit_user['profile_picture']); ?>" 
                                         alt="<?php echo htmlspecialchars($edit_user['name']); ?>" 
                                         style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                                <?php else: ?>
                                    <i class="fas fa-user" style="font-size: 24px; color: #666;"></i>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h4><?php echo htmlspecialchars($edit_user['name']); ?></h4>
                                <p style="color: #666; font-size: 14px;"><?php echo htmlspecialchars($edit_user['email']); ?></p>
                                <p style="font-size: 13px;">Current Role: <strong><?php echo ucfirst($edit_user['role']); ?></strong></p>
                            </div>
                        </div>
                        
                        <form method="POST" id="editRoleForm">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="update_role_permissions">
                            <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                            
                            <div class="form-group">
                                <label>
                                    <i class="fas fa-user-tag"></i> New Role
                                </label>
                                <select name="role" required>
                                    <option value="">Select Role</option>
                                    <?php foreach (['admin', 'subadmin', 'teacher', 'student', 'parent'] as $role): ?>
                                        <option value="<?php echo $role; ?>" 
                                            <?php echo ($edit_user['role'] === $role) ? 'selected' : ''; ?>>
                                            <?php echo ucfirst($role); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small style="display: block; margin-top: 5px; color: #666; font-size: 12px;">
                                    Changing role will reset permissions to defaults for that role.
                                </small>
                            </div>
                            
                            <div class="form-group">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <label>
                                        <i class="fas fa-key"></i> Permissions
                                    </label>
                                    <div>
                                        <button type="button" class="select-all-btn" onclick="selectAllPermissions()">Select All</button>
                                        <button type="button" class="select-all-btn" onclick="deselectAllPermissions()" style="color: #dc3545; margin-left: 10px;">Clear All</button>
                                    </div>
                                </div>
                                
                                <div class="permissions-container">
                                    <?php foreach ($permissions_list as $category => $perms): ?>
                                    <div class="permission-category">
                                        <h5><?php echo htmlspecialchars($category); ?></h5>
                                        <div>
                                            <?php foreach ($perms as $key => $data): ?>
                                            <div class="permission-item">
                                                <input type="checkbox" 
                                                       id="perm_<?php echo $key; ?>" 
                                                       name="permissions[]" 
                                                       value="<?php echo $key; ?>"
                                                       <?php echo in_array($key, $edit_user['permissions']) ? 'checked' : ''; ?>>
                                                <label for="perm_<?php echo $key; ?>">
                                                    <span class="permission-title"><?php echo htmlspecialchars($data['label']); ?></span>
                                                    <span class="permission-desc"><?php echo htmlspecialchars($data['desc']); ?></span>
                                                </label>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            
                            <div style="display: flex; gap: 10px;">
                                <button type="submit" class="btn btn-success" style="flex: 1;">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                                <a href="roles.php" class="btn btn-secondary" style="flex: 1; text-align: center;">
                                    Cancel
                                </a>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- Welcome/Instructions Panel -->
                    <div class="info-panel">
                        <h3><i class="fas fa-info-circle"></i> How to Use</h3>
                        <ul class="instructions">
                            <li><i class="fas fa-search"></i> Search and filter users using the search box</li>
                            <li><i class="fas fa-edit"></i> Click the edit icon to modify a user's role and permissions</li>
                            <li><i class="fas fa-exchange-alt"></i> Use quick role change for immediate role updates</li>
                            <li><i class="fas fa-save"></i> Changes are saved immediately</li>
                        </ul>
                        
                        <div style="background: #f8f9fa; border-radius: 8px; padding: 15px; margin-top: 20px;">
                            <h4 style="margin-bottom: 10px; font-size: 14px;">Role Descriptions:</h4>
                            <div style="font-size: 13px; color: #666; margin-bottom: 8px;">
                                <strong>Admin:</strong> Full system access, can manage all users and settings
                            </div>
                            <div style="font-size: 13px; color: #666; margin-bottom: 8px;">
                                <strong>Sub-Admin:</strong> Limited admin access, can manage specific areas
                            </div>
                            <div style="font-size: 13px; color: #666; margin-bottom: 8px;">
                                <strong>Teacher:</strong> Manage classes, assignments, and student progress
                            </div>
                            <div style="font-size: 13px; color: #666; margin-bottom: 8px;">
                                <strong>Student:</strong> Access learning materials and submit work
                            </div>
                            <div style="font-size: 13px; color: #666;">
                                <strong>Parent:</strong> Monitor child progress and communicate with teachers
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </section>
    
    <!-- Quick Role Change Modal -->
    <div id="quickRoleModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Quick Role Change</h3>
                <button class="close-modal" onclick="closeModal()">&times;</button>
            </div>
            <div style="margin-bottom: 20px;">
                <p>Change role for <strong id="modalUserName"></strong></p>
                <select id="modalRoleSelect" class="form-group" style="margin-top: 10px;">
                    <?php foreach (['admin', 'subadmin', 'teacher', 'student', 'parent'] as $role): ?>
                        <option value="<?php echo $role; ?>"><?php echo ucfirst($role); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button onclick="closeModal()" class="btn btn-secondary" style="flex: 1;">
                    Cancel
                </button>
                <button onclick="confirmRoleChange()" class="btn btn-primary" style="flex: 1;">
                    Change Role
                </button>
            </div>
        </div>
    </div>
    
    <script>
        // Global variables
        let selectedUserId = null;
        
        // Apply filter from dropdown
        function applyFilter(select) {
            const url = new URL(window.location);
            url.searchParams.set(select.name, select.value);
            url.searchParams.set('page', '1');
            window.location.href = url.toString();
        }
        
        // Quick role change
        function quickRoleChange(userId, userName, currentRole) {
            selectedUserId = userId;
            document.getElementById('modalUserName').textContent = userName;
            document.getElementById('modalRoleSelect').value = currentRole;
            document.getElementById('quickRoleModal').style.display = 'flex';
        }
        
        // Close modal
        function closeModal() {
            document.getElementById('quickRoleModal').style.display = 'none';
            selectedUserId = null;
        }
        
        // Confirm role change
        function confirmRoleChange() {
            if (!selectedUserId) return;
            
            const newRole = document.getElementById('modalRoleSelect').value;
            const csrfToken = '<?php echo $csrf_token; ?>';
            
            // Create form and submit
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = csrfToken;
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'update_role_permissions';
            
            const userIdInput = document.createElement('input');
            userIdInput.type = 'hidden';
            userIdInput.name = 'user_id';
            userIdInput.value = selectedUserId;
            
            const roleInput = document.createElement('input');
            roleInput.type = 'hidden';
            roleInput.name = 'role';
            roleInput.value = newRole;
            
            form.appendChild(csrfInput);
            form.appendChild(actionInput);
            form.appendChild(userIdInput);
            form.appendChild(roleInput);
            document.body.appendChild(form);
            form.submit();
        }
        
        // Select all permissions
        function selectAllPermissions() {
            document.querySelectorAll('input[name="permissions[]"]').forEach(cb => {
                cb.checked = true;
            });
        }
        
        // Deselect all permissions
        function deselectAllPermissions() {
            document.querySelectorAll('input[name="permissions[]"]').forEach(cb => {
                cb.checked = false;
            });
        }
        
        // Close modal on outside click
        window.addEventListener('click', function(event) {
            const modal = document.getElementById('quickRoleModal');
            if (event.target === modal) {
                closeModal();
            }
        });
        
        // Auto-check permissions when role changes
        document.addEventListener('DOMContentLoaded', function() {
            const roleSelect = document.querySelector('select[name="role"]');
            if (roleSelect) {
                roleSelect.addEventListener('change', function() {
                    const role = this.value;
                    if (role && confirm('Change role to ' + role + '? This will reset permissions to defaults.')) {
                        // Uncheck all permissions first
                        deselectAllPermissions();
                        
                        // In a real implementation, you would fetch default permissions via AJAX
                        // For now, we'll just show a message
                        alert('Role changed to ' + role + '. Please review and adjust permissions as needed.');
                    }
                });
            }
        });
    </script>
</body>
</html>