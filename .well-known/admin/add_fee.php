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

// Arkesel API constants (replace with actual)
define('ARKESEL_API_KEY', 'your_arkesel_api_key_here');
define('ARKESEL_SENDER', 'EduSync');

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token.';
        header("Location: add_fee.php");
        exit();
    }

    $target_type = $_POST['target_type'] ?? '';
    $fee_type = trim($_POST['fee_type'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $due_date = $_POST['due_date'] ?? '';
    $academic_year_id = intval($_POST['academic_year_id'] ?? 0);
    $term_id = intval($_POST['term_id'] ?? 0);
    $class_id = intval($_POST['class_id'] ?? 0);
    $user_id = intval($_POST['user_id'] ?? 0);
    $is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
    $department = trim($_POST['department'] ?? '');
    $send_sms = isset($_POST['send_sms']) ? true : false;

    // Validations
    if (!in_array($target_type, ['school', 'class', 'individual']) || empty($fee_type) || $amount <= 0 || empty($due_date) || $academic_year_id <= 0) {
        $_SESSION['error'] = 'Invalid input. Please fill all required fields correctly.';
        header("Location: add_fee.php");
        exit();
    }

    if ($target_type === 'class' && $class_id <= 0) {
        $_SESSION['error'] = 'Please select a class.';
        header("Location: add_fee.php");
        exit();
    }

    if ($target_type === 'individual' && $user_id <= 0) {
        $_SESSION['error'] = 'Please select a student.';
        header("Location: add_fee.php");
        exit();
    }

    // Insert fee
    $stmt = $db->prepare("INSERT INTO fees (target_type, class_id, user_id, department, fee_type, amount, due_date, academic_year_id, term_id, is_recurring, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    $stmt->execute([$target_type, $class_id ?: null, $user_id ?: null, $department, $fee_type, $amount, $due_date, $academic_year_id, $term_id ?: null, $is_recurring]);
    $fee_id = $db->lastInsertId();

    log_activity($_SESSION['user_id'], 'add_fee', "Added new fee ID: $fee_id of type $target_type");

    // Get affected students
    $students = [];
    if ($target_type === 'individual') {
        $students = [$user_id];
    } elseif ($target_type === 'class') {
        $stmt = $db->prepare("SELECT user_id FROM student_classes WHERE class_id = ? AND academic_year_id = ?");
        $stmt->execute([$class_id, $academic_year_id]);
        $students = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'user_id');
    } elseif ($target_type === 'school') {
        $stmt = $db->prepare("SELECT DISTINCT user_id FROM student_classes WHERE academic_year_id = ?");
        $stmt->execute([$academic_year_id]);
        $students = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'user_id');
    }

    if (empty($students)) {
        $_SESSION['error'] = 'No students found for this fee target.';
        header("Location: add_fee.php");
        exit();
    }

    // Create debts for each student
    $debt_stmt = $db->prepare("INSERT INTO debts (user_id, fee_id, amount_due, academic_year_id, term_id, class_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())");
    foreach ($students as $student_id) {
        $debt_stmt->execute([$student_id, $fee_id, $amount, $academic_year_id, $term_id ?: null, $class_id ?: null]);
    }

    // Send SMS if checked
    if ($send_sms) {
        // Get parents' phones for affected students
        $phones = [];
        $stmt = $db->prepare("SELECT DISTINCT u.phone_number FROM parent_student_mapping psm JOIN users u ON psm.parent_id = u.id WHERE psm.student_id = ? AND u.phone_number IS NOT NULL");
        foreach ($students as $student_id) {
            $stmt->execute([$student_id]);
            $phones = array_merge($phones, array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'phone_number'));
        }
        $phones = array_unique(array_filter($phones));

        if (!empty($phones)) {
            $sms_message = "New fee added: $fee_type - Amount: $amount, Due: $due_date. Please check your portal for details.";

            $endpoint = 'https://sms.arkesel.com/api/v2/sms/send';
            $data = [
                'sender' => ARKESEL_SENDER,
                'message' => $sms_message,
                'recipients' => $phones
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
                log_activity($_SESSION['user_id'], 'send_fee_sms', "Sent SMS for new fee ID: $fee_id to " . count($phones) . " parents");
                $_SESSION['success'] = 'Fee added and SMS sent successfully.';
            } else {
                $_SESSION['success'] = 'Fee added successfully, but SMS failed: ' . $response;
            }
        } else {
            $_SESSION['success'] = 'Fee added successfully, but no parent phone numbers found for SMS.';
        }
    } else {
        $_SESSION['success'] = 'Fee added successfully.';
    }

    header("Location: add_fee.php");
    exit();
}

