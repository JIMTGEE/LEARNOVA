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

// Handle payment processing (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'process_payment') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    $user_id = intval($_POST['user_id'] ?? 0);
    $debt_id = intval($_POST['debt_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? '');
    $transaction_id = trim($_POST['transaction_id'] ?? '');
    $send_sms = isset($_POST['send_sms']) ? true : false;

    if ($user_id <= 0 || $debt_id <= 0 || $amount <= 0 || empty($payment_method) || !in_array($payment_method, ['cash', 'bank_transfer', 'mobile_money', 'card'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid input. Please check all fields.']);
        exit();
    }

    // Fetch debt
    $debt_stmt = $db->prepare("SELECT * FROM debts WHERE id = ? AND user_id = ? AND status != 'paid'");
    $debt_stmt->execute([$debt_id, $user_id]);
    $debt = $debt_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$debt) {
        echo json_encode(['success' => false, 'message' => 'Debt not found or already paid.']);
        exit();
    }

    if ($amount > $debt['amount_due'] - $debt['amount_paid']) {
        echo json_encode(['success' => false, 'message' => 'Amount exceeds outstanding balance.']);
        exit();
    }

    // Insert payment
    $pay_stmt = $db->prepare("INSERT INTO payments (user_id, debt_id, amount_paid, payment_method, transaction_id, status, payment_date, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'completed', NOW(), NOW(), NOW())");
    $pay_stmt->execute([$user_id, $debt_id, $amount, $payment_method, $transaction_id]);
    $payment_id = $db->lastInsertId();

    // Update debt
    $new_paid = $debt['amount_paid'] + $amount;
    $new_status = ($new_paid >= $debt['amount_due']) ? 'paid' : 'partial';
    $update_debt = $db->prepare("UPDATE debts SET amount_paid = ?, status = ?, updated_at = NOW() WHERE id = ?");
    $update_debt->execute([$new_paid, $new_status, $debt_id]);

    log_activity($_SESSION['user_id'], 'process_payment', "Processed payment ID: $payment_id for debt ID: $debt_id (User: $user_id, Amount: $amount)");

    // Send SMS if checked
    if ($send_sms) {
        // Get parent/student phone
        $phones = [];
        // Parent
        $parent_stmt = $db->prepare("SELECT u.phone_number FROM parent_student_mapping psm JOIN users u ON psm.parent_id = u.id WHERE psm.student_id = ? AND u.phone_number IS NOT NULL");
        $parent_stmt->execute([$user_id]);
        $phones = array_merge($phones, array_column($parent_stmt->fetchAll(PDO::FETCH_ASSOC), 'phone_number'));
        // Student
        $student_stmt = $db->prepare("SELECT phone_number FROM users WHERE id = ? AND phone_number IS NOT NULL");
        $student_stmt->execute([$user_id]);
        $student_phone = $student_stmt->fetchColumn();
        if ($student_phone) $phones[] = $student_phone;

        $phones = array_unique(array_filter($phones));

        if (!empty($phones)) {
            $sms_message = "Payment confirmation: Amount $amount paid for fee. Transaction ID: $transaction_id. Outstanding: " . ($debt['amount_due'] - $new_paid) . ". Thank you!";

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
                log_activity($_SESSION['user_id'], 'send_payment_sms', "Sent SMS confirmation for payment ID: $payment_id to " . count($phones) . " recipients");
                echo json_encode(['success' => true, 'message' => 'Payment processed and SMS sent successfully.']);
            } else {
                echo json_encode(['success' => true, 'message' => 'Payment processed successfully, but SMS failed: ' . $response]);
            }
        } else {
            echo json_encode(['success' => true, 'message' => 'Payment processed successfully, but no phone numbers found for SMS.']);
        }
    } else {
        echo json_encode(['success' => true, 'message' => 'Payment processed successfully.']);
    }
    exit();
}

// Handle fetch debts for user (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_debts') {
    $user_id = intval($_GET['user_id'] ?? 0);
    if ($user_id > 0) {
        $stmt = $db->prepare("SELECT d.id, d.amount_due, d.amount_paid, f.fee_type, f.due_date, ay.year AS academic_year, t.name AS term 
                              FROM debts d 
                              JOIN fees f ON d.fee_id = f.id 
                              JOIN academic_years ay ON d.academic_year_id = ay.id 
                              LEFT JOIN terms t ON d.term_id = t.id 
                              WHERE d.user_id = ? AND d.status != 'paid' 
                              ORDER BY f.due_date ASC");
        $stmt->execute([$user_id]);
        $debts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['debts' => $debts]);
    } else {
        echo json_encode(['debts' => []]);
    }
    exit();
}

