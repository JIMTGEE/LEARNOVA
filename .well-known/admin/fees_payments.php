<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Check database connection
if (!$conn || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Connection is null'), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Database connection failed. Please contact the administrator.");
}

// Include log_activity.php and define fallback
$log_activity_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_activity_path)) {
    require_once($log_activity_path);
} else {
    error_log("log_activity.php not found at $log_activity_path", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    if (!function_exists('log_activity')) {
        function log_activity($user_id, $action, $details, $conn = null) {
            $log_file = $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log';
            $timestamp = date('Y-m-d H:i:s');
            error_log("[$timestamp] [user_id: $user_id] $action: $details\n", 3, $log_file);
        }
    }
}

// Check if log_activity function exists
if (!function_exists('log_activity')) {
    error_log("log_activity function is undefined after inclusion attempt", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    function log_activity($user_id, $action, $details, $conn = null) {
        $log_file = $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log';
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] [user_id: $user_id] $action: $details\n", 3, $log_file);
    }
}

// Check admin authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    log_activity($user_id, 'access_denied', 'Attempted to access fees_payments.php without admin role', $conn);
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

// Log page access
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
log_activity($_SESSION['user_id'], 'page_access', "Admin accessed fees_payments.php, IP: $ip_address, User Agent: $user_agent", $conn);

// Generate and store CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
$csrf_query = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)";
if ($csrf_stmt = $conn->prepare($csrf_query)) {
    $csrf_stmt->bind_param("iss", $_SESSION['user_id'], $csrf_token, $csrf_expiry);
    $csrf_stmt->execute();
    $csrf_stmt->close();
} else {
    error_log("Failed to store CSRF token: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
}
$_SESSION['csrf_token'] = $csrf_token;

// Fetch academic years
$academic_years_result = $conn->query("SELECT DISTINCT year FROM academic_calendar WHERE year REGEXP '^[0-9]{4}/[0-9]{4}$' ORDER BY year DESC");
if (!$academic_years_result) {
    error_log("Error fetching academic years: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    die("System error: Unable to fetch academic years. Please try again later.");
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
    die("System error: Unable to fetch departments. Please try again later.");
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
    die("System error: Unable to fetch classes. Please try again later.");
}
$classes = [];
while ($row = $classes_result->fetch_assoc()) {
    $classes[$row['id']] = ['class_name' => $row['class_name'], 'department_id' => $row['department_id']];
}
$classes_count = count($classes);
error_log("Fetched $classes_count classes", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
$classes_result->free();

// Initialize variables
$selected_year = !empty($academic_years) ? $academic_years[0] : '';
$selected_dept = 0;
$selected_class = 0;
$search_query = '';
$fees = [];
$student_debts = [];
$total_fees = 0;
$total_debts = 0;
$total_payments = 0;

// Handle AJAX requests for filtering and export
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && isset($_POST['csrf_token'])) {
    // Validate CSRF token
    $csrf_valid = false;
    $csrf_check_query = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    if ($csrf_stmt = $conn->prepare($csrf_check_query)) {
        $csrf_stmt->bind_param("is", $_SESSION['user_id'], $_POST['csrf_token']);
        $csrf_stmt->execute();
        $csrf_result = $csrf_stmt->get_result();
        $csrf_valid = $csrf_result->num_rows > 0;
        $csrf_stmt->close();
    }
    if (!$csrf_valid) {
        error_log("Invalid CSRF token for action {$_POST['action']}", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['error' => 'Invalid CSRF token']);
        exit;
    }

    header('Content-Type: application/json');
    if ($_POST['action'] === 'filter_classes') {
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $filtered_classes = [];
        if ($dept_id > 0) {
            foreach ($classes as $id => $class) {
                if ($class['department_id'] == $dept_id) {
                    $filtered_classes[] = ['id' => $id, 'class_name' => $class['class_name']];
                }
            }
        } else {
            foreach ($classes as $id => $class) {
                $filtered_classes[] = ['id' => $id, 'class_name' => $class['class_name']];
            }
        }
        error_log("Filtered " . count($filtered_classes) . " classes for dept_id=$dept_id", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
        echo json_encode(['success' => true, 'classes' => $filtered_classes]);
        exit;
    }
    if ($_POST['action'] === 'filter_data') {
        $academic_year = trim($_POST['academic_year'] ?? '');
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $class_id = (int)($_POST['class_id'] ?? 0);
        $search_query = trim($_POST['search_query'] ?? '');
        
        // Fetch fees
        $fees_query = "SELECT f.id, f.fee_type, f.amount, f.due_date, f.academic_year, f.department, c.class_name, u.name as learner_name,
                              CASE WHEN f.class_id IS NOT NULL THEN 'Class' WHEN f.user_id IS NOT NULL THEN 'Learner' ELSE 'School' END as target_type,
                              COALESCE(SUM(d.amount_due), 0) as outstanding_debt,
                              COALESCE(SUM(p.amount_paid), 0) as amount_paid
                       FROM fees f
                       LEFT JOIN classes c ON f.class_id = c.id
                       LEFT JOIN users u ON f.user_id = u.id
                       LEFT JOIN debts d ON f.id = d.fee_id
                       LEFT JOIN payments p ON f.id = p.fee_id
                       WHERE f.academic_year = ?";
        $params = [$academic_year];
        $types = "s";
        if ($dept_id > 0) {
            $fees_query .= " AND f.department = ?";
            $types .= "s";
            $params[] = $departments[$dept_id]['enum_name'];
        }
        if ($class_id > 0) {
            $fees_query .= " AND f.class_id = ?";
            $types .= "i";
            $params[] = $class_id;
        }
        if ($search_query) {
            $fees_query .= " AND (f.fee_type LIKE ? OR u.name LIKE ?)";
            $types .= "ss";
            $search_param = "%$search_query%";
            $params[] = $search_param;
            $params[] = $search_param;
        }
        $fees_query .= " GROUP BY f.id ORDER BY f.due_date";
        $fees_stmt = $conn->prepare($fees_query);
        if ($fees_stmt) {
            if ($params) {
                $fees_stmt->bind_param($types, ...$params);
            }
            $fees_stmt->execute();
            $fees_result = $fees_stmt->get_result();
            $fees = [];
            $total_fees = 0;
            $total_debts = 0;
            $total_payments = 0;
            while ($row = $fees_result->fetch_assoc()) {
                $fees[] = $row;
                $total_fees += $row['amount'];
                $total_debts += $row['outstanding_debt'];
                $total_payments += $row['amount_paid'];
            }
            $fees_stmt->close();
        } else {
            error_log("Fees query prepare failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        }

        // Fetch student debts
        $debts_query = "SELECT u.id as student_id, u.name as student_name, f.id as fee_id, f.fee_type, f.amount, f.academic_year, f.department,
                               c.class_name, d.amount_due, COALESCE(SUM(p.amount_paid), 0) as amount_paid
                        FROM users u
                        JOIN debts d ON u.id = d.user_id
                        JOIN fees f ON d.fee_id = f.id
                        LEFT JOIN classes c ON f.class_id = c.id
                        LEFT JOIN payments p ON d.fee_id = p.fee_id AND d.user_id = p.user_id
                        LEFT JOIN student_classes sc ON f.class_id = sc.class_id AND sc.user_id = d.user_id AND sc.academic_year = f.academic_year
                        WHERE f.academic_year = ? AND u.role = 'student' AND u.status = 'active'";
        $params = [$academic_year];
        $types = "s";
        if ($dept_id > 0) {
            $debts_query .= " AND f.department = ?";
            $types .= "s";
            $params[] = $departments[$dept_id]['enum_name'];
        }
        if ($class_id > 0) {
            $debts_query .= " AND f.class_id = ?";
            $types .= "i";
            $params[] = $class_id;
        }
        if ($search_query) {
            $debts_query .= " AND u.name LIKE ?";
            $types .= "s";
            $search_param = "%$search_query%";
            $params[] = $search_param;
        }
        $debts_query .= " GROUP BY u.id, f.id ORDER BY u.name, f.due_date";
        $debts_stmt = $conn->prepare($debts_query);
        if ($debts_stmt) {
            if ($params) {
                $debts_stmt->bind_param($types, ...$params);
            }
            $debts_stmt->execute();
            $debts_result = $debts_stmt->get_result();
            $student_debts = [];
            while ($row = $debts_result->fetch_assoc()) {
                $student_debts[] = $row;
            }
            $debts_stmt->close();
        } else {
            error_log("Student debts query prepare failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
        }

        error_log("Filtered fees: " . count($fees) . ", debts: " . count($student_debts) . " for academic_year=$academic_year, dept_id=$dept_id, class_id=$class_id, search_query=$search_query", 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log');
        log_activity($_SESSION['user_id'], 'filtered_fees_dashboard', "Filtered data for academic_year=$academic_year, dept_id=$dept_id, class_id=$class_id, search_query=$search_query, Total Fees: ₵$total_fees, Total Debts: ₵$total_debts, Total Payments: ₵$total_payments", $conn);
        echo json_encode([
            'success' => true,
            'fees' => $fees,
            'student_debts' => $student_debts,
            'total_fees' => $total_fees,
            'total_debts' => $total_debts,
            'total_payments' => $total_payments
        ]);
        exit;
    }
    if ($_POST['action'] === 'log_error') {
        $error_message = trim($_POST['message'] ?? 'Unknown client error');
        log_activity($_SESSION['user_id'], 'client_error', "Client-side error: $error_message", $conn);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($_POST['action'] === 'delete_fee') {
        $fee_id = (int)$_POST['fee_id'];
        
        $conn->begin_transaction();
        try {
            $delete_payments = "DELETE FROM payments WHERE fee_id = ?";
            if ($stmt = $conn->prepare($delete_payments)) {
                $stmt->bind_param("i", $fee_id);
                $stmt->execute();
                $stmt->close();
            } else {
                throw new Exception("Prepare failed for delete payments: " . $conn->error);
            }
            
            $delete_debts = "DELETE FROM debts WHERE fee_id = ?";
            if ($stmt = $conn->prepare($delete_debts)) {
                $stmt->bind_param("i", $fee_id);
                $stmt->execute();
                $stmt->close();
            } else {
                throw new Exception("Prepare failed for delete debts: " . $conn->error);
            }
            
            $delete_fee = "DELETE FROM fees WHERE id = ?";
            if ($stmt = $conn->prepare($delete_fee)) {
                $stmt->bind_param("i", $fee_id);
                $stmt->execute();
                $stmt->close();
            } else {
                throw new Exception("Prepare failed for delete fee: " . $conn->error);
            }
            
            $conn->commit();
            
            log_activity($_SESSION['user_id'], 'delete_fee', "Deleted fee ID: $fee_id", $conn);
            
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            $conn->rollback();
            error_log("Delete fee failed: " . $e->getMessage(), 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
            echo json_encode(['error' => 'Failed to delete fee: ' . $e->getMessage()]);
        }
        exit;
    }
}

// Initial data fetch
if ($selected_year) {
    $fees_query = "SELECT f.id, f.fee_type, f.amount, f.due_date, f.academic_year, f.department, c.class_name, u.name as learner_name,
                          CASE WHEN f.class_id IS NOT NULL THEN 'Class' WHEN f.user_id IS NOT NULL THEN 'Learner' ELSE 'School' END as target_type,
                          COALESCE(SUM(d.amount_due), 0) as outstanding_debt,
                          COALESCE(SUM(p.amount_paid), 0) as amount_paid
                   FROM fees f
                   LEFT JOIN classes c ON f.class_id = c.id
                   LEFT JOIN users u ON f.user_id = u.id
                   LEFT JOIN debts d ON f.id = d.fee_id
                   LEFT JOIN payments p ON f.id = p.fee_id
                   WHERE f.academic_year = ?
                   GROUP BY f.id
                   ORDER BY f.due_date";
    if ($fees_stmt = $conn->prepare($fees_query)) {
        $fees_stmt->bind_param("s", $selected_year);
        $fees_stmt->execute();
        $fees_result = $fees_stmt->get_result();
        while ($row = $fees_result->fetch_assoc()) {
            $fees[] = $row;
            $total_fees += $row['amount'];
            $total_debts += $row['outstanding_debt'];
            $total_payments += $row['amount_paid'];
        }
        $fees_stmt->close();
    } else {
        error_log("Initial fees query prepare failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    }

    $debts_query = "SELECT u.id as student_id, u.name as student_name, f.id as fee_id, f.fee_type, f.amount, f.academic_year, f.department,
                           c.class_name, d.amount_due, COALESCE(SUM(p.amount_paid), 0) as amount_paid
                    FROM users u
                    JOIN debts d ON u.id = d.user_id
                    JOIN fees f ON d.fee_id = f.id
                    LEFT JOIN classes c ON f.class_id = c.id
                    LEFT JOIN payments p ON d.fee_id = p.fee_id AND d.user_id = p.user_id
                    LEFT JOIN student_classes sc ON f.class_id = sc.class_id AND sc.user_id = d.user_id AND sc.academic_year = f.academic_year
                    WHERE f.academic_year = ? AND u.role = 'student' AND u.status = 'active'
                    GROUP BY u.id, f.id
                    ORDER BY u.name, f.due_date";
    if ($debts_stmt = $conn->prepare($debts_query)) {
        $debts_stmt->bind_param("s", $selected_year);
        $debts_stmt->execute();
        $debts_result = $debts_stmt->get_result();
        while ($row = $debts_result->fetch_assoc()) {
            $student_debts[] = $row;
        }
        $debts_stmt->close();
    } else {
        error_log("Initial student debts query prepare failed: " . $conn->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log');
    }

    log_activity($_SESSION['user_id'], 'viewed_fees_dashboard', "Initial load for academic_year=$selected_year, Total Fees: ₵$total_fees, Total Debts: ₵$total_debts, Total Payments: ₵$total_payments", $conn);
}

// Handle CSV export
if (isset($_GET['export']) && $_GET['export'] === 'csv' && isset($_GET['academic_year'])) {
    $academic_year = trim($_GET['academic_year']);
    $dept_id = (int)($_GET['department_id'] ?? 0);
    $class_id = (int)($_GET['class_id'] ?? 0);
    $search_query = trim($_GET['search_query'] ?? '');

    $fees_query = "SELECT f.id, f.fee_type, f.amount, f.due_date, f.academic_year, f.department, c.class_name, u.name as learner_name,
                          CASE WHEN f.class_id IS NOT NULL THEN 'Class' WHEN f.user_id IS NOT NULL THEN 'Learner' ELSE 'School' END as target_type,
                          COALESCE(SUM(d.amount_due), 0) as outstanding_debt,
                          COALESCE(SUM(p.amount_paid), 0) as amount_paid
                   FROM fees f
                   LEFT JOIN classes c ON f.class_id = c.id
                   LEFT JOIN users u ON f.user_id = u.id
                   LEFT JOIN debts d ON f.id = d.fee_id
                   LEFT JOIN payments p ON f.id = p.fee_id
                   WHERE f.academic_year = ?";
    $params = [$academic_year];
    $types = "s";
    if ($dept_id > 0) {
        $fees_query .= " AND f.department = ?";
        $types .= "s";
        $params[] = $departments[$dept_id]['enum_name'];
    }
    if ($class_id > 0) {
        $fees_query .= " AND f.class_id = ?";
        $types .= "i";
        $params[] = $class_id;
    }
    if ($search_query) {
        $fees_query .= " AND (f.fee_type LIKE ? OR u.name LIKE ?)";
        $types .= "ss";
        $search_param = "%$search_query%";
        $params[] = $search_param;
        $params[] = $search_param;
    }
    $fees_query .= " GROUP BY f.id ORDER BY f.due_date";
    $fees_stmt = $conn->prepare($fees_query);
    $export_fees = [];
    if ($fees_stmt) {
        if ($params) {
            $fees_stmt->bind_param($types, ...$params);
        }
        $fees_stmt->execute();
        $fees_result = $fees_stmt->get_result();
        while ($row = $fees_result->fetch_assoc()) {
            $export_fees[] = $row;
        }
        $fees_stmt->close();
    }

    $debts_query = "SELECT u.id as student_id, u.name as student_name, f.id as fee_id, f.fee_type, f.amount, f.academic_year, f.department,
                           c.class_name, d.amount_due, COALESCE(SUM(p.amount_paid), 0) as amount_paid
                    FROM users u
                    JOIN debts d ON u.id = d.user_id
                    JOIN fees f ON d.fee_id = f.id
                    LEFT JOIN classes c ON f.class_id = c.id
                    LEFT JOIN payments p ON d.fee_id = p.fee_id AND d.user_id = p.user_id
                    LEFT JOIN student_classes sc ON f.class_id = sc.class_id AND sc.user_id = d.user_id AND sc.academic_year = f.academic_year
                    WHERE f.academic_year = ? AND u.role = 'student' AND u.status = 'active'";
    $params = [$academic_year];
    $types = "s";
    if ($dept_id > 0) {
        $debts_query .= " AND f.department = ?";
        $types .= "s";
        $params[] = $departments[$dept_id]['enum_name'];
    }
    if ($class_id > 0) {
        $debts_query .= " AND f.class_id = ?";
        $types .= "i";
        $params[] = $class_id;
    }
    if ($search_query) {
        $debts_query .= " AND u.name LIKE ?";
        $types .= "s";
        $search_param = "%$search_query%";
        $params[] = $search_param;
    }
    $debts_query .= " GROUP BY u.id, f.id ORDER BY u.name, f.due_date";
    $debts_stmt = $conn->prepare($debts_query);
    $export_debts = [];
    if ($debts_stmt) {
        if ($params) {
            $debts_stmt->bind_param($types, ...$params);
        }
        $debts_stmt->execute();
        $debts_result = $debts_stmt->get_result();
        while ($row = $debts_result->fetch_assoc()) {
            $export_debts[] = $row;
        }
        $debts_stmt->close();
    }

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment;filename="fees_report_' . $academic_year . '_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Fees Summary']);
    fputcsv($output, ['Fee Type', 'Target', 'Class', 'Learner', 'Department', 'Amount (GH₵)', 'Outstanding Debt (GH₵)', 'Amount Paid (GH₵)', 'Due Date', 'Academic Year']);
    foreach ($export_fees as $fee) {
        fputcsv($output, [
            $fee['fee_type'],
            $fee['target_type'],
            $fee['class_name'] ?? 'N/A',
            $fee['learner_name'] ?? 'N/A',
            $fee['department'],
            number_format($fee['amount'], 2),
            number_format($fee['outstanding_debt'], 2),
            number_format($fee['amount_paid'], 2),
            $fee['due_date'],
            $fee['academic_year']
        ]);
    }
    fputcsv($output, []);
    fputcsv($output, ['Student Debt and Payment Details']);
    fputcsv($output, ['Student', 'Fee Type', 'Department', 'Class', 'Amount (GH₵)', 'Outstanding Debt (GH₵)', 'Amount Paid (GH₵)', 'Academic Year']);
    foreach ($export_debts as $debt) {
        fputcsv($output, [
            $debt['student_name'],
            $debt['fee_type'],
            $debt['department'],
            $debt['class_name'] ?? 'N/A',
            number_format($debt['amount'], 2),
            number_format($debt['amount_due'], 2),
            number_format($debt['amount_paid'], 2),
            $debt['academic_year']
        ]);
    }
    fclose($output);
    log_activity($_SESSION['user_id'], 'export_csv', "Exported fees report for academic_year=$academic_year, dept_id=$dept_id, class_id=$class_id, search_query=$search_query", $conn);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Admin dashboard for managing fees, debts, and payments at Queen's Paradise Montessori School.">
    <meta name="keywords" content="Queen's Paradise Montessori School, fees, payments, debts, admin dashboard">
    <meta name="author" content="Queen's Paradise Montessori School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Fees & Payments Dashboard - Queen's Paradise Montessori School">
    <meta property="og:description" content="Admin interface for managing fees, debts, and payments with filtering and export options.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/fees_payments.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Fees & Payments Dashboard - Queen's Paradise Montessori School">
    <meta name="twitter:description" content="Admin interface for managing fees, debts, and payments with filtering and export options.">
    <title>Fees & Payments Dashboard - Queen's Paradise Montessori School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #ff6200;
            --secondary-color: #ff8c00;
            --accent-color: #ffd700;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }
        body {
            background-color: #f9fafb;
            font-family: 'Inter', sans-serif;
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 1.5rem;
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 1rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(255, 98, 0, 0.25);
        }
        .btn-primary {
            background: linear-gradient(to right, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 8px;
            margin-right: 0.5rem;
        }
        .btn-primary:hover {
            background: linear-gradient(to right, #e55a00, #e08300);
            animation: animate__pulse 0.5s;
        }
        .stats-card {
            border-radius: 10px;
            padding: 1.5rem;
            color: #fff;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .stats-card.fees-charged {
            background: var(--primary-color);
        }
        .stats-card.unpaid-fees {
            background: #dc3545;
        }
        .stats-card.fees-paid {
            background: #28a745;
        }
        .filter-section {
            background-color: #fff;
            padding: 1rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 1rem;
        }
        .alert {
            border-radius: 8px;
        }
        .table-responsive {
            border-radius: 8px;
            overflow-x: auto;
        }
        .table th, .table td {
            vertical-align: middle;
        }
        .table th {
            background-color: #f8f9fa;
        }
        .loading-spinner {
            display: none;
            margin-left: 10px;
        }
        .button-section {
            margin-top: 1rem;
        }
        .print-table {
            display: none;
        }
        @media print {
            body {
                background: white;
            }
            .card, .filter-section, .alert, .button-section, .stats-card {
                display: none;
            }
            .print-section {
                display: block;
            }
            .print-table {
                display: table;
                width: 100%;
                border-collapse: collapse;
                font-size: 12pt;
            }
            .print-table th, .print-table td {
                border: 1px solid #000;
                padding: 8px;
                text-align: left;
            }
            .print-table th {
                background-color: #f0f0f0;
                font-weight: bold;
            }
            .print-header {
                text-align: center;
                margin-bottom: 20px;
            }
            .print-header img {
                max-width: 120px;
            }
            .print-footer {
                text-align: center;
                margin-top: 20px;
                font-size: 0.8rem;
            }
            @page {
                size: A4;
                margin: 1cm;
            }
        }
        @media (max-width: 767.98px) {
            .filter-section {
                padding: 0.5rem;
            }
            .card-header h3 {
                font-size: 1.2rem;
            }
            .form-label {
                font-size: 0.9rem;
            }
            .btn {
                font-size: 0.9rem;
                padding: 0.5rem;
                width: 100%;
                margin-bottom: 0.5rem;
            }
            .button-section {
                text-align: center;
            }
            .table {
                font-size: 0.9rem;
            }
            .stats-card {
                min-height: auto;
                margin-bottom: 1rem;
            }
        }
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

    <div class="container my-4">
        <section class="card mb-4 animate__animated animate__fadeIn">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-money-check-alt me-2"></i>Fees & Payments Dashboard</h3>
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['success_msg'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($_SESSION['success_msg']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <?php unset($_SESSION['success_msg']); ?>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['error_msg'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($_SESSION['error_msg']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        <?php unset($_SESSION['error_msg']); ?>
                    </div>
                <?php endif; ?>
                <?php if (empty($academic_years)): ?>
                    <div class="alert alert-warning">No academic years found in the database. Please add academic years.</div>
                <?php endif; ?>
                <?php if (empty($departments)): ?>
                    <div class="alert alert-warning">No departments found in the database. Please add departments.</div>
                <?php endif; ?>
                <?php if (empty($classes)): ?>
                    <div class="alert alert-warning">No classes found in the database. Please add classes.</div>
                <?php endif; ?>
                <?php if (empty($fees) && empty($student_debts)): ?>
                    <div class="alert alert-warning">No fees or debts found for the selected academic year. Try different filters.</div>
                <?php endif; ?>

                <!-- Filter Section -->
                <div class="filter-section">
                    <form method="POST" class="needs-validation" novalidate id="filter-form" aria-label="Filter fees and debts">
                        <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="academic_year" class="form-label">Academic Year</label>
                                <select name="academic_year" id="academic_year" class="form-select" required>
                                    <option value="">Select Academic Year</option>
                                    <?php foreach ($academic_years as $year): ?>
                                        <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $selected_year === $year ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($year); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select an academic year.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="department_id" class="form-label">Department</label>
                                <select name="department_id" id="department_id" class="form-select">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $id => $dept): ?>
                                        <option value="<?php echo htmlspecialchars($id); ?>">
                                            <?php echo htmlspecialchars($dept['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="class_id" class="form-label">Class</label>
                                <select name="class_id" id="class_id" class="form-select">
                                    <option value="">All Classes</option>
                                    <?php foreach ($classes as $id => $class): ?>
                                        <option value="<?php echo htmlspecialchars($id); ?>" data-dept="<?php echo htmlspecialchars($class['department_id']); ?>">
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="search_query" class="form-label">Search</label>
                                <input type="text" id="search_query" class="form-control" placeholder="Enter fee type or student name...">
                            </div>
                            <div class="col-md-6 button-section">
                                <button type="button" class="btn btn-primary" id="filter_btn">
                                    <i class="fas fa-filter me-1"></i>Apply Filters
                                    <span class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                </button>
                                <button type="button" class="btn btn-primary" id="print_btn">
                                    <i class="fas fa-print me-1"></i>Print Report
                                    <span class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                                </button>
                                <a href="?export=csv&academic_year=<?php echo urlencode($selected_year); ?>" class="btn btn-primary" id="export_btn">
                                    <i class="fas fa-file-csv me-1"></i>Export to CSV
                                </a>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Stats Section -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="stats-card fees-charged">
                            <div>
                                <h5>Total Fees Charged <i class="fas fa-info-circle" data-bs-toggle="tooltip" title="Total amount of fees assigned for the selected academic year."></i></h5>
                                <p id="total_fees">₵<?php echo number_format($total_fees, 2); ?></p>
                            </div>
                            <small>All fees billed to learners or classes.</small>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stats-card unpaid-fees">
                            <div>
                                <h5>Total Unpaid Fees <i class="fas fa-info-circle" data-bs-toggle="tooltip" title="Total outstanding fees that remain unpaid."></i></h5>
                                <p id="total_debts">₵<?php echo number_format($total_debts, 2); ?></p>
                            </div>
                            <small>Fees still owed by learners.</small>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="stats-card fees-paid">
                            <div>
                                <h5>Total Fees Paid <i class="fas fa-info-circle" data-bs-toggle="tooltip" title="Total amount of fees paid by learners."></i></h5>
                                <p id="total_payments">₵<?php echo number_format($total_payments, 2); ?></p>
                            </div>
                            <small>Payments received for the academic year.</small>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="mb-3 d-flex flex-wrap gap-2 button-section">
                    <a href="add_fee.php" class="btn btn-primary"><i class="fas fa-plus me-1"></i>Add Fee</a>
                    <a href="bulk_reminder.php" class="btn btn-primary"><i class="fas fa-sms me-1"></i>Bulk Reminder</a>
                    <a href="view_debts.php" class="btn btn-primary"><i class="fas fa-exclamation-triangle me-1"></i>View Debts</a>
                    <a href="view_payments.php" class="btn btn-primary"><i class="fas fa-list me-1"></i>View Payments</a>
                </div>

                <!-- Fees Table -->
                <h4 class="mb-3">Fees Summary</h4>
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="fees_table">
                        <thead>
                            <tr>
                                <th>Fee Type</th>
                                <th>Target</th>
                                <th>Class</th>
                                <th>Learner</th>
                                <th>Department</th>
                                <th>Amount (GH₵)</th>
                                <th>Outstanding Debt (GH₵)</th>
                                <th>Amount Paid (GH₵)</th>
                                <th>Due Date</th>
                                <th>Academic Year</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fees as $fee): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($fee['fee_type']); ?></td>
                                    <td><?php echo htmlspecialchars($fee['target_type']); ?></td>
                                    <td><?php echo htmlspecialchars($fee['class_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($fee['learner_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($fee['department']); ?></td>
                                    <td><?php echo number_format($fee['amount'], 2); ?></td>
                                    <td><?php echo number_format($fee['outstanding_debt'], 2); ?></td>
                                    <td><?php echo number_format($fee['amount_paid'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($fee['due_date']); ?></td>
                                    <td><?php echo htmlspecialchars($fee['academic_year']); ?></td>
                                    <td>
                                        <a href="pay_fee.php?fee_id=<?php echo $fee['id']; ?>" class="btn btn-success btn-sm" title="Pay Fee" aria-label="Pay Fee">
                                            <i class="fas fa-money-bill-wave"></i> Pay Fee
                                        </a>
                                        <button class="btn btn-danger btn-sm delete-fee" data-fee-id="<?php echo $fee['id']; ?>" title="Delete Fee" aria-label="Delete Fee">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Student Debts Table -->
                <h4 class="mb-3 mt-5">Student Debt and Payment Details</h4>
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="debts_table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Fee Type</th>
                                <th>Department</th>
                                <th>Class</th>
                                <th>Amount (GH₵)</th>
                                <th>Outstanding Debt (GH₵)</th>
                                <th>Amount Paid (GH₵)</th>
                                <th>Academic Year</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($student_debts as $debt): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($debt['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($debt['fee_type']); ?></td>
                                    <td><?php echo htmlspecialchars($debt['department']); ?></td>
                                    <td><?php echo htmlspecialchars($debt['class_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($debt['amount'], 2); ?></td>
                                    <td><?php echo number_format($debt['amount_due'], 2); ?></td>
                                    <td><?php echo number_format($debt['amount_paid'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($debt['academic_year']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Print Section -->
                <div class="print-section">
                    <div class="print-header">
                        <?php
                        $logo_path = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/logo.png';
                        if (file_exists($logo_path)) {
                            echo '<img src="/assets/images/logo.png" alt="Queen\'s Paradise Montessori School Logo" style="width: 100px; height: auto;">';
                        } else {
                            echo '<div style="font-size: 1rem; color: #555;">[School Logo]</div>';
                        }
                        ?>
                        <h1>Queen's Paradise Montessori School Fees Report</h1>
                        <p>Academic Year: <?php echo htmlspecialchars($selected_year); ?> - <?php echo date('F j, Y, g:i A T'); ?></p>
                        <p>P.O. Box 123, Accra, Ghana | info@qpmsedu.com</p>
                    </div>
                    <h4>Fees Summary</h4>
                    <table class="print-table">
                        <thead>
                            <tr>
                                <th>Fee Type</th>
                                <th>Target</th>
                                <th>Class</th>
                                <th>Learner</th>
                                <th>Department</th>
                                <th>Amount (GH₵)</th>
                                <th>Outstanding Debt (GH₵)</th>
                                <th>Amount Paid (GH₵)</th>
                                <th>Due Date</th>
                                <th>Academic Year</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($fees as $fee): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($fee['fee_type']); ?></td>
                                    <td><?php echo htmlspecialchars($fee['target_type']); ?></td>
                                    <td><?php echo htmlspecialchars($fee['class_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($fee['learner_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars($fee['department']); ?></td>
                                    <td><?php echo number_format($fee['amount'], 2); ?></td>
                                    <td><?php echo number_format($fee['outstanding_debt'], 2); ?></td>
                                    <td><?php echo number_format($fee['amount_paid'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($fee['due_date']); ?></td>
                                    <td><?php echo htmlspecialchars($fee['academic_year']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <h4 class="mt-4">Student Debt and Payment Details</h4>
                    <table class="print-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Fee Type</th>
                                <th>Department</th>
                                <th>Class</th>
                                <th>Amount (GH₵)</th>
                                <th>Outstanding Debt (GH₵)</th>
                                <th>Amount Paid (GH₵)</th>
                                <th>Academic Year</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($student_debts as $debt): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($debt['student_name']); ?></td>
                                    <td><?php echo htmlspecialchars($debt['fee_type']); ?></td>
                                    <td><?php echo htmlspecialchars($debt['department']); ?></td>
                                    <td><?php echo htmlspecialchars($debt['class_name'] ?? 'N/A'); ?></td>
                                    <td><?php echo number_format($debt['amount'], 2); ?></td>
                                    <td><?php echo number_format($debt['amount_due'], 2); ?></td>
                                    <td><?php echo number_format($debt['amount_paid'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($debt['academic_year']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="print-footer">
                        <p>Generated by Queen's Paradise Montessori School Management System - <?php echo date('F j, Y, g:i A T'); ?></p>
                    </div>
                </div>
            </div>
        </section>
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

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script>
        $(document).ready(function() {
            try {
                // Initialize Bootstrap validation
                const form = document.getElementById('filter-form');
                form.addEventListener('submit', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    form.classList.add('was-validated');
                }, false);

                // Initialize tooltips
                const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
                tooltipTriggerList.forEach(function(tooltipTriggerEl) {
                    new bootstrap.Tooltip(tooltipTriggerEl);
                });

                // Filter classes via AJAX
                function filterClasses() {
                    const deptId = $('#department_id').val();
                    const academicYear = $('#academic_year').val();
                    if (!academicYear) {
                        $('#class_id').html('<option value="">All Classes</option>');
                        return;
                    }
                    $.ajax({
                        url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                        type: 'POST',
                        dataType: 'json',
                        data: {
                            action: 'filter_classes',
                            department_id: deptId,
                            csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                        },
                        success: function(response) {
                            if (response.error) {
                                console.error('Class filter error:', response.error);
                                alert('Error filtering classes: ' + response.error);
                                logClientError('Class filter AJAX error: ' + response.error);
                                return;
                            }
                            let options = '<option value="">All Classes</option>';
                            response.classes.forEach(cls => {
                                options += `<option value="${cls.id}" data-dept="${deptId}">${cls.class_name}</option>`;
                            });
                            $('#class_id').html(options);
                            filterData();
                        },
                        error: function(xhr, status, error) {
                            console.error('Class filter AJAX error:', status, error);
                            alert('Failed to load classes. Please try again.');
                            logClientError('Class filter AJAX failed: ' + status + ' - ' + error);
                        }
                    });
                }

                // Filter fees and debts via AJAX
                function filterData() {
                    const academicYear = $('#academic_year').val();
                    const deptId = $('#department_id').val();
                    const classId = $('#class_id').val();
                    const searchQuery = $('#search_query').val();
                    if (!academicYear) {
                        $('#fees_table tbody').html('<tr><td colspan="11" class="text-center">Please select an academic year.</td></tr>');
                        $('#debts_table tbody').html('<tr><td colspan="8" class="text-center">Please select an academic year.</td></tr>');
                        $('.print-table').html('');
                        $('#total_fees').text('₵0.00');
                        $('#total_debts').text('₵0.00');
                        $('#total_payments').text('₵0.00');
                        $('#export_btn').attr('href', '?export=csv&academic_year=' + encodeURIComponent(academicYear));
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
                            csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                        },
                        success: function(response) {
                            $('#filter_btn .loading-spinner').hide();
                            if (response.error) {
                                console.error('Data filter error:', response.error);
                                alert('Error filtering data: ' + response.error);
                                logClientError('Data filter AJAX error: ' + response.error);
                                return;
                            }
                            // Update fees table
                            let feesTbody = '';
                            if (response.fees.length === 0) {
                                feesTbody = '<tr><td colspan="11" class="text-center">No fees found for the selected filters.</td></tr>';
                            } else {
                                response.fees.forEach(fee => {
                                    feesTbody += `
                                        <tr>
                                            <td>${fee.fee_type}</td>
                                            <td>${fee.target_type}</td>
                                            <td>${fee.class_name || 'N/A'}</td>
                                            <td>${fee.learner_name || 'N/A'}</td>
                                            <td>${fee.department}</td>
                                            <td>${parseFloat(fee.amount).toFixed(2)}</td>
                                            <td>${parseFloat(fee.outstanding_debt).toFixed(2)}</td>
                                            <td>${parseFloat(fee.amount_paid).toFixed(2)}</td>
                                            <td>${fee.due_date}</td>
                                            <td>${fee.academic_year}</td>
                                            <td>
                                                <a href="pay_fee.php?fee_id=${fee.id}" class="btn btn-success btn-sm" title="Pay Fee" aria-label="Pay Fee">
                                                    <i class="fas fa-money-bill-wave"></i> Pay Fee
                                                </a>
                                                <button class="btn btn-danger btn-sm delete-fee" data-fee-id="${fee.id}" title="Delete Fee" aria-label="Delete Fee">
                                                    <i class="fas fa-trash"></i> Delete
                                                </button>
                                            </td>
                                        </tr>`;
                                });
                            }
                            $('#fees_table tbody').html(feesTbody);

                            // Update debts table
                            let debtsTbody = '';
                            if (response.student_debts.length === 0) {
                                debtsTbody = '<tr><td colspan="8" class="text-center">No debts found for the selected filters.</td></tr>';
                            } else {
                                response.student_debts.forEach(debt => {
                                    debtsTbody += `
                                        <tr>
                                            <td>${debt.student_name}</td>
                                            <td>${debt.fee_type}</td>
                                            <td>${debt.department}</td>
                                            <td>${debt.class_name || 'N/A'}</td>
                                            <td>${parseFloat(debt.amount).toFixed(2)}</td>
                                            <td>${parseFloat(debt.amount_due).toFixed(2)}</td>
                                            <td>${parseFloat(debt.amount_paid).toFixed(2)}</td>
                                            <td>${debt.academic_year}</td>
                                        </tr>`;
                                });
                            }
                            $('#debts_table tbody').html(debtsTbody);

                            // Update stats
                            $('#total_fees').text('₵' + parseFloat(response.total_fees).toFixed(2));
                            $('#total_debts').text('₵' + parseFloat(response.total_debts).toFixed(2));
                            $('#total_payments').text('₵' + parseFloat(response.total_payments).toFixed(2));

                            // Update export link
                            $('#export_btn').attr('href', `?export=csv&academic_year=${encodeURIComponent(academicYear)}&department_id=${deptId}&class_id=${classId}&search_query=${encodeURIComponent(searchQuery)}`);

                            // Update print tables
                            let feesPrint = `
                                <thead>
                                    <tr>
                                        <th>Fee Type</th>
                                        <th>Target</th>
                                        <th>Class</th>
                                        <th>Learner</th>
                                        <th>Department</th>
                                        <th>Amount (GH₵)</th>
                                        <th>Outstanding Debt (GH₵)</th>
                                        <th>Amount Paid (GH₵)</th>
                                        <th>Due Date</th>
                                        <th>Academic Year</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${response.fees.map(fee => `
                                        <tr>
                                            <td>${fee.fee_type}</td>
                                            <td>${fee.target_type}</td>
                                            <td>${fee.class_name || 'N/A'}</td>
                                            <td>${fee.learner_name || 'N/A'}</td>
                                            <td>${fee.department}</td>
                                            <td>${parseFloat(fee.amount).toFixed(2)}</td>
                                            <td>${parseFloat(fee.outstanding_debt).toFixed(2)}</td>
                                            <td>${parseFloat(fee.amount_paid).toFixed(2)}</td>
                                            <td>${fee.due_date}</td>
                                            <td>${fee.academic_year}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>`;
                            let debtsPrint = `
                                <thead>
                                    <tr>
                                        <th>Student</th>
                                        <th>Fee Type</th>
                                        <th>Department</th>
                                        <th>Class</th>
                                        <th>Amount (GH₵)</th>
                                        <th>Outstanding Debt (GH₵)</th>
                                        <th>Amount Paid (GH₵)</th>
                                        <th>Academic Year</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${response.student_debts.map(debt => `
                                        <tr>
                                            <td>${debt.student_name}</td>
                                            <td>${debt.fee_type}</td>
                                            <td>${debt.department}</td>
                                            <td>${debt.class_name || 'N/A'}</td>
                                            <td>${parseFloat(debt.amount).toFixed(2)}</td>
                                            <td>${parseFloat(debt.amount_due).toFixed(2)}</td>
                                            <td>${parseFloat(debt.amount_paid).toFixed(2)}</td>
                                            <td>${debt.academic_year}</td>
                                        </tr>
                                    `).join('')}
                                </tbody>`;
                            $('.print-table').eq(0).html(feesPrint);
                            $('.print-table').eq(1).html(debtsPrint);
                        },
                        error: function(xhr, status, error) {
                            $('#filter_btn .loading-spinner').hide();
                            console.error('Data filter AJAX error:', status, error);
                            alert('Failed to load data. Please try again.');
                            logClientError('Data filter AJAX failed: ' + status + ' - ' + error);
                        }
                    });
                }

                // Log client-side errors to server
                function logClientError(message) {
                    $.ajax({
                        url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                        type: 'POST',
                        data: {
                            action: 'log_error',
                            message: message,
                            csrf_token: '<?php echo htmlspecialchars($csrf_token); ?>'
                        }
                    });
                }

                // Handle filter button click
                $('#filter_btn').click(function() {
                    filterData();
                });

                // Handle print button
                $('#print_btn').click(function() {
                    $('#print_btn .loading-spinner').show();
                    setTimeout(() => {
                        window.print();
                        $('#print_btn .loading-spinner').hide();
                    }, 500);
                });

                // Handle search input (debounced)
                let searchTimeout;
                $('#search_query').on('input', function() {
                    clearTimeout(searchTimeout);
                    searchTimeout = setTimeout(filterData, 500);
                });

                // Initialize filters
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

                // Handle delete fee
                $(document).on('click', '.delete-fee', function() {
                    const feeId = $(this).data('fee-id');
                    if (confirm('Are you sure you want to delete this fee? This will also delete related debts and payments.')) {
                        $.ajax({
                            url: '<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>',
                            type: 'POST',
                            dataType: 'json',
                            data: {
                                action: 'delete_fee',
                                fee_id: feeId,
                                csrf_token: $('#csrf_token').val()
                            },
                            success: function(response) {
                                if (response.success) {
                                    alert('Fee deleted successfully.');
                                    filterData(); // Refresh data
                                } else {
                                    alert('Error deleting fee: ' + (response.error || 'Unknown error'));
                                    logClientError('Delete fee AJAX error: ' + (response.error || 'Unknown'));
                                }
                            },
                            error: function(xhr, status, error) {
                                alert('Failed to delete fee. Please try again.');
                                logClientError('Delete fee AJAX failed: ' + status + ' - ' + error);
                            }
                        });
                    }
                });
            } catch (e) {
                console.error('JavaScript initialization error:', e);
                logClientError('JavaScript initialization error: ' + e.message);
            }
        });
    </script>
</body>
</html>