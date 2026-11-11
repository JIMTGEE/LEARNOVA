<?php
// teacher/analytics.php - Student Diagnostic Assessment Analytics
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

$teacher_id = $_SESSION['user_id'];
$current_date = date('Y-m-d');

try {
    $db = db();
    if (!$db) {
        throw new Exception("Database connection failed");
    }

    // Get current term
    $current_term = null;
    $term_id = null;
    
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

    // Get teacher's classes for filtering
    $classes_stmt = $db->prepare("
        SELECT DISTINCT c.id, c.class_name 
        FROM classes c 
        WHERE c.teacher_id = ? OR c.class_teacher_id = ?
        ORDER BY c.class_name
    ");
    $classes_stmt->execute([$teacher_id, $teacher_id]);
    $teacher_classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get subjects
    $subjects_stmt = $db->prepare("
        SELECT s.id, s.subject_name, s.subject_code 
        FROM subjects s 
        WHERE s.teacher_id = ? OR s.id IN (
            SELECT subject_id FROM classes WHERE teacher_id = ? OR class_teacher_id = ?
        )
        ORDER BY s.subject_name
    ");
    $subjects_stmt->execute([$teacher_id, $teacher_id, $teacher_id]);
    $subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get filter parameters
    $filter_class = $_GET['class'] ?? '';
    $filter_subject = $_GET['subject'] ?? '';
    $filter_student = $_GET['student'] ?? '';
    $filter_type = $_GET['type'] ?? 'all';
    $filter_date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $filter_date_to = $_GET['date_to'] ?? date('Y-m-d');

    // Build query for diagnostic assessments
    $query = "
        SELECT da.*, 
               u.name as student_name,
               c.class_name,
               s.subject_name,
               ay.year as academic_year
        FROM diagnostic_assessments da
        JOIN users u ON da.student_id = u.id
        JOIN classes c ON da.class_id = c.id
        JOIN subjects s ON da.subject_id = s.id
        JOIN academic_years ay ON da.academic_year_id = ay.id
        WHERE da.teacher_id = ?
        AND (c.teacher_id = ? OR c.class_teacher_id = ?)
    ";

    $params = [$teacher_id, $teacher_id, $teacher_id];

    if ($filter_class && $filter_class !== 'all') {
        $query .= " AND da.class_id = ?";
        $params[] = $filter_class;
    }

    if ($filter_subject && $filter_subject !== 'all') {
        $query .= " AND da.subject_id = ?";
        $params[] = $filter_subject;
    }

    if ($filter_student) {
        $query .= " AND u.name LIKE ?";
        $params[] = "%$filter_student%";
    }

    if ($filter_type && $filter_type !== 'all') {
        $query .= " AND da.assessment_type = ?";
        $params[] = $filter_type;
    }

    $query .= " AND da.assessment_date BETWEEN ? AND ?";
    $params[] = $filter_date_from;
    $params[] = $filter_date_to;

    $query .= " ORDER BY da.assessment_date DESC, da.created_at DESC";

    $assessments_stmt = $db->prepare($query);
    $assessments_stmt->execute($params);
    $assessments = $assessments_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get assessment statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total_assessments,
            AVG(overall_score) as avg_score,
            MIN(overall_score) as min_score,
            MAX(overall_score) as max_score,
            SUM(CASE WHEN overall_score >= 70 THEN 1 ELSE 0 END) as proficient_count,
            SUM(CASE WHEN overall_score BETWEEN 50 AND 69 THEN 1 ELSE 0 END) as developing_count,
            SUM(CASE WHEN overall_score < 50 THEN 1 ELSE 0 END) as beginner_count
        FROM diagnostic_assessments da
        JOIN classes c ON da.class_id = c.id
        WHERE da.teacher_id = ?
        AND (c.teacher_id = ? OR c.class_teacher_id = ?)
    ";

    $stats_params = [$teacher_id, $teacher_id, $teacher_id];
    
    if ($filter_class && $filter_class !== 'all') {
        $stats_query .= " AND da.class_id = ?";
        $stats_params[] = $filter_class;
    }
    
    if ($filter_subject && $filter_subject !== 'all') {
        $stats_query .= " AND da.subject_id = ?";
        $stats_params[] = $filter_subject;
    }
    
    $stats_query .= " AND da.assessment_date BETWEEN ? AND ?";
    $stats_params[] = $filter_date_from;
    $stats_params[] = $filter_date_to;

    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->execute($stats_params);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    // Get competency analysis
    $competency_query = "
        SELECT 
            dc.competency_area,
            COUNT(dc.id) as assessment_count,
            AVG(dc.percentage) as avg_percentage,
            AVG(dc.student_score) as avg_score,
            SUM(CASE WHEN dc.proficiency_level = 'master' THEN 1 ELSE 0 END) as master_count,
            SUM(CASE WHEN dc.proficiency_level = 'advanced' THEN 1 ELSE 0 END) as advanced_count,
            SUM(CASE WHEN dc.proficiency_level = 'proficient' THEN 1 ELSE 0 END) as proficient_count,
            SUM(CASE WHEN dc.proficiency_level = 'developing' THEN 1 ELSE 0 END) as developing_count,
            SUM(CASE WHEN dc.proficiency_level = 'beginner' THEN 1 ELSE 0 END) as beginner_count
        FROM diagnostic_competencies dc
        JOIN diagnostic_assessments da ON dc.assessment_id = da.id
        JOIN classes c ON da.class_id = c.id
        WHERE da.teacher_id = ?
        AND (c.teacher_id = ? OR c.class_teacher_id = ?)
    ";

    $competency_params = [$teacher_id, $teacher_id, $teacher_id];
    
    if ($filter_class && $filter_class !== 'all') {
        $competency_query .= " AND da.class_id = ?";
        $competency_params[] = $filter_class;
    }
    
    if ($filter_subject && $filter_subject !== 'all') {
        $competency_query .= " AND da.subject_id = ?";
        $competency_params[] = $filter_subject;
    }
    
    $competency_query .= " AND da.assessment_date BETWEEN ? AND ?";
    $competency_params[] = $filter_date_from;
    $competency_params[] = $filter_date_to;
    
    $competency_query .= " GROUP BY dc.competency_area ORDER BY avg_percentage DESC";
    
    $competency_stmt = $db->prepare($competency_query);
    $competency_stmt->execute($competency_params);
    $competency_stats = $competency_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get students needing intervention
    $intervention_query = "
        SELECT 
            u.id,
            u.name as student_name,
            c.class_name,
            s.subject_name,
            da.assessment_date,
            da.overall_score,
            da.learning_gaps,
            da.recommendations
        FROM diagnostic_assessments da
        JOIN users u ON da.student_id = u.id
        JOIN classes c ON da.class_id = c.id
        JOIN subjects s ON da.subject_id = s.id
        WHERE da.teacher_id = ?
        AND (c.teacher_id = ? OR c.class_teacher_id = ?)
        AND da.overall_score < 50
        AND da.assessment_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
    ";

    $intervention_params = [$teacher_id, $teacher_id, $teacher_id];
    
    if ($filter_class && $filter_class !== 'all') {
        $intervention_query .= " AND da.class_id = ?";
        $intervention_params[] = $filter_class;
    }
    
    if ($filter_subject && $filter_subject !== 'all') {
        $intervention_query .= " AND da.subject_id = ?";
        $intervention_params[] = $filter_subject;
    }
    
    $intervention_query .= " ORDER BY da.overall_score ASC LIMIT 10";
    
    $intervention_stmt = $db->prepare($intervention_query);
    $intervention_stmt->execute($intervention_params);
    $intervention_students = $intervention_stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Analytics page error: " . $e->getMessage());
    $assessments = [];
    $stats = [
        'total_assessments' => 0,
        'avg_score' => 0,
        'min_score' => 0,
        'max_score' => 0,
        'proficient_count' => 0,
        'developing_count' => 0,
        'beginner_count' => 0
    ];
    $competency_stats = [];
    $intervention_students = [];
    $teacher_classes = [];
    $subjects = [];
    $current_term = null;
}

// Include header
include $root_path . 'templates/teacher_header.php';
?>

<!-- Analytics Content -->
<section class="analytics">
    <!-- Page Header -->
    <div class="analytics-header">
        <h1 class="page-title">Student Diagnostic Assessment Analytics</h1>
        <div class="analytics-subtitle">
            <span class="welcome">Diagnostic insights and performance tracking</span>
            <span class="academic-info">
                <?php if ($current_term): ?>
                    <i class="fas fa-calendar"></i> Term: <?php echo htmlspecialchars($current_term['name']); ?>
                <?php endif; ?>
            </span>
        </div>
    </div>

    <!-- Filters Card -->
    <div class="card filters-card">
        <h3 class="card-title">
            <i class="fas fa-filter"></i> Filter Assessments
        </h3>
        <form method="GET" class="filters-form" id="analyticsFilters">
            <div class="filter-grid">
                <div class="filter-group">
                    <label for="filter_class"><i class="fas fa-chalkboard"></i> Class</label>
                    <select id="filter_class" name="class" class="filter-select">
                        <option value="all">All Classes</option>
                        <?php foreach ($teacher_classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>" <?php echo ($filter_class == $class['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_subject"><i class="fas fa-book"></i> Subject</label>
                    <select id="filter_subject" name="subject" class="filter-select">
                        <option value="all">All Subjects</option>
                        <?php foreach ($subjects as $subject): ?>
                            <option value="<?php echo $subject['id']; ?>" <?php echo ($filter_subject == $subject['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($subject['subject_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_student"><i class="fas fa-user-graduate"></i> Student</label>
                    <input type="text" id="filter_student" name="student" class="filter-input" 
                           placeholder="Search student..." value="<?php echo htmlspecialchars($filter_student); ?>">
                </div>

                <div class="filter-group">
                    <label for="filter_type"><i class="fas fa-clipboard-check"></i> Assessment Type</label>
                    <select id="filter_type" name="type" class="filter-select">
                        <option value="all">All Types</option>
                        <option value="pre" <?php echo ($filter_type == 'pre') ? 'selected' : ''; ?>>Pre-Assessment</option>
                        <option value="mid" <?php echo ($filter_type == 'mid') ? 'selected' : ''; ?>>Mid-Term</option>
                        <option value="post" <?php echo ($filter_type == 'post') ? 'selected' : ''; ?>>Post-Assessment</option>
                        <option value="custom" <?php echo ($filter_type == 'custom') ? 'selected' : ''; ?>>Custom</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="filter_date_from"><i class="fas fa-calendar-alt"></i> From Date</label>
                    <input type="date" id="filter_date_from" name="date_from" class="filter-input" 
                           value="<?php echo htmlspecialchars($filter_date_from); ?>">
                </div>

                <div class="filter-group">
                    <label for="filter_date_to"><i class="fas fa-calendar-alt"></i> To Date</label>
                    <input type="date" id="filter_date_to" name="date_to" class="filter-input" 
                           value="<?php echo htmlspecialchars($filter_date_to); ?>">
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="filter-btn apply">
                    <i class="fas fa-search"></i> Apply Filters
                </button>
                <button type="button" class="filter-btn reset" onclick="resetFilters()">
                    <i class="fas fa-redo"></i> Reset
                </button>
                <button type="button" class="filter-btn export" onclick="exportAnalytics()">
                    <i class="fas fa-download"></i> Export Data
                </button>
                <button type="button" class="filter-btn new-assessment" onclick="window.location.href='new_diagnostic.php'">
                    <i class="fas fa-plus-circle"></i> New Assessment
                </button>
            </div>
        </form>
    </div>

    <!-- Summary Statistics -->
    <div class="dashboard-section">
        <div class="two-column-grid">
            <!-- Overall Statistics -->
            <div class="card">
                <h3 class="card-title">
                    <i class="fas fa-chart-bar"></i> Assessment Overview
                </h3>
                <div class="stats-grid-overview">
                    <div class="overview-stat total">
                        <div class="stat-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo $stats['total_assessments']; ?></div>
                            <div class="stat-label">Total Assessments</div>
                        </div>
                    </div>

                    <div class="overview-stat avg">
                        <div class="stat-icon">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo number_format($stats['avg_score'] ?? 0, 1); ?>%</div>
                            <div class="stat-label">Average Score</div>
                        </div>
                    </div>

                    <div class="overview-stat high">
                        <div class="stat-icon">
                            <i class="fas fa-trophy"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo number_format($stats['max_score'] ?? 0, 1); ?>%</div>
                            <div class="stat-label">Highest Score</div>
                        </div>
                    </div>

                    <div class="overview-stat low">
                        <div class="stat-icon">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-value"><?php echo number_format($stats['min_score'] ?? 0, 1); ?>%</div>
                            <div class="stat-label">Lowest Score</div>
                        </div>
                    </div>
                </div>

                <!-- Proficiency Distribution -->
                <div class="proficiency-distribution">
                    <h4>Proficiency Distribution</h4>
                    <div class="distribution-bars">
                        <div class="distribution-bar">
                            <div class="bar-label">Beginner</div>
                            <div class="bar-container">
                                <div class="bar-fill beginner" style="width: <?php echo $stats['total_assessments'] > 0 ? ($stats['beginner_count'] / $stats['total_assessments'] * 100) : 0; ?>%">
                                    <span class="bar-value"><?php echo $stats['beginner_count']; ?></span>
                                </div>
                            </div>
                            <div class="bar-percentage"><?php echo $stats['total_assessments'] > 0 ? round($stats['beginner_count'] / $stats['total_assessments'] * 100, 1) : 0; ?>%</div>
                        </div>

                        <div class="distribution-bar">
                            <div class="bar-label">Developing</div>
                            <div class="bar-container">
                                <div class="bar-fill developing" style="width: <?php echo $stats['total_assessments'] > 0 ? ($stats['developing_count'] / $stats['total_assessments'] * 100) : 0; ?>%">
                                    <span class="bar-value"><?php echo $stats['developing_count']; ?></span>
                                </div>
                            </div>
                            <div class="bar-percentage"><?php echo $stats['total_assessments'] > 0 ? round($stats['developing_count'] / $stats['total_assessments'] * 100, 1) : 0; ?>%</div>
                        </div>

                        <div class="distribution-bar">
                            <div class="bar-label">Proficient</div>
                            <div class="bar-container">
                                <div class="bar-fill proficient" style="width: <?php echo $stats['total_assessments'] > 0 ? ($stats['proficient_count'] / $stats['total_assessments'] * 100) : 0; ?>%">
                                    <span class="bar-value"><?php echo $stats['proficient_count']; ?></span>
                                </div>
                            </div>
                            <div class="bar-percentage"><?php echo $stats['total_assessments'] > 0 ? round($stats['proficient_count'] / $stats['total_assessments'] * 100, 1) : 0; ?>%</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Competency Analysis -->
            <div class="card">
                <h3 class="card-title">
                    <i class="fas fa-brain"></i> Competency Analysis
                </h3>
                <div class="competency-analysis">
                    <?php if (empty($competency_stats)): ?>
                        <p class="empty-message">No competency data available</p>
                    <?php else: ?>
                        <div class="competency-list">
                            <?php foreach ($competency_stats as $competency): ?>
                                <div class="competency-item">
                                    <div class="competency-header">
                                        <h4><?php echo htmlspecialchars($competency['competency_area']); ?></h4>
                                        <span class="competency-score"><?php echo number_format($competency['avg_percentage'] ?? 0, 1); ?>%</span>
                                    </div>
                                    <div class="competency-stats">
                                        <div class="competency-stat">
                                            <span class="stat-label">Assessments:</span>
                                            <span class="stat-value"><?php echo $competency['assessment_count']; ?></span>
                                        </div>
                                        <div class="competency-stat">
                                            <span class="stat-label">Avg Score:</span>
                                            <span class="stat-value"><?php echo number_format($competency['avg_score'] ?? 0, 1); ?></span>
                                        </div>
                                    </div>
                                    <div class="competency-proficiency">
                                        <div class="proficiency-level">
                                            <span class="level-label">Master:</span>
                                            <span class="level-count master"><?php echo $competency['master_count']; ?></span>
                                        </div>
                                        <div class="proficiency-level">
                                            <span class="level-label">Advanced:</span>
                                            <span class="level-count advanced"><?php echo $competency['advanced_count']; ?></span>
                                        </div>
                                        <div class="proficiency-level">
                                            <span class="level-label">Proficient:</span>
                                            <span class="level-count proficient"><?php echo $competency['proficient_count']; ?></span>
                                        </div>
                                        <div class="proficiency-level">
                                            <span class="level-label">Developing:</span>
                                            <span class="level-count developing"><?php echo $competency['developing_count']; ?></span>
                                        </div>
                                        <div class="proficiency-level">
                                            <span class="level-label">Beginner:</span>
                                            <span class="level-count beginner"><?php echo $competency['beginner_count']; ?></span>
                                        </div>
                                    </div>
                                    <div class="competency-progress">
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $competency['avg_percentage'] ?? 0; ?>%"></div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="dashboard-section">
        <div class="card">
            <h3 class="card-title">
                <i class="fas fa-chart-line"></i> Performance Trends
            </h3>
            <div class="charts-container">
                <div class="chart-wrapper">
                    <canvas id="scoreTrendChart" height="300"></canvas>
                </div>
                <div class="chart-wrapper">
                    <canvas id="competencyRadarChart" height="300"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Assessments List -->
    <div class="dashboard-section">
        <div class="card">
            <h3 class="card-title">
                <i class="fas fa-list-alt"></i> Recent Assessments
                <span class="badge"><?php echo count($assessments); ?></span>
            </h3>
            <div class="assessments-table-container">
                <?php if (empty($assessments)): ?>
                    <p class="empty-message">No assessments found matching your criteria</p>
                <?php else: ?>
                    <table class="assessments-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Assessment</th>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Score</th>
                                <th>Proficiency</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($assessments as $assessment): ?>
                                <?php 
                                    $score = $assessment['overall_score'] ?? 0;
                                    $proficiency = '';
                                    $proficiency_class = '';
                                    
                                    if ($score >= 80) {
                                        $proficiency = 'Master';
                                        $proficiency_class = 'master';
                                    } elseif ($score >= 70) {
                                        $proficiency = 'Advanced';
                                        $proficiency_class = 'advanced';
                                    } elseif ($score >= 60) {
                                        $proficiency = 'Proficient';
                                        $proficiency_class = 'proficient';
                                    } elseif ($score >= 50) {
                                        $proficiency = 'Developing';
                                        $proficiency_class = 'developing';
                                    } else {
                                        $proficiency = 'Beginner';
                                        $proficiency_class = 'beginner';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <div class="student-info">
                                            <div class="student-avatar">
                                                <?php echo strtoupper(substr($assessment['student_name'], 0, 1)); ?>
                                            </div>
                                            <div class="student-name"><?php echo htmlspecialchars($assessment['student_name']); ?></div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($assessment['class_name']); ?></td>
                                    <td><?php echo htmlspecialchars($assessment['subject_name']); ?></td>
                                    <td>
                                        <div class="assessment-title">
                                            <strong><?php echo htmlspecialchars($assessment['title']); ?></strong>
                                            <?php if ($assessment['description']): ?>
                                                <small><?php echo htmlspecialchars(substr($assessment['description'], 0, 60)); ?>...</small>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo date('M j, Y', strtotime($assessment['assessment_date'])); ?></td>
                                    <td>
                                        <span class="type-badge <?php echo $assessment['assessment_type']; ?>">
                                            <?php echo ucfirst($assessment['assessment_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="score-display">
                                            <span class="score-value"><?php echo number_format($score, 1); ?>%</span>
                                            <div class="score-bar">
                                                <div class="score-fill <?php echo $proficiency_class; ?>" style="width: <?php echo $score; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="proficiency-badge <?php echo $proficiency_class; ?>">
                                            <?php echo $proficiency; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button class="action-btn view" onclick="viewAssessment(<?php echo $assessment['id']; ?>)">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                            <button class="action-btn edit" onclick="editAssessment(<?php echo $assessment['id']; ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="action-btn report" onclick="generateReport(<?php echo $assessment['id']; ?>)">
                                                <i class="fas fa-file-pdf"></i>
                                            </button>
                                            <button class="action-btn delete" onclick="deleteAssessment(<?php echo $assessment['id']; ?>, '<?php echo htmlspecialchars($assessment['title']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Intervention Needed -->
    <div class="dashboard-section">
        <div class="card intervention-card">
            <h3 class="card-title">
                <i class="fas fa-exclamation-triangle"></i> Students Needing Intervention
                <span class="badge warning"><?php echo count($intervention_students); ?></span>
            </h3>
            <div class="intervention-list">
                <?php if (empty($intervention_students)): ?>
                    <p class="empty-message">No students currently require intervention</p>
                <?php else: ?>
                    <?php foreach ($intervention_students as $student): ?>
                        <div class="intervention-item">
                            <div class="intervention-student">
                                <div class="student-avatar warning">
                                    <?php echo strtoupper(substr($student['student_name'], 0, 1)); ?>
                                </div>
                                <div class="student-details">
                                    <strong><?php echo htmlspecialchars($student['student_name']); ?></strong>
                                    <span><?php echo htmlspecialchars($student['class_name']); ?> - <?php echo htmlspecialchars($student['subject_name']); ?></span>
                                    <small>Last assessment: <?php echo date('M j, Y', strtotime($student['assessment_date'])); ?></small>
                                </div>
                            </div>
                            <div class="intervention-score">
                                <div class="score-display">
                                    <span class="score-value warning"><?php echo number_format($student['overall_score'], 1); ?>%</span>
                                    <div class="score-bar">
                                        <div class="score-fill warning" style="width: <?php echo $student['overall_score']; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="intervention-actions">
                                <button class="intervention-btn plan" onclick="createInterventionPlan(<?php echo $student['id']; ?>)">
                                    <i class="fas fa-clipboard-check"></i> Plan
                                </button>
                                <button class="intervention-btn contact" onclick="contactParent(<?php echo $student['id']; ?>)">
                                    <i class="fas fa-comment"></i> Contact
                                </button>
                                <button class="intervention-btn reassess" onclick="scheduleReassessment(<?php echo $student['id']; ?>)">
                                    <i class="fas fa-redo"></i> Reassess
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Analytics CSS -->
<style>
/* Analytics Layout */
.analytics {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
}

.analytics-header {
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

.analytics-subtitle {
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

/* Filters Card */
.filters-card {
    margin-bottom: 30px;
}

.filters-form {
    margin-top: 20px;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.filter-group label {
    font-weight: 600;
    color: var(--text-secondary);
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-select, .filter-input {
    padding: 10px 15px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: var(--bg-primary);
    color: var(--text-primary);
    font-family: 'Inter', sans-serif;
    font-size: 0.95rem;
    transition: all 0.3s ease;
}

.filter-select:focus, .filter-input:focus {
    outline: none;
    border-color: var(--primary-sea-blue);
    box-shadow: 0 0 0 3px rgba(23, 162, 184, 0.1);
}

.filter-actions {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
    padding-top: 20px;
    border-top: 1px solid var(--border-color);
}

.filter-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-family: 'Inter', sans-serif;
    font-weight: 600;
    font-size: 0.9rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
}

.filter-btn.apply {
    background: var(--primary-sea-blue);
    color: white;
}

.filter-btn.reset {
    background: var(--bg-secondary);
    color: var(--text-secondary);
    border: 1px solid var(--border-color);
}

.filter-btn.export {
    background: var(--accent-deep-navy);
    color: white;
}

.filter-btn.new-assessment {
    background: var(--accent-soft-cyan);
    color: var(--primary-black);
    margin-left: auto;
}

.filter-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

/* Overview Statistics */
.stats-grid-overview {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.overview-stat {
    background: var(--bg-secondary);
    border-radius: 10px;
    padding: 20px;
    display: flex;
    align-items: center;
    gap: 15px;
    border: 1px solid var(--border-color);
    transition: transform 0.3s ease;
}

.overview-stat:hover {
    transform: translateY(-4px);
}

.overview-stat.total {
    border-left: 4px solid var(--primary-sea-blue);
}

.overview-stat.avg {
    border-left: 4px solid #28a745;
}

.overview-stat.high {
    border-left: 4px solid #ffc107;
}

.overview-stat.low {
    border-left: 4px solid #dc3545;
}

.stat-icon {
    font-size: 2rem;
}

.overview-stat.total .stat-icon {
    color: var(--primary-sea-blue);
}

.overview-stat.avg .stat-icon {
    color: #28a745;
}

.overview-stat.high .stat-icon {
    color: #ffc107;
}

.overview-stat.low .stat-icon {
    color: #dc3545;
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
    margin-top: 4px;
}

/* Proficiency Distribution */
.proficiency-distribution {
    margin-top: 25px;
}

.proficiency-distribution h4 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 15px;
}

.distribution-bars {
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.distribution-bar {
    display: flex;
    align-items: center;
    gap: 15px;
}

.bar-label {
    min-width: 100px;
    font-size: 0.9rem;
    color: var(--text-secondary);
    font-weight: 500;
}

.bar-container {
    flex: 1;
    height: 24px;
    background: var(--bg-secondary);
    border-radius: 12px;
    overflow: hidden;
    position: relative;
}

.bar-fill {
    height: 100%;
    border-radius: 12px;
    position: relative;
    transition: width 1s ease-in-out;
}

.bar-fill.beginner {
    background: linear-gradient(90deg, #dc3545, #e35d6a);
}

.bar-fill.developing {
    background: linear-gradient(90deg, #ffc107, #ffd454);
}

.bar-fill.proficient {
    background: linear-gradient(90deg, #28a745, #4cc069);
}

.bar-value {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: white;
    font-weight: 600;
    font-size: 0.8rem;
}

.bar-percentage {
    min-width: 50px;
    text-align: right;
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.9rem;
}

/* Competency Analysis */
.competency-analysis {
    max-height: 400px;
    overflow-y: auto;
    padding-right: 10px;
}

.competency-list {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.competency-item {
    background: var(--bg-secondary);
    border-radius: 10px;
    padding: 20px;
    border: 1px solid var(--border-color);
}

.competency-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.competency-header h4 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text-primary);
    margin: 0;
}

.competency-score {
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--primary-sea-blue);
}

.competency-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 15px;
}

.competency-stat {
    display: flex;
    align-items: center;
    gap: 8px;
}

.stat-label {
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.stat-value {
    font-size: 0.9rem;
    font-weight: 600;
    color: var(--text-primary);
}

.competency-proficiency {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 15px;
}

.proficiency-level {
    display: flex;
    align-items: center;
    gap: 5px;
    padding: 4px 8px;
    background: var(--bg-primary);
    border-radius: 4px;
    font-size: 0.8rem;
}

.level-label {
    color: var(--text-secondary);
}

.level-count {
    font-weight: 600;
}

.level-count.master {
    color: #6f42c1;
}

.level-count.advanced {
    color: #6610f2;
}

.level-count.proficient {
    color: #28a745;
}

.level-count.developing {
    color: #ffc107;
}

.level-count.beginner {
    color: #dc3545;
}

.competency-progress {
    margin-top: 10px;
}

.progress-bar {
    height: 8px;
    background: var(--bg-primary);
    border-radius: 4px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, var(--primary-sea-blue), var(--accent-soft-cyan));
    border-radius: 4px;
    transition: width 1s ease-in-out;
}

/* Charts */
.charts-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 30px;
    margin-top: 20px;
}

.chart-wrapper {
    background: var(--bg-primary);
    border-radius: 10px;
    padding: 20px;
    border: 1px solid var(--border-color);
}

/* Assessments Table */
.assessments-table-container {
    overflow-x: auto;
    margin-top: 20px;
}

.assessments-table {
    width: 100%;
    border-collapse: collapse;
}

.assessments-table thead {
    background: var(--bg-secondary);
}

.assessments-table th {
    padding: 15px;
    text-align: left;
    font-weight: 600;
    color: var(--text-secondary);
    font-size: 0.9rem;
    border-bottom: 2px solid var(--border-color);
}

.assessments-table td {
    padding: 15px;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}

.student-info {
    display: flex;
    align-items: center;
    gap: 10px;
}

.student-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: var(--primary-sea-blue);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 0.9rem;
}

.student-avatar.warning {
    background: #dc3545;
}

.student-name {
    font-weight: 500;
    color: var(--text-primary);
}

.assessment-title {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.assessment-title strong {
    color: var(--text-primary);
    font-size: 0.95rem;
}

.assessment-title small {
    color: var(--text-secondary);
    font-size: 0.85rem;
}

.type-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.type-badge.pre {
    background: rgba(23, 162, 184, 0.1);
    color: var(--primary-sea-blue);
}

.type-badge.mid {
    background: rgba(255, 193, 7, 0.1);
    color: #ffc107;
}

.type-badge.post {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
}

.type-badge.custom {
    background: rgba(108, 117, 125, 0.1);
    color: #6c757d;
}

.score-display {
    display: flex;
    align-items: center;
    gap: 10px;
}

.score-value {
    min-width: 50px;
    font-weight: 600;
    font-size: 0.95rem;
}

.score-value.warning {
    color: #dc3545;
}

.score-bar {
    flex: 1;
    height: 8px;
    background: var(--bg-secondary);
    border-radius: 4px;
    overflow: hidden;
}

.score-fill {
    height: 100%;
    border-radius: 4px;
    transition: width 0.3s ease;
}

.score-fill.master {
    background: linear-gradient(90deg, #6f42c1, #8c68d6);
}

.score-fill.advanced {
    background: linear-gradient(90deg, #6610f2, #8540f5);
}

.score-fill.proficient {
    background: linear-gradient(90deg, #28a745, #4cc069);
}

.score-fill.developing {
    background: linear-gradient(90deg, #ffc107, #ffd454);
}

.score-fill.beginner {
    background: linear-gradient(90deg, #dc3545, #e35d6a);
}

.score-fill.warning {
    background: linear-gradient(90deg, #dc3545, #e35d6a);
}

.proficiency-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
}

.proficiency-badge.master {
    background: rgba(111, 66, 193, 0.1);
    color: #6f42c1;
}

.proficiency-badge.advanced {
    background: rgba(102, 16, 242, 0.1);
    color: #6610f2;
}

.proficiency-badge.proficient {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
}

.proficiency-badge.developing {
    background: rgba(255, 193, 7, 0.1);
    color: #ffc107;
}

.proficiency-badge.beginner {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}

.action-buttons {
    display: flex;
    gap: 8px;
}

.action-btn {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.3s ease;
    color: white;
    font-size: 0.9rem;
}

.action-btn.view {
    background: var(--primary-sea-blue);
}

.action-btn.edit {
    background: #ffc107;
}

.action-btn.report {
    background: #28a745;
}

.action-btn.delete {
    background: #dc3545;
}

.action-btn:hover {
    transform: scale(1.1);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
}

/* Intervention List */
.intervention-list {
    display: flex;
    flex-direction: column;
    gap: 15px;
    margin-top: 20px;
}

.intervention-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px;
    background: var(--bg-secondary);
    border-radius: 10px;
    border: 1px solid rgba(220, 53, 69, 0.2);
}

.intervention-student {
    display: flex;
    align-items: center;
    gap: 15px;
    flex: 1;
}

.student-details {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.student-details strong {
    color: var(--text-primary);
    font-size: 1rem;
}

.student-details span {
    color: var(--text-secondary);
    font-size: 0.9rem;
}

.student-details small {
    color: var(--text-muted);
    font-size: 0.8rem;
}

.intervention-score {
    min-width: 150px;
}

.intervention-actions {
    display: flex;
    gap: 10px;
}

.intervention-btn {
    padding: 8px 15px;
    border: none;
    border-radius: 6px;
    font-family: 'Inter', sans-serif;
    font-weight: 600;
    font-size: 0.85rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 5px;
    transition: all 0.3s ease;
}

.intervention-btn.plan {
    background: var(--primary-sea-blue);
    color: white;
}

.intervention-btn.contact {
    background: #ffc107;
    color: var(--primary-black);
}

.intervention-btn.reassess {
    background: #28a745;
    color: white;
}

.intervention-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0, 0, 0, 0.15);
}

/* Badges */
.badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 600;
    margin-left: 8px;
    background: var(--bg-secondary);
    color: var(--text-secondary);
}

.badge.warning {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}

/* Empty States */
.empty-message {
    text-align: center;
    color: var(--text-muted);
    padding: 40px 20px;
    font-style: italic;
    font-size: 1rem;
}

/* Responsive Design */
@media (max-width: 1200px) {
    .charts-container {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 992px) {
    .filter-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .stats-grid-overview {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .intervention-item {
        flex-direction: column;
        gap: 15px;
        align-items: stretch;
    }
    
    .intervention-student {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .intervention-actions {
        justify-content: center;
    }
}

@media (max-width: 768px) {
    .filter-grid {
        grid-template-columns: 1fr;
    }
    
    .stats-grid-overview {
        grid-template-columns: 1fr;
    }
    
    .filter-actions {
        flex-direction: column;
    }
    
    .filter-btn {
        width: 100%;
        justify-content: center;
    }
    
    .assessments-table {
        display: block;
    }
    
    .assessments-table thead {
        display: none;
    }
    
    .assessments-table tbody tr {
        display: block;
        margin-bottom: 15px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        padding: 15px;
    }
    
    .assessments-table td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px 0;
        border-bottom: 1px solid var(--border-color);
    }
    
    .assessments-table td:last-child {
        border-bottom: none;
    }
    
    .assessments-table td::before {
        content: attr(data-label);
        font-weight: 600;
        color: var(--text-secondary);
        margin-right: 15px;
        min-width: 100px;
    }
}

@media (max-width: 480px) {
    .competency-stats {
        flex-direction: column;
        gap: 10px;
    }
    
    .competency-proficiency {
        flex-direction: column;
        gap: 5px;
    }
    
    .intervention-actions {
        flex-direction: column;
    }
}
</style>

<!-- JavaScript for Analytics -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ============================================
// CHART FUNCTIONS
// ============================================

let scoreTrendChart = null;
let competencyRadarChart = null;

function initializeCharts() {
    // Sample data - In production, fetch from API
    const scoreTrendData = {
        labels: ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5', 'Week 6'],
        datasets: [{
            label: 'Average Score',
            data: [65, 68, 72, 75, 78, 82],
            borderColor: 'var(--primary-sea-blue)',
            backgroundColor: 'rgba(23, 162, 184, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4
        }, {
            label: 'Class Average',
            data: [60, 62, 65, 68, 70, 72],
            borderColor: 'var(--accent-soft-cyan)',
            backgroundColor: 'rgba(53, 208, 255, 0.1)',
            borderWidth: 2,
            fill: true,
            tension: 0.4
        }]
    };

    const competencyRadarData = {
        labels: ['Reading', 'Writing', 'Numeracy', 'Critical Thinking', 'Problem Solving', 'Communication'],
        datasets: [{
            label: 'Current Assessment',
            data: [85, 78, 92, 80, 75, 88],
            backgroundColor: 'rgba(23, 162, 184, 0.2)',
            borderColor: 'var(--primary-sea-blue)',
            borderWidth: 2,
            pointBackgroundColor: 'var(--primary-sea-blue)'
        }, {
            label: 'Previous Assessment',
            data: [75, 70, 85, 72, 68, 80],
            backgroundColor: 'rgba(53, 208, 255, 0.2)',
            borderColor: 'var(--accent-soft-cyan)',
            borderWidth: 2,
            pointBackgroundColor: 'var(--accent-soft-cyan)'
        }]
    };

    // Score Trend Chart
    const scoreTrendCtx = document.getElementById('scoreTrendChart').getContext('2d');
    scoreTrendChart = new Chart(scoreTrendCtx, {
        type: 'line',
        data: scoreTrendData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary')
                    }
                },
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    backgroundColor: getComputedStyle(document.documentElement).getPropertyValue('--card-bg'),
                    titleColor: getComputedStyle(document.documentElement).getPropertyValue('--text-primary'),
                    bodyColor: getComputedStyle(document.documentElement).getPropertyValue('--text-secondary'),
                    borderColor: getComputedStyle(document.documentElement).getPropertyValue('--border-color'),
                    borderWidth: 1
                }
            },
            scales: {
                x: {
                    grid: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                    },
                    ticks: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-secondary')
                    }
                },
                y: {
                    beginAtZero: true,
                    max: 100,
                    grid: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                    },
                    ticks: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-secondary'),
                        callback: function(value) {
                            return value + '%';
                        }
                    }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            }
        }
    });

    // Competency Radar Chart
    const competencyRadarCtx = document.getElementById('competencyRadarChart').getContext('2d');
    competencyRadarChart = new Chart(competencyRadarCtx, {
        type: 'radar',
        data: competencyRadarData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                r: {
                    beginAtZero: true,
                    max: 100,
                    ticks: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-secondary'),
                        backdropColor: 'transparent'
                    },
                    grid: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                    },
                    angleLines: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--border-color')
                    },
                    pointLabels: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary')
                    }
                }
            },
            plugins: {
                legend: {
                    labels: {
                        color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary')
                    }
                }
            }
        }
    });
}

function updateChartColors() {
    if (scoreTrendChart) {
        scoreTrendChart.options.plugins.legend.labels.color = getComputedStyle(document.documentElement).getPropertyValue('--text-primary');
        scoreTrendChart.options.scales.x.ticks.color = getComputedStyle(document.documentElement).getPropertyValue('--text-secondary');
        scoreTrendChart.options.scales.y.ticks.color = getComputedStyle(document.documentElement).getPropertyValue('--text-secondary');
        scoreTrendChart.options.scales.x.grid.color = getComputedStyle(document.documentElement).getPropertyValue('--border-color');
        scoreTrendChart.options.scales.y.grid.color = getComputedStyle(document.documentElement).getPropertyValue('--border-color');
        scoreTrendChart.update();
    }

    if (competencyRadarChart) {
        competencyRadarChart.options.scales.r.ticks.color = getComputedStyle(document.documentElement).getPropertyValue('--text-secondary');
        competencyRadarChart.options.scales.r.grid.color = getComputedStyle(document.documentElement).getPropertyValue('--border-color');
        competencyRadarChart.options.scales.r.angleLines.color = getComputedStyle(document.documentElement).getPropertyValue('--border-color');
        competencyRadarChart.options.scales.r.pointLabels.color = getComputedStyle(document.documentElement).getPropertyValue('--text-primary');
        competencyRadarChart.options.plugins.legend.labels.color = getComputedStyle(document.documentElement).getPropertyValue('--text-primary');
        competencyRadarChart.update();
    }
}

// ============================================
// FILTER FUNCTIONS
// ============================================

function resetFilters() {
    document.getElementById('filter_class').value = 'all';
    document.getElementById('filter_subject').value = 'all';
    document.getElementById('filter_student').value = '';
    document.getElementById('filter_type').value = 'all';
    
    const today = new Date();
    const thirtyDaysAgo = new Date(today);
    thirtyDaysAgo.setDate(today.getDate() - 30);
    
    document.getElementById('filter_date_from').value = thirtyDaysAgo.toISOString().split('T')[0];
    document.getElementById('filter_date_to').value = today.toISOString().split('T')[0];
    
    document.getElementById('analyticsFilters').submit();
}

function exportAnalytics() {
    const filters = {
        class: document.getElementById('filter_class').value,
        subject: document.getElementById('filter_subject').value,
        student: document.getElementById('filter_student').value,
        type: document.getElementById('filter_type').value,
        date_from: document.getElementById('filter_date_from').value,
        date_to: document.getElementById('filter_date_to').value
    };

    // Show export options modal
    showExportModal(filters);
}

function showExportModal(filters) {
    const modal = document.createElement('div');
    modal.className = 'export-modal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3><i class="fas fa-download"></i> Export Analytics Data</h3>
                <button class="modal-close" onclick="this.parentElement.parentElement.remove()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="export-options">
                    <div class="export-option" onclick="exportToPDF()">
                        <i class="fas fa-file-pdf"></i>
                        <div class="option-content">
                            <strong>PDF Report</strong>
                            <span>Export as printable PDF document</span>
                        </div>
                        <i class="fas fa-chevron-right"></i>
                    </div>
                    <div class="export-option" onclick="exportToCSV()">
                        <i class="fas fa-file-csv"></i>
                        <div class="option-content">
                            <strong>CSV Data</strong>
                            <span>Export raw data for spreadsheet analysis</span>
                        </div>
                        <i class="fas fa-chevron-right"></i>
                    </div>
                    <div class="export-option" onclick="exportToExcel()">
                        <i class="fas fa-file-excel"></i>
                        <div class="option-content">
                            <strong>Excel Report</strong>
                            <span>Export with charts and formatting</span>
                        </div>
                        <i class="fas fa-chevron-right"></i>
                    </div>
                </div>
                <div class="export-filters">
                    <h4>Selected Filters:</h4>
                    <div class="filter-tags">
                        ${filters.class !== 'all' ? `<span class="filter-tag">Class: ${document.getElementById('filter_class').options[document.getElementById('filter_class').selectedIndex].text}</span>` : ''}
                        ${filters.subject !== 'all' ? `<span class="filter-tag">Subject: ${document.getElementById('filter_subject').options[document.getElementById('filter_subject').selectedIndex].text}</span>` : ''}
                        ${filters.student ? `<span class="filter-tag">Student: ${filters.student}</span>` : ''}
                        ${filters.type !== 'all' ? `<span class="filter-tag">Type: ${filters.type}</span>` : ''}
                        <span class="filter-tag">Date Range: ${filters.date_from} to ${filters.date_to}</span>
                    </div>
                </div>
            </div>
        </div>
    `;

    document.body.appendChild(modal);
    
    // Add modal styles
    const style = document.createElement('style');
    style.textContent = `
        .export-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2000;
        }
        .modal-content {
            background: var(--card-bg);
            border-radius: 12px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }
        .modal-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 {
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-primary);
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            color: var(--text-secondary);
            cursor: pointer;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            transition: all 0.3s ease;
        }
        .modal-close:hover {
            background: var(--hover-bg);
            color: var(--primary-sea-blue);
        }
        .modal-body {
            padding: 20px;
        }
        .export-options {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 20px;
        }
        .export-option {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: var(--bg-secondary);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }
        .export-option:hover {
            background: var(--hover-bg);
            border-color: var(--primary-sea-blue);
            transform: translateX(5px);
        }
        .export-option i:first-child {
            font-size: 1.5rem;
            color: var(--primary-sea-blue);
        }
        .export-option i:last-child {
            margin-left: auto;
            color: var(--text-muted);
        }
        .option-content {
            flex: 1;
        }
        .option-content strong {
            display: block;
            color: var(--text-primary);
            margin-bottom: 2px;
        }
        .option-content span {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        .export-filters h4 {
            color: var(--text-secondary);
            margin-bottom: 10px;
            font-size: 0.95rem;
        }
        .filter-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }
        .filter-tag {
            background: var(--bg-secondary);
            color: var(--text-secondary);
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
        }
    `;
    document.head.appendChild(style);
}

function exportToPDF() {
    showNotification('Preparing PDF report... This may take a moment.', 'info');
    
    // Simulate PDF generation
    setTimeout(() => {
        showNotification('PDF report generated successfully!', 'success');
        // In production, this would trigger a download
        window.open('api/export_analytics.php?format=pdf&' + new URLSearchParams({
            class: document.getElementById('filter_class').value,
            subject: document.getElementById('filter_subject').value,
            student: document.getElementById('filter_student').value,
            type: document.getElementById('filter_type').value,
            date_from: document.getElementById('filter_date_from').value,
            date_to: document.getElementById('filter_date_to').value
        }), '_blank');
    }, 1500);
}

function exportToCSV() {
    showNotification('Exporting data to CSV...', 'info');
    
    // Trigger CSV download
    window.open('api/export_analytics.php?format=csv&' + new URLSearchParams({
        class: document.getElementById('filter_class').value,
        subject: document.getElementById('filter_subject').value,
        student: document.getElementById('filter_student').value,
        type: document.getElementById('filter_type').value,
        date_from: document.getElementById('filter_date_from').value,
        date_to: document.getElementById('filter_date_to').value
    }), '_blank');
}

function exportToExcel() {
    showNotification('Generating Excel report...', 'info');
    
    // Trigger Excel download
    window.open('api/export_analytics.php?format=excel&' + new URLSearchParams({
        class: document.getElementById('filter_class').value,
        subject: document.getElementById('filter_subject').value,
        student: document.getElementById('filter_student').value,
        type: document.getElementById('filter_type').value,
        date_from: document.getElementById('filter_date_from').value,
        date_to: document.getElementById('filter_date_to').value
    }), '_blank');
}

// ============================================
// ASSESSMENT FUNCTIONS
// ============================================

function viewAssessment(assessmentId) {
    window.location.href = `diagnostic_view.php?id=${assessmentId}`;
}

function editAssessment(assessmentId) {
    window.location.href = `diagnostic_edit.php?id=${assessmentId}`;
}

function generateReport(assessmentId) {
    showNotification('Generating detailed report...', 'info');
    
    setTimeout(() => {
        window.open(`api/generate_assessment_report.php?id=${assessmentId}`, '_blank');
        showNotification('Report generated successfully!', 'success');
    }, 1000);
}

function deleteAssessment(assessmentId, assessmentTitle) {
    if (confirm(`Are you sure you want to delete the assessment "${assessmentTitle}"? This action cannot be undone.`)) {
        // Show loading state
        showNotification('Deleting assessment...', 'info');
        
        // In production, use AJAX to delete
        fetch(`api/delete_assessment.php?id=${assessmentId}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-Token': getCsrfToken()
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Assessment deleted successfully!', 'success');
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showNotification('Failed to delete assessment: ' + data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Failed to delete assessment. Please try again.', 'error');
        });
    }
}

