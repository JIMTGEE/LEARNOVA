```php
<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Check database connection
if (!$conn || $conn->connect_error) {
    $temp_log = function($user_id, $action, $details) {
        $log_file = $_SERVER['DOCUMENT_ROOT'] . '/logs/error.log';
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] [user_id: $user_id] $action: $details\n", 3, $log_file);
    };
    $temp_log(0, 'db_error', "Database connection failed: " . ($conn->connect_error ?? 'Connection is null'));
    die("System error: Database connection failed. Please contact the administrator.");
}

// Define fallback log_activity function
if (!function_exists('log_activity')) {
    function log_activity($user_id, $action, $details, $conn = null) {
        $log_file = $_SERVER['DOCUMENT_ROOT'] . '/logs/activity.log';
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] [user_id: $user_id] $action: $details\n", 3, $log_file);
    }
}

// Include log_activity.php if it exists
$log_activity_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (file_exists($log_activity_path)) {
    try {
        require_once($log_activity_path);
    } catch (Exception $e) {
        log_activity(0, 'include_error', "Failed to include log_activity.php: " . $e->getMessage());
    }
} else {
    log_activity(0, 'file_missing', "log_activity.php not found at $log_activity_path");
}

// Check admin authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    log_activity($user_id, 'access_denied', 'Attempted to access pay_fee.php without admin role', $conn);
    $_SESSION['error_msg'] = "Please log in as an admin to access this page.";
    header('Location: /login.php');
    exit;
}

// Log page access
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
log_activity($_SESSION['user_id'], 'page_access', "Admin accessed pay_fee.php, IP: $ip_address, User Agent: $user_agent", $conn);

// Generate and store CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
$csrf_query = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)";
if ($csrf_stmt = $conn->prepare($csrf_query)) {
    $csrf_stmt->bind_param("iss", $_SESSION['user_id'], $csrf_token, $csrf_expiry);
    $csrf_stmt->execute();
    $csrf_stmt->close();
} else {
    log_activity($_SESSION['user_id'], 'csrf_error', "Failed to store CSRF token: " . $conn->error);
}
$_SESSION['csrf_token'] = $csrf_token;

// Check notifications table schema for category column
$can_send_notifications = false;
$category_check_query = "SHOW COLUMNS FROM notifications LIKE 'category'";
$category_result = $conn->query($category_check_query);
if ($category_result && $category_result->num_rows > 0) {
    $category_info = $category_result->fetch_assoc();
    $category_type = $category_info['Type'];
    if (stripos($category_type, "enum") !== false) {
        preg_match_all("/'([^']+)'/", $category_type, $matches);
        $valid_categories = $matches[1];
        if (in_array('financial', $valid_categories)) {
            $can_send_notifications = true;
        } else {
            log_activity($_SESSION['user_id'], 'schema_warning', "Notifications table 'category' column does not include 'financial'. Valid values: " . implode(', ', $valid_categories), $conn);
        }
    } else {
        $can_send_notifications = true;
    }
    $category_result->free();
} else {
    log_activity($_SESSION['user_id'], 'schema_error', "Failed to check notifications table schema: " . ($conn->error ?? 'Query failed'), $conn);
}

// Get fee_id from GET
$fee_id = isset($_GET['fee_id']) ? (int)$_GET['fee_id'] : 0;
if ($fee_id <= 0) {
    $_SESSION['error_msg'] = "Invalid fee ID.";
    header('Location: /admin/fees_payments.php');
    exit;
}

// Fetch fee details
$fee_query = "SELECT f.id, f.fee_type, f.amount, f.due_date, f.academic_year, f.department, f.target_type, f.class_id, f.user_id, 
                     c.class_name, u.name AS learner_name
              FROM fees f
              LEFT JOIN classes c ON f.class_id = c.id
              LEFT JOIN users u ON f.user_id = u.id
              WHERE f.id = ?";
if ($stmt = $conn->prepare($fee_query)) {
    $stmt->bind_param("i", $fee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $fee = $result->fetch_assoc();
    $stmt->close();
} else {
    log_activity($_SESSION['user_id'], 'db_error', "Failed to prepare fee query: " . $conn->error);
    $_SESSION['error_msg'] = "Failed to fetch fee details.";
    header('Location: /admin/fees_payments.php');
    exit;
}
if (!$fee) {
    $_SESSION['error_msg'] = "Fee not found.";
    header('Location: /admin/fees_payments.php');
    exit;
}

// Determine if fee is for individual or class
$is_individual = ($fee['target_type'] === 'individual');

// Fetch debts
$debts = [];
$debt_query = "SELECT d.id AS debt_id, d.user_id, d.amount_due, u.name AS student_name, d.class_id, c.class_name
               FROM debts d
               JOIN users u ON d.user_id = u.id
               LEFT JOIN classes c ON d.class_id = c.id
               WHERE d.fee_id = ? AND d.amount_due > 0
               ORDER BY u.name ASC";
if ($stmt = $conn->prepare($debt_query)) {
    $stmt->bind_param("i", $fee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $debts[] = $row;
    }
    $stmt->close();
} else {
    log_activity($_SESSION['user_id'], 'db_error', "Failed to prepare debt query: " . $conn->error);
    $_SESSION['error_msg'] = "Failed to fetch student debt.";
    header('Location: /admin/fees_payments.php');
    exit;
}

if (empty($debts)) {
    $_SESSION['error_msg'] = "No outstanding debt found for this fee.";
    header('Location: /admin/fees_payments.php');
    exit;
}

$selected_debt = $is_individual ? $debts[0] : null;

// Fetch recent payments for history preview
$recent_payments = [];
$payment_history_query = "SELECT p.user_id, p.amount_paid, p.payment_date, p.payment_method, p.receipt_number, u.name AS student_name
                         FROM payments p
                         JOIN users u ON p.user_id = u.id
                         WHERE p.fee_id = ?
                         ORDER BY p.created_at DESC
                         LIMIT 5";
if ($stmt = $conn->prepare($payment_history_query)) {
    $stmt->bind_param("i", $fee_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_payments[] = $row;
    }
    $stmt->close();
} else {
    log_activity($_SESSION['user_id'], 'db_error', "Failed to prepare payment history query: " . $conn->error);
}

// Generate receipt number
$generated_receipt_number = 'QPMS-' . date('Ymd') . '-' . sprintf("%03d", rand(1, 999));

// Handle form submission
$success_msg = '';
$error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_payment']) && isset($_POST['csrf_token'])) {
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
        $error_msg = "Invalid or expired session. Please try again.";
        log_activity($_SESSION['user_id'], 'validation_error', "Invalid CSRF token for payment submission");
    } else {
        $debt_id = (int)($_POST['debt_id'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? 0);
        $amount_paid = isset($_POST['amount_paid']) ? (float)$_POST['amount_paid'] : 0.00;
        $payment_date = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $payment_method = trim($_POST['payment_method'] ?? '');
        $receipt_number = trim($_POST['receipt_number'] ?? '');

        // Log submission data
        $submission_data = json_encode([
            'debt_id' => $debt_id,
            'user_id' => $user_id,
            'fee_id' => $fee_id,
            'amount_paid' => $amount_paid,
            'payment_date' => $payment_date,
            'payment_method' => $payment_method,
            'receipt_number' => $receipt_number
        ]);
        log_activity($_SESSION['user_id'], 'form_submission', "Submitted payment form: $submission_data", $conn);

        // Validation
        $error_msg = '';
        $verify_debt_query = "SELECT amount_due FROM debts WHERE id = ? AND fee_id = ? AND user_id = ? AND amount_due > 0";
        if ($stmt = $conn->prepare($verify_debt_query)) {
            $stmt->bind_param("iii", $debt_id, $fee_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $verify_debt = $result->fetch_assoc();
            $stmt->close();
            if (!$verify_debt) {
                $error_msg = "Invalid debt or user selected.";
                log_activity($_SESSION['user_id'], 'validation_error', "Invalid debt_id: $debt_id or user_id: $user_id for fee_id: $fee_id", $conn);
            } elseif ($amount_paid <= 0 || $amount_paid > $verify_debt['amount_due']) {
                $error_msg = "Payment amount must be greater than 0 and up to the outstanding balance (GH₵" . number_format($verify_debt['amount_due'], 2) . ").";
                log_activity($_SESSION['user_id'], 'validation_error', "Invalid amount_paid: $amount_paid (due: {$verify_debt['amount_due']})", $conn);
            }
        } else {
            $error_msg = "Failed to verify debt.";
            log_activity($_SESSION['user_id'], 'db_error', "Failed to prepare verify debt query: " . $conn->error, $conn);
        }

        if (empty($error_msg) && (empty($payment_date) || !preg_match("/^\d{4}-\d{2}-\d{2}$/", $payment_date))) {
            $error_msg = "Please select a valid payment date.";
            log_activity($_SESSION['user_id'], 'validation_error', "Invalid payment_date: $payment_date", $conn);
        }

        if (empty($error_msg) && (empty($payment_method) || !in_array($payment_method, ['cash', 'bank', 'mobile_money', 'check']))) {
            $error_msg = "Please select a valid payment method.";
            log_activity($_SESSION['user_id'], 'validation_error', "Invalid payment_method: $payment_method", $conn);
        }

        // Process payment if no errors
        if (empty($error_msg)) {
            $conn->begin_transaction();
            try {
                // Insert payment
                $insert_payment = "INSERT INTO payments (user_id, fee_id, amount_paid, payment_date, payment_method, receipt_number, class_id, recorded_by, created_at, updated_at) 
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";
                if ($stmt = $conn->prepare($insert_payment)) {
                    $class_id = $fee['class_id'];
                    $recorded_by = $_SESSION['user_id'];
                    $stmt->bind_param("iidsisii", $user_id, $fee_id, $amount_paid, $payment_date, $payment_method, $receipt_number, $class_id, $recorded_by);
                    if (!$stmt->execute()) {
                        throw new Exception("Payment insertion failed: " . $stmt->error);
                    }
                    $payment_id = $stmt->insert_id;
                    log_activity($_SESSION['user_id'], 'payment_inserted', "Inserted payment ID: $payment_id for user_id: $user_id, fee_id: $fee_id, amount: $amount_paid", $conn);
                    $stmt->close();
                } else {
                    throw new Exception("Failed to prepare payment insertion query: " . $conn->error);
                }

                // Update debt
                $update_debt = "UPDATE debts SET amount_due = amount_due - ? WHERE id = ? AND user_id = ? AND fee_id = ?";
                if ($stmt = $conn->prepare($update_debt)) {
                    $stmt->bind_param("diii", $amount_paid, $debt_id, $user_id, $fee_id);
                    if (!$stmt->execute()) {
                        throw new Exception("Debt update failed: " . $stmt->error);
                    }
                    log_activity($_SESSION['user_id'], 'debt_updated', "Updated debt ID: $debt_id for user_id: $user_id, fee_id: $fee_id, reduced by: $amount_paid", $conn);
                    $stmt->close();
                } else {
                    throw new Exception("Failed to prepare debt update query: " . $conn->error);
                }

                // Send notification if schema allows
                if ($can_send_notifications) {
                    $notify_query = "INSERT INTO notifications (user_id, target_role, title, message, type, category, created_at) 
                                     VALUES (?, 'student', ?, ?, 'info', 'financial', NOW())";
                    if ($notify_stmt = $conn->prepare($notify_query)) {
                        $title = "Payment Recorded for " . $fee['fee_type'];
                        $message = "A payment of GH₵$amount_paid has been recorded on $payment_date for " . $fee['fee_type'] . ". Receipt Number: $receipt_number. Outstanding balance: GH₵" . number_format($verify_debt['amount_due'] - $amount_paid, 2);
                        $notify_stmt->bind_param("iss", $user_id, $title, $message);
                        if (!$notify_stmt->execute()) {
                            throw new Exception("Notification insertion failed: " . $notify_stmt->error);
                        }
                        $notify_stmt->close();
                        log_activity($_SESSION['user_id'], 'notification_sent', "Sent notification to user_id: $user_id for payment ID: $payment_id", $conn);
                    } else {
                        throw new Exception("Failed to prepare notification query: " . $conn->error);
                    }
                } else {
                    log_activity($_SESSION['user_id'], 'notification_skipped', "Notifications skipped due to invalid 'category' value in schema for payment ID: $payment_id", $conn);
                }

                $conn->commit();
                log_activity($_SESSION['user_id'], 'success', "Payment committed successfully for payment ID: $payment_id", $conn);

                // Regenerate CSRF token
                $csrf_token = bin2hex(random_bytes(32));
                $csrf_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
                $csrf_query = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)";
                if ($csrf_stmt = $conn->prepare($csrf_query)) {
                    $csrf_stmt->bind_param("iss", $_SESSION['user_id'], $csrf_token, $csrf_expiry);
                    $csrf_stmt->execute();
                    $csrf_stmt->close();
                }
                $_SESSION['csrf_token'] = $csrf_token;
                $_SESSION['success_msg'] = "Payment of GH₵$amount_paid recorded successfully.";
                header("Location: /admin/fees_payments.php");
                exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = "Failed to record payment: " . $e->getMessage();
                log_activity($_SESSION['user_id'], 'error', "Failed to record payment: " . $e->getMessage(), $conn);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Admin page for paying student fees in Queen's Paradise Montessori School.">
    <meta name="keywords" content="Queen's Paradise Montessori School, pay fees, financial management">
    <meta name="author" content="Queen's Paradise Montessori School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Pay Fees - Queen's Paradise Montessori School">
    <meta property="og:description" content="Admin interface for recording payments on student fees.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/pay_fee.php">
    <title>Pay Fees - Queen's Paradise Montessori School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #ff6200;
            --secondary-color: #ff8c00;
            --accent-color: #1e88e5;
            --success-color: #28a745;
            --error-color: #dc3545;
            --dark-bg: #1a202c;
            --dark-text: #e2e8f0;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background-color: #f4f7fa;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #2d3748;
            transition: background-color 0.3s, color 0.3s;
        }
        body.dark-mode {
            background-color: var(--dark-bg);
            color: var(--dark-text);
        }
        .container {
            max-width: 900px;
            margin-top: 2rem;
            margin-bottom: 2rem;
        }
        .card {
            border: none;
            border-radius: 16px;
            box-shadow: var(--shadow);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.15);
        }
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 16px 16px 0 0;
            padding: 1.75rem;
            text-align: center;
            position: relative;
        }
        .card-header h3 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 600;
        }
        .card-body {
            padding: 2rem;
            background: white;
            border-radius: 0 0 16px 16px;
        }
        .dark-mode .card-body {
            background: #2d3748;
            color: var(--dark-text);
        }
        .form-control, .form-select {
            border-radius: 10px;
            padding: 0.75rem 1rem;
            font-size: 0.95rem;
            border: 1px solid #d1d5db;
            transition: border-color 0.2s, box-shadow 0.2s, transform 0.2s;
        }
        .dark-mode .form-control, .dark-mode .form-select {
            background: #4a5568;
            border-color: #718096;
            color: var(--dark-text);
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 98, 0, 0.1);
            outline: none;
            transform: scale(1.02);
        }
        .form-control.is-valid {
            border-color: var(--success-color);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3E%3Cpath fill='%2328a745' d='M2.3 6.73L.6 4.53c-.4-.4-.4-1 0-1.4l.7-.7c.4-.4 1-.4 1.4 0L3 3.74l2.3-2.3c.4-.4 1-.4 1.4 0l.7.7c.4.4.4 1 0 1.4L3.7 6.73c-.4.4-1 .4-1.4 0z'/%3E%3C/svg%3E");
            background-position: right 0.75rem center;
            background-size: 1rem;
        }
        .form-control.is-invalid {
            border-color: var(--error-color);
        }
        .form-label {
            font-weight: 600;
            color: #2d3748;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        .dark-mode .form-label {
            color: var(--dark-text);
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border: none;
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            font-size: 0.95rem;
            transition: background 0.3s, transform 0.2s, box-shadow 0.2s;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #e55a00, #e08300);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }
        .btn-primary:disabled {
            background: #d1d5db;
            cursor: not-allowed;
        }
        .btn-secondary {
            border-radius: 10px;
            padding: 0.75rem 1.5rem;
            background-color: #6b7280;
            border-color: #6b7280;
            font-weight: 600;
            transition: background-color 0.3s, transform 0.2s;
        }
        .btn-secondary:hover {
            background-color: #4b5563;
            border-color: #4b5563;
            transform: translateY(-2px);
        }
        .btn-outline-primary {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        .btn-outline-primary:hover {
            background: var(--primary-color);
            color: white;
        }
        .wizard-nav {
            display: flex;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            border-bottom: 2px solid #e2e8f0;
            position: relative;
        }
        .wizard-nav .step {
            flex: 1;
            text-align: center;
            padding: 0.5rem;
            font-weight: 600;
            color: #6b7280;
            transition: color 0.3s;
            position: relative;
        }
        .wizard-nav .step.active {
            color: var(--primary-color);
        }
        .wizard-nav .step.completed {
            color: var(--success-color);
        }
        .wizard-nav .step::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 100%;
            height: 2px;
            background: var(--primary-color);
            transform: scaleX(0);
            transition: transform 0.3s;
        }
        .wizard-nav .step.active::after {
            transform: scaleX(1);
        }
        .wizard-step {
            display: none;
        }
        .wizard-step.active {
            display: block;
        }
        .payment-history {
            background-color: #e6f3ff;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            cursor: pointer;
            transition: max-height 0.3s ease;
            overflow: hidden;
        }
        .payment-history.collapsed {
            max-height: 60px;
        }
        .payment-history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .payment-history-header i {
            transition: transform 0.3s ease;
        }
        .payment-history.collapsed .payment-history-header i {
            transform: rotate(180deg);
        }
        .student-info {
            background-color: #e6f3ff;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
        }
        .balance-preview {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: #4b5563;
        }
        .dark-mode .balance-preview {
            color: #a0aec0;
        }
        .progress-container {
            display: none;
            margin-bottom: 1rem;
        }
        .progress {
            height: 8px;
            border-radius: 10px;
        }
        .modal-content {
            border-radius: 16px;
            box-shadow: var(--shadow);
        }
        .modal-header {
            background: var(--accent-color);
            color: white;
            border-radius: 16px 16px 0 0;
        }
        .modal-body {
            font-size: 0.95rem;
        }
        .modal-footer .btn {
            border-radius: 10px;
        }
        .sticky-action-bar {
            position: sticky;
            bottom: 0;
            background: white;
            padding: 1rem;
            border-top: 1px solid #e2e8f0;
            display: none;
        }
        .dark-mode .sticky-action-bar {
            background: #2d3748;
            border-top: 1px solid #4a5568;
        }
        .theme-toggle {
            position: fixed;
            top: 1rem;
            right: 1rem;
            z-index: 1000;
        }
        .theme-toggle i {
            font-size: 1.2rem;
        }
        .receipt-preview {
            font-size: 0.85rem;
            color: #4b5563;
            margin-top: 0.5rem;
        }
        .dark-mode .receipt-preview {
            color: #a0aec0;
        }
        .toastr-container {
            z-index: 10000;
        }
        @media (max-width: 767.98px) {
            .container {
                padding: 0 1rem;
            }
            .card-body {
                padding: 1.5rem;
            }
            .card-header h3 {
                font-size: 1.25rem;
            }
            .form-label {
                font-size: 0.85rem;
            }
            .wizard-nav {
                flex-direction: column;
                align-items: flex-start;
            }
            .wizard-nav .step {
                width: 100%;
                text-align: left;
            }
            .wizard-nav .step::after {
                width: 0;
            }
            .wizard-nav .step.active::after {
                width: 100%;
            }
            .sticky-action-bar {
                display: flex;
                justify-content: space-between;
                gap: 0.5rem;
            }
            .btn {
                font-size: 0.9rem;
            }
        }
    </style>
</head>
<body>
    <button class="btn btn-outline-primary theme-toggle" id="theme-toggle" aria-label="Toggle theme">
        <i class="fas fa-moon"></i>
    </button>
    <?php 
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
    if (!file_exists($header_path)) {
        log_activity($_SESSION['user_id'], 'error', "Admin header file missing: $header_path", $conn);
        $_SESSION['error_msg'] = "Error: Admin header template is missing. Please contact the administrator.";
        header("Location: /login.php");
        exit();
    }
    include $header_path; 
    ?>

    <div class="container">
        <section class="card">
            <div class="card-header">
                <h3 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Pay Fee: <?php echo htmlspecialchars($fee['fee_type']); ?></h3>
            </div>
            <div class="card-body">
                <div class="wizard-nav" role="navigation" aria-label="Payment wizard steps">
                    <div class="step active" data-step="1">1. Review Student</div>
                    <div class="step" data-step="2">2. Payment Details</div>
                    <div class="step" data-step="3">3. Confirm</div>
                </div>

                <form method="POST" class="needs-validation" novalidate id="payment-form" aria-label="Record payment">
                    <input type="hidden" name="csrf_token" id="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="submit_payment" value="1">
                    <input type="hidden" name="user_id" id="user_id" value="<?php echo $is_individual ? htmlspecialchars($selected_debt['user_id']) : ''; ?>">
                    <input type="hidden" name="debt_id" id="debt_id" value="<?php echo $is_individual ? htmlspecialchars($selected_debt['debt_id']) : ''; ?>">

                    <!-- Step 1: Review Student -->
                    <div class="wizard-step active" data-step="1">
                        <?php if ($is_individual): ?>
                        <div class="student-info">
                            <h6>Selected Student</h6>
                            <p class="mb-2"><strong>Student:</strong> <span id="student_name"><?php echo htmlspecialchars($selected_debt['student_name']); ?></span></p>
                            <p class="mb-2"><strong>Class:</strong> <span id="class_name"><?php echo htmlspecialchars($selected_debt['class_name'] ?? 'N/A'); ?></span></p>
                            <p class="mb-0"><strong>Outstanding Fee:</strong> GH₵<span id="amount_due"><?php echo number_format($selected_debt['amount_due'], 2); ?></span></p>
                        </div>
                        <?php else: ?>
                        <div class="student-info">
                            <h6>Select Student in <?php echo htmlspecialchars($fee['class_name']); ?></h6>
                            <select id="select_student" class="form-select" required aria-label="Select student to pay for">
                                <option value="">Select a student</option>
                                <?php foreach ($debts as $debt): ?>
                                <option value="<?php echo $debt['debt_id']; ?>"
                                        data-user-id="<?php echo $debt['user_id']; ?>"
                                        data-student-name="<?php echo htmlspecialchars($debt['student_name']); ?>"
                                        data-class-name="<?php echo htmlspecialchars($debt['class_name'] ?? 'N/A'); ?>"
                                        data-amount-due="<?php echo $debt['amount_due']; ?>">
                                    <?php echo htmlspecialchars($debt['student_name']); ?> - GH₵<?php echo number_format($debt['amount_due'], 2); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="selected_student_info" class="mt-3" style="display: none;">
                                <p class="mb-2"><strong>Student:</strong> <span id="student_name"></span></p>
                                <p class="mb-2"><strong>Class:</strong> <span id="class_name"></span></p>
                                <p class="mb-0"><strong>Outstanding Fee:</strong> GH₵<span id="amount_due"></span></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="payment-history" id="payment_history">
                            <div class="payment-history-header">
                                <span><strong>Recent Payments</strong></span>
                                <i class="fas fa-chevron-down"></i>
                            </div>
                            <div class="payment-history-content">
                                <?php if (empty($recent_payments)): ?>
                                    <p>No recent payments found.</p>
                                <?php else: ?>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($recent_payments as $payment): ?>
                                            <li class="list-group-item" data-user-id="<?php echo htmlspecialchars($payment['user_id']); ?>">
                                                <strong><?php echo htmlspecialchars($payment['student_name']); ?>:</strong>
                                                GH₵<?php echo number_format($payment['amount_paid'], 2); ?> on <?php echo htmlspecialchars($payment['payment_date']); ?>
                                                (<?php echo htmlspecialchars($payment['payment_method']); ?>, Receipt: <?php echo htmlspecialchars($payment['receipt_number'] ?? 'N/A'); ?>)
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                    <p id="no_payments" class="mt-2" style="display: none;">No recent payments for this student.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-primary next-step" data-next="2">Next <i class="fas fa-arrow-right ms-1"></i></button>
                            <a href="/admin/fees_payments.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i>Cancel</a>
                        </div>
                    </div>

                    <!-- Step 2: Payment Details -->
                    <div class="wizard-step" data-step="2">
                        <div class="mb-4">
                            <label for="amount_paid" class="form-label">Payment Amount (GH₵)</label>
                            <input type="number" id="amount_paid" name="amount_paid" class="form-control" step="0.01" min="0.01" required value="<?php echo isset($_POST['amount_paid']) ? htmlspecialchars($_POST['amount_paid']) : ($is_individual ? $selected_debt['amount_due'] : ''); ?>" aria-describedby="amount_paid_feedback">
                            <div id="amount_paid_feedback" class="invalid-feedback">Please enter a valid amount greater than 0 and up to the outstanding balance.</div>
                            <div class="balance-preview" id="balance_preview">
                                New Balance: GH₵<span id="new_balance"><?php echo $is_individual ? number_format($selected_debt['amount_due'], 2) : '0.00'; ?></span>
                                (<span id="percentage_paid">0%</span> of debt paid)
                            </div>
                        </div>
                        <div class="mb-4">
                            <label for="payment_date" class="form-label">Payment Date</label>
                            <input type="date" id="payment_date" name="payment_date" class="form-control" value="<?php echo isset($_POST['payment_date']) ? htmlspecialchars($_POST['payment_date']) : date('Y-m-d'); ?>" required aria-describedby="payment_date_feedback">
                            <div id="payment_date_feedback" class="invalid-feedback">Please select a valid payment date.</div>
                        </div>
                        <div class="mb-4">
                            <label for="payment_method" class="form-label">Payment Method</label>
                            <select id="payment_method" name="payment_method" class="form-select" required aria-describedby="payment_method_feedback">
                                <option value="">Select Payment Method</option>
                                <option value="cash" <?php echo isset($_POST['payment_method']) && $_POST['payment_method'] === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="bank" <?php echo isset($_POST['payment_method']) && $_POST['payment_method'] === 'bank' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="mobile_money" <?php echo isset($_POST['payment_method']) && $_POST['payment_method'] === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                                <option value="check" <?php echo isset($_POST['payment_method']) && $_POST['payment_method'] === 'check' ? 'selected' : ''; ?>>Check</option>
                            </select>
                            <div id="payment_method_feedback" class="invalid-feedback">Please select a payment method.</div>
                        </div>
                        <div class="mb-4">
                            <label for="receipt_number" class="form-label">Receipt Number (Optional)</label>
                            <div class="input-group">
                                <input type="text" id="receipt_number" name="receipt_number" class="form-control" maxlength="50" value="<?php echo isset($_POST['receipt_number']) ? htmlspecialchars($_POST['receipt_number']) : ''; ?>" aria-describedby="receipt_number_feedback">
                                <button type="button" class="btn btn-outline-primary" id="generate_receipt"><i class="fas fa-redo"></i> Generate</button>
                            </div>
                            <div id="receipt_number_feedback" class="invalid-feedback">Receipt number must be 50 characters or less.</div>
                            <div class="receipt-preview">Preview: <span id="receipt_preview"><?php echo htmlspecialchars($generated_receipt_number); ?></span></div>
                        </div>
                        <div class="d-flex justify-content-between gap-2">
                            <button type="button" class="btn btn-secondary prev-step" data-prev="1"><i class="fas fa-arrow-left me-1"></i>Back</button>
                            <button type="button" class="btn btn-primary next-step" data-next="3">Next <i class="fas fa-arrow-right ms-1"></i></button>
                        </div>
                    </div>

                    <!-- Step 3: Confirm -->
                    <div class="wizard-step" data-step="3">
                        <div class="payment-summary">
                            <h6>Payment Summary</h6>
                            <p><strong>Student:</strong> <span id="summary_student_name"><?php echo $is_individual ? htmlspecialchars($selected_debt['student_name']) : ''; ?></span></p>
                            <p><strong>Amount:</strong> GH₵<span id="summary_amount"><?php echo $is_individual ? number_format($selected_debt['amount_due'], 2) : '0.00'; ?></span></p>
                            <p><strong>Payment Date:</strong> <span id="summary_payment_date"><?php echo date('Y-m-d'); ?></span></p>
                            <p><strong>Payment Method:</strong> <span id="summary_payment_method">None</span></p>
                            <p><strong>Receipt Number:</strong> <span id="summary_receipt_number">None</span></p>
                        </div>
                        <div class="progress-container" id="progress-container">
                            <div class="progress">
                                <div class="progress-bar bg-primary" role="progressbar" style="width: 0%;" id="progress-bar"></div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between gap-2">
                            <button type="button" class="btn btn-secondary prev-step" data-prev="2"><i class="fas fa-arrow-left me-1"></i>Back</button>
                            <button type="button" class="btn btn-primary" id="submitPaymentBtn" data-bs-toggle="modal" data-bs-target="#confirmModal">
                                <i class="fas fa-save me-1"></i>Record Payment
                                <span class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                            </button>
                        </div>
                    </div>
                </form>

                <div class="sticky-action-bar" id="sticky-action-bar">
                    <button type="button" class="btn btn-primary next-step" id="sticky-next-btn">Next <i class="fas fa-arrow-right ms-1"></i></button>
                    <a href="/admin/fees_payments.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-1"></i>Cancel</a>
                </div>
            </div>
        </section>

        <!-- Confirmation Modal -->
        <div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="confirmModalLabel">Confirm Payment</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <p>Are you sure you want to record this payment? This action cannot be undone.</p>
                        <div class="payment-summary">
                            <h6>Payment Summary</h6>
                            <p><strong>Student:</strong> <span id="modal_summary_student_name"><?php echo $is_individual ? htmlspecialchars($selected_debt['student_name']) : ''; ?></span></p>
                            <p><strong>Amount:</strong> GH₵<span id="modal_summary_amount"><?php echo $is_individual ? number_format($selected_debt['amount_due'], 2) : '0.00'; ?></span></p>
                            <p><strong>Payment Date:</strong> <span id="modal_summary_payment_date"><?php echo date('Y-m-d'); ?></span></p>
                            <p><strong>Payment Method:</strong> <span id="modal_summary_payment_method">None</span></p>
                            <p><strong>Receipt Number:</strong> <span id="modal_summary_receipt_number">None</span></p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" id="confirmSubmit">
                            <i class="fas fa-save me-1"></i>Confirm
                            <span class="loading-spinner"><i class="fas fa-spinner fa-spin"></i></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div aria-live="polite" id="live-region" class="visually-hidden"></div>
    </div>

    <?php 
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    if (!file_exists($footer_path)) {
        log_activity($_SESSION['user_id'], 'error', "Admin footer file missing: $footer_path", $conn);
        $_SESSION['error_msg'] = "Error: Admin footer template is missing. Please contact the administrator.";
        header("Location: /login.php");
        exit();
    }
    include $footer_path;
    $conn->close();
    ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/toastr@2.1.4/build/toastr.min.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize toastr
            toastr.options = {
                closeButton: true,
                progressBar: true,
                positionClass: 'toast-top-right',
                timeOut: 5000
            };

            // Initialize tooltips
            const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
            const tooltipList = [...tooltipTriggerList].map(tooltipTriggerEl => new bootstrap.Tooltip(tooltipTriggerEl));

            // Theme toggle
            $('#theme-toggle').click(function() {
                $('body').toggleClass('dark-mode');
                const icon = $(this).find('i');
                icon.toggleClass('fa-moon fa-sun');
                localStorage.setItem('theme', $('body').hasClass('dark-mode') ? 'dark' : 'light');
            });
            if (localStorage.getItem('theme') === 'dark') {
                $('body').addClass('dark-mode');
                $('#theme-toggle i').removeClass('fa-moon').addClass('fa-sun');
            }

            // Bootstrap form validation
            const form = document.getElementById('payment-form');
            form.addEventListener('submit', function(event) {
                event.preventDefault();
                event.stopPropagation();
                form.classList.add('was-validated');
            }, false);

            // Wizard navigation
            $('.next-step').click(function() {
                const currentStep = $('.wizard-step.active').data('step');
                const nextStep = $(this).data('next');
                if (validateStep(currentStep)) {
                    $('.wizard-step[data-step="' + currentStep + '"]').removeClass('active');
                    $('.wizard-nav .step[data-step="' + currentStep + '"]').removeClass('active').addClass('completed');
                    $('.wizard-step[data-step="' + nextStep + '"]').addClass('active');
                    $('.wizard-nav .step[data-step="' + nextStep + '"]').addClass('active');
                    updateStickyButton(nextStep);
                }
            });

            $('.prev-step').click(function() {
                const currentStep = $('.wizard-step.active').data('step');
                const prevStep = $(this).data('prev');
                $('.wizard-step[data-step="' + currentStep + '"]').removeClass('active');
                $('.wizard-nav .step[data-step="' + currentStep + '"]').removeClass('active');
                $('.wizard-step[data-step="' + prevStep + '"]').addClass('active');
                $('.wizard-nav .step[data-step="' + prevStep + '"]').addClass('active');
                updateStickyButton(prevStep);
            });

            function updateStickyButton(step) {
                const nextBtn = $('#sticky-next-btn');
                if (step == 1) {
                    nextBtn.text('Next').data('next', 2).prop('disabled', false);
                } else if (step == 2) {
                    nextBtn.text('Next').data('next', 3).prop('disabled', false);
                } else if (step == 3) {
                    nextBtn.text('Record Payment').data('next', 'submit').prop('disabled', false);
                }
            }

            function validateStep(step) {
                if (step == 1) {
                    if ($('#select_student').length && !$('#select_student').val()) {
                        $('#select_student').addClass('is-invalid');
                        $('#live-region').text('Please select a student.');
                        toastr.error('Please select a student.');
                        return false;
                    }
                    return true;
                } else if (step == 2) {
                    const amountPaid = parseFloat($('#amount_paid').val()) || 0;
                    const amountDue = parseFloat($('#amount_due').text().replace(/,/g, '')) || 0;
                    const date = $('#payment_date').val();
                    const method = $('#payment_method').val();
                    console.log('Validating Step 2: amountPaid=' + amountPaid + ', amountDue=' + amountDue);
                    if (amountPaid <= 0 || amountPaid > amountDue) {
                        $('#amount_paid').addClass('is-invalid');
                        $('#live-region').text('Please enter a valid amount greater than 0 and up to the outstanding balance (GH₵' + amountDue.toFixed(2) + ').');
                        toastr.error('Please enter a valid amount greater than 0 and up to the outstanding balance (GH₵' + amountDue.toFixed(2) + ').');
                        return false;
                    }
                    if (!date || !date.match(/^\d{4}-\d{2}-\d{2}$/)) {
                        $('#payment_date').addClass('is-invalid');
                        $('#live-region').text('Please select a valid payment date.');
                        toastr.error('Please select a valid payment date.');
                        return false;
                    }
                    if (!['cash', 'bank', 'mobile_money', 'check'].includes(method)) {
                        $('#payment_method').addClass('is-invalid');
                        $('#live-region').text('Please select a payment method.');
                        toastr.error('Please select a payment method.');
                        return false;
                    }
                    $('#amount_paid').addClass('is-valid').removeClass('is-invalid');
                    return true;
                }
                return true;
            }

            // Payment history toggle
            $('.payment-history-header').click(function() {
                $('#payment_history').toggleClass('collapsed');
            });

            // Generate receipt number
            $('#generate_receipt').click(function() {
                const receipt = '<?php echo $generated_receipt_number; ?>';
                $('#receipt_number').val(receipt).trigger('input');
                $('#receipt_preview').text(receipt);
                $('#live-region').text('Receipt number generated: ' + receipt);
            });

            // Debounced input validation
            let validationTimeout;
            function debounceValidation(callback, wait) {
                clearTimeout(validationTimeout);
                validationTimeout = setTimeout(callback, wait);
            }

            $('#amount_paid').on('input', function() {
                debounceValidation(() => {
                    const amountPaid = parseFloat($(this).val()) || 0;
                    const amountDue = parseFloat($('#amount_due').text().replace(/,/g, '')) || 0;
                    console.log('Amount Paid Input: amountPaid=' + amountPaid + ', amountDue=' + amountDue);
                    if (amountPaid > 0 && amountPaid <= amountDue) {
                        $(this).addClass('is-valid').removeClass('is-invalid');
                        $(this).siblings('.invalid-feedback').text('');
                        const newBalance = (amountDue - amountPaid).toFixed(2);
                        const percentage = amountDue > 0 ? ((amountPaid / amountDue) * 100).toFixed(0) : 0;
                        $('#new_balance').text(newBalance);
                        $('#percentage_paid').text(percentage + '%');
                        $('#balance_preview').show();
                        $('#live-region').text('Valid payment amount entered. New balance: GH₵' + newBalance + ' (' + percentage + '% of debt paid)');
                    } else {
                        $(this).addClass('is-invalid').removeClass('is-valid');
                        $(this).siblings('.invalid-feedback').text('Please enter a valid amount greater than 0 and up to the outstanding balance (GH₵' + amountDue.toFixed(2) + ').');
                        $('#balance_preview').hide();
                        $('#live-region').text('Invalid payment amount.');
                    }
                }, 300);
            });

            $('#payment_date').on('change', function() {
                const date = $(this).val();
                if (date && date.match(/^\d{4}-\d{2}-\d{2}$/)) {
                    $(this).addClass('is-valid').removeClass('is-invalid');
                    $(this).siblings('.invalid-feedback').text('');
                    $('#live-region').text('Valid payment date selected.');
                } else {
                    $(this).addClass('is-invalid').removeClass('is-valid');
                    $(this).siblings('.invalid-feedback').text('Please select a valid payment date.');
                    $('#live-region').text('Invalid payment date.');
                }
            });

            $('#payment_method').on('change', function() {
                const method = $(this).val();
                if (['cash', 'bank', 'mobile_money', 'check'].includes(method)) {
                    $(this).addClass('is-valid').removeClass('is-invalid');
                    $(this).siblings('.invalid-feedback').text('');
                    $('#live-region').text('Valid payment method selected.');
                } else {
                    $(this).addClass('is-invalid').removeClass('is-valid');
                    $(this).siblings('.invalid-feedback').text('Please select a payment method.');
                    $('#live-region').text('Invalid payment method.');
                }
            });

            $('#receipt_number').on('input', function() {
                const value = $(this).val();
                if (value.length <= 50) {
                    $(this).addClass('is-valid').removeClass('is-invalid');
                    $(this).siblings('.invalid-feedback').text('');
                    $('#receipt_preview').text(value || '<?php echo $generated_receipt_number; ?>');
                    $('#live-region').text('Receipt number updated.');
                } else {
                    $(this).addClass('is-invalid').removeClass('is-valid');
                    $(this).siblings('.invalid-feedback').text('Receipt number must be 50 characters or less.');
                    $('#live-region').text('Invalid receipt number.');
                }
            });

            // Update payment summary
            function updatePaymentSummary() {
                const studentName = $('#student_name').text();
                const amountPaid = $('#amount_paid').val();
                const paymentDate = $('#payment_date').val();
                const paymentMethod = $('#payment_method option:selected').text();
                const receiptNumber = $('#receipt_number').val() || 'None';
                if (studentName && amountPaid && paymentDate && paymentMethod) {
                    $('#summary_student_name, #modal_summary_student_name').text(studentName);
                    $('#summary_amount, #modal_summary_amount').text(parseFloat(amountPaid).toFixed(2));
                    $('#summary_payment_date, #modal_summary_payment_date').text(paymentDate);
                    $('#summary_payment_method, #modal_summary_payment_method').text(paymentMethod);
                    $('#summary_receipt_number, #modal_summary_receipt_number').text(receiptNumber);
                    $('#live-region').text('Payment summary updated.');
                } else {
                    $('#summary_student_name, #modal_summary_student_name').text(studentName);
                    $('#summary_amount, #modal_summary_amount').text(amountPaid ? parseFloat(amountPaid).toFixed(2) : '0.00');
                    $('#summary_payment_date, #modal_summary_payment_date').text(paymentDate || '');
                    $('#summary_payment_method, #modal_summary_payment_method').text(paymentMethod || 'None');
                    $('#summary_receipt_number, #modal_summary_receipt_number').text(receiptNumber);
                    $('#live-region').text('Payment summary partially updated.');
                }
            }

            // Handle student selection for class fees
            if ($('#select_student').length) {
                $('#select_student').change(function() {
                    var selectedOption = $(this).find('option:selected');
                    var userId = selectedOption.data('user-id');
                    var debtId = $(this).val();
                    var studentName = selectedOption.data('student-name');
                    var className = selectedOption.data('class-name');
                    var amountDue = parseFloat(selectedOption.data('amount-due'));
                    $('#user_id').val(userId);
                    $('#debt_id').val(debtId);
                    $('#student_name').text(studentName);
                    $('#class_name').text(className);
                    $('#amount_due').text(amountDue.toFixed(2));
                    $('#amount_paid').val(amountDue.toFixed(2)).trigger('input');
                    $('#selected_student_info').show();
                    updatePaymentSummary();

                    // Filter payment history
                    if ($('.payment-history-content ul').length) {
                        var historyItems = $('.payment-history-content li').hide().filter(function() {
                            return $(this).data('user-id') == userId;
                        }).show();
                        if (historyItems.length == 0) {
                            $('#no_payments').show();
                        } else {
                            $('#no_payments').hide();
                        }
                    }
                }).trigger('change'); // Trigger for initial selection
            } else {
                // For individual, filter payment history
                if ($('.payment-history-content ul').length) {
                    var userId = $('#user_id').val();
                    var historyItems = $('.payment-history-content li').hide().filter(function() {
                        return $(this).data('user-id') == userId;
                    }).show();
                    if (historyItems.length == 0) {
                        $('#no_payments').show();
                    } else {
                        $('#no_payments').hide();
                    }
                }
            }

            // Client-side validation before opening modal
            $('#submitPaymentBtn, #sticky-next-btn[data-next="submit"]').click(function() {
                const form = $('#payment-form');
                const amountPaid = parseFloat($('#amount_paid').val()) || 0;
                const amountDue = parseFloat($('#amount_due').text().replace(/,/g, '')) || 0;
                console.log('Submit Validation: amountPaid=' + amountPaid + ', amountDue=' + amountDue);
                if (!form[0].checkValidity() || amountPaid <= 0 || amountPaid > amountDue) {
                    form.addClass('was-validated');
                    if (amountPaid <= 0 || amountPaid > amountDue) {
                        $('#amount_paid').addClass('is-invalid');
                        $('#amount_paid_feedback').text('Please enter a valid amount greater than 0 and up to the outstanding balance (GH₵' + amountDue.toFixed(2) + ').');
                        $('#live-region').text('Please enter a valid amount greater than 0 and up to the outstanding balance (GH₵' + amountDue.toFixed(2) + ').');
                        toastr.error('Please enter a valid amount greater than 0 and up to the outstanding balance (GH₵' + amountDue.toFixed(2) + ').');
                    }
                    return;
                }
                updatePaymentSummary();
                $('#confirmModal').modal('show');
            });

            // Handle confirmation modal submit
            $('#confirmSubmit').click(function() {
                const form = $('#payment-form');
                const amountPaid = parseFloat($('#amount_paid').val()) || 0;
                const amountDue = parseFloat($('#amount_due').text().replace(/,/g, '')) || 0;
                if (form[0].checkValidity() && amountPaid > 0 && amountPaid <= amountDue) {
                    $('#progress-container').show();
                    let progress = 0;
                    const progressInterval = setInterval(() => {
                        progress += 33;
                        $('#progress-bar').css('width', progress + '%');
                        if (progress >= 100) clearInterval(progressInterval);
                    }, 500);
                    $('#confirmSubmit').prop('disabled', true).find('.loading-spinner').show();
                    $('#submitPaymentBtn').prop('disabled', true).find('.loading-spinner').show();
                    $('#sticky-next-btn').prop('disabled', true).find('.loading-spinner').show();
                    form.submit();
                } else {
                    form.addClass('was-validated');
                    $('#confirmModal').modal('hide');
                    $('#live-region').text('Form validation failed.');
                    toastr.error('Please complete all required fields and ensure the amount is valid.');
                }
            });

            // Initialize form state
            $('#amount_paid, #payment_date, #payment_method, #receipt_number').on('change input', updatePaymentSummary);
            updatePaymentSummary();
            $('#amount_paid').trigger('input');

            // Show toastr for server-side messages
            <?php if ($success_msg): ?>
                toastr.success('<?php echo htmlspecialchars($success_msg); ?>');
            <?php endif; ?>
            <?php if ($error_msg): ?>
                toastr.error('<?php echo htmlspecialchars($error_msg); ?>');
            <?php endif; ?>
            <?php if (isset($_SESSION['error_msg'])): ?>
                toastr.error('<?php echo htmlspecialchars($_SESSION['error_msg']); ?>');
                <?php unset($_SESSION['error_msg']); ?>
            <?php endif; ?>
        });
    </script>
</body>
</html>
```