// Fetch academic years
$years_stmt = $db->query("SELECT id, year FROM academic_years ORDER BY year DESC");
$academic_years = $years_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch terms (for current year, but show all)
$terms_stmt = $db->query("SELECT id, name FROM terms ORDER BY start_date ASC");
$terms = $terms_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch classes
$classes_stmt = $db->query("SELECT id, class_name FROM classes ORDER BY class_name ASC");
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch students
$students_stmt = $db->query("SELECT id, name FROM users WHERE role = 'student' ORDER BY name ASC");
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="add-fee">
        <h1 class="page-title">Add New Fee</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <form method="POST" class="fee-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <label for="target_type">Target Type:</label>
            <select name="target_type" id="target_type" required onchange="toggleTargetFields(this.value)">
                <option value="">Select Target</option>
                <option value="school">School-wide</option>
                <option value="class">Class</option>
                <option value="individual">Individual Student</option>
            </select>

            <div id="class-field" style="display: none;">
                <label for="class_id">Select Class:</label>
                <select name="class_id" id="class_id">
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div id="user-field" style="display: none;">
                <label for="user_id">Select Student:</label>
                <select name="user_id" id="user_id">
                    <option value="">Select Student</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <label for="fee_type">Fee Type:</label>
            <input type="text" name="fee_type" id="fee_type" required placeholder="e.g., Tuition, Library Fee">

            <label for="amount">Amount:</label>
            <input type="number" name="amount" id="amount" step="0.01" min="0" required placeholder="Enter amount">

            <label for="due_date">Due Date:</label>
            <input type="date" name="due_date" id="due_date" required min="<?php echo date('Y-m-d'); ?>">

            <label for="academic_year_id">Academic Year:</label>
            <select name="academic_year_id" id="academic_year_id" required>
                <option value="">Select Academic Year</option>
                <?php foreach ($academic_years as $year): ?>
                    <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['year']); ?></option>
                <?php endforeach; ?>
            </select>

            <label for="term_id">Term (Optional):</label>
            <select name="term_id" id="term_id">
                <option value="">No Term</option>
                <?php foreach ($terms as $term): ?>
                    <option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['name']); ?></option>
                <?php endforeach; ?>
            </select>

            <label for="department">Department (Optional):</label>
            <input type="text" name="department" id="department" placeholder="e.g., Primary">

            <label><input type="checkbox" name="is_recurring"> Is Recurring?</label>

            <label><input type="checkbox" name="send_sms" checked> Send SMS Notification to Parents</label>

            <button type="submit">Add Fee</button>
        </form>
    </section>

    <script>
        function toggleTargetFields(type) {
            document.getElementById('class-field').style.display = (type === 'class') ? 'block' : 'none';
            document.getElementById('user-field').style.display = (type === 'individual') ? 'block' : 'none';
        }
    </script>

    <style>
        /* Add Fee Styles */
        .add-fee {
            padding: 20px;
            max-width: 600px;
            margin: 0 auto;
        }

        .fee-form {
            background: var(--primary-white);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .fee-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .fee-form input, .fee-form select {
            width: 100%;
            padding: 10px;
            margin-bottom: 16px;
            border: 1px solid var(--accent-slate-gray);
            border-radius: 4px;
        }

        .fee-form button {
            background: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            width: 100%;
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