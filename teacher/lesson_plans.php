<?php
// teacher/lesson_plans.php - Complete Lesson Planning System
require_once dirname(__DIR__) . '/templates/teacher_header.php';

try {
    $db = db();
    
    // Get current term
    $term_stmt = $db->query("SELECT * FROM terms WHERE NOW() BETWEEN start_date AND end_date ORDER BY start_date DESC LIMIT 1");
    $current_term = $term_stmt->fetch(PDO::FETCH_ASSOC);
    $term_id = $current_term ? $current_term['id'] : null;
    
    // Get teacher's subjects
    $subject_stmt = $db->prepare("
        SELECT s.* FROM subjects s 
        LEFT JOIN classes c ON s.id = c.subject_id 
        WHERE c.teacher_id = ? OR c.class_teacher_id = ?
        GROUP BY s.id
        ORDER BY s.subject_name
    ");
    $subject_stmt->execute([$user_id, $user_id]);
    $teacher_subjects = $subject_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get teacher's classes
    $class_stmt = $db->prepare("
        SELECT c.*, d.name as department_name 
        FROM classes c 
        LEFT JOIN departments d ON c.department_id = d.id
        WHERE c.teacher_id = ? OR c.class_teacher_id = ?
        ORDER BY c.class_name
    ");
    $class_stmt->execute([$user_id, $user_id]);
    $teacher_classes = $class_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verify CSRF token
        require_once dirname(__DIR__) . '/functions/csrf.php';
        verify_csrf_token();
        
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create_lesson_plan') {
            // Create new lesson plan
            $subject_id = (int)$_POST['subject_id'];
            $class_id = (int)$_POST['class_id'];
            $topic = trim($_POST['topic']);
            $objectives = trim($_POST['objectives']);
            $lesson_date = $_POST['lesson_date'];
            $strand = $_POST['strand'] ?? '';
            $sub_strand = $_POST['sub_strand'] ?? '';
            $content_standard = $_POST['content_standard'] ?? '';
            $indicator = $_POST['indicator'] ?? '';
            $resources = $_POST['resources'] ?? '';
            $keywords = $_POST['keywords'] ?? '';
            $starter = $_POST['starter'] ?? '';
            $presentation = $_POST['presentation'] ?? '';
            $summary = $_POST['summary'] ?? '';
            $assessment = $_POST['assessment'] ?? '';
            $differentiation = $_POST['differentiation'] ?? '';
            $core_competencies = $_POST['core_competencies'] ?? '';
            
            $insert_stmt = $db->prepare("
                INSERT INTO lesson_plans 
                (teacher_id, subject_id, class_id, term_id, topic, objectives, lesson_date, 
                 strand, sub_strand, content_standard, indicator, resources, keywords, 
                 starter, presentation, summary, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', NOW(), NOW())
            ");
            
            $insert_stmt->execute([
                $user_id, $subject_id, $class_id, $term_id, $topic, $objectives, $lesson_date,
                $strand, $sub_strand, $content_standard, $indicator, $resources, $keywords,
                $starter, $presentation, $summary
            ]);
            
            $lesson_plan_id = $db->lastInsertId();
            
            // Also save to teacher_notes for GES format compatibility
            if (!empty($strand)) {
                $notes_stmt = $db->prepare("
                    INSERT INTO teacher_notes 
                    (teacher_id, class_id, subject_id, term_id, strand, sub_strand, 
                     content_standard, indicator, objectives, resources, keywords, 
                     starter, presentation, summary, reference, created_at, week, format)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'NaCCA Curriculum', NOW(), 
                            WEEK(?, 3), 'ges')
                ");
                
                $week_number = date('W', strtotime($lesson_date));
                $notes_stmt->execute([
                    $user_id, $class_id, $subject_id, $term_id, $strand, $sub_strand,
                    $content_standard, $indicator, $objectives, $resources, $keywords,
                    $starter, $presentation, $summary, $lesson_date
                ]);
            }
            
            // Log activity
            require_once dirname(__DIR__) . '/functions/log_activity.php';
            log_activity($user_id, "Created lesson plan: $topic for class ID: $class_id");
            
            $_SESSION['success'] = "Lesson plan created successfully!";
            header("Location: lesson_plans.php?view=" . $lesson_plan_id);
            exit();
            
        } elseif ($action === 'update_lesson_plan') {
            // Update existing lesson plan
            $lesson_plan_id = (int)$_POST['lesson_plan_id'];
            $topic = trim($_POST['topic']);
            $objectives = trim($_POST['objectives']);
            $lesson_date = $_POST['lesson_date'];
            $strand = $_POST['strand'] ?? '';
            $sub_strand = $_POST['sub_strand'] ?? '';
            $content_standard = $_POST['content_standard'] ?? '';
            $indicator = $_POST['indicator'] ?? '';
            $resources = $_POST['resources'] ?? '';
            $keywords = $_POST['keywords'] ?? '';
            $starter = $_POST['starter'] ?? '';
            $presentation = $_POST['presentation'] ?? '';
            $summary = $_POST['summary'] ?? '';
            $status = $_POST['status'] ?? 'draft';
            
            // Verify ownership
            $verify_stmt = $db->prepare("SELECT id FROM lesson_plans WHERE id = ? AND teacher_id = ?");
            $verify_stmt->execute([$lesson_plan_id, $user_id]);
            
            if ($verify_stmt->fetch()) {
                $update_stmt = $db->prepare("
                    UPDATE lesson_plans 
                    SET topic = ?, objectives = ?, lesson_date = ?, strand = ?, 
                        sub_strand = ?, content_standard = ?, indicator = ?, 
                        resources = ?, keywords = ?, starter = ?, presentation = ?, 
                        summary = ?, status = ?, updated_at = NOW()
                    WHERE id = ? AND teacher_id = ?
                ");
                
                $update_stmt->execute([
                    $topic, $objectives, $lesson_date, $strand, $sub_strand,
                    $content_standard, $indicator, $resources, $keywords,
                    $starter, $presentation, $summary, $status,
                    $lesson_plan_id, $user_id
                ]);
                
                // Log activity
                require_once dirname(__DIR__) . '/functions/log_activity.php';
                log_activity($user_id, "Updated lesson plan ID: $lesson_plan_id");
                
                $_SESSION['success'] = "Lesson plan updated successfully!";
                header("Location: lesson_plans.php?view=" . $lesson_plan_id);
                exit();
            } else {
                $_SESSION['error'] = "You don't have permission to edit this lesson plan.";
            }
            
        } elseif ($action === 'delete_lesson_plan') {
            // Delete lesson plan
            $lesson_plan_id = (int)$_POST['lesson_plan_id'];
            
            // Verify ownership
            $verify_stmt = $db->prepare("SELECT id, topic FROM lesson_plans WHERE id = ? AND teacher_id = ?");
            $verify_stmt->execute([$lesson_plan_id, $user_id]);
            
            if ($lesson_plan = $verify_stmt->fetch()) {
                $delete_stmt = $db->prepare("DELETE FROM lesson_plans WHERE id = ? AND teacher_id = ?");
                $delete_stmt->execute([$lesson_plan_id, $user_id]);
                
                // Log activity
                require_once dirname(__DIR__) . '/functions/log_activity.php';
                log_activity($user_id, "Deleted lesson plan: {$lesson_plan['topic']}");
                
                $_SESSION['success'] = "Lesson plan deleted successfully!";
                header("Location: lesson_plans.php");
                exit();
            } else {
                $_SESSION['error'] = "You don't have permission to delete this lesson plan.";
            }
        }
    }
    
    // Get existing lesson plans
    $lesson_plans_stmt = $db->prepare("
        SELECT lp.*, s.subject_name, c.class_name, 
               DATE_FORMAT(lp.lesson_date, '%W, %M %d, %Y') as formatted_date,
               DATEDIFF(lp.lesson_date, CURDATE()) as days_until
        FROM lesson_plans lp
        LEFT JOIN subjects s ON lp.subject_id = s.id
        LEFT JOIN classes c ON lp.class_id = c.id
        WHERE lp.teacher_id = ?
        ORDER BY lp.lesson_date DESC, lp.created_at DESC
    ");
    $lesson_plans_stmt->execute([$user_id]);
    $lesson_plans = $lesson_plans_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get specific lesson plan for viewing/editing
    $current_lesson = null;
    if (isset($_GET['view'])) {
        $view_id = (int)$_GET['view'];
        $view_stmt = $db->prepare("
            SELECT lp.*, s.subject_name, c.class_name, 
                   DATE_FORMAT(lp.lesson_date, '%Y-%m-%d') as date_input,
                   s.subject_code
            FROM lesson_plans lp
            LEFT JOIN subjects s ON lp.subject_id = s.id
            LEFT JOIN classes c ON lp.class_id = c.id
            WHERE lp.id = ? AND lp.teacher_id = ?
        ");
        $view_stmt->execute([$view_id, $user_id]);
        $current_lesson = $view_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
} catch (Exception $e) {
    error_log("Lesson plans error: " . $e->getMessage());
    $error = "Unable to load lesson plans. Please try again later.";
}
?>

<!-- Page-specific CSS -->
<style>
    /* Lesson Plans Specific Styles */
    .lesson-plans-container {
        padding: 0;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .page-title {
        font-family: 'Inter', sans-serif;
        font-weight: 700;
        font-size: 1.8rem;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .page-title i {
        color: var(--primary-sea-blue);
    }
    
    .action-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        font-family: 'Inter', sans-serif;
        font-weight: 600;
        font-size: 0.9rem;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }
    
    .btn-primary {
        background: var(--primary-sea-blue);
        color: white;
    }
    
    .btn-primary:hover {
        background: var(--accent-deep-navy);
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(23, 162, 184, 0.3);
    }
    
    .btn-secondary {
        background: var(--bg-secondary);
        color: var(--text-secondary);
        border: 1px solid var(--border-color);
    }
    
    .btn-secondary:hover {
        background: var(--hover-bg);
        color: var(--primary-sea-blue);
    }
    
    .btn-outline {
        background: transparent;
        color: var(--primary-sea-blue);
        border: 1px solid var(--primary-sea-blue);
    }
    
    .btn-outline:hover {
        background: var(--primary-sea-blue);
        color: white;
    }
    
    .btn-danger {
        background: #dc3545;
        color: white;
    }
    
    .btn-danger:hover {
        background: #c82333;
        transform: translateY(-2px);
    }
    
    .cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 20px;
        margin-bottom: 40px;
    }
    
    .lesson-plan-card {
        background: var(--card-bg);
        border-radius: 12px;
        padding: 20px;
        box-shadow: var(--card-shadow);
        border: 1px solid var(--border-color);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .lesson-plan-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    }
    
    .lesson-plan-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: var(--primary-sea-blue);
    }
    
    .card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 15px;
    }
    
    .card-title {
        font-family: 'Inter', sans-serif;
        font-weight: 600;
        font-size: 1.1rem;
        color: var(--text-primary);
        margin: 0;
        flex: 1;
    }
    
    .card-status {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .status-draft {
        background: rgba(108, 117, 125, 0.1);
        color: var(--text-muted);
    }
    
    .status-published {
        background: rgba(40, 167, 69, 0.1);
        color: #28a745;
    }
    
    .card-meta {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-bottom: 15px;
    }
    
    .meta-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.85rem;
        color: var(--text-secondary);
    }
    
    .meta-item i {
        color: var(--primary-sea-blue);
        width: 16px;
    }
    
    .card-objectives {
        color: var(--text-secondary);
        font-size: 0.9rem;
        line-height: 1.5;
        margin-bottom: 15px;
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;
        overflow: hidden;
    }
    
    .card-footer {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: 15px;
        border-top: 1px solid var(--border-color);
    }
    
    .card-date {
        font-size: 0.8rem;
        color: var(--text-muted);
    }
    
    .card-actions {
        display: flex;
        gap: 8px;
    }
    
    .icon-btn {
        width: 32px;
        height: 32px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: transparent;
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
    }
    
    .icon-btn:hover {
        background: var(--hover-bg);
        color: var(--primary-sea-blue);
        transform: scale(1.1);
    }
    
    /* Form Styles */
    .lesson-form-container {
        background: var(--card-bg);
        border-radius: 12px;
        padding: 30px;
        box-shadow: var(--card-shadow);
        margin-bottom: 40px;
    }
    
    .form-header {
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 1px solid var(--border-color);
    }
    
    .form-title {
        font-family: 'Inter', sans-serif;
        font-weight: 600;
        font-size: 1.3rem;
        color: var(--text-primary);
        margin: 0;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 25px;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .form-group.full-width {
        grid-column: 1 / -1;
    }
    
    .form-label {
        font-family: 'Inter', sans-serif;
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 5px;
    }
    
    .form-label .required {
        color: #dc3545;
    }
    
    .form-input,
    .form-select,
    .form-textarea {
        padding: 12px 15px;
        border: 1px solid var(--border-color);
        border-radius: 8px;
        background: var(--bg-primary);
        color: var(--text-primary);
        font-family: 'Source Sans 3', sans-serif;
        font-size: 0.95rem;
        transition: all 0.3s ease;
    }
    
    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus {
        outline: none;
        border-color: var(--primary-sea-blue);
        box-shadow: 0 0 0 3px rgba(23, 162, 184, 0.1);
    }
    
    .form-textarea {
        min-height: 100px;
        resize: vertical;
    }
    
    .textarea-large {
        min-height: 150px;
    }
    
    .form-help {
        font-size: 0.8rem;
        color: var(--text-muted);
        margin-top: 5px;
    }
    
    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 15px;
        padding-top: 25px;
        border-top: 1px solid var(--border-color);
    }
    
    /* GES Standards Section */
    .ges-standards {
        background: var(--bg-secondary);
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 25px;
        border-left: 4px solid var(--primary-sea-blue);
    }
    
    .ges-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 15px;
    }
    
    .ges-header i {
        color: var(--primary-sea-blue);
        font-size: 1.2rem;
    }
    
    .ges-header h4 {
        margin: 0;
        color: var(--text-primary);
        font-weight: 600;
    }
    
    .standards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 15px;
    }
    
    /* Alerts */
    .alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 25px;
        display: flex;
        align-items: center;
        gap: 12px;
        animation: slideIn 0.3s ease;
    }
    
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .alert-success {
        background: rgba(40, 167, 69, 0.1);
        border: 1px solid rgba(40, 167, 69, 0.3);
        color: #28a745;
    }
    
    .alert-error {
        background: rgba(220, 53, 69, 0.1);
        border: 1px solid rgba(220, 53, 69, 0.3);
        color: #dc3545;
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        background: var(--card-bg);
        border-radius: 12px;
        border: 2px dashed var(--border-color);
    }
    
    .empty-state i {
        font-size: 3rem;
        color: var(--text-muted);
        margin-bottom: 20px;
    }
    
    .empty-state h3 {
        color: var(--text-primary);
        margin-bottom: 10px;
    }
    
    .empty-state p {
        color: var(--text-secondary);
        margin-bottom: 25px;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .cards-grid {
            grid-template-columns: 1fr;
        }
        
        .form-grid {
            grid-template-columns: 1fr;
        }
        
        .lesson-form-container {
            padding: 20px;
        }
        
        .page-header {
            flex-direction: column;
            align-items: stretch;
        }
        
        .action-buttons {
            justify-content: stretch;
        }
        
        .btn {
            flex: 1;
            justify-content: center;
        }
    }
</style>

<div class="lesson-plans-container">
    <!-- Alerts -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <span><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></span>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-title">
            <i class="fas fa-book"></i>
            <h1><?php echo isset($current_lesson) ? 'Edit Lesson Plan' : 'Lesson Plans'; ?></h1>
        </div>
        
        <div class="action-buttons">
            <?php if (isset($current_lesson)): ?>
                <a href="lesson_plans.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Plans
                </a>
                <button type="button" onclick="window.print()" class="btn btn-outline">
                    <i class="fas fa-print"></i> Print
                </button>
            <?php else: ?>
                <a href="?create=new" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create New Plan
                </a>
                <a href="annual_plans.php" class="btn btn-secondary">
                    <i class="fas fa-calendar"></i> Annual Plans
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!isset($_GET['create']) && !isset($_GET['view'])): ?>
        <!-- Lesson Plans Grid -->
        <?php if (!empty($lesson_plans)): ?>
            <div class="cards-grid">
                <?php foreach ($lesson_plans as $plan): ?>
                    <div class="lesson-plan-card">
                        <div class="card-header">
                            <h3 class="card-title"><?php echo htmlspecialchars($plan['topic']); ?></h3>
                            <span class="card-status status-<?php echo $plan['status']; ?>">
                                <?php echo ucfirst($plan['status']); ?>
                            </span>
                        </div>
                        
                        <div class="card-meta">
                            <div class="meta-item">
                                <i class="fas fa-book"></i>
                                <span><?php echo htmlspecialchars($plan['subject_name']); ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-users"></i>
                                <span><?php echo htmlspecialchars($plan['class_name']); ?></span>
                            </div>
                            <?php if (!empty($plan['strand'])): ?>
                                <div class="meta-item">
                                    <i class="fas fa-tag"></i>
                                    <span><?php echo htmlspecialchars($plan['strand']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="card-objectives">
                            <?php echo nl2br(htmlspecialchars(substr($plan['objectives'], 0, 200) . (strlen($plan['objectives']) > 200 ? '...' : ''))); ?>
                        </div>
                        
                        <div class="card-footer">
                            <div class="card-date">
                                <i class="far fa-calendar"></i>
                                <?php echo $plan['formatted_date']; ?>
                                <?php if ($plan['days_until'] >= 0): ?>
                                    <span style="color: <?php echo $plan['days_until'] <= 7 ? '#dc3545' : '#28a745'; ?>">
                                        (<?php echo $plan['days_until'] == 0 ? 'Today' : ($plan['days_until'] . ' days'); ?>)
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="card-actions">
                                <a href="?view=<?php echo $plan['id']; ?>" class="icon-btn" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="?view=<?php echo $plan['id']; ?>&edit=true" class="icon-btn" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this lesson plan?');">
                                    <?php require dirname(__DIR__) . '/functions/csrf.php'; ?>
                                    <input type="hidden" name="action" value="delete_lesson_plan">
                                    <input type="hidden" name="lesson_plan_id" value="<?php echo $plan['id']; ?>">
                                    <button type="submit" class="icon-btn" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-book-open"></i>
                <h3>No Lesson Plans Yet</h3>
                <p>Create your first lesson plan to get started with weekly planning.</p>
                <a href="?create=new" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create First Plan
                </a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (isset($_GET['create']) || (isset($_GET['view']) && isset($_GET['edit']))): ?>
        <!-- Lesson Plan Form -->
        <div class="lesson-form-container">
            <form method="POST" id="lessonPlanForm">
                <?php require dirname(__DIR__) . '/functions/csrf.php'; ?>
                <input type="hidden" name="action" value="<?php echo isset($current_lesson) ? 'update_lesson_plan' : 'create_lesson_plan'; ?>">
                <?php if (isset($current_lesson)): ?>
                    <input type="hidden" name="lesson_plan_id" value="<?php echo $current_lesson['id']; ?>">
                <?php endif; ?>
                
                <div class="form-header">
                    <h2 class="form-title"><?php echo isset($current_lesson) ? 'Edit Lesson Plan' : 'Create New Lesson Plan'; ?></h2>
                    <p class="form-help">Fill in all required fields according to GES standards and NaCCA curriculum.</p>
                </div>
                
                <!-- Basic Information -->
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-book"></i>
                            Subject <span class="required">*</span>
                        </label>
                        <select name="subject_id" class="form-select" required>
                            <option value="">Select Subject</option>
                            <?php foreach ($teacher_subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>"
                                    <?php echo isset($current_lesson) && $current_lesson['subject_id'] == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    <?php if (!empty($subject['subject_code'])): ?>
                                        (<?php echo $subject['subject_code']; ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-users"></i>
                            Class <span class="required">*</span>
                        </label>
                        <select name="class_id" class="form-select" required>
                            <option value="">Select Class</option>
                            <?php foreach ($teacher_classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"
                                    <?php echo isset($current_lesson) && $current_lesson['class_id'] == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                    <?php if (!empty($class['department_name'])): ?>
                                        (<?php echo $class['department_name']; ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-calendar"></i>
                            Lesson Date <span class="required">*</span>
                        </label>
                        <input type="date" name="lesson_date" class="form-input" required
                               value="<?php echo isset($current_lesson) ? $current_lesson['date_input'] : date('Y-m-d'); ?>">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">
                            <i class="fas fa-heading"></i>
                            Topic/Title <span class="required">*</span>
                        </label>
                        <input type="text" name="topic" class="form-input" required
                               placeholder="Enter lesson topic or title"
                               value="<?php echo isset($current_lesson) ? htmlspecialchars($current_lesson['topic']) : ''; ?>">
                    </div>
                </div>
                
                <!-- GES Standards Section -->
                <div class="ges-standards">
                    <div class="ges-header">
                        <i class="fas fa-graduation-cap"></i>
                        <h4>GES Standards & NaCCA Curriculum</h4>
                    </div>
                    
                    <div class="standards-grid">
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-layer-group"></i>
                                Strand
                            </label>
                            <input type="text" name="strand" class="form-input"
                                   placeholder="e.g., Number, Geometry, Reading"
                                   value="<?php echo isset($current_lesson) ? htmlspecialchars($current_lesson['strand']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-layer-group"></i>
                                Sub-Strand
                            </label>
                            <input type="text" name="sub_strand" class="form-input"
                                   placeholder="e.g., Counting, Shapes, Comprehension"
                                   value="<?php echo isset($current_lesson) ? htmlspecialchars($current_lesson['sub_strand']) : ''; ?>">
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-bullseye"></i>
                                Content Standard
                            </label>
                            <textarea name="content_standard" class="form-textarea" rows="2"
                                      placeholder="The main learning standard"><?php echo isset($current_lesson) ? htmlspecialchars($current_lesson['content_standard']) : ''; ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-flag"></i>
                                Indicator
                            </label>
                            <textarea name="indicator" class="form-textarea" rows="2"
                                      placeholder="Specific learning indicator"><?php echo isset($current_lesson) ? htmlspecialchars($current_lesson['indicator']) : ''; ?></textarea>
                        </div>
                    </div>
                </div>
                
                <!-- Lesson Details -->
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label class="form-label">
                            <i class="fas fa-bullseye"></i>
                            Learning Objectives <span class="required">*</span>
                        </label>
                        <textarea name="objectives" class="form-textarea textarea-large" required rows="4"
                                  placeholder="What will students be able to do by the end of the lesson?"><?php echo isset($current_lesson) ? htmlspecialchars($current_lesson['objectives']) : ''; ?></textarea>
                        <div class="form-help">Use SMART objectives (Specific, Measurable, Achievable, Relevant, Time-bound)</div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">
                            <i class="fas fa-tools"></i>
                            Teaching & Learning Resources
                        </label>
                        <textarea name="resources" class="form-textarea" rows="3"
                                  placeholder="List all materials needed for the lesson"><?php echo isset($current_lesson) ? htmlspecialchars($current_lesson['resources']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-key"></i>
                            Key Vocabulary
                        </label>
                        <input type="text" name="keywords" class="form-input"
                               placeholder="e.g., Addition, Sum, Total"
                               value="<?php echo isset($current_lesson) ? htmlspecialchars($current_lesson['keywords']) : ''; ?>">
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">
                            <i class="fas fa-play-circle"></i>
                            Starter Activity (5-10 minutes)
                        </label>
                        <textarea name="starter" class="form-textarea" rows="3"
                                  placeholder="Engaging activity to introduce the lesson"><?php echo isset($current_lesson) ? htmlspecialchars($current_lesson['starter']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">
                            <i class="fas fa-chalkboard-teacher"></i>
                            Main Presentation (20-30 minutes) <span class="required">*</span>
                        </label>
                        <textarea name="presentation" class="form-textarea textarea-large" required rows="6"
                                  placeholder="Step-by-step teaching process, activities, and explanations"><?php echo isset($current_lesson) ? htmlspecialchars($current_lesson['presentation']) : ''; ?></textarea>
                        <div class="form-help">Include teacher-led instruction, student activities, and guided practice</div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label class="form-label">
                            <i class="fas fa-clipboard-check"></i>
                            Summary & Assessment (10-15 minutes)
                        </label>
                        <textarea name="summary" class="form-textarea" rows="3"
                                  placeholder="How will you assess learning? Wrap-up activities"><?php echo isset($current_lesson) ? htmlspecialchars($current_lesson['summary']) : ''; ?></textarea>
                    </div>
                    
                    <?php if (isset($current_lesson)): ?>
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-toggle-on"></i>
                                Status
                            </label>
                            <select name="status" class="form-select">
                                <option value="draft" <?php echo $current_lesson['status'] == 'draft' ? 'selected' : ''; ?>>Draft</option>
                                <option value="published" <?php echo $current_lesson['status'] == 'published' ? 'selected' : ''; ?>>Published</option>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="form-actions">
                    <a href="lesson_plans.php" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i>
                        <?php echo isset($current_lesson) ? 'Update Lesson Plan' : 'Save Lesson Plan'; ?>
                    </button>
                    <?php if (isset($current_lesson)): ?>
                        <button type="button" onclick="window.print()" class="btn btn-outline">
                            <i class="fas fa-print"></i> Print
                        </button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if (isset($current_lesson) && !isset($_GET['edit'])): ?>
        <!-- View Lesson Plan -->
        <div class="lesson-form-container">
            <div class="form-header" style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <h2 class="form-title"><?php echo htmlspecialchars($current_lesson['topic']); ?></h2>
                    <p style="color: var(--text-secondary); margin-top: 5px;">
                        <i class="far fa-calendar"></i> 
                        <?php echo date('l, F j, Y', strtotime($current_lesson['lesson_date'])); ?> | 
                        <i class="fas fa-users"></i> <?php echo htmlspecialchars($current_lesson['class_name']); ?> | 
                        <i class="fas fa-book"></i> <?php echo htmlspecialchars($current_lesson['subject_name']); ?>
                    </p>
                </div>
                <div>
                    <span class="card-status status-<?php echo $current_lesson['status']; ?>" style="font-size: 0.85rem;">
                        <?php echo ucfirst($current_lesson['status']); ?>
                    </span>
                </div>
            </div>
            
            <div style="margin: 25px 0;">
                <div style="display: flex; gap: 15px;">
                    <a href="?view=<?php echo $current_lesson['id']; ?>&edit=true" class="btn btn-outline">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this lesson plan?');">
                        <?php require dirname(__DIR__) . '/functions/csrf.php'; ?>
                        <input type="hidden" name="action" value="delete_lesson_plan">
                        <input type="hidden" name="lesson_plan_id" value="<?php echo $current_lesson['id']; ?>">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </form>
                </div>
            </div>
            
            <?php if (!empty($current_lesson['strand']) || !empty($current_lesson['sub_strand'])): ?>
                <div class="ges-standards">
                    <div class="ges-header">
                        <i class="fas fa-graduation-cap"></i>
                        <h4>GES Standards</h4>
                    </div>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                        <?php if (!empty($current_lesson['strand'])): ?>
                            <div>
                                <strong>Strand:</strong>
                                <p><?php echo nl2br(htmlspecialchars($current_lesson['strand'])); ?></p>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($current_lesson['sub_strand'])): ?>
                            <div>
                                <strong>Sub-Strand:</strong>
                                <p><?php echo nl2br(htmlspecialchars($current_lesson['sub_strand'])); ?></p>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($current_lesson['content_standard'])): ?>
                            <div>
                                <strong>Content Standard:</strong>
                                <p><?php echo nl2br(htmlspecialchars($current_lesson['content_standard'])); ?></p>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($current_lesson['indicator'])): ?>
                            <div>
                                <strong>Indicator:</strong>
                                <p><?php echo nl2br(htmlspecialchars($current_lesson['indicator'])); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div style="margin: 25px 0;">
                <h3 style="color: var(--text-primary); margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                    <i class="fas fa-bullseye"></i> Learning Objectives
                </h3>
                <div style="background: var(--bg-secondary); padding: 20px; border-radius: 8px;">
                    <?php echo nl2br(htmlspecialchars($current_lesson['objectives'])); ?>
                </div>
            </div>
            
            <?php if (!empty($current_lesson['resources'])): ?>
                <div style="margin: 25px 0;">
                    <h3 style="color: var(--text-primary); margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                        <i class="fas fa-tools"></i> Teaching & Learning Resources
                    </h3>
                    <div style="background: var(--bg-secondary); padding: 20px; border-radius: 8px;">
                        <?php echo nl2br(htmlspecialchars($current_lesson['resources'])); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($current_lesson['keywords'])): ?>
                <div style="margin: 25px 0;">
                    <h3 style="color: var(--text-primary); margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                        <i class="fas fa-key"></i> Key Vocabulary
                    </h3>
                    <div style="background: var(--bg-secondary); padding: 20px; border-radius: 8px;">
                        <?php echo htmlspecialchars($current_lesson['keywords']); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div style="margin: 25px 0;">
                <h3 style="color: var(--text-primary); margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                    <i class="fas fa-play-circle"></i> Starter Activity
                </h3>
                <div style="background: var(--bg-secondary); padding: 20px; border-radius: 8px;">
                    <?php echo !empty($current_lesson['starter']) ? nl2br(htmlspecialchars($current_lesson['starter'])) : '<em style="color: var(--text-muted);">No starter activity defined.</em>'; ?>
                </div>
            </div>
            
            <div style="margin: 25px 0;">
                <h3 style="color: var(--text-primary); margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                    <i class="fas fa-chalkboard-teacher"></i> Main Presentation
                </h3>
                <div style="background: var(--bg-secondary); padding: 20px; border-radius: 8px;">
                    <?php echo nl2br(htmlspecialchars($current_lesson['presentation'])); ?>
                </div>
            </div>
            
            <div style="margin: 25px 0;">
                <h3 style="color: var(--text-primary); margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid var(--border-color);">
                    <i class="fas fa-clipboard-check"></i> Summary & Assessment
                </h3>
                <div style="background: var(--bg-secondary); padding: 20px; border-radius: 8px;">
                    <?php echo !empty($current_lesson['summary']) ? nl2br(htmlspecialchars($current_lesson['summary'])) : '<em style="color: var(--text-muted);">No summary defined.</em>'; ?>
                </div>
            </div>
            
            <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid var(--border-color); font-size: 0.85rem; color: var(--text-muted);">
                <p><i class="far fa-clock"></i> Created: <?php echo date('F j, Y g:i A', strtotime($current_lesson['created_at'])); ?></p>
                <?php if ($current_lesson['created_at'] != $current_lesson['updated_at']): ?>
                    <p><i class="fas fa-sync"></i> Last Updated: <?php echo date('F j, Y g:i A', strtotime($current_lesson['updated_at'])); ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- JavaScript for Enhanced Functionality -->
<script>
// Auto-save draft functionality
let autoSaveInterval;
let formChanged = false;

function startAutoSave() {
    const form = document.getElementById('lessonPlanForm');
    if (!form) return;
    
    // Check for changes
    form.addEventListener('input', () => {
        formChanged = true;
    });
    
    // Auto-save every 30 seconds if changes detected
    autoSaveInterval = setInterval(() => {
        if (formChanged) {
            autoSaveDraft();
        }
    }, 30000);
}

function autoSaveDraft() {
    if (!formChanged) return;
    
    const form = document.getElementById('lessonPlanForm');
    const formData = new FormData(form);
    formData.append('action', 'auto_save_draft');
    
    fetch('api/save_draft.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Draft auto-saved');
            formChanged = false;
            
            // Show subtle notification
            showNotification('Draft auto-saved', 'success');
        }
    })
    .catch(error => {
        console.error('Auto-save error:', error);
    });
}

