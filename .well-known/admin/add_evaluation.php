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

// Include database connection first to avoid dependency issues in helpers
require_once __DIR__ . '/../config/db.php';
$db = db(); // Use the helper function to get PDO instance

// Include helpers and functions with upgraded error handling
try {
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../functions/csrf.php';
    require_once __DIR__ . '/../functions/log_activity.php';
} catch (Exception $e) {
    error_log("Error loading helpers/functions: " . $e->getMessage());
    $_SESSION['error'] = 'System error: Unable to load required files. Please check logs.';
    header("Location: dashboard.php");
    exit();
}

// Handle POST action: add evaluation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: add_teacher_evaluation.php");
        exit();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'add_evaluation') {
        $teacher_id = intval($_POST['teacher_id'] ?? 0);
        $class_id = intval($_POST['class_id'] ?? 0);
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $term_id = intval($_POST['term_id'] ?? 0);
        $lesson_plan_id = intval($_POST['lesson_plan_id'] ?? 0);
        $scores = [];
        for ($i = 1; $i <= 17; $i++) {
            $scores[$i] = intval($_POST["score_$i"] ?? 0);
        }
        $positive_notes = trim($_POST['positive_notes'] ?? '');
        $negative_notes = trim($_POST['negative_notes'] ?? '');
        $cuas = trim($_POST['cuas'] ?? '');
        $week = trim($_POST['week'] ?? '');
        $attendance_status = $_POST['attendance_status'] ?? 'Present';
        $department_id = intval($_POST['department_id'] ?? 0);
        $admin_id = $_SESSION['user_id'];

        // Validate required fields
        if ($teacher_id > 0 && $class_id > 0 && $term_id > 0 && in_array($attendance_status, ['Absent', 'Present', 'Late'])) {
            // Insert evaluation
            $insert_query = "INSERT INTO teacher_evaluations 
                             (teacher_id, class_id, subject_id, term_id, lesson_plan_id, 
                              score_1, score_2, score_3, score_4, score_5, score_6, score_7, score_8, score_9, score_10, 
                              score_11, score_12, score_13, score_14, score_15, score_16, score_17, 
                              positive_notes, negative_notes, cuas, week, attendance_status, admin_id, department_id) 
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($insert_query);
            $params = [$teacher_id, $class_id, $subject_id ?: null, $term_id, $lesson_plan_id ?: null,
                       $scores[1], $scores[2], $scores[3], $scores[4], $scores[5], $scores[6], $scores[7], $scores[8], $scores[9], $scores[10],
                       $scores[11], $scores[12], $scores[13], $scores[14], $scores[15], $scores[16], $scores[17],
                       $positive_notes ?: null, $negative_notes ?: null, $cuas ?: null, $week ?: null, $attendance_status, $admin_id, $department_id ?: null];
            $stmt->execute($params);
            $new_id = $db->lastInsertId();
            log_activity($_SESSION['user_id'], 'add_teacher_evaluation', "Added new teacher evaluation for teacher ID $teacher_id (ID: $new_id)");
            $_SESSION['success'] = 'Teacher evaluation added successfully.';
        } else {
            $_SESSION['error'] = 'Invalid input. Please fill all required fields.';
        }
        header("Location: add_teacher_evaluation.php");
        exit();
    }
}