// Fetch students
$students_stmt = $db->query("SELECT id, name FROM users WHERE role = 'student' ORDER BY name ASC");
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="process-payment">
        <h1 class="page-title">Process Payment</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <form class="payment-form" id="payment-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="process_payment">

            <label for="user_id">Select Student:</label>
            <select name="user_id" id="user_id" required onchange="fetchDebts(this.value)">
                <option value="">Select Student</option>
                <?php foreach ($students as $student): ?>
                    <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['name']); ?></option>
                <?php endforeach; ?>
            </select>

            <div id="debts-section" style="display: none;">
                <label for="debt_id">Select Debt:</label>
                <select name="debt_id" id="debt_id" required>
                    <!-- Populated via JS -->
                </select>

                <div id="debt-details" class="debt-details">
                    <!-- Details populated via JS -->
                </div>
            </div>

            <label for="amount">Amount Paid:</label>
            <input type="number" name="amount" id="amount" step="0.01" min="0.01" required placeholder="Enter amount paid">

            <label for="payment_method">Payment Method:</label>
            <select name="payment_method" id="payment_method" required>
                <option value="">Select Method</option>
                <option value="cash">Cash</option>
                <option value="bank_transfer">Bank Transfer</option>
                <option value="mobile_money">Mobile Money</option>
                <option value="card">Credit/Debit Card</option>
            </select>

            <label for="transaction_id">Transaction ID (Optional):</label>
            <input type="text" name="transaction_id" id="transaction_id" placeholder="Enter transaction ID">

            <label><input type="checkbox" name="send_sms" checked> Send SMS Confirmation to Parent/Student</label>

            <button type="submit">Process Payment</button>
        </form>
    </section>

    <script>
        function fetchDebts(userId) {
            if (userId > 0) {
                fetch(`process_payment.php?action=fetch_debts&user_id=${userId}`)
                    .then(response => response.json())
                    .then(data => {
                        const debtSelect = document.getElementById('debt_id');
                        debtSelect.innerHTML = '<option value="">Select Debt</option>';
                        data.debts.forEach(debt => {
                            debtSelect.innerHTML += `<option value="${debt.id}" data-details='Outstanding: ${debt.amount_due - debt.amount_paid}, Type: ${debt.fee_type}, Due: ${debt.due_date}, Year: ${debt.academic_year}, Term: ${debt.term || 'N/A'}'>${debt.fee_type} - Outstanding: ${debt.amount_due - debt.amount_paid}</option>`;
                        });
                        document.getElementById('debts-section').style.display = 'block';
                    })
                    .catch(err => console.error('Error fetching debts:', err));
            } else {
                document.getElementById('debts-section').style.display = 'none';
            }
        }

        document.getElementById('debt_id').addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            document.getElementById('debt-details').innerHTML = selected ? `<p>Details: ${selected.dataset.details}</p>` : '';
        });

        document.getElementById('payment-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            }).then(response => response.json())
              .then(data => {
                  alert(data.message);
                  if (data.success) {
                      this.reset();
                      document.getElementById('debts-section').style.display = 'none';
                      document.getElementById('debt-details').innerHTML = '';
                  }
              }).catch(err => {
                  console.error('Error processing payment:', err);
                  alert('Error processing payment.');
              });
        });
    </script>

    <style>
        /* Process Payment Styles */
        .process-payment {
            padding: 20px;
            max-width: 600px;
            margin: 0 auto;
        }

        .payment-form {
            background: var(--primary-white);
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .payment-form label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .payment-form input, .payment-form select {
            width: 100%;
            padding: 10px;
            margin-bottom: 16px;
            border: 1px solid var(--accent-slate-gray);
            border-radius: 4px;
        }

        .debt-details {
            background: #f8f9fa;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 16px;
        }

        .payment-form button {
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