function createInterventionPlan(studentId) {
    window.location.href = `intervention_plan.php?student_id=${studentId}`;
}

function contactParent(studentId) {
    window.open(`chat.php?student_id=${studentId}`, '_blank');
}

function scheduleReassessment(studentId) {
    const assessmentDate = prompt('Enter reassessment date (YYYY-MM-DD):', new Date().toISOString().split('T')[0]);
    if (assessmentDate) {
        showNotification('Scheduling reassessment...', 'info');
        
        fetch('api/schedule_reassessment.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getCsrfToken()
            },
            body: JSON.stringify({
                student_id: studentId,
                assessment_date: assessmentDate
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Reassessment scheduled successfully!', 'success');
            } else {
                showNotification('Failed to schedule reassessment: ' + data.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showNotification('Failed to schedule reassessment. Please try again.', 'error');
        });
    }
}

// ============================================
// HELPER FUNCTIONS
// ============================================

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
}

function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <div class="notification-icon">
            <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
        </div>
        <div class="notification-content">${message}</div>
        <button class="notification-close" onclick="this.parentElement.remove()">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    // Add to page
    document.body.appendChild(notification);
    
    // Add styles if not already added
    if (!document.querySelector('#notification-styles')) {
        const style = document.createElement('style');
        style.id = 'notification-styles';
        style.textContent = `
            .notification {
                position: fixed;
                top: 20px;
                right: 20px;
                background: var(--card-bg);
                border-left: 4px solid var(--primary-sea-blue);
                border-radius: 8px;
                padding: 15px 20px;
                display: flex;
                align-items: center;
                gap: 15px;
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
                z-index: 9999;
                animation: slideIn 0.3s ease, fadeOut 0.3s ease 4.7s forwards;
                max-width: 400px;
            }
            .notification-success {
                border-left-color: #28a745;
            }
            .notification-error {
                border-left-color: #dc3545;
            }
            .notification-info {
                border-left-color: var(--primary-sea-blue);
            }
            .notification-icon {
                font-size: 1.2rem;
            }
            .notification-success .notification-icon {
                color: #28a745;
            }
            .notification-error .notification-icon {
                color: #dc3545;
            }
            .notification-info .notification-icon {
                color: var(--primary-sea-blue);
            }
            .notification-content {
                flex: 1;
                color: var(--text-primary);
                font-size: 0.95rem;
            }
            .notification-close {
                background: none;
                border: none;
                color: var(--text-secondary);
                cursor: pointer;
                font-size: 0.9rem;
                width: 24px;
                height: 24px;
                display: flex;
                align-items: center;
                justify-content: center;
                border-radius: 50%;
                transition: all 0.3s ease;
            }
            .notification-close:hover {
                background: var(--hover-bg);
                color: var(--primary-sea-blue);
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
            @keyframes fadeOut {
                from {
                    opacity: 1;
                }
                to {
                    opacity: 0;
                }
            }
        `;
        document.head.appendChild(style);
    }
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        if (notification.parentElement) {
            notification.remove();
        }
    }, 5000);
}

