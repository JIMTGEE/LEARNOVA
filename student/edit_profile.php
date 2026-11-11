<?php
ob_start();
session_start();

// Verify user is logged in and is a student (aligned with dashboard.php)
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    error_log("Redirect to login: user_id=" . ($_SESSION['user_id'] ?? 'unset') . 
              ", user_role=" . ($_SESSION['user_role'] ?? 'unset') . 
              ", requested_url=" . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
    header("Location: /login.php?error=unauthorized");
    exit();
}

// Include database connection and functions
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error());
    die("Connection failed. Please try again later.");
}

// Initialize variables
$student_id = $_SESSION['user_id'];
$success_msg = $error_msg = "";
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

// Fetch student details (aligned with dashboard.php, extended for additional fields)
$student_sql = "SELECT u.name, u.email, u.department_id, u.profile_picture, u.phone, u.address, d.name AS department_name 
                FROM users u 
                LEFT JOIN departments d ON u.department_id = d.id 
                WHERE u.id = ? AND u.role = 'student'";
$student_stmt = $conn->prepare($student_sql);
$student_stmt->bind_param("i", $student_id);
$student_stmt->execute();
$student_result = $student_stmt->get_result();
$student = $student_result->fetch_assoc() ?? ['name' => 'Student', 'email' => '', 'department_id' => null, 'department_name' => 'Not assigned', 'profile_picture' => null, 'phone' => '', 'address' => ''];
$student_stmt->close();

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "Invalid CSRF token.";
    } else {
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validate inputs
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            $error_msg = "All password fields are required.";
        } elseif ($new_password !== $confirm_password) {
            $error_msg = "New password and confirmation do not match.";
        } elseif (strlen($new_password) < 8) {
            $error_msg = "New password must be at least 8 characters long.";
        } else {
            // Verify current password
            $password_sql = "SELECT password FROM users WHERE id = ? AND role = 'student'";
            $password_stmt = $conn->prepare($password_sql);
            $password_stmt->bind_param("i", $student_id);
            $password_stmt->execute();
            $password_result = $password_stmt->get_result();
            $stored_password = $password_result->fetch_assoc()['password'];
            $password_stmt->close();

            if (!password_verify($current_password, $stored_password)) {
                $error_msg = "Current password is incorrect.";
            } else {
                $conn->begin_transaction();
                try {
                    $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_password_sql = "UPDATE users SET password = ? WHERE id = ? AND role = 'student'";
                    $update_password_stmt = $conn->prepare($update_password_sql);
                    $update_password_stmt->bind_param("si", $new_password_hash, $student_id);
                    $update_password_stmt->execute();
                    if ($update_password_stmt->affected_rows > 0) {
                        logActivity($conn, $student_id, "Changed Password", "Student ID: $student_id");
                        $success_msg = "Password changed successfully.";
                    }
                    $update_password_stmt->close();
                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_msg = "Error changing password: " . $e->getMessage();
                    error_log("Password change error: " . $e->getMessage());
                }
            }
        }
    }
}

// Log access
logActivity($conn, $student_id, "Accessed Edit Profile", "Student accessed profile page");

include $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Student profile page for QueensPa School.">
    <meta name="keywords" content="QueensPa School, student profile, change password">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Student Profile">
    <meta property="og:description" content="View student profile details and change password.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/student/edit_profile.php">
    <title>Student Profile - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <style>
        :root {
            --primary-color: #ff4500;
            --primary-hover: #e03e00;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background: var(--bg-color);
            color: var(--text-color);
            font-family: 'Arial', sans-serif;
        }
        .container {
            max-width: 800px;
        }
        .card {
            border: none;
            border-radius: 12px;
            background: #fff;
            box-shadow: var(--shadow);
            transition: transform 0.2s ease;
        }
        .card-header {
            background: var(--primary-color);
            color: #fff;
            border-radius: 12px 12px 0 0;
            padding: 1rem;
        }
        .btn-primary {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            border-radius: 8px;
            padding: 0.6rem 1.2rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
        .form-control, .form-select {
            border-radius: 8px;
            border: 1px solid #ced4da;
            padding: 0.6rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(255, 69, 0, 0.2);
        }
        .alert {
            border-radius: 8px;
            padding: 0.8rem;
        }
        .form-label {
            font-weight: 500;
        }
        .profile-picture-preview {
            max-width: 150px;
            max-height: 150px;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .password-form {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        @media (max-width: 768px) {
            .container {
                padding: 0 1rem;
            }
            .profile-picture-preview {
                max-width: 100px;
                max-height: 100px;
            }
            .btn-primary {
                padding: 0.5rem 0.8rem;
                font-size: 0.85rem;
            }
        }
    </style>
</head>
<body>
    <div class="content">
        <main class="container my-4">
            <section class="card mb-4">
                <div class="card-header">
                    <h3 class="mb-0"><i class="fas fa-user me-2"></i>Student Profile</h3>
                </div>
                <div class="card-body">
                    <?php if (!empty($success_msg)): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($error_msg)): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_msg); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Profile Details (Read-Only) -->
                    <h5 class="mt-3">Profile Details</h5>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Name</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($student['name']); ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" value="<?php echo htmlspecialchars($student['email']); ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Department</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($student['department_name']); ?>" disabled>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" class="form-control" value="<?php echo htmlspecialchars($student['phone'] ?? 'Not set'); ?>" disabled>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Address</label>
                            <textarea class="form-control" rows="3" disabled><?php echo htmlspecialchars($student['address'] ?? 'Not set'); ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Profile Picture</label>
                            <?php if ($student['profile_picture']): ?>
                                <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" alt="Profile Picture" class="profile-picture-preview mt-2">
                            <?php else: ?>
                                <img src="/images/default-avatar.png" alt="Default Profile Picture" class="profile-picture-preview mt-2">
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Password Change Form -->
                    <h5 class="mt-4">Change Password</h5>
                    <form id="passwordForm" class="password-form" method="POST">
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label for="current_password" class="form-label">Current Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="current_password" name="current_password" required>
                                <div class="invalid-feedback">Please enter your current password.</div>
                            </div>
                            <div class="col-md-4">
                                <label for="new_password" class="form-label">New Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="new_password" name="new_password" required>
                                <div class="invalid-feedback">Please enter a new password (minimum 8 characters).</div>
                            </div>
                            <div class="col-md-4">
                                <label for="confirm_password" class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                                <div class="invalid-feedback">Please confirm your new password.</div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-key me-2"></i>Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </main>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/student_footer.php'; mysqli_close($conn); ob_end_flush(); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script>
        $(document).ready(function() {
            // Auto-dismiss alerts
            setTimeout(() => $('.alert').alert('close'), 5000);

            // Validate password form
            $('#passwordForm').on('submit', function(e) {
                e.preventDefault();
                const form = this;
                form.classList.add('was-validated');
                const newPassword = $('#new_password').val();
                const confirmPassword = $('#confirm_password').val();
                if (form.checkValidity()) {
                    if (newPassword !== confirmPassword) {
                        $('#confirm_password').addClass('is-invalid');
                        $('#confirm_password').next('.invalid-feedback').text('Passwords do not match.');
                        return;
                    }
                    if (newPassword.length < 8) {
                        $('#new_password').addClass('is-invalid');
                        $('#new_password').next('.invalid-feedback').text('Password must be at least 8 characters.');
                        return;
                    }
                    form.submit();
                }
            });
        });
    </script>
</body>
</html>