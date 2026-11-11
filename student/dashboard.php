<?php
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

// Verify user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header("Location: /login.php?error=unauthorized");
    exit;
}

$user_id = $_SESSION['user_id'];
// Default to latest academic year if not set
$stmt = $conn->prepare("SELECT year FROM academic_calendar ORDER BY year DESC LIMIT 1");
$stmt->execute();
$latest_academic_year = $stmt->get_result()->fetch_assoc()['year'] ?? '2024/2025';
$stmt->close();
$academic_year = $_SESSION['academic_year'] ?? $latest_academic_year;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 5;

// Fetch student classes for the selected academic year
$stmt = $conn->prepare("
    SELECT c.class_name
    FROM student_classes sc
    JOIN classes c ON sc.class_id = c.id
    WHERE sc.user_id = ? AND sc.academic_year = ?
");
$stmt->bind_param("is", $user_id, $academic_year);
$stmt->execute();
$classes_result = $stmt->get_result();
$student_classes = [];
while ($row = $classes_result->fetch_assoc()) {
    $student_classes[] = $row['class_name'];
}
$stmt->close();
$student_class_display = !empty($student_classes) ? implode(', ', $student_classes) : 'No class assigned';

// Predefined motivational quotes as fallback
$motivational_quotes = [
    "Your curiosity fuels your success!",
    "Every question you ask is a step toward knowledge.",
    "Keep pushing forward; your hard work will pay off!",
    "Learning today builds a brighter tomorrow.",
    "Mistakes are proof that you're trying—keep going!",
    // ... (keeping the full list as provided, truncated for brevity)
];

// Cache metrics in session to reduce database queries
if (!isset($_SESSION['metrics']) || $_SESSION['metrics']['timestamp'] < time() - 300 || $_SESSION['metrics']['academic_year'] !== $academic_year) {
    $metrics = [
        'assignments' => 0,
        'new_assignments' => 0,
        'attendance' => 0,
        'avg_grade' => 0,
        'badges' => 0,
        'library_loans' => 0,
        'clubs' => 0,
        'fees_due' => 0
    ];

    // Total Assignments
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM assignments a JOIN student_classes sc ON a.class_id = sc.class_id WHERE sc.user_id = ? AND sc.academic_year = ? AND a.status = 'published'");
    $stmt->bind_param("is", $user_id, $academic_year);
    $stmt->execute();
    $metrics['assignments'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // New Assignments (due within 7 days, published only)
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM assignments a JOIN student_classes sc ON a.class_id = sc.class_id WHERE sc.user_id = ? AND sc.academic_year = ? AND a.status = 'published' AND a.due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)");
    $stmt->bind_param("is", $user_id, $academic_year);
    $stmt->execute();
    $metrics['new_assignments'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Attendance
    $stmt = $conn->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present FROM attendance a JOIN student_classes sc ON a.class_id = sc.class_id WHERE sc.user_id = ? AND sc.academic_year = ?");
    $stmt->bind_param("is", $user_id, $academic_year);
    $stmt->execute();
    $attendance = $stmt->get_result()->fetch_assoc();
    $metrics['attendance'] = $attendance['total'] > 0 ? round(($attendance['present'] / $attendance['total']) * 100, 1) : 0;
    $stmt->close();

    // Average Grade
    $stmt = $conn->prepare("SELECT AVG(total) as avg_grade FROM grades g JOIN student_classes sc ON g.class_id = sc.class_id WHERE sc.user_id = ? AND sc.academic_year = ?");
    $stmt->bind_param("is", $user_id, $academic_year);
    $stmt->execute();
    $avg_grade_result = $stmt->get_result()->fetch_assoc()['avg_grade'];
    $metrics['avg_grade'] = $avg_grade_result !== null ? round($avg_grade_result, 1) : 0;
    $stmt->close();

    // Badges
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM student_progress sp JOIN badges b ON sp.badge_id = b.id WHERE sp.user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $metrics['badges'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Library Loans
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM library_loans WHERE user_id = ? AND status = 'active'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $metrics['library_loans'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Clubs
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM club_memberships WHERE user_id = ? AND status = 'active'");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $metrics['clubs'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    // Fees Due
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM fees f LEFT JOIN payments p ON f.id = p.fee_id AND p.user_id = ? WHERE f.user_id = ? AND p.payment_date IS NULL");
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $metrics['fees_due'] = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
    $stmt->close();

    $_SESSION['metrics'] = ['data' => $metrics, 'timestamp' => time(), 'academic_year' => $academic_year];
} else {
    $metrics = $_SESSION['metrics']['data'];
}

// Fetch student details
$stmt = $conn->prepare("SELECT name, profile_picture, email FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc() ?? ['name' => 'Student', 'profile_picture' => null, 'email' => ''];
$stmt->close();

// Fetch motivational quote
$stmt = $conn->prepare("SELECT description FROM wellness_resources WHERE type = 'article' ORDER BY RAND() LIMIT 1");
$stmt->execute();
$quote_result = $stmt->get_result()->fetch_assoc();
$quote = $quote_result ? $quote_result['description'] : $motivational_quotes[array_rand($motivational_quotes)];
$stmt->close();

// Fetch available academic years
$stmt = $conn->prepare("SELECT DISTINCT year FROM academic_calendar ORDER BY year DESC");
$stmt->execute();
$academic_years = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))");
$stmt->bind_param("is", $user_id, $csrf_token);
$stmt->execute();
$stmt->close();

// Log activity
logActivity($conn, $user_id, "Accessed Student Dashboard", "Student accessed dashboard for academic year $academic_year");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - QueensPa School Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/js-confetti@0.12.0/dist/js-confetti.browser.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" defer></script>
    <style>
        :root {
            --primary-orange: #FF4500;
            --secondary-orange: #FF6B00;
            --accent-dark: #1a202c;
            --accent-light: #f9fafb;
            --text-primary: #1f2937;
            --text-light: #e2e8f0;
            --card-bg: rgba(255, 255, 255, 0.1);
            --card-border: rgba(255, 255, 255, 0.2);
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #1a202c 0%, #2d3748 100%);
            color: var(--text-light);
            min-height: 100vh;
            overflow-x: hidden;
        }

        .glass-card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            backdrop-filter: blur(10px);
            border-radius: 16px;
            transition: all 0.3s ease;
        }

        .glass-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
        }

        .gradient-header {
            background: linear-gradient(90deg, var(--primary-orange), var(--secondary-orange));
            border-radius: 12px;
        }

        .neon-glow {
            box-shadow: 0 0 10px var(--primary-orange), 0 0 20px var(--primary-orange);
        }

        .shimmer {
            position: relative;
            overflow: hidden;
        }

        .shimmer::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            100% { left: 100%; }
        }

        .bounce {
            animation: bounce 1s ease-in-out;
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-8px); }
            60% { transform: translateY(-4px); }
        }

        .pulse {
            animation: pulse 1.5s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.03); }
            100% { transform: scale(1); }
        }

        .dark {
            --card-bg: rgba(45, 55, 72, 0.1);
            --card-border: rgba(100, 100, 100, 0.2);
        }

        @media print {
            body { background: white; color: #000; }
            .no-print { display: none; }
            .print-section { display: block !important; }
            .glass-card { background: white; border: 1px solid #e2e8f0; }
        }

        @media (max-width: 640px) {
            .glass-card { padding: 1rem; }
            h1 { font-size: 1.75rem; }
            h2 { font-size: 1.25rem; }
        }

        .profile-pic {
            border: 2px solid var(--primary-orange);
            box-shadow: 0 0 15px var(--primary-orange);
            transition: transform 0.3s ease;
        }

        .profile-pic:hover {
            transform: scale(1.1);
        }

        .notification-bell {
            position: relative;
        }

        .notification-bell .badge {
            position: absolute;
            top: -0.5rem;
            right: -0.5rem;
            background: #ef4444;
            color: white;
            border-radius: 9999px;
            padding: 0.25rem 0.5rem;
            font-size: 0.75rem;
        }

        input, select {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--card-border);
            color: var(--text-light);
            transition: all 0.3s ease;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary-orange);
            box-shadow: 0 0 8px var(--primary-orange);
        }
    </style>
