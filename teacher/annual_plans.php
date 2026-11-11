<?php
// teacher/annual_plans.php - Complete Annual Planning System
require_once dirname(__DIR__) . '/templates/teacher_header.php';

try {
    $db = db();
    
    // Get current academic year
    $year_stmt = $db->query("
        SELECT ay.* 
        FROM academic_years ay
        WHERE NOW() BETWEEN CONCAT(SUBSTRING(ay.year, 1, 4), '-09-01') 
        AND CONCAT(SUBSTRING(ay.year, 6, 4), '-08-31')
        OR ay.year LIKE CONCAT(YEAR(NOW()), '-%')
        ORDER BY ay.year DESC 
        LIMIT 1
    ");
    $current_year = $year_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get all academic years
    $all_years_stmt = $db->query("SELECT * FROM academic_years ORDER BY year DESC");
    $all_years = $all_years_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get terms for the current academic year
    $terms_stmt = $db->prepare("
        SELECT * FROM terms 
        WHERE academic_year_id = ? 
        ORDER BY start_date
    ");
    $terms_stmt->execute([$current_year ? $current_year['id'] : 0]);
    $terms = $terms_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get teacher's subjects
    $subject_stmt = $db->prepare("
        SELECT s.*, d.name as department_name 
        FROM subjects s 
        LEFT JOIN departments d ON s.department_id = d.id
        LEFT JOIN classes c ON s.id = c.subject_id 
        WHERE c.teacher_id = ? OR c.class_teacher_id = ?
        GROUP BY s.id
        ORDER BY s.subject_name
    ");
    $subject_stmt->execute([$user_id, $user_id]);
    $teacher_subjects = $subject_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get teacher's classes with departments
    $class_stmt = $db->prepare("
        SELECT c.*, d.name as department_name 
        FROM classes c 
        LEFT JOIN departments d ON c.department_id = d.id
        WHERE c.teacher_id = ? OR c.class_teacher_id = ?
        ORDER BY c.department_id, c.class_name
    ");
    $class_stmt->execute([$user_id, $user_id]);
    $teacher_classes = $class_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get departments
    $dept_stmt = $db->query("SELECT * FROM departments ORDER BY name");
    $departments = $dept_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Handle form submissions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verify CSRF token
        require_once dirname(__DIR__) . '/functions/csrf.php';
        verify_csrf_token();
        
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create_annual_plan') {
            $teacher_id = $user_id;
            $class_id = (int)$_POST['class_id'];
            $subject_id = (int)$_POST['subject_id'];
            $department_id = (int)$_POST['department_id'];
            $academic_year = trim($_POST['academic_year']);
            $format = $_POST['format'] ?? 'ges';
            
            // Check if plan already exists
            $check_stmt = $db->prepare("
                SELECT id FROM annual_plans 
                WHERE teacher_id = ? AND class_id = ? AND subject_id = ? 
                AND department_id = ? AND academic_year = ?
            ");
            $check_stmt->execute([$teacher_id, $class_id, $subject_id, $department_id, $academic_year]);
            
            if ($check_stmt->fetch()) {
                $_SESSION['error'] = "An annual plan for this class and subject already exists for the selected year.";
            } else {
                // Insert base annual plan
                $insert_stmt = $db->prepare("
                    INSERT INTO annual_plans 
                    (teacher_id, class_id, subject_id, department_id, academic_year, format, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $insert_stmt->execute([$teacher_id, $class_id, $subject_id, $department_id, $academic_year, $format]);
                $annual_plan_id = $db->lastInsertId();
                
                // Process term plans (13 weeks per term)
                for ($term = 1; $term <= 3; $term++) {
                    for ($week = 1; $week <= 13; $week++) {
                        $unit_key = "term{$term}_week{$week}_unit";
                        $topic_key = "term{$term}_week{$week}_topic";
                        
                        if (!empty($_POST[$unit_key]) || !empty($_POST[$topic_key])) {
                            $unit = trim($_POST[$unit_key] ?? '');
                            $topic = trim($_POST[$topic_key] ?? '');
                            
                            $termly_stmt = $db->prepare("
                                INSERT INTO termly_plans 
                                (annual_plan_id, teacher_id, class_id, subject_id, department_id, 
                                 term_number, week, unit, topic, format, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                            ");
                            $termly_stmt->execute([
                                $annual_plan_id, $teacher_id, $class_id, $subject_id, $department_id,
                                $term, $week, $unit, $topic, $format
                            ]);
                        }
                    }
                }
                
                // Log activity
                require_once dirname(__DIR__) . '/functions/log_activity.php';
                $subject_name = '';
                foreach ($teacher_subjects as $subj) {
                    if ($subj['id'] == $subject_id) {
                        $subject_name = $subj['subject_name'];
                        break;
                    }
                }
                log_activity($user_id, "Created annual plan for $subject_name ($academic_year)");
                
                $_SESSION['success'] = "Annual plan created successfully!";
                header("Location: annual_plans.php?view=" . $annual_plan_id);
                exit();
            }
            
        } elseif ($action === 'update_annual_plan') {
            $annual_plan_id = (int)$_POST['annual_plan_id'];
            
            // Verify ownership
            $verify_stmt = $db->prepare("
                SELECT id FROM annual_plans 
                WHERE id = ? AND teacher_id = ?
            ");
            $verify_stmt->execute([$annual_plan_id, $user_id]);
            
            if ($verify_stmt->fetch()) {
                $format = $_POST['format'] ?? 'ges';
                
                // Update termly plans
                $db->beginTransaction();
                
                try {
                    // Delete existing termly plans
                    $delete_stmt = $db->prepare("DELETE FROM termly_plans WHERE annual_plan_id = ?");
                    $delete_stmt->execute([$annual_plan_id]);
                    
                    // Re-insert updated plans
                    for ($term = 1; $term <= 3; $term++) {
                        for ($week = 1; $week <= 13; $week++) {
                            $unit_key = "term{$term}_week{$week}_unit";
                            $topic_key = "term{$term}_week{$week}_topic";
                            $lesson_key = "term{$term}_week{$week}_lesson";
                            
                            if (!empty($_POST[$unit_key]) || !empty($_POST[$topic_key]) || !empty($_POST[$lesson_key])) {
                                $unit = trim($_POST[$unit_key] ?? '');
                                $topic = trim($_POST[$topic_key] ?? '');
                                $lesson = trim($_POST[$lesson_key] ?? '');
                                
                                // Get annual plan details
                                $plan_stmt = $db->prepare("
                                    SELECT teacher_id, class_id, subject_id, department_id 
                                    FROM annual_plans WHERE id = ?
                                ");
                                $plan_stmt->execute([$annual_plan_id]);
                                $plan = $plan_stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if ($plan) {
                                    $termly_stmt = $db->prepare("
                                        INSERT INTO termly_plans 
                                        (annual_plan_id, teacher_id, class_id, subject_id, department_id, 
                                         term_number, week, unit, topic, lesson, format, updated_at)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                                    ");
                                    $termly_stmt->execute([
                                        $annual_plan_id, $plan['teacher_id'], $plan['class_id'], 
                                        $plan['subject_id'], $plan['department_id'], $term, $week, 
                                        $unit, $topic, $lesson, $format
                                    ]);
                                }
                            }
                        }
                    }
                    
                    $db->commit();
                    
                    // Log activity
                    require_once dirname(__DIR__) . '/functions/log_activity.php';
                    log_activity($user_id, "Updated annual plan ID: $annual_plan_id");
                    
                    $_SESSION['success'] = "Annual plan updated successfully!";
                    header("Location: annual_plans.php?view=" . $annual_plan_id);
                    exit();
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $_SESSION['error'] = "Error updating plan: " . $e->getMessage();
                }
            } else {
                $_SESSION['error'] = "You don't have permission to edit this plan.";
            }
            
        } elseif ($action === 'delete_annual_plan') {
            $annual_plan_id = (int)$_POST['annual_plan_id'];
            
            // Verify ownership
            $verify_stmt = $db->prepare("
                SELECT id, subject_id FROM annual_plans 
                WHERE id = ? AND teacher_id = ?
            ");
            $verify_stmt->execute([$annual_plan_id, $user_id]);
            
            if ($plan = $verify_stmt->fetch()) {
                $db->beginTransaction();
                
                try {
                    // Delete termly plans first
                    $delete_termly = $db->prepare("DELETE FROM termly_plans WHERE annual_plan_id = ?");
                    $delete_termly->execute([$annual_plan_id]);
                    
                    // Delete annual plan
                    $delete_annual = $db->prepare("DELETE FROM annual_plans WHERE id = ?");
                    $delete_annual->execute([$annual_plan_id]);
                    
                    $db->commit();
                    
                    // Log activity
                    require_once dirname(__DIR__) . '/functions/log_activity.php';
                    log_activity($user_id, "Deleted annual plan ID: $annual_plan_id");
                    
                    $_SESSION['success'] = "Annual plan deleted successfully!";
                    header("Location: annual_plans.php");
                    exit();
                    
                } catch (Exception $e) {
                    $db->rollBack();
                    $_SESSION['error'] = "Error deleting plan: " . $e->getMessage();
                }
            } else {
                $_SESSION['error'] = "You don't have permission to delete this plan.";
            }
        }
    }
    
    // Get existing annual plans
    $plans_stmt = $db->prepare("
        SELECT ap.*, s.subject_name, c.class_name, d.name as department_name,
               COUNT(tp.id) as weeks_planned,
               DATE_FORMAT(ap.created_at, '%M %d, %Y') as created_date
        FROM annual_plans ap
        LEFT JOIN subjects s ON ap.subject_id = s.id
        LEFT JOIN classes c ON ap.class_id = c.id
        LEFT JOIN departments d ON ap.department_id = d.id
        LEFT JOIN termly_plans tp ON ap.id = tp.annual_plan_id
        WHERE ap.teacher_id = ?
        GROUP BY ap.id
        ORDER BY ap.academic_year DESC, s.subject_name, c.class_name
    ");
    $plans_stmt->execute([$user_id]);
    $annual_plans = $plans_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get specific annual plan for viewing/editing
    $current_plan = null;
    $termly_plans = [];
    
    if (isset($_GET['view'])) {
        $view_id = (int)$_GET['view'];
        
        // Get annual plan details
        $view_stmt = $db->prepare("
            SELECT ap.*, s.subject_name, s.subject_code, c.class_name, 
                   d.name as department_name, u.name as teacher_name
            FROM annual_plans ap
            LEFT JOIN subjects s ON ap.subject_id = s.id
            LEFT JOIN classes c ON ap.class_id = c.id
            LEFT JOIN departments d ON ap.department_id = d.id
            LEFT JOIN users u ON ap.teacher_id = u.id
            WHERE ap.id = ? AND ap.teacher_id = ?
        ");
        $view_stmt->execute([$view_id, $user_id]);
        $current_plan = $view_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($current_plan) {
            // Get termly plans for this annual plan
            $termly_stmt = $db->prepare("
                SELECT * FROM termly_plans 
                WHERE annual_plan_id = ? 
                ORDER BY term_number, week
            ");
            $termly_stmt->execute([$view_id]);
            $all_termly = $termly_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Organize by term and week
            foreach ($all_termly as $tp) {
                $termly_plans[$tp['term_number']][$tp['week']] = $tp;
            }
        }
    }
    
    // Calculate weeks for each term
    $term_weeks = [];
    if (!empty($terms)) {
        foreach ($terms as $term) {
            $start = new DateTime($term['start_date']);
            $end = new DateTime($term['end_date']);
            $interval = $start->diff($end);
            $weeks = ceil($interval->days / 7);
            $term_weeks[$term['name']] = min($weeks, 13); // Max 13 weeks per term
        }
    } else {
        // Default to 13 weeks per term
        $term_weeks = ['Term 1' => 13, 'Term 2' => 13, 'Term 3' => 13];
    }
    
} catch (Exception $e) {
    error_log("Annual plans error: " . $e->getMessage());
    $error = "Unable to load annual plans. Please try again later.";
}
?>

<!-- Page-specific CSS -->
<style>
    /* Annual Plans Specific Styles */
    .annual-plans-container {
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
    
    .btn-success {
        background: #28a745;
        color: white;
    }
    
    .btn-success:hover {
        background: #218838;
        transform: translateY(-2px);
    }
    
    .btn-danger {
        background: #dc3545;
        color: white;
    }
    
    .btn-danger:hover {
        background: #c82333;
        transform: translateY(-2px);
    }
    
    /* Plan Cards */
    .cards-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
        gap: 20px;
        margin-bottom: 40px;
    }
    
    .annual-plan-card {
        background: var(--card-bg);
        border-radius: 12px;
        padding: 20px;
        box-shadow: var(--card-shadow);
        border: 1px solid var(--border-color);
        transition: all 0.3s ease;
        position: relative;
        overflow: hidden;
    }
    
    .annual-plan-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
    }
    
    .annual-plan-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 4px;
        height: 100%;
        background: linear-gradient(to bottom, var(--primary-sea-blue), var(--accent-soft-cyan));
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
    
    .card-year {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 0.75rem;
        font-weight: 600;
        background: rgba(23, 162, 184, 0.1);
        color: var(--primary-sea-blue);
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
    
    .progress-section {
        margin-bottom: 15px;
    }
    
    .progress-label {
        display: flex;
        justify-content: space-between;
        margin-bottom: 5px;
        font-size: 0.8rem;
        color: var(--text-secondary);
    }
    
    .progress-bar {
        height: 6px;
        background: var(--bg-secondary);
        border-radius: 3px;
        overflow: hidden;
    }
    
    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, var(--primary-sea-blue), var(--accent-soft-cyan));
        border-radius: 3px;
        transition: width 0.3s ease;
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
    .annual-form-container {
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
    
    .form-subtitle {
        color: var(--text-secondary);
        font-size: 0.95rem;
        margin-top: 5px;
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
    
    /* Term Tabs */
    .term-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 25px;
        overflow-x: auto;
        padding-bottom: 5px;
    }
    
    .term-tab {
        padding: 12px 24px;
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 8px;
        font-family: 'Inter', sans-serif;
        font-weight: 600;
        font-size: 0.9rem;
        color: var(--text-secondary);
        cursor: pointer;
        transition: all 0.3s ease;
        white-space: nowrap;
    }
    
    .term-tab:hover {
        background: var(--hover-bg);
        color: var(--primary-sea-blue);
    }
    
    .term-tab.active {
        background: var(--primary-sea-blue);
        color: white;
        border-color: var(--primary-sea-blue);
    }
    
    .term-content {
        display: none;
        animation: fadeIn 0.3s ease;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    .term-content.active {
        display: block;
    }
    
    /* Week Grid */
    .weeks-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 15px;
        margin-bottom: 30px;
    }
    
    .week-card {
        background: var(--bg-secondary);
        border-radius: 8px;
        padding: 15px;
        border: 1px solid var(--border-color);
        transition: all 0.3s ease;
    }
    
    .week-card:hover {
        border-color: var(--primary-sea-blue);
        box-shadow: 0 4px 12px rgba(23, 162, 184, 0.1);
    }
    
    .week-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 12px;
        padding-bottom: 10px;
        border-bottom: 1px solid var(--border-color);
    }
    
    .week-number {
        font-family: 'Inter', sans-serif;
        font-weight: 700;
        color: var(--primary-sea-blue);
        font-size: 1rem;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .week-fields {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }
    
    .field-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    
    .field-label {
        font-size: 0.8rem;
        color: var(--text-secondary);
        font-weight: 600;
    }
    
    .field-input {
        padding: 8px 12px;
        border: 1px solid var(--border-color);
        border-radius: 6px;
        background: var(--card-bg);
        color: var(--text-primary);
        font-size: 0.9rem;
        transition: all 0.3s ease;
    }
    
    .field-input:focus {
        outline: none;
        border-color: var(--primary-sea-blue);
        box-shadow: 0 0 0 2px rgba(23, 162, 184, 0.1);
    }
    
    /* Plan Overview */
    .plan-overview {
        background: var(--card-bg);
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 30px;
        box-shadow: var(--card-shadow);
    }
    
    .overview-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .overview-title {
        font-family: 'Inter', sans-serif;
        font-weight: 700;
        font-size: 1.4rem;
        color: var(--text-primary);
        margin: 0;
    }
    
    .overview-meta {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
    }
    
    .meta-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
        background: rgba(23, 162, 184, 0.1);
        color: var(--primary-sea-blue);
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: var(--bg-secondary);
        border-radius: 10px;
        padding: 20px;
        text-align: center;
        transition: all 0.3s ease;
    }
    
    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.1);
    }
    
    .stat-value {
        font-family: 'Inter', sans-serif;
        font-weight: 700;
        font-size: 2rem;
        color: var(--primary-sea-blue);
        margin-bottom: 5px;
    }
    
    .stat-label {
        font-size: 0.9rem;
        color: var(--text-secondary);
    }
    
    /* Term Overview Tables */
    .term-overview {
        margin-bottom: 40px;
    }
    
    .term-title {
        font-family: 'Inter', sans-serif;
        font-weight: 600;
        font-size: 1.2rem;
        color: var(--text-primary);
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid var(--primary-sea-blue);
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .overview-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 30px;
    }
    
    .overview-table th {
        background: var(--bg-secondary);
        color: var(--text-primary);
        font-family: 'Inter', sans-serif;
        font-weight: 600;
        font-size: 0.9rem;
        padding: 12px 15px;
        text-align: left;
        border: 1px solid var(--border-color);
    }
    
    .overview-table td {
        padding: 12px 15px;
        border: 1px solid var(--border-color);
        color: var(--text-secondary);
        font-size: 0.9rem;
    }
    
    .overview-table tr:hover {
        background: var(--hover-bg);
    }
    
    .week-cell {
        font-weight: 600;
        color: var(--text-primary);
        background: var(--bg-secondary);
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
    
    .alert-info {
        background: rgba(23, 162, 184, 0.1);
        border: 1px solid rgba(23, 162, 184, 0.3);
        color: var(--primary-sea-blue);
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
    @media (max-width: 992px) {
        .weeks-grid {
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        }
        
        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    @media (max-width: 768px) {
        .cards-grid {
            grid-template-columns: 1fr;
        }
        
        .weeks-grid {
            grid-template-columns: 1fr;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .form-grid {
            grid-template-columns: 1fr;
        }
        
        .annual-form-container {
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
        
        .term-tabs {
            flex-wrap: wrap;
        }
        
        .term-tab {
            flex: 1;
            text-align: center;
            min-width: 100px;
        }
        
        .overview-meta {
            flex-direction: column;
            gap: 10px;
        }
        
        .overview-table {
            display: block;
            overflow-x: auto;
        }
    }
    
    @media (max-width: 480px) {
        .card-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .card-year {
            align-self: flex-start;
        }
        
        .card-footer {
            flex-direction: column;
            gap: 15px;
            align-items: flex-start;
        }
        
        .card-actions {
            align-self: flex-end;
        }
    }
    
    /* Format Toggle */
    .format-toggle {
        display: flex;
        gap: 10px;
        margin-bottom: 20px;
        background: var(--bg-secondary);
        padding: 10px;
        border-radius: 8px;
        width: fit-content;
    }
    
    .format-option {
        padding: 8px 16px;
        border-radius: 6px;
        background: transparent;
        border: 1px solid transparent;
        font-family: 'Inter', sans-serif;
        font-weight: 600;
        font-size: 0.85rem;
        color: var(--text-secondary);
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .format-option:hover {
        background: var(--hover-bg);
        color: var(--primary-sea-blue);
    }
    
    .format-option.active {
        background: var(--primary-sea-blue);
        color: white;
        border-color: var(--primary-sea-blue);
    }
    
    /* Loading States */
    .loading {
        opacity: 0.7;
        pointer-events: none;
    }
    
    .loading::after {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(255, 255, 255, 0.5);
        border-radius: inherit;
    }
    
    .spinner {
        width: 40px;
        height: 40px;
        border: 3px solid var(--border-color);
        border-top-color: var(--primary-sea-blue);
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 20px auto;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
</style>

<div class="annual-plans-container">
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
            <i class="fas fa-calendar-alt"></i>
            <h1><?php echo isset($current_plan) ? 'Annual Plan Details' : 'Annual Plans'; ?></h1>
            <?php if ($current_year): ?>
                <span style="font-size: 0.9rem; color: var(--text-secondary);">
                    (Current: <?php echo $current_year['year']; ?>)
                </span>
            <?php endif; ?>
        </div>
        
        <div class="action-buttons">
            <?php if (isset($current_plan)): ?>
                <a href="annual_plans.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Plans
                </a>
                <?php if (!isset($_GET['edit'])): ?>
                    <a href="?view=<?php echo $current_plan['id']; ?>&edit=true" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Plan
                    </a>
                <?php endif; ?>
                <button type="button" onclick="exportPlan()" class="btn btn-outline">
                    <i class="fas fa-download"></i> Export
                </button>
            <?php else: ?>
                <a href="?create=new" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create New Plan
                </a>
                <a href="lesson_plans.php" class="btn btn-secondary">
                    <i class="fas fa-book"></i> Weekly Plans
                </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!isset($_GET['create']) && !isset($_GET['view'])): ?>
        <!-- Annual Plans Grid -->
        <?php if (!empty($annual_plans)): ?>
            <div class="cards-grid">
                <?php foreach ($annual_plans as $plan): ?>
                    <?php
                    $progress = min(100, round(($plan['weeks_planned'] / 39) * 100)); // 39 weeks = 13 weeks * 3 terms
                    $progress_color = $progress >= 75 ? '#28a745' : ($progress >= 50 ? '#ffc107' : '#dc3545');
                    ?>
                    
                    <div class="annual-plan-card">
                        <div class="card-header">
                            <h3 class="card-title"><?php echo htmlspecialchars($plan['subject_name']); ?></h3>
                            <span class="card-year"><?php echo htmlspecialchars($plan['academic_year']); ?></span>
                        </div>
                        
                        <div class="card-meta">
                            <div class="meta-item">
                                <i class="fas fa-users"></i>
                                <span><?php echo htmlspecialchars($plan['class_name']); ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-building"></i>
                                <span><?php echo htmlspecialchars($plan['department_name']); ?></span>
                            </div>
                            <div class="meta-item">
                                <i class="fas fa-calendar-week"></i>
                                <span><?php echo $plan['weeks_planned']; ?> weeks planned</span>
                            </div>
                        </div>
                        
                        <div class="progress-section">
                            <div class="progress-label">
                                <span>Completion</span>
                                <span><?php echo $progress; ?>%</span>
                            </div>
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: <?php echo $progress; ?>%; background: <?php echo $progress_color; ?>;"></div>
                            </div>
                        </div>
                        
                        <div class="card-footer">
                            <div class="card-date">
                                <i class="far fa-clock"></i> Created <?php echo $plan['created_date']; ?>
                            </div>
                            <div class="card-actions">
                                <a href="?view=<?php echo $plan['id']; ?>" class="icon-btn" title="View">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <a href="?view=<?php echo $plan['id']; ?>&edit=true" class="icon-btn" title="Edit">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this annual plan? This will also delete all termly plans.');">
                                    <?php require dirname(__DIR__) . '/functions/csrf.php'; ?>
                                    <input type="hidden" name="action" value="delete_annual_plan">
                                    <input type="hidden" name="annual_plan_id" value="<?php echo $plan['id']; ?>">
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
                <i class="fas fa-calendar-alt"></i>
                <h3>No Annual Plans Yet</h3>
                <p>Create your first annual plan to organize your teaching for the entire academic year.</p>
                <a href="?create=new" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Create First Plan
                </a>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (isset($_GET['create']) || (isset($_GET['view']) && isset($_GET['edit']))): ?>
        <!-- Annual Plan Form -->
        <div class="annual-form-container">
            <form method="POST" id="annualPlanForm">
                <?php require dirname(__DIR__) . '/functions/csrf.php'; ?>
                <input type="hidden" name="action" value="<?php echo isset($current_plan) ? 'update_annual_plan' : 'create_annual_plan'; ?>">
                <?php if (isset($current_plan)): ?>
                    <input type="hidden" name="annual_plan_id" value="<?php echo $current_plan['id']; ?>">
                    <input type="hidden" name="academic_year" value="<?php echo htmlspecialchars($current_plan['academic_year']); ?>">
                <?php endif; ?>
                
                <div class="form-header">
                    <h2 class="form-title"><?php echo isset($current_plan) ? 'Edit Annual Plan' : 'Create New Annual Plan'; ?></h2>
                    <p class="form-subtitle">Plan your teaching for the entire academic year (3 terms, 13 weeks each)</p>
                </div>
                
                <!-- Basic Information -->
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-book"></i>
                            Subject <span class="required">*</span>
                        </label>
                        <select name="subject_id" class="form-select" required <?php echo isset($current_plan) ? 'disabled' : ''; ?>>
                            <option value="">Select Subject</option>
                            <?php foreach ($teacher_subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>"
                                    <?php echo isset($current_plan) && $current_plan['subject_id'] == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                    <?php if (!empty($subject['subject_code'])): ?>
                                        (<?php echo $subject['subject_code']; ?>)
                                    <?php endif; ?>
                                    <?php if (!empty($subject['department_name'])): ?>
                                        - <?php echo $subject['department_name']; ?>
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($current_plan)): ?>
                            <input type="hidden" name="subject_id" value="<?php echo $current_plan['subject_id']; ?>">
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-users"></i>
                            Class <span class="required">*</span>
                        </label>
                        <select name="class_id" class="form-select" required <?php echo isset($current_plan) ? 'disabled' : ''; ?>>
                            <option value="">Select Class</option>
                            <?php foreach ($teacher_classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"
                                    <?php echo isset($current_plan) && $current_plan['class_id'] == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                    <?php if (!empty($class['department_name'])): ?>
                                        (<?php echo $class['department_name']; ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($current_plan)): ?>
                            <input type="hidden" name="class_id" value="<?php echo $current_plan['class_id']; ?>">
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-building"></i>
                            Department <span class="required">*</span>
                        </label>
                        <select name="department_id" class="form-select" required <?php echo isset($current_plan) ? 'disabled' : ''; ?>>
                            <option value="">Select Department</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>"
                                    <?php echo isset($current_plan) && $current_plan['department_id'] == $dept['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($dept['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($current_plan)): ?>
                            <input type="hidden" name="department_id" value="<?php echo $current_plan['department_id']; ?>">
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!isset($current_plan)): ?>
                        <div class="form-group">
                            <label class="form-label">
                                <i class="fas fa-calendar"></i>
                                Academic Year <span class="required">*</span>
                            </label>
                            <select name="academic_year" class="form-select" required>
                                <option value="">Select Academic Year</option>
                                <?php foreach ($all_years as $year): ?>
                                    <option value="<?php echo htmlspecialchars($year['year']); ?>"
                                        <?php echo $current_year && $year['id'] == $current_year['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year['year']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <option value="<?php echo date('Y'); ?>-<?php echo date('Y')+1; ?>">
                                    <?php echo date('Y'); ?>-<?php echo date('Y')+1; ?> (Custom)
                                </option>
                            </select>
                        </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-file-alt"></i>
                            Format
                        </label>
                        <select name="format" class="form-select">
                            <option value="ges" <?php echo isset($current_plan) && $current_plan['format'] == 'ges' ? 'selected' : ''; ?>>GES Standard</option>
                            <option value="oxford" <?php echo isset($current_plan) && $current_plan['format'] == 'oxford' ? 'selected' : ''; ?>>Oxford Format</option>
                        </select>
                    </div>
                </div>
                
                <!-- Format Toggle (Alternative UI) -->
                <div class="format-toggle">
                    <button type="button" class="format-option active" data-format="ges">
                        <i class="fas fa-graduation-cap"></i> GES Format
                    </button>
                    <button type="button" class="format-option" data-format="oxford">
                        <i class="fas fa-university"></i> Oxford Format
                    </button>
                </div>
                
                <!-- Term Navigation -->
                <div class="term-tabs" id="termTabs">
                    <button type="button" class="term-tab active" data-term="1">
                        <i class="fas fa-calendar-alt"></i> Term 1 (13 Weeks)
                    </button>
                    <button type="button" class="term-tab" data-term="2">
                        <i class="fas fa-calendar-alt"></i> Term 2 (13 Weeks)
                    </button>
                    <button type="button" class="term-tab" data-term="3">
                        <i class="fas fa-calendar-alt"></i> Term 3 (13 Weeks)
                    </button>
                </div>
                
                <!-- Term 1 Content -->
                <div class="term-content active" data-term="1">
                    <h3 style="color: var(--text-primary); margin-bottom: 20px;">
                        <i class="fas fa-calendar-check"></i> Term 1 - Weekly Planning
                    </h3>
                    <div class="weeks-grid">
                        <?php for ($week = 1; $week <= 13; $week++): ?>
                            <?php 
                            $tp = $termly_plans[1][$week] ?? [];
                            $unit = $tp['unit'] ?? '';
                            $topic = $tp['topic'] ?? '';
                            $lesson = $tp['lesson'] ?? '';
                            ?>
                            <div class="week-card">
                                <div class="week-header">
                                    <div class="week-number">
                                        <i class="fas fa-calendar-week"></i>
                                        Week <?php echo $week; ?>
                                    </div>
                                    <span style="font-size: 0.8rem; color: var(--text-muted);">
                                        <?php echo calculateWeekDates($week, $terms[0]['start_date'] ?? date('Y-09-01')); ?>
                                    </span>
                                </div>
                                <div class="week-fields">
                                    <div class="field-group">
                                        <label class="field-label">Unit/Strand</label>
                                        <input type="text" name="term1_week<?php echo $week; ?>_unit" 
                                               class="field-input" placeholder="Unit/Strand"
                                               value="<?php echo htmlspecialchars($unit); ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">Topic/Sub-strand</label>
                                        <input type="text" name="term1_week<?php echo $week; ?>_topic" 
                                               class="field-input" placeholder="Topic/Sub-strand"
                                               value="<?php echo htmlspecialchars($topic); ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">Lesson Objectives</label>
                                        <textarea name="term1_week<?php echo $week; ?>_lesson" 
                                                  class="field-input" rows="2" 
                                                  placeholder="Key learning objectives"><?php echo htmlspecialchars($lesson); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <!-- Term 2 Content -->
                <div class="term-content" data-term="2">
                    <h3 style="color: var(--text-primary); margin-bottom: 20px;">
                        <i class="fas fa-calendar-check"></i> Term 2 - Weekly Planning
                    </h3>
                    <div class="weeks-grid">
                        <?php for ($week = 1; $week <= 13; $week++): ?>
                            <?php 
                            $tp = $termly_plans[2][$week] ?? [];
                            $unit = $tp['unit'] ?? '';
                            $topic = $tp['topic'] ?? '';
                            $lesson = $tp['lesson'] ?? '';
                            ?>
                            <div class="week-card">
                                <div class="week-header">
                                    <div class="week-number">
                                        <i class="fas fa-calendar-week"></i>
                                        Week <?php echo $week; ?>
                                    </div>
                                    <span style="font-size: 0.8rem; color: var(--text-muted);">
                                        <?php echo calculateWeekDates($week, $terms[1]['start_date'] ?? date('Y-01-01')); ?>
                                    </span>
                                </div>
                                <div class="week-fields">
                                    <div class="field-group">
                                        <label class="field-label">Unit/Strand</label>
                                        <input type="text" name="term2_week<?php echo $week; ?>_unit" 
                                               class="field-input" placeholder="Unit/Strand"
                                               value="<?php echo htmlspecialchars($unit); ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">Topic/Sub-strand</label>
                                        <input type="text" name="term2_week<?php echo $week; ?>_topic" 
                                               class="field-input" placeholder="Topic/Sub-strand"
                                               value="<?php echo htmlspecialchars($topic); ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">Lesson Objectives</label>
                                        <textarea name="term2_week<?php echo $week; ?>_lesson" 
                                                  class="field-input" rows="2" 
                                                  placeholder="Key learning objectives"><?php echo htmlspecialchars($lesson); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <!-- Term 3 Content -->
                <div class="term-content" data-term="3">
                    <h3 style="color: var(--text-primary); margin-bottom: 20px;">
                        <i class="fas fa-calendar-check"></i> Term 3 - Weekly Planning
                    </h3>
                    <div class="weeks-grid">
                        <?php for ($week = 1; $week <= 13; $week++): ?>
                            <?php 
                            $tp = $termly_plans[3][$week] ?? [];
                            $unit = $tp['unit'] ?? '';
                            $topic = $tp['topic'] ?? '';
                            $lesson = $tp['lesson'] ?? '';
                            ?>
                            <div class="week-card">
                                <div class="week-header">
                                    <div class="week-number">
                                        <i class="fas fa-calendar-week"></i>
                                        Week <?php echo $week; ?>
                                    </div>
                                    <span style="font-size: 0.8rem; color: var(--text-muted);">
                                        <?php echo calculateWeekDates($week, $terms[2]['start_date'] ?? date('Y-04-01')); ?>
                                    </span>
                                </div>
                                <div class="week-fields">
                                    <div class="field-group">
                                        <label class="field-label">Unit/Strand</label>
                                        <input type="text" name="term3_week<?php echo $week; ?>_unit" 
                                               class="field-input" placeholder="Unit/Strand"
                                               value="<?php echo htmlspecialchars($unit); ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">Topic/Sub-strand</label>
                                        <input type="text" name="term3_week<?php echo $week; ?>_topic" 
                                               class="field-input" placeholder="Topic/Sub-strand"
                                               value="<?php echo htmlspecialchars($topic); ?>">
                                    </div>
                                    <div class="field-group">
                                        <label class="field-label">Lesson Objectives</label>
                                        <textarea name="term3_week<?php echo $week; ?>_lesson" 
                                                  class="field-input" rows="2" 
                                                  placeholder="Key learning objectives"><?php echo htmlspecialchars($lesson); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        <?php endfor; ?>
                    </div>
                </div>
                
                <div class="form-actions">
                    <a href="annual_plans.php<?php echo isset($current_plan) ? '?view=' . $current_plan['id'] : ''; ?>" class="btn btn-secondary">
                        Cancel
                    </a>
                    <button type="button" onclick="saveAsDraft()" class="btn btn-outline">
                        <i class="fas fa-save"></i> Save as Draft
                    </button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-check-circle"></i>
                        <?php echo isset($current_plan) ? 'Update Annual Plan' : 'Create Annual Plan'; ?>
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <?php if (isset($current_plan) && !isset($_GET['edit'])): ?>
        <!-- View Annual Plan -->
        <div class="plan-overview">
            <div class="overview-header">
                <div>
                    <h2 class="overview-title"><?php echo htmlspecialchars($current_plan['subject_name']); ?> Annual Plan</h2>
                    <p style="color: var(--text-secondary); margin-top: 5px;">
                        <i class="fas fa-user"></i> <?php echo htmlspecialchars($current_plan['teacher_name']); ?> | 
                        <i class="fas fa-calendar"></i> <?php echo htmlspecialchars($current_plan['academic_year']); ?>
                    </p>
                </div>
                <div class="overview-meta">
                    <span class="meta-badge">
                        <i class="fas fa-users"></i> <?php echo htmlspecialchars($current_plan['class_name']); ?>
                    </span>
                    <span class="meta-badge">
                        <i class="fas fa-building"></i> <?php echo htmlspecialchars($current_plan['department_name']); ?>
                    </span>
                    <span class="meta-badge">
                        <i class="fas fa-file-alt"></i> <?php echo ucfirst($current_plan['format']); ?> Format
                    </span>
                </div>
            </div>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value">39</div>
                    <div class="stat-label">Total Weeks</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($termly_plans[1] ?? []); ?></div>
                    <div class="stat-label">Term 1 Weeks Planned</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($termly_plans[2] ?? []); ?></div>
                    <div class="stat-label">Term 2 Weeks Planned</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value"><?php echo count($termly_plans[3] ?? []); ?></div>
                    <div class="stat-label">Term 3 Weeks Planned</div>
                </div>
            </div>
            
            <div style="margin: 30px 0;">
                <div style="display: flex; gap: 15px; flex-wrap: wrap;">
                    <a href="?view=<?php echo $current_plan['id']; ?>&edit=true" class="btn btn-primary">
                        <i class="fas fa-edit"></i> Edit Plan
                    </a>
                    <button type="button" onclick="exportPlan()" class="btn btn-outline">
                        <i class="fas fa-download"></i> Export as PDF
                    </button>
                    <button type="button" onclick="printPlan()" class="btn btn-outline">
                        <i class="fas fa-print"></i> Print
                    </button>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this annual plan? This will also delete all termly plans.');">
                        <?php require dirname(__DIR__) . '/functions/csrf.php'; ?>
                        <input type="hidden" name="action" value="delete_annual_plan">
                        <input type="hidden" name="annual_plan_id" value="<?php echo $current_plan['id']; ?>">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash"></i> Delete Plan
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <!-- Term Overview Tables -->
        <div class="term-overview">
            <h3 class="term-title">
                <i class="fas fa-calendar-alt"></i> Term 1 Overview
            </h3>
            <table class="overview-table">
                <thead>
                    <tr>
                        <th width="10%">Week</th>
                        <th width="30%">Unit/Strand</th>
                        <th width="30%">Topic/Sub-strand</th>
                        <th width="30%">Lesson Objectives</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($week = 1; $week <= 13; $week++): ?>
                        <?php $tp = $termly_plans[1][$week] ?? []; ?>
                        <tr>
                            <td class="week-cell">Week <?php echo $week; ?></td>
                            <td><?php echo !empty($tp['unit']) ? htmlspecialchars($tp['unit']) : '<em style="color: var(--text-muted);">Not planned</em>'; ?></td>
                            <td><?php echo !empty($tp['topic']) ? htmlspecialchars($tp['topic']) : '<em style="color: var(--text-muted);">Not planned</em>'; ?></td>
                            <td><?php echo !empty($tp['lesson']) ? nl2br(htmlspecialchars($tp['lesson'])) : '<em style="color: var(--text-muted);">Not planned</em>'; ?></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        
        <div class="term-overview">
            <h3 class="term-title">
                <i class="fas fa-calendar-alt"></i> Term 2 Overview
            </h3>
            <table class="overview-table">
                <thead>
                    <tr>
                        <th width="10%">Week</th>
                        <th width="30%">Unit/Strand</th>
                        <th width="30%">Topic/Sub-strand</th>
                        <th width="30%">Lesson Objectives</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($week = 1; $week <= 13; $week++): ?>
                        <?php $tp = $termly_plans[2][$week] ?? []; ?>
                        <tr>
                            <td class="week-cell">Week <?php echo $week; ?></td>
                            <td><?php echo !empty($tp['unit']) ? htmlspecialchars($tp['unit']) : '<em style="color: var(--text-muted);">Not planned</em>'; ?></td>
                            <td><?php echo !empty($tp['topic']) ? htmlspecialchars($tp['topic']) : '<em style="color: var(--text-muted);">Not planned</em>'; ?></td>
                            <td><?php echo !empty($tp['lesson']) ? nl2br(htmlspecialchars($tp['lesson'])) : '<em style="color: var(--text-muted);">Not planned</em>'; ?></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        
        <div class="term-overview">
            <h3 class="term-title">
                <i class="fas fa-calendar-alt"></i> Term 3 Overview
            </h3>
            <table class="overview-table">
                <thead>
                    <tr>
                        <th width="10%">Week</th>
                        <th width="30%">Unit/Strand</th>
                        <th width="30%">Topic/Sub-strand</th>
                        <th width="30%">Lesson Objectives</th>
                    </tr>
                </thead>
                <tbody>
                    <?php for ($week = 1; $week <= 13; $week++): ?>
                        <?php $tp = $termly_plans[3][$week] ?? []; ?>
                        <tr>
                            <td class="week-cell">Week <?php echo $week; ?></td>
                            <td><?php echo !empty($tp['unit']) ? htmlspecialchars($tp['unit']) : '<em style="color: var(--text-muted);">Not planned</em>'; ?></td>
                            <td><?php echo !empty($tp['topic']) ? htmlspecialchars($tp['topic']) : '<em style="color: var(--text-muted);">Not planned</em>'; ?></td>
                            <td><?php echo !empty($tp['lesson']) ? nl2br(htmlspecialchars($tp['lesson'])) : '<em style="color: var(--text-muted);">Not planned</em>'; ?></td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (!empty($current_plan['created_at'])): ?>
            <div style="margin-top: 40px; padding-top: 20px; border-top: 1px solid var(--border-color); font-size: 0.85rem; color: var(--text-muted);">
                <p><i class="far fa-clock"></i> Created: <?php echo date('F j, Y g:i A', strtotime($current_plan['created_at'])); ?></p>
                <?php if (!empty($current_plan['updated_at']) && $current_plan['created_at'] != $current_plan['updated_at']): ?>
                    <p><i class="fas fa-sync"></i> Last Updated: <?php echo date('F j, Y g:i A', strtotime($current_plan['updated_at'])); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
// Helper function to calculate week dates
function calculateWeekDates($week_number, $term_start_date) {
    $start_date = new DateTime($term_start_date);
    $start_date->modify('+' . (($week_number - 1) * 7) . ' days');
    $end_date = clone $start_date;
    $end_date->modify('+6 days');
    
    return $start_date->format('M d') . ' - ' . $end_date->format('M d');
}
?>

<!-- JavaScript for Enhanced Functionality -->
<script>
// Term Navigation
document.addEventListener('DOMContentLoaded', function() {
    // Term tabs
    const termTabs = document.querySelectorAll('.term-tab');
    const termContents = document.querySelectorAll('.term-content');
    
    termTabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const term = this.dataset.term;
            
            // Update active tab
            termTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            // Show corresponding content
            termContents.forEach(content => {
                content.classList.remove('active');
                if (content.dataset.term === term) {
                    content.classList.add('active');
                }
            });
        });
    });
    
    // Format toggle
    const formatOptions = document.querySelectorAll('.format-option');
    const formatSelect = document.querySelector('select[name="format"]');
    
    formatOptions.forEach(option => {
        option.addEventListener('click', function() {
            const format = this.dataset.format;
            
            // Update active option
            formatOptions.forEach(opt => opt.classList.remove('active'));
            this.classList.add('active');
            
            // Update select value
            if (formatSelect) {
                formatSelect.value = format;
            }
            
            // Toggle fields based on format
            toggleFormatFields(format);
        });
    });
    
    // Auto-save functionality
    startAutoSave();
    
    // Initialize progress tracking
    initializeProgressTracking();
});

// Toggle fields based on format selection
function toggleFormatFields(format) {
    const unitLabels = document.querySelectorAll('.field-group:nth-child(1) .field-label');
    const topicLabels = document.querySelectorAll('.field-group:nth-child(2) .field-label');
    
    if (format === 'ges') {
        unitLabels.forEach(label => {
            label.textContent = 'Strand';
        });
        topicLabels.forEach(label => {
            label.textContent = 'Sub-strand';
        });
    } else {
        unitLabels.forEach(label => {
            label.textContent = 'Unit';
        });
        topicLabels.forEach(label => {
            label.textContent = 'Topic';
        });
    }
}

// Auto-save draft
let autoSaveInterval;
let formChanged = false;

function startAutoSave() {
    const form = document.getElementById('annualPlanForm');
    if (!form) return;
    
    // Check for changes
    form.addEventListener('input', () => {
        formChanged = true;
        updateProgress();
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
    
    const form = document.getElementById('annualPlanForm');
    const formData = new FormData(form);
    formData.append('action', 'auto_save_draft');
    
    fetch('api/save_draft.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Annual plan draft auto-saved');
            formChanged = false;
            
            // Show subtle notification
            showNotification('Draft auto-saved', 'success');
        }
    })
    .catch(error => {
        console.error('Auto-save error:', error);
    });
}

// Save as draft
function saveAsDraft() {
    const form = document.getElementById('annualPlanForm');
    const formData = new FormData(form);
    formData.append('action', 'save_draft_only');
    
    // Show loading
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
    submitBtn.disabled = true;
    
    fetch('api/save_draft.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Draft saved successfully!', 'success');
            formChanged = false;
        } else {
            showNotification('Error saving draft', 'error');
        }
    })
    .catch(error => {
        showNotification('Network error saving draft', 'error');
        console.error('Save draft error:', error);
    })
    .finally(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    });
}

// Progress tracking
function initializeProgressTracking() {
    const weekCards = document.querySelectorAll('.week-card');
    
    weekCards.forEach(card => {
        const inputs = card.querySelectorAll('input, textarea');
        inputs.forEach(input => {
            input.addEventListener('input', updateProgress);
        });
    });
}

function updateProgress() {
    let filledWeeks = 0;
    let totalWeeks = 0;
    
    // Count filled weeks for each term
    for (let term = 1; term <= 3; term++) {
        for (let week = 1; week <= 13; week++) {
            totalWeeks++;
            const unit = document.querySelector(`[name="term${term}_week${week}_unit"]`);
            const topic = document.querySelector(`[name="term${term}_week${week}_topic"]`);
            const lesson = document.querySelector(`[name="term${term}_week${week}_lesson"]`);
            
            if (unit && topic && lesson) {
                if (unit.value.trim() !== '' || topic.value.trim() !== '' || lesson.value.trim() !== '') {
                    filledWeeks++;
                }
            }
        }
    }
    
    const progress = totalWeeks > 0 ? Math.round((filledWeeks / totalWeeks) * 100) : 0;
    
    // Update progress display if it exists
    const progressFill = document.querySelector('.progress-fill');
    const progressPercent = document.querySelector('.progress-label span:last-child');
    
    if (progressFill && progressPercent) {
        progressFill.style.width = `${progress}%`;
        progressPercent.textContent = `${progress}%`;
        
        // Update color based on progress
        let color;
        if (progress >= 75) {
            color = '#28a745';
        } else if (progress >= 50) {
            color = '#ffc107';
        } else {
            color = '#dc3545';
        }
        progressFill.style.background = color;
    }
    
    return progress;
}

// Export functionality
function exportPlan() {
    const planId = <?php echo isset($current_plan) ? $current_plan['id'] : 'null'; ?>;
    if (!planId) return;
    
    // Show loading
    showNotification('Generating PDF...', 'info');
    
    // Open in new tab
    window.open(`api/export_plan.php?id=${planId}&type=pdf`, '_blank');
}

function printPlan() {
    // Add print styles
    const printStyles = `
        @media print {
            .app-header, .sidebar, .action-buttons, .page-header, .overview-header .overview-meta,
            .stats-grid, .plan-overview > div:last-child, .term-overview:last-child {
                display: none !important;
            }
            
            .main-content {
                margin: 0 !important;
                padding: 20px !important;
            }
            
            .plan-overview, .term-overview {
                box-shadow: none !important;
                border: 1px solid #ddd !important;
                page-break-inside: avoid;
            }
            
            .overview-table {
                font-size: 12px !important;
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
            
            .term-title {
                color: black !important;
                border-bottom: 2px solid black !important;
            }
        }
    `;
    
    const styleSheet = document.createElement("style");
    styleSheet.type = "text/css";
    styleSheet.innerText = printStyles;
    document.head.appendChild(styleSheet);
    
    window.print();
    
    // Remove styles after printing
    setTimeout(() => {
        document.head.removeChild(styleSheet);
    }, 100);
}

// Show notification
function showNotification(message, type = 'info') {
    // Remove existing notifications
    const existing = document.querySelectorAll('.custom-notification');
    existing.forEach(n => n.remove());
    
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `custom-notification notification-${type}`;
    notification.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
        <span>${message}</span>
    `;
    
    // Style notification
    notification.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        padding: 12px 20px;
        background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : 'var(--primary-sea-blue)'};
        color: white;
        border-radius: 8px;
        display: flex;
        align-items: center;
        gap: 10px;
        z-index: 10000;
        animation: slideInRight 0.3s ease;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
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
        const form = document.getElementById('annualPlanForm');
        if (form) {
            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.click();
        }
    }
    
    // Ctrl+1,2,3 to switch terms
    if ((e.ctrlKey || e.metaKey) && e.key >= '1' && e.key <= '3') {
        e.preventDefault();
        const term = parseInt(e.key);
        const termTab = document.querySelector(`.term-tab[data-term="${term}"]`);
        if (termTab) termTab.click();
    }
    
    // Escape to cancel
    if (e.key === 'Escape' && (window.location.search.includes('create') || window.location.search.includes('edit'))) {
        if (formChanged) {
            if (confirm('You have unsaved changes. Cancel editing?')) {
                window.location.href = 'annual_plans.php' + (<?php echo isset($current_plan) ? "'?view=" . $current_plan['id'] . "'" : "''"; ?>);
            }
        } else {
            window.location.href = 'annual_plans.php' + (<?php echo isset($current_plan) ? "'?view=" . $current_plan['id'] . "'" : "''"; ?>);
        }
    }
});

// Handle beforeunload for unsaved changes
window.addEventListener('beforeunload', (e) => {
    if (formChanged) {
        e.preventDefault();
        e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
        return e.returnValue;
    }
});

// Quick fill functionality
function quickFillTemplate(templateName) {
    const templates = {
        'ges_math': {
            units: ['Number', 'Algebra', 'Geometry', 'Measurement', 'Data Handling'],
            topics: ['Counting', 'Patterns', 'Shapes', 'Length', 'Graphs']
        },
        'ges_english': {
            units: ['Reading', 'Writing', 'Listening & Speaking', 'Grammar', 'Comprehension'],
            topics: ['Phonics', 'Sentences', 'Conversation', 'Parts of Speech', 'Stories']
        },
        'ges_science': {
            units: ['Life Science', 'Physical Science', 'Earth & Space', 'Scientific Inquiry'],
            topics: ['Plants', 'Materials', 'Weather', 'Experiments']
        }
    };
    
    const template = templates[templateName];
    if (!template) return;
    
    // Fill weeks with template data
    for (let term = 1; term <= 3; term++) {
        for (let week = 1; week <= 13; week++) {
            const unitIndex = (week - 1) % template.units.length;
            const topicIndex = (week - 1) % template.topics.length;
            
            const unitInput = document.querySelector(`[name="term${term}_week${week}_unit"]`);
            const topicInput = document.querySelector(`[name="term${term}_week${week}_topic"]`);
            
            if (unitInput) unitInput.value = template.units[unitIndex];
            if (topicInput) topicInput.value = template.topics[topicIndex];
        }
    }
    
    formChanged = true;
    updateProgress();
    showNotification('Template applied successfully!', 'success');
}

// Add template buttons to form if creating new
<?php if (isset($_GET['create']) && !isset($current_plan)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const formHeader = document.querySelector('.form-header');
    if (formHeader) {
        const templateSection = document.createElement('div');
        templateSection.innerHTML = `
            <div style="margin: 20px 0; padding: 15px; background: var(--bg-secondary); border-radius: 8px;">
                <h4 style="margin: 0 0 10px 0; color: var(--text-primary);">
                    <i class="fas fa-magic"></i> Quick Templates
                </h4>
                <p style="color: var(--text-secondary); margin-bottom: 10px; font-size: 0.9rem;">
                    Apply a template to quickly fill in common topics:
                </p>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <button type="button" onclick="quickFillTemplate('ges_math')" class="btn btn-outline" style="font-size: 0.8rem;">
                        <i class="fas fa-calculator"></i> Mathematics
                    </button>
                    <button type="button" onclick="quickFillTemplate('ges_english')" class="btn btn-outline" style="font-size: 0.8rem;">
                        <i class="fas fa-language"></i> English
                    </button>
                    <button type="button" onclick="quickFillTemplate('ges_science')" class="btn btn-outline" style="font-size: 0.8rem;">
                        <i class="fas fa-flask"></i> Science
                    </button>
                </div>
            </div>
        `;
        formHeader.parentNode.insertBefore(templateSection, formHeader.nextSibling);
    }
});
<?php endif; ?>

// Copy to clipboard functionality
function copyPlanToClipboard() {
    const planText = generatePlanText();
    navigator.clipboard.writeText(planText)
        .then(() => showNotification('Plan copied to clipboard!', 'success'))
        .catch(() => showNotification('Failed to copy to clipboard', 'error'));
}

function generatePlanText() {
    let text = `Annual Plan: ${<?php echo isset($current_plan) ? json_encode($current_plan['subject_name']) : '""'; ?>}\n`;
    text += `Class: ${<?php echo isset($current_plan) ? json_encode($current_plan['class_name']) : '""'; ?>}\n`;
    text += `Academic Year: ${<?php echo isset($current_plan) ? json_encode($current_plan['academic_year']) : '""'; ?>}\n\n`;
    
    for (let term = 1; term <= 3; term++) {
        text += `TERM ${term}\n`;
        text += '='.repeat(50) + '\n';
        
        for (let week = 1; week <= 13; week++) {
            const unit = document.querySelector(`[name="term${term}_week${week}_unit"]`)?.value || '';
            const topic = document.querySelector(`[name="term${term}_week${week}_topic"]`)?.value || '';
            
            if (unit || topic) {
                text += `Week ${week}: ${unit} - ${topic}\n`;
            }
        }
        text += '\n';
    }
    
    return text;
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Update progress on page load
    setTimeout(updateProgress, 100);
});
</script>

<!-- Enhanced Hamburger Menu JavaScript (Fixed Version) -->
<script>
// Fixed Hamburger Menu Functionality
document.addEventListener('DOMContentLoaded', function() {
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');
    const mainContent = document.getElementById('mainContent');
    
    // Toggle sidebar
    function toggleSidebar() {
        sidebar.classList.toggle('open');
        sidebarOverlay.classList.toggle('active');
        mainContent.classList.toggle('shifted');
        
        // Update aria-expanded for accessibility
        const isExpanded = sidebar.classList.contains('open');
        menuToggle.setAttribute('aria-expanded', isExpanded);
        
        // Store state in localStorage for persistence
        localStorage.setItem('sidebarOpen', isExpanded);
    }
    
    // Close sidebar
    function closeSidebar() {
        sidebar.classList.remove('open');
        sidebarOverlay.classList.remove('active');
        mainContent.classList.remove('shifted');
        menuToggle.setAttribute('aria-expanded', 'false');
        localStorage.setItem('sidebarOpen', 'false');
    }
    
    // Initialize sidebar state from localStorage
    const savedState = localStorage.getItem('sidebarOpen');
    if (savedState === 'true') {
        sidebar.classList.add('open');
        sidebarOverlay.classList.add('active');
        mainContent.classList.add('shifted');
        menuToggle.setAttribute('aria-expanded', 'true');
    }
    
    // Event listeners
    if (menuToggle) {
        menuToggle.addEventListener('click', toggleSidebar);
        menuToggle.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggleSidebar();
            }
        });
    }
    
    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', closeSidebar);
    }
    
    // Close sidebar when clicking outside on mobile
    document.addEventListener('click', (e) => {
        if (window.innerWidth <= 992) {
            if (sidebar.classList.contains('open') && 
                !sidebar.contains(e.target) && 
                !menuToggle.contains(e.target) &&
                e.target !== sidebarOverlay) {
                closeSidebar();
            }
        }
    });
    
    // Handle escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && sidebar.classList.contains('open')) {
            closeSidebar();
        }
    });
    
    // Close sidebar when clicking a link on mobile
    const sidebarLinks = document.querySelectorAll('.sidebar a');
    sidebarLinks.forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 992) {
                closeSidebar();
            }
        });
    });
    
    // Toggle submenus
    const submenuToggles = document.querySelectorAll('.has-submenu > .menu-link');
    submenuToggles.forEach(toggle => {
        // Remove inline onclick
        toggle.removeAttribute('onclick');
        
        toggle.addEventListener('click', function(e) {
            e.preventDefault();
            const submenu = this.nextElementSibling;
            const arrow = this.querySelector('.menu-arrow');
            
            // Toggle current submenu
            submenu.classList.toggle('open');
            arrow.classList.toggle('rotated');
            
            // Close other submenus
            submenuToggles.forEach(otherToggle => {
                if (otherToggle !== this) {
                    const otherSubmenu = otherToggle.nextElementSibling;
                    const otherArrow = otherToggle.querySelector('.menu-arrow');
                    if (otherSubmenu && otherSubmenu.classList.contains('open')) {
                        otherSubmenu.classList.remove('open');
                        otherArrow.classList.remove('rotated');
                    }
                }
            });
        });
    });
    
    // Update active menu based on current page
    const currentPage = '<?php echo basename($_SERVER["PHP_SELF"]); ?>';
    const menuLinks = document.querySelectorAll('.sidebar .menu-link');
    
    menuLinks.forEach(link => {
        const href = link.getAttribute('href');
        if (href && href.includes(currentPage)) {
            link.classList.add('active');
            
            // Open parent submenu if this is a submenu link
            const submenuItem = link.closest('.submenu-item');
            if (submenuItem) {
                const parentLink = submenuItem.closest('.has-submenu').querySelector('.menu-link');
                const parentSubmenu = parentLink.nextElementSibling;
                const parentArrow = parentLink.querySelector('.menu-arrow');
                
                if (parentSubmenu && !parentSubmenu.classList.contains('open')) {
                    parentSubmenu.classList.add('open');
                    if (parentArrow) parentArrow.classList.add('rotated');
                }
            }
        } else {
            link.classList.remove('active');
        }
    });
    
    // Theme toggle
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', function() {
            const html = document.documentElement;
            const isDark = html.classList.contains('dark-mode');
            
            if (isDark) {
                html.classList.remove('dark-mode');
                document.cookie = 'dark_mode=false; path=/; max-age=31536000';
            } else {
                html.classList.add('dark-mode');
                document.cookie = 'dark_mode=true; path=/; max-age=31536000';
            }
        });
    }
    
    // Handle window resize
    let resizeTimer;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            if (window.innerWidth > 992 && sidebar.classList.contains('open')) {
                // Keep sidebar open on desktop
                mainContent.classList.add('shifted');
            } else if (window.innerWidth <= 992 && sidebar.classList.contains('open')) {
                // Remove shifted class on mobile
                mainContent.classList.remove('shifted');
            }
        }, 250);
    });
    
    // Initialize tooltips for icon buttons
    const iconButtons = document.querySelectorAll('.icon-btn');
    iconButtons.forEach(button => {
        const title = button.getAttribute('title');
        if (title) {
            button.setAttribute('aria-label', title);
        }
    });
    
    // Focus management for accessibility
    menuToggle.addEventListener('click', () => {
        if (sidebar.classList.contains('open')) {
            // When opening, focus first link in sidebar
            setTimeout(() => {
                const firstLink = sidebar.querySelector('a');
                if (firstLink) firstLink.focus();
            }, 100);
        } else {
            // When closing, return focus to menu toggle
            menuToggle.focus();
        }
    });
    
    // Trap focus within sidebar when open
    sidebar.addEventListener('keydown', (e) => {
        if (!sidebar.classList.contains('open')) return;
        
        if (e.key === 'Tab') {
            const focusableElements = sidebar.querySelectorAll('a, button, input, textarea, select');
            const firstElement = focusableElements[0];
            const lastElement = focusableElements[focusableElements.length - 1];
            
            if (e.shiftKey) {
                // Shift + Tab
                if (document.activeElement === firstElement) {
                    e.preventDefault();
                    lastElement.focus();
                }
            } else {
                // Tab
                if (document.activeElement === lastElement) {
                    e.preventDefault();
                    firstElement.focus();
                }
            }
        }
    });
    
    // Smooth scroll for sidebar
    sidebar.style.scrollBehavior = 'smooth';
});
</script>

<?php require_once dirname(__DIR__) . '/templates/teacher_footer.php'; ?>