// Fetch teachers
$teachers = $db->query("SELECT id, CONCAT(first_name, ' ', last_name) AS name FROM teachers WHERE status = 'active' ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch classes
$classes = $db->query("SELECT id, class_name FROM classes ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch subjects
$subjects = $db->query("SELECT id, subject_name FROM subjects WHERE status = 'Active' ORDER BY subject_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch terms
$terms = $db->query("SELECT t.id, t.name, ay.year FROM terms t JOIN academic_years ay ON t.academic_year_id = ay.id ORDER BY ay.year DESC, t.name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch lesson plans (for optional linking)
$lesson_plans = $db->query("SELECT id, topic FROM lesson_plans ORDER BY lesson_date DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch departments
$departments = $db->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="add-evaluation">
        <h1 class="page-title">Add Teacher Evaluation</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <!-- Add Evaluation Form -->
        <div class="form-card">
            <h2>Add New Teacher Evaluation</h2>
            <form method="POST" id="add-evaluation-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_evaluation">

                <label>Teacher:</label>
                <select name="teacher_id" id="teacher-select" required>
                    <option value="">Select Teacher</option>
                    <?php foreach ($teachers as $teacher): ?>
                        <option value="<?php echo $teacher['id']; ?>"><?php echo htmlspecialchars($teacher['name']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Class:</label>
                <select name="class_id" required>
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Subject (optional):</label>
                <select name="subject_id">
                    <option value="">Select Subject</option>
                    <?php foreach ($subjects as $subject): ?>
                        <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['subject_name']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Term:</label>
                <select name="term_id" required>
                    <option value="">Select Term</option>
                    <?php foreach ($terms as $term): ?>
                        <option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['name'] . ' (' . $term['year'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Lesson Plan (optional):</label>
                <select name="lesson_plan_id">
                    <option value="">Select Lesson Plan</option>
                    <?php foreach ($lesson_plans as $plan): ?>
                        <option value="<?php echo $plan['id']; ?>"><?php echo htmlspecialchars($plan['topic']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Department (optional):</label>
                <select name="department_id">
                    <option value="">Select Department</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Attendance Status:</label>
                <select name="attendance_status" required>
                    <option value="Present">Present</option>
                    <option value="Absent">Absent</option>
                    <option value="Late">Late</option>
                </select>

                <label>CUAS (optional):</label>
                <input type="text" name="cuas" maxlength="100">

                <label>Week (optional):</label>
                <input type="text" name="week" maxlength="50">

                <h3>Scores (1-17)</h3>
                <div class="scores-grid">
                    <?php for ($i = 1; $i <= 17; $i++): ?>
                        <div class="score-field">
                            <label>Score <?php echo $i; ?>:</label>
                            <input type="number" name="score_<?php echo $i; ?>" min="0" max="10" value="0">
                        </div>
                    <?php endfor; ?>
                </div>

                <label>Positive Notes (optional):</label>
                <textarea name="positive_notes" rows="4"></textarea>

                <label>Negative Notes (optional):</label>
                <textarea name="negative_notes" rows="4"></textarea>

                <button type="submit">Add Evaluation</button>
            </form>
        </div>
    </section>

    <style>
        /* Add Evaluation Styles - Ultra Modern and Comprehensive */
        .add-evaluation {
            display: grid;
            gap: 24px;
            padding: 20px;
            max-width: 1000px;
            margin: 0 auto;
        }

        .form-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 24px;
        }

        .form-card h2, .form-card h3 {
            margin-bottom: 16px;
            color: var(--primary-black);
        }

        .form-card label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--accent-slate-gray);
        }

        .form-card input, .form-card select, .form-card textarea {
            width: 100%;
            padding: 12px;
            margin-bottom: 16px;
            border: 1px solid var(--accent-slate-gray);
            border-radius: 4px;
            background-color: var(--primary-white);
            transition: border-color 0.2s;
        }

        .form-card input:focus, .form-card select:focus, .form-card textarea:focus {
            border-color: var(--primary-sea-blue);
            outline: none;
        }

        .form-card button {
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.2s;
        }

        .form-card button:hover {
            background-color: var(--accent-soft-cyan);
            transform: translateY(-2px);
        }

        .scores-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }

        .score-field label {
            font-size: 14px;
        }

        .score-field input {
            margin-bottom: 0;
        }

        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: 500;
        }

        .alert.success {
            background-color: rgba(40, 167, 69, 0.1);
            color: #28a745;
        }

        .alert.error {
            background-color: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }

        @media (max-width: 768px) {
            .add-evaluation {
                padding: 15px;
            }

            .form-card {
                padding: 16px;
            }

            .scores-grid {
                grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
            }
        }
    </style>

<?php include '../templates/admin_footer.php'; ?>