// ============================================
// PUSH NOTIFICATIONS SETUP
// ============================================

function setupPushNotifications() {
    if (!('Notification' in window)) {
        console.log('This browser does not support notifications');
        return;
    }
    
    if (Notification.permission === 'granted') {
        console.log('Notifications already granted');
        registerServiceWorker();
    } else if (Notification.permission !== 'denied') {
        Notification.requestPermission().then(permission => {
            if (permission === 'granted') {
                console.log('Notification permission granted');
                registerServiceWorker();
                showNotification('Notifications enabled! You will receive updates on assessment results.', 'success');
            }
        });
    }
}

function registerServiceWorker() {
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('../../sw.js')
            .then(registration => {
                console.log('ServiceWorker registration successful:', registration);
                subscribeToPushNotifications(registration);
            })
            .catch(error => {
                console.error('ServiceWorker registration failed:', error);
            });
    }
}

function subscribeToPushNotifications(registration) {
    registration.pushManager.getSubscription()
        .then(subscription => {
            if (subscription) {
                console.log('Already subscribed to push notifications');
                return subscription;
            }
            
            // Get VAPID public key from server
            return fetch('api/get_push_key.php')
                .then(response => response.json())
                .then(data => {
                    return registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: urlBase64ToUint8Array(data.publicKey)
                    });
                })
                .then(subscription => {
                    // Send subscription to server
                    return fetch('api/save_subscription.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': getCsrfToken()
                        },
                        body: JSON.stringify(subscription)
                    });
                });
        })
        .catch(error => {
            console.error('Error subscribing to push notifications:', error);
        });
}

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding)
        .replace(/\-/g, '+')
        .replace(/_/g, '/');
    
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);
    
    for (let i = 0; i < rawData.length; ++i) {
        outputArray[i] = rawData.charCodeAt(i);
    }
    return outputArray;
}

