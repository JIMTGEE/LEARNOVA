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
// Fetch KPIs from existing tables
// Total Users by Role
$total_students = $db->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();
$total_teachers = $db->query("SELECT COUNT(*) FROM users WHERE role = 'teacher'")->fetchColumn();
$total_parents = $db->query("SELECT COUNT(*) FROM users WHERE role = 'parent'")->fetchColumn();
$total_admins = $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
// Average Attendance % (from student_reports)
$avg_attendance = $db->query("SELECT AVG(attendance) FROM student_reports")->fetchColumn() ?? 0;
// Fees Collected (no fees table, so only collected from payments)
$fees_collected = $db->query("SELECT SUM(amount_paid) FROM payments")->fetchColumn() ?? 0;
// No outstanding calculation since no fees table/amount_due; set to 0 for placeholder
$outstanding_fees = 0;
// Pending Assignments/Quizzes (active quizzes)
$pending_assignments = $db->query("SELECT COUNT(*) FROM quizzes WHERE status = 'active'")->fetchColumn();
// Pending Transcript Requests
$pending_transcripts = $db->query("SELECT COUNT(*) FROM transcript_requests WHERE status = 'pending'")->fetchColumn();
// Recent Promotions
$recent_promotions = $db->query("SELECT COUNT(*) FROM promotion_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
// Average Student Score
$avg_score = $db->query("SELECT AVG(avg_scaled_score) FROM progress_aggregates")->fetchColumn() ?? 0;
// Top Performing Students (last term, top 5)
$top_students = $db->query("SELECT u.name, pa.avg_scaled_score FROM progress_aggregates pa JOIN users u ON pa.student_id = u.id ORDER BY pa.avg_scaled_score DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Low Attendance Students (below 80%, last 30 days)
$low_attendance = $db->query("SELECT u.name, sr.attendance FROM student_reports sr JOIN users u ON sr.user_id = u.id WHERE sr.attendance < 80 AND sr.created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Upcoming Events (next 7 days)
$upcoming_events = $db->query("SELECT title, event_date, start_time FROM school_events WHERE event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) ORDER BY event_date ASC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Activity
$recent_activity = $db->query("SELECT ul.*, u.name FROM user_logs ul JOIN users u ON ul.user_id = u.id ORDER BY ul.created_at DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
// Alerts (Unread Notifications for admin)
$alerts = $db->query("SELECT * FROM notifications WHERE (user_id = {$_SESSION['user_id']} OR target_role = 'admin') AND is_read = 0 ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Teacher Evaluation Summary (average scores) - Expanded sum
$avg_teacher_eval = $db->query("SELECT AVG((score_1 + score_2 + score_3 + score_4 + score_5 + score_6 + score_7 + score_8 + score_9 + score_10 + score_11 + score_12 + score_13 + score_14 + score_15 + score_16 + score_17)/17) FROM teacher_evaluations")->fetchColumn() ?? 0;
// System Usage Stats (logins last 7 days)
$recent_logins = $db->query("SELECT COUNT(*) FROM users WHERE last_login > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
// Additional KPIs from DB
// Number of Active Subjects
$active_subjects = $db->query("SELECT COUNT(*) FROM subjects WHERE status = 'Active'")->fetchColumn();
// Number of Wellness Resources
$wellness_resources_count = $db->query("SELECT COUNT(*) FROM wellness_resources")->fetchColumn();
// Number of Private Chat Sessions Active
$active_chats = $db->query("SELECT COUNT(*) FROM chat_sessions WHERE status = 'active'")->fetchColumn();
// Number of Mock Entries (drafts)
$draft_mocks = $db->query("SELECT COUNT(*) FROM mock_entries WHERE status = 'draft'")->fetchColumn();
// Number of Quiz Submissions (recent 30 days)
$recent_quiz_subs = $db->query("SELECT COUNT(*) FROM quiz_submissions WHERE submitted_at > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
// Average Resource Feedback Rating
$avg_resource_rating = $db->query("SELECT AVG(rating) FROM resource_feedback")->fetchColumn() ?? 0;
// Number of Promotion Logs (all time)
$total_promotions = $db->query("SELECT COUNT(*) FROM promotion_logs")->fetchColumn();
// Number of Teacher Notes (recent 30 days)
$recent_teacher_notes = $db->query("SELECT COUNT(*) FROM teacher_notes WHERE created_at > DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
// Number of Termly Plans
$total_termly_plans = $db->query("SELECT COUNT(*) FROM termly_plans")->fetchColumn();
// Number of Student Feedback Entries
$student_feedback_count = $db->query("SELECT COUNT(*) FROM student_feedback")->fetchColumn();
// Number of Report Summaries
$report_summaries_count = $db->query("SELECT COUNT(*) FROM report_summaries")->fetchColumn();
// Number of User Library Entries
$user_library_count = $db->query("SELECT COUNT(*) FROM user_library")->fetchColumn();
// For AI Insights - Prepare more comprehensive data for Gemini, using only existing DB data
$insight_data = json_encode([
    'total_students' => $total_students,
    'total_teachers' => $total_teachers,
    'total_parents' => $total_parents,
    'total_admins' => $total_admins,
    'avg_attendance' => $avg_attendance,
    'fees_collected' => $fees_collected,
    'pending_assignments' => $pending_assignments,
    'pending_transcripts' => $pending_transcripts,
    'recent_promotions' => $recent_promotions,
    'avg_score' => $avg_score,
    'avg_teacher_eval' => $avg_teacher_eval,
    'recent_logins' => $recent_logins,
    'low_attendance_count' => count($low_attendance),
    'active_subjects' => $active_subjects,
    'wellness_resources_count' => $wellness_resources_count,
    'active_chats' => $active_chats,
    'draft_mocks' => $draft_mocks,
    'recent_quiz_subs' => $recent_quiz_subs,
    'avg_resource_rating' => $avg_resource_rating,
    'total_promotions' => $total_promotions,
    'recent_teacher_notes' => $recent_teacher_notes,
    'total_termly_plans' => $total_termly_plans,
    'student_feedback_count' => $student_feedback_count,
    'report_summaries_count' => $report_summaries_count,
    'user_library_count' => $user_library_count,
    // Add even more if needed, but this covers most tables
]);
// Function to get AI Insights (using functions/gemini_api.php)
require_once '../functions/gemini_api.php';
$ai_prompt = "As an advanced AI school administrator assistant, analyze this comprehensive school data: $insight_data. Provide key insights, flag potential issues (e.g., low attendance, performance drops, high draft mocks, low ratings), suggest actionable recommendations (e.g., interventions for at-risk students, review drafts, improve resources), and predict trends (e.g., based on scores, attendance, submissions). Structure response with bullet points for clarity: Insights, Issues, Recommendations, Predictions. Keep it concise yet detailed.";
$ai_insights = get_gemini_response($ai_prompt); // Assume this function exists in gemini_api.php and returns the text response
// Fetch additional lists for expanded sections
// Recent Mock Entries (last 5)
$recent_mocks = $db->query("SELECT * FROM mock_entries ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Quiz Submissions (last 5)
$recent_quiz_submissions = $db->query("SELECT qs.*, u.name AS student_name, q.title AS quiz_title FROM quiz_submissions qs JOIN users u ON qs.user_id = u.id JOIN quizzes q ON qs.quiz_id = q.id ORDER BY qs.submitted_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Resource Feedback (last 5)
$recent_resource_feedback = $db->query("SELECT rf.*, u.name AS user_name FROM resource_feedback rf JOIN users u ON rf.user_id = u.id ORDER BY rf.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Teacher Evaluations (last 5)
$recent_teacher_evals = $db->query("SELECT te.*, t.first_name, t.last_name FROM teacher_evaluations te JOIN teachers t ON te.teacher_id = t.id ORDER BY te.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Termly Plans (last 5)
$recent_termly_plans = $db->query("SELECT tp.*, u.name AS teacher_name FROM termly_plans tp JOIN users u ON tp.teacher_id = u.id ORDER BY tp.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Student Progress (last 5)
$recent_student_progress = $db->query("SELECT sp.*, u.name AS student_name FROM student_progress sp JOIN users u ON sp.user_id = u.id ORDER BY sp.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Additional fetches to expand the dashboard
// Recent Private Chat Sessions (last 5 active)
$recent_chats = $db->query("SELECT pcs.*, us.name AS teacher_name, ur.name AS recipient_name FROM chat_sessions pcs JOIN users us ON pcs.teacher_id = us.id JOIN users ur ON pcs.recipient_id = ur.id WHERE pcs.status = 'active' ORDER BY pcs.start_time DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Payments (last 5)
$recent_payments = $db->query("SELECT p.*, u.name AS user_name FROM payments p JOIN users u ON p.user_id = u.id ORDER BY p.payment_date DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Notifications (last 5)
$recent_notifications = $db->query("SELECT n.*, u.name AS user_name FROM notifications n LEFT JOIN users u ON n.user_id = u.id ORDER BY n.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Promotion Logs (last 5)
$recent_promotion_logs = $db->query("SELECT pl.*, u.name AS student_name, t.name AS teacher_name FROM promotion_logs pl JOIN users u ON pl.user_id = u.id JOIN users t ON pl.teacher_id = t.id ORDER BY pl.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Student Reports (last 5)
$recent_student_reports = $db->query("SELECT sr.*, u.name AS student_name FROM student_reports sr JOIN users u ON sr.user_id = u.id ORDER BY sr.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Transcript Requests (last 5)
$recent_transcript_requests = $db->query("SELECT tr.*, u.name AS student_name FROM transcript_requests tr JOIN users u ON tr.student_id = u.id ORDER BY tr.requested_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent User Logs (expanded to 20 for more detail)
$expanded_user_logs = $db->query("SELECT ul.*, u.name FROM user_logs ul JOIN users u ON ul.user_id = u.id ORDER BY ul.created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
// Recent School Events (last 10)
$recent_school_events = $db->query("SELECT * FROM school_events ORDER BY event_date DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
// Recent Response Cache (last 5)
$recent_response_cache = $db->query("SELECT rc.*, u.name AS user_name FROM response_cache rc JOIN users u ON rc.user_id = u.id ORDER BY rc.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Parent Student Mappings (last 5)
$recent_parent_mappings = $db->query("SELECT psm.*, up.name AS parent_name, us.name AS student_name FROM parent_student_mapping psm JOIN users up ON psm.parent_id = up.id JOIN users us ON psm.student_id = us.id ORDER BY psm.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Quiz (last 5)
$recent_quizzes = $db->query("SELECT q.*, u.name AS teacher_name FROM quizzes q JOIN users u ON q.teacher_id = u.id ORDER BY q.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Teacher Notes (last 5)
$recent_teacher_notes_full = $db->query("SELECT tn.*, u.name AS teacher_name FROM teacher_notes tn JOIN users u ON tn.teacher_id = u.id ORDER BY tn.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Student Classes (last 5)
$recent_student_classes = $db->query("SELECT sc.*, u.name AS student_name FROM student_classes sc JOIN users u ON sc.user_id = u.id ORDER BY sc.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Student Feedback (last 5)
$recent_student_feedback = $db->query("SELECT sf.*, u.name AS student_name FROM student_feedback sf JOIN users u ON sf.user_id = u.id ORDER BY sf.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Report Summaries (last 5)
$recent_report_summaries = $db->query("SELECT rs.*, u.name AS user_name FROM report_summaries rs JOIN users u ON rs.user_id = u.id ORDER BY rs.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent User Library (last 5)
$recent_user_library = $db->query("SELECT ul.*, u.name AS user_name FROM user_library ul JOIN users u ON ul.user_id = u.id ORDER BY ul.added_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Wellness Resources (last 5)
$recent_wellness_resources = $db->query("SELECT * FROM wellness_resources ORDER BY created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Private Chat Messages (last 5)
$recent_chat_messages = $db->query("SELECT cm.*, u.name AS user_name FROM chat_messages cm JOIN users u ON cm.user_id = u.id ORDER BY cm.created_at DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Recent Progress Aggregates (last 5, assuming ordered by academic_year or term)
$recent_progress_aggregates = $db->query("SELECT pa.*, u.name AS student_name, ay.year AS academic_year FROM progress_aggregates pa JOIN users u ON pa.student_id = u.id LEFT JOIN academic_years ay ON pa.academic_year_id = ay.id ORDER BY ay.year DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
// Dynamic Chart Data Queries
// For Student Performance Trends: Assume term_id in progress_aggregates, fetch avg per term
$performance_query = $db->query("SELECT term_id, AVG(avg_scaled_score) as avg_score FROM progress_aggregates GROUP BY term_id ORDER BY term_id LIMIT 4");
$performance_data = $performance_query->fetchAll(PDO::FETCH_ASSOC);
$performance_labels = [];
$performance_values = [];
foreach ($performance_data as $row) {
    $performance_labels[] = 'Term ' . $row['term_id'];
    $performance_values[] = round($row['avg_score'], 2);
}
if (empty($performance_labels)) {
    $performance_labels = ['Term 1', 'Term 2', 'Term 3', 'Term 4'];
    $performance_values = [0, 0, 0, $avg_score];
}
// For Attendance Overview: Avg attendance per class + overall
$attendance_query = $db->query("SELECT class_id, AVG(attendance) as avg_attendance FROM student_reports GROUP BY class_id LIMIT 3");
$attendance_data = $attendance_query->fetchAll(PDO::FETCH_ASSOC);
$attendance_labels = [];
$attendance_values = [];
foreach ($attendance_data as $row) {
    $attendance_labels[] = 'Class ' . $row['class_id'];
    $attendance_values[] = round($row['avg_attendance'], 2);
}
$attendance_labels[] = 'Overall';
$attendance_values[] = round($avg_attendance, 2);
if (empty($attendance_labels)) {
    $attendance_labels = ['Class 1', 'Class 2', 'Class 3', 'Overall'];
    $attendance_values = [0, 0, 0, $avg_attendance];
}
// For Teacher Evaluation Scores: Assume scores are categories, fetch avg per score_1 to score_3 + overall
$eval_query = $db->query("SELECT AVG(score_1) as s1, AVG(score_2) as s2, AVG(score_3) as s3 FROM teacher_evaluations");
$eval_data = $eval_query->fetch(PDO::FETCH_ASSOC);
$eval_labels = ['Score 1', 'Score 2', 'Score 3', 'Avg'];
$eval_values = [round($eval_data['s1'] ?? 0, 2), round($eval_data['s2'] ?? 0, 2), round($eval_data['s3'] ?? 0, 2), round($avg_teacher_eval, 2)];
// For User Activity Over Time: Logins per day last 7 days
$activity_values = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $count = $db->query("SELECT COUNT(*) FROM users WHERE DATE(last_login) = '$date'")->fetchColumn();
    $activity_values[] = $count;
}
$activity_labels = [];
for ($i = 6; $i >= 0; $i--) {
    $activity_labels[] = 'Day ' . (7 - $i);
}
if (empty($activity_values)) {
    $activity_values = [0, 0, 0, 0, 0, 0, $recent_logins];
}
// For Quiz Submissions Trend: Submissions per month or recent, but since doughnut with single value, keep as is
// For Resource Rating Distribution: Assume polar with single avg, keep
// For Promotion Logs Over Time: Promotions per month, but bar with single value, keep
?>
<?php include '../templates/admin_header.php'; ?>
    <!-- Enhanced Dashboard Content - Expanded for Perfection -->
    <section class="dashboard">
        <h1 class="page-title">Admin Dashboard - EduSync Ultimate</h1>
        <!-- KPIs Grid (Ultra Expanded with All Possible Metrics from DB) -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <i class="fas fa-users icon"></i>
                <div class="value"><?php echo $total_students; ?></div>
                <div class="label">Total Students</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-chalkboard-teacher icon"></i>
                <div class="value"><?php echo $total_teachers; ?></div>
                <div class="label">Total Teachers</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-user-friends icon"></i>
                <div class="value"><?php echo $total_parents; ?></div>
                <div class="label">Total Parents</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-user-shield icon"></i>
                <div class="value"><?php echo $total_admins; ?></div>
                <div class="label">Total Admins</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-percentage icon"></i>
                <div class="value"><?php echo round($avg_attendance, 2); ?>%</div>
                <div class="label">Avg Attendance</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-money-bill-wave icon"></i>
                <div class="value">$<?php echo number_format($fees_collected, 2); ?></div>
                <div class="label">Fees Collected</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-tasks icon"></i>
                <div class="value"><?php echo $pending_assignments; ?></div>
                <div class="label">Pending Assignments</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-file-alt icon"></i>
                <div class="value"><?php echo $pending_transcripts; ?></div>
                <div class="label">Pending Transcripts</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-arrow-up icon"></i>
                <div class="value"><?php echo $recent_promotions; ?></div>
                <div class="label">Recent Promotions</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-star icon"></i>
                <div class="value"><?php echo round($avg_score, 2); ?></div>
                <div class="label">Avg Student Score</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-chart-line icon"></i>
                <div class="value"><?php echo round($avg_teacher_eval, 2); ?></div>
                <div class="label">Avg Teacher Eval</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-book icon"></i>
                <div class="value"><?php echo $active_subjects; ?></div>
                <div class="label">Active Subjects</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-heartbeat icon"></i>
                <div class="value"><?php echo $wellness_resources_count; ?></div>
                <div class="label">Wellness Resources</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-comments icon"></i>
                <div class="value"><?php echo $active_chats; ?></div>
                <div class="label">Active Chats</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-pencil-alt icon"></i>
                <div class="value"><?php echo $draft_mocks; ?></div>
                <div class="label">Draft Mocks</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-check-circle icon"></i>
                <div class="value"><?php echo $recent_quiz_subs; ?></div>
                <div class="label">Recent Quiz Subs</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-star-half-alt icon"></i>
                <div class="value"><?php echo round($avg_resource_rating, 2); ?></div>
                <div class="label">Avg Resource Rating</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-trophy icon"></i>
                <div class="value"><?php echo $total_promotions; ?></div>
                <div class="label">Total Promotions</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-sticky-note icon"></i>
                <div class="value"><?php echo $recent_teacher_notes; ?></div>
                <div class="label">Recent Teacher Notes</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-calendar-week icon"></i>
                <div class="value"><?php echo $total_termly_plans; ?></div>
                <div class="label">Total Termly Plans</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-comment-dots icon"></i>
                <div class="value"><?php echo $student_feedback_count; ?></div>
                <div class="label">Student Feedback</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-file-signature icon"></i>
                <div class="value"><?php echo $report_summaries_count; ?></div>
                <div class="label">Report Summaries</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-book-reader icon"></i>
                <div class="value"><?php echo $user_library_count; ?></div>
                <div class="label">User Library Entries</div>
            </div>
        </div>
        <!-- Visualizations (Enhanced with more charts) -->
        <div class="charts-grid">
            <div class="chart-card">
                <h3>Student Performance Trends</h3>
                <canvas id="performanceChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>Attendance Overview</h3>
                <canvas id="attendanceChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>Fees Collection Breakdown</h3>
                <canvas id="feesChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>Teacher Evaluation Scores</h3>
                <canvas id="evalChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>User Activity Over Time</h3>
                <canvas id="activityChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>Quiz Submissions Trend</h3>
                <canvas id="quizSubsChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>Resource Rating Distribution</h3>
                <canvas id="ratingChart"></canvas>
            </div>
            <div class="chart-card">
                <h3>Promotion Logs Over Time</h3>
                <canvas id="promotionsChart"></canvas>
            </div>
        </div>
        <!-- Top Performers and Alerts Sections (Expanded) -->
        <div class="performance-alerts-grid">
            <div class="top-performers-card">
                <h3>Top Performing Students</h3>
                <ul class="list">
                    <?php foreach ($top_students as $student): ?>
                        <li><?php echo htmlspecialchars($student['name']); ?> - Score: <?php echo $student['avg_scaled_score']; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="low-attendance-card">
                <h3>Students with Low Attendance</h3>
                <ul class="list">
                    <?php foreach ($low_attendance as $student): ?>
                        <li><?php echo htmlspecialchars($student['name']); ?> - <?php echo $student['attendance']; ?>%</li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="upcoming-events-card">
                <h3>Upcoming Events</h3>
                <ul class="list">
                    <?php foreach ($upcoming_events as $event): ?>
                        <li><?php echo htmlspecialchars($event['title']); ?> on <?php echo $event['event_date']; ?> at <?php echo $event['start_time']; ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <!-- Recent Activity and Alerts (Expanded with More Details) -->
        <div class="activity-alerts-grid">
            <div class="activity-card">
                <h3>Recent Activity Log</h3>
                <ul class="activity-list">
                    <?php foreach ($recent_activity as $activity): ?>
                        <li>
                            <span class="time"><?php echo htmlspecialchars($activity['created_at']); ?></span>
                            <span class="user"><?php echo htmlspecialchars($activity['name']); ?></span>: <?php echo htmlspecialchars($activity['action']); ?>
                            <p><?php echo htmlspecialchars($activity['description']); ?></p>
                            <small>IP: <?php echo htmlspecialchars($activity['ip_address']); ?> - Agent: <?php echo htmlspecialchars($activity['user_agent']); ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="alerts-card">
                <h3>Critical Alerts</h3>
                <ul class="alerts-list">
                    <?php foreach ($alerts as $alert): ?>
                        <li>
                            <i class="fas fa-bell icon"></i>
                            <div class="title"><?php echo htmlspecialchars($alert['title']); ?></div>
                            <div class="message"><?php echo htmlspecialchars($alert['message']); ?></div>
                            <span class="time"><?php echo htmlspecialchars($alert['created_at']); ?></span>
                            <small>Type: <?php echo htmlspecialchars($alert['type']); ?> - Category: <?php echo htmlspecialchars($alert['category']); ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <!-- Quick Actions (Expanded with More Options) -->
        <div class="quick-actions">
            <h3>Quick Actions Hub</h3>
            <div class="actions-grid">
                <a href="/admin/users.php?action=add" class="action-button"><i class="fas fa-user-plus"></i> Add User</a>
                <a href="/admin/classes.php" class="action-button"><i class="fas fa-chalkboard"></i> Manage Classes</a>
                <a href="/admin/fees.php" class="action-button"><i class="fas fa-money-check"></i> Process Payment</a>
                <a href="/admin/reports.php" class="action-button"><i class="fas fa-file-pdf"></i> Generate Report</a>
                <a href="/admin/notifications.php" class="action-button"><i class="fas fa-bullhorn"></i> Send Notification</a>
                <a href="/admin/calendar.php" class="action-button"><i class="fas fa-calendar-plus"></i> Add Event</a>
                <a href="/admin/assignments.php" class="action-button"><i class="fas fa-tasks"></i> Manage Assignments</a>
                <a href="/admin/maintenance.php" class="action-button"><i class="fas fa-tools"></i> System Maintenance</a>
                <a href="/admin/subjects.php" class="action-button"><i class="fas fa-book-open"></i> Manage Subjects</a>
                <a href="/admin/evaluations.php" class="action-button"><i class="fas fa-clipboard-check"></i> Teacher Evaluations</a>
                <a href="/admin/resources.php" class="action-button"><i class="fas fa-folder-open"></i> Manage Resources</a>
                <a href="/admin/logs.php" class="action-button"><i class="fas fa-file-alt"></i> View Logs</a>
            </div>
        </div>
        <!-- Advanced AI Insights (Enhanced Prompt for Better Analysis) -->
        <div class="ai-insights-card">
            <h3>AI-Powered Insights <i class="fas fa-brain"></i></h3>
            <p><?php echo nl2br(htmlspecialchars($ai_insights)); ?></p>
            <button onclick="refreshInsights()">Refresh Insights</button>
        </div>
        <!-- New Section: Recent Mock Entries -->
        <div class="recent-mocks-card">
            <h3>Recent Mock Entries</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Teacher ID</th>
                        <th>Class ID</th>
                        <th>Subject ID</th>
                        <th>Term ID</th>
                        <th>Mock Number</th>
                        <th>Academic Year</th>
                        <th>Exam Date</th>
                        <th>Status</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_mocks as $mock): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($mock['teacher_id']); ?></td>
                            <td><?php echo htmlspecialchars($mock['class_id']); ?></td>
                            <td><?php echo htmlspecialchars($mock['subject_id']); ?></td>
                            <td><?php echo htmlspecialchars($mock['term_id']); ?></td>
                            <td><?php echo htmlspecialchars($mock['mock_number']); ?></td>
                            <td><?php echo htmlspecialchars($mock['academic_year']); ?></td>
                            <td><?php echo htmlspecialchars($mock['exam_date']); ?></td>
                            <td><?php echo htmlspecialchars($mock['status']); ?></td>
                            <td><?php echo htmlspecialchars($mock['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- New Section: Recent Quiz Submissions -->
        <div class="recent-quiz-subs-card">
            <h3>Recent Quiz Submissions</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Quiz Title</th>
                        <th>Score</th>
                        <th>Submitted At</th>
                        <th>Is Correct</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_quiz_submissions as $sub): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($sub['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($sub['quiz_title']); ?></td>
                            <td><?php echo htmlspecialchars($sub['score']); ?></td>
                            <td><?php echo htmlspecialchars($sub['submitted_at']); ?></td>
                            <td><?php echo $sub['is_correct'] ? 'Yes' : 'No'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- New Section: Recent Resource Feedback -->
        <div class="recent-feedback-card">
            <h3>Recent Resource Feedback</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User Name</th>
                        <th>Resource ID</th>
                        <th>Rating</th>
                        <th>Comment</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_resource_feedback as $feedback): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($feedback['user_name']); ?></td>
                            <td><?php echo htmlspecialchars($feedback['resource_id']); ?></td>
                            <td><?php echo htmlspecialchars($feedback['rating']); ?></td>
                            <td><?php echo htmlspecialchars($feedback['comment']); ?></td>
                            <td><?php echo htmlspecialchars($feedback['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- New Section: Recent Teacher Evaluations -->
        <div class="recent-evals-card">
            <h3>Recent Teacher Evaluations</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Teacher Name</th>
                        <th>Class ID</th>
                        <th>Term ID</th>
                        <th>Avg Score</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_teacher_evals as $eval): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($eval['first_name'] . ' ' . $eval['last_name']); ?></td>
                            <td><?php echo htmlspecialchars($eval['class_id']); ?></td>
                            <td><?php echo htmlspecialchars($eval['term_id']); ?></td>
                            <td><?php echo round(($eval['score_1'] + $eval['score_2'] + $eval['score_3'] + $eval['score_4'] + $eval['score_5'] + $eval['score_6'] + $eval['score_7'] + $eval['score_8'] + $eval['score_9'] + $eval['score_10'] + $eval['score_11'] + $eval['score_12'] + $eval['score_13'] + $eval['score_14'] + $eval['score_15'] + $eval['score_16'] + $eval['score_17']) / 17, 2); ?></td>
                            <td><?php echo htmlspecialchars($eval['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- New Section: Recent Termly Plans -->
        <div class="recent-plans-card">
            <h3>Recent Termly Plans</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Teacher Name</th>
                        <th>Class ID</th>
                        <th>Subject ID</th>
                        <th>Term Number</th>
                        <th>Week</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_termly_plans as $plan): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($plan['teacher_name']); ?></td>
                            <td><?php echo htmlspecialchars($plan['class_id']); ?></td>
                            <td><?php echo htmlspecialchars($plan['subject_id']); ?></td>
                            <td><?php echo htmlspecialchars($plan['term_number']); ?></td>
                            <td><?php echo htmlspecialchars($plan['week']); ?></td>
                            <td><?php echo htmlspecialchars($plan['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- New Section: Recent Student Progress -->
        <div class="recent-progress-card">
            <h3>Recent Student Progress Updates</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Points Earned</th>
                        <th>Badge ID</th>
                        <th>Goal Description</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_student_progress as $progress): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($progress['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($progress['points_earned']); ?></td>
                            <td><?php echo htmlspecialchars($progress['badge_id']); ?></td>
                            <td><?php echo htmlspecialchars($progress['goal_description']); ?></td>
                            <td><?php echo htmlspecialchars($progress['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Private Chats -->
        <div class="recent-chats-card">
            <h3>Recent Active Private Chats</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Sender Name</th>
                        <th>Recipient Name</th>
                        <th>Title</th>
                        <th>Start Time</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_chats as $chat): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($chat['teacher_name']); ?></td>
                            <td><?php echo htmlspecialchars($chat['recipient_name']); ?></td>
                            <td><?php echo htmlspecialchars($chat['title']); ?></td>
                            <td><?php echo htmlspecialchars($chat['start_time']); ?></td>
                            <td><?php echo htmlspecialchars($chat['status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Payments -->
        <div class="recent-payments-card">
            <h3>Recent Payments</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User Name</th>
                        <th>Amount Paid</th>
                        <th>Payment Date</th>
                        <th>Method</th>
                        <th>Receipt Number</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_payments as $payment): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($payment['user_name']); ?></td>
                            <td><?php echo htmlspecialchars($payment['amount_paid']); ?></td>
                            <td><?php echo htmlspecialchars($payment['payment_date']); ?></td>
                            <td><?php echo htmlspecialchars($payment['payment_method']); ?></td>
                            <td><?php echo htmlspecialchars($payment['receipt_number']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Notifications -->
        <div class="recent-notifications-card">
            <h3>Recent Notifications</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User Name</th>
                        <th>Title</th>
                        <th>Message</th>
                        <th>Type</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_notifications as $notification): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($notification['user_name']); ?></td>
                            <td><?php echo htmlspecialchars($notification['title']); ?></td>
                            <td><?php echo htmlspecialchars($notification['message']); ?></td>
                            <td><?php echo htmlspecialchars($notification['type']); ?></td>
                            <td><?php echo htmlspecialchars($notification['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Promotion Logs -->
        <div class="recent-promotions-card">
            <h3>Recent Promotion Logs</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Old Class ID</th>
                        <th>New Class ID</th>
                        <th>Teacher Name</th>
                        <th>Academic Year</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_promotion_logs as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($log['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($log['old_class_id']); ?></td>
                            <td><?php echo htmlspecialchars($log['new_class_id']); ?></td>
                            <td><?php echo htmlspecialchars($log['teacher_name']); ?></td>
                            <td><?php echo htmlspecialchars($log['academic_year']); ?></td>
                            <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Student Reports -->
        <div class="recent-reports-card">
            <h3>Recent Student Reports</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Class ID</th>
                        <th>Term ID</th>
                        <th>Attendance</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_student_reports as $report): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($report['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($report['class_id']); ?></td>
                            <td><?php echo htmlspecialchars($report['term_id']); ?></td>
                            <td><?php echo htmlspecialchars($report['attendance']); ?></td>
                            <td><?php echo htmlspecialchars($report['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Transcript Requests -->
        <div class="recent-transcripts-card">
            <h3>Recent Transcript Requests</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Request Type</th>
                        <th>Delivery Method</th>
                        <th>Status</th>
                        <th>Requested At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_transcript_requests as $request): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($request['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($request['request_type']); ?></td>
                            <td><?php echo htmlspecialchars($request['delivery_method']); ?></td>
                            <td><?php echo htmlspecialchars($request['status']); ?></td>
                            <td><?php echo htmlspecialchars($request['requested_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Expanded User Logs -->
        <div class="expanded-logs-card">
            <h3>Expanded User Logs (Last 20)</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User Name</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>IP Address</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($expanded_user_logs as $log): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($log['name']); ?></td>
                            <td><?php echo htmlspecialchars($log['action']); ?></td>
                            <td><?php echo htmlspecialchars($log['description']); ?></td>
                            <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                            <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent School Events -->
        <div class="recent-events-card">
            <h3>Recent School Events (Last 10)</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Description</th>
                        <th>Event Type</th>
                        <th>Event Date</th>
                        <th>Start Time</th>
                        <th>End Time</th>
                        <th>Location</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_school_events as $event): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($event['title']); ?></td>
                            <td><?php echo htmlspecialchars($event['description']); ?></td>
                            <td><?php echo htmlspecialchars($event['event_type']); ?></td>
                            <td><?php echo htmlspecialchars($event['event_date']); ?></td>
                            <td><?php echo htmlspecialchars($event['start_time']); ?></td>
                            <td><?php echo htmlspecialchars($event['end_time']); ?></td>
                            <td><?php echo htmlspecialchars($event['location']); ?></td>
                            <td><?php echo htmlspecialchars($event['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Response Cache -->
        <div class="recent-cache-card">
            <h3>Recent Response Cache</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User Name</th>
                        <th>Prompt Hash</th>
                        <th>Response</th>
                        <th>Created At</th>
                        <th>Expires At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_response_cache as $cache): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($cache['user_name']); ?></td>
                            <td><?php echo htmlspecialchars($cache['prompt_hash']); ?></td>
                            <td><?php echo htmlspecialchars(substr($cache['response'], 0, 100)); ?>...</td>
                            <td><?php echo htmlspecialchars($cache['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($cache['expires_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Parent Student Mappings -->
        <div class="recent-mappings-card">
            <h3>Recent Parent Student Mappings</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Parent Name</th>
                        <th>Student Name</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_parent_mappings as $mapping): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($mapping['parent_name']); ?></td>
                            <td><?php echo htmlspecialchars($mapping['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($mapping['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Quizzes -->
        <div class="recent-quizzes-card">
            <h3>Recent Quizzes</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Teacher Name</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Class ID</th>
                        <th>Subject ID</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_quizzes as $quiz): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($quiz['teacher_name']); ?></td>
                            <td><?php echo htmlspecialchars($quiz['title']); ?></td>
                            <td><?php echo htmlspecialchars($quiz['status']); ?></td>
                            <td><?php echo htmlspecialchars($quiz['class_id']); ?></td>
                            <td><?php echo htmlspecialchars($quiz['subject_id']); ?></td>
                            <td><?php echo htmlspecialchars($quiz['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Teacher Notes -->
        <div class="recent-teacher-notes-card">
            <h3>Recent Teacher Notes</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Teacher Name</th>
                        <th>Week</th>
                        <th>Class ID</th>
                        <th>Term ID</th>
                        <th>Subject ID</th>
                        <th>Format</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_teacher_notes_full as $note): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($note['teacher_name']); ?></td>
                            <td><?php echo htmlspecialchars($note['week']); ?></td>
                            <td><?php echo htmlspecialchars($note['class_id']); ?></td>
                            <td><?php echo htmlspecialchars($note['term_id']); ?></td>
                            <td><?php echo htmlspecialchars($note['subject_id']); ?></td>
                            <td><?php echo htmlspecialchars($note['format']); ?></td>
                            <td><?php echo htmlspecialchars($note['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Student Classes -->
        <div class="recent-student-classes-card">
            <h3>Recent Student Classes</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Class ID</th>
                        <th>Academic Year</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_student_classes as $class): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($class['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($class['class_id']); ?></td>
                            <td><?php echo htmlspecialchars($class['academic_year']); ?></td>
                            <td><?php echo htmlspecialchars($class['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Student Feedback -->
        <div class="recent-student-feedback-card">
            <h3>Recent Student Feedback</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Class ID</th>
                        <th>Assessment ID</th>
                        <th>Rating</th>
                        <th>Feedback</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_student_feedback as $feedback): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($feedback['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($feedback['class_id']); ?></td>
                            <td><?php echo htmlspecialchars($feedback['assessment_id']); ?></td>
                            <td><?php echo htmlspecialchars($feedback['rating']); ?></td>
                            <td><?php echo htmlspecialchars($feedback['feedback']); ?></td>
                            <td><?php echo htmlspecialchars($feedback['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Report Summaries -->
        <div class="recent-report-summaries-card">
            <h3>Recent Report Summaries</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User Name</th>
                        <th>Report ID</th>
                        <th>Term ID</th>
                        <th>Summary</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_report_summaries as $summary): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($summary['user_name']); ?></td>
                            <td><?php echo htmlspecialchars($summary['report_id']); ?></td>
                            <td><?php echo htmlspecialchars($summary['term_id']); ?></td>
                            <td><?php echo htmlspecialchars(substr($summary['summary'], 0, 100)); ?>...</td>
                            <td><?php echo htmlspecialchars($summary['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent User Library -->
        <div class="recent-user-library-card">
            <h3>Recent User Library Additions</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User Name</th>
                        <th>Book ID</th>
                        <th>Added At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_user_library as $library): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($library['user_name']); ?></td>
                            <td><?php echo htmlspecialchars($library['book_id']); ?></td>
                            <td><?php echo htmlspecialchars($library['added_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Wellness Resources -->
        <div class="recent-wellness-card">
            <h3>Recent Wellness Resources</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Content URL</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_wellness_resources as $resource): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($resource['title']); ?></td>
                            <td><?php echo htmlspecialchars($resource['type']); ?></td>
                            <td><?php echo htmlspecialchars($resource['content_url']); ?></td>
                            <td><?php echo htmlspecialchars($resource['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Chat Messages -->
        <div class="recent-messages-card">
            <h3>Recent Private Chat Messages</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>User Name</th>
                        <th>Message</th>
                        <th>Read Status</th>
                        <th>Created At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_chat_messages as $message): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($message['user_name']); ?></td>
                            <td><?php echo htmlspecialchars(substr($message['message'], 0, 100)); ?>...</td>
                            <td><?php echo htmlspecialchars($message['read_status']); ?></td>
                            <td><?php echo htmlspecialchars($message['created_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <!-- Additional Section: Recent Progress Aggregates -->
        <div class="recent-aggregates-card">
            <h3>Recent Progress Aggregates</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Student Name</th>
                        <th>Avg Scaled Score</th>
                        <th>Highest Scaled Score</th>
                        <th>Entry Count</th>
                        <th>Academic Year</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_progress_aggregates as $aggregate): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($aggregate['student_name']); ?></td>
                            <td><?php echo htmlspecialchars($aggregate['avg_scaled_score']); ?></td>
                            <td><?php echo htmlspecialchars($aggregate['highest_scaled_score']); ?></td>
                            <td><?php echo htmlspecialchars($aggregate['entry_count']); ?></td>
                            <td><?php echo htmlspecialchars($aggregate['academic_year']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Expanded Chart Data - With more dynamic elements
        const performanceCtx = document.getElementById('performanceChart').getContext('2d');
        new Chart(performanceCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($performance_labels); ?>,
                datasets: [{
                    label: 'Avg Score',
                    data: <?php echo json_encode($performance_values); ?>,
                    borderColor: 'var(--primary-sea-blue)',
                    tension: 0.1,
                    fill: true,
                    backgroundColor: 'rgba(23, 162, 184, 0.2)'
                }]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true } },
                plugins: { legend: { display: true } }
            }
        });
        const attendanceCtx = document.getElementById('attendanceChart').getContext('2d');
        new Chart(attendanceCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($attendance_labels); ?>,
                datasets: [{
                    label: 'Attendance %',
                    data: <?php echo json_encode($attendance_values); ?>,
                    backgroundColor: 'var(--accent-soft-cyan)'
                }]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true } }
            }
        });
        const feesCtx = document.getElementById('feesChart').getContext('2d');
        new Chart(feesCtx, {
            type: 'pie',
            data: {
                labels: ['Collected', 'Outstanding'],
                datasets: [{
                    data: [<?php echo $fees_collected; ?>, <?php echo $outstanding_fees; ?>],
                    backgroundColor: ['var(--primary-sea-blue)', 'var(--accent-slate-gray)']
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
        const evalCtx = document.getElementById('evalChart').getContext('2d');
        new Chart(evalCtx, {
            type: 'radar',
            data: {
                labels: <?php echo json_encode($eval_labels); ?>,
                datasets: [{
                    label: 'Teacher Evals',
                    data: <?php echo json_encode($eval_values); ?>,
                    backgroundColor: 'rgba(23, 162, 184, 0.2)',
                    borderColor: 'var(--primary-sea-blue)'
                }]
            },
            options: { responsive: true }
        });
        const activityCtx = document.getElementById('activityChart').getContext('2d');
        new Chart(activityCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($activity_labels); ?>,
                datasets: [{
                    label: 'Logins',
                    data: <?php echo json_encode($activity_values); ?>,
                    borderColor: 'var(--accent-soft-cyan)',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                scales: { y: { beginAtZero: true } }
            }
        });
        // New Charts
        const quizSubsCtx = document.getElementById('quizSubsChart').getContext('2d');
        new Chart(quizSubsCtx, {
            type: 'doughnut',
            data: {
                labels: ['Recent Subs'],
                datasets: [{
                    data: [<?php echo $recent_quiz_subs; ?>],
                    backgroundColor: 'var(--primary-sea-blue)'
                }]
            },
            options: { responsive: true }
        });
        const ratingCtx = document.getElementById('ratingChart').getContext('2d');
        new Chart(ratingCtx, {
            type: 'polarArea',
            data: {
                labels: ['Avg Rating'],
                datasets: [{
                    data: [<?php echo $avg_resource_rating; ?>],
                    backgroundColor: 'var(--accent-soft-cyan)'
                }]
            },
            options: { responsive: true }
        });
        const promotionsCtx = document.getElementById('promotionsChart').getContext('2d');
        new Chart(promotionsCtx, {
            type: 'bar',
            data: {
                labels: ['Total Promotions'],
                datasets: [{
                    label: 'Promotions',
                    data: [<?php echo $total_promotions; ?>],
                    backgroundColor: 'var(--primary-sea-blue)'
                }]
            },
            options: { responsive: true }
        });
        // Refresh AI Insights (AJAX to regenerate)
        function refreshInsights() {
            fetch('/admin/refresh_insights.php')
                .then(response => response.text())
                .then(data => {
                    document.querySelector('.ai-insights-card p').innerHTML = data;
                })
                .catch(error => console.error('Error refreshing insights:', error));
        }
    </script>
    <style>
        /* Ultra Enhanced Dashboard Styles for World-Class UI - Expanded for Detail */
        .dashboard {
            display: grid;
            gap: 24px;
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
        }
        .page-title {
            font-family: var(--font-ui);
            font-size: 32px;
            font-weight: 700;
            color: var(--primary-black);
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            text-align: center;
            margin-bottom: 20px;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
        }
        .kpi-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            text-align: center;
            transition: transform 0.2s ease-out, box-shadow 0.2s;
        }
        .kpi-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }
        .kpi-card .icon {
            font-size: 36px;
            color: var(--primary-sea-blue);
            margin-bottom: 8px;
            display: block;
        }
        .kpi-card .value {
            font-size: 28px;
            font-weight: 700;
            color: var(--primary-black);
        }
        .kpi-card .label {
            font-size: 14px;
            color: var(--accent-slate-gray);
            text-transform: uppercase;
        }
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
        }
        .chart-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            transition: box-shadow 0.2s;
        }
        .chart-card:hover {
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.15);
        }
        .chart-card h3 {
            font-size: 20px;
            margin-bottom: 12px;
            color: var(--primary-black);
            text-align: center;
        }
        .performance-alerts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 16px;
        }
        .top-performers-card, .low-attendance-card, .upcoming-events-card, .recent-mocks-card, .recent-quiz-subs-card, .recent-feedback-card, .recent-evals-card, .recent-plans-card, .recent-progress-card, .recent-chats-card, .recent-payments-card, .recent-notifications-card, .recent-promotions-card, .recent-reports-card, .recent-transcripts-card, .expanded-logs-card, .recent-events-card, .recent-cache-card, .recent-mappings-card, .recent-quizzes-card, .recent-teacher-notes-card, .recent-student-classes-card, .recent-student-feedback-card, .recent-report-summaries-card, .recent-user-library-card, .recent-wellness-card, .recent-messages-card, .recent-aggregates-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            overflow-x: auto;
        }
        .list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .list li {
            padding: 8px 0;
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            font-size: 14px;
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
            font-weight: 600;
        }
        .activity-alerts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
        }
        .activity-card, .alerts-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            overflow-y: auto;
            max-height: 400px;
        }
        .activity-list, .alerts-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        .activity-list li, .alerts-list li {
            border-bottom: 1px solid rgba(0, 0, 0, 0.1);
            padding: 12px 0;
            transition: background-color 0.2s;
            font-size: 14px;
        }
        .activity-list li:hover, .alerts-list li:hover {
            background-color: rgba(0, 0, 0, 0.02);
        }
        .time {
            font-size: 12px;
            color: var(--accent-slate-gray);
        }
        .user {
            font-weight: 600;
        }
        .quick-actions {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }
        .actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
        }
        .action-button {
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 16px;
            transition: background-color 0.2s ease-out, transform 0.2s;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .action-button:hover {
            background-color: var(--accent-soft-cyan);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
        }
        .action-button i {
            margin-right: 12px;
            font-size: 18px;
        }
        .ai-insights-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 20px;
            margin-top: 24px;
        }
        .ai-insights-card h3 {
            display: flex;
            align-items: center;
            font-size: 22px;
            margin-bottom: 16px;
        }
        .ai-insights-card h3 i {
            margin-left: 12px;
            color: var(--primary-sea-blue);
            font-size: 24px;
        }
        .ai-insights-card p {
            white-space: pre-wrap;
            line-height: 1.6;
            font-size: 15px;
            color: var(--primary-black);
        }
        .ai-insights-card button {
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            cursor: pointer;
            margin-top: 16px;
            transition: background-color 0.2s, transform 0.2s;
            font-size: 16px;
        }
        .ai-insights-card button:hover {
            background-color: var(--accent-soft-cyan);
            transform: scale(1.05);
        }
        /* Enhanced Responsiveness */
        @media (max-width: 1200px) {
            .charts-grid {
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            }
            .performance-alerts-grid {
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            }
        }
        @media (max-width: 992px) {
            .dashboard {
                padding: 15px;
            }
            .kpi-grid {
                grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            }
            .actions-grid {
                grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            }
            .data-table {
                font-size: 13px;
            }
            .data-table th, .data-table td {
                padding: 10px;
            }
        }
        @media (max-width: 768px) {
            .activity-alerts-grid, .performance-alerts-grid, .charts-grid {
                grid-template-columns: 1fr;
            }
            .kpi-grid {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            }
            .data-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            .data-table thead {
                display: none;
            }
            .data-table tbody tr {
                display: block;
                margin-bottom: 10px;
                border: 1px solid rgba(0, 0, 0, 0.1);
                padding: 10px;
            }
            .data-table td {
                display: block;
                text-align: right;
                position: relative;
                padding-left: 50%;
            }
            .data-table td:before {
                content: attr(data-label);
                position: absolute;
                left: 10px;
                width: calc(50% - 20px);
                white-space: nowrap;
                text-align: left;
                font-weight: bold;
            }
        }
        @media (max-width: 576px) {
            .page-title {
                font-size: 24px;
            }
            .kpi-card .value {
                font-size: 24px;
            }
            .kpi-card .icon {
                font-size: 30px;
            }
            .chart-card h3 {
                font-size: 18px;
            }
            .ai-insights-card h3 {
                font-size: 20px;
            }
        }
    </style>
<?php include '../templates/admin_footer.php'; ?>