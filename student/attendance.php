<?php
ob_start();
session_start();

// Enable error reporting for debugging (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

// Ensure user is logged in and is a student
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'student') {
    header('Location: /login.php');
    exit;
}

// Get student data
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT name, profile_picture FROM users WHERE id = ? AND role = 'student'");
if (!$stmt) {
    error_log("Error preparing user query: " . $conn->error);
    die("System error: Please contact support.");
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
if (!$student) {
    header('Location: /login.php');
    exit;
}
$student_name = htmlspecialchars($student['name'] ?? 'Student');
$profile_picture = $student['profile_picture'] ? htmlspecialchars($student['profile_picture']) : '/assets/images/default_avatar.png';
$stmt->close();

// Current academic year
$current_academic_year = date('Y') . '/' . (date('Y') + 1);

// Fetch current academic term based on today's date (May 28, 2025)
$current_date = date('Y-m-d');
$stmt = $conn->prepare("SELECT id, term_name, start_date FROM academic_calendar WHERE start_date <= ? AND end_date >= ? LIMIT 1");
if (!$stmt) {
    error_log("Error preparing term query: " . $conn->error);
    die("System error: Please contact support.");
}
$stmt->bind_param("ss", $current_date, $current_date);
$stmt->execute();
$current_term = $stmt->get_result()->fetch_assoc();
$current_term_id = $current_term ? (int)$current_term['id'] : 0;
$current_term_name = $current_term ? htmlspecialchars($current_term['term_name']) : 'Current Term';
$default_start_date = $current_term ? $current_term['start_date'] : date('Y-m-d', strtotime('-30 days'));
$stmt->close();

// Fetch all terms for filter
$stmt = $conn->prepare("SELECT id, term_name FROM academic_calendar ORDER BY start_date DESC");
if (!$stmt) {
    error_log("Error preparing terms query: " . $conn->error);
    die("System error: Please contact support.");
}
$stmt->execute();
$terms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Fetch classes for enrolled student
$classes = [];
$stmt = $conn->prepare("SELECT c.id, c.class_name 
    FROM classes c 
    JOIN student_classes sc ON c.id = sc.class_id 
    WHERE sc.user_id = ? AND sc.academic_year = ?");
if (!$stmt) {
    error_log("Error preparing classes query: " . $conn->error);
    die("System error retrieving classes.");
}
$stmt->bind_param("is", $user_id, $current_academic_year);
$stmt->execute();
$classes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Handle filters
$class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$term_id = isset($_GET['term_id']) ? (int)$_GET['term_id'] : $current_term_id;
$start_date = isset($_GET['start_date']) && !empty($_GET['start_date']) ? $_GET['start_date'] : $default_start_date;
$end_date = isset($_GET['end_date']) && !empty($_GET['end_date']) ? $_GET['end_date'] : $current_date;

// Validate dates
$start_date = date('Y-m-d', strtotime($start_date));
$end_date = date('Y-m-d', strtotime($end_date));
if ($start_date > $end_date) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

// Fetch attendance records
$query = "
    SELECT a.id, a.date, a.status, a.type, c.class_name, 
           s.subject_name, CONCAT(t.first_name, ' ', t.last_name) AS teacher_name,
           ac.term_name
    FROM attendance a
    JOIN classes c ON a.class_id = c.id
    JOIN student_classes sc ON c.id = sc.class_id
    LEFT JOIN subjects s ON c.teacher_id = s.teacher_id
    LEFT JOIN teachers t ON s.teacher_id = t.id
    LEFT JOIN academic_calendar ac ON ac.id = ? 
    WHERE a.user_id = ? AND sc.academic_year = ? 
      AND a.date BETWEEN ? AND ?
";
$params = [$term_id ?: $current_term_id, $user_id, $current_academic_year, $start_date, $end_date];
$types = "iisss";

if ($class_id > 0) {
    $query .= " AND a.class_id = ?";
    $params[] = $class_id;
    $types .= "i";
}
$query .= " ORDER BY a.date DESC";

$stmt = $conn->prepare($query);
if (!$stmt) {
    error_log("Error preparing attendance query: " . $conn->error);
    die("System error retrieving attendance.");
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$attendance_records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate attendance summary
$total_days = count($attendance_records);
$present_days = count(array_filter($attendance_records, fn($record) => $record['status'] === 'Present'));
$absent_days = count(array_filter($attendance_records, fn($record) => $record['status'] === 'Absent'));
$late_days = count(array_filter($attendance_records, fn($record) => $record['status'] === 'Late'));

require_once $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php';
?>

<main class="container my-4">
    <!-- Header Section -->
    <section class="mb-5 text-center animate__animated animate__bounceIn">
        <div class="card hero-card">
            <div class="card-body position-relative">
                <h1 class="display-4 fw-bold text-dark">Your Attendance, <?= $student_name ?>! ✅</h1>
                <p class="lead text-muted">Track your presence like an anime hero!</p>
                <img src="/assets/images/chibi_mascot_attendance.png" alt="Chibi Mascot" class="mascot-img" width="120">
            </div>
        </div>
    </section>

    <!-- Summary Section -->
    <section class="mb-4">
        <div class="card summary-card animate__animated animate__fadeIn">
            <div class="card-body">
                <h4 class="card-title mb-3">Attendance Summary</h4>
                <div class="row text-center">
                    <div class="col-md-3">
                        <p class="fw-bold">Total Days</p>
                        <p class="fs-4 text-primary"><?= $total_days ?></p>
                    </div>
                    <div class="col-md-3">
                        <p class="fw-bold">Present</p>
                        <p class="fs-4 text-success"><?= $present_days ?></p>
                    </div>
                    <div class="col-md-3">
                        <p class="fw-bold">Absent</p>
                        <p class="fs-4 text-danger"><?= $absent_days ?></p>
                    </div>
                    <div class="col-md-3">
                        <p class="fw-bold">Late</p>
                        <p class="fs-4 text-warning"><?= $late_days ?></p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Filters -->
    <section class="mb-4">
        <div class="card filter-card animate__animated animate__fadeIn">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="GET" action="">
                    <div class="col-md-3">
                        <label for="class_id" class="form-label">Filter by Class</label>
                        <select id="class_id" name="class_id" class="form-select">
                            <option value="0">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $class_id == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="term_id" class="form-label">Filter by Term</label>
                        <select id="term_id" name="term_id" class="form-select">
                            <option value="0">All Terms</option>
                            <?php foreach ($terms as $term): ?>
                                <option value="<?= $term['id'] ?>" <?= $term_id == $term['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($term['term_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="start_date" class="form-label">Start Date</label>
                        <input type="date" id="start_date" name="start_date" class="form-control" value="<?= htmlspecialchars($start_date) ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="end_date" class="form-label">End Date</label>
                        <input type="date" id="end_date" name="end_date" class="form-control" value="<?= htmlspecialchars($end_date) ?>">
                    </div>
                    <div class="col-md-2 text-end">
                        <button type="submit" class="btn btn-primary ripple" data-tooltip-text="Apply filters">Apply Filters</button>
                        <a href="/student/attendance.php" class="btn btn-outline-primary ripple" data-tooltip-text="Reset filters">Reset</a>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <!-- Attendance Records -->
    <section>
        <?php if (empty($attendance_records)): ?>
            <div class="card text-center animate__animated animate__pulse">
                <div class="card-body">
                    <img src="/assets/images/no_attendance.png" alt="No Attendance" width="100" class="mb-3">
                    <h5 class="card-title">No Attendance Records Found!</h5>
                    <p class="card-text">No attendance has been recorded yet, or try adjusting the filters!</p>
                </div>
            </div>
        <?php else: ?>
            <h4 class="mb-3">Attendance Records</h4>
            <div class="row g-3">
                <?php foreach ($attendance_records as $record): ?>
                    <div class="col-md-6 col-lg-4">
                        <div class="card attendance-card animate__animated animate__zoomIn">
                            <div class="card-body position-relative">
                                <h5 class="card-title"><?= date('F j, Y', strtotime($record['date'])) ?></h5>
                                <p class="mb-1">
                                    <i class="fas fa-chalkboard me-1"></i> 
                                    <?= htmlspecialchars($record['class_name']) ?>
                                </p>
                                <p class="mb-1">
                                    <i class="fas fa-book me-1"></i> 
                                    <?= htmlspecialchars($record['subject_name'] ?? 'General') ?>
                                </p>
                                <p class="mb-1">
                                    <i class="fas fa-user-tie me-1"></i> 
                                    <?= htmlspecialchars($record['teacher_name'] ?? 'TBD') ?>
                                </p>
                                <p class="mb-1">
                                    <i class="fas fa-calendar me-1"></i> 
                                    <?= htmlspecialchars($record['term_name'] ?? 'Unknown Term') ?>
                                </p>
                                <p class="mb-1">
                                    <i class="fas fa-clipboard-check me-1"></i> 
                                    Status: 
                                    <span class="badge <?= $record['status'] === 'Present' ? 'bg-success' : ($record['status'] === 'Absent' ? 'bg-danger' : 'bg-warning') ?>">
                                        <?= htmlspecialchars($record['status']) ?>
                                    </span>
                                </p>
                                <p class="mb-0">
                                    <i class="fas fa-info-circle me-1"></i> 
                                    Type: <?= htmlspecialchars($record['type'] ?? 'Class') ?>
                                </p>
                                <span class="star-accent"></span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/templates/footer.php'; ?>

<style>
    :root {
        --primary-color: #ff4500;
        --primary-hover: #e03e00;
        --secondary-color: #00c4b4;
        --bg-gradient: linear-gradient(135deg, #f8f9fa, #fff3e0);
        --card-bg: #ffffff;
        --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        --gradient-button: linear-gradient(45deg, #ff4500, #ff6b00);
    }
    body {
        background: var(--bg-gradient);
        background-image: url('/assets/images/anime_sparkles.png');
        background-size: cover;
        background-attachment: fixed;
        font-family: 'Inter', sans-serif;
        overflow-x: hidden;
    }
    .hero-card, .summary-card, .filter-card, .attendance-card {
        background: var(--card-bg);
        border: 2px solid var(--primary-color);
        border-radius: 15px;
        box-shadow: var(--shadow);
        position: relative;
        overflow: hidden;
    }
    .hero-card::before {
        content: '';
        position: absolute;
        background: url('/assets/images/sparkle_effect.png');
        background-size: cover;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        opacity: 0.1;
        z-index: 0;
    }
    .mascot-img {
        position: absolute;
        right: 20px;
        bottom: -10px;
        animation: float 3s ease-in-out infinite;
        z-index: 1;
    }
    .attendance-card {
        border: 2px solid transparent;
        border-image: linear-gradient(45deg, #ff4500, #00c4b4) 1;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }
    .attendance-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
    }
    .star-accent {
        position: absolute;
        top: 10px;
        right: 10px;
        width: 25px;
        height: 25px;
        background: url('/assets/images/anime_star.png') no-repeat center;
        background-size: contain;
        opacity: 0.7;
    }
    .btn-primary {
        background: var(--gradient-button);
        border: none;
        border-radius: 25px;
        padding: 0.5rem 1.5rem;
        transition: transform 0.2s ease;
    }
    .btn-primary:hover {
        transform: scale(1.05);
    }
    .btn-outline-primary {
        border-color: var(--primary-color);
        color: var(--primary-color);
        border-radius: 25px;
    }
    .btn-outline-primary:hover {
        background: var(--gradient-button);
        color: white;
    }
    [data-tooltip-text] {
        position: relative;
    }
    [data-tooltip-text]::after {
        content: attr(data-tooltip-text);
        position: absolute;
        background: var(--primary-color);
        color: white;
        padding: 0.5rem;
        border-radius: 8px;
        font-size: 0.8rem;
        top: -40px;
        left: 50%;
        transform: translateX(-50%);
        opacity: 0;
        transition: opacity 0.3s ease;
        pointer-events: none;
        z-index: 1000;
        white-space: nowrap;
    }
    [data-tooltip-text]:hover::after {
        opacity: 1;
    }
    @keyframes float {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
    }
    @media (max-width: 767px) {
        .hero-card h1 {
            font-size: 2rem;
        }
        .mascot-img {
            width: 80px;
            right: 10px;
            bottom: -5px;
        }
        .attendance-card {
            margin-bottom: 1rem;
        }
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', () => {
    // Ripple effect for buttons
    document.querySelectorAll('.ripple').forEach(el => {
        el.addEventListener('click', (e) => {
            const rect = el.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            const ripple = document.createElement('span');
            ripple.style.position = 'absolute';
            ripple.style.background = 'rgba(255, 255, 255, 0.5)';
            ripple.style.borderRadius = '50%';
            ripple.style.transform = 'scale(0)';
            ripple.style.animation = 'ripple 0.6s linear';
            ripple.style.left = `${x}px`;
            ripple.style.top = `${y}px`;
            el.appendChild(ripple);
            setTimeout(() => ripple.remove(), 600);
        });
    });

    // Define ripple animation
    const styleSheet = document.createElement('style');
    styleSheet.textContent = `
        @keyframes ripple {
            to {
                transform: scale(4);
                opacity: 0;
            }
        }
    `;
    document.head.appendChild(styleSheet);
});
</script>

<?php
mysqli_close($conn);
ob_end_flush();
?>