// ============================================
// INITIALIZATION
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    // Initialize charts
    initializeCharts();
    
    // Setup push notifications
    setupPushNotifications();
    
    // Update chart colors when theme changes
    const observer = new MutationObserver(updateChartColors);
    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['class']
    });
    
    // Make table responsive
    makeTableResponsive();
    
    // Initialize tooltips
    initializeTooltips();
});

function makeTableResponsive() {
    const table = document.querySelector('.assessments-table');
    if (!table || window.innerWidth > 768) return;
    
    const headers = [];
    table.querySelectorAll('thead th').forEach(header => {
        headers.push(header.textContent);
    });
    
    table.querySelectorAll('tbody tr').forEach(row => {
        row.querySelectorAll('td').forEach((cell, index) => {
            cell.setAttribute('data-label', headers[index]);
        });
    });
}

function initializeTooltips() {
    // Initialize tooltips for action buttons
    const tooltipElements = document.querySelectorAll('.action-btn, .intervention-btn, .filter-btn');
    
    tooltipElements.forEach(element => {
        element.addEventListener('mouseenter', function(e) {
            const tooltip = document.createElement('div');
            tooltip.className = 'tooltip';
            tooltip.textContent = this.getAttribute('title') || 
                                 this.querySelector('i').className.includes('fa-eye') ? 'View Details' :
                                 this.querySelector('i').className.includes('fa-edit') ? 'Edit' :
                                 this.querySelector('i').className.includes('fa-file-pdf') ? 'Generate Report' :
                                 this.querySelector('i').className.includes('fa-trash') ? 'Delete' :
                                 this.querySelector('i').className.includes('fa-clipboard-check') ? 'Create Plan' :
                                 this.querySelector('i').className.includes('fa-comment') ? 'Contact Parent' :
                                 this.querySelector('i').className.includes('fa-redo') ? 'Schedule Reassessment' :
                                 this.querySelector('i').className.includes('fa-search') ? 'Apply Filters' :
                                 this.querySelector('i').className.includes('fa-redo') ? 'Reset Filters' :
                                 this.querySelector('i').className.includes('fa-download') ? 'Export Data' :
                                 this.querySelector('i').className.includes('fa-plus-circle') ? 'New Assessment' : '';
            
            document.body.appendChild(tooltip);
            
            const rect = this.getBoundingClientRect();
            tooltip.style.left = rect.left + rect.width / 2 - tooltip.offsetWidth / 2 + 'px';
            tooltip.style.top = rect.top - tooltip.offsetHeight - 10 + 'px';
            
            this.tooltip = tooltip;
        });
        
        element.addEventListener('mouseleave', function() {
            if (this.tooltip) {
                this.tooltip.remove();
                this.tooltip = null;
            }
        });
    });
    
    // Add tooltip styles
    const style = document.createElement('style');
    style.textContent = `
        .tooltip {
            position: fixed;
            background: var(--primary-black);
            color: white;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.8rem;
            z-index: 10000;
            pointer-events: none;
            white-space: nowrap;
            opacity: 0.9;
        }
        .tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            margin-left: -5px;
            border-width: 5px;
            border-style: solid;
            border-color: var(--primary-black) transparent transparent transparent;
        }
    `;
    document.head.appendChild(style);
}

