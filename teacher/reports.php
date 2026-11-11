<?php
// teacher/behavior_reports.php - Enhanced Behavior Reporting System
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not authenticated as teacher
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    header("Location: ../../login.php");
    exit();
}

$root_path = dirname(dirname(__FILE__)) . '/';
require_once $root_path . 'config/db.php';

// Initialize variables
$success_message = '';
$error_message = '';
$reports = [];
$students = [];
$classes = [];
$report_details = null;
$stats = [
    'total_reports' => 0,
    'today_reports' => 0,
    'parent_notified' => 0,
    'admin_notified' => 0,
    'critical_reports' => 0
];
$chart_data = [
    'labels' => ['Low', 'Medium', 'High', 'Critical'],
    'values' => [0, 0, 0, 0],
    'common_type' => 'N/A',
    'avg_severity' => 'N/A'
];

try {
    $db = db();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
    $teacher_id = $_SESSION['user_id'];
    
    // Handle AJAX actions
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        
        switch ($_GET['action']) {
            case 'get_students':
                $class_id = filter_input(INPUT_GET, 'class_id', FILTER_VALIDATE_INT);
                if ($class_id) {
                    $stmt = $db->prepare("
                        SELECT u.id, u.name 
                        FROM users u
                        JOIN student_classes sc ON u.id = sc.user_id
                        WHERE sc.class_id = ? AND u.role = 'student'
                        ORDER BY u.name
                    ");
                    $stmt->execute([$class_id]);
                    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
                } else {
                    echo json_encode([]);
                }
                exit();
                
            case 'get_report':
                $report_id = filter_input(INPUT_GET, 'report_id', FILTER_VALIDATE_INT);
                if ($report_id) {
                    $stmt = $db->prepare("
                        SELECT 
                            br.*,
                            u.name as student_name,
                            c.class_name,
                            t.name as teacher_name
                        FROM behavior_reports br
                        JOIN users u ON br.student_id = u.id
                        JOIN classes c ON br.class_id = c.id
                        JOIN users t ON br.teacher_id = t.id
                        WHERE br.id = ? AND br.teacher_id = ?
                    ");
                    $stmt->execute([$report_id, $teacher_id]);
                    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
                }
                exit();
                
            case 'get_stats':
                $stmt = $db->prepare("
                    SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today,
                        SUM(CASE WHEN parent_notified = 1 THEN 1 ELSE 0 END) as parent_notified,
                        SUM(CASE WHEN admin_notified = 1 THEN 1 ELSE 0 END) as admin_notified,
                        SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical,
                        behavior_type,
                        severity,
                        COUNT(*) as count
                    FROM behavior_reports 
                    WHERE teacher_id = ?
                    AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    GROUP BY behavior_type, severity
                ");
                $stmt->execute([$teacher_id]);
                $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Process for chart
                $severity_counts = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
                $type_counts = [];
                
                foreach ($data as $row) {
                    $severity_counts[$row['severity']] += $row['count'];
                    $type_counts[$row['behavior_type']] = ($type_counts[$row['behavior_type']] ?? 0) + $row['count'];
                }
                
                $chart_data['values'] = [
                    $severity_counts['low'],
                    $severity_counts['medium'],
                    $severity_counts['high'],
                    $severity_counts['critical']
                ];
                
                if (!empty($type_counts)) {
                    arsort($type_counts);
                    $chart_data['common_type'] = ucfirst(key($type_counts));
                }
                
                echo json_encode($chart_data);
                exit();
                
            case 'export':
                $stmt = $db->prepare("
                    SELECT 
                        br.report_date,
                        u.name as student_name,
                        c.class_name,
                        br.behavior_type,
                        br.severity,
                        br.description,
                        br.action_taken,
                        br.follow_up,
                        br.parent_notified,
                        br.admin_notified,
                        br.status,
                        br.created_at
                    FROM behavior_reports br
                    JOIN users u ON br.student_id = u.id
                    JOIN classes c ON br.class_id = c.id
                    WHERE br.teacher_id = ?
                    AND br.report_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
                    ORDER BY br.report_date DESC
                ");
                $stmt->execute([$teacher_id]);
                $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="behavior_reports_' . date('Y-m-d') . '.csv"');
                
                $output = fopen('php://output', 'w');
                fputcsv($output, [
                    'Report Date', 'Student', 'Class', 'Behavior Type', 
                    'Severity', 'Description', 'Action Taken', 'Follow Up',
                    'Parent Notified', 'Admin Notified', 'Status', 'Created At'
                ]);
                
                foreach ($reports as $report) {
                    fputcsv($output, $report);
                }
                
                fclose($output);
                exit();
        }
    }
    
    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF Protection
        require_once $root_path . 'functions/csrf.php';
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            $error_message = "Security token mismatch. Please try again.";
        } else {
            $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
            $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
            $report_date = filter_input(INPUT_POST, 'report_date');
            $behavior_type = filter_input(INPUT_POST, 'behavior_type', FILTER_SANITIZE_STRING);
            $severity = filter_input(INPUT_POST, 'severity', FILTER_SANITIZE_STRING);
            $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
            $action_taken = filter_input(INPUT_POST, 'action_taken', FILTER_SANITIZE_STRING);
            $follow_up = filter_input(INPUT_POST, 'follow_up', FILTER_SANITIZE_STRING);
            $parent_notified = isset($_POST['parent_notified']) ? 1 : 0;
            $admin_notified = isset($_POST['admin_notified']) ? 1 : 0;
            $is_private = isset($_POST['is_private']) ? 1 : 0;
            
            // Validate required fields
            if (!$student_id || !$class_id || !$report_date || !$behavior_type || !$description) {
                $error_message = "Please fill in all required fields.";
            } elseif (strlen($description) < 10) {
                $error_message = "Description must be at least 10 characters.";
            } else {
                // Insert behavior report
                $stmt = $db->prepare("
                    INSERT INTO behavior_reports (
                        student_id, class_id, teacher_id, report_date, 
                        behavior_type, severity, description, action_taken, 
                        follow_up, parent_notified, admin_notified, is_private, 
                        status, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW(), NOW())
                ");
                
                if ($stmt->execute([
                    $student_id, $class_id, $teacher_id, $report_date,
                    $behavior_type, $severity, $description, $action_taken,
                    $follow_up, $parent_notified, $admin_notified, $is_private
                ])) {
                    $report_id = $db->lastInsertId();
                    $success_message = "Behavior report submitted successfully.";
                    
                    // Create notifications for admin and parents if requested
                    if ($admin_notified) {
                        $notif_stmt = $db->prepare("
                            INSERT INTO notifications (target_role, title, message, type, link, created_at)
                            VALUES ('admin', 'New Behavior Report', 
                                    'Teacher has submitted a behavior report for a student.', 
                                    'warning', 'admin/behavior_reports.php?id=$report_id', NOW())
                        ");
                        $notif_stmt->execute();
                    }
                    
                    if ($parent_notified) {
                        // Get parent ID
                        $parent_stmt = $db->prepare("
                            SELECT p.parent_id 
                            FROM parent_student_mapping p 
                            WHERE p.student_id = ?
                        ");
                        $parent_stmt->execute([$student_id]);
                        $parent = $parent_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($parent) {
                            $parent_notif_stmt = $db->prepare("
                                INSERT INTO notifications (user_id, title, message, type, link, created_at)
                                VALUES (?, 'Behavior Report Update', 
                                        'Teacher has submitted a behavior report about your child.', 
                                        'warning', 'parent/behavior_reports.php?id=$report_id', NOW())
                            ");
                            $parent_notif_stmt->execute([$parent['parent_id']]);
                        }
                    }
                    
                    // Send push notification if enabled
                    $this->sendPushNotification($teacher_id, $student_id, $behavior_type, $severity);
                    
                } else {
                    $error_message = "Failed to submit report. Please try again.";
                }
            }
        }
    }
    
    // Generate CSRF token
    require_once $root_path . 'functions/csrf.php';
    $csrf_token = generate_csrf_token();
    
    // Get teacher's classes
    $class_stmt = $db->prepare("
        SELECT DISTINCT c.id, c.class_name 
        FROM classes c
        WHERE c.teacher_id = ? OR c.class_teacher_id = ?
        ORDER BY c.class_name
    ");
    $class_stmt->execute([$teacher_id, $teacher_id]);
    $classes = $class_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent behavior reports (last 30 days)
    $report_stmt = $db->prepare("
        SELECT 
            br.id, br.report_date, br.behavior_type, br.severity,
            br.description, br.status, br.created_at,
            br.parent_notified, br.admin_notified, br.is_private,
            u.name as student_name,
            c.class_name,
            CONCAT(LEFT(br.description, 100), '...') as preview
        FROM behavior_reports br
        JOIN users u ON br.student_id = u.id
        JOIN classes c ON br.class_id = c.id
        WHERE br.teacher_id = ?
        AND br.report_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        ORDER BY br.report_date DESC, br.created_at DESC
        LIMIT 50
    ");
    $report_stmt->execute([$teacher_id]);
    $reports = $report_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get statistics
    $stats_stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_reports,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) as today_reports,
            SUM(CASE WHEN parent_notified = 1 THEN 1 ELSE 0 END) as parent_notified,
            SUM(CASE WHEN admin_notified = 1 THEN 1 ELSE 0 END) as admin_notified,
            SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical_reports
        FROM behavior_reports 
        WHERE teacher_id = ?
    ");
    $stats_stmt->execute([$teacher_id]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get chart data
    $chart_stmt = $db->prepare("
        SELECT 
            behavior_type,
            severity,
            COUNT(*) as count
        FROM behavior_reports 
        WHERE teacher_id = ?
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY behavior_type, severity
    ");
    $chart_stmt->execute([$teacher_id]);
    $chart_raw = $chart_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process chart data
    $severity_counts = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];
    $type_counts = [];
    
    foreach ($chart_raw as $row) {
        $severity_counts[$row['severity']] += $row['count'];
        $type_counts[$row['behavior_type']] = ($type_counts[$row['behavior_type']] ?? 0) + $row['count'];
    }
    
    $chart_data['values'] = [
        $severity_counts['low'],
        $severity_counts['medium'],
        $severity_counts['high'],
        $severity_counts['critical']
    ];
    
    if (!empty($type_counts)) {
        arsort($type_counts);
        $chart_data['common_type'] = ucfirst(key($type_counts));
        
        // Calculate average severity score
        $total_score = 0;
        $total_reports = 0;
        $severity_scores = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];
        
        foreach ($severity_counts as $severity => $count) {
            $total_score += $severity_scores[$severity] * $count;
            $total_reports += $count;
        }
        
        if ($total_reports > 0) {
            $avg_score = $total_score / $total_reports;
            $chart_data['avg_severity'] = $avg_score < 1.5 ? 'Low' : 
                                        ($avg_score < 2.5 ? 'Medium' : 
                                        ($avg_score < 3.5 ? 'High' : 'Critical'));
        }
    }
    
    // View specific report if requested
    if (isset($_GET['view']) && is_numeric($_GET['view'])) {
        $view_stmt = $db->prepare("
            SELECT 
                br.*,
                u.name as student_name,
                c.class_name,
                t.name as teacher_name
            FROM behavior_reports br
            JOIN users u ON br.student_id = u.id
            JOIN classes c ON br.class_id = c.id
            JOIN users t ON br.teacher_id = t.id
            WHERE br.id = ? AND br.teacher_id = ?
        ");
        $view_stmt->execute([$_GET['view'], $teacher_id]);
        $report_details = $view_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
} catch (Exception $e) {
    error_log("Behavior reports error: " . $e->getMessage());
    $error_message = "An error occurred while loading the page.";
}

// Include header
include $root_path . 'templates/teacher_header.php';
?>

<!-- Main Content -->
<div class="behavior-reports-container">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-clipboard-list"></i> Behavior Reports
        </h1>
        <p class="page-subtitle">Track and report student behavior incidents</p>
    </div>
    
    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-file-alt"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['total_reports'] ?? 0; ?></div>
                <div class="stat-label">Total Reports</div>
            </div>
        </div>
        
        <div class="stat-card warning">
            <div class="stat-icon">
                <i class="fas fa-calendar-day"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['today_reports'] ?? 0; ?></div>
                <div class="stat-label">Today</div>
            </div>
        </div>
        
        <div class="stat-card info">
            <div class="stat-icon">
                <i class="fas fa-bell"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['parent_notified'] ?? 0; ?></div>
                <div class="stat-label">Parent Notifications</div>
            </div>
        </div>
        
        <div class="stat-card danger">
            <div class="stat-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $stats['critical_reports'] ?? 0; ?></div>
                <div class="stat-label">Critical Incidents</div>
            </div>
        </div>
    </div>
    
    <!-- Main Content Grid -->
    <div class="content-grid">
        <!-- Left Column: New Report Form -->
        <div class="form-column">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-plus-circle"></i> Submit New Behavior Report
                    </h3>
                </div>
                <div class="card-body">
                    <?php if ($success_message): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($error_message): ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form id="behaviorReportForm" method="POST" class="report-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="class_id" class="required">
                                    <i class="fas fa-chalkboard"></i> Class
                                </label>
                                <select id="class_id" name="class_id" class="form-control" required 
                                        onchange="loadStudents(this.value)">
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>">
                                            <?php echo htmlspecialchars($class['class_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="student_id" class="required">
                                    <i class="fas fa-user-graduate"></i> Student
                                </label>
                                <select id="student_id" name="student_id" class="form-control" required>
                                    <option value="">Select Class First</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="report_date" class="required">
                                    <i class="fas fa-calendar"></i> Report Date
                                </label>
                                <input type="date" id="report_date" name="report_date" 
                                       class="form-control" 
                                       value="<?php echo date('Y-m-d'); ?>" 
                                       max="<?php echo date('Y-m-d'); ?>" 
                                       required>
                            </div>
                            
                            <div class="form-group">
                                <label for="behavior_type" class="required">
                                    <i class="fas fa-tag"></i> Behavior Type
                                </label>
                                <select id="behavior_type" name="behavior_type" class="form-control" required>
                                    <option value="">Select Type</option>
                                    <option value="positive">Positive Behavior</option>
                                    <option value="disruptive">Disruptive Behavior</option>
                                    <option value="bullying">Bullying</option>
                                    <option value="academic">Academic Concern</option>
                                    <option value="attendance">Attendance Issue</option>
                                    <option value="social">Social/Emotional</option>
                                    <option value="safety">Safety Concern</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label for="severity" class="required">
                                    <i class="fas fa-exclamation-circle"></i> Severity Level
                                </label>
                                <select id="severity" name="severity" class="form-control" required>
                                    <option value="low">Low - Minor Issue</option>
                                    <option value="medium" selected>Medium - Concerning</option>
                                    <option value="high">High - Serious Concern</option>
                                    <option value="critical">Critical - Immediate Action Needed</option>
                                </select>
                                <div class="severity-indicator">
                                    <div class="severity-dot low"></div>
                                    <div class="severity-dot medium active"></div>
                                    <div class="severity-dot high"></div>
                                    <div class="severity-dot critical"></div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description" class="required">
                                <i class="fas fa-align-left"></i> Incident Description
                            </label>
                            <textarea id="description" name="description" 
                                      class="form-control" rows="4" 
                                      placeholder="Describe the behavior incident in detail..." 
                                      required></textarea>
                            <div class="char-count">
                                <span id="charCount">0</span> / 1000 characters
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="action_taken">
                                <i class="fas fa-hands-helping"></i> Action Taken
                            </label>
                            <textarea id="action_taken" name="action_taken" 
                                      class="form-control" rows="3" 
                                      placeholder="What immediate action was taken?"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="follow_up">
                                <i class="fas fa-forward"></i> Follow-up Required
                            </label>
                            <textarea id="follow_up" name="follow_up" 
                                      class="form-control" rows="3" 
                                      placeholder="What follow-up actions are needed?"></textarea>
                        </div>
                        
                        <div class="form-row checkboxes">
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="parent_notified" value="1" checked>
                                    <span class="checkmark"></span>
                                    <i class="fas fa-user-friends"></i> Notify Parent/Guardian
                                </label>
                                <small class="checkbox-help">Send notification to parent about this report</small>
                            </div>
                            
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="admin_notified" value="1">
                                    <span class="checkmark"></span>
                                    <i class="fas fa-user-shield"></i> Notify Administration
                                </label>
                                <small class="checkbox-help">Alert school administrators</small>
                            </div>
                            
                            <div class="checkbox-group">
                                <label class="checkbox-label">
                                    <input type="checkbox" name="is_private" value="1">
                                    <span class="checkmark"></span>
                                    <i class="fas fa-lock"></i> Keep Private
                                </label>
                                <small class="checkbox-help">Restrict visibility to teachers/admins only</small>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-paper-plane"></i> Submit Report
                            </button>
                            <button type="reset" class="btn btn-secondary" onclick="clearForm()">
                                <i class="fas fa-redo"></i> Clear Form
                            </button>
                            <button type="button" class="btn btn-outline" onclick="saveDraft()">
                                <i class="fas fa-save"></i> Save Draft
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Quick Templates -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-sticky-note"></i> Quick Templates
                    </h3>
                </div>
                <div class="card-body">
                    <div class="templates-grid">
                        <button class="template-btn" onclick="applyTemplate('positive')">
                            <i class="fas fa-thumbs-up"></i>
                            <span>Positive Behavior</span>
                        </button>
                        <button class="template-btn" onclick="applyTemplate('attendance')">
                            <i class="fas fa-clock"></i>
                            <span>Attendance Issue</span>
                        </button>
                        <button class="template-btn" onclick="applyTemplate('homework')">
                            <i class="fas fa-book"></i>
                            <span>Homework Concern</span>
                        </button>
                        <button class="template-btn" onclick="applyTemplate('bullying')">
                            <i class="fas fa-ban"></i>
                            <span>Bullying Report</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Right Column: Recent Reports & Stats -->
        <div class="list-column">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-history"></i> Recent Reports
                        <span class="badge"><?php echo count($reports); ?></span>
                    </h3>
                    <div class="card-actions">
                        <div class="search-box">
                            <input type="text" id="searchReports" placeholder="Search reports..." 
                                   onkeyup="searchReports(this.value)">
                            <i class="fas fa-search"></i>
                        </div>
                        <button onclick="exportReports()" class="btn-icon" title="Export CSV">
                            <i class="fas fa-download"></i>
                        </button>
                        <button onclick="refreshReports()" class="btn-icon" title="Refresh">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($reports)): ?>
                        <div class="empty-state">
                            <i class="fas fa-clipboard"></i>
                            <h4>No Reports Yet</h4>
                            <p>Submit your first behavior report using the form.</p>
                            <button class="btn btn-primary" onclick="document.getElementById('class_id').focus()">
                                <i class="fas fa-plus"></i> Create First Report
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="reports-list" id="reportsList">
                            <?php foreach ($reports as $report): ?>
                                <div class="report-item severity-<?php echo strtolower($report['severity'] ?? 'medium'); ?>" 
                                     data-search="<?php echo htmlspecialchars(strtolower($report['student_name'] . ' ' . $report['class_name'] . ' ' . $report['behavior_type'] . ' ' . $report['description'])); ?>">
                                    <div class="report-header">
                                        <div class="report-meta">
                                            <span class="report-date">
                                                <i class="fas fa-calendar"></i>
                                                <?php echo date('M d, Y', strtotime($report['report_date'])); ?>
                                            </span>
                                            <span class="report-severity badge severity-<?php echo strtolower($report['severity']); ?>">
                                                <?php echo ucfirst($report['severity']); ?>
                                            </span>
                                            <?php if ($report['parent_notified']): ?>
                                                <span class="notification-badge" title="Parent Notified">
                                                    <i class="fas fa-user-friends"></i>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($report['admin_notified']): ?>
                                                <span class="notification-badge admin" title="Admin Notified">
                                                    <i class="fas fa-user-shield"></i>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ($report['is_private']): ?>
                                                <span class="notification-badge private" title="Private Report">
                                                    <i class="fas fa-lock"></i>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="report-actions">
                                            <button class="btn-icon view-report" 
                                                    onclick="viewReport(<?php echo $report['id']; ?>)" 
                                                    title="View Details">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="btn-icon edit-report" 
                                                    onclick="editReport(<?php echo $report['id']; ?>)" 
                                                    title="Edit Report">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                        </div>
                                    </div>
                                    
                                    <div class="report-content">
                                        <div class="report-student">
                                            <i class="fas fa-user-graduate"></i>
                                            <strong><?php echo htmlspecialchars($report['student_name']); ?></strong>
                                            <span class="report-class">
                                                (<?php echo htmlspecialchars($report['class_name']); ?>)
                                            </span>
                                        </div>
                                        
                                        <div class="report-type">
                                            <i class="fas fa-tag"></i>
                                            <span class="type-badge type-<?php echo strtolower($report['behavior_type']); ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $report['behavior_type'])); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="report-preview">
                                            <?php echo htmlspecialchars($report['preview']); ?>
                                        </div>
                                    </div>
                                    
                                    <div class="report-footer">
                                        <span class="report-time">
                                            <i class="far fa-clock"></i>
                                            <?php echo date('h:i A', strtotime($report['created_at'])); ?>
                                        </span>
                                        <span class="report-status status-<?php echo strtolower($report['status']); ?>">
                                            <?php echo ucfirst($report['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="card-footer">
                            <a href="reports_archive.php" class="view-all-link">
                                View All Reports <i class="fas fa-arrow-right"></i>
                            </a>
                            <div class="pagination-info">
                                Showing <?php echo min(50, count($reports)); ?> of latest reports
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Statistics Chart -->
            <div class="card quick-stats">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-chart-pie"></i> Behavior Overview (30 Days)
                    </h3>
                </div>
                <div class="card-body">
                    <div class="stats-chart">
                        <canvas id="behaviorChart"></canvas>
                    </div>
                    <div class="stats-summary">
                        <div class="stat-item">
                            <span class="stat-label">Most Common Type:</span>
                            <span class="stat-value" id="commonType"><?php echo $chart_data['common_type']; ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Avg Severity:</span>
                            <span class="stat-value" id="avgSeverity"><?php echo $chart_data['avg_severity']; ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Reports Today:</span>
                            <span class="stat-value"><?php echo $stats['today_reports'] ?? 0; ?></span>
                        </div>
                        <div class="stat-item">
                            <span class="stat-label">Critical Incidents:</span>
                            <span class="stat-value"><?php echo $stats['critical_reports'] ?? 0; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Report Details Modal -->
<div id="reportModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas fa-file-alt"></i> Behavior Report Details
            </h3>
            <button class="modal-close" onclick="closeModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="reportDetails">
            <!-- Report details will be loaded here -->
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal()">
                <i class="fas fa-times"></i> Close
            </button>
            <button class="btn btn-primary" onclick="printReport()">
                <i class="fas fa-print"></i> Print
            </button>
            <button class="btn btn-outline" onclick="shareReport()">
                <i class="fas fa-share-alt"></i> Share
            </button>
        </div>
    </div>
</div>

<!-- Edit Report Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fas fa-edit"></i> Edit Behavior Report
            </h3>
            <button class="modal-close" onclick="closeEditModal()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <div class="modal-body" id="editReportForm">
            <!-- Edit form will be loaded here -->
        </div>
    </div>
</div>

<!-- CSS Styles -->
<style>
/* Enhanced CSS with new features */
.behavior-reports-container {
    padding: 20px;
    max-width: 1600px;
    margin: 0 auto;
}

/* Enhanced Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 20px;
    box-shadow: var(--card-shadow);
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 4px;
    background: linear-gradient(90deg, var(--primary-sea-blue), var(--accent-soft-cyan));
}

.stat-card.warning::before {
    background: linear-gradient(90deg, #ffc107, #fd7e14);
}

.stat-card.info::before {
    background: linear-gradient(90deg, #17a2b8, #35D0FF);
}

.stat-card.danger::before {
    background: linear-gradient(90deg, #dc3545, #ff6b6b);
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.15);
}

.stat-icon {
    font-size: 2.2rem;
    color: var(--primary-sea-blue);
    width: 60px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(23, 162, 184, 0.1);
    border-radius: 12px;
    flex-shrink: 0;
}

/* Content Grid */
.content-grid {
    display: grid;
    grid-template-columns: 1.2fr 0.8fr;
    gap: 30px;
}

@media (max-width: 1400px) {
    .content-grid {
        grid-template-columns: 1fr;
    }
}

/* Search Box */
.search-box {
    position: relative;
    margin-right: 10px;
}

.search-box input {
    padding: 8px 35px 8px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--bg-primary);
    color: var(--text-primary);
    font-size: 0.9rem;
    width: 200px;
    transition: all 0.3s ease;
}

.search-box input:focus {
    outline: none;
    border-color: var(--primary-sea-blue);
    width: 250px;
}

.search-box i {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
    font-size: 0.9rem;
}

/* Templates Grid */
.templates-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

.template-btn {
    padding: 12px;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    color: var(--text-primary);
    font-size: 0.9rem;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.template-btn:hover {
    background: var(--hover-bg);
    border-color: var(--primary-sea-blue);
    transform: translateY(-2px);
}

.template-btn i {
    font-size: 1.2rem;
    color: var(--primary-sea-blue);
}

/* Severity Indicator */
.severity-indicator {
    display: flex;
    gap: 5px;
    margin-top: 8px;
}

.severity-dot {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    cursor: pointer;
    transition: all 0.3s ease;
}

.severity-dot.low {
    background: #28a745;
    opacity: 0.3;
}

.severity-dot.medium {
    background: #ffc107;
    opacity: 0.3;
}

.severity-dot.high {
    background: #fd7e14;
    opacity: 0.3;
}

.severity-dot.critical {
    background: #dc3545;
    opacity: 0.3;
}

.severity-dot.active {
    opacity: 1;
    transform: scale(1.2);
}

/* Notification Badges */
.notification-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    background: var(--primary-sea-blue);
    color: white;
    border-radius: 50%;
    font-size: 0.7rem;
    margin-left: 5px;
}

.notification-badge.admin {
    background: #6c757d;
}

.notification-badge.private {
    background: #ffc107;
}

/* Edit Report Button */
.edit-report {
    color: var(--text-secondary);
}

.edit-report:hover {
    color: var(--primary-sea-blue);
}

/* Pagination Info */
.pagination-info {
    font-size: 0.8rem;
    color: var(--text-secondary);
}

/* Enhanced Modal */
.modal-content {
    background: var(--card-bg);
    border-radius: 16px;
    width: 100%;
    max-width: 900px;
    max-height: 90vh;
    overflow: hidden;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.25);
    animation: modalSlideIn 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes modalSlideIn {
    from {
        opacity: 0;
        transform: translateY(30px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* Report Detail Styles in Modal */
.report-detail {
    padding: 10px;
}

.detail-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border-color);
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.detail-item label {
    font-weight: 600;
    color: var(--text-secondary);
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.detail-item span {
    color: var(--text-primary);
    font-size: 1rem;
}

.detail-section {
    margin-bottom: 25px;
}

.detail-section h4 {
    font-size: 1.1rem;
    margin-bottom: 12px;
    color: var(--text-primary);
    display: flex;
    align-items: center;
    gap: 10px;
}

.detail-content {
    background: var(--bg-secondary);
    padding: 15px;
    border-radius: 8px;
    border-left: 4px solid var(--primary-sea-blue);
    line-height: 1.6;
    color: var(--text-primary);
}

.status-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    background: var(--bg-secondary);
    padding: 15px;
    border-radius: 8px;
}

.status-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.status-label {
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.status-value {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.9rem;
}

/* Button Outline Variant */
.btn-outline {
    background: transparent;
    border: 1px solid var(--primary-sea-blue);
    color: var(--primary-sea-blue);
}

.btn-outline:hover {
    background: rgba(23, 162, 184, 0.1);
}

/* Empty State Enhancement */
.empty-state {
    text-align: center;
    padding: 60px 20px;
}

.empty-state .btn {
    margin-top: 20px;
}

/* Print Optimization */
@media print {
    .modal-header .modal-close,
    .modal-footer,
    .btn-outline {
        display: none !important;
    }
    
    .modal-content {
        box-shadow: none;
        border: none;
        max-height: none;
        max-width: none;
    }
    
    .modal-body {
        max-height: none;
        overflow: visible;
        padding: 20px;
    }
    
    .detail-content {
        background: white !important;
        border: 1px solid #ddd;
    }
    
    .status-info {
        background: #f8f9fa !important;
        border: 1px solid #ddd;
    }
}

/* Dark mode optimizations */
.dark-mode .detail-content {
    background: rgba(255, 255, 255, 0.05);
}

.dark-mode .status-info {
    background: rgba(255, 255, 255, 0.05);
}

/* Loading States */
.loading {
    opacity: 0.6;
    pointer-events: none;
}

.loading::after {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 30px;
    height: 30px;
    border: 3px solid var(--border-color);
    border-top-color: var(--primary-sea-blue);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    to { transform: translate(-50%, -50%) rotate(360deg); }
}

/* Toast Notifications */
.toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-left: 4px solid var(--primary-sea-blue);
    padding: 15px 20px;
    border-radius: 8px;
    box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    z-index: 10000;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: toastSlideIn 0.3s ease;
    max-width: 400px;
}

@keyframes toastSlideIn {
    from {
        transform: translateX(100%);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

.toast.success {
    border-left-color: #28a745;
}

.toast.error {
    border-left-color: #dc3545;
}

.toast.info {
    border-left-color: #17a2b8;
}

.toast i {
    font-size: 1.2rem;
}

.toast.success i {
    color: #28a745;
}

.toast.error i {
    color: #dc3545;
}

.toast.info i {
    color: #17a2b8;
}

.toast .toast-close {
    margin-left: auto;
    background: none;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 0;
    font-size: 0.9rem;
}

/* Responsive Design */
@media (max-width: 768px) {
    .content-grid {
        gap: 20px;
    }
    
    .search-box input {
        width: 150px;
    }
    
    .search-box input:focus {
        width: 180px;
    }
    
    .templates-grid {
        grid-template-columns: 1fr;
    }
    
    .modal-content {
        margin: 10px;
        max-height: 80vh;
    }
    
    .detail-row {
        grid-template-columns: 1fr;
    }
    
    .form-actions {
        flex-direction: column;
        gap: 10px;
    }
    
    .form-actions .btn {
        width: 100%;
    }
}
</style>

<!-- Embedded JavaScript -->
<script>
// Embedded Data (PHP variables converted to JS)
const chartData = <?php echo json_encode($chart_data); ?>;
const teacherId = <?php echo $teacher_id; ?>;

// Global variables
let currentEditingReport = null;

// Load students based on selected class
async function loadStudents(classId) {
    if (!classId) {
        document.getElementById('student_id').innerHTML = '<option value="">Select Class First</option>';
        return;
    }
    
    try {
        const response = await fetch(`?action=get_students&class_id=${classId}`);
        const data = await response.json();
        
        const studentSelect = document.getElementById('student_id');
        studentSelect.innerHTML = '<option value="">Select Student</option>';
        
        data.forEach(student => {
            const option = document.createElement('option');
            option.value = student.id;
            option.textContent = student.name;
            studentSelect.appendChild(option);
        });
    } catch (error) {
        console.error('Error loading students:', error);
        showToast('Error loading students. Please try again.', 'error');
    }
}

// Update severity indicator dots
document.getElementById('severity').addEventListener('change', function() {
    const dots = document.querySelectorAll('.severity-dot');
    dots.forEach(dot => dot.classList.remove('active'));
    
    const severityMap = {
        'low': 0,
        'medium': 1,
        'high': 2,
        'critical': 3
    };
    
    dots[severityMap[this.value]]?.classList.add('active');
});

// Character count for description
document.getElementById('description').addEventListener('input', function() {
    const charCount = this.value.length;
    document.getElementById('charCount').textContent = charCount;
    
    if (charCount > 1000) {
        this.value = this.value.substring(0, 1000);
        document.getElementById('charCount').textContent = 1000;
    }
});

// View report details
async function viewReport(reportId) {
    try {
        const modal = document.getElementById('reportModal');
        const details = document.getElementById('reportDetails');
        
        // Show loading
        details.innerHTML = '<div class="loading"></div>';
        modal.classList.add('show');
        
        const response = await fetch(`?action=get_report&report_id=${reportId}`);
        const data = await response.json();
        
        if (!data) {
            details.innerHTML = '<div class="empty-state">Report not found</div>';
            return;
        }
        
        const formatDate = (dateString) => {
            return new Date(dateString).toLocaleDateString('en-US', {
                weekday: 'long',
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
        };
        
        const formatTime = (dateString) => {
            return new Date(dateString).toLocaleTimeString('en-US', {
                hour: '2-digit',
                minute: '2-digit'
            });
        };
        
        const severityColors = {
            'low': '#28a745',
            'medium': '#ffc107',
            'high': '#fd7e14',
            'critical': '#dc3545'
        };
        
        let html = `
            <div class="report-detail">
                <div class="detail-row">
                    <div class="detail-item">
                        <label><i class="fas fa-user-graduate"></i> Student:</label>
                        <span>${data.student_name}</span>
                    </div>
                    <div class="detail-item">
                        <label><i class="fas fa-chalkboard"></i> Class:</label>
                        <span>${data.class_name}</span>
                    </div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-item">
                        <label><i class="fas fa-calendar"></i> Report Date:</label>
                        <span>${formatDate(data.report_date)}</span>
                    </div>
                    <div class="detail-item">
                        <label><i class="fas fa-clock"></i> Submitted:</label>
                        <span>${formatDate(data.created_at)} at ${formatTime(data.created_at)}</span>
                    </div>
                </div>
                
                <div class="detail-row">
                    <div class="detail-item">
                        <label><i class="fas fa-tag"></i> Behavior Type:</label>
                        <span class="type-badge type-${data.behavior_type.toLowerCase()}">
                            ${data.behavior_type.replace('_', ' ')}
                        </span>
                    </div>
                    <div class="detail-item">
                        <label><i class="fas fa-exclamation-circle"></i> Severity:</label>
                        <span class="badge severity-${data.severity.toLowerCase()}" 
                              style="background: ${severityColors[data.severity]}; color: white;">
                            ${data.severity.charAt(0).toUpperCase() + data.severity.slice(1)}
                        </span>
                    </div>
                </div>
                
                <div class="detail-section">
                    <h4><i class="fas fa-align-left"></i> Incident Description</h4>
                    <div class="detail-content">${data.description.replace(/\n/g, '<br>')}</div>
                </div>
                
                ${data.action_taken ? `
                <div class="detail-section">
                    <h4><i class="fas fa-hands-helping"></i> Action Taken</h4>
                    <div class="detail-content">${data.action_taken.replace(/\n/g, '<br>')}</div>
                </div>
                ` : ''}
                
                ${data.follow_up ? `
                <div class="detail-section">
                    <h4><i class="fas fa-forward"></i> Follow-up Required</h4>
                    <div class="detail-content">${data.follow_up.replace(/\n/g, '<br>')}</div>
                </div>
                ` : ''}
                
                <div class="detail-section">
                    <h4><i class="fas fa-info-circle"></i> Report Status</h4>
                    <div class="status-info">
                        <div class="status-item">
                            <span class="status-label">Status:</span>
                            <span class="status-value status-${data.status.toLowerCase()}">
                                ${data.status.charAt(0).toUpperCase() + data.status.slice(1)}
                            </span>
                        </div>
                        <div class="status-item">
                            <span class="status-label">Parent Notified:</span>
                            <span class="status-value">
                                ${data.parent_notified ? '✅ Yes' : '❌ No'}
                            </span>
                        </div>
                        <div class="status-item">
                            <span class="status-label">Admin Notified:</span>
                            <span class="status-value">
                                ${data.admin_notified ? '✅ Yes' : '❌ No'}
                            </span>
                        </div>
                        <div class="status-item">
                            <span class="status-label">Visibility:</span>
                            <span class="status-value">
                                ${data.is_private ? '🔒 Private (Admins only)' : '👁️ Visible to Parents'}
                            </span>
                        </div>
                        <div class="status-item">
                            <span class="status-label">Reported By:</span>
                            <span class="status-value">${data.teacher_name}</span>
                        </div>
                        <div class="status-item">
                            <span class="status-label">Last Updated:</span>
                            <span class="status-value">${formatDate(data.updated_at)}</span>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        details.innerHTML = html;
    } catch (error) {
        console.error('Error loading report:', error);
        showToast('Error loading report details.', 'error');
    }
}

// Edit report
async function editReport(reportId) {
    try {
        currentEditingReport = reportId;
        const modal = document.getElementById('editModal');
        const formContainer = document.getElementById('editReportForm');
        
        // Show loading
        formContainer.innerHTML = '<div class="loading"></div>';
        modal.classList.add('show');
        
        const response = await fetch(`?action=get_report&report_id=${reportId}`);
        const data = await response.json();
        
        if (!data) {
            formContainer.innerHTML = '<div class="empty-state">Report not found</div>';
            return;
        }
        
        let html = `
            <form id="editReportFormInner" onsubmit="return updateReport(event)">
                <input type="hidden" name="report_id" value="${data.id}">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="form-group">
                    <label><i class="fas fa-align-left"></i> Description</label>
                    <textarea name="description" class="form-control" rows="4" required>${data.description}</textarea>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-hands-helping"></i> Action Taken</label>
                    <textarea name="action_taken" class="form-control" rows="3">${data.action_taken || ''}</textarea>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-forward"></i> Follow-up Required</label>
                    <textarea name="follow_up" class="form-control" rows="3">${data.follow_up || ''}</textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-exclamation-circle"></i> Severity</label>
                        <select name="severity" class="form-control">
                            <option value="low" ${data.severity === 'low' ? 'selected' : ''}>Low</option>
                            <option value="medium" ${data.severity === 'medium' ? 'selected' : ''}>Medium</option>
                            <option value="high" ${data.severity === 'high' ? 'selected' : ''}>High</option>
                            <option value="critical" ${data.severity === 'critical' ? 'selected' : ''}>Critical</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-info-circle"></i> Status</label>
                        <select name="status" class="form-control">
                            <option value="active" ${data.status === 'active' ? 'selected' : ''}>Active</option>
                            <option value="resolved" ${data.status === 'resolved' ? 'selected' : ''}>Resolved</option>
                            <option value="archived" ${data.status === 'archived' ? 'selected' : ''}>Archived</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row checkboxes">
                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="parent_notified" value="1" ${data.parent_notified ? 'checked' : ''}>
                            <span class="checkmark"></span>
                            <i class="fas fa-user-friends"></i> Notify Parent
                        </label>
                    </div>
                    
                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="admin_notified" value="1" ${data.admin_notified ? 'checked' : ''}>
                            <span class="checkmark"></span>
                            <i class="fas fa-user-shield"></i> Notify Admin
                        </label>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Report
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="button" class="btn btn-danger" onclick="deleteReport(${data.id})">
                        <i class="fas fa-trash"></i> Delete
                    </button>
                </div>
            </form>
        `;
        
        formContainer.innerHTML = html;
    } catch (error) {
        console.error('Error loading edit form:', error);
        showToast('Error loading edit form.', 'error');
    }
}

// Update report
async function updateReport(event) {
    event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    
    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const text = await response.text();
        if (response.ok) {
            showToast('Report updated successfully!', 'success');
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showToast('Error updating report.', 'error');
        }
    } catch (error) {
        console.error('Error updating report:', error);
        showToast('Error updating report.', 'error');
    }
}

// Delete report
async function deleteReport(reportId) {
    if (!confirm('Are you sure you want to delete this report? This action cannot be undone.')) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('csrf_token', '<?php echo $csrf_token; ?>');
        formData.append('delete_report', '1');
        formData.append('report_id', reportId);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        if (response.ok) {
            showToast('Report deleted successfully!', 'success');
            setTimeout(() => {
                location.reload();
            }, 1500);
        } else {
            showToast('Error deleting report.', 'error');
        }
    } catch (error) {
        console.error('Error deleting report:', error);
        showToast('Error deleting report.', 'error');
    }
}

// Close modals
function closeModal() {
    document.getElementById('reportModal').classList.remove('show');
}

function closeEditModal() {
    document.getElementById('editModal').classList.remove('show');
    currentEditingReport = null;
}

// Print report
function printReport() {
    window.print();
}

// Share report
function shareReport() {
    if (navigator.share) {
        navigator.share({
            title: 'Behavior Report',
            text: 'Check out this behavior report',
            url: window.location.href
        });
    } else {
        // Fallback: copy to clipboard
        const tempInput = document.createElement('input');
        tempInput.value = window.location.href;
        document.body.appendChild(tempInput);
        tempInput.select();
        document.execCommand('copy');
        document.body.removeChild(tempInput);
        showToast('Link copied to clipboard!', 'info');
    }
}

// Export reports
function exportReports() {
    window.open('?action=export', '_blank');
}

// Refresh reports
function refreshReports() {
    location.reload();
}

// Search reports
function searchReports(query) {
    const reports = document.querySelectorAll('.report-item');
    const searchTerm = query.toLowerCase().trim();
    
    reports.forEach(report => {
        const searchData = report.getAttribute('data-search') || '';
        if (searchData.includes(searchTerm) || !searchTerm) {
            report.style.display = 'block';
        } else {
            report.style.display = 'none';
        }
    });
}

// Clear form
function clearForm() {
    document.getElementById('behaviorReportForm').reset();
    document.getElementById('charCount').textContent = '0';
    
    // Reset severity indicator
    const dots = document.querySelectorAll('.severity-dot');
    dots.forEach(dot => dot.classList.remove('active'));
    dots[1].classList.add('active'); // Medium
}

// Save draft to localStorage
function saveDraft() {
    const form = document.getElementById('behaviorReportForm');
    const formData = {
        class_id: form.class_id.value,
        student_id: form.student_id.value,
        report_date: form.report_date.value,
        behavior_type: form.behavior_type.value,
        severity: form.severity.value,
        description: form.description.value,
        action_taken: form.action_taken.value,
        follow_up: form.follow_up.value,
        parent_notified: form.parent_notified.checked,
        admin_notified: form.admin_notified.checked,
        is_private: form.is_private.checked
    };
    
    localStorage.setItem('behavior_report_draft', JSON.stringify(formData));
    showToast('Draft saved locally!', 'info');
}

// Load draft from localStorage
function loadDraft() {
    const draft = localStorage.getItem('behavior_report_draft');
    if (draft) {
        const formData = JSON.parse(draft);
        const form = document.getElementById('behaviorReportForm');
        
        Object.keys(formData).forEach(key => {
            const element = form[key];
            if (element) {
                if (element.type === 'checkbox') {
                    element.checked = formData[key];
                } else {
                    element.value = formData[key];
                }
            }
        });
        
        // Update dependent fields
        if (formData.class_id) {
            loadStudents(formData.class_id).then(() => {
                if (formData.student_id) {
                    form.student_id.value = formData.student_id;
                }
            });
        }
        
        // Update character count
        document.getElementById('charCount').textContent = formData.description.length;
        
        // Update severity indicator
        document.getElementById('severity').dispatchEvent(new Event('change'));
        
        showToast('Draft loaded from local storage!', 'info');
    }
}

// Apply template
function applyTemplate(template) {
    const templates = {
        positive: {
            behavior_type: 'positive',
            severity: 'low',
            description: 'Demonstrated excellent behavior today. Showed respect to classmates and actively participated in class discussions. Went above and beyond expectations.',
            action_taken: 'Praised student in front of class. Awarded positive behavior sticker.',
            follow_up: 'Continue to reinforce positive behavior. Consider nominating for Student of the Month.'
        },
        attendance: {
            behavior_type: 'attendance',
            severity: 'medium',
            description: 'Multiple unexplained absences this week. Missing important class material and falling behind on assignments.',
            action_taken: 'Spoke with student about importance of regular attendance. Notified school attendance officer.',
            follow_up: 'Schedule meeting with parents. Monitor attendance closely for next two weeks.'
        },
        homework: {
            behavior_type: 'academic',
            severity: 'medium',
            description: 'Consistently failing to submit homework assignments. When submitted, work appears rushed and incomplete.',
            action_taken: 'Assigned detention to complete missing work. Provided additional resources and support.',
            follow_up: 'Check homework daily. Consider reduced workload if needed. Contact parents if no improvement.'
        },
        bullying: {
            behavior_type: 'bullying',
            severity: 'critical',
            description: 'Reported incident of verbal bullying toward another student during lunch break. Multiple witnesses confirmed the behavior.',
            action_taken: 'Immediate separation of students. Incident reported to school counselor and administration.',
            follow_up: 'Schedule mediation session. Monitor behavior closely. Consider disciplinary action if behavior continues.'
        }
    };
    
    const selected = templates[template];
    if (!selected) return;
    
    const form = document.getElementById('behaviorReportForm');
    
    // Update form fields
    form.behavior_type.value = selected.behavior_type;
    form.severity.value = selected.severity;
    form.description.value = selected.description;
    form.action_taken.value = selected.action_taken;
    form.follow_up.value = selected.follow_up;
    
    // Update dependent elements
    document.getElementById('severity').dispatchEvent(new Event('change'));
    document.getElementById('charCount').textContent = selected.description.length;
    
    showToast(`Applied ${template} template!`, 'info');
}

// Initialize Chart.js
function initializeChart() {
    const ctx = document.getElementById('behaviorChart').getContext('2d');
    
    // Destroy existing chart if it exists
    if (window.behaviorChart instanceof Chart) {
        window.behaviorChart.destroy();
    }
    
    const isDarkMode = document.documentElement.classList.contains('dark-mode');
    const textColor = isDarkMode ? '#FFFFFF' : '#0B0D10';
    const gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.1)';
    
    window.behaviorChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: chartData.labels,
            datasets: [{
                data: chartData.values,
                backgroundColor: [
                    'rgba(40, 167, 69, 0.8)',
                    'rgba(255, 193, 7, 0.8)',
                    'rgba(253, 126, 20, 0.8)',
                    'rgba(220, 53, 69, 0.8)'
                ],
                borderColor: [
                    'rgba(40, 167, 69, 1)',
                    'rgba(255, 193, 7, 1)',
                    'rgba(253, 126, 20, 1)',
                    'rgba(220, 53, 69, 1)'
                ],
                borderWidth: 2,
                hoverOffset: 15
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: textColor,
                        padding: 20,
                        font: {
                            size: 11
                        },
                        usePointStyle: true
                    }
                },
                tooltip: {
                    backgroundColor: isDarkMode ? 'rgba(0, 0, 0, 0.8)' : 'rgba(255, 255, 255, 0.9)',
                    titleColor: textColor,
                    bodyColor: textColor,
                    borderColor: gridColor,
                    borderWidth: 1
                }
            },
            animation: {
                animateScale: true,
                animateRotate: true,
                duration: 1000
            }
        }
    });
    
    // Update chart when theme changes
    window.addEventListener('themechange', () => {
        setTimeout(initializeChart, 100);
    });
}

// Show toast notification
function showToast(message, type = 'info') {
    // Remove existing toasts
    const existingToasts = document.querySelectorAll('.toast');
    existingToasts.forEach(toast => toast.remove());
    
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        info: 'fa-info-circle'
    };
    
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.innerHTML = `
        <i class="fas ${icons[type]}"></i>
        <span>${message}</span>
        <button class="toast-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    document.body.appendChild(toast);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        if (toast.parentElement) {
            toast.remove();
        }
    }, 5000);
}

// Form validation
document.getElementById('behaviorReportForm').addEventListener('submit', function(e) {
    const description = this.description.value.trim();
    if (description.length < 10) {
        e.preventDefault();
        showToast('Please provide a detailed description (at least 10 characters).', 'error');
        return false;
    }
    
    // Clear draft after successful submission
    localStorage.removeItem('behavior_report_draft');
    
    return true;
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize chart
    initializeChart();
    
    // Set maximum date to today
    document.getElementById('report_date').max = new Date().toISOString().split('T')[0];
    
    // Initialize character count
    const description = document.getElementById('description');
    document.getElementById('charCount').textContent = description.value.length;
    
    // Initialize severity indicator
    document.getElementById('severity').dispatchEvent(new Event('change'));
    
    // Load draft if available
    loadDraft();
    
    // Auto-save draft every 30 seconds
    setInterval(saveDraft, 30000);
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl/Cmd + S to save draft
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            saveDraft();
        }
        
        // Ctrl/Cmd + Enter to submit form
        if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
            const form = document.getElementById('behaviorReportForm');
            if (form.checkValidity()) {
                form.submit();
            }
        }
    });
    
    // View specific report if in URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('view')) {
        viewReport(urlParams.get('view'));
    }
});

// Handle window resize
let resizeTimeout;
window.addEventListener('resize', () => {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(() => {
        if (window.behaviorChart) {
            window.behaviorChart.resize();
        }
    }, 250);
});

// Push Notification Support (optional enhancement)
if ('serviceWorker' in navigator && 'PushManager' in window) {
    navigator.serviceWorker.register('../../sw.js')
        .then(registration => {
            console.log('Service Worker registered');
            
            // Request permission
            Notification.requestPermission().then(permission => {
                if (permission === 'granted') {
                    console.log('Push notifications enabled');
                }
            });
        })
        .catch(error => {
            console.error('Service Worker registration failed:', error);
        });
}
</script>

<?php 
// Handle POST for deleting reports
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_report'])) {
    require_once $root_path . 'functions/csrf.php';
    
    if (verify_csrf_token($_POST['csrf_token'])) {
        $report_id = filter_input(INPUT_POST, 'report_id', FILTER_VALIDATE_INT);
        
        if ($report_id) {
            try {
                $delete_stmt = $db->prepare("DELETE FROM behavior_reports WHERE id = ? AND teacher_id = ?");
                $delete_stmt->execute([$report_id, $teacher_id]);
                
                // Redirect to avoid resubmission
                header("Location: behavior_reports.php?deleted=1");
                exit();
            } catch (Exception $e) {
                error_log("Delete report error: " . $e->getMessage());
            }
        }
    }
}

// Show success message if report was deleted
if (isset($_GET['deleted'])) {
    echo '<script>showToast("Report deleted successfully!", "success");</script>';
}

// Include footer
include $root_path . 'templates/teacher_footer.php';
?>