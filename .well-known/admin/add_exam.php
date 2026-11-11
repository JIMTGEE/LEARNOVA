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

// Handle POST action: add exam
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: add_exam.php");
        exit();
    }

    if (isset($_POST['action']) && $_POST['action'] === 'add_exam') {
        $class_id = intval($_POST['class_id'] ?? 0);
        $subject_id = intval($_POST['subject_id'] ?? 0);
        $term_id = intval($_POST['term_id'] ?? 0);
        $exam_date = $_POST['exam_date'] ?? '';
        $start_time = $_POST['start_time'] ?? null;
        $end_time = $_POST['end_time'] ?? null;
        $room = trim($_POST['room'] ?? '');
        $instructions = trim($_POST['instructions'] ?? '');

        // Validate inputs
        if ($class_id > 0 && $subject_id > 0 && $term_id > 0 && !empty($exam_date) && ($start_time === null || $end_time === null || $start_time < $end_time)) {
            // Check for conflicts: class not having another exam at the same time
            $conflict_query = "SELECT COUNT(*) FROM exam_schedules WHERE class_id = ? AND exam_date = ? AND (
                               (start_time < ? AND end_time > ?) OR 
                               (start_time < ? AND end_time > ?) OR 
                               (start_time >= ? AND start_time < ?) OR 
                               (end_time > ? AND end_time <= ?)
                               )";
            $stmt = $db->prepare($conflict_query);
            $stmt->execute([$class_id, $exam_date, $end_time ?: '23:59:59', $start_time ?: '00:00:00', $start_time ?: '00:00:00', $end_time ?: '23:59:59', $start_time ?: '00:00:00', $end_time ?: '23:59:59', $start_time ?: '00:00:00', $end_time ?: '23:59:59']);
            $conflict = $stmt->fetchColumn();

            if ($conflict == 0) {
                // No conflicts, insert exam schedule
                $insert_stmt = $db->prepare("INSERT INTO exam_schedules (class_id, subject_id, term_id, exam_date, start_time, end_time, room, instructions) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $insert_stmt->execute([$class_id, $subject_id, $term_id, $exam_date, $start_time ?: null, $end_time ?: null, $room, $instructions ?: null]);
                $new_id = $db->lastInsertId();
                log_activity($_SESSION['user_id'], 'add_exam', "Added new exam schedule for class ID $class_id, subject ID $subject_id (ID: $new_id)");
                $_SESSION['success'] = 'Exam schedule added successfully.';
            } else {
                $_SESSION['error'] = 'Scheduling conflict detected for the class on this date and time.';
            }
        } else {
            $_SESSION['error'] = 'Invalid input. Please check all required fields and ensure times are logical.';
        }
        header("Location: add_exam.php");
        exit();
    }
}

// Fetch all classes
$classes = $db->query("SELECT id, class_name FROM classes ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all terms
$terms = $db->query("SELECT t.id, t.name, ay.year FROM terms t JOIN academic_years ay ON t.academic_year_id = ay.id ORDER BY ay.year DESC, t.name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all subjects with teacher info
$subjects = $db->query("SELECT s.id, s.subject_name, u.name AS teacher_name FROM subjects s LEFT JOIN users u ON s.teacher_id = u.id ORDER BY s.subject_name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch class-subject mappings for dynamic filtering
$class_subjects = $db->query("SELECT class_id, subject_id FROM class_subjects")->fetchAll(PDO::FETCH_ASSOC);

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="add-exam">
        <h1 class="page-title">Add Exam Schedule</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <!-- Add Exam Form -->
        <div class="form-card">
            <h2>Add New Exam Schedule</h2>
            <form method="POST" id="add-exam-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="add_exam">

                <label>Class:</label>
                <select name="class_id" id="class-select" required>
                    <option value="">Select Class</option>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['class_name']); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Subject:</label>
                <select name="subject_id" id="subject-select" required>
                    <option value="">Select Subject (based on class)</option>
                </select>

                <label>Term:</label>
                <select name="term_id" required>
                    <option value="">Select Term</option>
                    <?php foreach ($terms as $term): ?>
                        <option value="<?php echo $term['id']; ?>"><?php echo htmlspecialchars($term['name'] . ' (' . $term['year'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Exam Date:</label>
                <input type="date" name="exam_date" required>

                <label>Start Time (optional):</label>
                <input type="time" name="start_time">

                <label>End Time (optional):</label>
                <input type="time" name="end_time">

                <label>Room:</label>
                <input type="text" name="room" maxlength="50">

                <label>Instructions:</label>
                <textarea name="instructions" rows="5"></textarea>

                <button type="submit">Add Exam Schedule</button>
            </form>
        </div>
    </section>

    <script>
        // Dynamic Subject Loading based on Class Selection
        document.getElementById('class-select').addEventListener('change', function() {
            const classId = this.value;
            const subjectSelect = document.getElementById('subject-select');
            subjectSelect.innerHTML = '<option value="">Loading...</option>';

            const classSubjects = <?php echo json_encode($class_subjects); ?>;
            const allSubjects = <?php echo json_encode($subjects); ?>;
            const filteredSubjects = allSubjects.filter(subject => 
                classSubjects.some(cs => cs.class_id == classId && cs.subject_id == subject.id)
            );

            subjectSelect.innerHTML = '<option value="">Select Subject</option>';
            filteredSubjects.forEach(subject => {
                const option = document.createElement('option');
                option.value = subject.id;
                option.textContent = `${subject.subject_name} (Teacher: ${subject.teacher_name || 'Unassigned'})`;
                subjectSelect.appendChild(option);
            });
        });

        // Form Validation: Ensure end time > start time if both provided
        document.getElementById('add-exam-form').addEventListener('submit', function(e) {
            const startTime = document.querySelector('input[name="start_time"]').value;
            const endTime = document.querySelector('input[name="end_time"]').value;
            if (startTime && endTime && startTime >= endTime) {
                alert('End time must be after start time if both are provided.');
                e.preventDefault();
            }
        });
    </script>

    <style>
        /* Add Exam Styles - Ultra Modern and Comprehensive */
        .add-exam {
            display: grid;
            gap: 24px;
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
        }

        .form-card {
            background-color: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            padding: 24px;
        }

        .form-card h2 {
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
            .add-exam {
                padding: 15px;
            }

            .form-card {
                padding: 16px;
            }
        }
    </style>

<?php include '../templates/admin_footer.php'; ?>