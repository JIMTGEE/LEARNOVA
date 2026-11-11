<?php
// teacher/assignments_create.php
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

// Include database connection and CSRF functions
require_once $root_path . 'config/db.php';
require_once $root_path . 'functions/csrf.php';
require_once $root_path . 'includes/helpers.php';

// Initialize variables
$error = '';
$success = '';
$assignment_data = [
    'class_id' => '',
    'subject_id' => '',
    'term_id' => '',
    'title' => '',
    'description' => '',
    'type' => 'homework',
    'questions' => '',
    'max_points' => 100,
    'due_date' => '',
    'tags' => '',
    'link_url' => '',
    'is_group_assignment' => 0,
    'group_id' => '',
    'status' => 'draft'
];

// Get available data for dropdowns
try {
    $db = db();
    $teacher_id = $_SESSION['user_id'];
    
    // Get current term
    $current_term = null;
    $stmt = $db->prepare("
        SELECT t.*, ay.year 
        FROM terms t 
        JOIN academic_years ay ON t.academic_year_id = ay.id 
        WHERE CURDATE() BETWEEN t.start_date AND t.end_date 
        LIMIT 1
    ");
    $stmt->execute();
    $current_term = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get teacher's classes
    $stmt = $db->prepare("
        SELECT DISTINCT c.id, c.class_name 
        FROM classes c
        WHERE c.teacher_id = ? OR c.class_teacher_id = ?
        ORDER BY c.class_name
    ");
    $stmt->execute([$teacher_id, $teacher_id]);
    $teacher_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get subjects
    $stmt = $db->prepare("
        SELECT DISTINCT s.id, s.subject_name, s.subject_code
        FROM subjects s
        JOIN classes c ON (c.teacher_id = ? OR c.class_teacher_id = ?)
        WHERE s.status = 'Active'
        ORDER BY s.subject_name
    ");
    $stmt->execute([$teacher_id, $teacher_id]);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get terms
    $stmt = $db->prepare("
        SELECT t.id, t.name, ay.year, t.start_date, t.end_date
        FROM terms t
        JOIN academic_years ay ON t.academic_year_id = ay.id
        WHERE t.end_date >= CURDATE()
        ORDER BY t.start_date
    ");
    $stmt->execute();
    $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get available groups for group assignments
    $stmt = $db->prepare("
        SELECT g.id, g.name, g.class_id, c.class_name
        FROM groups g
        JOIN classes c ON g.class_id = c.id
        WHERE (c.teacher_id = ? OR c.class_teacher_id = ?)
        AND g.status = 'active'
        ORDER BY g.name
    ");
    $stmt->execute([$teacher_id, $teacher_id]);
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    error_log("Assignment creation initialization error: " . $e->getMessage());
    $error = "Unable to load form data. Please try again.";
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $error = "Security token invalid. Please try again.";
    } else {
        // Process form data
        $assignment_data = array_map('trim', $_POST);
        
        // Validate required fields
        $required_fields = ['class_id', 'subject_id', 'title', 'type', 'max_points'];
        foreach ($required_fields as $field) {
            if (empty($assignment_data[$field])) {
                $error = "Please fill in all required fields.";
                break;
            }
        }
        
        // Validate max points
        if (!$error && (!is_numeric($assignment_data['max_points']) || $assignment_data['max_points'] <= 0)) {
            $error = "Maximum points must be a positive number.";
        }
        
        // Validate due date if provided
        if (!$error && !empty($assignment_data['due_date'])) {
            $due_date = DateTime::createFromFormat('Y-m-d', $assignment_data['due_date']);
            if (!$due_date) {
                $error = "Invalid due date format. Please use YYYY-MM-DD.";
            } elseif ($due_date < new DateTime('today')) {
                $error = "Due date cannot be in the past.";
            }
        }
        
        // Validate questions for quiz type
        if (!$error && $assignment_data['type'] === 'quiz') {
            $questions = json_decode($assignment_data['questions'] ?? '[]', true);
            if (empty($questions) || !is_array($questions)) {
                $error = "Please add at least one question for the quiz.";
            } else {
                foreach ($questions as $index => $question) {
                    if (empty($question['text'])) {
                        $error = "Question " . ($index + 1) . " text is required.";
                        break;
                    }
                    if (empty($question['options']) || !is_array($question['options'])) {
                        $error = "Question " . ($index + 1) . " must have at least one option.";
                        break;
                    }
                    // Check if any option is empty
                    foreach ($question['options'] as $optIndex => $option) {
                        if (empty($option)) {
                            $error = "Question " . ($index + 1) . ", Option " . ($optIndex + 1) . " cannot be empty.";
                            break 2;
                        }
                    }
                    // Check if correct answer is set
                    if (!isset($question['correct']) || !is_numeric($question['correct'])) {
                        $error = "Please select the correct answer for Question " . ($index + 1);
                        break;
                    }
                }
            }
        }
        
        // If no errors, save assignment
        if (!$error) {
            try {
                // Handle file upload if present
                $file_path = '';
                if (isset($_FILES['assignment_file']) && $_FILES['assignment_file']['error'] === UPLOAD_ERR_OK) {
                    $upload_dir = $root_path . 'Uploads/assignments/';
                    if (!file_exists($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }
                    
                    $file_ext = strtolower(pathinfo($_FILES['assignment_file']['name'], PATHINFO_EXTENSION));
                    $allowed_ext = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'ppt', 'pptx', 'xlsx', 'zip', 'rar'];
                    
                    if (!in_array($file_ext, $allowed_ext)) {
                        $error = "File type not allowed. Allowed types: " . implode(', ', $allowed_ext);
                    } else {
                        $max_size = 50 * 1024 * 1024; // 50MB
                        if ($_FILES['assignment_file']['size'] > $max_size) {
                            $error = "File size must be less than 50MB.";
                        } else {
                            $file_name = uniqid('assign_', true) . '.' . $file_ext;
                            $target_file = $upload_dir . $file_name;
                            
                            if (move_uploaded_file($_FILES['assignment_file']['tmp_name'], $target_file)) {
                                $file_path = 'assignments/' . $file_name;
                            } else {
                                $error = "Failed to upload file.";
                            }
                        }
                    }
                }
                
                if (!$error) {
                    $db->beginTransaction();
                    
                    // Insert assignment
                    $stmt = $db->prepare("
                        INSERT INTO assignments (
                            class_id, subject_id, teacher_id, term_id, title,
                            description, type, questions, max_points, file_path,
                            link_url, is_group_assignment, group_id, status,
                            due_date, tags, created_at, updated_at
                        ) VALUES (
                            :class_id, :subject_id, :teacher_id, :term_id, :title,
                            :description, :type, :questions, :max_points, :file_path,
                            :link_url, :is_group_assignment, :group_id, :status,
                            :due_date, :tags, NOW(), NOW()
                        )
                    ");
                    
                    $params = [
                        ':class_id' => $assignment_data['class_id'],
                        ':subject_id' => $assignment_data['subject_id'],
                        ':teacher_id' => $teacher_id,
                        ':term_id' => !empty($assignment_data['term_id']) ? $assignment_data['term_id'] : ($current_term ? $current_term['id'] : null),
                        ':title' => $assignment_data['title'],
                        ':description' => $assignment_data['description'],
                        ':type' => $assignment_data['type'],
                        ':questions' => $assignment_data['questions'] ?? '',
                        ':max_points' => $assignment_data['max_points'],
                        ':file_path' => $file_path,
                        ':link_url' => $assignment_data['link_url'] ?? '',
                        ':is_group_assignment' => isset($assignment_data['is_group_assignment']) ? 1 : 0,
                        ':group_id' => !empty($assignment_data['group_id']) ? $assignment_data['group_id'] : null,
                        ':status' => $assignment_data['status'],
                        ':due_date' => !empty($assignment_data['due_date']) ? $assignment_data['due_date'] : null,
                        ':tags' => $assignment_data['tags'] ?? ''
                    ];
                    
                    $stmt->execute($params);
                    $assignment_id = $db->lastInsertId();
                    
                    // Insert assignment questions if provided (for quiz type)
                    if (!empty($assignment_data['questions']) && $assignment_data['type'] === 'quiz') {
                        $questions = json_decode($assignment_data['questions'], true);
                        if (is_array($questions)) {
                            $question_stmt = $db->prepare("
                                INSERT INTO assignment_questions (assignment_id, question_text, question_number, options, correct_answer)
                                VALUES (:assignment_id, :question_text, :question_number, :options, :correct_answer)
                            ");
                            
                            $question_num = 1;
                            foreach ($questions as $question) {
                                $question_stmt->execute([
                                    ':assignment_id' => $assignment_id,
                                    ':question_text' => $question['text'] ?? '',
                                    ':question_number' => $question_num,
                                    ':options' => json_encode($question['options'] ?? []),
                                    ':correct_answer' => $question['correct'] ?? 0
                                ]);
                                $question_num++;
                            }
                        }
                    }
                    
                    // Create notification for students if published
                    if ($assignment_data['status'] === 'published') {
                        $notification_stmt = $db->prepare("
                            INSERT INTO notifications (user_id, target_role, title, message, type, link, category, created_at)
                            SELECT 
                                sc.user_id, 
                                'student' as target_role,
                                :title,
                                :message,
                                'assignment',
                                :link,
                                'academic',
                                NOW()
                            FROM student_classes sc
                            WHERE sc.class_id = :class_id
                            AND sc.user_id IS NOT NULL
                        ");
                        
                        $assignment_link = '../../student/assignments_view.php?id=' . $assignment_id;
                        
                        $notification_stmt->execute([
                            ':title' => '📚 New Assignment: ' . $assignment_data['title'],
                            ':message' => 'A new ' . $assignment_data['type'] . ' has been assigned to your class. Due: ' . (!empty($assignment_data['due_date']) ? date('M d, Y', strtotime($assignment_data['due_date'])) : 'No due date'),
                            ':link' => $assignment_link,
                            ':class_id' => $assignment_data['class_id']
                        ]);
                        
                        // Also notify parents of students
                        $parent_notification_stmt = $db->prepare("
                            INSERT INTO notifications (user_id, target_role, title, message, type, link, category, created_at)
                            SELECT 
                                p.parent_id, 
                                'parent' as target_role,
                                :title,
                                :message,
                                'assignment',
                                :link,
                                'academic',
                                NOW()
                            FROM parent_student_mapping p
                            JOIN student_classes sc ON p.student_id = sc.user_id
                            WHERE sc.class_id = :class_id
                            AND p.parent_id IS NOT NULL
                        ");
                        
                        $parent_notification_stmt->execute([
                            ':title' => '👨‍👩‍👧 Child\'s New Assignment: ' . $assignment_data['title'],
                            ':message' => 'Your child has been assigned a new ' . $assignment_data['type'] . '. Please ensure they complete it on time.',
                            ':link' => $assignment_link,
                            ':class_id' => $assignment_data['class_id']
                        ]);
                    }
                    
                    // Log activity
                    log_activity($teacher_id, 'create_assignment', 'Created assignment: ' . $assignment_data['title'] . ' (ID: ' . $assignment_id . ')');
                    
                    $db->commit();
                    
                    $success = "Assignment created successfully!";
                    
                    // Clear form data on success
                    $assignment_data = [
                        'class_id' => '',
                        'subject_id' => '',
                        'term_id' => '',
                        'title' => '',
                        'description' => '',
                        'type' => 'homework',
                        'questions' => '',
                        'max_points' => 100,
                        'due_date' => '',
                        'tags' => '',
                        'link_url' => '',
                        'is_group_assignment' => 0,
                        'group_id' => '',
                        'status' => 'draft'
                    ];
                    
                    // If published, redirect to assignments list
                    if (isset($_POST['publish']) && $_POST['publish'] === 'publish') {
                        header("Location: assignments.php?success=Assignment published successfully!");
                        exit();
                    }
                }
            } catch (Exception $e) {
                if (isset($db)) {
                    $db->rollBack();
                }
                error_log("Assignment creation error: " . $e->getMessage());
                $error = "Failed to create assignment. Please try again. Error: " . $e->getMessage();
            }
        }
    }
}

// Generate CSRF token
$csrf_token = generate_csrf_token();

// Include header
include $root_path . 'templates/teacher_header.php';
?>

<!-- Create Assignment Page -->
<section class="dashboard">
    <!-- Page Header -->
    <div class="dashboard-header">
        <h1 class="page-title">Create New Assignment</h1>
        <div class="dashboard-subtitle">
            <span class="welcome">Create assignments, quizzes, and assessments for your classes</span>
            <div class="header-actions">
                <a href="assignments.php" class="btn-back">
                    <i class="fas fa-arrow-left"></i> Back to Assignments
                </a>
                <button type="button" id="theme-toggle" class="btn-icon" title="Toggle theme">
                    <i class="fas fa-moon"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Alerts -->
    <?php if ($error): ?>
    <div class="alert alert-error alert-dismissible">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="alert-close">&times;</button>
    </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="alert-close">&times;</button>
        <div class="alert-actions">
            <a href="assignments.php" class="btn-small">View All Assignments</a>
            <a href="assignments_create.php" class="btn-small outline">Create Another</a>
            <a href="assignments_view.php?id=<?php echo $assignment_id ?? ''; ?>" class="btn-small">View Assignment</a>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main Form -->
    <div class="card">
        <form method="POST" enctype="multipart/form-data" id="assignment-form" class="assignment-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            
            <div class="form-grid">
                <!-- Basic Information -->
                <div class="form-section">
                    <h3 class="form-section-title">
                        <i class="fas fa-info-circle"></i> Basic Information
                    </h3>
                    
                    <div class="form-group">
                        <label for="title" class="form-label">
                            Assignment Title <span class="required">*</span>
                        </label>
                        <input type="text" id="title" name="title" class="form-control" 
                               value="<?php echo htmlspecialchars($assignment_data['title']); ?>"
                               required maxlength="255" placeholder="Enter assignment title">
                        <div class="form-error" id="title-error"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="description" class="form-label">Description</label>
                        <textarea id="description" name="description" class="form-control rich-editor" 
                                  rows="4" placeholder="Describe the assignment..."><?php echo htmlspecialchars($assignment_data['description']); ?></textarea>
                        <small class="form-hint">You can use basic HTML formatting</small>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="type" class="form-label">
                                Type <span class="required">*</span>
                            </label>
                            <select id="type" name="type" class="form-control" required>
                                <option value="homework" <?php echo $assignment_data['type'] === 'homework' ? 'selected' : ''; ?>>Homework</option>
                                <option value="classwork" <?php echo $assignment_data['type'] === 'classwork' ? 'selected' : ''; ?>>Classwork</option>
                                <option value="quiz" <?php echo $assignment_data['type'] === 'quiz' ? 'selected' : ''; ?>>Quiz</option>
                                <option value="test" <?php echo $assignment_data['type'] === 'test' ? 'selected' : ''; ?>>Test</option>
                                <option value="project" <?php echo $assignment_data['type'] === 'project' ? 'selected' : ''; ?>>Project</option>
                                <option value="essay" <?php echo $assignment_data['type'] === 'essay' ? 'selected' : ''; ?>>Essay</option>
                                <option value="presentation" <?php echo $assignment_data['type'] === 'presentation' ? 'selected' : ''; ?>>Presentation</option>
                                <option value="research" <?php echo $assignment_data['type'] === 'research' ? 'selected' : ''; ?>>Research Paper</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="max_points" class="form-label">
                                Maximum Points <span class="required">*</span>
                            </label>
                            <div class="input-with-suffix">
                                <input type="number" id="max_points" name="max_points" 
                                       class="form-control" min="1" max="1000" step="0.5"
                                       value="<?php echo htmlspecialchars($assignment_data['max_points']); ?>"
                                       required>
                                <span class="input-suffix">points</span>
                            </div>
                            <small class="form-hint">Maximum score students can achieve</small>
                            <div class="form-error" id="max_points-error"></div>
                        </div>
                    </div>
                </div>

                <!-- Class & Subject -->
                <div class="form-section">
                    <h3 class="form-section-title">
                        <i class="fas fa-chalkboard-teacher"></i> Class & Subject
                    </h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="class_id" class="form-label">
                                Class <span class="required">*</span>
                            </label>
                            <select id="class_id" name="class_id" class="form-control" required>
                                <option value="">Select a class</option>
                                <?php foreach ($teacher_classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>"
                                    <?php echo $assignment_data['class_id'] == $class['id'] ? 'selected' : ''; ?>
                                    data-students="<?php echo getClassStudentCount($class['id']); ?>">
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-hint" id="class-info"></div>
                        </div>
                        
                        <div class="form-group">
                            <label for="subject_id" class="form-label">
                                Subject <span class="required">*</span>
                            </label>
                            <select id="subject_id" name="subject_id" class="form-control" required>
                                <option value="">Select a subject</option>
                                <?php foreach ($subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>"
                                    <?php echo $assignment_data['subject_id'] == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['subject_name']); ?> (<?php echo $subject['subject_code']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="term_id" class="form-label">Term</label>
                            <select id="term_id" name="term_id" class="form-control">
                                <option value="">Select term (optional)</option>
                                <?php foreach ($terms as $term): ?>
                                <option value="<?php echo $term['id']; ?>"
                                    <?php echo $assignment_data['term_id'] == $term['id'] ? 'selected' : ''; ?>
                                    <?php echo $current_term && $term['id'] == $current_term['id'] ? 'data-current="true"' : ''; ?>
                                    data-start="<?php echo $term['start_date']; ?>"
                                    data-end="<?php echo $term['end_date']; ?>">
                                    <?php echo htmlspecialchars($term['name'] . ' - ' . $term['year']); ?>
                                    <?php echo $current_term && $term['id'] == $current_term['id'] ? '(Current)' : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="due_date" class="form-label">Due Date</label>
                            <input type="date" id="due_date" name="due_date" 
                                   class="form-control"
                                   value="<?php echo htmlspecialchars($assignment_data['due_date']); ?>"
                                   min="<?php echo date('Y-m-d'); ?>">
                            <small class="form-hint">Leave empty for no due date</small>
                            <div class="form-error" id="due_date-error"></div>
                        </div>
                    </div>
                </div>

                <!-- Assignment Content -->
                <div class="form-section full-width">
                    <h3 class="form-section-title">
                        <i class="fas fa-file-alt"></i> Assignment Content
                    </h3>
                    
                    <!-- Questions Editor (for quizzes) -->
                    <div id="questions-section" class="form-group" style="display: none;">
                        <label class="form-label">Quiz Questions <span class="required">*</span></label>
                        <div class="questions-editor">
                            <div class="questions-header">
                                <span id="questions-count">0 questions</span>
                                <span id="total-points">Total: 0 points</span>
                            </div>
                            <div id="questions-container">
                                <!-- Questions will be added here dynamically -->
                            </div>
                            <div class="questions-actions">
                                <button type="button" id="add-question" class="btn-small primary">
                                    <i class="fas fa-plus"></i> Add Question
                                </button>
                                <button type="button" id="add-multiple-choice" class="btn-small outline">
                                    <i class="fas fa-list-ol"></i> Add Multiple Choice
                                </button>
                                <button type="button" id="add-true-false" class="btn-small outline">
                                    <i class="fas fa-check-circle"></i> Add True/False
                                </button>
                            </div>
                            <textarea id="questions-json" name="questions" style="display: none;"></textarea>
                            <div class="form-error" id="questions-error"></div>
                        </div>
                    </div>
                    
                    <!-- File Upload -->
                    <div class="form-group">
                        <label for="assignment_file" class="form-label">Assignment File</label>
                        <div class="file-upload">
                            <input type="file" id="assignment_file" name="assignment_file" 
                                   class="file-input" accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.ppt,.pptx,.xlsx,.zip,.rar">
                            <div class="file-upload-area" id="file-drop-area">
                                <i class="fas fa-cloud-upload-alt"></i>
                                <p>Drag & drop assignment file here or <span class="browse-link">click to browse</span></p>
                                <small>Max size: 50MB. Allowed: PDF, DOC, DOCX, TXT, JPG, PNG, PPT, PPTX, XLSX, ZIP, RAR</small>
                            </div>
                            <div id="file-preview" class="file-preview"></div>
                            <div class="form-error" id="file-error"></div>
                        </div>
                    </div>
                    
                    <!-- External Link -->
                    <div class="form-group">
                        <label for="link_url" class="form-label">External Link (Optional)</label>
                        <div class="input-with-icon">
                            <i class="fas fa-link"></i>
                            <input type="url" id="link_url" name="link_url" class="form-control"
                                   placeholder="https://example.com/assignment-resource"
                                   value="<?php echo htmlspecialchars($assignment_data['link_url'] ?? ''); ?>">
                        </div>
                        <small class="form-hint">Link to external resources or online content</small>
                    </div>
                </div>

                <!-- Advanced Options -->
                <div class="form-section">
                    <h3 class="form-section-title">
                        <i class="fas fa-cog"></i> Advanced Options
                    </h3>
                    
                    <div class="form-group">
                        <label class="form-label">Assignment Settings</label>
                        <div class="checkbox-group">
                            <label class="checkbox">
                                <input type="checkbox" name="is_group_assignment" value="1"
                                    <?php echo $assignment_data['is_group_assignment'] ? 'checked' : ''; ?>
                                    id="group-toggle">
                                <span class="checkmark"></span>
                                Group Assignment
                            </label>
                            <small class="form-hint">Allow students to submit as a group</small>
                        </div>
                    </div>
                    
                    <!-- Group Selection (only shown when group assignment is checked) -->
                    <div id="group-section" class="form-group" style="display: none;">
                        <label for="group_id" class="form-label">Select Group</label>
                        <select id="group_id" name="group_id" class="form-control">
                            <option value="">Select a group (optional)</option>
                            <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"
                                <?php echo $assignment_data['group_id'] == $group['id'] ? 'selected' : ''; ?>
                                data-class="<?php echo $group['class_id']; ?>">
                                <?php echo htmlspecialchars($group['name'] . ' - ' . $group['class_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-hint">Assign to specific group or leave empty for all groups</small>
                    </div>
                    
                    <!-- Tags -->
                    <div class="form-group">
                        <label for="tags" class="form-label">Tags</label>
                        <input type="text" id="tags" name="tags" class="form-control tags-input"
                               value="<?php echo htmlspecialchars($assignment_data['tags']); ?>"
                               placeholder="Type and press Enter to add tags...">
                        <div id="tags-container" class="tags-container"></div>
                        <small class="form-hint">Tags help organize and search assignments</small>
                    </div>
                    
                    <!-- Instructions for Students -->
                    <div class="form-group">
                        <label for="instructions" class="form-label">Special Instructions</label>
                        <textarea id="instructions" name="instructions" class="form-control" 
                                  rows="3" placeholder="Any special instructions for students..."></textarea>
                    </div>
                </div>

                <!-- Publish Options -->
                <div class="form-section">
                    <h3 class="form-section-title">
                        <i class="fas fa-paper-plane"></i> Publish Options
                    </h3>
                    
                    <div class="form-group">
                        <label for="status" class="form-label">Status</label>
                        <div class="radio-group">
                            <label class="radio">
                                <input type="radio" name="status" value="draft"
                                    <?php echo $assignment_data['status'] === 'draft' ? 'checked' : ''; ?>>
                                <span class="radiomark"></span>
                                <div class="radio-content">
                                    <strong>Save as Draft</strong>
                                    <small>Only you can see this assignment</small>
                                </div>
                            </label>
                            <label class="radio">
                                <input type="radio" name="status" value="published"
                                    <?php echo $assignment_data['status'] === 'published' ? 'checked' : ''; ?>>
                                <span class="radiomark"></span>
                                <div class="radio-content">
                                    <strong>Publish Now</strong>
                                    <small>Students and parents will be notified immediately</small>
                                </div>
                            </label>
                            <label class="radio">
                                <input type="radio" name="status" value="scheduled"
                                    <?php echo $assignment_data['status'] === 'scheduled' ? 'checked' : ''; ?>>
                                <span class="radiomark"></span>
                                <div class="radio-content">
                                    <strong>Schedule Publication</strong>
                                    <small>Set a specific date and time to publish</small>
                                </div>
                            </label>
                        </div>
                    </div>
                    
                    <!-- Schedule Options -->
                    <div id="schedule-section" class="form-group" style="display: none;">
                        <label for="publish_date" class="form-label">Publish Date & Time</label>
                        <div class="form-row">
                            <div class="form-group">
                                <input type="date" id="publish_date" name="publish_date" class="form-control"
                                       min="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="form-group">
                                <input type="time" id="publish_time" name="publish_time" class="form-control"
                                       value="09:00">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Preview & Submit -->
                    <div class="form-actions">
                        <button type="button" id="preview-btn" class="btn-secondary">
                            <i class="fas fa-eye"></i> Preview
                        </button>
                        <button type="button" id="save-template" class="btn-secondary">
                            <i class="fas fa-save"></i> Save as Template
                        </button>
                        <button type="submit" name="save_draft" value="draft" class="btn-secondary">
                            <i class="fas fa-save"></i> Save Draft
                        </button>
                        <button type="submit" name="publish" value="publish" class="btn-primary">
                            <i class="fas fa-paper-plane"></i> Publish Assignment
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Quick Help -->
    <div class="card help-card">
        <h3 class="card-title">
            <i class="fas fa-question-circle"></i> Quick Help
        </h3>
        <div class="help-content">
            <div class="help-item">
                <strong>Assignment Types:</strong>
                <ul>
                    <li><strong>Homework:</strong> Take-home assignments</li>
                    <li><strong>Classwork:</strong> In-class assignments</li>
                    <li><strong>Quiz:</strong> Multiple-choice or short answer questions</li>
                    <li><strong>Test:</strong> Formal examinations</li>
                    <li><strong>Project:</strong> Long-term assignments</li>
                    <li><strong>Essay:</strong> Written compositions</li>
                    <li><strong>Presentation:</strong> Oral presentations</li>
                    <li><strong>Research:</strong> Research papers</li>
                </ul>
            </div>
            <div class="help-item">
                <strong>Tips:</strong>
                <ul>
                    <li>Use tags to organize assignments by topic</li>
                    <li>Set realistic due dates</li>
                    <li>Provide clear descriptions and instructions</li>
                    <li>Use draft mode to prepare assignments before publishing</li>
                    <li>Group assignments are great for collaborative projects</li>
                    <li>Add relevant files or links for better context</li>
                </ul>
            </div>
            <div class="help-item">
                <strong>Quiz Creation:</strong>
                <ul>
                    <li>Add multiple-choice questions with one correct answer</li>
                    <li>Include True/False questions for quick assessment</li>
                    <li>Set points for each question</li>
                    <li>Preview the quiz before publishing</li>
                </ul>
            </div>
        </div>
    </div>
</section>

<!-- CSS Styles -->
<style>
/* Theme Variables */
:root {
    /* Light Theme */
    --primary-sea-blue: #17A2B8;
    --primary-dark: #081F2C;
    --primary-light: #35D0FF;
    --text-primary: #0B0D10;
    --text-secondary: #5E6A79;
    --text-muted: #8A94A6;
    --bg-primary: #FFFFFF;
    --bg-secondary: #F8F9FA;
    --bg-tertiary: #E9ECEF;
    --border-color: #DEE2E6;
    --border-light: #E9ECEF;
    --card-bg: #FFFFFF;
    --card-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
    --card-shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.12);
    --success-color: #28A745;
    --warning-color: #FFC107;
    --error-color: #DC3545;
    --info-color: #17A2B8;
}

[data-theme="dark"] {
    /* Dark Theme */
    --primary-sea-blue: #35D0FF;
    --primary-dark: #0B0D10;
    --primary-light: #17A2B8;
    --text-primary: #F8F9FA;
    --text-secondary: #CED4DA;
    --text-muted: #8A94A6;
    --bg-primary: #0B0D10;
    --bg-secondary: #161B22;
    --bg-tertiary: #212529;
    --border-color: #343A40;
    --border-light: #495057;
    --card-bg: #161B22;
    --card-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    --card-shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.4);
    --success-color: #28A745;
    --warning-color: #FFC107;
    --error-color: #DC3545;
    --info-color: #35D0FF;
}

/* Form Layout */
.assignment-form {
    width: 100%;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 24px;
}

.form-section {
    background: var(--bg-secondary);
    border-radius: 12px;
    padding: 24px;
    border: 1px solid var(--border-color);
    transition: all 0.3s ease;
}

.form-section:hover {
    border-color: var(--primary-sea-blue);
    box-shadow: var(--card-shadow);
}

.form-section.full-width {
    grid-column: 1 / -1;
}

.form-section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--primary-sea-blue);
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
}

/* Form Elements */
.form-group {
    margin-bottom: 20px;
}

.form-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 16px;
}

.form-label {
    display: block;
    margin-bottom: 8px;
    font-weight: 500;
    color: var(--text-primary);
}

.form-control {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: var(--card-bg);
    color: var(--text-primary);
    font-size: 0.95rem;
    transition: all 0.2s ease;
    font-family: 'Inter', sans-serif;
}

.form-control:focus {
    outline: none;
    border-color: var(--primary-sea-blue);
    box-shadow: 0 0 0 3px rgba(23, 162, 184, 0.1);
}

.form-control::placeholder {
    color: var(--text-muted);
}

textarea.form-control {
    min-height: 100px;
    resize: vertical;
    line-height: 1.5;
}

select.form-control {
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%235E6A79' viewBox='0 0 16 16'%3E%3Cpath d='M7.247 11.14 2.451 5.658C1.885 5.013 2.345 4 3.204 4h9.592a1 1 0 0 1 .753 1.659l-4.796 5.48a1 1 0 0 1-1.506 0z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 15px center;
    padding-right: 40px;
    cursor: pointer;
}

.form-hint {
    display: block;
    margin-top: 6px;
    font-size: 0.85rem;
    color: var(--text-secondary);
    line-height: 1.4;
}

.form-error {
    display: block;
    margin-top: 6px;
    font-size: 0.85rem;
    color: var(--error-color);
    display: none;
}

.form-error.show {
    display: block;
}

.required {
    color: var(--error-color);
}

/* Input with Icon/Suffix */
.input-with-icon {
    position: relative;
}

.input-with-icon .fas {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    z-index: 1;
}

.input-with-icon .form-control {
    padding-left: 44px;
}

.input-with-suffix {
    position: relative;
}

.input-suffix {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-muted);
    font-size: 0.9rem;
}

.input-with-suffix .form-control {
    padding-right: 60px;
}

/* File Upload */
.file-upload {
    margin-top: 10px;
}

.file-input {
    display: none;
}

.file-upload-area {
    border: 2px dashed var(--border-color);
    border-radius: 12px;
    padding: 40px 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: var(--bg-tertiary);
    position: relative;
    overflow: hidden;
}

.file-upload-area:hover,
.file-upload-area.dragover {
    border-color: var(--primary-sea-blue);
    background: rgba(23, 162, 184, 0.05);
    transform: translateY(-2px);
}

.file-upload-area.dragover::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(23, 162, 184, 0.1);
    z-index: 1;
}

.file-upload-area i {
    font-size: 3rem;
    color: var(--primary-sea-blue);
    margin-bottom: 16px;
    transition: transform 0.3s ease;
}

.file-upload-area:hover i {
    transform: translateY(-4px);
}

.file-upload-area p {
    margin-bottom: 10px;
    color: var(--text-primary);
    font-weight: 500;
    font-size: 1.1rem;
}

.file-upload-area .browse-link {
    color: var(--primary-sea-blue);
    text-decoration: underline;
    cursor: pointer;
}

.file-upload-area small {
    color: var(--text-secondary);
    display: block;
    max-width: 400px;
    margin: 0 auto;
}

.file-preview {
    margin-top: 20px;
    padding: 20px;
    background: var(--bg-secondary);
    border-radius: 12px;
    border: 1px solid var(--border-color);
    display: none;
}

.file-preview.show {
    display: block;
    animation: slideDown 0.3s ease;
}

.file-preview-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 12px 16px;
    background: var(--card-bg);
    border-radius: 8px;
    margin-bottom: 12px;
    transition: all 0.2s ease;
    border: 1px solid var(--border-light);
}

.file-preview-item:hover {
    border-color: var(--primary-sea-blue);
    box-shadow: var(--card-shadow);
}

.file-preview-item:last-child {
    margin-bottom: 0;
}

.file-info {
    display: flex;
    align-items: center;
    gap: 12px;
    flex: 1;
    min-width: 0;
}

.file-icon {
    font-size: 1.5rem;
    color: var(--primary-sea-blue);
    flex-shrink: 0;
}

.file-details {
    flex: 1;
    min-width: 0;
}

.file-name {
    color: var(--text-primary);
    font-weight: 500;
    margin-bottom: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.file-size {
    font-size: 0.85rem;
    color: var(--text-secondary);
}

.remove-file {
    background: none;
    border: none;
    color: var(--error-color);
    cursor: pointer;
    font-size: 1.2rem;
    padding: 8px;
    border-radius: 6px;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.remove-file:hover {
    background: rgba(220, 53, 69, 0.1);
}

/* Questions Editor */
.questions-editor {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 24px;
    box-shadow: var(--card-shadow);
}

.questions-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border-light);
}

#questions-count, #total-points {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.95rem;
}

#total-points {
    color: var(--primary-sea-blue);
}

.questions-actions {
    display: flex;
    gap: 12px;
    margin-top: 20px;
    flex-wrap: wrap;
}

.question-item {
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 20px;
    transition: all 0.3s ease;
    position: relative;
}

.question-item:hover {
    border-color: var(--primary-sea-blue);
    box-shadow: var(--card-shadow);
}

.question-item.editing {
    border-color: var(--primary-sea-blue);
    box-shadow: 0 0 0 3px rgba(23, 162, 184, 0.1);
}

.question-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border-light);
}

