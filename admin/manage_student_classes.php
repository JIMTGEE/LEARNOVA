<?php
session_start();

// Restrict to admins only and validate session
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Invalid session: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    header("Location: ../login.php");
    exit();
}

// Include database connection
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
} catch (Exception $e) {
    error_log("Failed to include db.php: " . $e->getMessage());
    die("System error: Unable to connect to database. Please contact the administrator.");
}

if (!$conn || $conn->connect_error) {
    error_log("Database connection failed: " . ($conn->connect_error ?? 'Unknown error'));
    die("System error: Database connection failed. Please contact the administrator.");
}

// Include logging function
try {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
} catch (Exception $e) {
    error_log("Failed to include log_activity.php: " . $e->getMessage());
    die("System error: Unable to load logging functions. Please contact the administrator.");
}

// Validate user_id exists in users table
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'admin' AND status = 'active'";
if ($user_check_stmt = $conn->prepare($user_check_sql)) {
    $user_check_stmt->bind_param("i", $_SESSION['user_id']);
    $user_check_stmt->execute();
    $user_check_result = $user_check_stmt->get_result();
    if ($user_check_result->num_rows === 0) {
        error_log("Invalid or inactive admin user_id: " . $_SESSION['user_id']);
        header("Location: ../login.php?error=invalid_user");
        exit();
    }
    $user_check_stmt->close();
} else {
    error_log("Failed to prepare user check query: " . $conn->error);
    die("System error: Unable to validate user. Please contact the administrator.");
}

// Fetch classes
$classes_sql = "SELECT id, class_name FROM classes ORDER BY class_name";
$classes_result = $conn->query($classes_sql);
if (!$classes_result) {
    error_log("Error fetching classes: " . $conn->error);
    $error_msg = "Unable to fetch classes. Please try again later.";
    $classes = [];
} else {
    $classes = $classes_result->fetch_all(MYSQLI_ASSOC);
    $classes_result->free();
}

// Fetch assignments and group by class
$assignments_sql = "SELECT sc.id, sc.user_id, sc.class_id, sc.academic_year, u.name as student_name, c.class_name 
                   FROM student_classes sc 
                   JOIN users u ON sc.user_id = u.id 
                   JOIN classes c ON sc.class_id = c.id 
                   WHERE u.role = 'student' AND u.status = 'active'
                   ORDER BY c.class_name, u.name";
$assignments_result = $conn->query($assignments_sql);
$grouped_assignments = [];
$print_assignments = [];
if (!$assignments_result) {
    error_log("Error fetching assignments: " . $conn->error);
    $error_msg = "Unable to fetch assignments. Please try again later.";
} else {
    while ($row = $assignments_result->fetch_assoc()) {
        $class_name = $row['class_name'];
        if (!isset($grouped_assignments[$class_name])) {
            $grouped_assignments[$class_name] = [];
            $print_assignments[$class_name] = [];
        }
        $grouped_assignments[$class_name][] = $row;
        $print_assignments[$class_name][] = $row;
    }
    $assignments_result->free();
}

// Initialize variables
$success_msg = $error_msg = "";

