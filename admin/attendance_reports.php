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

// Include database connection
require_once '../config/db.php';
$db = db(); // Use the helper function to get PDO instance

// Include helpers and functions
require_once '../includes/helpers.php';
require_once '../functions/csrf.php';
require_once '../functions/log_activity.php';

// Determine current term based on date with try-catch
$current_date = date('Y-m-d');
$current_term_query = "SELECT t.id, t.name, ay.year, t.start_date, t.end_date 
                       FROM terms t 
                       JOIN academic_years ay ON t.academic_year_id = ay.id 
                       WHERE ? BETWEEN t.start_date AND t.end_date 
                       ORDER BY t.start_date DESC LIMIT 1";
try {
    $stmt = $db->prepare($current_term_query);
    $stmt->execute([$current_date]);
    $current_term = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching current term: " . $e->getMessage());
    $current_term = null;
}
$current_term_id = $current_term ? $current_term['id'] : 0;

// Handle filters: class, date range, student search
$class_filter = intval($_GET['class_id'] ?? 0);
$start_date = $_GET['start_date'] ?? ($current_term ? $current_term['start_date'] : date('Y-m-d', strtotime('-30 days')));
$end_date = $_GET['end_date'] ?? ($current_term ? $current_term['end_date'] : date('Y-m-d'));
$student_search = isset($_GET['student_search']) ? '%' . trim($_GET['student_search']) . '%' : '%';

// Validate dates
if (strtotime($start_date) > strtotime($end_date)) {
    $temp = $start_date;
    $start_date = $end_date;
    $end_date = $temp;
}

// Fetch classes for filter
$classes = $db->query("SELECT id, class_name FROM classes ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch attendance data
$attendance_query = "SELECT a.*, u.name AS student_name, c.class_name 
                     FROM attendance a 
                     JOIN users u ON a.user_id = u.id 
                     JOIN classes c ON a.class_id = c.id 
                     WHERE a.date BETWEEN ? AND ? AND u.name LIKE ?";
$params = [$start_date, $end_date, $student_search];
if ($class_filter > 0) {
    $attendance_query .= " AND a.class_id = ?";
    $params[] = $class_filter;
}
$attendance_query .= " ORDER BY a.date DESC, u.name ASC";
try {
    $stmt = $db->prepare($attendance_query);
    $stmt->execute($params);
    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching attendance records: " . $e->getMessage());
    $attendance_records = [];
}

// Calculate KPIs
$total_attendances = count($attendance_records);
$present_count = count(array_filter($attendance_records, function($record) { return $record['status'] === 'present'; }));
$absent_count = count(array_filter($attendance_records, function($record) { return $record['status'] === 'absent'; }));
$late_count = count(array_filter($attendance_records, function($record) { return $record['status'] === 'late'; }));
$attendance_rate = $total_attendances > 0 ? round(($present_count / $total_attendances) * 100, 2) : 0;

// Group by date for daily stats
$daily_stats = [];
foreach ($attendance_records as $record) {
    $date = $record['date'];
    if (!isset($daily_stats[$date])) {
        $daily_stats[$date] = ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0];
    }
    $daily_stats[$date][$record['status']]++;
    $daily_stats[$date]['total']++;
}