.question-number {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 1.1rem;
}

.question-points {
    display: flex;
    align-items: center;
    gap: 8px;
}

.question-points input {
    width: 80px;
    padding: 6px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--card-bg);
    color: var(--text-primary);
}

.question-actions {
    display: flex;
    gap: 8px;
}

.question-action-btn {
    background: none;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 6px;
    border-radius: 6px;
    transition: all 0.2s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.question-action-btn:hover {
    background: var(--bg-tertiary);
    color: var(--text-primary);
}

.question-text {
    width: 100%;
    padding: 12px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    margin-bottom: 16px;
    background: var(--card-bg);
    color: var(--text-primary);
    font-size: 0.95rem;
    font-family: 'Inter', sans-serif;
    transition: all 0.2s ease;
}

.question-text:focus {
    outline: none;
    border-color: var(--primary-sea-blue);
    box-shadow: 0 0 0 3px rgba(23, 162, 184, 0.1);
}

.question-type {
    margin-bottom: 16px;
}

.question-type select {
    width: 200px;
}

.options-list {
    margin-top: 16px;
}

.option-item {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 12px;
    padding: 12px;
    background: var(--bg-tertiary);
    border-radius: 8px;
    transition: all 0.2s ease;
}

.option-item:hover {
    background: var(--bg-secondary);
}

.option-item.correct {
    background: rgba(40, 167, 69, 0.1);
    border-left: 3px solid var(--success-color);
}

.option-item .option-number {
    font-weight: 500;
    color: var(--text-secondary);
    min-width: 24px;
}

.option-input {
    flex: 1;
    padding: 10px 12px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--card-bg);
    color: var(--text-primary);
    font-size: 0.95rem;
    transition: all 0.2s ease;
}