</head>
<body class="min-h-screen flex flex-col">
    <!-- Header -->
    <?php
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php';
    try {
        if (!file_exists($header_path)) {
            throw new Exception("Student header file missing: $header_path");
        }
        include $header_path;
    } catch (Exception $e) {
        error_log("Failed to include student_header.php: " . $e->getMessage());
        logActivity($conn, $user_id, "Error", "Failed to load student header template: " . $e->getMessage());
        $_SESSION['error_msg'] = "System error: Unable to load page header.";
        header("Location: /login.php");
        exit();
    }
    ?>

    <main class="container mx-auto p-4 sm:p-6 flex-grow">
        <!-- Welcome Section -->
        <section class="mb-8 bounce">
            <div class="glass-card gradient-header text-white p-6">
                <div class="flex flex-col sm:flex-row items-center justify-between">
                    <div class="flex items-center">
                        <img src="<?php echo htmlspecialchars($user['profile_picture'] ?: '/images/default-avatar.png'); ?>" alt="Profile Picture" class="profile-pic w-20 h-20 rounded-full mr-4 mb-4 sm:mb-0 cursor-pointer" onclick="window.location.href='/student/profile.php'">
                        <div>
                            <h1 class="text-3xl sm:text-4xl font-extrabold">Welcome, <?php echo htmlspecialchars($user['name']); ?>!</h1>
                            <p class="text-lg">Class: <?php echo htmlspecialchars($student_class_display); ?></p>
                            <p class="text-md italic mt-2">"<?php echo htmlspecialchars($quote); ?>"</p>
                        </div>
                    </div>
                    <div class="mt-4 sm:mt-0">
                        <select id="academicYearSelect" class="p-2 rounded-lg" aria-label="Select academic year">
                            <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo htmlspecialchars($year['year']); ?>" <?php echo $year['year'] === $academic_year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </section>

        <!-- Key Metrics -->
        <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <?php
            $metrics_data = [
                ['icon' => 'fa-tasks', 'label' => 'Assignments', 'value' => $metrics['assignments']],
                ['icon' => 'fa-exclamation-circle', 'label' => 'New Assignments', 'value' => $metrics['new_assignments']],
                ['icon' => 'fa-check-circle', 'label' => 'Attendance', 'value' => $metrics['attendance'] . '%'],
                ['icon' => 'fa-star', 'label' => 'Average Grade', 'value' => $metrics['avg_grade'] . '%'],
                ['icon' => 'fa-medal', 'label' => 'Badges', 'value' => $metrics['badges']],
                ['icon' => 'fa-book', 'label' => 'Library Loans', 'value' => $metrics['library_loans']],
                ['icon' => 'fa-users', 'label' => 'Clubs', 'value' => $metrics['clubs']],
                ['icon' => 'fa-money-bill', 'label' => 'Fees Due', 'value' => $metrics['fees_due']],
            ];
            foreach ($metrics_data as $metric): ?>
                <div class="glass-card p-4 pulse" role="button" aria-label="<?php echo $metric['label']; ?>">
                    <h3 class="text-lg font-semibold"><i class="fas <?php echo $metric['icon']; ?> mr-2"></i><?php echo $metric['label']; ?></h3>
                    <p class="text-2xl font-bold"><?php echo $metric['value']; ?></p>
                </div>
            <?php endforeach; ?>
        </section>

        <!-- Calendar -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-calendar-alt mr-2"></i>Your Schedule</h2>
            <div id="calendar" class="glass-card p-4 shimmer"></div>
        </section>

        <!-- New Assignments -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-exclamation-circle mr-2"></i>New Assignments (Due This Week)</h2>
            <div class="flex flex-col sm:flex-row justify-between mb-4 no-print">
                <input type="text" id="newAssignmentSearch" class="p-2 rounded-lg w-full sm:w-1/2 mr-0 sm:mr-2 mb-2 sm:mb-0" placeholder="Search new assignments..." aria-label="Search new assignments">
                <select id="newAssignmentFilter" class="p-2 rounded-lg w-full sm:w-1/4" aria-label="Filter new assignments by status">
                    <option value="all">All</option>
                    <option value="pending">Pending</option>
                    <option value="submitted">Submitted</option>
                    <option value="graded">Graded</option>
                </select>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse glass-card">
                    <thead class="gradient-header text-white">
                        <tr>
                            <th class="p-3">Title</th>
                            <th class="p-3">Subject</th>
                            <th class="p-3">Due Date</th>
                            <th class="p-3">Progress</th>
                            <th class="p-3 no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="newAssignmentTable">
                        <?php
                        $stmt = $conn->prepare("
                            SELECT a.id, a.title, s.subject_name, a.due_date, asub.status, IFNULL(asub.score, 0) as score, a.max_points
                            FROM assignments a
                            JOIN student_classes sc ON a.class_id = sc.class_id
                            JOIN subjects s ON a.subject_id = s.id
                            LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND asub.user_id = ?
                            WHERE sc.user_id = ? AND sc.academic_year = ? AND a.status = 'published' AND a.due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
                            ORDER BY a.due_date ASC
                            LIMIT ? OFFSET ?
                        ");
                        $offset = ($page - 1) * $per_page;
                        $stmt->bind_param("iisii", $user_id, $user_id, $academic_year, $per_page, $offset);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            $progress = $row['max_points'] > 0 ? ($row['score'] / $row['max_points']) * 100 : 0;
                            $status = $row['status'] ?? 'pending';
                            echo "<tr class='hover:bg-gray-700'>
                                <td class='p-3'>" . htmlspecialchars($row['title']) . "</td>
                                <td class='p-3'>" . htmlspecialchars($row['subject_name']) . "</td>
                                <td class='p-3'>" . htmlspecialchars($row['due_date']) . "</td>
                                <td class='p-3 flex items-center'>
                                    <span class='mr-2'>" . htmlspecialchars($status) . "</span>
                                    <div class='w-12 bg-gray-600 rounded-full h-1.5'>
                                        <div class='bg-orange-500 h-1.5 rounded-full' style='width: {$progress}%'></div>
                                    </div>
                                </td>
                                <td class='p-3 no-print'>
                                    <button class='text-orange-400 hover:text-orange-300 focus:ring-2 focus:ring-orange-500' data-bs-toggle='modal' data-bs-target='#assignmentModal' data-assignment-id='{$row['id']}' aria-label='View assignment {$row['title']}'>View</button>
                                </td>
                            </tr>";
                        }
                        $stmt->close();
                        ?>
                    </tbody>
                </table>
                <!-- Pagination -->
                <?php
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM assignments a JOIN student_classes sc ON a.class_id = sc.class_id WHERE sc.user_id = ? AND sc.academic_year = ? AND a.status = 'published' AND a.due_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)");
                $stmt->bind_param("is", $user_id, $academic_year);
                $stmt->execute();
                $total = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
                $stmt->close();
                $pages = ceil($total / $per_page);
                ?>
                <nav class="mt-4 flex justify-center no-print" aria-label="New assignments pagination">
                    <ul class="flex space-x-2">
                        <?php for ($i = 1; $i <= $pages; $i++): ?>
                            <li>
                                <a class="px-3 py-1 glass-card <?php echo $i === $page ? 'bg-orange-500 text-white' : 'text-orange-400'; ?> hover:bg-orange-600" href="?page=<?php echo $i; ?>" aria-current="<?php echo $i === $page ? 'page' : 'false'; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
        </section>

        <!-- All Assignments -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-tasks mr-2"></i>All Assignments</h2>
            <div class="flex flex-col sm:flex-row justify-between mb-4 no-print">
                <input type="text" id="assignmentSearch" class="p-2 rounded-lg w-full sm:w-1/2 mr-0 sm:mr-2 mb-2 sm:mb-0" placeholder="Search assignments..." aria-label="Search assignments">
                <select id="assignmentFilter" class="p-2 rounded-lg w-full sm:w-1/4" aria-label="Filter assignments by status">
                    <option value="all">All</option>
                    <option value="pending">Pending</option>
                    <option value="submitted">Submitted</option>
                    <option value="graded">Graded</option>
                </select>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse glass-card">
                    <thead class="gradient-header text-white">
                        <tr>
                            <th class="p-3">Title</th>
                            <th class="p-3">Subject</th>
                            <th class="p-3">Due Date</th>
                            <th class="p-3">Status</th>
                            <th class="p-3 no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="assignmentTable">
                        <?php
                        $stmt = $conn->prepare("
                            SELECT a.id, a.title, s.subject_name, a.due_date, asub.status
                            FROM assignments a
                            JOIN student_classes sc ON a.class_id = sc.class_id
                            JOIN subjects s ON a.subject_id = s.id
                            LEFT JOIN assignment_submissions asub ON a.id = asub.assignment_id AND asub.user_id = ?
                            WHERE sc.user_id = ? AND sc.academic_year = ? AND a.status = 'published'
                            ORDER BY a.due_date DESC
                            LIMIT ? OFFSET ?
                        ");
                        $stmt->bind_param("iisii", $user_id, $user_id, $academic_year, $per_page, $offset);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            $status = $row['status'] ?? 'pending';
                            echo "<tr class='hover:bg-gray-700'>
                                <td class='p-3'>" . htmlspecialchars($row['title']) . "</td>
                                <td class='p-3'>" . htmlspecialchars($row['subject_name']) . "</td>
                                <td class='p-3'>" . htmlspecialchars($row['due_date']) . "</td>
                                <td class='p-3'>" . htmlspecialchars($status) . "</td>
                                <td class='p-3 no-print'>
                                    <button class='text-orange-400 hover:text-orange-300 focus:ring-2 focus:ring-orange-500' data-bs-toggle='modal' data-bs-target='#assignmentModal' data-assignment-id='{$row['id']}' aria-label='View assignment {$row['title']}'>View</button>
                                </td>
                            </tr>";
                        }
                        $stmt->close();
                        ?>
                    </tbody>
                </table>
                <!-- Pagination -->
                <?php
                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM assignments a JOIN student_classes sc ON a.class_id = sc.class_id WHERE sc.user_id = ? AND sc.academic_year = ? AND a.status = 'published'");
                $stmt->bind_param("is", $user_id, $academic_year);
                $stmt->execute();
                $total = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
                $stmt->close();
                $pages = ceil($total / $per_page);
                ?>
                <nav class="mt-4 flex justify-center no-print" aria-label="All assignments pagination">
                    <ul class="flex space-x-2">
                        <?php for ($i = 1; $i <= $pages; $i++): ?>
                            <li>
                                <a class="px-3 py-1 glass-card <?php echo $i === $page ? 'bg-orange-500 text-white' : 'text-orange-400'; ?> hover:bg-orange-600" href="?page=<?php echo $i; ?>" aria-current="<?php echo $i === $page ? 'page' : 'false'; ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
        </section>

        <!-- Assignment Modal -->
        <div class="modal fade" id="assignmentModal" tabindex="-1" aria-labelledby="assignmentModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content glass-card">
                    <div class="modal-header gradient-header text-white">
                        <h5 class="modal-title" id="assignmentModalLabel">Assignment Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="assignmentModalBody"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary glass-card" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Grades Chart -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-chart-bar mr-2"></i>Your Grades</h2>
            <div class="mb-4 no-print">
                <select id="termFilter" class="p-2 rounded-lg w-full sm:w-1/4" aria-label="Filter grades by term">
                    <option value="all">All Terms</option>
                    <?php
                    $stmt = $conn->prepare("SELECT id, term_name FROM academic_calendar WHERE year = ?");
                    $stmt->bind_param("s", $academic_year);
                    $stmt->execute();
                    $terms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $stmt->close();
                    foreach ($terms as $term) {
                        echo "<option value='{$term['id']}'>" . htmlspecialchars($term['term_name']) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <canvas id="gradesChart" class="glass-card p-4"></canvas>
        </section>

        <!-- Notifications -->
        <section class="mb-8" id="notifications">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-bell mr-2"></i>Notifications</h2>
            <div class="flex justify-end mb-2 no-print">
                <button id="markAllRead" class="text-orange-400 hover:text-orange-300" aria-label="Mark all notifications as read">Mark All as Read</button>
            </div>
            <ul class="glass-card divide-y">
                <?php
                $stmt = $conn->prepare("
                    SELECT id, title, message, is_read, created_at, category
                    FROM notifications
                    WHERE user_id = ? OR target_role = 'student'
                    ORDER BY created_at DESC
                    LIMIT ? OFFSET ?
                ");
                $stmt->bind_param("iii", $user_id, $per_page, $offset);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $read_class = $row['is_read'] ? 'opacity-50' : '';
                    $category_class = $row['category'] === 'academic' ? 'bg-blue-900/20' : ($row['category'] === 'extracurricular' ? 'bg-green-900/20' : 'bg-yellow-900/20');
                    echo "<li class='p-4 $read_class $category_class' data-notification-id='{$row['id']}'>
                        <div class='flex justify-between'>
                            <div>
                                <h3 class='font-semibold'>" . htmlspecialchars($row['title']) . "</h3>
                                <p>" . htmlspecialchars($row['message']) . "</p>
                                <span class='text-sm text-gray-400'>" . htmlspecialchars($row['created_at']) . "</span>
                            </div>
                            <button class='text-orange-400 hover:text-orange-300 no-print mark-read' data-id='{$row['id']}' aria-label='Mark notification {$row['title']} as read'>Mark as Read</button>
                        </div>
                    </li>";
                }
                $stmt->close();
                ?>
            </ul>
            <!-- Pagination -->
            <?php
            $stmt = $conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? OR target_role = 'student'");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $total = $stmt->get_result()->fetch_assoc()['count'] ?? 0;
            $stmt->close();
            $pages = ceil($total / $per_page);
            ?>
            <nav class="mt-4 flex justify-center no-print" aria-label="Notifications pagination">
                <ul class="flex space-x-2">
                    <?php for ($i = 1; $i <= $pages; $i++): ?>
                        <li>
                            <a class="px-3 py-1 glass-card <?php echo $i === $page ? 'bg-orange-500 text-white' : 'text-orange-400'; ?> hover:bg-orange-600" href="?page=<?php echo $i; ?>" aria-current="<?php echo $i === $page ? 'page' : 'false'; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                </ul>
            </nav>
        </section>

        <!-- Schedule -->
        <section class="mb-8 print-section hidden">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-calendar-week mr-2"></i>Weekly Schedule</h2>
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse glass-card">
                    <thead class="gradient-header text-white">
                        <tr>
                            <th class="p-3">Day</th>
                            <th class="p-3">Subject</th>
                            <th class="p-3">Time</th>
                            <th class="p-3">Class</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $conn->prepare("
                            SELECT cs.day_of_week, s.subject_name, cs.start_time, cs.end_time, c.class_name
                            FROM class_schedules cs
                            JOIN student_classes sc ON cs.class_id = sc.class_id
                            JOIN subjects s ON cs.subject_id = s.id
                            JOIN classes c ON cs.class_id = c.id
                            WHERE sc.user_id = ? AND sc.academic_year = ?
                            ORDER BY FIELD(cs.day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'), cs.start_time
                        ");
                        $stmt->bind_param("is", $user_id, $academic_year);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            echo "<tr class='hover:bg-gray-700'>
                                <td class='p-3'>" . htmlspecialchars($row['day_of_week']) . "</td>
                                <td class='p-3'>" . htmlspecialchars($row['subject_name']) . "</td>
                                <td class='p-3'>" . htmlspecialchars($row['start_time']) . " - " . htmlspecialchars($row['end_time']) . "</td>
                                <td class='p-3'>" . htmlspecialchars($row['class_name']) . "</td>
                            </tr>";
                        }
                        $stmt->close();
                        ?>
                    </tbody>
                </table>
            </div>
            <button class="mt-4 glass-card bg-orange-500 text-white px-4 py-2 hover:bg-orange-600 no-print" onclick="window.print()" aria-label="Print schedule">Print Schedule</button>
        </section>

        <!-- Group Projects -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-users mr-2"></i>Group Projects</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php
                $stmt = $conn->prepare("
                    SELECT g.id, g.name, g.project_title, g.deadline, g.max_points, gm.role,
                           IFNULL(SUM(asub.score) / g.max_points * 100, 0) as progress
                    FROM groups g
                    JOIN group_members gm ON g.id = gm.group_id
                    LEFT JOIN assignment_submissions asub ON g.id = asub.group_id
                    WHERE gm.user_id = ? AND g.status = 'active'
                    GROUP BY g.id
                    LIMIT 6
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $progress = min($row['progress'], 100);
                    echo "<div class='glass-card p-4'>
                        <h3 class='text-lg font-semibold'>" . htmlspecialchars($row['project_title']) . "</h3>
                        <p>Group: " . htmlspecialchars($row['name']) . "</p>
                        <p>Role: " . htmlspecialchars($row['role']) . "</p>
                        <p>Deadline: " . htmlspecialchars($row['deadline']) . "</p>
                        <div class='w-full bg-gray-600 rounded-full h-2.5'>
                            <div class='bg-green-500 h-2.5 rounded-full' style='width: {$progress}%'></div>
                        </div>
                        <button class='text-orange-400 hover:text-orange-300 no-print mt-2' data-bs-toggle='modal' data-bs-target='#groupModal' data-group-id='{$row['id']}' aria-label='View group project {$row['project_title']}'>View Details</button>
                    </div>";
                }
                $stmt->close();
                ?>
            </div>
        </section>

        <!-- Group Modal -->
        <div class="modal fade" id="groupModal" tabindex="-1" aria-labelledby="groupModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content glass-card">
                    <div class="modal-header gradient-header text-white">
                        <h5 class="modal-title" id="groupModalLabel">Group Project Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="groupModalBody"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary glass-card" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Library Loans -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-book mr-2"></i>Library Books</h2>
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse glass-card">
                    <thead class="gradient-header text-white">
                        <tr>
                            <th class="p-3">Book Title</th>
                            <th class="p-3">Loan Date</th>
                            <th class="p-3">Due Date</th>
                            <th class="p-3">Status</th>
                            <th class="p-3 no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $conn->prepare("
                            SELECT lb.title, ll.loan_date, ll.due_date, ll.status
                            FROM library_loans ll
                            JOIN library_books lb ON ll.book_id = lb.id
                            WHERE ll.user_id = ? AND ll.status = 'active'
                            LIMIT 5
                        ");
                        $stmt->bind_param("i", $user_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            echo "<tr class='hover:bg-gray-700'>
                                <td class='p-3'>" . htmlspecialchars($row['title']) . "</td>
                                <td class='p-3'>" . htmlspecialchars($row['loan_date']) . "</td>
                                <td class='p-3'>" . htmlspecialchars($row['due_date']) . "</td>
                                <td class='p-3'>" . htmlspecialchars($row['status']) . "</td>
                                <td class='p-3 no-print'>
                                    <a href='/library/view.php?title=" . urlencode($row['title']) . "' class='text-orange-400 hover:text-orange-300' aria-label='View book {$row['title']}'>View</a>
                                </td>
                            </tr>";
                        }
                        $stmt->close();
                        ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Clubs & Events -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-users-cog mr-2"></i>Clubs & Events</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php
                $stmt = $conn->prepare("
                    SELECT c.name, c.description, cm.role, ce.title as event_title, ce.event_date
                    FROM club_memberships cm
                    JOIN clubs c ON cm.club_id = c.id
                    LEFT JOIN club_events ce ON c.id = ce.club_id AND ce.event_date >= NOW()
                    WHERE cm.user_id = ? AND cm.status = 'active'
                    ORDER BY ce.event_date ASC
                    LIMIT 6
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    echo "<div class='glass-card p-4'>
                        <h3 class='text-lg font-semibold'>" . htmlspecialchars($row['name']) . "</h3>
                        <p>" . htmlspecialchars(substr($row['description'] ?? '', 0, 100)) . "...</p>
                        <p>Role: " . htmlspecialchars($row['role']) . "</p>";
                    if ($row['event_title']) {
                        echo "<p>Upcoming Event: " . htmlspecialchars($row['event_title']) . " on " . htmlspecialchars($row['event_date']) . "</p>";
                    }
                    echo "<button class='text-orange-400 hover:text-orange-300 no-print mt-2' data-bs-toggle='modal' data-bs-target='#clubModal' data-club-name='{$row['name']}' aria-label='View club {$row['name']}'>View Club</button>
                    </div>";
                }
                $stmt->close();
                ?>
            </div>
        </section>

        <!-- Club Modal -->
        <div class="modal fade" id="clubModal" tabindex="-1" aria-labelledby="clubModalLabel" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content glass-card">
                    <div class="modal-header gradient-header text-white">
                        <h5 class="modal-title" id="clubModalLabel">Club Details</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="clubModalBody"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary glass-card" data-bs-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fees Status -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-money-bill mr-2"></i>Fees Status</h2>
            <div class="overflow-x-auto">
                <table class="w-full table-auto border-collapse glass-card">
                    <thead class="gradient-header text-white">
                        <tr>
                            <th class="p-3">Fee Type</th>
                            <th class="p-3">Amount</th>
                            <th class="p-3">Due Date</th>
                            <th class="p-3">Status</th>
                            <th class="p-3 no-print">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $stmt = $conn->prepare("
                            SELECT f.fee_type, f.amount, f.due_date, p.payment_date
                            FROM fees f
                            LEFT JOIN payments p ON f.id = p.fee_id AND p.user_id = ?
                            WHERE f.user_id = ?
                            ORDER BY f.due_date DESC
                            LIMIT 5
                        ");
                        $stmt->bind_param("ii", $user_id, $user_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            $status = $row['payment_date'] ? 'Paid' : 'Pending';
                            echo "<tr class='hover:bg-gray-700'>
                                <td class='p-3'>" . htmlspecialchars($row['fee_type']) . "</td>
                                <td class='p-3'>" . htmlspecialchars($row['amount']) . "</td>
                                <td class='p-3'>" . htmlspecialchars($row['due_date']) . "</td>
                                <td class='p-3'>" . htmlspecialchars($status) . "</td>
                                <td class='p-3 no-print'>
                                    <a href='/fees/pay.php?type=" . urlencode($row['fee_type']) . "' class='text-orange-400 hover:text-orange-300' aria-label='Pay fee {$row['fee_type']}'>Pay Now</a>
                                </td>
                            </tr>";
                        }
                        $stmt->close();
                        ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Learning Resources -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-book-open mr-2"></i>Learning Resources</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php
                $stmt = $conn->prepare("
                    SELECT title, description, url as content_url
                    FROM learning_resources
                    WHERE access_level IN ('public', 'class')
                    ORDER BY created_at DESC
                    LIMIT 6
                ");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    echo "<div class='glass-card p-4'>
                        <h3 class='text-lg font-semibold'>" . htmlspecialchars($row['title']) . "</h3>
                        <p>" . htmlspecialchars(substr($row['description'] ?? '', 0, 100)) . "...</p>
                        <a href='" . htmlspecialchars($row['content_url']) . "' class='text-orange-400 hover:text-orange-300 no-print' target='_blank' aria-label='Access resource {$row['title']}'>Access Resource</a>
                    </div>";
                }
                $stmt->close();
                ?>
            </div>
        </section>

        <!-- Forum Activity -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-comments mr-2"></i>Forum Activity</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <?php
                $stmt = $conn->prepare("
                    SELECT ft.title, ft.description, fp.content, fp.created_at
                    FROM forum_threads ft
                    JOIN forum_posts fp ON ft.id = fp.thread_id
                    WHERE ft.class_id IN (SELECT class_id FROM student_classes WHERE user_id = ?)
                    ORDER BY fp.created_at DESC
                    LIMIT 4
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    echo "<div class='glass-card p-4'>
                        <h3 class='text-lg font-semibold'>" . htmlspecialchars($row['title']) . "</h3>
                        <p>" . htmlspecialchars(substr($row['description'] ?? '', 0, 100)) . "...</p>
                        <p class='text-sm'>" . htmlspecialchars(substr($row['content'] ?? '', 0, 50)) . "...</p>
                        <span class='text-sm text-gray-400'>" . htmlspecialchars($row['created_at']) . "</span>
                        <a href='/forum/view.php?title=" . urlencode($row['title']) . "' class='text-orange-400 hover:text-orange-300 no-print' aria-label='Join discussion {$row['title']}'>Join Discussion</a>
                    </div>";
                }
                $stmt->close();
                ?>
            </div>
        </section>

        <!-- Badges & Progress -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-medal mr-2"></i>Badges & Achievements</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php
                $stmt = $conn->prepare("
                    SELECT b.name, b.points_required, sp.points_earned, b.icon
                    FROM badges b
                    LEFT JOIN student_progress sp ON b.id = sp.badge_id AND sp.user_id = ?
                    WHERE sp.points_earned IS NOT NULL
                    LIMIT 6
                ");
                $stmt->bind_param("i", $user_id);
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    $progress = min(($row['points_earned'] / $row['points_required']) * 100, 100);
                    $is_earned = $row['points_earned'] >= $row['points_required'];
                    $badge_class = $is_earned ? 'badge-earned pulse neon-glow' : '';
                    echo "<div class='glass-card p-4 $badge_class' data-badge-name='{$row['name']}' role='button' aria-label='Badge {$row['name']}'>
                        <div class='flex items-center'>
                            <img src='" . htmlspecialchars($row['icon'] ?: '/images/default-badge.png') . "' alt='Badge Icon' class='w-12 h-12 mr-2'>
                            <div>
                                <h3 class='font-semibold'>" . htmlspecialchars($row['name']) . "</h3>
                                <div class='w-full bg-gray-600 rounded-full h-2.5'>
                                    <div class='bg-green-500 h-2.5 rounded-full' style='width: {$progress}%'></div>
                                </div>
                                <p class='text-sm'>{$row['points_earned']} / {$row['points_required']} points</p>
                            </div>
                        </div>
                    </div>";
                }
                $stmt->close();
                ?>
            </div>
        </section>

        <!-- Wellness Resources -->
        <section class="mb-8">
            <h2 class="text-2xl font-bold mb-4"><i class="fas fa-heart mr-2"></i>Wellness Tips</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php
                $stmt = $conn->prepare("
                    SELECT title, description, content_url
                    FROM wellness_resources
                    WHERE type IN ('article', 'link')
                    ORDER BY created_at DESC
                    LIMIT 6
                ");
                $stmt->execute();
                $result = $stmt->get_result();
                while ($row = $result->fetch_assoc()) {
                    echo "<div class='glass-card p-4'>
                        <h3 class='text-lg font-semibold'>" . htmlspecialchars($row['title']) . "</h3>
                        <p>" . htmlspecialchars(substr($row['description'] ?? '', 0, 100)) . "...</p>
                        <a href='" . htmlspecialchars($row['content_url']) . "' class='text-orange-400 hover:text-orange-300 no-print' target='_blank' aria-label='Learn more about {$row['title']}'>Learn More</a>
                    </div>";
                }
                $stmt->close();
                ?>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="gradient-header text-white p-6">
        <div class="container mx-auto text-center">
            <p class="mb-2">© 2025 QueensPa School. All rights reserved.</p>
            <div class="flex justify-center space-x-4 mb-2">
                <a href="https://facebook.com/profile" class="text-white hover:text-orange-300" aria-label="Facebook Profile"><i class="fab fa-facebook mr-1"></i>Facebook Profile</a>
                <a href="https://facebook.com/page" class="text-white hover:text-orange-300" aria-label="Facebook Page"><i class="fab fa-facebook-square mr-1"></i>Facebook Page</a>
                <a href="https://twitter.com" class="text-white hover:text-orange-300" aria-label="Twitter"><i class="fab fa-twitter mr-1"></i>Twitter</a>
                <a href="https://instagram.com" class="text-white hover:text-orange-300" aria-label="Instagram"><i class="fab fa-instagram mr-1"></i>Instagram</a>
                <a href="https://linkedin.com" class="text-white hover:text-orange-300" aria-label="LinkedIn"><i class="fab fa-linkedin mr-1"></i>LinkedIn</a>
                <a href="https://youtube.com" class="text-white hover:text-orange-300" aria-label="YouTube"><i class="fab fa-youtube mr-1"></i>YouTube</a>
            </div>
            <p>Developed by NerdSpring | Contact: <a href="mailto:nerdspring@gmail07.com" class="hover:text-orange-300" aria-label="Email Nerdspring">Email</a> | <a href="https://wa.me/233534219854" class="hover:text-orange-300" aria-label="WhatsApp Kadevelops">WhatsApp</a></p>
        </div>
    </footer>

    <script>
        // Initialize Confetti
        const confetti = new JSConfetti();

        // Academic Year Selection
        document.addEventListener('DOMContentLoaded', function() {
            const academicYearSelect = document.getElementById('academicYearSelect');
            const storedAcademicYear = localStorage.getItem('selectedAcademicYear');
            if (storedAcademicYear) {
                academicYearSelect.value = storedAcademicYear;
            }
            academicYearSelect.addEventListener('change', function() {
                const selectedYear = this.value;
                localStorage.setItem('selectedAcademicYear', selectedYear);
                fetch('/api/update_session.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ academic_year: selectedYear, csrf_token: '<?php echo $csrf_token; ?>' })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    }
                });
            });

            // Calendar Initialization
            const calendarEl = document.getElementById('calendar');
            const calendar = new FullCalendar.Calendar(calendarEl, {
                initialView: window.innerWidth < 640 ? 'listWeek' : 'dayGridMonth',
                events: `/api/events.php?user_id=${<?php echo $user_id; ?>}&academic_year=${encodeURIComponent('<?php echo $academic_year; ?>')}`,
                eventColor: '#FF4500',
                eventClick: function(info) {
                    const modal = new bootstrap.Modal(document.getElementById('eventModal'));
                    document.getElementById('eventModalBody').innerHTML = `
                        <h3 class="font-semibold">${info.event.title}</h3>
                        <p>Date: ${info.event.start.toLocaleDateString()}</p>
                    `;
                    modal.show();
                }
            });
            calendar.render();

            // Event Modal
            const eventModal = document.createElement('div');
            eventModal.className = 'modal fade';
            eventModal.id = 'eventModal';
            eventModal.setAttribute('tabindex', '-1');
            eventModal.setAttribute('aria-labelledby', 'eventModalLabel');
            eventModal.setAttribute('aria-hidden', 'true');
            eventModal.innerHTML = `
                <div class="modal-dialog">
                    <div class="modal-content glass-card">
                        <div class="modal-header gradient-header text-white">
                            <h5 class="modal-title" id="eventModalLabel">Event Details</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" id="eventModalBody"></div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary glass-card" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(eventModal);

            // Grades Chart
            const ctx = document.getElementById('gradesChart').getContext('2d');
            let gradesChart = new Chart(ctx, {
                type: 'radar',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Grades (%)',
                        data: [],
                        backgroundColor: 'rgba(255, 69, 0, 0.2)',
                        borderColor: '#FF4500',
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    scales: { r: { beginAtZero: true, max: 100 } },
                    plugins: { legend: { position: 'bottom', labels: { color: '#e2e8f0' } } }
                }
            });

            // Fetch Grades Data
            function updateGradesChart(termId = 'all') {
                fetch(`/api/grades.php?user_id=${<?php echo $user_id; ?>}&academic_year=${encodeURIComponent('<?php echo $academic_year; ?>')}&term_id=${termId}`)
                    .then(response => response.json())
                    .then(data => {
                        gradesChart.data.labels = data.subjects;
                        gradesChart.data.datasets[0].data = data.grades;
                        gradesChart.update();
                    });
            }
            updateGradesChart();
            document.getElementById('termFilter').addEventListener('change', function() {
                updateGradesChart(this.value);
            });

            // Assignment Search and Filter
            function setupTableFilter(searchId, filterId, tableId) {
                const search = document.getElementById(searchId);
                const filter = document.getElementById(filterId);
                const table = document.getElementById(tableId);
                const rows = table.getElementsByTagName('tr');

                function filterTable() {
                    const searchText = search.value.toLowerCase();
                    const filterValue = filter.value;
                    for (let row of rows) {
                        const title = row.cells[0].textContent.toLowerCase();
                        const status = row.cells[3].textContent.toLowerCase();
                        const matchesSearch = title.includes(searchText);
                        const matchesFilter = filterValue === 'all' || status === filterValue;
                        row.style.display = matchesSearch && matchesFilter ? '' : 'none';
                    }
                }
                search.addEventListener('input', filterTable);
                filter.addEventListener('change', filterTable);
            }
            setupTableFilter('assignmentSearch', 'assignmentFilter', 'assignmentTable');
            setupTableFilter('newAssignmentSearch', 'newAssignmentFilter', 'newAssignmentTable');

            // Assignment Modal Population
            document.querySelectorAll('[data-assignment-id]').forEach(button => {
                button.addEventListener('click', function() {
                    const assignmentId = this.getAttribute('data-assignment-id');
                    fetch(`/api/assignment_details.php?id=${assignmentId}&user_id=${<?php echo $user_id; ?>}&csrf_token=${<?php echo json_encode($csrf_token); ?>}&academic_year=${encodeURIComponent('<?php echo $academic_year; ?>')}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                document.getElementById('assignmentModalBody').innerHTML = `
                                    <h3 class="font-semibold">${data.title}</h3>
                                    <p>Subject: ${data.subject_name}</p>
                                    <p>Due Date: ${data.due_date}</p>
                                    <p>Status: ${data.status || 'Pending'}</p>
                                    <p>Description: ${data.description || 'No description'}</p>
                                `;
                            }
                        });
                });
            });

            // Group Modal Population
            document.querySelectorAll('[data-group-id]').forEach(button => {
                button.addEventListener('click', function() {
                    const groupId = this.getAttribute('data-group-id');
                    fetch(`/api/group_details.php?id=${groupId}&user_id=${<?php echo $user_id; ?>}&csrf_token=${<?php echo json_encode($csrf_token); ?>}&academic_year=${encodeURIComponent('<?php echo $academic_year; ?>')}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                document.getElementById('groupModalBody').innerHTML = `
                                    <h3 class="font-semibold">${data.project_title}</h3>
                                    <p>Group: ${data.name}</p>
                                    <p>Deadline: ${data.deadline}</p>
                                    <p>Members: ${data.members.join(', ')}</p>
                                `;
                            }
                        });
                });
            });

            // Club Modal Population
            document.querySelectorAll('[data-club-name]').forEach(button => {
                button.addEventListener('click', function() {
                    const clubName = this.getAttribute('data-club-name');
                    fetch(`/api/club_details.php?name=${encodeURIComponent(clubName)}&user_id=${<?php echo $user_id; ?>}&csrf_token=${<?php echo json_encode($csrf_token); ?>}&academic_year=${encodeURIComponent('<?php echo $academic_year; ?>')}`)
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                document.getElementById('clubModalBody').innerHTML = `
                                    <h3 class="font-semibold">${data.name}</h3>
                                    <p>${data.description}</p>
                                    <p>Upcoming Events: ${data.events.join(', ') || 'None'}</p>
                                `;
                            }
                        });
                });
            });

            // Mark Notification as Read
            document.querySelectorAll('.mark-read').forEach(button => {
                button.addEventListener('click', function() {
                    const notificationId = this.getAttribute('data-id');
                    fetch('/api/mark_notification_read.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: notificationId, csrf_token: '<?php echo $csrf_token; ?>' })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            this.parentElement.classList.add('opacity-50');
                            this.remove();
                            updateNotificationBadge();
                            playNotificationSound();
                            confetti.addConfetti({ confettiNumber: 50 });
                        }
                    });
                });
            });

            // Mark All Notifications as Read
            document.getElementById('markAllRead').addEventListener('click', function() {
                fetch('/api/mark_all_notifications_read.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: <?php echo $user_id; ?>, csrf_token: '<?php echo $csrf_token; ?>' })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.querySelectorAll('[data-notification-id]').forEach(li => {
                            li.classList.add('opacity-50');
                            li.querySelector('.mark-read')?.remove();
                        });
                        updateNotificationBadge();
                        playNotificationSound();
                        confetti.addConfetti({ confettiNumber: 100 });
                    }
                });
            });

            // Notification Sound
            function playNotificationSound() {
                const audio = new Audio('/sounds/notification.mp3');
                audio.play().catch(() => {});
            }

            // Update Notification Badge
            function updateNotificationBadge() {
                fetch(`/api/unread_notifications.php?user_id=${<?php echo $user_id; ?>}`)
                    .then(response => response.json())
                    .then(data => {
                        const badge = document.querySelector('.notification-bell .badge');
                        if (data.count > 0) {
                            badge.textContent = data.count;
                            badge.style.display = 'block';
                        } else {
                            badge.style.display = 'none';
                        }
                    });
            }

            // Badge Confetti Animation
            document.querySelectorAll('.badge-earned').forEach(badge => {
                badge.addEventListener('click', () => {
                    confetti.addConfetti({
                        emojis: ['🏆', '🎉', '🌟'],
                        emojiSize: 50,
                        confettiNumber: 150
                    });
                });
            });

            // Dark Mode Toggle
            const darkModeToggle = document.createElement('button');
            darkModeToggle.innerHTML = '<i class="fas fa-moon"></i>';
            darkModeToggle.className = 'fixed bottom-4 right-4 glass-card bg-orange-500 text-white p-2 rounded-full no-print hover:bg-orange-600';
            darkModeToggle.setAttribute('aria-label', 'Toggle dark mode');
            document.body.appendChild(darkModeToggle);
            darkModeToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark');
                localStorage.setItem('darkMode', document.body.classList.contains('dark'));
            });
            if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark');
        });
    </script>
</body>
</html>