// Include header
try {
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_header.php';
} catch (Exception $e) {
    error_log("Failed to include admin_header.php: " . $e->getMessage());
    die("System error: Unable to load page header. Please contact the administrator.");
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View student-class assignments in Queen's Park Montessori School management system.">
    <meta name="keywords" content="Queen's Park Montessori, student class assignments, admin dashboard, school management">
    <meta name="author" content="Queen's Park Montessori School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="Queen's Park Montessori Manage Student Classes">
    <meta property="og:description" content="Admin interface for viewing student-class assignments in Queen's Park Montessori School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/admin/manage_student_classes.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="Queen's Park Montessori Manage Student Classes">
    <meta name="twitter:description" content="Admin interface for viewing student-class assignments in Queen's Park Montessori School.">
    <title>Manage Student Classes - Queen's Park Montessori</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            500: '#f97316',
                            600: '#ea580c',
                        },
                        secondary: '#6b7280',
                        accent: '#10b981',
                        background: '#f4f4f4',
                    },
                },
            },
        };
    </script>
    <style>
        [data-theme="dark"] {
            --primary-500: #fb923c;
            --primary-600: #f97316;
            --secondary: #9ca3af;
            --accent: #34d399;
            --background: #1f2937;
            --text: #d1d5db;
        }
        body {
            background-color: var(--background);
            color: var(--text, #1f2937);
        }
        .dark .bg-background { background-color: var(--background); }
        .dark .text-text { color: var(--text); }
        .class-card {
            transition: transform 0.2s;
            width: 100%;
            max-width: 350px;
            height: 400px;
            display: flex;
            flex-direction: column;
        }
        .class-card:hover {
            transform: translateY(-5px);
        }
        .class-card .card-body {
            flex: 1;
            overflow-y: auto;
            max-height: 300px;
            padding: 1rem;
        }
        .class-card .student-count {
            font-size: 0.9rem;
            font-weight: bold;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.3rem 0.6rem;
            border-radius: 12px;
        }
        .print-section {
            display: none;
        }
        @media print {
            body { background: white; color: black; }
            .print-section { display: block; }
            .print-header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #f97316; padding-bottom: 10px; }
            .print-header img { max-width: 100px; }
            .print-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            .print-table th, .print-table td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            .print-table th { background: linear-gradient(to right, #f97316, #ea580c); color: white; }
            .print-group-header { font-size: 14pt; font-weight: bold; margin: 20px 0 10px; color: #f97316; page-break-before: always; }
            .print-footer { text-align: center; margin-top: 20px; font-size: 10pt; color: #555; }
            @page { size: A4; margin: 20mm; }
        }
    </style>
</head>
<body class="min-h-screen bg-background text-text">
    <div class="content" id="content">
        <main class="container mx-auto p-4">
            <section class="bg-white dark:bg-gray-800 shadow-lg rounded-lg p-6 mb-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-2xl font-bold text-primary-500">📚 Student-Class Assignments</h3>
                    <div class="space-x-4">
                        <a href="/admin/assign_class.php" class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>Assign Class
                        </a>
                        <a href="/admin/promote_learners.php" class="bg-accent hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-arrow-up mr-2"></i>Promote Learners
                        </a>
                        <a href="/admin/demote_learners.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-arrow-down mr-2"></i>Demote Learners
                        </a>
                    </div>
                </div>
                <?php if (!empty($success_msg)) { ?>
                    <div class="bg-green-100 text-green-800 p-4 rounded-lg mb-4 animate-pulse" role="alert"><?php echo htmlspecialchars($success_msg); ?></div>
                <?php } ?>
                <?php if (!empty($error_msg)) { ?>
                    <div class="bg-red-100 text-red-800 p-4 rounded-lg mb-4 animate-pulse" role="alert"><?php echo htmlspecialchars($error_msg); ?></div>
                <?php } ?>
                <div class="text-end mb-3">
                    <button class="bg-primary-500 hover:bg-primary-600 text-white px-4 py-2 rounded-lg" onclick="window.print()">
                        <i class="fas fa-print mr-2"></i>Print Assignments
                    </button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php if (empty($grouped_assignments)) { ?>
                        <div class="col-span-full">
                            <div class="bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 p-4 rounded-lg text-center">No assignments found.</div>
                        </div>
                    <?php } else { ?>
                        <?php foreach ($grouped_assignments as $class_name => $assignments) { ?>
                            <div class="class-card bg-white dark:bg-gray-800 shadow-lg rounded-lg">
                                <div class="card-header bg-gradient-to-r from-primary-500 to-primary-600 text-white p-4 rounded-t-lg flex justify-between items-center">
                                    <h5 class="mb-0"><?php echo htmlspecialchars($class_name); ?></h5>
                                    <span class="student-count"><?php echo count($assignments); ?> Students</span>
                                </div>
                                <div class="card-body">
                                    <ul class="divide-y divide-gray-200 dark:divide-gray-600">
                                        <?php foreach ($assignments as $assignment) { ?>
                                            <li class="py-2 flex justify-between items-center">
                                                <span>
                                                    <?php echo htmlspecialchars($assignment['student_name']); ?>
                                                    <small class="text-gray-500 dark:text-gray-400">(<?php echo htmlspecialchars($assignment['academic_year']); ?>)</small>
                                                </span>
                                            </li>
                                        <?php } ?>
                                    </ul>
                                </div>
                            </div>
                        <?php } ?>
                    <?php } ?>
                </div>
                <div class="print-section">
                    <div class="print-header">
                        <img src="/assets/images/logo.png" alt="Queen's Park Montessori Logo" onerror="this.src='/assets/images/default_logo.png'">
                        <h2>Queen's Park Montessori</h2>
                        <p>P.O. Box 123, Accra, Ghana | info@qpmsedu.com</p>
                        <p>Student-Class Assignments - <?php echo date('F j, Y'); ?></p>
                    </div>
                    <?php foreach ($print_assignments as $class_name => $class_assignments) { ?>
                        <h3 class="print-group-header"><?php echo htmlspecialchars($class_name); ?> (<?php echo count($class_assignments); ?> Students)</h3>
                        <table class="print-table">
                            <thead>
                                <tr>
                                    <th>Student</th>
                                    <th>Academic Year</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($class_assignments as $assignment) { ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($assignment['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($assignment['academic_year']); ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    <?php } ?>
                    <div class="print-footer">
                        <p>Generated by Queen's Park Montessori School Management System</p>
                    </div>
                </div>
            </section>
        </main>
    </div>
    <?php 
    try {
        include $_SERVER['DOCUMENT_ROOT'] . '/templates/admin_footer.php';
    } catch (Exception $e) {
        error_log("Failed to include admin_footer.php: " . $e->getMessage());
        echo "<footer class='text-center py-4 text-gray-500'>System error: Unable to load page footer. Please contact the administrator.</footer>";
    }
    $conn->close();
    ?>
    <button id="theme-toggle" class="fixed bottom-4 right-4 bg-primary-500 hover:bg-primary-600 text-white p-2 rounded-full">
        <i class="fas fa-moon"></i>
    </button>
    <script>
        // Theme toggle
        const toggleButton = document.getElementById('theme-toggle');
        const htmlElement = document.documentElement;
        const currentTheme = localStorage.getItem('theme') || 'light';
        htmlElement.setAttribute('data-theme', currentTheme);
        if (currentTheme === 'dark') {
            toggleButton.innerHTML = '<i class="fas fa-sun"></i>';
        }
        toggleButton.addEventListener('click', () => {
            const newTheme = htmlElement.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
            htmlElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            toggleButton.innerHTML = newTheme === 'light' ? '<i class="fas fa-moon"></i>' : '<i class="fas fa-sun"></i>';
        });
    </script>
</body>
</html>