// ============================================
// REAL-TIME UPDATES (WebSocket)
// ============================================

function setupRealTimeUpdates() {
    // Check if WebSocket is supported
    if ('WebSocket' in window) {
        const protocol = window.location.protocol === 'https:' ? 'wss://' : 'ws://';
        const ws = new WebSocket(protocol + window.location.host + '/ws/analytics');
        
        ws.onopen = function() {
            console.log('WebSocket connection established');
            // Subscribe to updates
            ws.send(JSON.stringify({
                type: 'subscribe',
                teacher_id: <?php echo $teacher_id; ?>,
                channel: 'analytics_updates'
            }));
        };
        
        ws.onmessage = function(event) {
            const data = JSON.parse(event.data);
            
            switch (data.type) {
                case 'new_assessment':
                    showNotification(`New assessment submitted: ${data.assessment_title}`, 'info');
                    // Refresh data
                    setTimeout(() => {
                        window.location.reload();
                    }, 3000);
                    break;
                    
                case 'assessment_graded':
                    showNotification(`Assessment graded: ${data.student_name} - ${data.score}%`, 'success');
                    break;
                    
                case 'intervention_alert':
                    showNotification(`Intervention needed: ${data.student_name}`, 'error');
                    break;
            }
        };
        
        ws.onclose = function() {
            console.log('WebSocket connection closed');
            // Try to reconnect after 5 seconds
            setTimeout(setupRealTimeUpdates, 5000);
        };
        
        ws.onerror = function(error) {
            console.error('WebSocket error:', error);
        };
    }
}

