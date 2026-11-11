<?php
// teacher/lesson_plans.php - NCCA/GES Lesson Plans Management
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

// Initialize variables
$teacher_id = $_SESSION['user_id'];
$current_date = date('Y-m-d');
$message = '';
$error = '';

try {
    $db = db();
    if (!$db) {
        throw new Exception("Database connection failed");
    }
    
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
    
    // Get teacher's classes
    $teacher_classes = [];
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT c.id, c.class_name, d.name as department_name
            FROM classes c
            LEFT JOIN departments d ON c.department_id = d.id
            WHERE c.teacher_id = ? OR c.class_teacher_id = ?
            ORDER BY c.class_name
        ");
        $stmt->execute([$teacher_id, $teacher_id]);
        $teacher_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Classes query error: " . $e->getMessage());
    }
    
    // Get subjects
    $subjects = [];
    try {
        $stmt = $db->prepare("
            SELECT id, subject_name, subject_code
            FROM subjects
            WHERE teacher_id = ? OR department_id IN (
                SELECT department_id FROM classes WHERE teacher_id = ? OR class_teacher_id = ?
            )
            ORDER BY subject_name
        ");
        $stmt->execute([$teacher_id, $teacher_id, $teacher_id]);
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Subjects query error: " . $e->getMessage());
    }
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'delete_lesson':
                    $lesson_id = $_POST['lesson_id'] ?? null;
                    if ($lesson_id) {
                        try {
                            // Delete from lesson_plans
                            $stmt = $db->prepare("DELETE FROM lesson_plans WHERE id = ? AND teacher_id = ?");
                            $stmt->execute([$lesson_id, $teacher_id]);
                            
                            // Delete from teacher_notes if exists
                            $stmt = $db->prepare("DELETE FROM teacher_notes WHERE teacher_id = ? AND lesson_plan_id = ?");
                            $stmt->execute([$teacher_id, $lesson_id]);
                            
                            $message = "Lesson plan deleted successfully!";
                        } catch (Exception $e) {
                            $error = "Error deleting lesson plan: " . $e->getMessage();
                        }
                    }
                    break;
                    
                case 'duplicate_lesson':
                    $lesson_id = $_POST['lesson_id'] ?? null;
                    if ($lesson_id) {
                        try {
                            // Get original lesson
                            $stmt = $db->prepare("
                                SELECT * FROM lesson_plans 
                                WHERE id = ? AND teacher_id = ?
                            ");
                            $stmt->execute([$lesson_id, $teacher_id]);
                            $original = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($original) {
                                // Create new lesson plan
                                $stmt = $db->prepare("
                                    INSERT INTO lesson_plans 
                                    (teacher_id, class_id, subject_id, term_id, topic, 
                                     objectives, lesson_date, resources, status, created_at, updated_at)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW(), NOW())
                                ");
                                
                                $new_topic = $original['topic'] . " (Copy)";
                                $stmt->execute([
                                    $teacher_id,
                                    $original['class_id'],
                                    $original['subject_id'],
                                    $original['term_id'],
                                    $new_topic,
                                    $original['objectives'],
                                    $original['lesson_date'],
                                    $original['resources'],
                                ]);
                                
                                $new_lesson_id = $db->lastInsertId();
                                
                                // Duplicate teacher notes if they exist
                                $stmt = $db->prepare("
                                    SELECT * FROM teacher_notes 
                                    WHERE teacher_id = ? AND lesson_plan_id = ?
                                ");
                                $stmt->execute([$teacher_id, $lesson_id]);
                                $original_notes = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if ($original_notes) {
                                    $stmt = $db->prepare("
                                        INSERT INTO teacher_notes 
                                        (teacher_id, lesson_plan_id, class_id, term_id, subject_id,
                                         week, strand, sub_strand, content_standard, indicator,
                                         objectives, reference, resources, keywords, starter,
                                         presentation, summary, format, unit, topic)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                    ");
                                    
                                    $stmt->execute([
                                        $teacher_id,
                                        $new_lesson_id,
                                        $original_notes['class_id'],
                                        $original_notes['term_id'],
                                        $original_notes['subject_id'],
                                        $original_notes['week'],
                                        $original_notes['strand'],
                                        $original_notes['sub_strand'],
                                        $original_notes['content_standard'],
                                        $original_notes['indicator'],
                                        $original_notes['objectives'],
                                        $original_notes['reference'],
                                        $original_notes['resources'],
                                        $original_notes['keywords'],
                                        $original_notes['starter'],
                                        $original_notes['presentation'],
                                        $original_notes['summary'],
                                        $original_notes['format'],
                                        $original_notes['unit'],
                                        $original_notes['topic']
                                    ]);
                                }
                                
                                $message = "Lesson plan duplicated successfully!";
                                header("Location: lesson_plan_edit.php?id=" . $new_lesson_id);
                                exit();
                            }
                        } catch (Exception $e) {
                            $error = "Error duplicating lesson plan: " . $e->getMessage();
                        }
                    }
                    break;
            }
        }
    }
    
    // Get lesson plans with NCCA details
    $lesson_plans = [];
    try {
        $stmt = $db->prepare("
            SELECT lp.*, 
                   c.class_name,
                   s.subject_name,
                   s.subject_code,
                   t.name as term_name,
                   tn.strand,
                   tn.sub_strand,
                   tn.week,
                   tn.format
            FROM lesson_plans lp
            LEFT JOIN classes c ON lp.class_id = c.id
            LEFT JOIN subjects s ON lp.subject_id = s.id
            LEFT JOIN terms t ON lp.term_id = t.id
            LEFT JOIN teacher_notes tn ON lp.id = tn.lesson_plan_id
            WHERE lp.teacher_id = ?
            ORDER BY lp.lesson_date DESC, lp.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$teacher_id]);
        $lesson_plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Lesson plans query error: " . $e->getMessage());
    }
    
    // Get upcoming lessons (next 7 days)
    $upcoming_lessons = [];
    try {
        $stmt = $db->prepare("
            SELECT lp.*, c.class_name, s.subject_name
            FROM lesson_plans lp
            LEFT JOIN classes c ON lp.class_id = c.id
            LEFT JOIN subjects s ON lp.subject_id = s.id
            WHERE lp.teacher_id = ? 
            AND lp.lesson_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            ORDER BY lp.lesson_date ASC
            LIMIT 10
        ");
        $stmt->execute([$teacher_id]);
        $upcoming_lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Upcoming lessons query error: " . $e->getMessage());
    }
    
    // Get weekly lesson counts for stats
    $weekly_stats = [];
    try {
        $stmt = $db->prepare("
            SELECT 
                DATE_FORMAT(lesson_date, '%Y-%u') as week_number,
                COUNT(*) as lesson_count,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published_count
            FROM lesson_plans 
            WHERE teacher_id = ? 
            AND lesson_date >= DATE_SUB(CURDATE(), INTERVAL 4 WEEK)
            GROUP BY DATE_FORMAT(lesson_date, '%Y-%u')
            ORDER BY week_number DESC
            LIMIT 4
        ");
        $stmt->execute([$teacher_id]);
        $weekly_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Weekly stats query error: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    error_log("Lesson plans page error: " . $e->getMessage());
    $error = "An error occurred while loading lesson plans. Please try again.";
}

// Include header
include $root_path . 'templates/teacher_header.php';
?>

<!-- Lesson Plans Content -->
<section class="dashboard">
    <!-- Page Header -->
    <div class="dashboard-header">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <h1 class="page-title">NCCA/GES Lesson Plans</h1>
                <div class="dashboard-subtitle">
                    <span class="welcome">Create and manage your lesson plans according to NCCA/GES standards</span>
                    <?php if ($current_term): ?>
                        <span class="academic-info">
                            <i class="fas fa-calendar"></i> Term: <?php echo htmlspecialchars($current_term['name']); ?> | 
                            Academic Year: <?php echo htmlspecialchars($current_term['year']); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div>
                <a href="lesson_plan_edit.php" class="btn btn-lg btn-primary">
                    <i class="fas fa-plus-circle"></i> New Lesson Plan
                </a>
            </div>
        </div>
    </div>
    
    <!-- Messages -->
    <?php if ($message): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    
    <!-- Stats Cards -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-icon">
                <i class="fas fa-book-open"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value"><?php echo count($lesson_plans); ?></div>
                <div class="kpi-label">Total Lessons</div>
            </div>
        </div>
        
        <div class="kpi-card success">
            <div class="kpi-icon">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="kpi-content">
                <?php 
                $published_count = 0;
                foreach ($lesson_plans as $lesson) {
                    if ($lesson['status'] === 'published') {
                        $published_count++;
                    }
                }
                ?>
                <div class="kpi-value"><?php echo $published_count; ?></div>
                <div class="kpi-label">Published</div>
            </div>
        </div>
        
        <div class="kpi-card warning">
            <div class="kpi-icon">
                <i class="fas fa-pen"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value"><?php echo count($lesson_plans) - $published_count; ?></div>
                <div class="kpi-label">Drafts</div>
            </div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-icon">
                <i class="fas fa-calendar-day"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value"><?php echo count($upcoming_lessons); ?></div>
                <div class="kpi-label">Upcoming (7 days)</div>
            </div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-icon">
                <i class="fas fa-layer-group"></i>
            </div>
            <div class="kpi-content">
                <?php 
                $ncca_formatted = 0;
                foreach ($lesson_plans as $lesson) {
                    if (!empty($lesson['format'])) {
                        $ncca_formatted++;
                    }
                }
                ?>
                <div class="kpi-value"><?php echo $ncca_formatted; ?></div>
                <div class="kpi-label">NCCA Format</div>
            </div>
        </div>
        
        <div class="kpi-card">
            <div class="kpi-icon">
                <i class="fas fa-chart-line"></i>
            </div>
            <div class="kpi-content">
                <div class="kpi-value">
                    <?php 
                    $avg_week = 0;
                    if (!empty($weekly_stats)) {
                        $total = 0;
                        foreach ($weekly_stats as $week) {
                            $total += $week['lesson_count'];
                        }
                        $avg_week = round($total / count($weekly_stats));
                    }
                    echo $avg_week;
                    ?>
                </div>
                <div class="kpi-label">Avg/Week</div>
            </div>
        </div>
    </div>
    
    <!-- Main Content Area -->
    <div class="dashboard-section">
        <div class="two-column-grid">
            <!-- Upcoming Lessons -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-calendar-week"></i> Upcoming Lessons
                    </h3>
                    <div class="card-tools">
                        <a href="calendar.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-calendar-alt"></i> View Calendar
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="upcoming-lessons-list">
                        <?php if (empty($upcoming_lessons)): ?>
                            <div class="empty-state">
                                <i class="fas fa-calendar-times"></i>
                                <p>No upcoming lessons in the next 7 days</p>
                                <a href="lesson_plan_edit.php" class="btn btn-sm btn-primary">
                                    Create Your First Lesson
                                </a>
                            </div>
                        <?php else: ?>
                            <?php foreach ($upcoming_lessons as $lesson): ?>
                                <div class="upcoming-lesson-item <?php echo $lesson['status'] === 'published' ? 'published' : 'draft'; ?>">
                                    <div class="lesson-date">
                                        <div class="day"><?php echo date('D', strtotime($lesson['lesson_date'])); ?></div>
                                        <div class="date"><?php echo date('j', strtotime($lesson['lesson_date'])); ?></div>
                                        <div class="month"><?php echo date('M', strtotime($lesson['lesson_date'])); ?></div>
                                    </div>
                                    <div class="lesson-details">
                                        <h5><?php echo htmlspecialchars($lesson['topic']); ?></h5>
                                        <p class="text-muted">
                                            <?php echo htmlspecialchars($lesson['class_name']); ?> • 
                                            <?php echo htmlspecialchars($lesson['subject_name']); ?>
                                        </p>
                                        <div class="lesson-tags">
                                            <span class="tag tag-<?php echo $lesson['status']; ?>">
                                                <?php echo ucfirst($lesson['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="lesson-actions">
                                        <a href="lesson_plan_edit.php?id=<?php echo $lesson['id']; ?>" 
                                           class="btn btn-sm btn-primary">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <a href="lesson_plan_view.php?id=<?php echo $lesson['id']; ?>" 
                                           target="_blank" class="btn btn-sm btn-outline-primary">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if (!empty($upcoming_lessons)): ?>
                    <div class="card-footer">
                        <a href="lesson_plans.php?view=upcoming" class="btn btn-sm btn-link">
                            View All Upcoming <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Quick Templates -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-magic"></i> Quick Templates
                    </h3>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">Start with a pre-designed NCCA/GES template:</p>
                    <div class="templates-grid">
                        <a href="lesson_plan_edit.php?template=basic" class="template-card">
                            <div class="template-icon">
                                <i class="fas fa-file-alt"></i>
                            </div>
                            <div class="template-content">
                                <h5>Basic Lesson Plan</h5>
                                <p>Simple format with all essential sections</p>
                            </div>
                        </a>
                        
                        <a href="lesson_plan_edit.php?template=nacca_full" class="template-card">
                            <div class="template-icon">
                                <i class="fas fa-graduation-cap"></i>
                            </div>
                            <div class="template-content">
                                <h5>Full NCCA Format</h5>
                                <p>Complete with strands, indicators, and standards</p>
                            </div>
                        </a>
                        
                        <a href="lesson_plan_edit.php?template=ges_primary" class="template-card">
                            <div class="template-icon">
                                <i class="fas fa-school"></i>
                            </div>
                            <div class="template-content">
                                <h5>GES Primary</h5>
                                <p>Designed for primary school curriculum</p>
                            </div>
                        </a>
                        
                        <a href="lesson_plan_edit.php?template=assessment" class="template-card">
                            <div class="template-icon">
                                <i class="fas fa-clipboard-check"></i>
                            </div>
                            <div class="template-content">
                                <h5>Assessment Focus</h5>
                                <p>Focus on evaluation and feedback</p>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- All Lesson Plans -->
    <div class="dashboard-section">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-list"></i> All Lesson Plans
                </h3>
                <div class="card-tools">
                    <div class="input-group input-group-sm" style="width: 200px;">
                        <input type="text" id="searchLessons" class="form-control" placeholder="Search lessons...">
                        <button class="btn btn-outline-secondary" type="button">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="card-body">
                <!-- Filters -->
                <div class="filters-bar">
                    <div class="filter-group">
                        <label>Filter by:</label>
                        <select id="filterStatus" class="form-select form-select-sm">
                            <option value="">All Status</option>
                            <option value="published">Published</option>
                            <option value="draft">Draft</option>
                        </select>
                        <select id="filterClass" class="form-select form-select-sm">
                            <option value="">All Classes</option>
                            <?php foreach ($teacher_classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <select id="filterSubject" class="form-select form-select-sm">
                            <option value="">All Subjects</option>
                            <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" id="filterDate" class="form-control form-control-sm" 
                               placeholder="Filter by date">
                    </div>
                    <div class="filter-actions">
                        <button id="clearFilters" class="btn btn-sm btn-outline-secondary">
                            <i class="fas fa-times"></i> Clear
                        </button>
                        <button id="exportLessons" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                
                <!-- Lessons Table -->
                <div class="table-responsive">
                    <table class="table table-hover table-striped">
                        <thead>
                            <tr>
                                <th width="120">Date</th>
                                <th>Topic</th>
                                <th width="150">Class</th>
                                <th width="150">Subject</th>
                                <th width="120">Strand</th>
                                <th width="80">Status</th>
                                <th width="100">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="lessonsTableBody">
                            <?php if (empty($lesson_plans)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4">
                                        <div class="empty-state">
                                            <i class="fas fa-book-open fa-2x mb-3 text-muted"></i>
                                            <h5>No lesson plans yet</h5>
                                            <p class="text-muted">Create your first lesson plan to get started</p>
                                            <a href="lesson_plan_edit.php" class="btn btn-primary">
                                                <i class="fas fa-plus"></i> Create Lesson Plan
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($lesson_plans as $lesson): ?>
                                    <tr class="lesson-row" 
                                        data-id="<?php echo $lesson['id']; ?>"
                                        data-status="<?php echo $lesson['status']; ?>"
                                        data-class="<?php echo $lesson['class_id']; ?>"
                                        data-subject="<?php echo $lesson['subject_id']; ?>"
                                        data-date="<?php echo $lesson['lesson_date']; ?>"
                                        data-topic="<?php echo htmlspecialchars($lesson['topic']); ?>">
                                        <td>
                                            <div class="date-cell">
                                                <div class="date-day"><?php echo date('D', strtotime($lesson['lesson_date'])); ?></div>
                                                <div class="date-full"><?php echo date('M j, Y', strtotime($lesson['lesson_date'])); ?></div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="lesson-title">
                                                <strong><?php echo htmlspecialchars($lesson['topic']); ?></strong>
                                                <?php if ($lesson['week']): ?>
                                                    <span class="badge bg-info">Week <?php echo $lesson['week']; ?></span>
                                                <?php endif; ?>
                                                <?php if ($lesson['strand']): ?>
                                                    <small class="d-block text-muted"><?php echo htmlspecialchars($lesson['strand']); ?></small>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?php echo htmlspecialchars($lesson['class_name']); ?></td>
                                        <td><?php echo htmlspecialchars($lesson['subject_name']); ?></td>
                                        <td>
                                            <?php if ($lesson['strand']): ?>
                                                <span class="badge bg-light text-dark"><?php echo htmlspecialchars($lesson['strand']); ?></span>
                                                <?php if ($lesson['sub_strand']): ?>
                                                    <small class="d-block text-muted"><?php echo htmlspecialchars($lesson['sub_strand']); ?></small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $lesson['status']; ?>">
                                                <?php echo ucfirst($lesson['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <a href="lesson_plan_edit.php?id=<?php echo $lesson['id']; ?>" 
                                                   class="btn btn-sm btn-outline-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="lesson_plan_view.php?id=<?php echo $lesson['id']; ?>" 
                                                   target="_blank" class="btn btn-sm btn-outline-info" title="Preview">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <div class="btn-group" role="group">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary dropdown-toggle" 
                                                            data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fas fa-ellipsis-v"></i>
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <li>
                                                            <form method="POST" action="" class="d-inline">
                                                                <input type="hidden" name="action" value="duplicate_lesson">
                                                                <input type="hidden" name="lesson_id" value="<?php echo $lesson['id']; ?>">
                                                                <button type="submit" class="dropdown-item">
                                                                    <i class="fas fa-copy"></i> Duplicate
                                                                </button>
                                                            </form>
                                                        </li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <form method="POST" action="" 
                                                                  onsubmit="return confirm('Are you sure you want to delete this lesson plan? This action cannot be undone.');">
                                                                <input type="hidden" name="action" value="delete_lesson">
                                                                <input type="hidden" name="lesson_id" value="<?php echo $lesson['id']; ?>">
                                                                <button type="submit" class="dropdown-item text-danger">
                                                                    <i class="fas fa-trash"></i> Delete
                                                                </button>
                                                            </form>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="card-footer">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <span class="text-muted">
                            Showing <?php echo count($lesson_plans); ?> of <?php echo count($lesson_plans); ?> lesson plans
                        </span>
                    </div>
                    <div>
                        <nav>
                            <ul class="pagination pagination-sm mb-0">
                                <li class="page-item disabled"><a class="page-link" href="#">Previous</a></li>
                                <li class="page-item active"><a class="page-link" href="#">1</a></li>
                                <li class="page-item"><a class="page-link" href="#">2</a></li>
                                <li class="page-item"><a class="page-link" href="#">3</a></li>
                                <li class="page-item"><a class="page-link" href="#">Next</a></li>
                            </ul>
                        </nav>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Weekly Statistics -->
    <div class="dashboard-section">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-chart-line"></i> Weekly Statistics
                </h3>
            </div>
            <div class="card-body">
                <div class="stats-grid">
                    <?php if (empty($weekly_stats)): ?>
                        <div class="empty-state">
                            <i class="fas fa-chart-bar fa-2x mb-3 text-muted"></i>
                            <p class="text-muted">No lesson data for the past 4 weeks</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($weekly_stats as $stat): ?>
                            <div class="stat-card">
                                <div class="stat-header">
                                    <h5>Week <?php echo substr($stat['week_number'], 4); ?></h5>
                                    <span class="stat-date">
                                        <?php 
                                        // Convert week number to approximate date
                                        $year = substr($stat['week_number'], 0, 4);
                                        $week = substr($stat['week_number'], 4);
                                        $date = new DateTime();
                                        $date->setISODate($year, $week);
                                        echo $date->format('M j') . ' - ' . $date->modify('+6 days')->format('M j');
                                        ?>
                                    </span>
                                </div>
                                <div class="stat-body">
                                    <div class="stat-value"><?php echo $stat['lesson_count']; ?></div>
                                    <div class="stat-label">Total Lessons</div>
                                    <div class="stat-progress">
                                        <div class="progress">
                                            <?php 
                                            $percentage = $stat['lesson_count'] > 0 ? 
                                                ($stat['published_count'] / $stat['lesson_count']) * 100 : 0;
                                            ?>
                                            <div class="progress-bar bg-success" style="width: <?php echo $percentage; ?>%">
                                                <?php echo $stat['published_count']; ?> published
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Filter functionality
    const filterStatus = document.getElementById('filterStatus');
    const filterClass = document.getElementById('filterClass');
    const filterSubject = document.getElementById('filterSubject');
    const filterDate = document.getElementById('filterDate');
    const searchInput = document.getElementById('searchLessons');
    const clearFiltersBtn = document.getElementById('clearFilters');
    const lessonRows = document.querySelectorAll('.lesson-row');
    
    function filterLessons() {
        const statusFilter = filterStatus.value;
        const classFilter = filterClass.value;
        const subjectFilter = filterSubject.value;
        const dateFilter = filterDate.value;
        const searchFilter = searchInput.value.toLowerCase();
        
        let visibleCount = 0;
        
        lessonRows.forEach(row => {
            let show = true;
            
            // Apply filters
            if (statusFilter && row.dataset.status !== statusFilter) {
                show = false;
            }
            
            if (classFilter && row.dataset.class !== classFilter) {
                show = false;
            }
            
            if (subjectFilter && row.dataset.subject !== subjectFilter) {
                show = false;
            }
            
            if (dateFilter && row.dataset.date !== dateFilter) {
                show = false;
            }
            
            // Apply search
            if (searchFilter && !row.dataset.topic.toLowerCase().includes(searchFilter)) {
                show = false;
            }
            
            row.style.display = show ? '' : 'none';
            if (show) visibleCount++;
        });
        
        // Show/hide empty state
        const emptyRow = document.querySelector('#lessonsTableBody tr:first-child');
        if (emptyRow && emptyRow.cells.length === 1) {
            emptyRow.style.display = visibleCount === 0 ? '' : 'none';
        }
    }
    
    // Add event listeners
    [filterStatus, filterClass, filterSubject, filterDate].forEach(filter => {
        filter?.addEventListener('change', filterLessons);
    });
    
    searchInput?.addEventListener('input', filterLessons);
    
    clearFiltersBtn?.addEventListener('click', function() {
        filterStatus.value = '';
        filterClass.value = '';
        filterSubject.value = '';
        filterDate.value = '';
        searchInput.value = '';
        filterLessons();
    });
    
    // Export functionality
    document.getElementById('exportLessons')?.addEventListener('click', function() {
        exportLessonsToCSV();
    });
    
    function exportLessonsToCSV() {
        const rows = [];
        const headers = ['Date', 'Topic', 'Class', 'Subject', 'Strand', 'Sub-Strand', 'Week', 'Status'];
        
        // Add headers
        rows.push(headers.join(','));
        
        // Add visible rows
        document.querySelectorAll('.lesson-row').forEach(row => {
            if (row.style.display !== 'none') {
                const cells = row.cells;
                const rowData = [
                    row.dataset.date,
                    row.dataset.topic,
                    cells[2].textContent.trim(),
                    cells[3].textContent.trim(),
                    row.querySelector('.badge.bg-light')?.textContent.trim() || '',
                    row.querySelector('small.d-block.text-muted')?.textContent.trim() || '',
                    row.querySelector('.badge.bg-info')?.textContent.replace('Week ', '') || '',
                    row.dataset.status
                ].map(cell => `"${cell}"`);
                
                rows.push(rowData.join(','));
            }
        });
        
        // Create and download CSV
        const csvContent = rows.join('\n');
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = `lesson-plans-${new Date().toISOString().split('T')[0]}.csv`;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
        
        showToast('Lesson plans exported successfully!', 'success');
    }
    
    function showToast(message, type = 'info') {
        // Remove existing toasts
        document.querySelectorAll('.toast').forEach(toast => toast.remove());
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <div class="toast-icon">
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'}"></i>
            </div>
            <div class="toast-message">${message}</div>
            <button class="toast-close">&times;</button>
        `;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.classList.add('show');
        }, 10);
        
        // Close button
        toast.querySelector('.toast-close').addEventListener('click', function() {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        });
        
        // Auto remove
        setTimeout(() => {
            if (toast.parentNode) {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 300);
            }
        }, 5000);
    }
    
    // Initialize filters
    filterLessons();
});

// Add toast styles
const toastStyle = document.createElement('style');
toastStyle.textContent = `
.toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    background: var(--card-bg);
    border-radius: 8px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.2);
    padding: 15px;
    display: flex;
    align-items: center;
    gap: 12px;
    z-index: 10000;
    transform: translateY(100px);
    opacity: 0;
    transition: all 0.3s ease;
    border-left: 4px solid;
    max-width: 350px;
}

.toast.show {
    transform: translateY(0);
    opacity: 1;
}

.toast-success {
    border-left-color: #28a745;
}

.toast-info {
    border-left-color: var(--primary-sea-blue);
}

.toast-warning {
    border-left-color: #ffc107;
}

.toast-danger {
    border-left-color: #dc3545;
}

.toast-icon {
    font-size: 1.2rem;
}

.toast-success .toast-icon {
    color: #28a745;
}

.toast-info .toast-icon {
    color: var(--primary-sea-blue);
}

.toast-warning .toast-icon {
    color: #ffc107;
}

.toast-danger .toast-icon {
    color: #dc3545;
}

.toast-message {
    flex: 1;
    font-size: 0.9rem;
    color: var(--text-primary);
}

.toast-close {
    background: none;
    border: none;
    color: var(--text-secondary);
    font-size: 1.2rem;
    cursor: pointer;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
}

.toast-close:hover {
    background: var(--bg-secondary);
    color: var(--text-primary);
}
`;
document.head.appendChild(toastStyle);
</script>

<!-- Additional CSS -->
<style>
/* Enhanced UI Styles matching dashboard */
.kpi-card.success {
    border-left-color: #28a745;
}

.kpi-card.success .kpi-icon {
    color: #28a745;
}

.kpi-card.warning {
    border-left-color: #ffc107;
}

.kpi-card.warning .kpi-icon {
    color: #ffc107;
}

/* Card Header */
.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    border-bottom: 1px solid var(--border-color);
    background: var(--card-bg);
}

.card-tools {
    display: flex;
    gap: 10px;
    align-items: center;
}

/* Upcoming Lessons */
.upcoming-lessons-list {
    max-height: 400px;
    overflow-y: auto;
}

.upcoming-lesson-item {
    display: flex;
    align-items: center;
    padding: 15px;
    margin-bottom: 10px;
    background: var(--bg-secondary);
    border-radius: 8px;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.upcoming-lesson-item:hover {
    transform: translateX(4px);
    border-color: var(--primary-sea-blue);
}

.upcoming-lesson-item.published {
    border-left: 4px solid #28a745;
}

.upcoming-lesson-item.draft {
    border-left: 4px solid #6c757d;
}

.lesson-date {
    text-align: center;
    min-width: 70px;
    margin-right: 15px;
}

.lesson-date .day {
    font-size: 0.8rem;
    text-transform: uppercase;
    color: var(--text-secondary);
}

.lesson-date .date {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary-sea-blue);
    line-height: 1;
}

.lesson-date .month {
    font-size: 0.8rem;
    text-transform: uppercase;
    color: var(--text-secondary);
}

.lesson-details {
    flex: 1;
}

.lesson-details h5 {
    margin: 0 0 5px 0;
    color: var(--text-primary);
    font-size: 1rem;
}

.lesson-tags {
    display: flex;
    gap: 5px;
    margin-top: 5px;
}

.tag {
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.tag-published {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
}

.tag-draft {
    background: rgba(108, 117, 125, 0.1);
    color: #6c757d;
}

.lesson-actions {
    display: flex;
    gap: 5px;
    opacity: 0;
    transition: opacity 0.3s ease;
}

.upcoming-lesson-item:hover .lesson-actions {
    opacity: 1;
}

/* Templates Grid */
.templates-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.template-card {
    display: flex;
    align-items: center;
    padding: 15px;
    background: var(--bg-secondary);
    border-radius: 8px;
    border: 1px solid var(--border-color);
    text-decoration: none;
    color: var(--text-primary);
    transition: all 0.3s ease;
}

.template-card:hover {
    background: var(--primary-sea-blue);
    color: white;
    border-color: var(--primary-sea-blue);
    transform: translateY(-2px);
}

.template-card:hover .template-icon {
    color: white;
}

.template-icon {
    font-size: 1.5rem;
    color: var(--primary-sea-blue);
    margin-right: 15px;
    transition: color 0.3s ease;
}

.template-content h5 {
    margin: 0 0 5px 0;
    font-size: 0.95rem;
}

.template-content p {
    margin: 0;
    font-size: 0.8rem;
    opacity: 0.8;
}

/* Filters Bar */
.filters-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    background: var(--bg-secondary);
    border-radius: 8px;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.filter-group label {
    font-weight: 600;
    color: var(--text-primary);
    margin-right: 5px;
}

.filter-actions {
    display: flex;
    gap: 10px;
}

/* Table Styles */
.table {
    width: 100%;
    color: var(--text-primary);
    border-collapse: collapse;
}

.table th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
    color: var(--text-secondary);
    border-bottom: 2px solid var(--border-color);
    padding: 12px 8px;
}

.table td {
    padding: 12px 8px;
    border-bottom: 1px solid var(--border-color);
    vertical-align: middle;
}

.table-striped tbody tr:nth-of-type(odd) {
    background-color: var(--bg-secondary);
}

.table-hover tbody tr:hover {
    background-color: rgba(23, 162, 184, 0.05);
}

.date-cell {
    display: flex;
    flex-direction: column;
}

.date-day {
    font-weight: 600;
    color: var(--text-secondary);
    font-size: 0.8rem;
    text-transform: uppercase;
}

.date-full {
    font-size: 0.85rem;
    color: var(--text-primary);
}

.lesson-title {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

/* Status Badges */
.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-published {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
}

.status-draft {
    background: rgba(108, 117, 125, 0.1);
    color: #6c757d;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.stat-card {
    background: var(--bg-secondary);
    border-radius: 8px;
    padding: 15px;
    border: 1px solid var(--border-color);
}

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.stat-header h5 {
    margin: 0;
    font-size: 0.9rem;
    color: var(--text-primary);
}

.stat-date {
    font-size: 0.75rem;
    color: var(--text-secondary);
}

.stat-body {
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--primary-sea-blue);
    line-height: 1;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 0.85rem;
    color: var(--text-secondary);
    margin-bottom: 10px;
}

.stat-progress {
    margin-top: 10px;
}

.progress {
    height: 6px;
    background-color: var(--border-color);
    border-radius: 3px;
    overflow: hidden;
}

.progress-bar {
    height: 100%;
    background-color: #28a745;
    font-size: 0.6rem;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    white-space: nowrap;
}

/* Empty States */
.empty-state {
    text-align: center;
    padding: 30px 20px;
}

.empty-state i {
    margin-bottom: 15px;
    color: var(--text-muted);
}

.empty-state h5 {
    margin-bottom: 10px;
    color: var(--text-primary);
}

.empty-state p {
    margin-bottom: 20px;
    color: var(--text-secondary);
}

/* Pagination */
.pagination {
    margin: 0;
}

.page-link {
    color: var(--text-primary);
    background-color: var(--card-bg);
    border-color: var(--border-color);
    padding: 0.375rem 0.75rem;
}

.page-link:hover {
    color: var(--primary-sea-blue);
    background-color: var(--bg-secondary);
    border-color: var(--border-color);
}

.page-item.active .page-link {
    background-color: var(--primary-sea-blue);
    border-color: var(--primary-sea-blue);
    color: white;
}

/* Alert Dismissible */
.alert-dismissible {
    padding-right: 3rem;
}

.btn-close {
    filter: invert(0.5);
}

/* Responsive Design */
@media (max-width: 768px) {
    .card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .card-tools {
        width: 100%;
    }
    
    .filters-bar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group label {
        margin-bottom: 5px;
    }
    
    .upcoming-lesson-item {
        flex-direction: column;
        align-items: stretch;
    }
    
    .lesson-date {
        margin-right: 0;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .lesson-date .day,
    .lesson-date .date,
    .lesson-date .month {
        display: inline-block;
    }
    
    .lesson-actions {
        margin-top: 10px;
        opacity: 1;
    }
    
    .templates-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<?php 
// Include footer
include $root_path . 'templates/teacher_footer.php';
?>