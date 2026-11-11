<?php
ob_start();

// Start session only if not active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verify teacher authentication
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Please log in as a teacher to access this page.";
    header("Location: /login.php");
    exit();
}

// Include database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Check database connection
if (!$conn) {
    error_log("Connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "Database connection failed. Please try again later.";
    header("Location: /login.php");
    exit();
}

// Fetch teacher details
$teacher_id = $_SESSION['user_id'];
$teacher_sql = "SELECT name, profile_picture FROM users WHERE id = ? AND role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher_result = $teacher_stmt->get_result();
$teacher = $teacher_result->fetch_assoc();
$teacher_stmt->close();
if (!$teacher) {
    error_log("Teacher not found for user_id: $teacher_id");
    $_SESSION['error_msg'] = "Teacher account not found.";
    header("Location: /login.php");
    exit();
}

// Log page access
$ip_address = $_SERVER['REMOTE_ADDR'];
$user_agent = $_SERVER['HTTP_USER_AGENT'];
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) 
            VALUES (?, 'Accessed View Attendance', 'Teacher accessed view attendance page', ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_stmt->bind_param("iss", $teacher_id, $ip_address, $user_agent);
$log_stmt->execute();
$log_stmt->close();

// Validate POST data
if (!isset($_POST['class_id']) || !isset($_POST['academic_year'])) {
    $_SESSION['error_msg'] = "Invalid request. Please select a class.";
    header("Location: manage_attendance.php");
    exit();
}
$class_id = (int)$_POST['class_id'];
$academic_year = $_POST['academic_year'];

// Validate academic year
$academic_years_sql = "SELECT DISTINCT year FROM academic_calendar";
$academic_years_stmt = $conn->prepare($academic_years_sql);
$academic_years_stmt->execute();
$academic_years_result = $academic_years_stmt->get_result();
$academic_years = [];
while ($row = $academic_years_result->fetch_assoc()) {
    $academic_years[] = $row['year'];
}
$academic_years_stmt->close();
if (!in_array($academic_year, $academic_years)) {
    error_log("Invalid academic year: $academic_year");
    $_SESSION['error_msg'] = "Invalid academic year selected.";
    header("Location: manage_attendance.php");
    exit();
}

// Verify teacher is assigned to the class
$class_sql = "
    SELECT c.class_name
    FROM classes c
    LEFT JOIN class_schedules cs ON c.id = cs.class_id
    LEFT JOIN academic_calendar ac ON cs.term_id = ac.id
    WHERE c.id = ? AND (c.teacher_id = ? OR cs.teacher_id = ?) AND (ac.year = ? OR ac.year IS NULL)";
$class_stmt = $conn->prepare($class_sql);
$class_stmt->bind_param("iiis", $class_id, $teacher_id, $teacher_id, $academic_year);
$class_stmt->execute();
$class_result = $class_stmt->get_result();
$class = $class_result->fetch_assoc();
$class_stmt->close();
if (!$class) {
    error_log("Teacher $teacher_id not authorized for class_id: $class_id");
    $_SESSION['error_msg'] = "You are not authorized to view attendance for this class.";
    header("Location: manage_attendance.php");
    exit();
}
$class_name = $class['class_name'];

// Fetch academic year start and end dates
$calendar_sql = "SELECT start_date, end_date FROM academic_calendar WHERE year = ?";
$calendar_stmt = $conn->prepare($calendar_sql);
$calendar_stmt->bind_param("s", $academic_year);
$calendar_stmt->execute();
$calendar_result = $calendar_stmt->get_result();
$calendar = $calendar_result->fetch_assoc();
$calendar_stmt->close();
if (!$calendar) {
    error_log("Academic calendar not found for year: $academic_year");
    $_SESSION['error_msg'] = "Academic calendar not found.";
    header("Location: manage_attendance.php");
    exit();
}
$start_date = new DateTime($calendar['start_date']);
$end_date = new DateTime($calendar['end_date']);

// Calculate 16 weeks
$interval = $start_date->diff($end_date);
$total_days = $interval->days + 1; // Include end date
$days_per_week = ceil($total_days / 16);
$weeks = [];
for ($i = 0; $i < 16; $i++) {
    $week_start = (clone $start_date)->modify("+$i weeks");
    $week_end = (clone $week_start)->modify("+$days_per_week days -1 second");
    if ($week_end > $end_date) $week_end = $end_date;
    $weeks[$i + 1] = [
        'start' => $week_start->format('Y-m-d'),
        'end' => $week_end->format('Y-m-d')
    ];
}

// Get selected week filter
$selected_week = $_POST['week'] ?? 'all';

// Fetch students and attendance data
$students_sql = "
    SELECT u.id, u.name
    FROM users u
    JOIN student_classes sc ON u.id = sc.user_id
    WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student'
    ORDER BY u.name";
$students_stmt = $conn->prepare($students_sql);
$students_stmt->bind_param("is", $class_id, $academic_year);
$students_stmt->execute();
$students_result = $students_stmt->get_result();
$students = [];
while ($row = $students_result->fetch_assoc()) {
    $students[$row['id']] = $row['name'];
}
$students_stmt->close();

// Fetch attendance records grouped by week
$attendance_records = [];
$total_present = 0;
$total_absent = 0;
$total_records = 0;
foreach ($students as $student_id => $student_name) {
    $attendance_records[$student_id] = [
        'name' => $student_name,
        'weeks' => array_fill(1, 16, ['present' => 0, 'absent' => 0]),
        'total_present' => 0,
        'total_absent' => 0
    ];
}
$attendance_sql = "
    SELECT a.user_id, a.date, a.status,
           FLOOR(DATEDIFF(a.date, ?) / ?) + 1 AS week_number
    FROM attendance a
    JOIN users u ON a.user_id = u.id
    WHERE a.class_id = ? AND u.role = 'student' AND a.date BETWEEN ? AND ?";
if ($selected_week !== 'all') {
    $attendance_sql .= " AND FLOOR(DATEDIFF(a.date, ?) / ?) + 1 = ?";
}
$attendance_sql .= " ORDER BY a.user_id, a.date";
$attendance_stmt = $conn->prepare($attendance_sql);
$params = [$start_date->format('Y-m-d'), $days_per_week, $class_id, $start_date->format('Y-m-d'), $end_date->format('Y-m-d')];
$types = "siiss";
if ($selected_week !== 'all') {
    $params[] = $start_date->format('Y-m-d');
    $params[] = $days_per_week;
    $params[] = (int)$selected_week;
    $types .= "sii";
}
$attendance_stmt->bind_param($types, ...$params);
$attendance_stmt->execute();
$attendance_result = $attendance_stmt->get_result();
while ($row = $attendance_result->fetch_assoc()) {
    $student_id = $row['user_id'];
    $week_number = $row['week_number'];
    if ($week_number >= 1 && $week_number <= 16) {
        if ($row['status'] === 'present') {
            $attendance_records[$student_id]['weeks'][$week_number]['present']++;
            $attendance_records[$student_id]['total_present']++;
            $total_present++;
        } elseif ($row['status'] === 'absent') {
            $attendance_records[$student_id]['weeks'][$week_number]['absent']++;
            $attendance_records[$student_id]['total_absent']++;
            $total_absent++;
        }
        $total_records++;
    }
}
$attendance_stmt->close();

// Calculate percentages
$percent_present = $total_records > 0 ? round(($total_present / $total_records) * 100, 2) : 0;

// Include header template
$header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
if (!file_exists($header_path)) {
    error_log("Teacher header file missing: $header_path");
    $_SESSION['error_msg'] = "Error: Teacher header template is missing. Please contact the administrator.";
    $log_sql = "INSERT INTO activity_logs (user_id, action, description, created_at) 
                VALUES (?, 'Error', ?, NOW())";
    $log_stmt = $conn->prepare($log_sql);
    $error_desc = "Failed to load teacher header template at $header_path";
    $log_stmt->bind_param("is", $teacher_id, $error_desc);
    $log_stmt->execute();
    $log_stmt->close();
    header("Location: /login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="View weekly attendance records for a class at QueensPa School.">
    <meta name="keywords" content="QueensPa School, teacher, view attendance, class management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="View Attendance - QueensPa School">
    <meta property="og:description" content="Teacher interface for viewing weekly class attendance records at QueensPa School.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/view_attendance.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="View Attendance - QueensPa School">
    <meta name="twitter:description" content="Teacher interface for viewing weekly class attendance records at QueensPa School.">
    <title>View Attendance - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" integrity="sha512-c42qTSw/wPZ3/5LBzD+Bw5f7bSF2oxou6wEb+I/lqeaKV5FDIfMvvRp772y4jcJLKuGUOpbJMdg/BTl50fJYAw==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #ff4500;
            --secondary-color: #6c757d;
            --accent-color: #ffc107;
            --text-color: #333;
            --bg-color: #f8f9fa;
            --shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            --present-gradient-start: #e6f3e6;
            --present-gradient-end: #4caf50;
            --absent-gradient-start: #f7e4e4;
            --absent-gradient-end: #f44336;
        }
        body {
            font-family: 'Inter', Arial, sans-serif;
            color: var(--text-color);
        }
        .card {
            border-radius: 12px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
            max-width: 95%;
            margin: 0 auto;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .card-header {
            background: linear-gradient(to right, var(--primary-color), #ff6b00);
            color: white;
            border-radius: 12px 12px 0 0;
            padding: 1.5rem;
        }
        .card-body {
            padding: 2rem;
        }
        .btn-primary {
            background-color: var(--primary-color) !important;
            border-color: var(--primary-color) !important;
            border-radius: 8px;
            transition: background-color 0.3s ease;
            font-weight: 500;
        }
        .btn-primary:hover {
            background-color: #e03e00 !important;
            border-color: #e03e00 !important;
            animation: animate__pulse 0.5s;
        }
        .btn-secondary {
            background-color: var(--accent-color) !important;
            border-color: var(--accent-color) !important;
            border-radius: 8px;
            color: var(--text-color);
            transition: background-color 0.3s ease;
            font-weight: 500;
        }
        .btn-secondary:hover {
            background-color: #e0a800 !important;
            border-color: #e0a800 !important;
            animation: animate__pulse 0.5s;
        }
        .form-select, .form-control {
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 400;
        }
        .table-responsive {
            border-radius: 12px;
            overflow-x: auto;
        }
        .table {
            font-size: 1.1rem;
            font-weight: 400;
        }
        .table thead {
            background: var(--primary-color);
            color: white;
            font-weight: 600;
        }
        .table-hover tbody tr:hover {
            background-color: #f5f5f5;
        }
        .profile-img {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            object-fit: cover;
        }
        .alert {
            border-radius: 8px;
        }
        .stat-card {
            background: #fff;
            padding: 2rem;
            border-radius: 12px;
            box-shadow: var(--shadow);
            text-align: center;
            transition: background-color 0.3s ease;
        }
        .stat-card:hover {
            background-color: var(--bg-color);
        }
        .stat-card i {
            font-size: 2.5rem;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        .present-cell {
            background: linear-gradient(to right, var(--present-gradient-start), var(--present-gradient-end));
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 0.5rem;
        }
        .absent-cell {
            background: linear-gradient(to right, var(--absent-gradient-start), var(--absent-gradient-end));
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 0.5rem;
        }
        .total-cell {
            background: #e9ecef;
            text-align: center;
            font-weight: 600;
            border-radius: 6px;
            padding: 0.5rem;
        }
        @media (max-width: 767.98px) {
            .quick-links {
                flex-direction: column;
                align-items: stretch;
            }
            .quick-links .btn {
                width: 100%;
                margin-bottom: 0.5rem;
            }
            .card {
                max-width: 100%;
            }
            .card-body {
                padding: 1rem;
            }
            .table {
                font-size: 0.95rem;
            }
            .table th, .table td {
                padding: 0.75rem;
            }
            .table th:not(:first-child) {
                min-width: 90px;
            }
            .form-select {
                font-size: 0.9rem;
                padding: 0.5rem;
            }
            .stat-card {
                padding: 1.25rem;
            }
            .stat-card h5 {
                font-size: 1.1rem;
            }
            .stat-card p.fs-3 {
                font-size: 1.75rem !important;
            }
        }
        @media (min-width: 768px) {
            .table th:not(:first-child) {
                min-width: 120px;
            }
        }
        @media (min-width: 992px) {
            .table th:not(:first-child) {
                min-width: 140px;
            }
        }
    </style>
</head>
<body>
    <?php include $header_path; ?>

    <div class="content" id="content">
        <main class="container my-4">
            <!-- Messages -->
            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="alert alert-danger animate__animated animate__fadeIn"><?php echo htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?></div>
            <?php endif; ?>

            <!-- Teacher Profile Header -->
            <section class="card mb-4 animate__animated animate__fadeInDown">
                <div class="card-header d-flex align-items-center">
                    <img src="/<?php echo htmlspecialchars($teacher['profile_picture'] ?: 'Uploads/profiles/default.jpg'); ?>" alt="Profile Picture" class="profile-img me-3">
                    <h3 class="mb-0">View Attendance for <?php echo htmlspecialchars($class_name); ?>, <?php echo htmlspecialchars($teacher['name']); ?> (Teacher)</h3>
                    <a href="edit_user.php?id=<?php echo $teacher_id; ?>" class="btn btn-sm btn-secondary ms-auto animate__animated animate__fadeIn"><i class="fas fa-edit"></i> Edit Profile</a>
                </div>
            </section>

            <!-- Week Filter and Attendance Records -->
            <section class="card mb-4 animate__animated animate__zoomIn">
                <div class="card-header">
                    <h3 class="mb-0">Weekly Attendance for <?php echo htmlspecialchars($class_name); ?> (<?php echo htmlspecialchars($academic_year); ?>)</h3>
                </div>
                <div class="card-body">
                    <!-- Week Filter -->
                    <form method="POST" class="row g-3 align-items-center mb-4">
                        <input type="hidden" name="class_id" value="<?php echo $class_id; ?>">
                        <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($academic_year); ?>">
                        <div class="col-auto">
                            <label for="week" class="form-label">Filter by Week:</label>
                        </div>
                        <div class="col-auto">
                            <select name="week" id="week" class="form-select" onchange="this.form.submit()">
                                <option value="all" <?php echo $selected_week === 'all' ? 'selected' : ''; ?>>All Weeks</option>
                                <?php for ($i = 1; $i <= 16; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $selected_week === (string)$i ? 'selected' : ''; ?>>
                                        Week <?php echo $i; ?> (<?php echo htmlspecialchars($weeks[$i]['start']); ?> to <?php echo htmlspecialchars($weeks[$i]['end']); ?>)
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </form>

                    <!-- Attendance Table -->
                    <?php if (empty($attendance_records)): ?>
                        <div class="alert alert-info animate__animated animate__fadeIn">
                            No attendance records found for <?php echo htmlspecialchars($class_name); ?> 
                            <?php echo $selected_week !== 'all' ? 'in Week ' . $selected_week : 'in ' . htmlspecialchars($academic_year); ?>.
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <div class="col-12">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Student Name</th>
                                                <?php if ($selected_week === 'all'): ?>
                                                    <?php for ($i = 1; $i <= 16; $i++): ?>
                                                        <th>Week <?php echo $i; ?></th>
                                                    <?php endfor; ?>
                                                    <th class="total-cell">Total Present</th>
                                                    <th class="total-cell">Total Absent</th>
                                                <?php else: ?>
                                                    <th class="present-cell">Present</th>
                                                    <th class="absent-cell">Absent</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($attendance_records as $student_id => $record): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($record['name']); ?></td>
                                                    <?php if ($selected_week === 'all'): ?>
                                                        <?php for ($i = 1; $i <= 16; $i++): ?>
                                                            <td>
                                                                <div class="d-flex justify-content-around align-items-center">
                                                                    <span class="present-cell d-inline-block px-3 py-2 rounded">
                                                                        P: <?php echo $record['weeks'][$i]['present']; ?>
                                                                    </span>
                                                                    <span class="absent-cell d-inline-block px-3 py-2 rounded">
                                                                        A: <?php echo $record['weeks'][$i]['absent']; ?>
                                                                    </span>
                                                                </div>
                                                            </td>
                                                        <?php endfor; ?>
                                                        <td class="total-cell"><?php echo $record['total_present']; ?></td>
                                                        <td class="total-cell"><?php echo $record['total_absent']; ?></td>
                                                    <?php else: ?>
                                                        <td class="present-cell"><?php echo $record['weeks'][$selected_week]['present']; ?></td>
                                                        <td class="absent-cell"><?php echo $record['weeks'][$selected_week]['absent']; ?></td>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="stat-card text-center">
                                    <i class="fas fa-clipboard-check"></i>
                                    <h5>Total Records</h5>
                                    <p class="fs-3"><?php echo $total_records; ?></p>
                                </div>
                                <div class="stat-card text-center mt-3">
                                    <i class="fas fa-percentage"></i>
                                    <h5>Present (%)</h5>
                                    <p class="fs-3"><?php echo $percent_present; ?>%</p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <div class="mt-4">
                        <a href="manage_attendance.php" class="btn btn-secondary animate__animated animate__fadeIn"><i class="fas fa-arrow-left"></i> Back to Classes</a>
                    </div>
                </div>
            </section>

            <!-- Quick Links -->
            <section class="d-flex flex-wrap quick-links gap-2 mb-4 animate__animated animate__fadeInUp">
                <a href="dashboard.php" class="btn btn-primary flex-grow-1 flex-md-grow-0 animate__animated animate__fadeIn"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
                <a href="submit_grades.php" class="btn btn-primary flex-grow-1 flex-md-grow-0 animate__animated animate__fadeIn"><i class="fas fa-chart-line"></i> Submit Grades</a>
                <a href="view_evaluations.php?teacher_id=<?php echo $teacher_id; ?>" class="btn btn-primary flex-grow-1 flex-md-grow-0 animate__animated animate__fadeIn"><i class="fas fa-person-check"></i> View Evaluations</a>
                <a href="manage_students.php" class="btn btn-primary flex-grow-1 flex-md-grow-0 animate__animated animate__fadeIn"><i class="fas fa-user-graduate"></i> Manage Students</a>
                <a href="manage_assignments.php" class="btn btn-primary flex-grow-1 flex-md-grow-0 animate__animated animate__fadeIn"><i class="fas fa-tasks"></i> Manage Assignments</a>
                <a href="manage_attendance.php" class="btn btn-primary flex-grow-1 flex-md-grow-0 animate__animated animate__fadeIn"><i class="fas fa-users"></i> Manage Attendance</a>
            </section>
        </main>
    </div>

    <?php
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
    if (!file_exists($footer_path)) {
        error_log("Teacher footer file missing: $footer_path");
        $_SESSION['error_msg'] = "Error: Teacher footer template is missing. Please contact the administrator.";
        $log_sql = "INSERT INTO activity_logs (user_id, action, description, created_at) 
                    VALUES (?, 'Error', ?, NOW())";
        $log_stmt = $conn->prepare($log_sql);
        $error_desc = "Failed to load teacher footer template at $footer_path";
        $log_stmt->bind_param("is", $teacher_id, $error_desc);
        $log_stmt->execute();
        $log_stmt->close();
        header("Location: /login.php");
        exit();
    }
    include $footer_path;

    mysqli_close($conn);
    ob_end_flush();
    ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/js/all.min.js" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" defer></script>
</body>
</html>