<?php
session_start();

// Restrict to admins only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header("Location: ../login.php");
    exit();
}

// Include database connection and functions
require_once '../config/db.php';
require_once '../functions/log_activity.php';

// Get PDO connection
$db = db(); // Using the helper function from db.php

// Fetch subjects with department and teacher names
$query = "SELECT s.id, s.subject_name, s.subject_code, d.name AS department_name, s.description, s.status, 
                 s.created_at, s.updated_at, u.name AS teacher_name 
          FROM subjects s 
          LEFT JOIN departments d ON s.department_id = d.id 
          LEFT JOIN users u ON s.teacher_id = u.id 
          ORDER BY s.created_at DESC";

try {
    $stmt = $db->prepare($query);
    $stmt->execute();
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Log error instead of dying in production
    error_log("Query failed: " . $e->getMessage());
    $subjects = []; // Empty array to prevent further errors
}

// Log activity
$admin_id = $_SESSION['user_id'];
$action = "Viewed Subject Management";
$description = "Admin viewed the subject management dashboard.";
logActivity($admin_id, $action, $description);

// Include header
include '../templates/admin_header.php';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage subjects in EduSync, including adding, editing, and deleting subjects.">
    <meta name="keywords" content="EduSync, subject management, admin dashboard, school management">
    <meta name="author" content="Prince Seed × NerdSpring Dev Team">
    <meta name="robots" content="noindex, nofollow">
    <!-- Open Graph -->
    <meta property="og:title" content="EduSync Subject Management">
    <meta property="og:description" content="Admin interface for managing subjects in EduSync.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://edusync.example.com/admin/subject_management.php">
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="EduSync Subject Management">
    <meta name="twitter:description" content="Admin interface for managing subjects in EduSync.">
    <title>Subject Management - EduSync</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <!-- Custom Styles matching Design System -->
    <style>
        :root {
            --primary-white: #FFFFFF;
            --primary-sea-blue: #17A2B8;
            --primary-black: #0B0D10;
            --accent-deep-navy: #081F2C;
            --accent-slate-gray: #5E6A79;
            --accent-soft-cyan: #35D0FF;
            --font-ui: 'Inter', sans-serif;
            --font-content: 'Source Sans 3', sans-serif;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        body {
            background-color: var(--primary-white);
            font-family: var(--font-content);
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
        }
        .card-header {
            background: linear-gradient(to right, var(--accent-deep-navy), var(--primary-sea-blue));
            color: var(--primary-white);
            border-radius: 12px 12px 0 0;
        }
        .btn-primary, .btn-success, .btn-info, .btn-warning, .btn-danger {
            background-color: var(--primary-sea-blue);
            border-color: var(--primary-sea-blue);
            border-radius: 8px;
            transition: background-color 0.2s ease-out, transform 0.2s ease-out;
        }
        .btn-primary:hover, .btn-success:hover, .btn-info:hover, .btn-warning:hover, .btn-danger:hover {
            background-color: var(--accent-soft-cyan);
            border-color: var(--accent-soft-cyan);
            transform: translateY(-2px);
        }
        .table-responsive {
            border-radius: 12px;
            overflow-x: auto;
        }
        .table thead {
            background: var(--accent-deep-navy);
            color: var(--primary-white);
        }
        .table-hover tbody tr:hover {
            background-color: rgba(23, 162, 184, 0.1); /* Subtle sea blue tint */
        }
        /* Accessibility enhancements */
        .table th, .table td {
            text-align: left;
            vertical-align: middle;
        }
        /* Mobile-first responsiveness */
        @media (max-width: 767.98px) {
            .action-buttons {
                display: flex;
                flex-direction: column;
                gap: 0.5rem;
            }
            .action-buttons .btn {
                width: 100%;
            }
            .table-responsive {
                margin: 0 -1rem;
            }
        }
    </style>
</head>
<body>
    <!-- Main Content -->
    <div class="container my-4">
        <section class="card mb-4" aria-labelledby="subject-management-title">
            <div class="card-header">
                <h3 class="mb-0" id="subject-management-title">📚 Subject Management</h3>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
                    <p class="text-muted mb-0">Manage all subjects and their details.</p>
                    <a href="add_subject.php" class="btn btn-success mt-2 mt-md-0"><i class="fas fa-plus"></i> Add New Subject</a>
                </div>

                <!-- Subjects Table -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-bordered" aria-label="Subjects Table">
                        <thead>
                            <tr>
                                <th scope="col">ID</th>
                                <th scope="col">Subject Name</th>
                                <th scope="col">Subject Code</th>
                                <th scope="col">Department</th>
                                <th scope="col">Teacher</th>
                                <th scope="col">Status</th>
                                <th scope="col">Created At</th>
                                <th scope="col">Updated At</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($subjects)): ?>
                                <tr><td colspan="9" class="text-center text-muted">No subjects found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($subjects as $subject): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($subject['id']) ?></td>
                                        <td><?= htmlspecialchars($subject['subject_name']) ?></td>
                                        <td><?= htmlspecialchars($subject['subject_code']) ?></td>
                                        <td><?= htmlspecialchars($subject['department_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($subject['teacher_name'] ?? 'N/A') ?></td>
                                        <td>
                                            <span class="badge bg-<?= $subject['status'] === 'Active' ? 'success' : 'secondary' ?>">
                                                <?= htmlspecialchars($subject['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($subject['created_at']) ?></td>
                                        <td><?= htmlspecialchars($subject['updated_at'] ?? 'N/A') ?></td>
                                        <td>
                                            <div class="action-buttons d-flex gap-1 flex-wrap">
                                                <a href="view_subject.php?id=<?= $subject['id'] ?>" class="btn btn-info btn-sm"><i class="fas fa-eye"></i> View</a>
                                                <a href="edit_subject.php?id=<?= $subject['id'] ?>" class="btn btn-warning btn-sm"><i class="fas fa-edit"></i> Edit</a>
                                                <a href="delete_subject.php?id=<?= $subject['id'] ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this subject?');"><i class="fas fa-trash"></i> Delete</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <?php
    include '../templates/admin_footer.php';
    ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
</body>
</html>