.option-input:focus {
    outline: none;
    border-color: var(--primary-sea-blue);
}

.correct-option {
    width: 20px;
    height: 20px;
    cursor: pointer;
    accent-color: var(--success-color);
}

.option-actions {
    display: flex;
    gap: 8px;
}

.option-action-btn {
    background: none;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 4px;
    border-radius: 4px;
    transition: all 0.2s ease;
}

.option-action-btn:hover {
    background: var(--bg-tertiary);
    color: var(--text-primary);
}

.add-option-btn {
    background: var(--bg-tertiary);
    border: 1px dashed var(--border-color);
    color: var(--text-secondary);
    padding: 10px 16px;
    border-radius: 8px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    font-size: 0.95rem;
}

.add-option-btn:hover {
    background: var(--bg-secondary);
    border-color: var(--primary-sea-blue);
    color: var(--text-primary);
}

/* Checkbox & Radio */
.checkbox-group,
.radio-group {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

.checkbox,
.radio {
    display: flex;
    align-items: flex-start;
    cursor: pointer;
    position: relative;
    padding-left: 40px;
    user-select: none;
    transition: all 0.2s ease;
}

.checkbox:hover,
.radio:hover {
    color: var(--primary-sea-blue);
}

.checkbox input,
.radio input {
    position: absolute;
    opacity: 0;
    cursor: pointer;
}

.checkmark,
.radiomark {
    position: absolute;
    left: 0;
    top: 2px;
    height: 20px;
    width: 20px;
    background: var(--card-bg);
    border: 2px solid var(--border-color);
    border-radius: 4px;
    transition: all 0.2s ease;
}

.radiomark {
    border-radius: 50%;
}

.checkbox:hover .checkmark,
.radio:hover .radiomark {
    border-color: var(--primary-sea-blue);
}

.checkbox input:checked ~ .checkmark {
    background: var(--primary-sea-blue);
    border-color: var(--primary-sea-blue);
}

.radio input:checked ~ .radiomark {
    border-color: var(--primary-sea-blue);
}

.checkmark:after,
.radiomark:after {
    content: "";
    position: absolute;
    display: none;
}

.checkbox input:checked ~ .checkmark:after {
    display: block;
    left: 6px;
    top: 2px;
    width: 5px;
    height: 10px;
    border: solid white;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}

.radio input:checked ~ .radiomark:after {
    display: block;
    top: 5px;
    left: 5px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--primary-sea-blue);
}

