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

// Handle POST action: add event
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: add_event.php");
        exit();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'add_event') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $event_type = $_POST['event_type'] ?? '';
        $event_date = $_POST['event_date'] ?? '';
        $start_time = $_POST['start_time'] ?? null;
        $end_time = $_POST['end_time'] ?? null;
        $location = trim($_POST['location'] ?? '');
        $rsvp_required = isset($_POST['rsvp_required']) ? 1 : 0;

        // Validate inputs
        $valid_types = ['sports', 'cultural', 'academic', 'holiday', 'other'];
        if (!empty($title) && in_array($event_type, $valid_types) && !empty($event_date) && ($start_time === null || $end_time === null || $start_time < $end_time)) {
            $stmt = $db->prepare("INSERT INTO school_events (title, description, event_type, event_date, start_time, end_time, location, rsvp_required) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $description ?: null, $event_type, $event_date, $start_time ?: null, $end_time ?: null, $location, $rsvp_required]);
            $new_id = $db->lastInsertId();
            log_activity($_SESSION['user_id'], 'add_event', "Added new school event: $title (ID: $new_id)");
            $_SESSION['success'] = 'School event added successfully.';
        } else {
            $_SESSION['error'] = 'Invalid input. Please check all required fields and ensure times are logical.';
        }
        header("Location: add_event.php");
        exit();
    }
}

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="add-event">
        <h1 class="page-title">Add School Event</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <!-- Add Event Form -->
        <div class="form-card">
            <h2>Add New School Event</h2>
            <form method="POST" id="add-event-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_event">

                <label>Title:</label>
                <input type="text" name="title" required maxlength="255">

                <label>Description:</label>
                <textarea name="description" rows="5"></textarea>

                <label>Event Type:</label>
                <select name="event_type" required>
                    <option value="">Select Type</option>
                    <option value="sports">Sports</option>
                    <option value="cultural">Cultural</option>
                    <option value="academic">Academic</option>
                    <option value="holiday">Holiday</option>
                    <option value="other">Other</option>
                </select>

                <label>Event Date:</label>
                <input type="date" name="event_date" required>

                <label>Start Time (optional):</label>
                <input type="time" name="start_time">

                <label>End Time (optional):</label>
                <input type="time" name="end_time">

                <label>Location:</label>
                <input type="text" name="location" maxlength="255">

                <label><input type="checkbox" name="rsvp_required"> RSVP Required</label>

                <button type="submit">Add Event</button>
            </form>
        </div>
    </section>

    <script>
        // Form Validation: Ensure end time > start time if both provided
        document.getElementById('add-event-form').addEventListener('submit', function(e) {
            const startTime = document.querySelector('input[name="start_time"]').value;
            const endTime = document.querySelector('input[name="end_time"]').value;
            if (startTime && endTime && startTime >= endTime) {
                alert('End time must be after start time if both are provided.');
                e.preventDefault();
            }
        });
    </script>

    <style>
        /* Add Event Styles - Ultra Modern and Comprehensive */
        .add-event {
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
            color: var(--primary-black);
        }

        .form-card label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--accent-slate-gray);
        }

        .form-card input, .form-card select, .form-card textarea {
            width: 100%;
            padding: 12px;
            margin-bottom: 16px;
            border: 1px solid var(--accent-slate-gray);
            border-radius: 4px;
            background-color: var(--primary-white);
            transition: border-color 0.2s;
        }

        .form-card input:focus, .form-card select:focus, .form-card textarea:focus {
            border-color: var(--primary-sea-blue);
            outline: none;
        }

        .form-card button {
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.2s;
        }

        .form-card button:hover {
            background-color: var(--accent-soft-cyan);
            transform: translateY(-2px);
        }

        .form-card label input[type="checkbox"] {
            width: auto;
            margin-right: 8px;
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
            .add-event {
                padding: 15px;
            }

            .form-card {
                padding: 16px;
            }
        }
    </style>

<?php include '../templates/admin_footer.php'; ?>