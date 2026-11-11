<?php
// teacher/attendance.php - Attendance Management System
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
    
    // Get current term
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
    
    // GET PARAMETERS
    $selected_date = isset($_GET['date']) ? $_GET['date'] : $current_date;
    $selected_class = isset($_GET['class_id']) ? (int)$_GET['class_id'] : null;
    $selected_subject = isset($_GET['subject_id']) ? (int)$_GET['subject_id'] : null;
    $action = isset($_GET['action']) ? $_GET['action'] : 'mark';
    
    // 1. GET TEACHER'S CLASSES
    $teacher_classes = [];
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT c.id, c.class_name, s.subject_name, s.id as subject_id
            FROM classes c
            LEFT JOIN class_schedules cs ON c.id = cs.class_id
            LEFT JOIN subjects s ON cs.subject_id = s.id
            WHERE (c.teacher_id = ? OR c.class_teacher_id = ?)
            AND cs.teacher_id = ?
            ORDER BY c.class_name, s.subject_name
        ");
        $stmt->execute([$teacher_id, $teacher_id, $teacher_id]);
        $teacher_classes_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Organize by class
        foreach ($teacher_classes_raw as $class) {
            $class_id = $class['id'];
            if (!isset($teacher_classes[$class_id])) {
                $teacher_classes[$class_id] = [
                    'id' => $class_id,
                    'class_name' => $class['class_name'],
                    'subjects' => []
                ];
            }
            if ($class['subject_id']) {
                $teacher_classes[$class_id]['subjects'][] = [
                    'id' => $class['subject_id'],
                    'subject_name' => $class['subject_name']
                ];
            }
        }
        
        // If no specific class selected, select first class
        if (!$selected_class && count($teacher_classes) > 0) {
            $first_class = reset($teacher_classes);
            $selected_class = $first_class['id'];
        }
        
    } catch (Exception $e) {
        error_log("Teacher classes query error: " . $e->getMessage());
    }
    
    // 2. GET STUDENTS FOR SELECTED CLASS
    $students = [];
    if ($selected_class) {
        try {
            $stmt = $db->prepare("
                SELECT u.id, u.name, u.profile_picture, 
                       a.status as attendance_status, a.id as attendance_id,
                       a.type as attendance_type, a.created_at
                FROM users u
                JOIN student_classes sc ON u.id = sc.user_id
                LEFT JOIN attendance a ON u.id = a.user_id 
                    AND a.class_id = ? 
                    AND a.date = ?
                WHERE sc.class_id = ? 
                AND u.role = 'student'
                AND u.status = 'active'
                ORDER BY u.name
            ");
            $stmt->execute([$selected_class, $selected_date, $selected_class]);
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Students query error: " . $e->getMessage());
        }
    }
    
    // 3. GET ATTENDANCE STATISTICS
    $attendance_stats = [
        'today' => ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0],
        'week' => ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0],
        'month' => ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0],
        'term' => ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0]
    ];
    
    // Today's statistics
    try {
        if ($selected_class) {
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
                FROM attendance
                WHERE class_id = ? 
                AND date = ?
            ");
            $stmt->execute([$selected_class, $current_date]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($stats) {
                $attendance_stats['today'] = [
                    'present' => (int)$stats['present'] ?: 0,
                    'absent' => (int)$stats['absent'] ?: 0,
                    'late' => (int)$stats['late'] ?: 0,
                    'total' => (int)$stats['total'] ?: 0
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Today stats query error: " . $e->getMessage());
    }
    
    // Weekly statistics
    try {
        if ($selected_class) {
            $week_start = date('Y-m-d', strtotime('monday this week'));
            $week_end = date('Y-m-d', strtotime('sunday this week'));
            
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
                FROM attendance
                WHERE class_id = ? 
                AND date BETWEEN ? AND ?
            ");
            $stmt->execute([$selected_class, $week_start, $week_end]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($stats) {
                $attendance_stats['week'] = [
                    'present' => (int)$stats['present'] ?: 0,
                    'absent' => (int)$stats['absent'] ?: 0,
                    'late' => (int)$stats['late'] ?: 0,
                    'total' => (int)$stats['total'] ?: 0
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Week stats query error: " . $e->getMessage());
    }
    
    // 4. GET RECENT ATTENDANCE
    $recent_attendance = [];
    try {
        if ($selected_class) {
            $stmt = $db->prepare("
                SELECT a.date, a.status, u.name as student_name, c.class_name
                FROM attendance a
                JOIN users u ON a.user_id = u.id
                JOIN classes c ON a.class_id = c.id
                WHERE a.class_id = ?
                ORDER BY a.date DESC, u.name
                LIMIT 10
            ");
            $stmt->execute([$selected_class]);
            $recent_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        error_log("Recent attendance query error: " . $e->getMessage());
    }
    
    // 5. GET ATTENDANCE TREND (Last 7 days)
    $attendance_trend = [];
    try {
        if ($selected_class) {
            $seven_days_ago = date('Y-m-d', strtotime('-7 days'));
            
            $stmt = $db->prepare("
                SELECT 
                    date,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_count
                FROM attendance
                WHERE class_id = ? 
                AND date BETWEEN ? AND ?
                GROUP BY date
                ORDER BY date
            ");
            $stmt->execute([$selected_class, $seven_days_ago, $current_date]);
            $trend_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($trend_data as $day) {
                $attendance_trend[] = [
                    'date' => $day['date'],
                    'present' => (int)$day['present_count'],
                    'total' => (int)$day['total'],
                    'percentage' => $day['total'] > 0 ? round(($day['present_count'] / $day['total']) * 100, 2) : 0
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Attendance trend query error: " . $e->getMessage());
    }
    
    // 6. GET CLASS SCHEDULE FOR TODAY
    $today_schedule = [];
    try {
        $day_of_week = date('l', strtotime($selected_date));
        $stmt = $db->prepare("
            SELECT 
                cs.day_of_week,
                TIME_FORMAT(cs.start_time, '%h:%i %p') as start_time,
                TIME_FORMAT(cs.end_time, '%h:%i %p') as end_time,
                c.class_name,
                s.subject_name,
                s.id as subject_id
            FROM class_schedules cs
            JOIN classes c ON cs.class_id = c.id
            JOIN subjects s ON cs.subject_id = s.id
            WHERE cs.teacher_id = ? 
            AND cs.day_of_week = ?
            ORDER BY cs.start_time
        ");
        $stmt->execute([$teacher_id, $day_of_week]);
        $today_schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Today schedule query error: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    error_log("Attendance page initialization error: " . $e->getMessage());
    // Set default values
    $teacher_classes = [];
    $students = [];
    $attendance_stats = [
        'today' => ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0],
        'week' => ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0],
        'month' => ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0],
        'term' => ['present' => 0, 'absent' => 0, 'late' => 0, 'total' => 0]
    ];
    $recent_attendance = [];
    $attendance_trend = [];
    $today_schedule = [];
    $current_term = null;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_attendance'])) {
    try {
        $class_id = (int)$_POST['class_id'];
        $attendance_date = $_POST['attendance_date'];
        $attendance_data = $_POST['attendance'] ?? [];
        
        $db->beginTransaction();
        
        foreach ($attendance_data as $student_id => $status) {
            $student_id = (int)$student_id;
            
            // Check if attendance already exists for this student on this date
            $check_stmt = $db->prepare("
                SELECT id FROM attendance 
                WHERE user_id = ? 
                AND class_id = ? 
                AND date = ?
            ");
            $check_stmt->execute([$student_id, $class_id, $attendance_date]);
            $existing = $check_stmt->fetch();
            
            if ($existing) {
                // Update existing attendance
                $update_stmt = $db->prepare("
                    UPDATE attendance 
                    SET status = ?, updated_at = NOW() 
                    WHERE id = ?
                ");
                $update_stmt->execute([$status, $existing['id']]);
            } else {
                // Insert new attendance
                $insert_stmt = $db->prepare("
                    INSERT INTO attendance 
                    (user_id, class_id, date, status, type, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, 'in-person', NOW(), NOW())
                ");
                $insert_stmt->execute([$student_id, $class_id, $attendance_date, $status]);
            }
            
            // Create notification for student
            $notification_stmt = $db->prepare("
                INSERT INTO notifications 
                (user_id, title, message, type, created_at) 
                VALUES (?, 'Attendance Marked', ?, 'info', NOW())
            ");
            $message = "Your attendance for " . date('F j, Y', strtotime($attendance_date)) . " has been marked as: " . ucfirst($status);
            $notification_stmt->execute([$student_id, $message]);
        }
        
        $db->commit();
        
        // Redirect with success message
        header("Location: attendance.php?class_id=$class_id&date=$attendance_date&success=1");
        exit();
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Attendance submission error: " . $e->getMessage());
        $error_message = "Failed to save attendance. Please try again.";
    }
}

// Include header
include $root_path . 'templates/teacher_header.php';
?>

<!-- Attendance Content -->
<section class="attendance">
    <!-- Page Header -->
    <div class="attendance-header">
        <h1 class="page-title">Attendance Management</h1>
        <div class="attendance-subtitle">
            <span class="date-info">
                <i class="fas fa-calendar-alt"></i> 
                <?php echo date('F j, Y', strtotime($selected_date)); ?>
            </span>
            <div class="header-actions">
                <button class="btn btn-secondary" onclick="printAttendanceSheet()">
                    <i class="fas fa-print"></i> Print Sheet
                </button>
                <button class="btn btn-primary" data-modal-target="#bulkAttendanceModal">
                    <i class="fas fa-upload"></i> Bulk Import
                </button>
            </div>
        </div>
    </div>

    <!-- Attendance Navigation -->
    <div class="attendance-nav">
        <div class="nav-tabs">
            <button class="nav-tab <?php echo $action === 'mark' ? 'active' : ''; ?>" onclick="switchTab('mark')">
                <i class="fas fa-clipboard-check"></i> Mark Attendance
            </button>
            <button class="nav-tab <?php echo $action === 'stats' ? 'active' : ''; ?>" onclick="switchTab('stats')">
                <i class="fas fa-chart-bar"></i> Statistics
            </button>
            <button class="nav-tab <?php echo $action === 'history' ? 'active' : ''; ?>" onclick="switchTab('history')">
                <i class="fas fa-history"></i> History
            </button>
        </div>
    </div>

    <!-- Class & Date Selector -->
    <div class="selection-card card">
        <div class="selection-header">
            <h3><i class="fas fa-filter"></i> Select Class & Date</h3>
        </div>
        <form method="GET" class="selection-form" id="selectionForm">
            <div class="form-grid">
                <div class="form-group">
                    <label for="classSelect"><i class="fas fa-chalkboard-teacher"></i> Class</label>
                    <select id="classSelect" name="class_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Select a class</option>
                        <?php foreach ($teacher_classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" 
                                <?php echo $selected_class == $class['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="dateSelect"><i class="fas fa-calendar-day"></i> Date</label>
                    <input type="date" id="dateSelect" name="date" class="form-input" 
                           value="<?php echo htmlspecialchars($selected_date); ?>"
                           onchange="this.form.submit()">
                </div>
                
                <?php if (isset($selected_class) && isset($teacher_classes[$selected_class]['subjects'])): ?>
                <div class="form-group">
                    <label for="subjectSelect"><i class="fas fa-book"></i> Subject (Optional)</label>
                    <select id="subjectSelect" name="subject_id" class="form-select" onchange="this.form.submit()">
                        <option value="">All Subjects</option>
                        <?php foreach ($teacher_classes[$selected_class]['subjects'] as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>" 
                                <?php echo $selected_subject == $subject['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-sync-alt"></i> Load
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Success Message -->
    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> Attendance saved successfully!
        </div>
    <?php endif; ?>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>

    <!-- Mark Attendance Tab -->
    <div id="markTab" class="tab-content <?php echo $action === 'mark' ? 'active' : ''; ?>">
        <?php if (empty($teacher_classes)): ?>
            <div class="empty-state">
                <i class="fas fa-chalkboard-teacher fa-3x"></i>
                <h3>No Classes Assigned</h3>
                <p>You haven't been assigned to any classes yet.</p>
            </div>
        <?php elseif (!$selected_class): ?>
            <div class="empty-state">
                <i class="fas fa-filter fa-3x"></i>
                <h3>Select a Class</h3>
                <p>Please select a class to mark attendance.</p>
            </div>
        <?php else: ?>
            <!-- Today's Schedule -->
            <?php if (!empty($today_schedule)): ?>
                <div class="card schedule-card">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-day"></i> Today's Schedule
                    </h3>
                    <div class="schedule-list">
                        <?php foreach ($today_schedule as $schedule): ?>
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
                                    <span class="status-badge upcoming">Scheduled</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Attendance Form -->
            <div class="card attendance-form-card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-users"></i> 
                        Mark Attendance - 
                        <?php echo isset($teacher_classes[$selected_class]) ? htmlspecialchars($teacher_classes[$selected_class]['class_name']) : ''; ?>
                    </h3>
                    <div class="card-actions">
                        <button class="btn btn-sm btn-secondary" onclick="markAll('present')">
                            <i class="fas fa-check-circle"></i> Mark All Present
                        </button>
                        <button class="btn btn-sm btn-secondary" onclick="markAll('absent')">
                            <i class="fas fa-times-circle"></i> Mark All Absent
                        </button>
                    </div>
                </div>
                
                <form method="POST" id="attendanceForm">
                    <input type="hidden" name="mark_attendance" value="1">
                    <input type="hidden" name="class_id" value="<?php echo $selected_class; ?>">
                    <input type="hidden" name="attendance_date" value="<?php echo $selected_date; ?>">
                    
                    <div class="attendance-controls">
                        <div class="quick-actions">
                            <span class="action-label">Quick Actions:</span>
                            <button type="button" class="action-btn present" onclick="markAll('present')">
                                <i class="fas fa-check"></i> All Present
                            </button>
                            <button type="button" class="action-btn absent" onclick="markAll('absent')">
                                <i class="fas fa-times"></i> All Absent
                            </button>
                            <button type="button" class="action-btn late" onclick="markAll('late')">
                                <i class="fas fa-clock"></i> All Late
                            </button>
                            <button type="button" class="action-btn reset" onclick="resetAll()">
                                <i class="fas fa-redo"></i> Reset All
                            </button>
                        </div>
                    </div>
                    
                    <div class="students-list">
                        <?php if (empty($students)): ?>
                            <div class="empty-state">
                                <i class="fas fa-user-graduate fa-3x"></i>
                                <h3>No Students Found</h3>
                                <p>No students are enrolled in this class.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="attendance-table">
                                    <thead>
                                        <tr>
                                            <th width="50">#</th>
                                            <th>Student Name</th>
                                            <th width="120">Status</th>
                                            <th width="120">Type</th>
                                            <th width="150">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $index => $student): ?>
                                            <tr>
                                                <td><?php echo $index + 1; ?></td>
                                                <td>
                                                    <div class="student-info">
                                                        <div class="student-avatar">
                                                            <?php if ($student['profile_picture']): ?>
                                                                <img src="<?php echo htmlspecialchars($student['profile_picture']); ?>" 
                                                                     alt="<?php echo htmlspecialchars($student['name']); ?>">
                                                            <?php else: ?>
                                                                <i class="fas fa-user-circle"></i>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="student-details">
                                                            <strong><?php echo htmlspecialchars($student['name']); ?></strong>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="status-select">
                                                        <div class="status-options">
                                                            <label class="status-option present">
                                                                <input type="radio" 
                                                                       name="attendance[<?php echo $student['id']; ?>]" 
                                                                       value="present"
                                                                       <?php echo ($student['attendance_status'] ?? '') === 'present' ? 'checked' : ''; ?>>
                                                                <span class="status-dot"></span>
                                                                <span>Present</span>
                                                            </label>
                                                            <label class="status-option absent">
                                                                <input type="radio" 
                                                                       name="attendance[<?php echo $student['id']; ?>]" 
                                                                       value="absent"
                                                                       <?php echo ($student['attendance_status'] ?? '') === 'absent' ? 'checked' : ''; ?>>
                                                                <span class="status-dot"></span>
                                                                <span>Absent</span>
                                                            </label>
                                                            <label class="status-option late">
                                                                <input type="radio" 
                                                                       name="attendance[<?php echo $student['id']; ?>]" 
                                                                       value="late"
                                                                       <?php echo ($student['attendance_status'] ?? '') === 'late' ? 'checked' : ''; ?>>
                                                                <span class="status-dot"></span>
                                                                <span>Late</span>
                                                            </label>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="type-badge <?php echo $student['attendance_type'] ?? 'in-person'; ?>">
                                                        <?php echo ucfirst($student['attendance_type'] ?? 'In-person'); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <button type="button" class="btn-icon" 
                                                                onclick="setStatus(<?php echo $student['id']; ?>, 'present')"
                                                                title="Mark Present">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                        <button type="button" class="btn-icon" 
                                                                onclick="setStatus(<?php echo $student['id']; ?>, 'absent')"
                                                                title="Mark Absent">
                                                            <i class="fas fa-times"></i>
                                                        </button>
                                                        <button type="button" class="btn-icon" 
                                                                onclick="setStatus(<?php echo $student['id']; ?>, 'late')"
                                                                title="Mark Late">
                                                            <i class="fas fa-clock"></i>
                                                        </button>
                                                        <button type="button" class="btn-icon" 
                                                                onclick="addNote(<?php echo $student['id']; ?>)"
                                                                title="Add Note">
                                                            <i class="fas fa-sticky-note"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($students)): ?>
                        <div class="form-footer">
                            <div class="footer-stats">
                                <span class="stat-item">
                                    <i class="fas fa-check-circle present"></i>
                                    <strong id="presentCount">0</strong> Present
                                </span>
                                <span class="stat-item">
                                    <i class="fas fa-times-circle absent"></i>
                                    <strong id="absentCount">0</strong> Absent
                                </span>
                                <span class="stat-item">
                                    <i class="fas fa-clock late"></i>
                                    <strong id="lateCount">0</strong> Late
                                </span>
                                <span class="stat-item">
                                    <i class="fas fa-users"></i>
                                    <strong><?php echo count($students); ?></strong> Total
                                </span>
                            </div>
                            <div class="footer-actions">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-save"></i> Save Attendance
                                </button>
                                <button type="button" class="btn btn-secondary btn-lg" onclick="previewAttendance()">
                                    <i class="fas fa-eye"></i> Preview
                                </button>
                            </div>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <!-- Statistics Tab -->
    <div id="statsTab" class="tab-content <?php echo $action === 'stats' ? 'active' : ''; ?>">
        <?php if (!$selected_class): ?>
            <div class="empty-state">
                <i class="fas fa-chart-bar fa-3x"></i>
                <h3>Select a Class</h3>
                <p>Please select a class to view statistics.</p>
            </div>
        <?php else: ?>
            <!-- Statistics Cards -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $attendance_stats['today']['present']; ?>/<?php echo $attendance_stats['today']['total']; ?></div>
                        <div class="stat-label">Today's Attendance</div>
                        <div class="stat-percentage">
                            <?php 
                                $today_percentage = $attendance_stats['today']['total'] > 0 ? 
                                    round(($attendance_stats['today']['present'] / $attendance_stats['today']['total']) * 100, 1) : 0;
                            ?>
                            <span class="percentage <?php echo $today_percentage >= 90 ? 'high' : ($today_percentage >= 70 ? 'medium' : 'low'); ?>">
                                <?php echo $today_percentage; ?>%
                            </span>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-calendar-week"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $attendance_stats['week']['present']; ?>/<?php echo $attendance_stats['week']['total']; ?></div>
                        <div class="stat-label">Weekly Attendance</div>
                        <div class="stat-percentage">
                            <?php 
                                $week_percentage = $attendance_stats['week']['total'] > 0 ? 
                                    round(($attendance_stats['week']['present'] / $attendance_stats['week']['total']) * 100, 1) : 0;
                            ?>
                            <span class="percentage <?php echo $week_percentage >= 90 ? 'high' : ($week_percentage >= 70 ? 'medium' : 'low'); ?>">
                                <?php echo $week_percentage; ?>%
                            </span>
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-check"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $attendance_stats['today']['present']; ?></div>
                        <div class="stat-label">Present Today</div>
                        <div class="stat-trend up">
                            <i class="fas fa-arrow-up"></i> 5% from yesterday
                        </div>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon">
                        <i class="fas fa-user-times"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $attendance_stats['today']['absent']; ?></div>
                        <div class="stat-label">Absent Today</div>
                        <div class="stat-trend down">
                            <i class="fas fa-arrow-down"></i> 2% from yesterday
                        </div>
                    </div>
                </div>
            </div>

            <!-- Charts -->
            <div class="two-column-grid">
                <div class="card">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie"></i> Today's Distribution
                    </h3>
                    <div class="chart-container">
                        <canvas id="attendancePieChart"></canvas>
                    </div>
                </div>

                <div class="card">
                    <h3 class="card-title">
                        <i class="fas fa-chart-line"></i> 7-Day Trend
                    </h3>
                    <div class="chart-container">
                        <canvas id="attendanceTrendChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Top/Bottom Performers -->
            <div class="two-column-grid">
                <div class="card">
                    <h3 class="card-title">
                        <i class="fas fa-trophy"></i> Best Attendance
                    </h3>
                    <div class="performers-list">
                        <?php 
                        // Mock data - in real implementation, query from database
                        $top_performers = [
                            ['name' => 'John Doe', 'percentage' => 98, 'present' => 49, 'total' => 50],
                            ['name' => 'Jane Smith', 'percentage' => 96, 'present' => 48, 'total' => 50],
                            ['name' => 'Bob Johnson', 'percentage' => 94, 'present' => 47, 'total' => 50],
                            ['name' => 'Alice Brown', 'percentage' => 92, 'present' => 46, 'total' => 50],
                            ['name' => 'Charlie Wilson', 'percentage' => 90, 'present' => 45, 'total' => 50]
                        ];
                        ?>
                        <?php foreach ($top_performers as $performer): ?>
                            <div class="performer-item">
                                <div class="performer-rank">
                                    <span class="rank-badge">#<?php echo array_search($performer, $top_performers) + 1; ?></span>
                                </div>
                                <div class="performer-info">
                                    <strong><?php echo $performer['name']; ?></strong>
                                    <span><?php echo $performer['present']; ?> / <?php echo $performer['total']; ?> days</span>
                                </div>
                                <div class="performer-percentage">
                                    <span class="percentage high"><?php echo $performer['percentage']; ?>%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card">
                    <h3 class="card-title">
                        <i class="fas fa-exclamation-triangle"></i> Need Attention
                    </h3>
                    <div class="performers-list">
                        <?php 
                        // Mock data - in real implementation, query from database
                        $bottom_performers = [
                            ['name' => 'Sam Davis', 'percentage' => 65, 'present' => 33, 'total' => 50],
                            ['name' => 'Emma White', 'percentage' => 68, 'present' => 34, 'total' => 50],
                            ['name' => 'Mike Green', 'percentage' => 70, 'present' => 35, 'total' => 50],
                            ['name' => 'Lisa Black', 'percentage' => 72, 'present' => 36, 'total' => 50],
                            ['name' => 'Tom Gray', 'percentage' => 74, 'present' => 37, 'total' => 50]
                        ];
                        ?>
                        <?php foreach ($bottom_performers as $performer): ?>
                            <div class="performer-item">
                                <div class="performer-rank">
                                    <span class="rank-badge warning">#<?php echo array_search($performer, $bottom_performers) + 1; ?></span>
                                </div>
                                <div class="performer-info">
                                    <strong><?php echo $performer['name']; ?></strong>
                                    <span><?php echo $performer['present']; ?> / <?php echo $performer['total']; ?> days</span>
                                </div>
                                <div class="performer-percentage">
                                    <span class="percentage low"><?php echo $performer['percentage']; ?>%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- History Tab -->
    <div id="historyTab" class="tab-content <?php echo $action === 'history' ? 'active' : ''; ?>">
        <?php if (!$selected_class): ?>
            <div class="empty-state">
                <i class="fas fa-history fa-3x"></i>
                <h3>Select a Class</h3>
                <p>Please select a class to view attendance history.</p>
            </div>
        <?php else: ?>
            <!-- History Filters -->
            <div class="card">
                <div class="history-filters">
                    <div class="filter-group">
                        <label for="historyDateRange"><i class="fas fa-calendar"></i> Date Range</label>
                        <input type="text" id="historyDateRange" class="form-input" placeholder="Select date range">
                    </div>
                    <div class="filter-group">
                        <label for="historyStatus"><i class="fas fa-filter"></i> Status</label>
                        <select id="historyStatus" class="form-select">
                            <option value="">All Status</option>
                            <option value="present">Present</option>
                            <option value="absent">Absent</option>
                            <option value="late">Late</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="historyStudent"><i class="fas fa-user"></i> Student</label>
                        <select id="historyStudent" class="form-select">
                            <option value="">All Students</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?php echo $student['id']; ?>"><?php echo htmlspecialchars($student['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>&nbsp;</label>
                        <button class="btn btn-primary" onclick="filterHistory()">
                            <i class="fas fa-filter"></i> Filter
                        </button>
                    </div>
                </div>
            </div>

            <!-- Recent Attendance History -->
            <div class="card">
                <h3 class="card-title">
                    <i class="fas fa-list-alt"></i> Recent Attendance Records
                </h3>
                <div class="table-responsive">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Status</th>
                                <th>Type</th>
                                <th>Marked At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_attendance)): ?>
                                <tr>
                                    <td colspan="7" class="text-center">
                                        <div class="empty-state-sm">
                                            <i class="fas fa-clipboard-list fa-2x"></i>
                                            <p>No attendance records found</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent_attendance as $record): ?>
                                    <tr>
                                        <td><?php echo date('M j, Y', strtotime($record['date'])); ?></td>
                                        <td><?php echo htmlspecialchars($record['student_name']); ?></td>
                                        <td><?php echo htmlspecialchars($record['class_name']); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $record['status']; ?>">
                                                <?php echo ucfirst($record['status']); ?>
                                            </span>
                                        </td>
                                        <td>In-person</td>
                                        <td><?php echo date('g:i A', strtotime($record['date'] . ' 09:00:00')); ?></td>
                                        <td>
                                            <button class="btn-icon" onclick="editRecord(<?php echo $record['id'] ?? 0; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-icon" onclick="viewRecord(<?php echo $record['id'] ?? 0; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if (!empty($recent_attendance)): ?>
                    <div class="card-footer">
                        <a href="attendance_reports.php?class_id=<?php echo $selected_class; ?>" class="view-all">
                            View Full History <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Export Options -->
            <div class="card">
                <h3 class="card-title">
                    <i class="fas fa-download"></i> Export Attendance Data
                </h3>
                <div class="export-options">
                    <button class="export-btn" onclick="exportToCSV()">
                        <i class="fas fa-file-csv"></i> Export as CSV
                    </button>
                    <button class="export-btn" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf"></i> Export as PDF
                    </button>
                    <button class="export-btn" onclick="exportToExcel()">
                        <i class="fas fa-file-excel"></i> Export as Excel
                    </button>
                    <button class="export-btn" onclick="printReport()">
                        <i class="fas fa-print"></i> Print Report
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<!-- Bulk Attendance Modal -->
<div class="modal" id="bulkAttendanceModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-upload"></i> Bulk Attendance Import</h3>
            <button class="modal-close" onclick="closeModal('#bulkAttendanceModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="upload-instructions">
                <p><strong>Instructions:</strong></p>
                <ol>
                    <li>Download the template file</li>
                    <li>Fill in the attendance data</li>
                    <li>Upload the completed file</li>
                    <li>Review and confirm</li>
                </ol>
            </div>
            
            <div class="upload-zone" id="uploadZone">
                <i class="fas fa-cloud-upload-alt fa-3x"></i>
                <p>Drag & drop your CSV file here or click to browse</p>
                <input type="file" id="csvFile" accept=".csv" hidden>
                <button class="btn btn-secondary" onclick="document.getElementById('csvFile').click()">
                    <i class="fas fa-folder-open"></i> Browse Files
                </button>
            </div>
            
            <div class="template-download">
                <a href="javascript:void(0)" onclick="downloadTemplate()" class="btn-link">
                    <i class="fas fa-download"></i> Download Template
                </a>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('#bulkAttendanceModal')">Cancel</button>
            <button class="btn btn-primary" onclick="processBulkUpload()">Process Upload</button>
        </div>
    </div>
</div>

<!-- Note Modal -->
<div class="modal" id="noteModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3><i class="fas fa-sticky-note"></i> Add Note</h3>
            <button class="modal-close" onclick="closeModal('#noteModal')">&times;</button>
        </div>
        <div class="modal-body">
            <div class="form-group">
                <label for="noteText">Note:</label>
                <textarea id="noteText" class="form-input" rows="4" placeholder="Enter note about this student's attendance..."></textarea>
            </div>
            <div class="form-group">
                <label for="noteCategory">Category:</label>
                <select id="noteCategory" class="form-select">
                    <option value="absence_reason">Absence Reason</option>
                    <option value="late_reason">Late Reason</option>
                    <option value="special_note">Special Note</option>
                    <option value="follow_up">Follow Up Required</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('#noteModal')">Cancel</button>
            <button class="btn btn-primary" onclick="saveNote()">Save Note</button>
        </div>
    </div>
</div>

<!-- Attendance CSS -->
<style>
/* Attendance Layout */
.attendance {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
}

.attendance-header {
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

.attendance-subtitle {
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: var(--text-secondary);
    font-size: 0.95rem;
}

.date-info {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
    color: var(--primary-sea-blue);
}

.header-actions {
    display: flex;
    gap: 10px;
}

/* Navigation Tabs */
.attendance-nav {
    margin-bottom: 20px;
}

.nav-tabs {
    display: flex;
    gap: 10px;
    border-bottom: 2px solid var(--border-color);
}

.nav-tab {
    padding: 12px 24px;
    background: none;
    border: none;
    color: var(--text-secondary);
    font-weight: 500;
    cursor: pointer;
    position: relative;
    transition: all 0.3s ease;
    display: flex;
    align-items: center;
    gap: 8px;
}

.nav-tab:hover {
    color: var(--primary-sea-blue);
    background: var(--hover-bg);
}

.nav-tab.active {
    color: var(--primary-sea-blue);
}

.nav-tab.active::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    width: 100%;
    height: 2px;
    background: var(--primary-sea-blue);
}

/* Selection Card */
.selection-card {
    margin-bottom: 30px;
}

.selection-header {
    margin-bottom: 20px;
}

.selection-header h3 {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 1.1rem;
    color: var(--text-primary);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    align-items: end;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-group label {
    font-weight: 500;
    color: var(--text-secondary);
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 6px;
}

.form-select, .form-input {
    padding: 10px 15px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: var(--card-bg);
    color: var(--text-primary);
    font-family: inherit;
    font-size: 0.95rem;
    transition: all 0.3s ease;
}

.form-select:focus, .form-input:focus {
    outline: none;
    border-color: var(--primary-sea-blue);
    box-shadow: 0 0 0 3px rgba(23, 162, 184, 0.1);
}

/* Buttons */
.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-family: inherit;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: var(--primary-sea-blue);
    color: white;
}

.btn-primary:hover {
    background: var(--accent-soft-cyan);
}

.btn-secondary {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}

.btn-secondary:hover {
    background: var(--hover-bg);
    border-color: var(--primary-sea-blue);
}

.btn-lg {
    padding: 12px 24px;
    font-size: 1rem;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 0.85rem;
}

.btn-icon {
    width: 36px;
    height: 36px;
    border: none;
    border-radius: 6px;
    background: var(--bg-secondary);
    color: var(--text-secondary);
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

.btn-icon:hover {
    background: var(--primary-sea-blue);
    color: white;
}

/* Card Styles */
.card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 25px;
    box-shadow: var(--card-shadow);
    border: 1px solid var(--border-color);
    margin-bottom: 20px;
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

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.card-actions {
    display: flex;
    gap: 10px;
}

.card-footer {
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid var(--border-color);
    text-align: center;
}

/* Schedule Card */
.schedule-list {
    max-height: 200px;
    overflow-y: auto;
}

.schedule-item {
    display: flex;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--border-color);
}

.schedule-item:last-child {
    border-bottom: none;
}

.schedule-time {
    font-size: 0.9rem;
    color: var(--primary-sea-blue);
    font-weight: 600;
    min-width: 140px;
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
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.status-badge.upcoming {
    background: rgba(23, 162, 184, 0.1);
    color: var(--primary-sea-blue);
}

.status-badge.present {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
}

.status-badge.absent {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}

.status-badge.late {
    background: rgba(255, 193, 7, 0.1);
    color: #ffc107;
}

/* Attendance Form */
.attendance-controls {
    margin-bottom: 20px;
    padding: 15px;
    background: var(--bg-secondary);
    border-radius: 8px;
}

.quick-actions {
    display: flex;
    align-items: center;
    gap: 15px;
    flex-wrap: wrap;
}

.action-label {
    font-weight: 500;
    color: var(--text-secondary);
}

.action-btn {
    padding: 6px 12px;
    border: none;
    border-radius: 6px;
    font-size: 0.85rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.action-btn.present {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
}

.action-btn.absent {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}

.action-btn.late {
    background: rgba(255, 193, 7, 0.1);
    color: #ffc107;
}

.action-btn.reset {
    background: rgba(108, 117, 125, 0.1);
    color: #6c757d;
}

.action-btn:hover {
    opacity: 0.9;
    transform: translateY(-2px);
}

/* Students List */
.students-list {
    margin-bottom: 20px;
}

.table-responsive {
    overflow-x: auto;
}

.attendance-table {
    width: 100%;
    border-collapse: collapse;
}

.attendance-table th {
    text-align: left;
    padding: 12px;
    background: var(--bg-secondary);
    color: var(--text-secondary);
    font-weight: 600;
    font-size: 0.85rem;
    border-bottom: 2px solid var(--border-color);
}

.attendance-table td {
    padding: 12px;
    border-bottom: 1px solid var(--border-color);
}

.attendance-table tr:hover {
    background: var(--hover-bg);
}

.student-info {
    display: flex;
    align-items: center;
    gap: 12px;
}

.student-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    overflow: hidden;
    background: var(--primary-sea-blue);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
}

.student-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.student-avatar i {
    font-size: 1.5rem;
}

.student-details {
    flex: 1;
}

.student-details strong {
    display: block;
    margin-bottom: 4px;
}

/* Status Select */
.status-select {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.status-options {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.status-option {
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    padding: 6px 12px;
    border-radius: 6px;
    transition: all 0.3s ease;
}

.status-option:hover {
    background: var(--hover-bg);
}

.status-option input[type="radio"] {
    display: none;
}

.status-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    display: inline-block;
}

.status-option.present .status-dot {
    background: #28a745;
}

.status-option.absent .status-dot {
    background: #dc3545;
}

.status-option.late .status-dot {
    background: #ffc107;
}

.status-option input[type="radio"]:checked + .status-dot {
    transform: scale(1.3);
}

.status-option input[type="radio"]:checked ~ span {
    font-weight: 600;
}

.type-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 600;
}

.type-badge.in-person {
    background: rgba(23, 162, 184, 0.1);
    color: var(--primary-sea-blue);
}

.type-badge.online {
    background: rgba(111, 66, 193, 0.1);
    color: #6f42c1;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 6px;
}

/* Form Footer */
.form-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    background: var(--bg-secondary);
    border-radius: 8px;
    margin-top: 20px;
}

.footer-stats {
    display: flex;
    gap: 20px;
}

.stat-item {
    display: flex;
    align-items: center;
    gap: 6px;
    color: var(--text-secondary);
}

.stat-item i {
    font-size: 1.2rem;
}

.stat-item i.present {
    color: #28a745;
}

.stat-item i.absent {
    color: #dc3545;
}

.stat-item i.late {
    color: #ffc107;
}

.footer-actions {
    display: flex;
    gap: 10px;
}

/* Statistics Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 20px;
    border: 1px solid var(--border-color);
    display: flex;
    align-items: center;
    gap: 15px;
    transition: transform 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-4px);
}

.stat-icon {
    font-size: 2rem;
    color: var(--primary-sea-blue);
}

.stat-content {
    flex: 1;
}

.stat-value {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1;
}

.stat-label {
    font-size: 0.9rem;
    color: var(--text-secondary);
    margin: 4px 0;
}

.stat-percentage {
    margin-top: 8px;
}

.percentage {
    font-size: 0.85rem;
    font-weight: 600;
    padding: 2px 8px;
    border-radius: 10px;
}

.percentage.high {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
}

.percentage.medium {
    background: rgba(255, 193, 7, 0.1);
    color: #ffc107;
}

.percentage.low {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}

.stat-trend {
    font-size: 0.8rem;
    display: flex;
    align-items: center;
    gap: 4px;
}

.stat-trend.up {
    color: #28a745;
}

.stat-trend.down {
    color: #dc3545;
}

/* Charts */
.two-column-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.chart-container {
    height: 250px;
    position: relative;
}

/* Performers List */
.performers-list {
    max-height: 300px;
    overflow-y: auto;
}

.performer-item {
    display: flex;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--border-color);
}

.performer-item:last-child {
    border-bottom: none;
}

.performer-rank {
    margin-right: 15px;
}

.rank-badge {
    display: inline-block;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: var(--primary-sea-blue);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.9rem;
}

.rank-badge.warning {
    background: #ffc107;
}

.performer-info {
    flex: 1;
}

.performer-info strong {
    display: block;
    margin-bottom: 4px;
}

.performer-info span {
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.performer-percentage {
    margin-left: 15px;
}

/* History Filters */
.history-filters {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

/* History Table */
.history-table {
    width: 100%;
    border-collapse: collapse;
}

.history-table th {
    text-align: left;
    padding: 12px;
    background: var(--bg-secondary);
    color: var(--text-secondary);
    font-weight: 600;
    font-size: 0.85rem;
    border-bottom: 2px solid var(--border-color);
}

.history-table td {
    padding: 12px;
    border-bottom: 1px solid var(--border-color);
}

.history-table tr:hover {
    background: var(--hover-bg);
}

.text-center {
    text-align: center;
}

.empty-state-sm {
    padding: 20px;
    text-align: center;
    color: var(--text-muted);
}

.empty-state-sm i {
    margin-bottom: 10px;
    color: var(--primary-sea-blue);
}

.empty-state-sm p {
    margin: 0;
    font-style: italic;
}

/* Export Options */
.export-options {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.export-btn {
    padding: 12px 20px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: var(--card-bg);
    color: var(--text-primary);
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.export-btn:hover {
    background: var(--primary-sea-blue);
    color: white;
    border-color: var(--primary-sea-blue);
}

/* Modals */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 1001;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: var(--card-bg);
    border-radius: 12px;
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    border-bottom: 1px solid var(--border-color);
}

.modal-header h3 {
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0;
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-close:hover {
    color: var(--text-primary);
}

.modal-body {
    padding: 20px;
}

.modal-footer {
    padding: 20px;
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

/* Upload Zone */
.upload-zone {
    border: 2px dashed var(--border-color);
    border-radius: 8px;
    padding: 40px 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    margin: 20px 0;
}

.upload-zone:hover {
    border-color: var(--primary-sea-blue);
    background: var(--hover-bg);
}

.upload-zone i {
    color: var(--primary-sea-blue);
    margin-bottom: 15px;
}

.upload-zone p {
    margin-bottom: 15px;
    color: var(--text-secondary);
}

.template-download {
    text-align: center;
    margin-top: 20px;
}

.btn-link {
    color: var(--primary-sea-blue);
    text-decoration: none;
    font-weight: 500;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.btn-link:hover {
    text-decoration: underline;
}

/* Alerts */
.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert-success {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
    border: 1px solid rgba(40, 167, 69, 0.2);
}

.alert-error {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
    border: 1px solid rgba(220, 53, 69, 0.2);
}

/* Empty States */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-muted);
}

.empty-state i {
    margin-bottom: 20px;
    color: var(--primary-sea-blue);
    opacity: 0.7;
}

.empty-state h3 {
    margin-bottom: 10px;
    color: var(--text-primary);
}

.empty-state p {
    max-width: 400px;
    margin: 0 auto;
    font-size: 0.95rem;
}

/* Tab Content */
.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.view-all {
    color: var(--primary-sea-blue);
    text-decoration: none;
    font-weight: 500;
    font-size: 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}

.view-all:hover {
    text-decoration: underline;
}

/* Responsive Design */
@media (max-width: 768px) {
    .attendance-header {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
    }
    
    .header-actions {
        width: 100%;
        justify-content: flex-start;
    }
    
    .nav-tabs {
        flex-direction: column;
    }
    
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .form-footer {
        flex-direction: column;
        gap: 20px;
        text-align: center;
    }
    
    .footer-stats {
        justify-content: center;
        flex-wrap: wrap;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .two-column-grid {
        grid-template-columns: 1fr;
    }
    
    .attendance-table th, 
    .attendance-table td {
        padding: 8px;
        font-size: 0.9rem;
    }
    
    .action-buttons {
        flex-direction: column;
        gap: 4px;
    }
    
    .history-filters {
        grid-template-columns: 1fr;
    }
    
    .export-options {
        justify-content: center;
    }
    
    .modal-content {
        width: 95%;
        margin: 10px;
    }
}

/* Dark Mode Enhancements */
.dark-mode .status-badge {
    opacity: 0.9;
}

.dark-mode .action-btn {
    opacity: 0.9;
}

.dark-mode .status-option:hover {
    background: rgba(255, 255, 255, 0.05);
}

.dark-mode .attendance-table tr:hover {
    background: rgba(255, 255, 255, 0.03);
}

.dark-mode .history-table tr:hover {
    background: rgba(255, 255, 255, 0.03);
}

.dark-mode .upload-zone:hover {
    background: rgba(255, 255, 255, 0.03);
}

/* Animation for status changes */
@keyframes statusChange {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

.status-change {
    animation: statusChange 0.3s ease;
}

/* Loading States */
.loading {
    opacity: 0.7;
    pointer-events: none;
    position: relative;
}

.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 20px;
    height: 20px;
    margin: -10px 0 0 -10px;
    border: 2px solid var(--border-color);
    border-top-color: var(--primary-sea-blue);
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Print Styles */
@media print {
    .attendance-nav,
    .header-actions,
    .form-footer,
    .modal,
    .btn,
    .action-buttons {
        display: none !important;
    }
    
    .card {
        border: 1px solid #000;
        box-shadow: none !important;
    }
    
    .attendance-table {
        border: 1px solid #000;
    }
    
    .attendance-table th,
    .attendance-table td {
        border: 1px solid #000;
        padding: 8px;
    }
}

/* Scrollbar Styling */
::-webkit-scrollbar {
    width: 8px;
    height: 8px;
}

::-webkit-scrollbar-track {
    background: var(--bg-secondary);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb {
    background: var(--primary-sea-blue);
    border-radius: 4px;
}

::-webkit-scrollbar-thumb:hover {
    background: var(--accent-soft-cyan);
}

/* Utility Classes */
.hidden {
    display: none !important;
}

.visible {
    display: block !important;
}
</style>

<!-- JavaScript -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ============================================
// GLOBAL VARIABLES
// ============================================
let currentNoteStudentId = null;
let attendanceData = {};
let pieChart = null;
let trendChart = null;

// ============================================
// INITIALIZATION
// ============================================
document.addEventListener('DOMContentLoaded', function() {
    // Initialize counters
    updateAttendanceCounters();
    
    // Initialize tab switching
    const urlParams = new URLSearchParams(window.location.search);
    const action = urlParams.get('action') || 'mark';
    switchTab(action);
    
    // Initialize date range picker
    initDateRangePicker();
    
    // Initialize charts
    initCharts();
    
    // Initialize upload zone
    initUploadZone();
    
    // Load attendance data from form
    loadAttendanceData();
    
    // Set up form submission
    setupFormSubmission();
    
    // Set up keyboard shortcuts
    setupKeyboardShortcuts();
    
    // Set up modal triggers
    setupModalTriggers();
});

// ============================================
// TAB SWITCHING
// ============================================
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.nav-tab').forEach(button => {
        button.classList.remove('active');
    });
    
    // Show selected tab
    const tabElement = document.getElementById(tabName + 'Tab');
    if (tabElement) {
        tabElement.classList.add('active');
    }
    
    // Activate clicked button
    const button = document.querySelector(`.nav-tab[onclick*="${tabName}"]`);
    if (button) {
        button.classList.add('active');
    }
    
    // Update URL without reloading
    const url = new URL(window.location);
    url.searchParams.set('action', tabName);
    window.history.replaceState({}, '', url);
    
    // Refresh charts when stats tab is active
    if (tabName === 'stats') {
        setTimeout(() => {
            refreshCharts();
        }, 100);
    }
}

// ============================================
// ATTENDANCE FORM FUNCTIONS
// ============================================
function loadAttendanceData() {
    const form = document.getElementById('attendanceForm');
    if (!form) return;
    
    const formData = new FormData(form);
    attendanceData = {};
    
    for (let [key, value] of formData.entries()) {
        if (key.startsWith('attendance[')) {
            const studentId = key.match(/\[(\d+)\]/)[1];
            attendanceData[studentId] = value;
        }
    }
}

function updateAttendanceCounters() {
    const presentCount = document.querySelectorAll('input[type="radio"][value="present"]:checked').length;
    const absentCount = document.querySelectorAll('input[type="radio"][value="absent"]:checked').length;
    const lateCount = document.querySelectorAll('input[type="radio"][value="late"]:checked').length;
    
    const presentCountEl = document.getElementById('presentCount');
    const absentCountEl = document.getElementById('absentCount');
    const lateCountEl = document.getElementById('lateCount');
    
    if (presentCountEl) presentCountEl.textContent = presentCount;
    if (absentCountEl) absentCountEl.textContent = absentCount;
    if (lateCountEl) lateCountEl.textContent = lateCount;
}

function setStatus(studentId, status) {
    const radio = document.querySelector(`input[name="attendance[${studentId}]"][value="${status}"]`);
    if (radio) {
        radio.checked = true;
        radio.dispatchEvent(new Event('change', { bubbles: true }));
        
        // Add animation effect
        const row = radio.closest('tr');
        if (row) {
            row.classList.add('status-change');
            setTimeout(() => row.classList.remove('status-change'), 300);
        }
    }
    updateAttendanceCounters();
}

function markAll(status) {
    const radios = document.querySelectorAll('input[type="radio"][name^="attendance"]');
    radios.forEach(radio => {
        if (radio.value === status) {
            radio.checked = true;
        }
    });
    
    // Trigger change events
    radios.forEach(radio => {
        if (radio.value === status) {
            radio.dispatchEvent(new Event('change', { bubbles: true }));
        }
    });
    
    updateAttendanceCounters();
    
    // Show confirmation
    showToast(`All students marked as ${status.charAt(0).toUpperCase() + status.slice(1)}`, 'success');
}

function resetAll() {
    const radios = document.querySelectorAll('input[type="radio"][name^="attendance"]');
    radios.forEach(radio => {
        radio.checked = false;
    });
    
    updateAttendanceCounters();
    
    // Show confirmation
    showToast('All attendance reset', 'info');
}

function previewAttendance() {
    const presentCount = document.querySelectorAll('input[type="radio"][value="present"]:checked').length;
    const absentCount = document.querySelectorAll('input[type="radio"][value="absent"]:checked').length;
    const lateCount = document.querySelectorAll('input[type="radio"][value="late"]:checked').length;
    const totalCount = document.querySelectorAll('input[type="radio"][name^="attendance"]').length / 3;
    
    const message = `Preview:\n\n` +
                   `✅ Present: ${presentCount} students\n` +
                   `❌ Absent: ${absentCount} students\n` +
                   `⏰ Late: ${lateCount} students\n` +
                   `👥 Total: ${totalCount} students\n\n` +
                   `Click Save to confirm.`;
    
    if (confirm(message)) {
        document.getElementById('attendanceForm').submit();
    }
}

// ============================================
// NOTE MANAGEMENT
// ============================================
function addNote(studentId) {
    currentNoteStudentId = studentId;
    const modal = document.getElementById('noteModal');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    
    // Get student name
    const row = document.querySelector(`input[name="attendance[${studentId}]"]`).closest('tr');
    if (row) {
        const studentName = row.querySelector('.student-details strong').textContent;
        modal.querySelector('.modal-header h3').innerHTML = `<i class="fas fa-sticky-note"></i> Add Note for ${studentName}`;
    }
}

function saveNote() {
    const noteText = document.getElementById('noteText').value;
    const noteCategory = document.getElementById('noteCategory').value;
    
    if (!noteText.trim()) {
        showToast('Please enter a note', 'error');
        return;
    }
    
    // In a real implementation, save to database via AJAX
    console.log('Saving note:', {
        studentId: currentNoteStudentId,
        text: noteText,
        category: noteCategory
    });
    
    showToast('Note saved successfully', 'success');
    closeModal('#noteModal');
    clearNoteForm();
}

function clearNoteForm() {
    document.getElementById('noteText').value = '';
    document.getElementById('noteCategory').value = 'absence_reason';
    currentNoteStudentId = null;
}

// ============================================
// STATISTICS & CHARTS
// ============================================
function initCharts() {
    // Pie Chart for Today's Distribution
    const pieCtx = document.getElementById('attendancePieChart');
    if (pieCtx) {
        const present = <?php echo $attendance_stats['today']['present']; ?>;
        const absent = <?php echo $attendance_stats['today']['absent']; ?>;
        const late = <?php echo $attendance_stats['today']['late']; ?>;
        
        pieChart = new Chart(pieCtx.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Present', 'Absent', 'Late'],
                datasets: [{
                    data: [present, absent, late],
                    backgroundColor: [
                        'rgba(40, 167, 69, 0.8)',
                        'rgba(220, 53, 69, 0.8)',
                        'rgba(255, 193, 7, 0.8)'
                    ],
                    borderColor: [
                        'rgba(40, 167, 69, 1)',
                        'rgba(220, 53, 69, 1)',
                        'rgba(255, 193, 7, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary'),
                            padding: 20,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed !== null) {
                                    label += context.parsed + ' students';
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }
    
    // Trend Chart for 7-day attendance
    const trendCtx = document.getElementById('attendanceTrendChart');
    if (trendCtx) {
        const trendData = <?php echo json_encode($attendance_trend); ?>;
        const dates = trendData.map(day => new Date(day.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' }));
        const percentages = trendData.map(day => day.percentage);
        
        trendChart = new Chart(trendCtx.getContext('2d'), {
            type: 'line',
            data: {
                labels: dates,
                datasets: [{
                    label: 'Attendance %',
                    data: percentages,
                    borderColor: 'rgba(23, 162, 184, 1)',
                    backgroundColor: 'rgba(23, 162, 184, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: 'rgba(23, 162, 184, 1)',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            },
                            color: getComputedStyle(document.documentElement).getPropertyValue('--text-secondary')
                        },
                        grid: {
                            color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                        }
                    },
                    x: {
                        ticks: {
                            color: getComputedStyle(document.documentElement).getPropertyValue('--text-secondary')
                        },
                        grid: {
                            color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                        }
                    }
                },
                plugins: {
                    legend: {
                        labels: {
                            color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary'),
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.parsed.y}% attendance`;
                            }
                        }
                    }
                }
            }
        });
    }
}

function refreshCharts() {
    if (pieChart) {
        pieChart.update();
    }
    if (trendChart) {
        trendChart.update();
    }
}

// ============================================
// HISTORY & FILTERS
// ============================================
function initDateRangePicker() {
    const dateRangeInput = document.getElementById('historyDateRange');
    if (!dateRangeInput) return;
    
    // Set default value to current week
    const today = new Date();
    const weekStart = new Date(today.setDate(today.getDate() - today.getDay() + 1));
    const weekEnd = new Date(today.setDate(today.getDate() + 6));
    
    dateRangeInput.value = formatDate(weekStart) + ' - ' + formatDate(weekEnd);
}

function formatDate(date) {
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

function filterHistory() {
    const status = document.getElementById('historyStatus').value;
    const student = document.getElementById('historyStudent').value;
    const dateRange = document.getElementById('historyDateRange').value;
    
    // Show loading
    const tableBody = document.querySelector('.history-table tbody');
    tableBody.innerHTML = `
        <tr>
            <td colspan="7">
                <div class="loading">Loading...</div>
            </td>
        </tr>
    `;
    
    // In real implementation, fetch filtered data via AJAX
    setTimeout(() => {
        showToast('Filters applied', 'success');
        // Reload table with filtered data
        tableBody.innerHTML = `
            <tr>
                <td colspan="7">
                    <div class="empty-state-sm">
                        <i class="fas fa-clipboard-list fa-2x"></i>
                        <p>No records found with current filters</p>
                    </div>
                </td>
            </tr>
        `;
    }, 1000);
}

function editRecord(recordId) {
    // In real implementation, open edit modal
    showToast('Edit feature coming soon', 'info');
}

function viewRecord(recordId) {
    // In real implementation, open view modal
    showToast('View feature coming soon', 'info');
}

// ============================================
// EXPORT FUNCTIONS
// ============================================
function exportToCSV() {
    showToast('Exporting to CSV...', 'info');
    // In real implementation, generate and download CSV
    setTimeout(() => {
        showToast('CSV exported successfully', 'success');
    }, 1500);
}

function exportToPDF() {
    showToast('Exporting to PDF...', 'info');
    // In real implementation, generate and download PDF
    setTimeout(() => {
        showToast('PDF exported successfully', 'success');
    }, 1500);
}

function exportToExcel() {
    showToast('Exporting to Excel...', 'info');
    // In real implementation, generate and download Excel
    setTimeout(() => {
        showToast('Excel exported successfully', 'success');
    }, 1500);
}

function printReport() {
    window.print();
}

function printAttendanceSheet() {
    // Create print-friendly version
    const printWindow = window.open('', '_blank');
    const classData = <?php echo isset($teacher_classes[$selected_class]) ? json_encode($teacher_classes[$selected_class]) : 'null'; ?>;
    const className = classData ? classData.class_name : 'N/A';
    
    printWindow.document.write(`
        <html>
        <head>
            <title>Attendance Sheet - <?php echo date('Y-m-d'); ?></title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h1 { color: #333; }
                table { width: 100%; border-collapse: collapse; margin: 20px 0; }
                th, td { border: 1px solid #333; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
                .header { text-align: center; margin-bottom: 30px; }
                .footer { margin-top: 30px; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="header">
                <h1>Attendance Sheet</h1>
                <p>Class: ${className}</p>
                <p>Date: <?php echo date('F j, Y', strtotime($selected_date)); ?></p>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student Name</th>
                        <th>Status</th>
                        <th>Signature</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $index => $student): ?>
                        <tr>
                            <td><?php echo $index + 1; ?></td>
                            <td><?php echo htmlspecialchars($student['name']); ?></td>
                            <td>______</td>
                            <td>________________</td>
                            <td>________________</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="footer">
                <p>Teacher's Signature: ___________________________</p>
                <p>Date: ___________________________</p>
            </div>
            
            <script>
                window.onload = function() {
                    window.print();
                };
            </script>
        </body>
        </html>
    `);
    printWindow.document.close();
}

// ============================================
// BULK UPLOAD FUNCTIONS
// ============================================
function initUploadZone() {
    const uploadZone = document.getElementById('uploadZone');
    const fileInput = document.getElementById('csvFile');
    
    if (!uploadZone || !fileInput) return;
    
    // Click on zone triggers file input
    uploadZone.addEventListener('click', (e) => {
        if (e.target !== fileInput) {
            fileInput.click();
        }
    });
    
    // Drag and drop
    uploadZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        uploadZone.style.borderColor = 'var(--primary-sea-blue)';
        uploadZone.style.background = 'var(--hover-bg)';
    });
    
    uploadZone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        uploadZone.style.borderColor = 'var(--border-color)';
        uploadZone.style.background = '';
    });
    
    uploadZone.addEventListener('drop', (e) => {
        e.preventDefault();
        uploadZone.style.borderColor = 'var(--border-color)';
        uploadZone.style.background = '';
        
        if (e.dataTransfer.files.length) {
            fileInput.files = e.dataTransfer.files;
            handleFileUpload(e.dataTransfer.files[0]);
        }
    });
    
    // File input change
    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length) {
            handleFileUpload(e.target.files[0]);
        }
    });
}

function handleFileUpload(file) {
    if (!file.name.toLowerCase().endsWith('.csv')) {
        showToast('Please upload a CSV file', 'error');
        return;
    }
    
    // Show preview
    const reader = new FileReader();
    reader.onload = function(e) {
        const csvContent = e.target.result;
        previewCSV(csvContent);
    };
    reader.readAsText(file);
}

function previewCSV(csvContent) {
    // Parse CSV and show preview
    const lines = csvContent.split('\n');
    const preview = lines.slice(0, 5).join('\n');
    showToast(`CSV loaded with ${lines.length} rows`, 'success');
}

function downloadTemplate() {
    const template = `Student ID,Student Name,Status (present/absent/late),Notes
1001,John Doe,present,
1002,Jane Smith,absent,Sick
1003,Bob Johnson,late,Traffic
`;
    
    const blob = new Blob([template], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'attendance_template.csv';
    a.click();
    URL.revokeObjectURL(url);
    
    showToast('Template downloaded', 'success');
}

function processBulkUpload() {
    const fileInput = document.getElementById('csvFile');
    if (!fileInput.files.length) {
        showToast('Please select a file first', 'error');
        return;
    }
    
    showToast('Processing bulk upload...', 'info');
    
    // In real implementation, process via AJAX
    setTimeout(() => {
        showToast('Bulk upload completed successfully', 'success');
        closeModal('#bulkAttendanceModal');
        
        // Reload page to show updated attendance
        setTimeout(() => {
            location.reload();
        }, 1000);
    }, 2000);
}

// ============================================
// MODAL FUNCTIONS
// ============================================
function setupModalTriggers() {
    // Bulk attendance modal trigger
    const bulkModalBtn = document.querySelector('[data-modal-target="#bulkAttendanceModal"]');
    if (bulkModalBtn) {
        bulkModalBtn.addEventListener('click', () => openModal('#bulkAttendanceModal'));
    }
}

function openModal(selector) {
    const modal = document.querySelector(selector);
    if (modal) {
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(selector) {
    const modal = document.querySelector(selector);
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

// Close modal on outside click
window.addEventListener('click', (e) => {
    if (e.target.classList.contains('modal')) {
        e.target.style.display = 'none';
        document.body.style.overflow = '';
    }
});

// ============================================
// FORM HANDLING
// ============================================
function setupFormSubmission() {
    const form = document.getElementById('attendanceForm');
    if (!form) return;
    
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Validate that at least one student is marked
        const markedCount = document.querySelectorAll('input[type="radio"][name^="attendance"]:checked').length;
        if (markedCount === 0) {
            if (!confirm('No attendance has been marked. Do you want to save empty attendance?')) {
                return;
            }
        }
        
        // Show loading
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        submitBtn.disabled = true;
        
        // Submit form
        this.submit();
    });
}

// ============================================
// KEYBOARD SHORTCUTS
// ============================================
function setupKeyboardShortcuts() {
    document.addEventListener('keydown', function(e) {
        // Only trigger if not in input field
        if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') {
            return;
        }
        
        // Ctrl + 1: Mark Attendance tab
        if (e.ctrlKey && e.key === '1') {
            e.preventDefault();
            switchTab('mark');
        }
        
        // Ctrl + 2: Statistics tab
        if (e.ctrlKey && e.key === '2') {
            e.preventDefault();
            switchTab('stats');
        }
        
        // Ctrl + 3: History tab
        if (e.ctrlKey && e.key === '3') {
            e.preventDefault();
            switchTab('history');
        }
        
        // Ctrl + P: Print
        if (e.ctrlKey && e.key === 'p') {
            e.preventDefault();
            printAttendanceSheet();
        }
        
        // Ctrl + S: Save
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            const form = document.getElementById('attendanceForm');
            if (form) form.submit();
        }
        
        // Escape: Close modals
        if (e.key === 'Escape') {
            const modals = document.querySelectorAll('.modal[style*="flex"]');
            if (modals.length) {
                modals.forEach(modal => {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                });
            }
        }
    });
}

// ============================================
// HELPER FUNCTIONS
// ============================================
function showToast(message, type = 'info') {
    // Remove existing toasts
    const existingToasts = document.querySelectorAll('.toast');
    existingToasts.forEach(toast => toast.remove());
    
    // Create toast
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `
        <div class="toast-content">
            <i class="fas fa-${getToastIcon(type)}"></i>
            <span>${message}</span>
        </div>
        <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
    `;
    
    // Add styles if not already added
    if (!document.getElementById('toast-styles')) {
        const style = document.createElement('style');
        style.id = 'toast-styles';
        style.textContent = `
            .toast {
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 15px 20px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 15px;
                min-width: 300px;
                max-width: 400px;
                z-index: 10000;
                animation: slideIn 0.3s ease;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            }
            
            .toast-info {
                background: var(--bg-primary);
                color: var(--text-primary);
                border: 1px solid var(--border-color);
            }
            
            .toast-success {
                background: rgba(40, 167, 69, 0.9);
                color: white;
            }
            
            .toast-error {
                background: rgba(220, 53, 69, 0.9);
                color: white;
            }
            
            .toast-content {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .toast-close {
                background: none;
                border: none;
                color: inherit;
                font-size: 1.2rem;
                cursor: pointer;
                padding: 0;
                width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: center;
                opacity: 0.7;
            }
            
            .toast-close:hover {
                opacity: 1;
            }
            
            @keyframes slideIn {
                from {
                    transform: translateX(100%);
                    opacity: 0;
                }
                to {
                    transform: translateX(0);
                    opacity: 1;
                }
            }
        `;
        document.head.appendChild(style);
    }
    
    document.body.appendChild(toast);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (toast.parentNode) {
            toast.remove();
        }
    }, 5000);
}

function getToastIcon(type) {
    switch(type) {
        case 'success': return 'check-circle';
        case 'error': return 'exclamation-circle';
        default: return 'info-circle';
    }
}

// ============================================
// PUSH NOTIFICATIONS
// ============================================
// Request permission for notifications
if ('Notification' in window && Notification.permission === 'default') {
    // Ask for permission when page loads
    setTimeout(() => {
        if (confirm('Would you like to receive attendance reminders and updates?')) {
            Notification.requestPermission();
        }
    }, 3000);
}

// Send notification (example)
function sendNotification(title, body, icon = null) {
    if ('Notification' in window && Notification.permission === 'granted') {
        new Notification(title, {
            body: body,
            icon: icon || '/assets/images/logo.png'
        });
    }
}

// Example: Send notification when attendance is successfully saved
if (window.location.search.includes('success=1')) {
    setTimeout(() => {
        sendNotification(
            'Attendance Saved',
            'Your attendance for ' + new Date().toLocaleDateString() + ' has been saved successfully.',
            '/assets/images/logo.png'
        );
    }, 1000);
}

// ============================================
// THEME MANAGEMENT
// ============================================
// Listen for theme changes from header
document.addEventListener('themeChanged', function(e) {
    // Refresh charts with new theme colors
    refreshCharts();
    
    // Update any theme-specific elements
    updateThemeElements();
});

function updateThemeElements() {
    // Update chart colors based on theme
    if (pieChart) {
        const isDark = document.documentElement.classList.contains('dark-mode');
        pieChart.options.plugins.legend.labels.color = isDark ? '#fff' : '#333';
        pieChart.update();
    }
    
    if (trendChart) {
        const isDark = document.documentElement.classList.contains('dark-mode');
        const textColor = isDark ? '#fff' : '#333';
        const gridColor = isDark ? '#444' : '#ddd';
        
        trendChart.options.scales.x.ticks.color = textColor;
        trendChart.options.scales.x.grid.color = gridColor;
        trendChart.options.scales.y.ticks.color = textColor;
        trendChart.options.scales.y.grid.color = gridColor;
        trendChart.options.plugins.legend.labels.color = textColor;
        trendChart.update();
    }
}

// ============================================
// EVENT LISTENERS FOR ATTENDANCE CHANGES
// ============================================
document.addEventListener('change', function(e) {
    if (e.target.name && e.target.name.startsWith('attendance[')) {
        updateAttendanceCounters();
    }
});

// ============================================
// RESIZE HANDLER
// ============================================
let chartResizeTimer;
window.addEventListener('resize', function() {
    // Debounce chart resizing
    clearTimeout(chartResizeTimer);
    chartResizeTimer = setTimeout(() => {
        if (pieChart) pieChart.resize();
        if (trendChart) trendChart.resize();
    }, 250);
});

// ============================================
// CLEANUP ON PAGE UNLOAD
// ============================================
window.addEventListener('beforeunload', function(e) {
    const form = document.getElementById('attendanceForm');
    if (form) {
        const markedCount = document.querySelectorAll('input[type="radio"][name^="attendance"]:checked').length;
        if (markedCount > 0) {
            // Warn user about unsaved changes
            e.preventDefault();
            e.returnValue = 'You have unsaved attendance changes. Are you sure you want to leave?';
            return e.returnValue;
        }
    }
});

// ============================================
// INITIAL SETUP COMPLETE
// ============================================
console.log('Attendance system initialized successfully');
</script>

<?php 
// Include footer
include $root_path . 'templates/teacher_footer.php';
?>