<?php
// teacher/dashboard.php - Light Theme Version
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not authenticated as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    header("Location: ../../login.php");
    exit();
}

// Get root path
$root_path = dirname(dirname(__FILE__)) . '/';

// Include database connection
require_once $root_path . 'config/db.php';

// Check dark mode preference
$dark_mode = isset($_COOKIE['dark_mode']) && $_COOKIE['dark_mode'] === 'true';

try {
    $db = db();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $teacher_id = $_SESSION['user_id'];
    $current_date = date('Y-m-d');
    
    // 1. Get current term
    $current_term = null;
    $term_id = null;
    
    try {
        $stmt = $db->prepare("
            SELECT t.*, ay.year 
            FROM terms t 
            JOIN academic_years ay ON t.academic_year_id = ay.id 
            WHERE ? BETWEEN t.start_date AND t.end_date 
            LIMIT 1
        ");
        $stmt->execute([$current_date]);
        $current_term = $stmt->fetch(PDO::FETCH_ASSOC);
        $term_id = $current_term ? $current_term['id'] : null;
    } catch (Exception $e) {
        error_log("Term query error: " . $e->getMessage());
    }
    
    // 2. TEACHER KPIS
    $kpis = [
        'classes_taught' => 0,
        'total_students' => 0,
        'pending_grading' => 0,
        'todays_classes' => 0,
        'avg_attendance' => 0,
        'upcoming_deadlines' => 0
    ];
    
    // Classes taught
    try {
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT c.id) 
            FROM classes c
            WHERE c.teacher_id = ? OR c.class_teacher_id = ?
        ");
        $stmt->execute([$teacher_id, $teacher_id]);
        $kpis['classes_taught'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Classes taught query error: " . $e->getMessage());
    }
    
    // Total students taught
    try {
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT sc.user_id) 
            FROM student_classes sc
            JOIN classes c ON sc.class_id = c.id 
            WHERE (c.teacher_id = ? OR c.class_teacher_id = ?)
        ");
        $stmt->execute([$teacher_id, $teacher_id]);
        $kpis['total_students'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Total students query error: " . $e->getMessage());
    }
    
    // Pending assignments to grade
    try {
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT asub.id)
            FROM assignment_submissions asub
            JOIN assignments a ON asub.assignment_id = a.id
            WHERE a.teacher_id = ? 
            AND (asub.status = 'submitted' OR asub.status = 'pending')
            AND asub.is_graded = 0
        ");
        $stmt->execute([$teacher_id]);
        $kpis['pending_grading'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Pending grading query error: " . $e->getMessage());
    }
    
    // Today's classes
    try {
        $day_of_week = date('l');
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM class_schedules cs
            WHERE cs.teacher_id = ? 
            AND cs.day_of_week = ?
        ");
        $stmt->execute([$teacher_id, $day_of_week]);
        $kpis['todays_classes'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Today's classes query error: " . $e->getMessage());
    }
    
    // Upcoming deadlines
    try {
        $stmt = $db->prepare("
            SELECT COUNT(*)
            FROM assignments
            WHERE teacher_id = ? 
            AND due_date >= CURDATE()
            AND due_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND status = 'published'
        ");
        $stmt->execute([$teacher_id]);
        $kpis['upcoming_deadlines'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        error_log("Upcoming deadlines query error: " . $e->getMessage());
    }
    
    // 3. RECENT ACTIVITY
    $recent_assignments = [];
    $recent_submissions = [];
    
    // Recent assignments
    try {
        $stmt = $db->prepare("
            SELECT id, title, created_at
            FROM assignments 
            WHERE teacher_id = ? 
            ORDER BY created_at DESC 
            LIMIT 5
        ");
        $stmt->execute([$teacher_id]);
        $recent_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Recent assignments query error: " . $e->getMessage());
    }
    
    // Recent submissions
    try {
        $stmt = $db->prepare("
            SELECT asub.id, asub.created_at, a.title, u.name as student_name
            FROM assignment_submissions asub
            JOIN assignments a ON asub.assignment_id = a.id
            JOIN users u ON asub.user_id = u.id
            WHERE a.teacher_id = ?
            ORDER BY asub.created_at DESC 
            LIMIT 5
        ");
        $stmt->execute([$teacher_id]);
        $recent_submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Recent submissions query error: " . $e->getMessage());
    }
    
    // 4. TODAY'S SCHEDULE
    $todays_schedule = [];
    try {
        $stmt = $db->prepare("
            SELECT 
                cs.day_of_week,
                TIME_FORMAT(cs.start_time, '%h:%i %p') as start_time,
                TIME_FORMAT(cs.end_time, '%h:%i %p') as end_time,
                c.class_name,
                s.subject_name
            FROM class_schedules cs
            JOIN classes c ON cs.class_id = c.id
            JOIN subjects s ON cs.subject_id = s.id
            WHERE cs.teacher_id = ? 
            AND cs.day_of_week = ?
            ORDER BY cs.start_time
        ");
        $stmt->execute([$teacher_id, date('l')]);
        $todays_schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Today's schedule query error: " . $e->getMessage());
    }
    
    // 5. NOTIFICATIONS
    $notifications = [];
    try {
        $stmt = $db->prepare("
            SELECT 
                n.title,
                n.message,
                n.type,
                n.created_at,
                n.link
            FROM notifications n
            WHERE (n.user_id = ? OR n.target_role = 'teacher')
            AND n.is_read = 0
            ORDER BY n.created_at DESC
            LIMIT 5
        ");
        $stmt->execute([$teacher_id]);
        $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Notifications query error: " . $e->getMessage());
    }
    
    // 6. CLASS PERFORMANCE
    $class_performance = [];
    try {
        $stmt = $db->prepare("
            SELECT 
                c.id,
                c.class_name,
                COUNT(DISTINCT sc.user_id) as student_count
            FROM classes c
            LEFT JOIN student_classes sc ON c.id = sc.class_id
            WHERE c.teacher_id = ? OR c.class_teacher_id = ?
            GROUP BY c.id, c.class_name
            LIMIT 5
        ");
        $stmt->execute([$teacher_id, $teacher_id]);
        $class_performance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Class performance query error: " . $e->getMessage());
    }
    
    // 7. ASSIGNMENT STATISTICS
    $assignment_stats = [
        'published' => 0,
        'draft' => 0,
        'overdue' => 0,
        'total' => 0
    ];
    
    try {
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
                SUM(CASE WHEN due_date < CURDATE() AND status = 'published' THEN 1 ELSE 0 END) as overdue
            FROM assignments
            WHERE teacher_id = ?
        ");
        $stmt->execute([$teacher_id]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($stats) {
            $assignment_stats = array_merge($assignment_stats, $stats);
        }
    } catch (Exception $e) {
        error_log("Assignment stats query error: " . $e->getMessage());
    }
    
    // 8. LIVE CLASSES TODAY
    $live_classes = [];
    try {
        $stmt = $db->prepare("
            SELECT 
                lc.title,
                lc.start_time,
                lc.meeting_link,
                c.class_name
            FROM live_classes lc
            JOIN classes c ON lc.class_id = c.id
            WHERE DATE(lc.start_time) = CURDATE()
            AND (c.teacher_id = ? OR c.class_teacher_id = ?)
            ORDER BY lc.start_time
            LIMIT 3
        ");
        $stmt->execute([$teacher_id, $teacher_id]);
        $live_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Live classes query error: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    error_log("Dashboard initialization error: " . $e->getMessage());
    // Set default values
    $kpis = [
        'classes_taught' => 0,
        'total_students' => 0,
        'pending_grading' => 0,
        'todays_classes' => 0,
        'avg_attendance' => 0,
        'upcoming_deadlines' => 0
    ];
    $recent_assignments = [];
    $recent_submissions = [];
    $todays_schedule = [];
    $notifications = [];
    $class_performance = [];
    $assignment_stats = [
        'published' => 0,
        'draft' => 0,
        'overdue' => 0,
        'total' => 0
    ];
    $live_classes = [];
    $current_term = null;
}

// Include header
include $root_path . 'templates/teacher_header.php';
?>

<!-- Dashboard Content -->
<section class="dashboard">
    <!-- Page Header -->
    <div class="dashboard-header">
        <h1 class="page-title">Teacher Dashboard</h1>
        <div class="dashboard-subtitle">
            <span class="welcome">Welcome back, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Teacher'); ?>!</span>
            <span class="academic-info">
                <?php if ($current_term): ?>
                    <i class="fas fa-calendar"></i> Term: <?php echo htmlspecialchars($current_term['name']); ?> | 
                    Academic Year: <?php echo htmlspecialchars($current_term['year']); ?>
                <?php else: ?>
                    <i class="fas fa-calendar"></i> No active term
                <?php endif; ?>
            </span>
        </div>
    </div>

    <!-- Main KPI Grid -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon">
                <i class="fas fa-chalkboard-teacher"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value"><?php echo $kpis['classes_taught']; ?></div>
                <div class="kpi-label">Classes</div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value"><?php echo $kpis['total_students']; ?></div>
                <div class="kpi-label">Students</div>
            </div>
        </div>

        <div class="kpi-card warning">
            <div class="kpi-icon">
                <i class="fas fa-file-signature"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value"><?php echo $kpis['pending_grading']; ?></div>
                <div class="kpi-label">Pending</div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-icon">
                <i class="fas fa-calendar-day"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value"><?php echo $kpis['todays_classes']; ?></div>
                <div class="kpi-label">Today's Classes</div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-icon">
                <i class="fas fa-clock"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value"><?php echo $kpis['upcoming_deadlines']; ?></div>
                <div class="kpi-label">Deadlines</div>
            </div>
        </div>

        <div class="kpi-card">
            <div class="kpi-icon">
                <i class="fas fa-percentage"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value">0%</div>
                <div class="kpi-label">Avg Attendance</div>
            </div>
        </div>
    </div>

    <!-- Charts & Quick Actions -->
    <div class="dashboard-section">
        <div class="two-column-grid">
            <!-- Quick Actions -->
            <div class="card">
                <h3 class="card-title">Quick Actions</h3>
                <div class="quick-actions-grid">
                    <a href="assignments.php?action=create" class="quick-action">
                        <i class="fas fa-plus-circle"></i>
                        <span>Create Assignment</span>
                    </a>
                    <a href="lesson_plans.php?action=create" class="quick-action">
                        <i class="fas fa-book"></i>
                        <span>Plan Lesson</span>
                    </a>
                    <a href="attendance.php?date=<?php echo date('Y-m-d'); ?>" class="quick-action">
                        <i class="fas fa-clipboard-check"></i>
                        <span>Take Attendance</span>
                    </a>
                    <a href="grades.php" class="quick-action">
                        <i class="fas fa-star"></i>
                        <span>Enter Grades</span>
                    </a>
                    <a href="resources.php" class="quick-action">
                        <i class="fas fa-folder"></i>
                        <span>Resources</span>
                    </a>
                    <a href="reports.php" class="quick-action">
                        <i class="fas fa-chart-bar"></i>
                        <span>Reports</span>
                    </a>
                </div>
            </div>

            <!-- Live Classes Today -->
            <?php if (!empty($live_classes)): ?>
            <div class="card">
                <h3 class="card-title">Live Classes Today</h3>
                <div class="live-classes-list">
                    <?php foreach ($live_classes as $class): ?>
                        <div class="live-class-item">
                            <div class="live-class-icon">
                                <i class="fas fa-video"></i>
                            </div>
                            <div class="live-class-details">
                                <strong><?php echo htmlspecialchars($class['title']); ?></strong>
                                <span><?php echo htmlspecialchars($class['class_name']); ?></span>
                                <small>Starts at: <?php echo date('h:i A', strtotime($class['start_time'])); ?></small>
                            </div>
                            <?php if (!empty($class['meeting_link'])): ?>
                                <a href="<?php echo htmlspecialchars($class['meeting_link']); ?>" target="_blank" class="live-class-join">
                                    Join <i class="fas fa-external-link-alt"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Activity & Today's Schedule -->
    <div class="dashboard-section">
        <div class="two-column-grid">
            <!-- Recent Activity -->
            <div class="card">
                <h3 class="card-title">Recent Activity</h3>
                <div class="activity-tabs">
                    <div class="tab-header">
                        <button class="tab-button active" onclick="switchTab('assignments')">Assignments</button>
                        <button class="tab-button" onclick="switchTab('submissions')">Submissions</button>
                    </div>
                    
                    <div id="assignments-tab" class="tab-content active">
                        <div class="activity-list">
                            <?php if (empty($recent_assignments)): ?>
                                <p class="empty-message">No assignments created yet</p>
                            <?php else: ?>
                                <?php foreach ($recent_assignments as $assignment): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fas fa-file-alt"></i>
                                        </div>
                                        <div class="activity-details">
                                            <div class="activity-desc"><?php echo htmlspecialchars($assignment['title']); ?></div>
                                            <div class="activity-time"><?php echo date('M j, g:i A', strtotime($assignment['created_at'])); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div id="submissions-tab" class="tab-content">
                        <div class="activity-list">
                            <?php if (empty($recent_submissions)): ?>
                                <p class="empty-message">No submissions yet</p>
                            <?php else: ?>
                                <?php foreach ($recent_submissions as $submission): ?>
                                    <div class="activity-item">
                                        <div class="activity-icon">
                                            <i class="fas fa-paper-plane"></i>
                                        </div>
                                        <div class="activity-details">
                                            <div class="activity-desc">
                                                <?php echo htmlspecialchars($submission['student_name']); ?> - 
                                                <?php echo htmlspecialchars($submission['title']); ?>
                                            </div>
                                            <div class="activity-time"><?php echo date('M j, g:i A', strtotime($submission['created_at'])); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Today's Schedule -->
            <div class="card">
                <h3 class="card-title">
                    <i class="fas fa-calendar-day"></i> Today's Schedule
                </h3>
                <div class="schedule-list">
                    <?php if (empty($todays_schedule)): ?>
                        <p class="empty-message">No classes scheduled for today</p>
                    <?php else: ?>
                        <?php foreach ($todays_schedule as $schedule): ?>
                            <div class="schedule-item">
                                <div class="schedule-time">
                                    <?php echo htmlspecialchars($schedule['start_time']); ?> - 
                                    <?php echo htmlspecialchars($schedule['end_time']); ?>
                                </div>
                                <div class="schedule-details">
                                    <strong><?php echo htmlspecialchars($schedule['class_name']); ?></strong>
                                    <span><?php echo htmlspecialchars($schedule['subject_name']); ?></span>
                                </div>
                                <div class="schedule-status">
                                    <span class="status-badge upcoming">Upcoming</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics & Notifications -->
    <div class="dashboard-section">
        <div class="two-column-grid">
            <!-- Assignment Statistics -->
            <div class="card">
                <h3 class="card-title">Assignment Statistics</h3>
                <div class="stats-grid">
                    <div class="stat-item published">
                        <div class="stat-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $assignment_stats['published']; ?></div>
                            <div class="stat-label">Published</div>
                        </div>
                    </div>
                    <div class="stat-item draft">
                        <div class="stat-icon">
                            <i class="fas fa-edit"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $assignment_stats['draft']; ?></div>
                            <div class="stat-label">Draft</div>
                        </div>
                    </div>
                    <div class="stat-item overdue">
                        <div class="stat-icon">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $assignment_stats['overdue']; ?></div>
                            <div class="stat-label">Overdue</div>
                        </div>
                    </div>
                    <div class="stat-item total">
                        <div class="stat-icon">
                            <i class="fas fa-tasks"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $assignment_stats['total']; ?></div>
                            <div class="stat-label">Total</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notifications -->
            <div class="card">
                <h3 class="card-title">Recent Notifications</h3>
                <div class="notifications-list">
                    <?php if (empty($notifications)): ?>
                        <p class="empty-message">No new notifications</p>
                    <?php else: ?>
                        <?php foreach ($notifications as $notification): ?>
                            <div class="notification-item <?php echo htmlspecialchars($notification['type'] ?? 'info'); ?>">
                                <div class="notification-icon">
                                    <i class="fas fa-bell"></i>
                                </div>
                                <div class="notification-content">
                                    <strong><?php echo htmlspecialchars($notification['title']); ?></strong>
                                    <p><?php echo htmlspecialchars(substr($notification['message'], 0, 60)); ?>...</p>
                                    <small><?php echo date('M j, g:i A', strtotime($notification['created_at'])); ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($notifications)): ?>
                    <div class="card-footer">
                        <a href="notifications.php" class="view-all">View All Notifications</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- My Classes -->
    <div class="dashboard-section">
        <div class="card">
            <h3 class="card-title">
                <i class="fas fa-chalkboard-teacher"></i> My Classes
            </h3>
            <div class="classes-grid">
                <?php if (empty($class_performance)): ?>
                    <p class="empty-message">No classes assigned</p>
                <?php else: ?>
                    <?php foreach ($class_performance as $class): ?>
                        <div class="class-card">
                            <div class="class-header">
                                <i class="fas fa-chalkboard"></i>
                                <h4><?php echo htmlspecialchars($class['class_name']); ?></h4>
                            </div>
                            <div class="class-stats">
                                <div class="class-stat">
                                    <i class="fas fa-users"></i>
                                    <span><?php echo $class['student_count']; ?> Students</span>
                                </div>
                                <div class="class-stat">
                                    <i class="fas fa-tasks"></i>
                                    <span>0 Assignments</span>
                                </div>
                            </div>
                            <div class="class-actions">
                                <a href="class_details.php?id=<?php echo $class['id']; ?>" class="class-btn view">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="attendance.php?class_id=<?php echo $class['id']; ?>" class="class-btn attendance">
                                    <i class="fas fa-clipboard-check"></i> Attendance
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="card-footer">
                <a href="classes.php" class="view-all">Manage All Classes</a>
            </div>
        </div>
    </div>
</section>

<!-- Dashboard CSS -->
<style>
/* Dashboard Layout */
.dashboard {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
}

.dashboard-header {
    margin-bottom: 30px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--border-color);
}

.page-title {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 8px;
}

.dashboard-subtitle {
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: var(--text-secondary);
    font-size: 0.95rem;
}

.welcome {
    font-weight: 600;
    color: var(--primary-sea-blue);
}

.academic-info {
    display: flex;
    align-items: center;
    gap: 8px;
}

/* KPI Grid */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.kpi-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 20px;
    box-shadow: var(--card-shadow);
    display: flex;
    align-items: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    border: 1px solid var(--border-color);
}

.kpi-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}

.kpi-card.warning {
    border-left: 4px solid #ffc107;
}

.kpi-icon {
    font-size: 2rem;
    color: var(--primary-sea-blue);
    margin-right: 15px;
}

.kpi-card.warning .kpi-icon {
    color: #ffc107;
}

.kpi-content {
    flex: 1;
}

.kpi-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1;
}

.kpi-label {
    font-size: 0.9rem;
    color: var(--text-secondary);
    margin: 4px 0;
}

/* Section Styles */
.dashboard-section {
    margin-bottom: 30px;
}

.two-column-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 20px;
}

/* Card Styles */
.card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 25px;
    box-shadow: var(--card-shadow);
    border: 1px solid var(--border-color);
}

.card-title {
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.card-footer {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid var(--border-color);
    text-align: center;
}

.view-all {
    color: var(--primary-sea-blue);
    text-decoration: none;
    font-weight: 500;
    font-size: 0.9rem;
}

.view-all:hover {
    text-decoration: underline;
}

/* Quick Actions */
.quick-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
}

.quick-action {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 20px 15px;
    background: var(--primary-sea-blue);
    color: white;
    border-radius: 8px;
    text-decoration: none;
    transition: transform 0.2s ease, background 0.2s ease;
}

.quick-action:hover {
    background: var(--accent-soft-cyan);
    transform: scale(1.05);
}

.quick-action i {
    font-size: 1.5rem;
    margin-bottom: 10px;
}

.quick-action span {
    font-size: 0.9rem;
    text-align: center;
}

/* Live Classes */
.live-classes-list {
    max-height: 300px;
    overflow-y: auto;
}

.live-class-item {
    display: flex;
    align-items: center;
    padding: 15px 0;
    border-bottom: 1px solid var(--border-color);
}

.live-class-item:last-child {
    border-bottom: none;
}

.live-class-icon {
    font-size: 1.5rem;
    color: var(--primary-sea-blue);
    margin-right: 15px;
}

.live-class-details {
    flex: 1;
}

.live-class-details strong {
    display: block;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.live-class-details span {
    font-size: 0.9rem;
    color: var(--text-secondary);
    display: block;
}

.live-class-details small {
    font-size: 0.8rem;
    color: var(--text-muted);
}

.live-class-join {
    background: var(--primary-sea-blue);
    color: white;
    padding: 8px 15px;
    border-radius: 6px;
    text-decoration: none;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 5px;
}

.live-class-join:hover {
    background: var(--accent-soft-cyan);
}

/* Activity Tabs */
.activity-tabs {
    margin-top: 10px;
}

.tab-header {
    display: flex;
    border-bottom: 2px solid var(--border-color);
    margin-bottom: 15px;
}

.tab-button {
    padding: 10px 20px;
    background: none;
    border: none;
    color: var(--text-secondary);
    font-weight: 500;
    cursor: pointer;
    position: relative;
    transition: all 0.3s ease;
}

.tab-button.active {
    color: var(--primary-sea-blue);
}

.tab-button.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    width: 100%;
    height: 2px;
    background: var(--primary-sea-blue);
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

/* Activity List */
.activity-list {
    max-height: 300px;
    overflow-y: auto;
}

.activity-item {
    display: flex;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--border-color);
}

.activity-item:last-child {
    border-bottom: none;
}

.activity-icon {
    font-size: 1.2rem;
    color: var(--primary-sea-blue);
    margin-right: 15px;
}

.activity-details {
    flex: 1;
}

.activity-desc {
    color: var(--text-primary);
    margin-bottom: 4px;
    font-size: 0.95rem;
}

.activity-time {
    font-size: 0.8rem;
    color: var(--text-secondary);
}

/* Schedule List */
.schedule-list {
    max-height: 300px;
    overflow-y: auto;
}

.schedule-item {
    display: flex;
    align-items: center;
    padding: 15px 0;
    border-bottom: 1px solid var(--border-color);
}

.schedule-item:last-child {
    border-bottom: none;
}

.schedule-time {
    font-size: 0.9rem;
    color: var(--primary-sea-blue);
    font-weight: 600;
    min-width: 130px;
}

.schedule-details {
    flex: 1;
    margin-left: 15px;
}

.schedule-details strong {
    display: block;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.schedule-details span {
    font-size: 0.9rem;
    color: var(--text-secondary);
}

.schedule-status {
    margin-left: 15px;
}

.status-badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-badge.upcoming {
    background: rgba(23, 162, 184, 0.1);
    color: var(--primary-sea-blue);
}

/* Statistics Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    gap: 15px;
}

.stat-item {
    background: var(--bg-secondary);
    border-radius: 8px;
    padding: 15px;
    display: flex;
    align-items: center;
    gap: 10px;
    border: 1px solid var(--border-color);
}

.stat-item.published {
    border-left: 4px solid #28a745;
}

.stat-item.draft {
    border-left: 4px solid #6c757d;
}

.stat-item.overdue {
    border-left: 4px solid #dc3545;
}

.stat-item.total {
    border-left: 4px solid var(--primary-sea-blue);
}

.stat-icon {
    font-size: 1.5rem;
}

.stat-item.published .stat-icon {
    color: #28a745;
}

.stat-item.draft .stat-icon {
    color: #6c757d;
}

.stat-item.overdue .stat-icon {
    color: #dc3545;
}

.stat-item.total .stat-icon {
    color: var(--primary-sea-blue);
}

.stat-content {
    flex: 1;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-primary);
}

.stat-label {
    font-size: 0.8rem;
    color: var(--text-secondary);
}

/* Notifications List */
.notifications-list {
    max-height: 300px;
    overflow-y: auto;
}

.notification-item {
    display: flex;
    align-items: flex-start;
    padding: 15px 0;
    border-bottom: 1px solid var(--border-color);
}

.notification-item:last-child {
    border-bottom: none;
}

.notification-icon {
    font-size: 1.2rem;
    color: var(--primary-sea-blue);
    margin-right: 15px;
    margin-top: 2px;
}

.notification-item.warning .notification-icon {
    color: #ffc107;
}

.notification-item.success .notification-icon {
    color: #28a745;
}

.notification-content {
    flex: 1;
}

.notification-content strong {
    display: block;
    color: var(--text-primary);
    margin-bottom: 4px;
}

.notification-content p {
    color: var(--text-secondary);
    font-size: 0.9rem;
    margin-bottom: 4px;
    line-height: 1.4;
}

.notification-content small {
    font-size: 0.8rem;
    color: var(--text-secondary);
}

/* Classes Grid */
.classes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.class-card {
    background: var(--card-bg);
    border-radius: 10px;
    padding: 20px;
    border: 1px solid var(--border-color);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.class-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--card-shadow);
}

.class-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
}

.class-header i {
    font-size: 1.5rem;
    color: var(--primary-sea-blue);
}

.class-header h4 {
    font-size: 1.1rem;
    color: var(--text-primary);
    margin: 0;
}

.class-stats {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-bottom: 15px;
}

.class-stat {
    display: flex;
    align-items: center;
    gap: 8px;
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.class-stat i {
    color: var(--primary-sea-blue);
}

.class-actions {
    display: flex;
    gap: 10px;
}

.class-btn {
    flex: 1;
    padding: 8px 12px;
    text-align: center;
    text-decoration: none;
    border-radius: 6px;
    font-size: 0.9rem;
    transition: all 0.3s ease;
}

.class-btn.view {
    background: var(--primary-sea-blue);
    color: white;
}

.class-btn.attendance {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}

.class-btn:hover {
    opacity: 0.9;
    transform: translateY(-2px);
}

/* Empty States */
.empty-message {
    text-align: center;
    color: var(--text-muted);
    padding: 40px 20px;
    font-style: italic;
}

/* Responsive Design */
@media (max-width: 768px) {
    .kpi-grid {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }
    
    .two-column-grid {
        grid-template-columns: 1fr;
    }
    
    .quick-actions-grid {
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .classes-grid {
        grid-template-columns: 1fr;
    }
    
    .schedule-item {
        flex-wrap: wrap;
    }
    
    .schedule-time {
        min-width: 100%;
        margin-bottom: 8px;
    }
    
    .schedule-status {
        margin-left: 0;
        margin-top: 8px;
        width: 100%;
    }
}
</style>

<!-- JavaScript -->
<script>
// ============================================
// SIDEBAR & MENU FUNCTIONS (FIXED)
// ============================================

// Toggle Sidebar
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const overlay = document.getElementById('sidebarOverlay');
    
    sidebar.classList.toggle('open');
    mainContent.classList.toggle('shifted');
    
    if (overlay) {
        overlay.classList.toggle('active');
    }
}

// Toggle Submenu
function toggleSubmenu(event, element) {
    event.preventDefault();
    event.stopPropagation();
    
    const submenu = element.nextElementSibling;
    const arrow = element.querySelector('.menu-arrow');
    
    submenu.classList.toggle('open');
    arrow.classList.toggle('rotated');
}

// Toggle Theme
function toggleTheme() {
    const html = document.documentElement;
    const isDarkMode = html.classList.contains('dark-mode');
    
    if (isDarkMode) {
        html.classList.remove('dark-mode');
        html.classList.add('light-mode');
        document.cookie = "dark_mode=false; path=/; max-age=31536000";
    } else {
        html.classList.remove('light-mode');
        html.classList.add('dark-mode');
        document.cookie = "dark_mode=true; path=/; max-age=31536000";
    }
}

// Show Notifications
function showNotifications() {
    // You can implement a notifications dropdown here
    window.location.href = 'notifications.php';
}

// Tab Switching for Dashboard
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all buttons
    document.querySelectorAll('.tab-button').forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById(tabName + '-tab').classList.add('active');
    
    // Activate clicked button
    event.target.classList.add('active');
}

// ============================================
// INITIALIZATION WHEN PAGE LOADS
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // 1. Attach event listeners to header buttons
    const menuToggle = document.getElementById('menuToggle');
    const themeToggle = document.getElementById('themeToggle');
    const notificationsBtn = document.getElementById('notificationsBtn');
    const userProfile = document.getElementById('userProfile');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    
    // 2. Menu Toggle Button
    if (menuToggle) {
        menuToggle.addEventListener('click', toggleSidebar);
    }
    
    // 3. Theme Toggle Button
    if (themeToggle) {
        themeToggle.addEventListener('click', toggleTheme);
    }
    
    // 4. Notifications Button
    if (notificationsBtn) {
        notificationsBtn.addEventListener('click', showNotifications);
    }
    
    // 5. User Profile Dropdown
    if (userProfile) {
        userProfile.addEventListener('click', function() {
            // You can implement a dropdown user menu here
            alert('User menu clicked - implement dropdown functionality');
        });
    }
    
    // 6. Sidebar Overlay (close sidebar when clicking outside on mobile)
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            
            sidebar.classList.remove('open');
            mainContent.classList.remove('shifted');
            sidebarOverlay.classList.remove('active');
        });
    }
    
    // 7. Close sidebar when clicking on a link (mobile)
    const sidebarLinks = document.querySelectorAll('.sidebar .menu-link, .sidebar .submenu-link');
    sidebarLinks.forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 992) {
                const sidebar = document.getElementById('sidebar');
                const mainContent = document.getElementById('mainContent');
                const overlay = document.getElementById('sidebarOverlay');
                
                sidebar.classList.remove('open');
                mainContent.classList.remove('shifted');
                
                if (overlay) {
                    overlay.classList.remove('active');
                }
            }
        });
    });
    
    // 8. Highlight current page in sidebar
    const currentPage = window.location.pathname.split('/').pop();
    const menuLinks = document.querySelectorAll('.menu-link, .submenu-link');
    
    menuLinks.forEach(link => {
        const linkPage = link.getAttribute('href').split('/').pop();
        if (linkPage === currentPage) {
            link.classList.add('active');
            
            // Open parent submenu if applicable
            const parentSubmenu = link.closest('.submenu');
            if (parentSubmenu) {
                parentSubmenu.classList.add('open');
                const parentLink = parentSubmenu.previousElementSibling;
                if (parentLink) {
                    const arrow = parentLink.querySelector('.menu-arrow');
                    if (arrow) {
                        arrow.classList.add('rotated');
                    }
                }
            }
        }
    });
    
    // 9. Push Notifications Setup
    const enableNotificationsBtn = document.getElementById('enableNotifications');
    if (enableNotificationsBtn) {
        enableNotificationsBtn.addEventListener('click', async () => {
            if (!('Notification' in window)) {
                alert('This browser does not support notifications.');
                return;
            }
            
            const permission = await Notification.requestPermission();
            
            if (permission === 'granted') {
                alert('Notifications enabled! You will receive updates on assignments, grades, and announcements.');
                
                // Register service worker if available
                if ('serviceWorker' in navigator) {
                    try {
                        const registration = await navigator.serviceWorker.register('../../sw.js');
                        console.log('Service Worker registered:', registration);
                    } catch (error) {
                        console.error('Service Worker registration failed:', error);
                    }
                }
            } else {
                alert('Notifications permission denied. You can enable them in browser settings.');
            }
        });
    }
    
    // 10. Close sidebar with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const overlay = document.getElementById('sidebarOverlay');
            
            if (sidebar && sidebar.classList.contains('open')) {
                sidebar.classList.remove('open');
                mainContent.classList.remove('shifted');
                
                if (overlay) {
                    overlay.classList.remove('active');
                }
            }
        }
    });
    
    // 11. Handle window resize
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('mainContent');
            const overlay = document.getElementById('sidebarOverlay');
            
            // On larger screens, always show sidebar shifted
            if (window.innerWidth > 992) {
                sidebar.classList.remove('open');
                mainContent.classList.add('shifted');
                if (overlay) {
                    overlay.classList.remove('active');
                }
            } else {
                mainContent.classList.remove('shifted');
            }
        }, 250);
    });
    
    // 12. Initialize for current screen size
    if (window.innerWidth > 992) {
        const mainContent = document.getElementById('mainContent');
        mainContent.classList.add('shifted');
    }
});

// ============================================
// HELPER FUNCTIONS
// ============================================

// Debounce function for resize events
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Check if element is in viewport
function isInViewport(element) {
    const rect = element.getBoundingClientRect();
    return (
        rect.top >= 0 &&
        rect.left >= 0 &&
        rect.bottom <= (window.innerHeight || document.documentElement.clientHeight) &&
        rect.right <= (window.innerWidth || document.documentElement.clientWidth)
    );
}

// Smooth scroll to element
function smoothScrollTo(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        element.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }
}
</script>

<?php 
// Include footer
include $root_path . 'templates/teacher_footer.php';
?>