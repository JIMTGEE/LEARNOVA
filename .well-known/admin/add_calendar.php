<?php
// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in and has an admin role - Using specified auth logic
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    // Debug: Log why redirecting (preserved)
    error_log("Redirecting to login: user_id not set or role not admin. Role: " . ($_SESSION['user_role'] ?? 'not set'));
    header("Location: ../login.php");
    exit();
}

// Include database connection first to avoid dependency issues in helpers
require_once __DIR__ . '/../config/db.php';
$db = db(); // Use the helper function to get PDO instance

// Include helpers and functions with upgraded error handling
try {
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../functions/csrf.php';
    require_once __DIR__ . '/../functions/log_activity.php';
} catch (Exception $e) {
    error_log("Error loading helpers/functions: " . $e->getMessage());
    $_SESSION['error'] = 'System error: Unable to load required files. Please check logs.';
    header("Location: dashboard.php");
    exit();
}

// Handle POST actions: add academic year, add term
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: add_calendar.php");
        exit();
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_year':
                $year = trim($_POST['year'] ?? '');
                if (preg_match('/^\d{4}-\d{4}$/', $year)) {
                    $stmt = $db->prepare("INSERT INTO academic_years (year) VALUES (?)");
                    $stmt->execute([$year]);
                    $new_id = $db->lastInsertId();
                    log_activity($_SESSION['user_id'], 'add_academic_year', "Added new academic year: $year (ID: $new_id)");
                    $_SESSION['success'] = 'Academic year added successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid year format. Use YYYY-YYYY.';
                }
                break;

            case 'add_term':
                $academic_year_id = intval($_POST['academic_year_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $start_date = $_POST['start_date'] ?? '';
                $end_date = $_POST['end_date'] ?? '';

                if ($academic_year_id > 0 && !empty($name) && !empty($start_date) && !empty($end_date) && strtotime($start_date) < strtotime($end_date)) {
                    $stmt = $db->prepare("INSERT INTO terms (academic_year_id, name, start_date, end_date) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$academic_year_id, $name, $start_date, $end_date]);
                    $new_id = $db->lastInsertId();
                    log_activity($_SESSION['user_id'], 'add_term', "Added new term: $name for year ID $academic_year_id (ID: $new_id)");
                    $_SESSION['success'] = 'Term added successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid input. Ensure all fields are filled and dates are logical.';
                }
                break;
        }
        header("Location: add_calendar.php");
        exit();
    }
}

// Fetch existing academic years for term addition
$academic_years = $db->query("SELECT * FROM academic_years ORDER BY year DESC")->fetchAll(PDO::FETCH_ASSOC);

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="add-calendar">
        <h1 class="page-title">Add Academic Years and Terms</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <!-- Add Academic Year Form -->
        <div class="form-card">
            <h2>Add New Academic Year</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_year">
                <label>Year (e.g., 2024-2025):</label>
                <input type="text" name="year" placeholder="YYYY-YYYY" required pattern="\d{4}-\d{4}">
                <button type="submit">Add Year</button>
            </form>
        </div>

        <!-- Add Term Form -->
        <div class="form-card">
            <h2>Add New Term</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_term">
                <label>Academic Year:</label>
                <select name="academic_year_id" required>
                    <option value="">Select Year</option>
                    <?php foreach ($academic_years as $year): ?>
                        <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['year']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label>Term Name (e.g., Term 1):</label>
                <input type="text" name="name" required>
                <label>Start Date:</label>
                <input type="date" name="start_date" required>
                <label>End Date:</label>
                <input type="date" name="end_date" required>
                <button type="submit">Add Term</button>
            </form>
        </div>
    </section>

    <style>
        /* Add Calendar Styles */
        .add-calendar {
            display: grid;
            gap: 24px;
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }

        .form-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 24px;
        }

        .form-card h2 {
            margin-bottom: 16px;
        }

        .form-card label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .form-card input, .form-card select {
            width: 100%;
            padding: 12px;
            margin-bottom: 16px;
            border: 1px solid var(--accent-slate-gray);
            border-radius: 4px;
        }

        .form-card button {
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .form-card button:hover {
            background-color: var(--accent-soft-cyan);
        }

        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: 500;
        }

        .alert.success {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }

        .alert.error {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        @media (max-width: 768px) {
            .add-calendar {
                padding: 15px;
            }
        }
    </style>

<?php include '../templates/admin_footer.php'; ?>