function showNotification(message, type) {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        <span>${message}</span>
    `;
    
    // Style notification
    notification.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 12px 20px;
        background: ${type === 'success' ? 'var(--primary-sea-blue)' : '#dc3545'};
        color: white;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 10px;
        z-index: 10000;
        animation: slideInRight 0.3s ease;
    `;
    
    document.body.appendChild(notification);
    
    // Remove after 3 seconds
    setTimeout(() => {
        notification.style.animation = 'slideOutRight 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Keyboard shortcuts
document.addEventListener('keydown', (e) => {
    // Ctrl+S to save
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        document.getElementById('lessonPlanForm').submit();
    }
    
    // Escape to cancel
    if (e.key === 'Escape' && (window.location.search.includes('create') || window.location.search.includes('edit'))) {
        if (confirm('Cancel editing? Any unsaved changes will be lost.')) {
            window.location.href = 'lesson_plans.php';
        }
    }
});

// Print styles
const printStyles = `
    @media print {
        .app-header, .sidebar, .action-buttons, .form-actions, .card-actions, .page-header .action-buttons {
            display: none !important;
        }
        
        .main-content {
            margin: 0 !important;
            padding: 20px !important;
        }
        
        .lesson-form-container {
            box-shadow: none !important;
            border: 1px solid #ddd !important;
            page-break-inside: avoid;
        }
        
        body {
            background: white !important;
            color: black !important;
        }
        
        a {
            color: black !important;
            text-decoration: none !important;
        }
        
        .btn, .icon-btn {
            display: none !important;
        }
    }
`;

// Add print styles to head
const styleSheet = document.createElement("style");
styleSheet.type = "text/css";
styleSheet.innerText = printStyles;
document.head.appendChild(styleSheet);

// Start auto-save when form is present
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('lessonPlanForm')) {
        startAutoSave();
    }
    
    // Handle beforeunload for unsaved changes
    window.addEventListener('beforeunload', (e) => {
        if (formChanged) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
            return e.returnValue;
        }
    });
});