.radio-content {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.radio-content strong {
    color: var(--text-primary);
    font-weight: 500;
}

.radio-content small {
    color: var(--text-secondary);
    font-size: 0.85rem;
}

/* Tags Input */
.tags-input {
    margin-bottom: 8px;
}

.tags-container {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 8px;
}

.tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: rgba(23, 162, 184, 0.1);
    color: var(--primary-sea-blue);
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 500;
}

.tag-remove {
    background: none;
    border: none;
    color: inherit;
    cursor: pointer;
    padding: 0;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 16px;
    height: 16px;
    border-radius: 50%;
}

.tag-remove:hover {
    background: rgba(0, 0, 0, 0.1);
}

/* Form Actions */
.form-actions {
    display: flex;
    justify-content: flex-end;
    gap: 16px;
    margin-top: 32px;
    padding-top: 24px;
    border-top: 1px solid var(--border-color);
    flex-wrap: wrap;
}

.btn-primary,
.btn-secondary,
.btn-outline {
    padding: 12px 24px;
    border-radius: 8px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: none;
    font-size: 0.95rem;
    font-family: 'Inter', sans-serif;
}

.btn-primary {
    background: var(--primary-sea-blue);
    color: white;
}

.btn-primary:hover {
    background: #148a9c;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(23, 162, 184, 0.3);
}