// Start real-time updates
if (window.location.protocol !== 'file:') {
    setupRealTimeUpdates();
}

// ============================================
// THEME SYNC WITH HEADER
// ============================================

// Sync theme with header
function syncTheme() {
    const isDarkMode = document.documentElement.classList.contains('dark-mode');
    const themeToggle = document.getElementById('themeToggle');
    
    if (themeToggle) {
        const iconMoon = themeToggle.querySelector('.fa-moon');
        const iconSun = themeToggle.querySelector('.fa-sun');
        
        if (isDarkMode) {
            if (iconMoon) iconMoon.style.display = 'none';
            if (iconSun) iconSun.style.display = 'inline-block';
        } else {
            if (iconMoon) iconMoon.style.display = 'inline-block';
            if (iconSun) iconSun.style.display = 'none';
        }
    }
}

// Listen for theme changes from header
window.addEventListener('themeChanged', syncTheme);

// Initial sync
syncTheme();

// ============================================
// PERFORMANCE OPTIMIZATIONS
// ============================================

// Debounce resize handler
let resizeTimeout;
window.addEventListener('resize', function() {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(() => {
        makeTableResponsive();
        updateChartColors();
    }, 250);
});

// Lazy load images
document.addEventListener('DOMContentLoaded', function() {
    const images = document.querySelectorAll('img[data-src]');
    
    const imageObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                const img = entry.target;
                img.src = img.getAttribute('data-src');
                img.removeAttribute('data-src');
                observer.unobserve(img);
            }
        });
    });
    
    images.forEach(img => imageObserver.observe(img));
});

// Memory cleanup
window.addEventListener('beforeunload', function() {
    if (scoreTrendChart) scoreTrendChart.destroy();
    if (competencyRadarChart) competencyRadarChart.destroy();
});
</script>

<?php 
// Include footer
include $root_path . 'templates/teacher_footer.php';
?>