// Strand suggestions based on subject
const strandSuggestions = {
    'Mathematics': ['Number', 'Algebra', 'Geometry', 'Measurement', 'Data Handling'],
    'English': ['Reading', 'Writing', 'Listening & Speaking', 'Grammar', 'Comprehension'],
    'Science': ['Life Science', 'Physical Science', 'Earth & Space', 'Scientific Inquiry'],
    'Social Studies': ['History', 'Geography', 'Civics', 'Economics'],
    // Add more subjects as needed
};

function updateStrandSuggestions() {
    const subjectSelect = document.querySelector('select[name="subject_id"]');
    const strandInput = document.querySelector('input[name="strand"]');
    
    if (subjectSelect && strandInput) {
        subjectSelect.addEventListener('change', function() {
            const selectedText = this.options[this.selectedIndex].text;
            const subjectName = selectedText.split('(')[0].trim();
            
            if (strandSuggestions[subjectName]) {
                // Create datalist for suggestions
                let datalist = document.getElementById('strand-suggestions');
                if (!datalist) {
                    datalist = document.createElement('datalist');
                    datalist.id = 'strand-suggestions';
                    strandInput.parentNode.appendChild(datalist);
                }
                
                // Update datalist options
                datalist.innerHTML = strandSuggestions[subjectName]
                    .map(strand => `<option value="${strand}">${strand}</option>`)
                    .join('');
                
                strandInput.setAttribute('list', 'strand-suggestions');
            } else {
                strandInput.removeAttribute('list');
            }
        });
    }
}

// Initialize suggestions
document.addEventListener('DOMContentLoaded', updateStrandSuggestions);
</script>

<?php require_once dirname(__DIR__) . '/templates/teacher_footer.php'; ?>