.btn-secondary {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}

.btn-secondary:hover {
    background: var(--bg-tertiary);
    transform: translateY(-2px);
    box-shadow: var(--card-shadow);
}

.btn-outline {
    background: transparent;
    color: var(--primary-sea-blue);
    border: 1px solid var(--primary-sea-blue);
}

.btn-outline:hover {
    background: rgba(23, 162, 184, 0.1);
    transform: translateY(-2px);
}

.btn-small {
    padding: 8px 16px;
    font-size: 0.85rem;
}

.btn-small.primary {
    background: var(--primary-sea-blue);
    color: white;
    border: none;
}

.btn-small.primary:hover {
    background: #148a9c;
}

.btn-small.outline {
    background: transparent;
    border: 1px solid var(--primary-sea-blue);
    color: var(--primary-sea-blue);
}

.btn-small.outline:hover {
    background: rgba(23, 162, 184, 0.1);
}

.btn-icon {
    background: transparent;
    border: none;
    color: var(--text-secondary);
    cursor: pointer;
    padding: 8px;
    border-radius: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.btn-icon:hover {
    background: var(--bg-tertiary);
    color: var(--text-primary);
}

.btn-back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--primary-sea-blue);
    text-decoration: none;
    font-weight: 500;
    padding: 8px 16px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--card-bg);
    transition: all 0.3s ease;
}

.btn-back:hover {
    background: var(--primary-sea-blue);
    color: white;
    border-color: var(--primary-sea-blue);
    transform: translateX(-4px);
}

/* Alerts */
.alert {
    padding: 16px 20px;
    border-radius: 10px;
    margin-bottom: 24px;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    position: relative;
    animation: slideDown 0.3s ease;
}

.alert-dismissible {
    padding-right: 50px;
}

.alert-error {
    background: rgba(220, 53, 69, 0.1);
    border: 1px solid rgba(220, 53, 69, 0.2);
    color: var(--error-color);
}

.alert-success {
    background: rgba(40, 167, 69, 0.1);
    border: 1px solid rgba(40, 167, 69, 0.2);
    color: var(--success-color);
}

.alert i {
    font-size: 1.2rem;
    margin-top: 2px;
}

