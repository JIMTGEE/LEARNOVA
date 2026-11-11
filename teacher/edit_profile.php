<?php
ob_start();
session_start();

// Check if the user is logged in and has a teacher role (aligned with admin page and dashboard)
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Redirect to login: user_id=" . ($_SESSION['user_id'] ?? 'unset') . 
              ", user_role=" . ($_SESSION['user_role'] ?? 'unset') . 
              ", requested_url=" . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
    header("Location: ../login.php");
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
$teacher_id = $_SESSION['user_id'];
$success_msg = $error_msg = "";
$csrf_token = bin2hex(random_bytes(32));
$_SESSION['csrf_token'] = $csrf_token;

// Fetch teacher details
$teacher_sql = "SELECT name, email, department_id, profile_picture, phone, address FROM users WHERE id = ? AND role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher_result = $teacher_stmt->get_result();
$teacher = $teacher_result->fetch_assoc();
$teacher_stmt->close();

if (!$teacher) {
    $error_msg = "Teacher profile not found.";
}

// Fetch departments
$departments_sql = "SELECT id, name FROM departments ORDER BY name";
$departments_stmt = $conn->prepare($departments_sql);
$departments_stmt->execute();
$departments_result = $departments_stmt->get_result();
$departments = $departments_result->fetch_all(MYSQLI_ASSOC);
$departments_stmt->close();

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error_msg = "Invalid CSRF token.";
    } else {
        $name = trim($_POST['name'] ?? '');
        $department_id = (int)($_POST['department_id'] ?? 0);
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $profile_picture = $teacher['profile_picture'];

        // Validate inputs
        if (empty($name)) {
            $error_msg = "Name is required.";
        } elseif ($department_id <= 0) {
            $error_msg = "Please select a department.";
        } elseif (strlen($phone) > 20) {
            $error_msg = "Phone number is too long (max 20 characters).";
        } elseif (strlen($address) > 255) {
            $error_msg = "Address is too long (max 255 characters).";
        } else {
            // Handle profile picture upload
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/Uploads/profiles/';
                $file_name = $teacher_id . '_' . time() . '_' . basename($_FILES['profile_picture']['name']);
                $file_path = $upload_dir . $file_name;
                $file_type = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

                if (!in_array($file_type, ['jpg', 'jpeg', 'png', 'gif'])) {
                    $error_msg = "Only JPG, PNG, or GIF files are allowed.";
                } elseif ($_FILES['profile_picture']['size'] > 5 * 1024 * 1024) {
                    $error_msg = "Profile picture must be less than 5MB.";
                } else {
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $file_path)) {
                        $profile_picture = '/Uploads/profiles/' . $file_name;
                        // Delete old profile picture if exists
                        if ($teacher['profile_picture'] && file_exists($_SERVER['DOCUMENT_ROOT'] . $teacher['profile_picture'])) {
                            unlink($_SERVER['DOCUMENT_ROOT'] . $teacher['profile_picture']);
                        }
                    } else {
                        $error_msg = "Failed to upload profile picture.";
                    }
                }
            }

            if (empty($error_msg)) {
                $conn->begin_transaction();
                try {
                    $update_sql = "UPDATE users SET name = ?, department_id = ?, phone = ?, address = ?, profile_picture = ? WHERE id = ? AND role = 'teacher'";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("sisssi", $name, $department_id, $phone, $address, $profile_picture, $teacher_id);
                    $update_stmt->execute();
                    if ($update_stmt->affected_rows > 0) {
                        logActivity($conn, $teacher_id, "Updated Profile", "Name: $name, Department ID: $department_id, Phone: $phone, Address: $address, Profile Picture: $profile_picture");
                        $success_msg = "Profile updated successfully.";
                        $teacher['name'] = $name;
                        $teacher['department_id'] = $department_id;
                        $teacher['phone'] = $phone;
                        $teacher['address'] = $address;
                        $teacher['profile_picture'] = $profile_picture;
                    }
                    $update_stmt->close();
                    $conn->commit();
                } catch (Exception $e) {
                    $conn->rollback();
                    $error_msg = "Error updating profile: " . $e->getMessage();
                    error_log("Profile update error: " . $e->getMessage());
                }
            }
        }
    }
}

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
            $password_sql = "SELECT password FROM users WHERE id = ? AND role = 'teacher'";
            $password_stmt = $conn->prepare($password_sql);
            $password_stmt->bind_param("i", $teacher_id);
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
                    $update_password_sql = "UPDATE users SET password = ? WHERE id = ? AND role = 'teacher'";
                    $update_password_stmt = $conn->prepare($update_password_sql);
                    $update_password_stmt->bind_param("si", $new_password_hash, $teacher_id);
                    $update_password_stmt->execute();
                    if ($update_password_stmt->affected_rows > 0) {
                        logActivity($conn, $teacher_id, "Changed Password", "Teacher ID: $teacher_id");
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

include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Teacher profile editing page for QueensPa School.">
    <meta name="keywords" content="QueensPa School, teacher profile, edit profile">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Teacher Profile">
    <meta property="og:description" content="Edit teacher profile details and change password.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/edit_profile.php">
    <title>Edit Profile - QueensPa School</title>
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
        .profile-form, .password-form {
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
                    <h3 class="mb-0"><i class="fas fa-user-edit me-2"></i>Edit Teacher Profile</h3>
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

                    <!-- Profile Update Form -->
                    <h5 class="mt-3">Update Profile Details</h5>
                    <form id="profileForm" class="profile-form" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="update_profile">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($teacher['name']); ?>" required>
                                <div class="invalid-feedback">Please enter your name.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="email" class="form-label">Email (Cannot be changed)</label>
                                <input type="email" class="form-control" id="email" value="<?php echo htmlspecialchars($teacher['email']); ?>" disabled>
                            </div>
                            <div class="col-md-6">
                                <label for="department_id" class="form-label">Department <span class="text-danger">*</span></label>
                                <select class="form-select" id="department_id" name="department_id" required>
                                    <option value="">Select Department</option>
                                    <?php foreach ($departments as $department): ?>
                                        <option value="<?php echo $department['id']; ?>" <?php echo $department['id'] == $teacher['department_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($department['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="invalid-feedback">Please select a department.</div>
                            </div>
                            <div class="col-md-6">
                                <label for="phone" class="form-label">Phone</label>
                                <input type="text" class="form-control" id="phone" name="phone" value="<?php echo htmlspecialchars($teacher['phone'] ?? ''); ?>" maxlength="20">
                                <div class="invalid-feedback">Phone number is too long (max 20 characters).</div>
                            </div>
                            <div class="col-md-12">
                                <label for="address" class="form-label">Address</label>
                                <textarea class="form-control" id="address" name="address" rows="3" maxlength="255"><?php echo htmlspecialchars($teacher['address'] ?? ''); ?></textarea>
                                <div class="invalid-feedback">Address is too long (max 255 characters).</div>
                            </div>
                            <div class="col-md-6">
                                <label for="profile_picture" class="form-label">Profile Picture</label>
                                <input type="file" class="form-control" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/gif">
                                <?php if ($teacher['profile_picture']): ?>
                                    <img src="<?php echo htmlspecialchars($teacher['profile_picture']); ?>" alt="Profile Picture" class="profile-picture-preview mt-2">
                                <?php endif; ?>
                                <div class="invalid-feedback">Please select a valid image file.</div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Profile
                            </button>
                        </div>
                    </form>

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
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; mysqli_close($conn); ob_end_flush(); ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script>
        $(document).ready(function() {
            // Auto-dismiss alerts
            setTimeout(() => $('.alert').alert('close'), 5000);

            // Validate profile form
            $('#profileForm').on('submit', function(e) {
                e.preventDefault();
                const form = this;
                form.classList.add('was-validated');
                if (form.checkValidity()) {
                    form.submit();
                }
            });

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