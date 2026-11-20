<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/includes/helpers.php');

// Debug mode toggle (set to true to fetch all debts, not just overdue)
define('DEBUG_MODE', true);

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Connection is null'), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    http_response_code(500);
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Check admin authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    log_activity($user_id, 'access_denied', 'Attempted to access bulk_reminder.php without admin role', $conn);
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

// Log page access
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
log_activity($_SESSION['user_id'], 'page_access', "Admin accessed bulk_reminder.php, IP: $ip_address, User Agent: $user_agent", $conn);

// Generate and store CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $csrf_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)");
    if ($stmt) {
        $stmt->bind_param("iss", $_SESSION['user_id'], $_SESSION['csrf_token'], $csrf_expiry);
        $stmt->execute();
        $stmt->close();
    } else {
        error_log("Failed to prepare CSRF token insertion: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    }
}

// Fetch academic years
$academic_years_result = $conn->query("SELECT DISTINCT year FROM academic_calendar WHERE year REGEXP '^[0-9]{4}/[0-9]{4}$' ORDER BY year DESC");
if (!$academic_years_result) {
    error_log("Error fetching academic years: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    http_response_code(500);
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unable to fetch academic years']);
    exit;
}
$academic_years = [];
while ($row = $academic_years_result->fetch_assoc()) {
    $academic_years[] = $row['year'];
}
$academic_years_count = count($academic_years);
error_log("Fetched $academic_years_count academic years", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
$academic_years_result->free();

// Fetch departments
$departments_result = $conn->query("SELECT id, name FROM departments ORDER BY name ASC");
if (!$departments_result) {
    error_log("Error fetching departments: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    http_response_code(500);
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unable to fetch departments']);
    exit;
}
$departments = [];
$valid_department_enums = ['Lower Primary', 'Upper Primary', 'Junior High', 'Kindergarten', 'Pre-School'];
while ($row = $departments_result->fetch_assoc()) {
    $dept_name = $row['name'];
    if (!in_array($dept_name, $valid_department_enums)) {
        $dept_name = str_contains($row['name'], 'Lower') ? 'Lower Primary' :
                     (str_contains($row['name'], 'Upper') ? 'Upper Primary' :
                     (str_contains($row['name'], 'Junior') ? 'Junior High' :
                     (str_contains($row['name'], 'Kindergarten') ? 'Kindergarten' : $row['name'])));
    }
    $departments[$row['id']] = ['name' => $row['name'], 'enum_name' => $dept_name];
}
$departments_count = count($departments);
error_log("Fetched $departments_count departments", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
$departments_result->free();

// Fetch classes
$classes_result = $conn->query("SELECT id, class_name, department_id FROM classes ORDER BY class_name ASC");
if (!$classes_result) {
    error_log("Error fetching classes: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    http_response_code(500);
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unable to fetch classes']);
    exit;
}
$classes = [];
while ($row = $classes_result->fetch_assoc()) {
    if (isset($departments[$row['department_id']])) {
        $classes[$row['id']] = ['class_name' => $row['class_name'], 'department_id' => $row['department_id']];
    }
}
$classes_count = count($classes);
error_log("Fetched $classes_count classes", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
$classes_result->free();

// Initialize variables
$selected_year = !empty($academic_years) ? $academic_years[0] : '';
$selected_dept = 0;
$selected_class = 0;
$search_query = '';
$overdue_debts = [];
$total_debts = 0;
$total_unpaid = 0;
$debug_message = '';

// Function to fetch overdue debts
function fetchOverdueDebts($conn, $academic_year, $dept_id, $class_id, $search_query, $debug_mode = false) {
    global $departments;
    $query = "
        SELECT 
            u.id AS student_id, 
            u.name AS student_name, 
            f.fee_type, 
            CASE 
                WHEN f.class_id IS NOT NULL THEN 'Class' 
                WHEN f.user_id IS NOT NULL THEN 'Learner' 
                ELSE 'School' 
            END AS target_type, 
            c.class_name, 
            f.amount, 
            f.due_date, 
            d.amount_due AS total_balance, 
            f.academic_year, 
            f.department, 
            d.id AS debt_id
        FROM users u
        JOIN debts d ON u.id = d.user_id
        JOIN fees f ON d.fee_id = f.id
        LEFT JOIN classes c ON f.class_id = c.id
        LEFT JOIN student_classes sc ON f.class_id = sc.class_id AND sc.user_id = d.user_id AND sc.academic_year = f.academic_year
        WHERE f.academic_year = ?
        AND u.role = 'student' 
        AND u.status = 'active' 
        " . ($debug_mode ? "" : "AND f.due_date < CURDATE()") . "
        AND d.amount_due > 0
    ";
    $params = [$academic_year];
    $types = "s";
    if ($dept_id > 0 && isset($departments[$dept_id])) {
        $query .= " AND f.department = ?";
        $types .= "s";
        $params[] = $departments[$dept_id]['enum_name'];
    }
    if ($class_id > 0) {
        $query .= " AND f.class_id = ?";
        $types .= "i";
        $params[] = $class_id;
    }
    if ($search_query) {
        $query .= " AND (u.name LIKE ? OR f.fee_type LIKE ?)";
        $types .= "ss";
        $search_param = "%$search_query%";
        $params[] = $search_param;
        $params[] = $search_param;
    }
    $query .= " ORDER BY u.name, f.due_date";
    error_log("Executing query: $query with params: " . json_encode($params), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/debug.log');

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log("Error preparing overdue debts query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        return [];
    }
    if ($params) {
        $stmt->bind_param($types, ...$params);
    }
    if (!$stmt->execute()) {
        error_log("Query execution failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        $stmt->close();
        return [];
    }
    $result = $stmt->get_result();
    $debts = [];
    while ($row = $result->fetch_assoc()) {
        $debts[] = [
            'student_id' => $row['student_id'],
            'student_name' => $row['student_name'],
            'fee_type' => $row['fee_type'],
            'target_type' => $row['target_type'],
            'class_name' => $row['class_name'],
            'amount' => $row['amount'],
            'due_date' => $row['due_date'],
            'total_balance' => $row['total_balance'],
            'academic_year' => $row['academic_year'],
            'department' => $row['department'],
            'debt_id' => $row['debt_id']
        ];
    }
    $stmt->close();
    error_log("Fetched " . count($debts) . " debts (debug_mode: " . ($debug_mode ? 'true' : 'false') . ")", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/debug.log');

    // Debug: Check table counts
    if (empty($debts)) {
        $counts = [
            'users' => $conn->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'")->fetch_row()[0],
            'fees' => $conn->query("SELECT COUNT(*) FROM fees WHERE academic_year = '$academic_year'" . ($debug_mode ? "" : " AND due_date < CURDATE()"))->fetch_row()[0],
            'debts' => $conn->query("SELECT COUNT(*) FROM debts WHERE amount_due > 0")->fetch_row()[0],
            'student_classes' => $conn->query("SELECT COUNT(*) FROM student_classes WHERE academic_year = '$academic_year'")->fetch_row()[0]
        ];
        error_log("Table counts: " . json_encode($counts), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/debug.log');
    }

    return $debts;
}

// Handle POST actions
$success_msg = '';
$error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['csrf_token'])) {
    $stmt = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    if (!$stmt) {
        error_log("Error preparing CSRF validation query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        http_response_code(500);
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'CSRF validation setup failed']);
        exit;
    }
    $stmt->bind_param("is", $_SESSION['user_id'], $_POST['csrf_token']);
    $stmt->execute();
    $csrf_valid = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    if (!$csrf_valid) {
        error_log("Invalid CSRF token for action {$_POST['action']}, user_id: {$_SESSION['user_id']}, token: {$_POST['csrf_token']}", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        $error_msg = "Invalid or expired session. Please try again.";
        log_activity($_SESSION['user_id'], 'csrf_error', "Invalid CSRF token for action {$_POST['action']}", $conn);
    } else {
        if ($_POST['action'] === 'delete_debt') {
            $debt_id = (int)($_POST['debt_id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM debts WHERE id = ?");
            if (!$stmt) {
                error_log("Error preparing delete debt query: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
                ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Failed to prepare delete query']);
                exit;
            }
            $stmt->bind_param("i", $debt_id);
            if ($stmt->execute()) {
                ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                log_activity($_SESSION['user_id'], 'delete_debt', "Deleted debt ID $debt_id", $conn);
            } else {
                error_log("Error deleting debt ID $debt_id: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
                ob_clean();
                header('Content-Type: application/json');
                http_response_code(500);
                echo json_encode(['error' => 'Failed to delete debt']);
                log_activity($_SESSION['user_id'], 'delete_debt_error', "Failed to delete debt ID $debt_id: " . $stmt->error, $conn);
            }
            $stmt->close();
            exit;
        } elseif ($_POST['action'] === 'filter_classes') {
            $dept_id = (int)($_POST['department_id'] ?? 0);
            if ($dept_id > 0 && !isset($departments[$dept_id])) {
                error_log("Invalid department_id: $dept_id for filter_classes", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
                ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Invalid department ID']);
                exit;
            }
            $filtered_classes = [];
            foreach ($classes as $id => $class) {
                if ($dept_id == 0 || $class['department_id'] == $dept_id) {
                    $filtered_classes[] = ['id' => $id, 'class_name' => $class['class_name']];
                }
            }
            error_log("Filtered " . count($filtered_classes) . " classes for dept_id=$dept_id", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'classes' => $filtered_classes]);
            exit;
        } elseif ($_POST['action'] === 'filter_data') {
            $academic_year = trim($_POST['academic_year'] ?? '');
            $dept_id = (int)($_POST['department_id'] ?? 0);
            $class_id = (int)($_POST['class_id'] ?? 0);
            $search_query = trim($_POST['search_query'] ?? '');

            if (empty($academic_year)) {
                ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Academic year is required']);
                exit;
            }

            $overdue_debts = fetchOverdueDebts($conn, $academic_year, $dept_id, $class_id, $search_query, DEBUG_MODE);
            $total_unpaid = array_sum(array_map(function($debt) { return $debt['total_balance']; }, $overdue_debts));
            $total_debts = count($overdue_debts);
            $chart_data = getFeeChartData($conn, $academic_year);

            // Validate chart data structure
            if (!isset($chart_data['labels']) || !isset($chart_data['paid']) || !isset($chart_data['unpaid']) ||
                !is_array($chart_data['labels']) || !is_array($chart_data['paid']) || !is_array($chart_data['unpaid'])) {
                error_log("Invalid chart data structure: " . json_encode($chart_data), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
                $chart_data = [
                    'labels' => ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
                    'paid' => array_fill(0, 12, 0),
                    'unpaid' => array_fill(0, 12, 0)
                ];
            }

            $response = [
                'success' => true,
                'debts' => $overdue_debts,
                'total_debts' => $total_debts,
                'total_unpaid' => $total_unpaid,
                'chart_data' => $chart_data,
                'debug_message' => empty($overdue_debts) ? "No debts found. Check if fees, debts, or student records exist for $academic_year." : ""
            ];
            error_log("JSON Response: " . json_encode($response), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/debug.log');
            
            ob_clean();
            header('Content-Type: application/json');
            $json = json_encode($response);
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("JSON encoding error: " . json_last_error_msg(), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'JSON encoding failed']);
                exit;
            }
            echo $json;
            error_log("Filtered $total_debts debts, total unpaid: GHS $total_unpaid for year=$academic_year, dept_id=$dept_id, class_id=$class_id", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
            exit;
        } elseif ($_POST['action'] === 'log_error') {
            $message = trim($_POST['message'] ?? 'Unknown client error');
            log_activity($_SESSION['user_id'], 'client_error', $message, $conn);
            ob_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        // Regenerate CSRF token
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $csrf_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
        $stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)");
        if ($stmt) {
            $stmt->bind_param("iss", $_SESSION['user_id'], $_SESSION['csrf_token'], $csrf_expiry);
            $stmt->execute();
            $stmt->close();
        }
    }
}

// Initial data fetch
if ($selected_year) {
    $overdue_debts = fetchOverdueDebts($conn, $selected_year, $selected_dept, $selected_class, $search_query, DEBUG_MODE);
    $total_unpaid = array_sum(array_map(function($debt) { return $debt['total_balance']; }, $overdue_debts));
    $total_debts = count($overdue_debts);
    $debug_message = empty($overdue_debts) ? "No debts found for $selected_year. Try a different academic year or check database records." : "";
    log_activity($_SESSION['user_id'], 'viewed_reminders_dashboard', "Initial load for academic_year=$selected_year, Total Debts: $total_debts, Total Unpaid: GHS $total_unpaid, Debug: $debug_message", $conn);
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && isset($_GET['academic_year'])) {
    $academic_year = trim($_GET['academic_year'] ?? '');
    $dept_id = (int)($_GET['department_id'] ?? 0);
    $class_id = (int)($_GET['class_id'] ?? 0);
    $search_query = trim($_GET['search_query'] ?? '');

    $overdue_debts = fetchOverdueDebts($conn, $academic_year, $dept_id, $class_id, $search_query, DEBUG_MODE);
    ob_clean();
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="overdue_debts_' . date('YmdHis') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Student', 'Fee Type', 'Target', 'Class', 'Amount (GHS)', 'Due Date', 'Balance (GHS)', 'Academic Year', 'Department']);
    foreach ($overdue_debts as $debt) {
        fputcsv($output, [
            $debt['student_name'],
            $debt['fee_type'],
            $debt['target_type'],
            $debt['class_name'] ?? 'N/A',
            number_format($debt['amount'], 2),
            $debt['due_date'],
            number_format($debt['total_balance'], 2),
            $debt['academic_year'],
            $debt['department']
        ]);
    }
    fclose($output);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Fee Reminders - QPMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .overdue { background-color: #f8d7da; }
        .loading-spinner { display: none; margin-left: 8px; }
        .print-area { display: none; }
        @media print {
            body * { visibility: hidden; }
            .print-area, .print-area * { visibility: visible; }
            .print-area { position: absolute; top: 0; left: 0; width: 100%; }
            .print-table { display: table; }
        }
        .btn-primary { background-color: var(--primary-color, #007bff); border-color: var(--primary-color, #007bff); }
        .btn-success { background-color: var(--success-color, #28a745); border-color: var(--success-color, #28a745); }
        .btn-danger { background-color: var(--danger-color, #dc3545); border-color: var(--danger-color, #dc3545); }
        .btn-info { background-color: var(--info-color, #17a2b8); border-color: var(--info-color, #17a2b8); }
        .btn-warning { background-color: var(--warning-color, #ffc107); border-color: var(--warning-color, #ffc107); }
    </style>
</head>
<body>
    <?php 
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
    if (!file_exists($header_path)) {
        error_log("Admin header file missing: $header_path", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        log_activity($_SESSION['user_id'], 'error', "Failed to load admin header template at $header_path", $conn);
        $_SESSION['error_msg'] = "Error: Admin header template is missing. Please contact the administrator.";
        header("Location: /login.php");
        exit();
    }
    include $header_path; 
    ?>
    
    <div class="container mt-4">
        <h1 class="mb-4"><i class="fas fa-bell me-2"></i>Bulk Fee Reminders</h1>
        
        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <?php if ($debug_message): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($debug_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form id="filter_form" class="needs-validation" novalidate>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="academic_year" class="form-label">Academic Year</label>
                            <select id="academic_year" class="form-select" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $year === $selected_year ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">Please select an academic year.</div>
                        </div>
                        <div class="col-md-3">
                            <label for="department_id" class="form-label">Department</label>
                            <select id="department_id" class="form-select">
                                <option value="0">All Departments</option>
                                <?php foreach ($departments as $id => $dept): ?>
                                    <option value="<?php echo $id; ?>" <?php echo $id === $selected_dept ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="class_id" class="form-label">Class</label>
                            <select id="class_id" class="form-select">
                                <option value="0">All Classes</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="search_query" class="form-label">Search</label>
                            <input type="text" id="search_query" class="form-control" placeholder="Search by student or fee type">
                        </div>
                        <div class="col-md-12 mt-3">
                            <label class="form-label">Delivery Methods</label>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="delivery_methods[]" id="delivery_email" value="email">
                                <label class="form-check-label" for="delivery_email">Email</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input" type="checkbox" name="delivery_methods[]" id="delivery_whatsapp" value="whatsapp" checked>
                                <label class="form-check-label" for="delivery_whatsapp">WhatsApp</label>
                            </div>
                        </div>
                        <div class="col-md-12 mt-3">
                            <button type="button" id="filter_btn" class="btn btn-primary">
                                <i class="fas fa-filter me-1"></i>Filter
                                <span class="spinner-border spinner-border-sm loading-spinner"></span>
                            </button>
                            <a id="export_btn" href="#" class="btn btn-success">
                                <i class="fas fa-file-export me-1"></i>Export CSV
                            </a>
                            <button type="button" id="print_btn" class="btn btn-info">
                                <i class="fas fa-print me-1"></i>Print
                                <span class="spinner-border spinner-border-sm loading-spinner"></span>
                            </button>
                        </div>
                    </div>
                    <input type="hidden" id="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                </form>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-4 col-12">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">Total Overdue Debts</h5>
                        <p class="card-text" id="total_debts"><?php echo $total_debts; ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="card text-center">
                    <div class="card-body">
                        <h5 class="card-title">Total Unpaid Amount</h5>
                        <p class="card-text" id="total_unpaid">GHS <?php echo number_format($total_unpaid, 2); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-4 col-12">
                <div class="card text-center">
                    <div class="card-body">
                        <button id="refreshBtn" class="btn btn-primary"><i class="fas fa-sync-alt me-1"></i>Refresh</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-body">
                <canvas id="feeChart" class="my-4"></canvas>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <table id="debtsTable" class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="selectAll"></th>
                            <th>Student</th>
                            <th>Fee Type</th>
                            <th>Target</th>
                            <th>Class</th>
                            <th>Amount (GHS)</th>
                            <th>Due Date</th>
                            <th>Balance (GHS)</th>
                            <th>Academic Year</th>
                            <th>Department</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($overdue_debts as $debt): ?>
                            <tr class="<?php echo $debt['due_date'] < date('Y-m-d') ? 'overdue' : ''; ?>">
                                <td><input type="checkbox" name="debt_ids[]" value="<?php echo htmlspecialchars($debt['student_id']); ?>" class="debt-checkbox"></td>
                                <td><?php echo htmlspecialchars($debt['student_name']); ?></td>
                                <td><?php echo htmlspecialchars($debt['fee_type']); ?></td>
                                <td><?php echo htmlspecialchars($debt['target_type']); ?></td>
                                <td><?php echo htmlspecialchars($debt['class_name'] ?? 'N/A'); ?></td>
                                <td><?php echo number_format($debt['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($debt['due_date']); ?></td>
                                <td><?php echo number_format($debt['total_balance'], 2); ?></td>
                                <td><?php echo htmlspecialchars($debt['academic_year']); ?></td>
                                <td><?php echo htmlspecialchars($debt['department']); ?></td>
                                <td>
                                    <button class="btn btn-danger btn-sm delete-debt" data-debt-id="<?php echo htmlspecialchars($debt['debt_id']); ?>" title="Delete Debt" aria-label="Delete Debt">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($overdue_debts)): ?>
                            <tr><td colspan="11" class="text-center"><?php echo htmlspecialchars($debug_message ?: 'No overdue debts found for the selected filters.'); ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="d-flex justify-content-end flex-wrap gap-2 mt-3">
                    <a id="sendRemindersBtn" href="#" class="btn btn-warning" onclick="goToSendReminder(); return false;">
                        <i class="fas fa-bell me-1"></i>Send Reminders
                    </a>
                    <a href="/admin/fees_payments.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-1"></i>Back to Fees
                    </a>
                </div>
            </div>
        </div>

        <!-- Print Area -->
        <div class="print-area">
            <h2>Overdue Debts Report</h2>
            <table class="table print-table">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Fee Type</th>
                        <th>Target</th>
                        <th>Class</th>
                        <th>Amount (GHS)</th>
                        <th>Due Date</th>
                        <th>Balance (GHS)</th>
                        <th>Academic Year</th>
                        <th>Department</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($overdue_debts as $debt): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($debt['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($debt['fee_type']); ?></td>
                            <td><?php echo htmlspecialchars($debt['target_type']); ?></td>
                            <td><?php echo htmlspecialchars($debt['class_name'] ?? 'N/A'); ?></td>
                            <td><?php echo number_format($debt['amount'], 2); ?></td>
                            <td><?php echo htmlspecialchars($debt['due_date']); ?></td>
                            <td><?php echo number_format($debt['total_balance'], 2); ?></td>
                            <td><?php echo htmlspecialchars($debt['academic_year']); ?></td>
                            <td><?php echo htmlspecialchars($debt['department']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($overdue_debts)): ?>
                        <tr><td colspan="9" class="text-center"><?php echo htmlspecialchars($debug_message ?: 'No overdue debts found.'); ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <div class="print-footer">
                <p>Generated by Queen's Paradise Montessori School Management System - <?php echo date('F j, Y, g:i A T'); ?></p>
            </div>
        </div>
    </div>

    <?php 
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    if (!file_exists($footer_path)) {
        error_log("Admin footer file missing: $footer_path", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        log_activity($_SESSION['user_id'], 'error', "Failed to load admin footer template at $footer_path", $conn);
        $_SESSION['error_msg'] = "Error: Admin footer template is missing. Please contact the administrator.";
        header("Location: /login.php");
        exit();
    }
    include $footer_path; 
    $conn->close();
    ?>

    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            try {
                const debtsTable = $('#debtsTable').DataTable({
                    pageLength: 10,
                    order: [[8, 'asc']],
                    columnDefs: [{ orderable: false, targets: 0 }],
                    language: { emptyTable: "<?php echo htmlspecialchars($debug_message ?: 'No overdue debts found for the selected filters. Check database or filters.'); ?>" }
                });

                $('#selectAll').on('click', function() {
                    $('.debt-checkbox').prop('checked', this.checked);
                    updateButtons();
                });

                $(document).on('change', '.debt-checkbox', function() {
                    if (!this.checked) $('#selectAll').prop('checked', false);
                    updateButtons();
                });

                $('#refreshBtn').on('click', function() {
                    filterData();
                });

                function updateButtons() {
                    const checkedCount = $('.debt-checkbox:checked').length;
                    $('#sendRemindersBtn').toggleClass('disabled', checkedCount === 0);
                }

                function filterClasses() {
                    const deptId = $('#department_id').val();
                    const academicYear = $('#academic_year').val();
                    if (!academicYear) {
                        $('#class_id').html('<option value="0">All Classes</option>');
                        alert('Please select an academic year first.');
                        return;
                    }
                    $.ajax({
                        url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'filter_classes',
                            department_id: deptId,
                            csrf_token: $('#csrf_token').val()
                        },
                        success: function(response) {
                            if (response.success) {
                                let options = '<option value="0">All Classes</option>';
                                response.classes.forEach(cls => {
                                    options += `<option value="${cls.id}">${cls.class_name}</option>`;
                                });
                                $('#class_id').html(options);
                                filterData();
                            } else {
                                console.error('Filter classes error:', response.error);
                                alert('Failed to load classes: ' + (response.error || 'Unknown error. Check server logs.'));
                                logClientError('Filter classes AJAX error: ' + (response.error || 'Unknown'));
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('AJAX error:', status, error, xhr.responseText);
                            alert('Failed to load classes. Server returned: ' + xhr.status + ' ' + xhr.statusText);
                            logClientError('Filter classes AJAX failed: ' + status + ' - ' + error + ' - ' + xhr.responseText);
                        }
                    });
                }

                function filterData() {
                    const academicYear = $('#academic_year').val();
                    const deptId = $('#department_id').val();
                    const classId = $('#class_id').val();
                    const searchQuery = $('#search_query').val();
                    if (!academicYear) {
                        debtsTable.clear().draw();
                        $('#debtsTable tbody').html('<tr><td colspan="11" class="text-center">Please select an academic year.</td></tr>');
                        $('#total_debts').text('0');
                        $('#total_unpaid').text('GHS 0.00');
                        $('.print-table tbody').html('');
                        $('#export_btn').attr('href', `?export=csv&academic_year=${encodeURIComponent(academicYear)}`);
                        return;
                    }
                    $('#filter_btn .loading-spinner').show();
                    $.ajax({
                        url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'filter_data',
                            academic_year: academicYear,
                            department_id: deptId,
                            class_id: classId,
                            search_query: searchQuery,
                            csrf_token: $('#csrf_token').val()
                        },
                        success: function(response) {
                            $('#filter_btn .loading-spinner').hide();
                            if (response.success) {
                                debtsTable.clear();
                                let tbody = '';
                                let totalDebts = response.total_debts || 0;
                                if (response.debts.length === 0) {
                                    tbody = `<tr><td colspan="11" class="text-center">${response.debug_message || 'No debts found for the selected filters.'}</td></tr>`;
                                } else {
                                    response.debts.forEach(debt => {
                                        tbody += `
                                            <tr class="${debt.due_date < '<?php echo date('Y-m-d'); ?>' ? 'overdue' : ''}">
                                                <td><input type="checkbox" name="debt_ids[]" value="${debt.student_id}" class="debt-checkbox"></td>
                                                <td>${debt.student_name}</td>
                                                <td>${debt.fee_type}</td>
                                                <td>${debt.target_type}</td>
                                                <td>${debt.class_name || 'N/A'}</td>
                                                <td>${parseFloat(debt.amount).toFixed(2)}</td>
                                                <td>${debt.due_date}</td>
                                                <td>${parseFloat(debt.total_balance).toFixed(2)}</td>
                                                <td>${debt.academic_year}</td>
                                                <td>${debt.department}</td>
                                                <td>
                                                    <button class="btn btn-danger btn-sm delete-debt" data-debt-id="${debt.debt_id}" title="Delete Debt" aria-label="Delete Debt">
                                                        <i class="fas fa-trash"></i> Delete
                                                    </button>
                                                </td>
                                            </tr>`;
                                        debtsTable.row.add([
                                            `<input type="checkbox" name="debt_ids[]" value="${debt.student_id}" class="debt-checkbox">`,
                                            debt.student_name,
                                            debt.fee_type,
                                            debt.target_type,
                                            debt.class_name || 'N/A',
                                            parseFloat(debt.amount).toFixed(2),
                                            debt.due_date,
                                            parseFloat(debt.total_balance).toFixed(2),
                                            debt.academic_year,
                                            debt.department,
                                            `<button class="btn btn-danger btn-sm delete-debt" data-debt-id="${debt.debt_id}" title="Delete Debt" aria-label="Delete Debt"><i class="fas fa-trash"></i> Delete</button>`
                                        ]);
                                    });
                                }
                                debtsTable.draw();
                                $('#debtsTable tbody').html(tbody);
                                $('#total_debts').text(totalDebts);
                                $('#total_unpaid').text('GHS ' + parseFloat(response.total_unpaid || 0).toFixed(2));

                                // Update chart
                                const chart = Chart.getChart('feeChart');
                                if (response.chart_data && response.chart_data.labels && response.chart_data.paid && response.chart_data.unpaid) {
                                    if (!chart) {
                                        new Chart(document.getElementById('feeChart'), {
                                            type: 'bar',
                                            data: {
                                                labels: response.chart_data.labels,
                                                datasets: [
                                                    {
                                                        label: 'Paid',
                                                        data: response.chart_data.paid,
                                                        backgroundColor: '#28a745',
                                                        borderColor: '#218838',
                                                        borderWidth: 1
                                                    },
                                                    {
                                                        label: 'Unpaid',
                                                        data: response.chart_data.unpaid,
                                                        backgroundColor: '#dc3545',
                                                        borderColor: '#c82333',
                                                        borderWidth: 1
                                                    }
                                                ]
                                            },
                                            options: {
                                                scales: {
                                                    y: { beginAtZero: true, title: { display: true, text: 'Amount (GHS)' } },
                                                    x: { title: { display: true, text: 'Month' } }
                                                },
                                                plugins: {
                                                    legend: { position: 'top' },
                                                    title: { display: true, text: 'Fee Payment Trends' }
                                                }
                                            }
                                        });
                                    } else {
                                        chart.data.labels = response.chart_data.labels;
                                        chart.data.datasets[0].data = response.chart_data.paid;
                                        chart.data.datasets[1].data = response.chart_data.unpaid;
                                        chart.update();
                                    }
                                } else {
                                    console.error('Invalid chart data:', response.chart_data);
                                    logClientError('Invalid chart data received: ' + JSON.stringify(response.chart_data));
                                    alert('Failed to update chart: Invalid or missing chart data.');
                                    if (chart) {
                                        chart.data.labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
                                        chart.data.datasets[0].data = Array(12).fill(0);
                                        chart.data.datasets[1].data = Array(12).fill(0);
                                        chart.update();
                                    }
                                }

                                // Update print table
                                let printTbody = response.debts.length === 0 ?
                                    `<tr><td colspan="9" class="text-center">${response.debug_message || 'No debts found.'}</td></tr>` :
                                    response.debts.map(debt => `
                                        <tr>
                                            <td>${debt.student_name}</td>
                                            <td>${debt.fee_type}</td>
                                            <td>${debt.target_type}</td>
                                            <td>${debt.class_name || 'N/A'}</td>
                                            <td>${parseFloat(debt.amount).toFixed(2)}</td>
                                            <td>${debt.due_date}</td>
                                            <td>${parseFloat(debt.total_balance).toFixed(2)}</td>
                                            <td>${debt.academic_year}</td>
                                            <td>${debt.department}</td>
                                        </tr>
                                    `).join('');
                                $('.print-table tbody').html(printTbody);

                                // Update export link
                                $('#export_btn').attr('href', `?export=csv&academic_year=${encodeURIComponent(academicYear)}&department_id=${deptId}&class_id=${classId}&search_query=${encodeURIComponent(searchQuery)}`);

                                updateButtons();
                            } else {
                                alert('Failed to load data: ' + (response.error || 'Unknown error. Check server logs.'));
                                logClientError('Filter data AJAX error: ' + (response.error || 'Unknown'));
                            }
                        },
                        error: function(xhr, status, error) {
                            $('#filter_btn .loading-spinner').hide();
                            console.error('AJAX error details:', {
                                status: xhr.status,
                                statusText: xhr.statusText,
                                responseText: xhr.responseText
                            });
                            alert('Failed to load data. Server returned: ' + xhr.status + ' ' + xhr.statusText + '. Response: ' + xhr.responseText);
                            logClientError('Filter data AJAX failed: ' + status + ' - ' + error + ' - ' + xhr.responseText);
                        }
                    });
                }

                function logClientError(message) {
                    $.ajax({
                        url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                        type: 'POST',
                        data: {
                            action: 'log_error',
                            message: message,
                            csrf_token: $('#csrf_token').val()
                        }
                    });
                }

                window.goToSendReminder = function() {
                    const checked = $('.debt-checkbox:checked');
                    if (checked.length === 0) {
                        alert('Please select at least one student.');
                        return;
                    }
                    const methods = [];
                    $('input[name="delivery_methods[]"]:checked').each(function() {
                        methods.push($(this).val());
                    });
                    if (methods.length === 0) {
                        alert('Please select at least one delivery method (Email, WhatsApp).');
                        return;
                    }
                    const debtIds = checked.map(function() { return $(this).val(); }).get().join(',');
                    const academicYear = $('#academic_year').val();
                    const deptId = $('#department_id').val();
                    const classId = $('#class_id').val();
                    const searchQuery = $('#search_query').val();
                    const url = `send_reminder.php?debt_ids=${encodeURIComponent(debtIds)}&methods=${encodeURIComponent(methods.join(','))}&academic_year=${encodeURIComponent(academicYear)}&department_id=${deptId}&class_id=${classId}&search_query=${encodeURIComponent(searchQuery)}&csrf_token=${encodeURIComponent($('#csrf_token').val())}`;
                    window.location.href = url;
                };

                $(document).on('click', '.delete-debt', function() {
                    const debtId = $(this).data('debt-id');
                    if (confirm('Are you sure you want to delete this debt?')) {
                        $.ajax({
                            url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                            type: 'POST',
                            dataType: 'json',
                            data: {
                                action: 'delete_debt',
                                debt_id: debtId,
                                csrf_token: $('#csrf_token').val()
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert('Debt deleted successfully.');
                                    filterData();
                                } else {
                                    alert('Error deleting debt: ' + (response.error || 'Unknown error'));
                                    logClientError('Delete debt AJAX error: ' + (response.error || 'Unknown'));
                                }
                            },
                            error: function(xhr, status, error) {
                                alert('Failed to delete debt. Server returned: ' + xhr.status + ' ' + xhr.statusText);
                                logClientError('Delete debt AJAX failed: ' + status + ' - ' + error + ' - ' + xhr.responseText);
                            }
                        });
                    }
                });

                $('#filter_btn').click(function() {
                    const form = document.getElementById('filter_form');
                    if (!form.checkValidity()) {
                        form.classList.add('was-validated');
                        return;
                    }
                    filterData();
                });

                $('#print_btn').click(function() {
                    $('#print_btn .loading-spinner').show();
                    setTimeout(() => {
                        window.print();
                        $('#print_btn .loading-spinner').hide();
                    }, 500);
                });

                let searchTimeout;
                $('#search_query').on('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(filterData, 500);
                });

                $('#department_id').change(filterClasses);
                $('#academic_year').change(function() {
                    filterClasses();
                    filterData();
                });
                $('#class_id').change(filterData);
                <?php if ($selected_year): ?>
                    filterClasses();
                    filterData();
                <?php endif; ?>
            } catch (e) {
                console.error('JavaScript initialization error:', e);
                logClientError('JavaScript initialization error: ' + e.message);
                alert('An unexpected error occurred. Please refresh the page or contact support.');
            }
        });
    </script>
</body>
</html>