.alert-close {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: inherit;
    cursor: pointer;
    font-size: 1.5rem;
    padding: 0;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0.7;
    transition: opacity 0.2s ease;
}

.alert-close:hover {
    opacity: 1;
}

.alert-actions {
    margin-top: 12px;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
}

/* Help Card */
.help-card {
    margin-top: 32px;
    background: var(--bg-secondary);
    border: 1px solid var(--border-color);
}

.help-card .card-title {
    display: flex;
    align-items: center;
    gap: 10px;
    color: var(--primary-sea-blue);
    margin-bottom: 20px;
}

.help-content {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 24px;
}

.help-item {
    padding: 16px;
    background: var(--bg-tertiary);
    border-radius: 8px;
    border-left: 4px solid var(--primary-sea-blue);
}

.help-item strong {
    display: block;
    margin-bottom: 12px;
    color: var(--text-primary);
    font-size: 0.95rem;
}

.help-item ul {
    margin: 0;
    padding-left: 20px;
    color: var(--text-secondary);
}

.help-item li {
    margin-bottom: 8px;
    font-size: 0.9rem;
    line-height: 1.5;
}

.help-item li strong {
    display: inline;
    font-weight: 600;
}

/* Header Actions */
.header-actions {
    display: flex;
    align-items: center;
    gap: 12px;
}

/* Animations */
@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes fadeIn {
    from {
        opacity: 0;
    }
    to {
        opacity: 1;
    }
}

/* Loading States */
.loading {
    position: relative;
    pointer-events: none;
    opacity: 0.7;
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
    to {
        transform: rotate(360deg);
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
        gap: 20px;
    }
    
    .form-section {
        padding: 20px;
    }
    
    .form-row {
        grid-template-columns: 1fr;
        gap: 16px;
    }
    
    .form-actions {
        flex-direction: column;
        gap: 12px;
    }
    
    .btn-primary,
    .btn-secondary,
    .btn-outline {
        width: 100%;
        justify-content: center;
    }
    
    .help-content {
        grid-template-columns: 1fr;
    }
    
    .questions-actions {
        flex-direction: column;
    }
    
    .questions-actions button {
        width: 100%;
        justify-content: center;
    }
    
    .file-upload-area {
        padding: 30px 16px;
    }
    
    .file-upload-area p {
        font-size: 1rem;
    }
}