// Group by student for individual stats
$student_stats = [];
foreach ($attendance_records as $record) {
    $student_id = $record['user_id'];
    if (!isset($student_stats[$student_id])) {
        $student_stats[$student_id] = ['name' => $record['student_name'], 'present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0];
    }
    $student_stats[$student_id][$record['status']]++;
    $student_stats[$student_id]['total']++;
}

// Log access
log_activity($_SESSION['user_id'], 'view_attendance_reports', "Viewed attendance reports for term ID: $current_term_id from $start_date to $end_date");

// Generate CSRF token (though no forms here, for consistency)
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="attendance-reports">
        <h1 class="page-title">Attendance Reports <?php echo $current_term ? " - Current Term: {$current_term['name']} ({$current_term['year']})" : ''; ?></h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <!-- Filters -->
        <form class="search-form" method="GET">
            <label>Start Date:</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
            <label>End Date:</label>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
            <label>Class:</label>
            <select name="class_id">
                <option value="">All Classes</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($class['class_name']); ?></option>
                <?php endforeach; ?>
            </select>
            <label>Student Search:</label>
            <input type="text" name="student_search" placeholder="Search by student name..." value="<?php echo htmlspecialchars($_GET['student_search'] ?? ''); ?>">
            <button type="submit"><i class="fas fa-filter"></i> Apply Filters</button>
        </form>

        <!-- KPIs -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <i class="fas fa-users icon"></i>
                <div class="value"><?php echo $total_attendances; ?></div>
                <div class="label">Total Records</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-check-circle icon"></i>
                <div class="value"><?php echo $present_count; ?></div>
                <div class="label">Present</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-times-circle icon"></i>
                <div class="value"><?php echo $absent_count; ?></div>
                <div class="label">Absent</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-clock icon"></i>
                <div class="value"><?php echo $late_count; ?></div>
                <div class="label">Late</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-percentage icon"></i>
                <div class="value"><?php echo $attendance_rate; ?>%</div>
                <div class="label">Attendance Rate</div>
            </div>
        </div>

        <!-- Charts -->
        <div class="charts-grid">
            <div class="chart-card">
                <h3>Daily Attendance Trends</h3>
                <canvas id="dailyChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>Overall Status Breakdown</h3>
                <canvas id="statusChart"></canvas>
            </div>
        </div>

        <!-- Student Stats Table -->
        <div class="student-stats-card">
            <h2>Student Attendance Stats</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Present</th>
                        <th>Absent</th>
                        <th>Late</th>
                        <th>Total</th>
                        <th>Attendance Rate</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($student_stats as $stats): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($stats['name']); ?></td>
                            <td><?php echo $stats['present']; ?></td>
                            <td><?php echo $stats['absent']; ?></td>
                            <td><?php echo $stats['late']; ?></td>
                            <td><?php echo $stats['total']; ?></td>
                            <td><?php echo $stats['total'] > 0 ? round(($stats['present'] / $stats['total']) * 100, 2) : 0; ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Detailed Records Table -->
        <div class="records-card">
            <h2>Detailed Attendance Records</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Student Name</th>
                        <th>Class Name</th>
                        <th>Status</th>
                        <th>Type</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($attendance_records as $record): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($record['date']); ?></td>
                            <td><?php echo htmlspecialchars($record['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($record['class_name']); ?></td>
                            <td><span class="status-badge <?php echo htmlspecialchars($record['status']); ?>"><?php echo ucfirst(htmlspecialchars($record['status'])); ?></span></td>
                            <td><?php echo ucfirst(htmlspecialchars($record['type'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Daily Attendance Chart (Line)
        const dailyCtx = document.getElementById('dailyChart').getContext('2d');
        const dailyData = <?php 
            $labels = array_keys($daily_stats);
            rsort($labels); // Sort dates descending
            $present = [];
            $absent = [];
            $late = [];
            foreach ($labels as $date) {
                $stats = $daily_stats[$date];
                $present[] = $stats['present'];
                $absent[] = $stats['absent'];
                $late[] = $stats['late'];
            }
            echo json_encode([
                'labels' => $labels,
                'present' => $present,
                'absent' => $absent,
                'late' => $late
            ]); 
        ?>;
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: dailyData.labels,
                datasets: [
                    { label: 'Present', data: dailyData.present, borderColor: '#28a745', fill: false },
                    { label: 'Absent', data: dailyData.absent, borderColor: '#dc3545', fill: false },
                    { label: 'Late', data: dailyData.late, borderColor: '#ffc107', fill: false }
                ]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true } }
            }
        });

        // Status Breakdown Chart (Pie)
        const statusCtx = document.getElementById('statusChart').getContext('2d');
        new Chart(statusCtx, {
            type: 'pie',
            data: {
                labels: ['Present', 'Absent', 'Late'],
                datasets: [{
                    data: [<?php echo $present_count; ?>, <?php echo $absent_count; ?>, <?php echo $late_count; ?>],
                    backgroundColor: ['#28a745', '#dc3545', '#ffc107']
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { position: 'bottom' } }
            }
        });
    </script>

    <style>
        /* Attendance Reports Styles - Ultra Modern */
        .attendance-reports {
            display: grid;
            gap: 24px;
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .search-form {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            background-color: var(--primary-white);
            padding: 16px;
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .search-form label {
            font-weight: 500;
            color: var(--accent-slate-gray);
        }

        .search-form input, .search-form select {
            padding: 12px;
            border: 1px solid var(--accent-slate-gray);
            border-radius: 4px;
            min-width: 150px;
        }

        .search-form button {
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .search-form button:hover {
            background-color: var(--accent-soft-cyan);
        }

        .kpi-grid, .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .kpi-card, .chart-card, .student-stats-card, .records-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }

        .kpi-card {
            text-align: center;
        }

        .kpi-card .icon {
            font-size: 36px;
            color: var(--primary-sea-blue);
            margin-bottom: 8px;
        }

        .kpi-card .value {
            font-size: 28px;
            font-weight: 700;
        }

        .kpi-card .label {
            font-size: 14px;
            color: var(--accent-slate-gray);
        }

        .chart-card h3, .student-stats-card h2, .records-card h2 {
            margin-bottom: 12px;
            color: var(--primary-black);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .data-table th, .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        }

        .data-table th {
            background-color: var(--accent-deep-navy);
            color: var(--primary-white);
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            color: var(--primary-white);
            font-size: 12px;
        }

        .status-badge.present { background-color: #28a745; }
        .status-badge.absent { background-color: #dc3545; }
        .status-badge.late { background-color: #ffc107; }

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
            .search-form {
                flex-direction: column;
            }

            .search-form input, .search-form select, .search-form button {
                width: 100%;
            }

            .kpi-grid, .charts-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

<?php include '../templates/admin_footer.php'; ?>