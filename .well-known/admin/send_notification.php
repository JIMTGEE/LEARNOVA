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
$db = db(); // Use the helper function to get PDO instance

// Include helpers and functions
require_once '../includes/helpers.php';
require_once '../functions/csrf.php';
require_once '../functions/log_activity.php';

// Assume Arkesel API key is defined in config (replace with actual key)
define('ARKESEL_API_KEY', 'your_arkesel_api_key_here'); // Add to config/arkesel.php or env
define('ARKESEL_SENDER', 'EduSync'); // Sender ID

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token.';
        header("Location: send_notifications.php");
        exit();
    }

    $recipient_role = $_POST['recipient_role'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $message = trim($_POST['message'] ?? '');
    $send_sms = isset($_POST['send_sms']) ? true : false;

    if (!in_array($recipient_role, ['all', 'parent', 'student', 'teacher']) || empty($title) || empty($message)) {
        $_SESSION['error'] = 'Invalid input. Please fill all fields correctly.';
        header("Location: send_notifications.php");
        exit();
    }

    // Fetch users based on role
    $query = "SELECT id, name, phone_number FROM users WHERE role IN (";
    $params = [];
    if ($recipient_role === 'all') {
        $query .= "'parent', 'student', 'teacher')";
    } else {
        $query .= "?)";
        $params[] = $recipient_role;
    }
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        $_SESSION['error'] = 'No users found for the selected role.';
        header("Location: send_notifications.php");
        exit();
    }

    // Insert notifications per user
    $notify_stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, created_at, is_read) VALUES (?, ?, ?, NOW(), 0)");
    foreach ($users as $user) {
        $notify_stmt->execute([$user['id'], $title, $message]);
    }

    log_activity($_SESSION['user_id'], 'send_notification', "Sent notification to role: $recipient_role - Title: $title");

    // Send SMS if checked
    if ($send_sms) {
        $phones = array_filter(array_column($users, 'phone_number')); // Get valid phones
        if (!empty($phones)) {
            // Batch send (Arkesel supports multiple recipients)
            $endpoint = 'https://sms.arkesel.com/api/v2/sms/send';
            $data = [
                'sender' => ARKESEL_SENDER,
                'message' => $message,
                'recipients' => $phones // Array of phones
            ];

            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'api-key: ' . ARKESEL_API_KEY
            ]);

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code === 200) {
                log_activity($_SESSION['user_id'], 'send_sms', "Sent SMS to role: $recipient_role");
                $_SESSION['success'] = 'Notifications and SMS sent successfully.';
            } else {
                $_SESSION['error'] = 'Notifications sent, but SMS failed: ' . $response;
            }
        } else {
            $_SESSION['error'] = 'Notifications sent, but no phone numbers found for SMS.';
        }
    } else {
        $_SESSION['success'] = 'Notifications sent successfully.';
    }

    header("Location: send_notifications.php");
    exit();
}

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="send-notifications">
        <h1 class="page-title">Send Notifications</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <form method="POST" class="notification-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <label for="recipient_role">Recipient Role:</label>
            <select name="recipient_role" id="recipient_role" required>
                <option value="">Select Role</option>
                <option value="all">All (Parents, Students, Teachers)</option>
                <option value="parent">Parents</option>
                <option value="student">Students</option>
                <option value="teacher">Teachers</option>
            </select>

            <label for="title">Title:</label>
            <input type="text" name="title" id="title" required placeholder="Notification Title">

            <label for="message">Message:</label>
            <textarea name="message" id="message" required placeholder="Enter your message here..."></textarea>

            <label><input type="checkbox" name="send_sms"> Send as SMS (to phone numbers on file)</label>

            <button type="submit">Send Notification</button>
        </form>
    </section>

    <style>
        /* Send Notifications Styles */
        .send-notifications {
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }

        .notification-form {
            background: var(--primary-white);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .notification-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .notification-form input[type="text"],
        .notification-form textarea,
        .notification-form select {
            width: 100%;
            padding: 10px;
            margin-bottom: 16px;
            border: 1px solid var(--accent-slate-gray);
            border-radius: 4px;
        }

        .notification-form textarea {
            height: 150px;
        }

        .notification-form button {
            background: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
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