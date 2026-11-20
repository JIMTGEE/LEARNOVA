<?php
session_start();

// Check if the user is logged in and has an admin role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and header
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');
;

// Check if the database connection was successful
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

// Initialize variables for form feedback
$success_msg = $error_msg = "";

// Check if an ID is provided to edit
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $error_msg = "Error: No valid calendar entry selected.";
    header("refresh:2;url=calendar.php");
} else {
    $id = intval($_GET['id']);

    // Query to get the existing calendar entry data using prepared statements
    $sql = "SELECT * FROM academic_calendar WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if (!$row) {
        $error_msg = "Calendar entry not found.";
        header("refresh:2;url=calendar.php");
    } else {
        // If the form is submitted, update the calendar entry
        if ($_SERVER['REQUEST_METHOD'] == 'POST') {
            // Get and sanitize form data
            $year = $_POST['year'] ?? '';
            $term_name = $_POST['term_name'] ?? '';
            $start_date = $_POST['start_date'] ?? '';
            $end_date = $_POST['end_date'] ?? '';

            // Validate inputs
            if (empty($year) || empty($term_name) || empty($start_date) || empty($end_date)) {
                $error_msg = "All fields are required.";
            } else {
                // Validate year format (YYYY/YYYY)
                if (!preg_match('/^\d{4}\/\d{4}$/', $year)) {
                    $error_msg = "Please enter the year in the format YYYY/YYYY (e.g., 2025/2026).";
                } else {
                    // Split the year to validate the range
                    list($start_year, $end_year) = explode('/', $year);
                    $start_year = (int)$start_year;
                    $end_year = (int)$end_year;

                    if ($start_year < 2000 || $end_year > 2100 || $end_year != $start_year + 1) {
                        $error_msg = "Please enter a valid academic year between 2000/2001 and 2099/2100, where the second year is the first year plus 1.";
                    } elseif (strtotime($end_date) <= strtotime($start_date)) {
                        $error_msg = "End date must be after start date.";
                    } else {
                        // Check for overlapping terms in the same year, excluding the current entry
                        $check_sql = "SELECT id FROM academic_calendar WHERE id != ? AND year = ? AND term_name = ? AND (
                            (start_date <= ? AND end_date >= ?) OR 
                            (start_date <= ? AND end_date >= ?)
                        )";
                        $check_stmt = $conn->prepare($check_sql);
                        $check_stmt->bind_param("issssss", $id, $year, $term_name, $start_date, $start_date, $end_date, $end_date);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();

                        if ($check_result->num_rows > 0) {
                            $error_msg = "This term overlaps with an existing term in the same year.";
                        } else {
                            // Update the calendar entry using prepared statements
                            $update_sql = "UPDATE academic_calendar 
                                           SET year = ?, term_name = ?, start_date = ?, end_date = ? 
                                           WHERE id = ?";
                            $update_stmt = $conn->prepare($update_sql);
                            $update_stmt->bind_param("ssssi", $year, $term_name, $start_date, $end_date, $id);

                            if ($update_stmt->execute()) {
                                // Log the action
                                $user_id = $_SESSION['user_id'];
                                $action = "Updated Academic Calendar Entry";
                                $description = "ID: $id, Year: $year, Term: $term_name, Start: $start_date, End: $end_date";
                                logActivity($conn, $user_id, $action, $description);

                                $success_msg = "Academic calendar entry updated successfully! Redirecting...";
                                // Redirect after 2 seconds
                                header("refresh:2;url=calendar.php");
                                // Prevent further form submission
                                $_POST = array();
                            } else {
                                $error_msg = "Error updating calendar entry: " . $conn->error;
                            }
                            $update_stmt->close();
                        }
                        $check_stmt->close();
                    }
                }
            }
        }
    }
    $stmt->close();
}

// Include the admin header
include $_SERVER['DOCUMENT_ROOT'] . '/queenspa/templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Academic Calendar Entry</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #ffffff;
            color: #000000;
        }
        .sidebar {
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            width: 250px;
            background-color: #ff4500;
            padding-top: 20px;
        }
        .sidebar a {
            color: #ffffff;
            padding: 15px 20px;
            display: block;
            text-decoration: none;
        }
        .sidebar a:hover {
            background-color: #e03e00;
        }
        .main-content {
            margin-left: 250px;
            padding: 20px;
        }
        .card-header {
            background-color: #ff4500;
            color: #ffffff;
        }
        .btn-primary {
            background-color: #ff4500;
            border-color: #ff4500;
        }
        .btn-primary:hover {
            background-color: #e03e00;
            border-color: #e03e00;
        }
        .btn-secondary {
            background-color: #6c757d;
            border-color: #6c757d;
        }
        .btn-secondary:hover {
            background-color: #5a6268;
            border-color: #5a6268;
        }
        .card {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .night-mode {
            background-color: #000000;
            color: #ffffff;
        }
        .night-mode .sidebar {
            background-color: #333333;
        }
        .night-mode .sidebar a {
            color: #ffffff;
        }
        .night-mode .sidebar a:hover {
            background-color: #555555;
        }
        .night-mode .card {
            background-color: #333333;
            color: #ffffff;
        }
    </style>
</head>
<body>
    <!-- Main Content -->
    <div class="main-content">
        <div class="container mt-5 animate__animated animate__fadeIn">
            <h1 class="mb-4 text-center">📅 Edit Academic Year/Term</h1>

            <!-- Display success or error messages -->
            <?php if (!empty($success_msg)): ?>
                <div class="alert alert-success"><?php echo $success_msg; ?></div>
            <?php endif; ?>
            <?php if (!empty($error_msg)): ?>
                <div class="alert alert-danger"><?php echo $error_msg; ?></div>
            <?php endif; ?>

            <!-- Form to edit academic calendar entry -->
            <?php if (isset($row) && $row): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h3 class="mb-0">Edit Calendar Entry (ID: <?php echo $id; ?>)</h3>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="year" class="form-label">Academic Year (e.g., 2025/2026)</label>
                                <input type="text" class="form-control" id="year" name="year" value="<?php echo htmlspecialchars($row['year']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="term_name" class="form-label">Term</label>
                                <input type="text" class="form-control" id="term_name" name="term_name" value="<?php echo htmlspecialchars($row['term_name']); ?>" placeholder="e.g., Term 1" required>
                            </div>
                            <div class="mb-3">
                                <label for="start_date" class="form-label">Start Date</label>
                                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($row['start_date']); ?>" required>
                            </div>
                            <div class="mb-3">
                                <label for="end_date" class="form-label">End Date</label>
                                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($row['end_date']); ?>" required>
                            </div>
                            <button type="submit" class="btn btn-primary">Update Calendar Entry</button>
                            <a href="calendar.php" class="btn btn-secondary ms-2">Cancel</a>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Embedded JS for night/day mode toggle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const toggleButton = document.createElement('button');
            toggleButton.textContent = 'Toggle Night Mode';
            toggleButton.className = 'btn btn-secondary position-fixed top-0 end-0 m-2';
            document.body.appendChild(toggleButton);

            const isNightMode = localStorage.getItem('nightMode') === 'true';
            if (isNightMode) {
                document.body.classList.add('night-mode');
            }

            toggleButton.addEventListener('click', () => {
                document.body.classList.toggle('night-mode');
                localStorage.setItem('nightMode', document.body.classList.contains('night-mode'));
            });
        });
    </script>
</body>
</html>

<?php
// Include the admin footer
include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';

// Close the database connection
mysqli_close($conn);
?>