@media (max-width: 480px) {
    .form-section {
        padding: 16px;
    }
    
    .alert {
        padding: 12px 16px;
    }
    
    .alert-actions {
        flex-direction: column;
    }
    
    .alert-actions a {
        width: 100%;
        text-align: center;
    }
}
</style>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Theme Management
    const themeToggle = document.getElementById('theme-toggle');
    const themeIcon = themeToggle.querySelector('i');
    
    // Check for saved theme or prefer-color-scheme
    const savedTheme = localStorage.getItem('theme') || 'light';
    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    const currentTheme = savedTheme === 'system' ? (prefersDark ? 'dark' : 'light') : savedTheme;
    
    // Apply theme
    document.documentElement.setAttribute('data-theme', currentTheme);
    themeIcon.className = currentTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    
    // Theme toggle handler
    themeToggle.addEventListener('click', function() {
        const currentTheme = document.documentElement.getAttribute('data-theme');
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
        
        document.documentElement.setAttribute('data-theme', newTheme);
        themeIcon.className = newTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
        localStorage.setItem('theme', newTheme);
        
        // Update header theme if it exists
        updateHeaderTheme(newTheme);
    });
    
    function updateHeaderTheme(theme) {
        // This would update the header theme if needed
        const header = document.querySelector('.dashboard-header');
        if (header) {
            header.dataset.theme = theme;
        }
    }
    
    // File Upload Handling
    const fileInput = document.getElementById('assignment_file');
    const fileDropArea = document.getElementById('file-drop-area');
    const filePreview = document.getElementById('file-preview');
    const fileError = document.getElementById('file-error');
    
    // File upload event handlers
    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
        fileDropArea.addEventListener(eventName, preventDefaults, false);
    });
    
    function preventDefaults(e) {
        e.preventDefault();
        e.stopPropagation();
    }
    
    ['dragenter', 'dragover'].forEach(eventName => {
        fileDropArea.addEventListener(eventName, highlight, false);
    });
    
    ['dragleave', 'drop'].forEach(eventName => {
        fileDropArea.addEventListener(eventName, unhighlight, false);
    });
    
    function highlight() {
        fileDropArea.classList.add('dragover');
    }
    
    function unhighlight() {
        fileDropArea.classList.remove('dragover');
    }
    
    fileDropArea.addEventListener('drop', handleDrop, false);
    fileDropArea.addEventListener('click', () => fileInput.click());
    fileDropArea.querySelector('.browse-link').addEventListener('click', (e) => {
        e.preventDefault();
        fileInput.click();
    });
    
    function handleDrop(e) {
        const dt = e.dataTransfer;
        const files = dt.files;
        handleFiles(files);
    }
    
    fileInput.addEventListener('change', function() {
        handleFiles(this.files);
    });
    
    function handleFiles(files) {
        if (files.length === 0) return;
        
        const file = files[0];
        const fileSizeMB = file.size / (1024 * 1024);
        const maxSize = 50; // 50MB
        
        if (fileSizeMB > maxSize) {
            showFileError(`File size must be less than ${maxSize}MB`);
            return;
        }
        
        // Check file extension
        const allowedExt = ['pdf', 'doc', 'docx', 'txt', 'jpg', 'jpeg', 'png', 'ppt', 'pptx', 'xlsx', 'zip', 'rar'];
        const fileExt = file.name.split('.').pop().toLowerCase();
        
        if (!allowedExt.includes(fileExt)) {
            showFileError(`File type .${fileExt} not allowed. Allowed types: ${allowedExt.join(', ')}`);
            return;
        }
        
        showFilePreview(file);
        hideFileError();
    }
    
    function showFileError(message) {
        fileError.textContent = message;
        fileError.classList.add('show');
        fileDropArea.style.borderColor = 'var(--error-color)';
    }
    
    function hideFileError() {
        fileError.classList.remove('show');
        fileDropArea.style.borderColor = '';
    }
    
    function showFilePreview(file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const fileType = file.type.split('/')[0];
            let icon = 'fa-file';
            
            // Set icon based on file type
            if (fileType === 'image') icon = 'fa-file-image';
            else if (file.name.match(/\.pdf$/i)) icon = 'fa-file-pdf';
            else if (file.name.match(/\.docx?$/i)) icon = 'fa-file-word';
            else if (file.name.match(/\.xlsx?$/i)) icon = 'fa-file-excel';
            else if (file.name.match(/\.pptx?$/i)) icon = 'fa-file-powerpoint';
            else if (file.name.match(/\.(zip|rar)$/i)) icon = 'fa-file-archive';
            else if (file.name.match(/\.txt$/i)) icon = 'fa-file-alt';
            
            const fileSize = formatFileSize(file.size);
            
            filePreview.innerHTML = `
                <div class="file-preview-item">
                    <div class="file-info">
                        <i class="fas ${icon} file-icon"></i>
                        <div class="file-details">
                            <div class="file-name">${escapeHtml(file.name)}</div>
                            <div class="file-size">${fileSize}</div>
                        </div>
                    </div>
                    <button type="button" class="remove-file" onclick="removeFile()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            filePreview.classList.add('show');
        };
        reader.readAsDataURL(file);
    }
    
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    window.removeFile = function() {
        fileInput.value = '';
        filePreview.innerHTML = '';
        filePreview.classList.remove('show');
    };
    
    // Group Assignment Toggle
    const groupToggle = document.getElementById('group-toggle');
    const groupSection = document.getElementById('group-section');
    const groupSelect = document.getElementById('group_id');
    const classSelect = document.getElementById('class_id');
    
    groupToggle.addEventListener('change', function() {
        groupSection.style.display = this.checked ? 'block' : 'none';
        if (!this.checked) {
            groupSelect.value = '';
        }
    });
    
    // Filter groups based on selected class
    classSelect.addEventListener('change', function() {
        const selectedClassId = this.value;
        Array.from(groupSelect.options).forEach(option => {
            if (option.value) {
                const classId = option.dataset.class;
                option.style.display = !classId || classId === selectedClassId ? '' : 'none';
            }
        });
    });
    
    // Trigger initial state
    if (groupToggle.checked) {
        groupSection.style.display = 'block';
    }
    
    // Update class info
    classSelect.addEventListener('change', updateClassInfo);
    function updateClassInfo() {
        const selectedOption = classSelect.options[classSelect.selectedIndex];
        const studentCount = selectedOption.dataset.students || '0';
        document.getElementById('class-info').textContent = `${studentCount} students in this class`;
    }
    updateClassInfo(); // Initial call
    
    // Quiz Questions Editor
    const typeSelect = document.getElementById('type');
    const questionsSection = document.getElementById('questions-section');
    const questionsContainer = document.getElementById('questions-container');
    const questionsJson = document.getElementById('questions-json');
    const questionsCount = document.getElementById('questions-count');
    const totalPoints = document.getElementById('total-points');
    const addQuestionBtn = document.getElementById('add-question');
    const addMultipleChoiceBtn = document.getElementById('add-multiple-choice');
    const addTrueFalseBtn = document.getElementById('add-true-false');
    const questionsError = document.getElementById('questions-error');
    
    let questions = [];
    let nextQuestionId = 1;
    
    typeSelect.addEventListener('change', function() {
        if (this.value === 'quiz') {
            questionsSection.style.display = 'block';
            if (questions.length === 0) {
                addNewQuestion('multiple_choice');
            }
        } else {
            questionsSection.style.display = 'none';
            questionsError.classList.remove('show');
        }
        updateQuestionsUI();
    });
    
    addQuestionBtn.addEventListener('click', () => addNewQuestion('multiple_choice'));
    addMultipleChoiceBtn.addEventListener('click', () => addNewQuestion('multiple_choice'));
    addTrueFalseBtn.addEventListener('click', () => addNewQuestion('true_false'));
    
    function addNewQuestion(type = 'multiple_choice') {
        const questionId = nextQuestionId++;
        let question;
        
        if (type === 'true_false') {
            question = {
                id: questionId,
                text: '',
                type: 'true_false',
                options: ['True', 'False'],
                correct: 0,
                points: 1
            };
        } else {
            question = {
                id: questionId,
                text: '',
                type: 'multiple_choice',
                options: ['', ''],
                correct: 0,
                points: 1
            };
        }
        
        questions.push(question);
        renderQuestion(question);
        updateQuestionsUI();
    }
    
    function renderQuestion(question) {
        const questionElement = document.createElement('div');
        questionElement.className = 'question-item';
        questionElement.dataset.id = question.id;
        
        let optionsHTML = '';
        question.options.forEach((option, index) => {
            const isTrueFalse = question.type === 'true_false';
            optionsHTML += `
                <div class="option-item ${index === question.correct ? 'correct' : ''}">
                    <span class="option-number">${String.fromCharCode(65 + index)}</span>
                    <input type="text" class="option-input" value="${option}" 
                           placeholder="${isTrueFalse ? option : 'Enter option text'}" 
                           ${isTrueFalse ? 'readonly' : ''}
                           oninput="updateQuestionOption(${question.id}, ${index}, this.value)">
                    <input type="radio" name="correct_${question.id}" value="${index}" 
                           class="correct-option" ${index === question.correct ? 'checked' : ''}
                           onchange="setCorrectAnswer(${question.id}, ${index})">
                    ${!isTrueFalse ? `
                    <div class="option-actions">
                        <button type="button" class="option-action-btn" onclick="removeOption(${question.id}, ${index})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    ` : ''}
                </div>
            `;
        });
        
        questionElement.innerHTML = `
            <div class="question-header">
                <span class="question-number">Question ${questions.findIndex(q => q.id === question.id) + 1}</span>
                <div class="question-points">
                    <input type="number" min="0" step="0.5" value="${question.points}" 
                           onchange="updateQuestionPoints(${question.id}, this.value)">
                    <span>points</span>
                </div>
                <div class="question-actions">
                    <button type="button" class="question-action-btn" onclick="moveQuestionUp(${question.id})" title="Move up">
                        <i class="fas fa-arrow-up"></i>
                    </button>
                    <button type="button" class="question-action-btn" onclick="moveQuestionDown(${question.id})" title="Move down">
                        <i class="fas fa-arrow-down"></i>
                    </button>
                    ${question.type !== 'true_false' ? `
                    <button type="button" class="question-action-btn" onclick="addOptionToQuestion(${question.id})" title="Add option">
                        <i class="fas fa-plus"></i>
                    </button>
                    ` : ''}
                    <button type="button" class="question-action-btn" onclick="removeQuestion(${question.id})" title="Delete question">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
            <textarea class="question-text" placeholder="Enter question text..." 
                      oninput="updateQuestionText(${question.id}, this.value)">${question.text}</textarea>
            <div class="question-type">
                <select onchange="changeQuestionType(${question.id}, this.value)">
                    <option value="multiple_choice" ${question.type === 'multiple_choice' ? 'selected' : ''}>Multiple Choice</option>
                    <option value="true_false" ${question.type === 'true_false' ? 'selected' : ''}>True/False</option>
                </select>
            </div>
            <div class="options-list">
                ${optionsHTML}
                ${question.type !== 'true_false' ? `
                <button type="button" class="add-option-btn" onclick="addOptionToQuestion(${question.id})">
                    <i class="fas fa-plus"></i> Add Option
                </button>
                ` : ''}
            </div>
        `;
        
        questionsContainer.appendChild(questionElement);
    }
    
    function updateQuestionsUI() {
        questionsCount.textContent = `${questions.length} question${questions.length !== 1 ? 's' : ''}`;
        const total = questions.reduce((sum, q) => sum + parseFloat(q.points || 0), 0);
        totalPoints.textContent = `Total: ${total} points`;
        updateQuestionsJson();
    }
    
    function updateQuestionsJson() {
        questionsJson.value = JSON.stringify(questions);
    }
    
    function reorderQuestions() {
        questionsContainer.innerHTML = '';
        questions.forEach(question => {
            renderQuestion(question);
        });
        updateQuestionsUI();
    }
    
    // Question Management Functions
    window.updateQuestionText = function(questionId, text) {
        const question = questions.find(q => q.id === questionId);
        if (question) {
            question.text = text;
            updateQuestionsJson();
        }
    };
    
    window.updateQuestionPoints = function(questionId, points) {
        const question = questions.find(q => q.id === questionId);
        if (question) {
            question.points = parseFloat(points) || 0;
            updateQuestionsUI();
        }
    };
    
    window.updateQuestionOption = function(questionId, optionIndex, value) {
        const question = questions.find(q => q.id === questionId);
        if (question && question.options[optionIndex] !== undefined) {
            question.options[optionIndex] = value;
            updateQuestionsJson();
        }
    };
    
    window.setCorrectAnswer = function(questionId, correctIndex) {
        const question = questions.find(q => q.id === questionId);
        if (question) {
            question.correct = correctIndex;
            // Update visual feedback
            const questionElement = document.querySelector(`[data-id="${questionId}"]`);
            if (questionElement) {
                questionElement.querySelectorAll('.option-item').forEach((item, index) => {
                    item.classList.toggle('correct', index === correctIndex);
                });
            }
            updateQuestionsJson();
        }
    };
    
    window.addOptionToQuestion = function(questionId) {
        const question = questions.find(q => q.id === questionId);
        if (question && question.type === 'multiple_choice') {
            question.options.push('');
            reorderQuestions();
        }
    };
    
    window.removeOption = function(questionId, optionIndex) {
        const question = questions.find(q => q.id === questionId);
        if (question && question.options.length > 2) {
            question.options.splice(optionIndex, 1);
            if (question.correct >= optionIndex) {
                question.correct = Math.max(0, question.correct - 1);
            }
            reorderQuestions();
        }
    };
    
    window.changeQuestionType = function(questionId, newType) {
        const question = questions.find(q => q.id === questionId);
        if (question) {
            question.type = newType;
            if (newType === 'true_false') {
                question.options = ['True', 'False'];
                question.correct = 0;
            }
            reorderQuestions();
        }
    };
    
    window.moveQuestionUp = function(questionId) {
        const index = questions.findIndex(q => q.id === questionId);
        if (index > 0) {
            [questions[index], questions[index - 1]] = [questions[index - 1], questions[index]];
            reorderQuestions();
        }
    };
    
    window.moveQuestionDown = function(questionId) {
        const index = questions.findIndex(q => q.id === questionId);
        if (index < questions.length - 1) {
            [questions[index], questions[index + 1]] = [questions[index + 1], questions[index]];
            reorderQuestions();
        }
    };
    
    window.removeQuestion = function(questionId) {
        questions = questions.filter(q => q.id !== questionId);
        const questionElement = document.querySelector(`[data-id="${questionId}"]`);
        if (questionElement) {
            questionElement.remove();
        }
        updateQuestionsUI();
    };
    
    // Tags Management
    const tagsInput = document.getElementById('tags');
    const tagsContainer = document.getElementById('tags-container');
    let tags = (tagsInput.value ? tagsInput.value.split(',').map(t => t.trim()).filter(t => t) : []) || [];
    
    function renderTags() {
        tagsContainer.innerHTML = '';
        tags.forEach((tag, index) => {
            const tagElement = document.createElement('span');
            tagElement.className = 'tag';
            tagElement.innerHTML = `
                ${escapeHtml(tag)}
                <button type="button" class="tag-remove" onclick="removeTag(${index})">
                    <i class="fas fa-times"></i>
                </button>
            `;
            tagsContainer.appendChild(tagElement);
        });
        tagsInput.value = tags.join(', ');
    }
    
    tagsInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            const tag = this.value.trim();
            if (tag && !tags.includes(tag)) {
                tags.push(tag);
                renderTags();
            }
            this.value = '';
        }
    });
    
    tagsInput.addEventListener('blur', function() {
        const tag = this.value.trim();
        if (tag && !tags.includes(tag)) {
            tags.push(tag);
            renderTags();
        }
        this.value = '';
    });
    
    window.removeTag = function(index) {
        tags.splice(index, 1);
        renderTags();
    };
    
    renderTags(); // Initial render
    
    // Schedule Section Toggle
    const statusRadios = document.querySelectorAll('input[name="status"]');
    const scheduleSection = document.getElementById('schedule-section');
    
    statusRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            scheduleSection.style.display = this.value === 'scheduled' ? 'block' : 'none';
        });
    });
    
    // Initialize schedule section
    const scheduledRadio = document.querySelector('input[name="status"][value="scheduled"]');
    if (scheduledRadio && scheduledRadio.checked) {
        scheduleSection.style.display = 'block';
    }
    
    // Form Validation
    const form = document.getElementById('assignment-form');
    const titleInput = document.getElementById('title');
    const maxPointsInput = document.getElementById('max_points');
    const dueDateInput = document.getElementById('due_date');
    const titleError = document.getElementById('title-error');
    const maxPointsError = document.getElementById('max_points-error');
    const dueDateError = document.getElementById('due_date-error');
    
    function validateField(input, errorElement, validator) {
        const value = input.value.trim();
        const error = validator(value);
        if (error) {
            errorElement.textContent = error;
            errorElement.classList.add('show');
            input.style.borderColor = 'var(--error-color)';
            return false;
        } else {
            errorElement.classList.remove('show');
            input.style.borderColor = '';
            return true;
        }
    }
    
    function validateTitle(value) {
        if (!value) return 'Assignment title is required';
        if (value.length < 3) return 'Title must be at least 3 characters';
        if (value.length > 255) return 'Title must be less than 255 characters';
        return '';
    }
    
    function validateMaxPoints(value) {
        if (!value) return 'Maximum points is required';
        const num = parseFloat(value);
        if (isNaN(num)) return 'Must be a valid number';
        if (num <= 0) return 'Must be greater than 0';
        if (num > 1000) return 'Must be less than 1000';
        return '';
    }
    
    function validateDueDate(value) {
        if (value) {
            const date = new Date(value);
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            if (date < today) return 'Due date cannot be in the past';
        }
        return '';
    }
    
    function validateQuestions() {
        if (typeSelect.value === 'quiz') {
            if (questions.length === 0) {
                questionsError.textContent = 'Please add at least one question for the quiz';
                questionsError.classList.add('show');
                return false;
            }
            
            for (let i = 0; i < questions.length; i++) {
                const q = questions[i];
                if (!q.text.trim()) {
                    questionsError.textContent = `Question ${i + 1} text is required`;
                    questionsError.classList.add('show');
                    return false;
                }
                if (q.options.some(opt => !opt.trim())) {
                    questionsError.textContent = `Question ${i + 1} has empty options`;
                    questionsError.classList.add('show');
                    return false;
                }
            }
            
            questionsError.classList.remove('show');
        }
        return true;
    }
    
    // Real-time validation
    titleInput.addEventListener('input', () => validateField(titleInput, titleError, validateTitle));
    maxPointsInput.addEventListener('input', () => validateField(maxPointsInput, maxPointsError, validateMaxPoints));
    dueDateInput.addEventListener('input', () => validateField(dueDateInput, dueDateError, validateDueDate));
    
    // Form submission validation
    form.addEventListener('submit', function(e) {
        let isValid = true;
        
        // Validate required fields
        isValid = validateField(titleInput, titleError, validateTitle) && isValid;
        isValid = validateField(maxPointsInput, maxPointsError, validateMaxPoints) && isValid;
        isValid = validateField(dueDateInput, dueDateError, validateDueDate) && isValid;
        
        // Validate class and subject selection
        if (!classSelect.value) {
            isValid = false;
            alert('Please select a class');
        }
        
        if (!subjectSelect.value) {
            isValid = false;
            alert('Please select a subject');
        }
        
        // Validate questions for quiz
        isValid = validateQuestions() && isValid;
        
        if (!isValid) {
            e.preventDefault();
            // Scroll to first error
            const firstError = document.querySelector('.form-error.show');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    });
    
    // Preview button
    document.getElementById('preview-btn').addEventListener('click', function() {
        // Validate form first
        let isValid = true;
        isValid = validateField(titleInput, titleError, validateTitle) && isValid;
        isValid = validateField(maxPointsInput, maxPointsError, validateMaxPoints) && isValid;
        isValid = validateQuestions() && isValid;
        
        if (!isValid) {
            alert('Please fix the errors before previewing');
            return;
        }
        
        // Collect form data
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());
        
        // Store in sessionStorage for preview page
        sessionStorage.setItem('assignmentPreview', JSON.stringify({
            ...data,
            questions: questions,
            tags: tags,
            timestamp: Date.now()
        }));
        
        // Open preview in new tab
        window.open('assignment_preview.php', '_blank');
    });
    
    // Save as template
    document.getElementById('save-template').addEventListener('click', function() {
        const title = titleInput.value.trim();
        if (!title) {
            alert('Please enter a title for the template');
            return;
        }
        
        const templateData = {
            title: title,
            description: document.getElementById('description').value,
            type: typeSelect.value,
            questions: questions,
            max_points: maxPointsInput.value,
            tags: tags,
            created_at: new Date().toISOString()
        };
        
        // Save to localStorage (or you could implement server-side saving)
        const templates = JSON.parse(localStorage.getItem('assignment_templates') || '[]');
        templates.push(templateData);
        localStorage.setItem('assignment_templates', JSON.stringify(templates));
        
        alert('Template saved successfully!');
    });
    
    // Auto-select current term
    const termSelect = document.getElementById('term_id');
    const currentTermOption = termSelect.querySelector('option[data-current="true"]');
    if (currentTermOption && !termSelect.value) {
        currentTermOption.selected = true;
    }
    
    // Set minimum due date to today
    dueDateInput.min = new Date().toISOString().split('T')[0];
    
    // Set default publish date to tomorrow
    const publishDateInput = document.getElementById('publish_date');
    const publishTimeInput = document.getElementById('publish_time');
    if (publishDateInput) {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        publishDateInput.min = new Date().toISOString().split('T')[0];
        publishDateInput.value = tomorrow.toISOString().split('T')[0];
    }
    
    // Initialize based on current type
    if (typeSelect.value === 'quiz') {
        questionsSection.style.display = 'block';
        if (questions.length === 0) {
            addNewQuestion('multiple_choice');
        }
    }
    
    // Close alert buttons
    document.querySelectorAll('.alert-close').forEach(button => {
        button.addEventListener('click', function() {
            this.closest('.alert').style.display = 'none';
        });
    });
    
    // Auto-hide success alert after 10 seconds
    const successAlert = document.querySelector('.alert-success');
    if (successAlert) {
        setTimeout(() => {
            successAlert.style.display = 'none';
        }, 10000);
    }
});
</script>

<?php 
// Include footer
include $root_path . 'templates/teacher_footer.php';
?>