<?php
// teacher/resources.php - Teaching Resources Management
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
    
    // Get current term
    $current_term = null;
    $current_date = date('Y-m-d');
    
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
        error_log("Teacher classes query error: " . $e->getMessage());
    }
    
    // Get subjects taught by teacher
    $teacher_subjects = [];
    try {
        $stmt = $db->prepare("
            SELECT DISTINCT s.id, s.subject_name, s.subject_code, d.name as department_name
            FROM subjects s
            LEFT JOIN departments d ON s.department_id = d.id
            WHERE s.teacher_id = ?
            ORDER BY s.subject_name
        ");
        $stmt->execute([$teacher_id]);
        $teacher_subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Teacher subjects query error: " . $e->getMessage());
    }
    
    // Handle form submissions
    $message = '';
    $message_type = '';
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // CSRF Protection
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $message = "Security token invalid. Please try again.";
            $message_type = 'error';
        } else {
            // Handle resource upload
            if (isset($_POST['action']) && $_POST['action'] === 'upload_resource') {
                try {
                    $title = trim($_POST['title'] ?? '');
                    $description = trim($_POST['description'] ?? '');
                    $resource_type = $_POST['resource_type'] ?? 'document';
                    $class_id = $_POST['class_id'] ?? null;
                    $subject_id = $_POST['subject_id'] ?? null;
                    $access_level = $_POST['access_level'] ?? 'class';
                    $tags = trim($_POST['tags'] ?? '');
                    
                    if (empty($title)) {
                        throw new Exception("Title is required");
                    }
                    
                    $file_path = null;
                    $url = null;
                    
                    // Handle file upload
                    if ($resource_type === 'document' && isset($_FILES['resource_file']) && $_FILES['resource_file']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = $root_path . 'Uploads/resources/';
                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        
                        $file_name = uniqid() . '_' . basename($_FILES['resource_file']['name']);
                        $file_path = 'Uploads/resources/' . $file_name;
                        $full_path = $root_path . $file_path;
                        
                        // Validate file type
                        $allowed_types = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'mp4', 'mp3', 'zip'];
                        $file_ext = strtolower(pathinfo($_FILES['resource_file']['name'], PATHINFO_EXTENSION));
                        
                        if (!in_array($file_ext, $allowed_types)) {
                            throw new Exception("File type not allowed. Allowed types: " . implode(', ', $allowed_types));
                        }
                        
                        // Move uploaded file
                        if (!move_uploaded_file($_FILES['resource_file']['tmp_name'], $full_path)) {
                            throw new Exception("Failed to upload file");
                        }
                    } elseif ($resource_type === 'link' && !empty($_POST['url'])) {
                        $url = filter_var($_POST['url'], FILTER_VALIDATE_URL);
                        if (!$url) {
                            throw new Exception("Invalid URL format");
                        }
                    }
                    
                    // Insert into database
                    $stmt = $db->prepare("
                        INSERT INTO learning_resources (
                            title, description, resource_type, file_path, url, 
                            subject_id, class_id, teacher_id, term_id, academic_year_id,
                            access_level, tags, created_by, created_at, updated_at
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                        )
                    ");
                    
                    $stmt->execute([
                        $title,
                        $description,
                        $resource_type,
                        $file_path,
                        $url,
                        $subject_id ?: null,
                        $class_id ?: null,
                        $teacher_id,
                        $current_term ? $current_term['id'] : null,
                        $current_term ? $current_term['academic_year_id'] : null,
                        $access_level,
                        $tags,
                        $teacher_id
                    ]);
                    
                    $message = "Resource uploaded successfully!";
                    $message_type = 'success';
                    
                } catch (Exception $e) {
                    $message = "Error uploading resource: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
            
            // Handle note creation
            elseif (isset($_POST['action']) && $_POST['action'] === 'save_note') {
                try {
                    $week = $_POST['week'] ?? 1;
                    $class_id = $_POST['class_id'] ?? null;
                    $subject_id = $_POST['subject_id'] ?? null;
                    $strand = trim($_POST['strand'] ?? '');
                    $sub_strand = trim($_POST['sub_strand'] ?? '');
                    $content_standard = trim($_POST['content_standard'] ?? '');
                    $indicator = trim($_POST['indicator'] ?? '');
                    $objectives = trim($_POST['objectives'] ?? '');
                    $reference = trim($_POST['reference'] ?? '');
                    $resources = trim($_POST['resources'] ?? '');
                    $keywords = trim($_POST['keywords'] ?? '');
                    $starter = trim($_POST['starter'] ?? '');
                    $presentation = trim($_POST['presentation'] ?? '');
                    $summary = trim($_POST['summary'] ?? '');
                    $unit = trim($_POST['unit'] ?? '');
                    $topic = trim($_POST['topic'] ?? '');
                    $format = $_POST['format'] ?? 'oxford';
                    
                    if (empty($class_id) || empty($subject_id)) {
                        throw new Exception("Class and Subject are required");
                    }
                    
                    // Check if note exists for this week, class, subject
                    $stmt = $db->prepare("
                        SELECT id FROM teacher_notes 
                        WHERE teacher_id = ? AND week = ? AND class_id = ? AND subject_id = ?
                    ");
                    $stmt->execute([$teacher_id, $week, $class_id, $subject_id]);
                    $existing_note = $stmt->fetch();
                    
                    if ($existing_note) {
                        // Update existing note
                        $stmt = $db->prepare("
                            UPDATE teacher_notes SET
                                strand = ?, sub_strand = ?, content_standard = ?, indicator = ?,
                                objectives = ?, reference = ?, resources = ?, keywords = ?,
                                starter = ?, presentation = ?, summary = ?, unit = ?, topic = ?,
                                format = ?, term_id = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([
                            $strand, $sub_strand, $content_standard, $indicator,
                            $objectives, $reference, $resources, $keywords,
                            $starter, $presentation, $summary, $unit, $topic,
                            $format, $current_term ? $current_term['id'] : null,
                            $existing_note['id']
                        ]);
                        $message = "Note updated successfully!";
                    } else {
                        // Insert new note
                        $stmt = $db->prepare("
                            INSERT INTO teacher_notes (
                                teacher_id, week, class_id, subject_id, term_id,
                                strand, sub_strand, content_standard, indicator, objectives,
                                reference, resources, keywords, starter, presentation,
                                summary, unit, topic, format
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $teacher_id, $week, $class_id, $subject_id, $current_term ? $current_term['id'] : null,
                            $strand, $sub_strand, $content_standard, $indicator, $objectives,
                            $reference, $resources, $keywords, $starter, $presentation,
                            $summary, $unit, $topic, $format
                        ]);
                        $message = "Note saved successfully!";
                    }
                    
                    $message_type = 'success';
                    
                } catch (Exception $e) {
                    $message = "Error saving note: " . $e->getMessage();
                    $message_type = 'error';
                }
            }
        }
    }
    
    // Generate CSRF token
    require_once $root_path . 'functions/csrf.php';
    $csrf_token = generate_csrf_token();
    
    // Fetch uploaded resources
    $uploaded_resources = [];
    try {
        $stmt = $db->prepare("
            SELECT lr.*, 
                   c.class_name,
                   s.subject_name,
                   u.name as teacher_name,
                   t.name as term_name
            FROM learning_resources lr
            LEFT JOIN classes c ON lr.class_id = c.id
            LEFT JOIN subjects s ON lr.subject_id = s.id
            LEFT JOIN users u ON lr.created_by = u.id
            LEFT JOIN terms t ON lr.term_id = t.id
            WHERE lr.created_by = ?
            ORDER BY lr.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$teacher_id]);
        $uploaded_resources = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Resources query error: " . $e->getMessage());
    }
    
    // Fetch teacher notes
    $teacher_notes = [];
    try {
        $stmt = $db->prepare("
            SELECT tn.*, 
                   c.class_name,
                   s.subject_name,
                   t.name as term_name
            FROM teacher_notes tn
            LEFT JOIN classes c ON tn.class_id = c.id
            LEFT JOIN subjects s ON tn.subject_id = s.id
            LEFT JOIN terms t ON tn.term_id = t.id
            WHERE tn.teacher_id = ?
            ORDER BY tn.week, tn.class_id, tn.subject_id
            LIMIT 50
        ");
        $stmt->execute([$teacher_id]);
        $teacher_notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Teacher notes query error: " . $e->getMessage());
    }
    
    // Get resource statistics
    $resource_stats = [
        'total' => 0,
        'documents' => 0,
        'videos' => 0,
        'links' => 0,
        'by_class' => []
    ];
    
    try {
        // Total resources
        $stmt = $db->prepare("SELECT COUNT(*) FROM learning_resources WHERE created_by = ?");
        $stmt->execute([$teacher_id]);
        $resource_stats['total'] = (int)$stmt->fetchColumn();
        
        // By type
        $stmt = $db->prepare("
            SELECT resource_type, COUNT(*) as count 
            FROM learning_resources 
            WHERE created_by = ? 
            GROUP BY resource_type
        ");
        $stmt->execute([$teacher_id]);
        $types = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($types as $type) {
            if ($type['resource_type'] === 'video' || $type['resource_type'] === 'audio') {
                $resource_stats['videos'] += $type['count'];
            } elseif ($type['resource_type'] === 'link') {
                $resource_stats['links'] += $type['count'];
            } else {
                $resource_stats['documents'] += $type['count'];
            }
        }
        
        // By class
        $stmt = $db->prepare("
            SELECT c.class_name, COUNT(lr.id) as count
            FROM learning_resources lr
            LEFT JOIN classes c ON lr.class_id = c.id
            WHERE lr.created_by = ?
            GROUP BY lr.class_id, c.class_name
            ORDER BY count DESC
            LIMIT 5
        ");
        $stmt->execute([$teacher_id]);
        $resource_stats['by_class'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Resource stats query error: " . $e->getMessage());
    }
    
} catch (Exception $e) {
    error_log("Resources page initialization error: " . $e->getMessage());
    $message = "System error occurred. Please try again later.";
    $message_type = 'error';
}

// Include header
include $root_path . 'templates/teacher_header.php';
?>

<!-- Resources Content -->
<section class="resources-page">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">
            <i class="fas fa-folder"></i> Teaching Resources
        </h1>
        <div class="page-subtitle">
            <span class="welcome">Upload resources and create lesson notes for your classes</span>
            <span class="academic-info">
                <?php if ($current_term): ?>
                    <i class="fas fa-calendar"></i> Term: <?php echo htmlspecialchars($current_term['name']); ?>
                <?php else: ?>
                    <i class="fas fa-calendar"></i> No active term
                <?php endif; ?>
            </span>
        </div>
    </div>

    <!-- Message Alert -->
    <?php if ($message): ?>
    <div class="alert alert-<?php echo $message_type; ?>">
        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
        <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-file-alt"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $resource_stats['total']; ?></div>
                <div class="stat-label">Total Resources</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-file-pdf"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $resource_stats['documents']; ?></div>
                <div class="stat-label">Documents</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-video"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $resource_stats['videos']; ?></div>
                <div class="stat-label">Videos & Audio</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">
                <i class="fas fa-link"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo $resource_stats['links']; ?></div>
                <div class="stat-label">Web Links</div>
            </div>
        </div>
    </div>

    <!-- Main Content Tabs -->
    <div class="content-tabs">
        <div class="tab-header">
            <button class="tab-button active" onclick="switchTab('upload')">
                <i class="fas fa-upload"></i> Upload Resource
            </button>
            <button class="tab-button" onclick="switchTab('notes')">
                <i class="fas fa-edit"></i> Create Notes
            </button>
            <button class="tab-button" onclick="switchTab('library')">
                <i class="fas fa-book"></i> My Resources
            </button>
            <button class="tab-button" onclick="switchTab('mynotes')">
                <i class="fas fa-sticky-note"></i> My Notes
            </button>
        </div>

        <!-- Upload Resource Tab -->
        <div id="upload-tab" class="tab-content active">
            <div class="card">
                <h3 class="card-title">Upload Teaching Resource</h3>
                <form id="uploadForm" method="POST" enctype="multipart/form-data" class="resource-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="upload_resource">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="title">
                                <i class="fas fa-heading"></i> Resource Title *
                            </label>
                            <input type="text" id="title" name="title" required 
                                   placeholder="Enter resource title" maxlength="255">
                        </div>
                        
                        <div class="form-group">
                            <label for="description">
                                <i class="fas fa-align-left"></i> Description
                            </label>
                            <textarea id="description" name="description" 
                                      placeholder="Brief description of the resource" rows="2"></textarea>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="resource_type">
                                <i class="fas fa-file"></i> Resource Type *
                            </label>
                            <select id="resource_type" name="resource_type" required onchange="toggleResourceFields()">
                                <option value="document">Document (PDF, Word, PPT)</option>
                                <option value="video">Video/Audio</option>
                                <option value="link">Web Link</option>
                                <option value="interactive">Interactive Content</option>
                            </select>
                        </div>
                        
                        <div class="form-group" id="fileUploadGroup">
                            <label for="resource_file">
                                <i class="fas fa-cloud-upload-alt"></i> Upload File *
                            </label>
                            <input type="file" id="resource_file" name="resource_file" 
                                   accept=".pdf,.doc,.docx,.ppt,.pptx,.txt,.jpg,.jpeg,.png,.gif,.mp4,.mp3,.zip">
                            <div class="file-info" id="fileInfo">No file selected</div>
                        </div>
                        
                        <div class="form-group" id="urlGroup" style="display: none;">
                            <label for="url">
                                <i class="fas fa-link"></i> URL *
                            </label>
                            <input type="url" id="url" name="url" placeholder="https://example.com/resource">
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="class_id">
                                <i class="fas fa-chalkboard"></i> For Class (Optional)
                            </label>
                            <select id="class_id" name="class_id">
                                <option value="">All Classes</option>
                                <?php foreach ($teacher_classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="subject_id">
                                <i class="fas fa-book"></i> For Subject (Optional)
                            </label>
                            <select id="subject_id" name="subject_id">
                                <option value="">All Subjects</option>
                                <?php foreach ($teacher_subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="access_level">
                                <i class="fas fa-lock"></i> Access Level
                            </label>
                            <select id="access_level" name="access_level">
                                <option value="class">Class Only</option>
                                <option value="public">All Teachers</option>
                                <option value="private">Private (Only Me)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="tags">
                                <i class="fas fa-tags"></i> Tags (comma separated)
                            </label>
                            <input type="text" id="tags" name="tags" 
                                   placeholder="e.g., math, algebra, worksheet">
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-upload"></i> Upload Resource
                        </button>
                        <button type="reset" class="btn-secondary">
                            <i class="fas fa-redo"></i> Reset Form
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Create Notes Tab -->
        <div id="notes-tab" class="tab-content">
            <div class="card">
                <h3 class="card-title">Create Lesson Notes</h3>
                <form id="notesForm" method="POST" class="notes-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="save_note">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="note_week">
                                <i class="fas fa-calendar-week"></i> Week Number *
                            </label>
                            <select id="note_week" name="week" required>
                                <?php for ($i = 1; $i <= 14; $i++): ?>
                                <option value="<?php echo $i; ?>">Week <?php echo $i; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="note_class_id">
                                <i class="fas fa-chalkboard"></i> Class *
                            </label>
                            <select id="note_class_id" name="class_id" required onchange="loadExistingNote()">
                                <option value="">Select Class</option>
                                <?php foreach ($teacher_classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>">
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="note_subject_id">
                                <i class="fas fa-book"></i> Subject *
                            </label>
                            <select id="note_subject_id" name="subject_id" required onchange="loadExistingNote()">
                                <option value="">Select Subject</option>
                                <?php foreach ($teacher_subjects as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>">
                                    <?php echo htmlspecialchars($subject['subject_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="note_format">
                                <i class="fas fa-edit"></i> Format
                            </label>
                            <select id="note_format" name="format">
                                <option value="oxford">Oxford Format</option>
                                <option value="ges">GES Format</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="unit">
                                <i class="fas fa-layer-group"></i> Unit
                            </label>
                            <input type="text" id="unit" name="unit" placeholder="e.g., Unit 1: Numbers">
                        </div>
                        
                        <div class="form-group">
                            <label for="topic">
                                <i class="fas fa-tasks"></i> Topic
                            </label>
                            <input type="text" id="topic" name="topic" placeholder="e.g., Addition and Subtraction">
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="strand">
                                <i class="fas fa-stream"></i> Strand
                            </label>
                            <input type="text" id="strand" name="strand" placeholder="Main strand">
                        </div>
                        
                        <div class="form-group">
                            <label for="sub_strand">
                                <i class="fas fa-indent"></i> Sub-strand
                            </label>
                            <input type="text" id="sub_strand" name="sub_strand" placeholder="Sub-strand">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="content_standard">
                            <i class="fas fa-bullseye"></i> Content Standard
                        </label>
                        <textarea id="content_standard" name="content_standard" 
                                  placeholder="Content standard for this lesson" rows="2"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="indicator">
                            <i class="fas fa-chart-line"></i> Indicator
                        </label>
                        <textarea id="indicator" name="indicator" 
                                  placeholder="Learning indicators" rows="2"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="objectives">
                            <i class="fas fa-tasks"></i> Learning Objectives
                        </label>
                        <textarea id="objectives" name="objectives" 
                                  placeholder="By the end of the lesson, students will be able to..." 
                                  rows="3"></textarea>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="reference">
                                <i class="fas fa-book-open"></i> References
                            </label>
                            <textarea id="reference" name="reference" 
                                      placeholder="Textbooks and other references" rows="2"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="resources">
                                <i class="fas fa-tools"></i> Teaching Resources
                            </label>
                            <textarea id="resources" name="resources" 
                                      placeholder="Materials needed for the lesson" rows="2"></textarea>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="keywords">
                            <i class="fas fa-key"></i> Key Words
                        </label>
                        <input type="text" id="keywords" name="keywords" 
                               placeholder="Comma separated keywords">
                    </div>
                    
                    <div class="form-group">
                        <label for="starter">
                            <i class="fas fa-play-circle"></i> Starter Activity
                        </label>
                        <textarea id="starter" name="starter" 
                                  placeholder="Introduction/warm-up activity" rows="3"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="presentation">
                            <i class="fas fa-chalkboard-teacher"></i> Main Presentation
                        </label>
                        <textarea id="presentation" name="presentation" 
                                  placeholder="Step-by-step lesson presentation" rows="6"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="summary">
                            <i class="fas fa-clipboard-check"></i> Summary & Assessment
                        </label>
                        <textarea id="summary" name="summary" 
                                  placeholder="Lesson summary and assessment questions" rows="3"></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn-primary">
                            <i class="fas fa-save"></i> Save Note
                        </button>
                        <button type="button" class="btn-secondary" onclick="resetNoteForm()">
                            <i class="fas fa-redo"></i> Clear Form
                        </button>
                        <button type="button" class="btn-info" onclick="previewNote()">
                            <i class="fas fa-eye"></i> Preview
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- My Resources Tab -->
        <div id="library-tab" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">My Uploaded Resources</h3>
                    <div class="card-actions">
                        <button class="btn-small" onclick="refreshResources()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                        <button class="btn-small" onclick="exportResources()">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                
                <div class="table-controls">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="resourceSearch" placeholder="Search resources...">
                    </div>
                    <div class="filter-box">
                        <select id="resourceFilter">
                            <option value="">All Types</option>
                            <option value="document">Documents</option>
                            <option value="video">Videos/Audio</option>
                            <option value="link">Links</option>
                        </select>
                    </div>
                </div>
                
                <div class="table-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Type</th>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Upload Date</th>
                                <th>Access</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($uploaded_resources)): ?>
                            <tr>
                                <td colspan="7" class="empty-message">No resources uploaded yet</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($uploaded_resources as $resource): ?>
                                <tr>
                                    <td>
                                        <div class="resource-title">
                                            <i class="fas fa-<?php echo getResourceIcon($resource['resource_type']); ?>"></i>
                                            <strong><?php echo htmlspecialchars($resource['title']); ?></strong>
                                        </div>
                                        <small><?php echo htmlspecialchars(substr($resource['description'] ?? '', 0, 50)); ?>...</small>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?php echo $resource['resource_type']; ?>">
                                            <?php echo ucfirst($resource['resource_type']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($resource['class_name'] ?? 'All'); ?></td>
                                    <td><?php echo htmlspecialchars($resource['subject_name'] ?? 'All'); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($resource['created_at'])); ?></td>
                                    <td>
                                        <span class="badge badge-access">
                                            <?php echo ucfirst($resource['access_level']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($resource['resource_type'] === 'link' && !empty($resource['url'])): ?>
                                            <a href="<?php echo htmlspecialchars($resource['url']); ?>" 
                                               target="_blank" class="btn-icon" title="Open Link">
                                                <i class="fas fa-external-link-alt"></i>
                                            </a>
                                            <?php elseif (!empty($resource['file_path'])): ?>
                                            <a href="<?php echo $root_path . htmlspecialchars($resource['file_path']); ?>" 
                                               target="_blank" class="btn-icon" title="View/Download">
                                                <i class="fas fa-download"></i>
                                            </a>
                                            <?php endif; ?>
                                            <button class="btn-icon" onclick="editResource(<?php echo $resource['id']; ?>)" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="btn-icon btn-danger" 
                                                    onclick="deleteResource(<?php echo $resource['id']; ?>)" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="table-footer">
                    <div class="pagination-info">
                        Showing <?php echo count($uploaded_resources); ?> resources
                    </div>
                    <div class="pagination-controls">
                        <button class="btn-small" disabled>Previous</button>
                        <span>Page 1 of 1</span>
                        <button class="btn-small" disabled>Next</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- My Notes Tab -->
        <div id="mynotes-tab" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">My Lesson Notes</h3>
                    <div class="card-actions">
                        <button class="btn-small" onclick="refreshNotes()">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </button>
                        <button class="btn-small" onclick="printAllNotes()">
                            <i class="fas fa-print"></i> Print All
                        </button>
                    </div>
                </div>
                
                <div class="table-controls">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="notesSearch" placeholder="Search notes...">
                    </div>
                    <div class="filter-box">
                        <select id="notesFilter">
                            <option value="">All Classes</option>
                            <?php foreach ($teacher_classes as $class): ?>
                            <option value="<?php echo $class['id']; ?>">
                                <?php echo htmlspecialchars($class['class_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="notes-grid">
                    <?php if (empty($teacher_notes)): ?>
                    <div class="empty-message">No notes created yet</div>
                    <?php else: ?>
                        <?php foreach ($teacher_notes as $note): ?>
                        <div class="note-card">
                            <div class="note-header">
                                <div class="note-title">
                                    <h4>Week <?php echo $note['week']; ?> - <?php echo htmlspecialchars($note['topic'] ?? 'Untitled'); ?></h4>
                                    <span class="note-class"><?php echo htmlspecialchars($note['class_name']); ?></span>
                                </div>
                                <div class="note-subject">
                                    <i class="fas fa-book"></i>
                                    <?php echo htmlspecialchars($note['subject_name']); ?>
                                </div>
                            </div>
                            
                            <div class="note-content">
                                <div class="note-section">
                                    <label>Objectives:</label>
                                    <p><?php echo nl2br(htmlspecialchars(substr($note['objectives'] ?? '', 0, 100))); ?>...</p>
                                </div>
                                <div class="note-section">
                                    <label>Keywords:</label>
                                    <p><?php echo htmlspecialchars($note['keywords'] ?? ''); ?></p>
                                </div>
                            </div>
                            
                            <div class="note-footer">
                                <div class="note-meta">
                                    <span><i class="fas fa-edit"></i> <?php echo $note['format']; ?> Format</span>
                                    <span><i class="fas fa-calendar"></i> <?php echo $note['term_name'] ?? 'N/A'; ?></span>
                                </div>
                                <div class="note-actions">
                                    <button class="btn-icon" onclick="viewNote(<?php echo $note['id']; ?>)" title="View">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn-icon" onclick="editNote(<?php echo $note['id']; ?>)" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn-icon btn-danger" onclick="deleteNote(<?php echo $note['id']; ?>)" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <button class="btn-icon" onclick="printNote(<?php echo $note['id']; ?>)" title="Print">
                                        <i class="fas fa-print"></i>
                                    </button>
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

<!-- Resource Preview Modal -->
<div id="previewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Resource Preview</h3>
            <button class="modal-close" onclick="closePreview()">&times;</button>
        </div>
        <div class="modal-body" id="previewContent">
            <!-- Preview content will be loaded here -->
        </div>
    </div>
</div>

<!-- Note Preview Modal -->
<div id="notePreviewModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Lesson Note Preview</h3>
            <button class="modal-close" onclick="closeNotePreview()">&times;</button>
        </div>
        <div class="modal-body" id="notePreviewContent">
            <!-- Note preview will be loaded here -->
        </div>
        <div class="modal-footer">
            <button class="btn-primary" onclick="printNotePreview()">
                <i class="fas fa-print"></i> Print Note
            </button>
            <button class="btn-secondary" onclick="closeNotePreview()">
                <i class="fas fa-times"></i> Close
            </button>
        </div>
    </div>
</div>

<style>
/* Resources Page Styles */
.resources-page {
    padding: 20px;
    max-width: 1400px;
    margin: 0 auto;
}

.page-header {
    margin-bottom: 30px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--border-color);
}

.page-title {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.page-subtitle {
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

/* Statistics Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: var(--card-bg);
    border-radius: 12px;
    padding: 20px;
    box-shadow: var(--card-shadow);
    display: flex;
    align-items: center;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    border: 1px solid var(--border-color);
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}

.stat-icon {
    font-size: 2rem;
    color: var(--primary-sea-blue);
    margin-right: 15px;
}

.stat-content {
    flex: 1;
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text-primary);
    line-height: 1;
}

.stat-label {
    font-size: 0.9rem;
    color: var(--text-secondary);
    margin: 4px 0;
}

/* Alert Styles */
.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 500;
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

/* Tabs */
.content-tabs {
    background: var(--card-bg);
    border-radius: 12px;
    overflow: hidden;
    border: 1px solid var(--border-color);
    box-shadow: var(--card-shadow);
}

.tab-header {
    display: flex;
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border-color);
    overflow-x: auto;
}

.tab-button {
    padding: 15px 25px;
    background: none;
    border: none;
    color: var(--text-secondary);
    font-weight: 500;
    cursor: pointer;
    position: relative;
    transition: all 0.3s ease;
    white-space: nowrap;
    display: flex;
    align-items: center;
    gap: 8px;
    border-bottom: 2px solid transparent;
}

.tab-button:hover {
    background: var(--hover-bg);
    color: var(--primary-sea-blue);
}

.tab-button.active {
    color: var(--primary-sea-blue);
    border-bottom-color: var(--primary-sea-blue);
}

.tab-content {
    display: none;
    padding: 30px;
}

.tab-content.active {
    display: block;
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

/* Card Styles */
.card {
    background: var(--card-bg);
    border-radius: 10px;
    padding: 25px;
    border: 1px solid var(--border-color);
}

.card-title {
    font-size: 1.3rem;
    font-weight: 600;
    color: var(--text-primary);
    margin-bottom: 25px;
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

/* Form Styles */
.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
    color: var(--text-primary);
    margin-bottom: 8px;
    font-size: 0.95rem;
}

.form-group label i {
    color: var(--primary-sea-blue);
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 12px 15px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--bg-primary);
    color: var(--text-primary);
    font-size: 0.95rem;
    transition: all 0.3s ease;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: var(--primary-sea-blue);
    box-shadow: 0 0 0 3px rgba(23, 162, 184, 0.1);
}

.form-group textarea {
    min-height: 100px;
    resize: vertical;
}

.file-info {
    margin-top: 8px;
    padding: 10px;
    background: var(--bg-secondary);
    border-radius: 6px;
    color: var(--text-secondary);
    font-size: 0.9rem;
}

/* Button Styles */
.btn-primary, .btn-secondary, .btn-info, .btn-small {
    padding: 12px 24px;
    border: none;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-size: 0.95rem;
}

.btn-primary {
    background: var(--primary-sea-blue);
    color: white;
}

.btn-primary:hover {
    background: var(--accent-soft-cyan);
    transform: translateY(-2px);
}

.btn-secondary {
    background: var(--bg-secondary);
    color: var(--text-primary);
    border: 1px solid var(--border-color);
}

.btn-secondary:hover {
    background: var(--border-color);
}

.btn-info {
    background: var(--accent-deep-navy);
    color: white;
}

.btn-info:hover {
    background: var(--accent-slate-gray);
}

.btn-small {
    padding: 8px 16px;
    font-size: 0.85rem;
}

.form-actions {
    display: flex;
    gap: 15px;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid var(--border-color);
}

/* Table Styles */
.table-controls {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    gap: 20px;
}

.search-box {
    flex: 1;
    position: relative;
    max-width: 400px;
}

.search-box i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--text-secondary);
}

.search-box input {
    width: 100%;
    padding: 12px 15px 12px 45px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--bg-primary);
    color: var(--text-primary);
}

.filter-box select {
    padding: 12px 15px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: var(--bg-primary);
    color: var(--text-primary);
    min-width: 150px;
}

.table-container {
    overflow-x: auto;
    border-radius: 8px;
    border: 1px solid var(--border-color);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
}

.data-table th {
    background: var(--bg-secondary);
    padding: 15px;
    text-align: left;
    font-weight: 600;
    color: var(--text-primary);
    border-bottom: 2px solid var(--border-color);
}

.data-table td {
    padding: 15px;
    border-bottom: 1px solid var(--border-color);
    color: var(--text-secondary);
}

.data-table tr:hover {
    background: var(--hover-bg);
}

.resource-title {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 5px;
}

.resource-title i {
    color: var(--primary-sea-blue);
    font-size: 1.2rem;
}

/* Badge Styles */
.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: 600;
}

.badge-document {
    background: rgba(23, 162, 184, 0.1);
    color: var(--primary-sea-blue);
}

.badge-video {
    background: rgba(220, 53, 69, 0.1);
    color: #dc3545;
}

.badge-link {
    background: rgba(40, 167, 69, 0.1);
    color: #28a745;
}

.badge-access {
    background: rgba(108, 117, 125, 0.1);
    color: var(--accent-slate-gray);
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 5px;
}

.btn-icon {
    width: 36px;
    height: 36px;
    border: none;
    border-radius: 50%;
    background: var(--bg-secondary);
    color: var(--text-secondary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.3s ease;
}

.btn-icon:hover {
    background: var(--primary-sea-blue);
    color: white;
    transform: scale(1.1);
}

.btn-icon.btn-danger:hover {
    background: #dc3545;
}

.table-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    border-top: 1px solid var(--border-color);
}

.pagination-controls {
    display: flex;
    align-items: center;
    gap: 15px;
}

.pagination-controls button {
    padding: 8px 16px;
}

/* Notes Grid */
.notes-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.note-card {
    background: var(--card-bg);
    border-radius: 10px;
    padding: 20px;
    border: 1px solid var(--border-color);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.note-card:hover {
    transform: translateY(-4px);
    box-shadow: var(--card-shadow);
}

.note-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid var(--border-color);
}

.note-title h4 {
    font-size: 1.1rem;
    color: var(--text-primary);
    margin-bottom: 5px;
}

.note-class {
    font-size: 0.85rem;
    color: var(--primary-sea-blue);
    font-weight: 500;
}

.note-subject {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 0.9rem;
    color: var(--text-secondary);
}

.note-content {
    margin-bottom: 15px;
}

.note-section {
    margin-bottom: 10px;
}

.note-section label {
    font-weight: 600;
    color: var(--text-primary);
    font-size: 0.9rem;
    display: block;
    margin-bottom: 5px;
}

.note-section p {
    color: var(--text-secondary);
    font-size: 0.9rem;
    line-height: 1.4;
}

.note-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: 15px;
    border-top: 1px solid var(--border-color);
}

.note-meta {
    display: flex;
    flex-direction: column;
    gap: 5px;
    font-size: 0.8rem;
    color: var(--text-muted);
}

.note-meta span {
    display: flex;
    align-items: center;
    gap: 5px;
}

.note-actions {
    display: flex;
    gap: 5px;
}

/* Modal Styles */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    z-index: 10000;
    align-items: center;
    justify-content: center;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: var(--bg-primary);
    border-radius: 12px;
    width: 90%;
    max-width: 800px;
    max-height: 80vh;
    overflow: hidden;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.modal-header {
    padding: 20px 25px;
    background: var(--bg-secondary);
    border-bottom: 1px solid var(--border-color);
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.modal-header h3 {
    font-size: 1.3rem;
    color: var(--text-primary);
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    color: var(--text-secondary);
    cursor: pointer;
    width: 40px;
    height: 40px;
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
    padding: 25px;
    overflow-y: auto;
    max-height: 60vh;
}

.modal-footer {
    padding: 20px 25px;
    background: var(--bg-secondary);
    border-top: 1px solid var(--border-color);
    display: flex;
    justify-content: flex-end;
    gap: 15px;
}

/* Empty States */
.empty-message {
    text-align: center;
    padding: 40px 20px;
    color: var(--text-muted);
    font-style: italic;
    grid-column: 1 / -1;
}

/* Responsive Design */
@media (max-width: 992px) {
    .form-grid {
        grid-template-columns: 1fr;
    }
    
    .notes-grid {
        grid-template-columns: 1fr;
    }
    
    .table-controls {
        flex-direction: column;
        align-items: stretch;
    }
    
    .search-box {
        max-width: none;
    }
}

@media (max-width: 768px) {
    .resources-page {
        padding: 15px;
    }
    
    .page-title {
        font-size: 1.5rem;
    }
    
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    
    .tab-header {
        flex-wrap: wrap;
    }
    
    .tab-button {
        flex: 1;
        min-width: 120px;
        padding: 12px 15px;
        font-size: 0.9rem;
    }
    
    .form-actions {
        flex-direction: column;
    }
    
    .btn-primary, .btn-secondary, .btn-info {
        width: 100%;
    }
    
    .modal-content {
        width: 95%;
        max-height: 90vh;
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .stat-card {
        padding: 15px;
    }
    
    .tab-content {
        padding: 20px;
    }
    
    .card {
        padding: 20px;
    }
}
</style>

<script>
// Helper function for resource icons
function getResourceIcon(type) {
    const icons = {
        'document': 'file-pdf',
        'video': 'video',
        'audio': 'music',
        'link': 'link',
        'interactive': 'gamepad'
    };
    return icons[type] || 'file';
}

// Tab switching
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
    event.target.closest('.tab-button').classList.add('active');
}

// Toggle resource fields based on type
function toggleResourceFields() {
    const resourceType = document.getElementById('resource_type').value;
    const fileUploadGroup = document.getElementById('fileUploadGroup');
    const urlGroup = document.getElementById('urlGroup');
    const fileInput = document.getElementById('resource_file');
    const urlInput = document.getElementById('url');
    
    if (resourceType === 'link') {
        fileUploadGroup.style.display = 'none';
        urlGroup.style.display = 'block';
        fileInput.required = false;
        urlInput.required = true;
    } else {
        fileUploadGroup.style.display = 'block';
        urlGroup.style.display = 'none';
        fileInput.required = true;
        urlInput.required = false;
    }
}

// File input handler
document.getElementById('resource_file').addEventListener('change', function(e) {
    const fileInfo = document.getElementById('fileInfo');
    if (this.files.length > 0) {
        const file = this.files[0];
        const fileSize = (file.size / (1024 * 1024)).toFixed(2); // MB
        fileInfo.textContent = `${file.name} (${fileSize} MB)`;
        fileInfo.style.color = 'var(--primary-sea-blue)';
    } else {
        fileInfo.textContent = 'No file selected';
        fileInfo.style.color = 'var(--text-secondary)';
    }
});

// Load existing note
async function loadExistingNote() {
    const week = document.getElementById('note_week').value;
    const classId = document.getElementById('note_class_id').value;
    const subjectId = document.getElementById('note_subject_id').value;
    
    if (!week || !classId || !subjectId) return;
    
    try {
        const response = await fetch(`../../api/teacher/get_note.php?week=${week}&class_id=${classId}&subject_id=${subjectId}`);
        const data = await response.json();
        
        if (data.success && data.note) {
            const note = data.note;
            // Populate form fields
            document.getElementById('unit').value = note.unit || '';
            document.getElementById('topic').value = note.topic || '';
            document.getElementById('strand').value = note.strand || '';
            document.getElementById('sub_strand').value = note.sub_strand || '';
            document.getElementById('content_standard').value = note.content_standard || '';
            document.getElementById('indicator').value = note.indicator || '';
            document.getElementById('objectives').value = note.objectives || '';
            document.getElementById('reference').value = note.reference || '';
            document.getElementById('resources').value = note.resources || '';
            document.getElementById('keywords').value = note.keywords || '';
            document.getElementById('starter').value = note.starter || '';
            document.getElementById('presentation').value = note.presentation || '';
            document.getElementById('summary').value = note.summary || '';
            document.getElementById('note_format').value = note.format || 'oxford';
            
            // Show success message
            showMessage('Note loaded successfully!', 'success');
        }
    } catch (error) {
        console.error('Error loading note:', error);
    }
}

// Reset note form
function resetNoteForm() {
    if (confirm('Are you sure you want to clear the form? All unsaved changes will be lost.')) {
        document.getElementById('notesForm').reset();
    }
}

// Preview note
function previewNote() {
    const form = document.getElementById('notesForm');
    const formData = new FormData(form);
    
    // Create preview content
    let previewHtml = `
        <div class="note-preview">
            <div class="preview-header">
                <h2>${formData.get('topic') || 'Untitled Lesson'}</h2>
                <div class="preview-meta">
                    <span><strong>Week:</strong> ${formData.get('week')}</span>
                    <span><strong>Format:</strong> ${formData.get('format')}</span>
                </div>
            </div>
            
            <div class="preview-section">
                <h3><i class="fas fa-layer-group"></i> Unit & Topic</h3>
                <p><strong>Unit:</strong> ${formData.get('unit') || 'N/A'}</p>
                <p><strong>Topic:</strong> ${formData.get('topic') || 'N/A'}</p>
            </div>
            
            <div class="preview-section">
                <h3><i class="fas fa-stream"></i> Strand & Sub-strand</h3>
                <p><strong>Strand:</strong> ${formData.get('strand') || 'N/A'}</p>
                <p><strong>Sub-strand:</strong> ${formData.get('sub_strand') || 'N/A'}</p>
            </div>
            
            <div class="preview-section">
                <h3><i class="fas fa-bullseye"></i> Content Standard & Indicator</h3>
                <p><strong>Content Standard:</strong> ${formData.get('content_standard') || 'N/A'}</p>
                <p><strong>Indicator:</strong> ${formData.get('indicator') || 'N/A'}</p>
            </div>
            
            <div class="preview-section">
                <h3><i class="fas fa-tasks"></i> Learning Objectives</h3>
                <p>${formData.get('objectives')?.replace(/\n/g, '<br>') || 'N/A'}</p>
            </div>
            
            <div class="preview-section">
                <h3><i class="fas fa-key"></i> Key Words</h3>
                <p>${formData.get('keywords') || 'N/A'}</p>
            </div>
            
            <div class="preview-section">
                <h3><i class="fas fa-play-circle"></i> Starter Activity</h3>
                <p>${formData.get('starter')?.replace(/\n/g, '<br>') || 'N/A'}</p>
            </div>
            
            <div class="preview-section">
                <h3><i class="fas fa-chalkboard-teacher"></i> Main Presentation</h3>
                <p>${formData.get('presentation')?.replace(/\n/g, '<br>') || 'N/A'}</p>
            </div>
            
            <div class="preview-section">
                <h3><i class="fas fa-clipboard-check"></i> Summary & Assessment</h3>
                <p>${formData.get('summary')?.replace(/\n/g, '<br>') || 'N/A'}</p>
            </div>
            
            <div class="preview-section">
                <h3><i class="fas fa-book-open"></i> References</h3>
                <p>${formData.get('reference')?.replace(/\n/g, '<br>') || 'N/A'}</p>
            </div>
            
            <div class="preview-section">
                <h3><i class="fas fa-tools"></i> Teaching Resources</h3>
                <p>${formData.get('resources')?.replace(/\n/g, '<br>') || 'N/A'}</p>
            </div>
        </div>
        
        <style>
            .note-preview { color: var(--text-primary); }
            .preview-header { margin-bottom: 30px; padding-bottom: 15px; border-bottom: 2px solid var(--border-color); }
            .preview-header h2 { color: var(--primary-sea-blue); margin-bottom: 10px; }
            .preview-meta { display: flex; gap: 20px; color: var(--text-secondary); }
            .preview-section { margin-bottom: 25px; padding-bottom: 15px; border-bottom: 1px solid var(--border-color); }
            .preview-section h3 { color: var(--text-primary); margin-bottom: 10px; display: flex; align-items: center; gap: 10px; }
            .preview-section h3 i { color: var(--primary-sea-blue); }
            .preview-section p { color: var(--text-secondary); line-height: 1.6; }
        </style>
    `;
    
    document.getElementById('notePreviewContent').innerHTML = previewHtml;
    document.getElementById('notePreviewModal').classList.add('active');
}

// Close note preview
function closeNotePreview() {
    document.getElementById('notePreviewModal').classList.remove('active');
}

// Print note preview
function printNotePreview() {
    const printContent = document.getElementById('notePreviewContent').innerHTML;
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
            <head>
                <title>Lesson Note - Print</title>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; padding: 20px; }
                    h2 { color: #17A2B8; border-bottom: 2px solid #eee; padding-bottom: 10px; }
                    h3 { color: #444; margin-top: 20px; }
                    .preview-section { margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee; }
                    .preview-meta { margin-bottom: 20px; color: #666; }
                    @media print {
                        body { padding: 0; }
                        .no-print { display: none; }
                    }
                </style>
            </head>
            <body>
                ${printContent}
            </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
}

// Resource functions
function refreshResources() {
    location.reload();
}

function exportResources() {
    // Implement export functionality
    alert('Export functionality will be implemented');
}

function editResource(id) {
    // Implement edit resource
    alert('Edit resource ' + id);
}

function deleteResource(id) {
    if (confirm('Are you sure you want to delete this resource? This action cannot be undone.')) {
        fetch(`../../api/teacher/delete_resource.php?id=${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-Token': '<?php echo $csrf_token; ?>'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage('Resource deleted successfully!', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showMessage('Error deleting resource: ' + data.message, 'error');
            }
        })
        .catch(error => {
            showMessage('Error deleting resource: ' + error, 'error');
        });
    }
}

// Note functions
function refreshNotes() {
    location.reload();
}

function printAllNotes() {
    alert('Print all notes functionality will be implemented');
}

function viewNote(id) {
    window.open(`../../api/teacher/view_note.php?id=${id}`, '_blank');
}

function editNote(id) {
    // Redirect to edit page or load into form
    fetch(`../../api/teacher/get_note.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.note) {
                const note = data.note;
                // Switch to notes tab
                switchTab('notes');
                // Populate form
                document.getElementById('note_week').value = note.week;
                document.getElementById('note_class_id').value = note.class_id;
                document.getElementById('note_subject_id').value = note.subject_id;
                document.getElementById('unit').value = note.unit || '';
                document.getElementById('topic').value = note.topic || '';
                document.getElementById('strand').value = note.strand || '';
                document.getElementById('sub_strand').value = note.sub_strand || '';
                document.getElementById('content_standard').value = note.content_standard || '';
                document.getElementById('indicator').value = note.indicator || '';
                document.getElementById('objectives').value = note.objectives || '';
                document.getElementById('reference').value = note.reference || '';
                document.getElementById('resources').value = note.resources || '';
                document.getElementById('keywords').value = note.keywords || '';
                document.getElementById('starter').value = note.starter || '';
                document.getElementById('presentation').value = note.presentation || '';
                document.getElementById('summary').value = note.summary || '';
                document.getElementById('note_format').value = note.format || 'oxford';
                
                showMessage('Note loaded for editing!', 'success');
            }
        })
        .catch(error => {
            showMessage('Error loading note: ' + error, 'error');
        });
}

function deleteNote(id) {
    if (confirm('Are you sure you want to delete this note? This action cannot be undone.')) {
        fetch(`../../api/teacher/delete_note.php?id=${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-Token': '<?php echo $csrf_token; ?>'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showMessage('Note deleted successfully!', 'success');
                setTimeout(() => location.reload(), 1000);
            } else {
                showMessage('Error deleting note: ' + data.message, 'error');
            }
        })
        .catch(error => {
            showMessage('Error deleting note: ' + error, 'error');
        });
    }
}

function printNote(id) {
    window.open(`../../api/teacher/print_note.php?id=${id}`, '_blank');
}

// Search and filter
document.getElementById('resourceSearch').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    const rows = document.querySelectorAll('#library-tab tbody tr');
    
    rows.forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(searchTerm) ? '' : 'none';
    });
});

document.getElementById('resourceFilter').addEventListener('change', function(e) {
    const filterType = e.target.value;
    const rows = document.querySelectorAll('#library-tab tbody tr');
    
    rows.forEach(row => {
        if (!filterType) {
            row.style.display = '';
            return;
        }
        
        const typeCell = row.querySelector('td:nth-child(2)');
        if (typeCell) {
            const type = typeCell.textContent.toLowerCase();
            row.style.display = type.includes(filterType) ? '' : 'none';
        }
    });
});

// Show message function
function showMessage(message, type) {
    // Remove existing alerts
    const existingAlert = document.querySelector('.alert');
    if (existingAlert) existingAlert.remove();
    
    // Create new alert
    const alert = document.createElement('div');
    alert.className = `alert alert-${type}`;
    alert.innerHTML = `
        <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
        ${message}
    `;
    
    // Insert after page header
    const pageHeader = document.querySelector('.page-header');
    pageHeader.parentNode.insertBefore(alert, pageHeader.nextSibling);
    
    // Auto remove after 5 seconds
    setTimeout(() => {
        alert.style.opacity = '0';
        setTimeout(() => alert.remove(), 300);
    }, 5000);
}

// Initialize when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Initialize resource type toggle
    toggleResourceFields();
    
    // Add push notification support
    if ('serviceWorker' in navigator && 'PushManager' in window) {
        navigator.serviceWorker.register('../../sw.js')
            .then(function(registration) {
                console.log('Service Worker registered with scope:', registration.scope);
                
                // Request notification permission
                if (Notification.permission === 'default') {
                    Notification.requestPermission().then(permission => {
                        if (permission === 'granted') {
                            console.log('Notification permission granted.');
                        }
                    });
                }
            })
            .catch(function(error) {
                console.log('Service Worker registration failed:', error);
            });
    }
});
</script>

<?php 
// Include footer
include $root_path . 'templates/teacher_footer.php';
?>