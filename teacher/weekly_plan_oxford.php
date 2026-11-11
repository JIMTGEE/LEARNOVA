<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Restrict to teachers
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access: user_id=" . ($_SESSION['user_id'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}

// Database connection
try {
    require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
} catch (Exception $e) {
    error_log("DB include failed: " . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Unable to connect to database.";
    header("Location: /login.php");
    exit();
}

if (!$conn || mysqli_connect_error()) {
    error_log("DB connection failed: " . mysqli_connect_error());
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

$teacher_id = (int)$_SESSION['user_id'];

// Validate teacher
$teacher_sql = "SELECT id, name, department_id FROM users WHERE id = ? AND role = 'teacher'";
$teacher_stmt = $conn->prepare($teacher_sql);
$teacher_stmt->bind_param("i", $teacher_id);
$teacher_stmt->execute();
$teacher = $teacher_stmt->get_result()->fetch_assoc();
$teacher_stmt->close();

if (!$teacher) {
    error_log("Teacher not found: user_id=$teacher_id");
    $_SESSION['error_msg'] = "Teacher account not found.";
    header("Location: /login.php");
    exit();
}
$teacher_department_id = $teacher['department_id'];

// Log access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
$log_sql = "INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) VALUES (?, 'Accessed Oxford Lesson Plan', ?, ?, ?, NOW())";
$log_stmt = $conn->prepare($log_sql);
$log_desc = "Teacher accessed Oxford lesson plan page";
$log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
$log_stmt->execute() ?: error_log("Log failed: " . $log_stmt->error);
$log_stmt->close();

// CSRF token
$csrf_token = bin2hex(random_bytes(32));
$csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
$csrf_stmt = $conn->prepare($csrf_sql);
$csrf_stmt->bind_param("is", $teacher_id, $csrf_token);
$csrf_stmt->execute();
$csrf_stmt->close();
$conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");

// AJAX for schedule details
if (isset($_GET['action']) && $_GET['action'] === 'get_schedule_details') {
    $schedule_id = (int)($_GET['schedule_id'] ?? 0);
    $response = ['schedule' => null, 'error' => ''];

    if ($schedule_id <= 0) {
        $response['error'] = 'Invalid schedule ID.';
    } else {
        $schedule_sql = "SELECT cs.id, cs.class_id, cs.subject_id, cs.term_id, cs.department_id,
                        c.class_name, s.subject_name, ac.term_name, d.name AS department_name,
                        CONCAT(cs.day_of_week, ' ', cs.start_time, ' - ', cs.end_time) AS time_slot
                        FROM class_schedules cs
                        JOIN classes c ON cs.class_id = c.id
                        LEFT JOIN subjects s ON cs.subject_id = s.id
                        JOIN academic_calendar ac ON cs.term_id = ac.id
                        LEFT JOIN departments d ON cs.department_id = d.id
                        WHERE cs.id = ? AND cs.teacher_id = ? AND cs.department_id = ?";
        $schedule_stmt = $conn->prepare($schedule_sql);
        $schedule_stmt->bind_param("iii", $schedule_id, $teacher_id, $teacher_department_id);
        if ($schedule_stmt->execute()) {
            $schedule = $schedule_stmt->get_result()->fetch_assoc();
            if ($schedule) {
                $response['schedule'] = [
                    'class_id' => $schedule['class_id'],
                    'class_name' => $schedule['class_name'],
                    'subject_id' => $schedule['subject_id'] ?? 0,
                    'subject_name' => $schedule['subject_name'] ?? 'N/A',
                    'term_id' => $schedule['term_id'],
                    'term_name' => $schedule['term_name'],
                    'department_id' => $schedule['department_id'] ?? $teacher_department_id,
                    'department_name' => $schedule['department_name'] ?? 'N/A',
                    'time_slot' => $schedule['time_slot']
                ];
            } else {
                $response['error'] = 'No schedule found.';
            }
        } else {
            $response['error'] = 'Query failed.';
            error_log("Schedule query error: " . $schedule_stmt->error);
        }
        $schedule_stmt->close();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Fetch schedules (Oxford-specific department)
$schedules_sql = "SELECT cs.id, c.class_name, s.subject_name, ac.term_name, 
                 CONCAT(cs.day_of_week, ' ', cs.start_time, ' - ', cs.end_time) AS time_slot
                 FROM class_schedules cs
                 JOIN classes c ON cs.class_id = c.id
                 LEFT JOIN subjects s ON cs.subject_id = s.id
                 JOIN academic_calendar ac ON cs.term_id = ac.id
                 WHERE cs.teacher_id = ? AND cs.department_id = ? AND ac.start_date <= CURDATE() AND ac.end_date >= CURDATE()
                 ORDER BY cs.day_of_week, cs.start_time LIMIT 100";
$schedules_stmt = $conn->prepare($schedules_sql);
$schedules_stmt->bind_param("ii", $teacher_id, $teacher_department_id);
$schedules_stmt->execute();
$schedules = $schedules_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$schedules_stmt->close();

// Fetch Oxford lesson plans
$plans_sql = "SELECT tn.id, tn.week, c.class_name, ac.term_name, tn.created_at 
              FROM teacher_notes tn 
              JOIN classes c ON tn.class_id = c.id 
              JOIN academic_calendar ac ON tn.term_id = ac.id 
              WHERE tn.teacher_id = ? AND tn.format = 'oxford'
              ORDER BY tn.created_at DESC LIMIT 100";
$plans_stmt = $conn->prepare($plans_sql);
$plans_stmt->bind_param("i", $teacher_id);
$plans_stmt->execute();
$plans = $plans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
$plans_stmt->close();

// Handle form submission
$errors = [];
$success_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_csrf = filter_input(INPUT_POST, 'csrf_token', FILTER_UNSAFE_RAW) ?? '';
    if (!preg_match('/^[a-f0-9]{64}$/', $posted_csrf)) {
        $errors[] = "Invalid CSRF token.";
    } else {
        $csrf_check_sql = "SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
        $csrf_check_stmt = $conn->prepare($csrf_check_sql);
        $csrf_check_stmt->bind_param("is", $teacher_id, $posted_csrf);
        $csrf_check_stmt->execute();
        if ($csrf_check_stmt->get_result()->num_rows === 0) {
            $errors[] = "Invalid or expired CSRF token.";
        }
        $csrf_check_stmt->close();
    }

    if (empty($errors)) {
        $action = $_POST['action'] ?? '';
        if ($action === 'submit') {
            $week = filter_input(INPUT_POST, 'week', FILTER_VALIDATE_INT);
            $schedule_id = filter_input(INPUT_POST, 'schedule_id', FILTER_VALIDATE_INT);
            $class_id = filter_input(INPUT_POST, 'class_id', FILTER_VALIDATE_INT);
            $term_id = filter_input(INPUT_POST, 'term_id', FILTER_VALIDATE_INT);
            $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_VALIDATE_INT);
            $department_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
            $unit = htmlspecialchars(trim(filter_input(INPUT_POST, 'unit', FILTER_UNSAFE_RAW) ?? ''));
            $topic = htmlspecialchars(trim(filter_input(INPUT_POST, 'topic', FILTER_UNSAFE_RAW) ?? ''));
            $objectives = htmlspecialchars(trim(filter_input(INPUT_POST, 'objectives', FILTER_UNSAFE_RAW) ?? ''));
            $reference = htmlspecialchars(trim(filter_input(INPUT_POST, 'reference', FILTER_UNSAFE_RAW) ?? ''));
            $resources = htmlspecialchars(trim(filter_input(INPUT_POST, 'resources', FILTER_UNSAFE_RAW) ?? ''));
            $keywords = htmlspecialchars(trim(filter_input(INPUT_POST, 'keywords', FILTER_UNSAFE_RAW) ?? ''));
            $starter = htmlspecialchars(trim(filter_input(INPUT_POST, 'starter', FILTER_UNSAFE_RAW) ?? ''));
            $presentation = htmlspecialchars(trim(filter_input(INPUT_POST, 'presentation', FILTER_UNSAFE_RAW) ?? ''));
            $summary = htmlspecialchars(trim(filter_input(INPUT_POST, 'summary', FILTER_UNSAFE_RAW) ?? ''));

            if (!$week || $week < 1 || $week > 16) $errors[] = "Invalid week number (1-16 required).";
            if (!$schedule_id) $errors[] = "Invalid schedule selected.";
            if (!$class_id) $errors[] = "Invalid class selected.";
            if (!$term_id) $errors[] = "Invalid term selected.";
            if (!$subject_id) $errors[] = "Invalid subject selected.";
            if (!$department_id) $errors[] = "Invalid department selected.";
            if (empty($objectives)) $errors[] = "Objectives are required.";
            if (empty($reference)) $errors[] = "Reference is required.";
            if (empty($resources)) $errors[] = "Resources are required.";
            if (empty($keywords)) $errors[] = "Keywords are required.";
            if (empty($starter)) $errors[] = "Starter is required.";
            if (empty($presentation)) $errors[] = "Presentation is required.";
            if (strlen($presentation) > 5000) $errors[] = "Presentation cannot exceed 5000 characters.";
            if (empty($summary)) $errors[] = "Summary is required.";

            if (empty($errors)) {
                $conn->begin_transaction();
                try {
                    $insert_sql = "INSERT INTO teacher_notes (
                        teacher_id, week, class_id, term_id, subject_id, unit, topic, 
                        objectives, reference, resources, keywords, starter, presentation, summary, format, created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'oxford', NOW())";
                    $insert_stmt = $conn->prepare($insert_sql);
                    $insert_stmt->bind_param(
                        "iiiiisssssssss",
                        $teacher_id, $week, $class_id, $term_id, $subject_id, $unit, $topic,
                        $objectives, $reference, $resources, $keywords, $starter, $presentation, $summary
                    );
                    if ($insert_stmt->execute()) {
                        $success_msg = "Oxford lesson plan saved successfully!";
                        $log_desc = "Teacher created Oxford lesson plan for class_id: $class_id, week: $week, subject_id: $subject_id";
                        $log_stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) VALUES (?, 'Created Oxford Lesson Plan', ?, ?, ?, NOW())");
                        $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
                        $log_stmt->execute() ?: error_log("Log creation failed: " . $log_stmt->error);
                        $log_stmt->close();

                        $plans_stmt = $conn->prepare($plans_sql);
                        $plans_stmt->bind_param("i", $teacher_id);
                        $plans_stmt->execute();
                        $plans = $plans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                        $plans_stmt->close();

                        $conn->commit();
                    } else {
                        $errors[] = "Failed to save lesson plan.";
                        error_log("Insert error: " . $conn->error);
                        $conn->rollback();
                    }
                    $insert_stmt->close();
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = "Error saving lesson plan: " . $e->getMessage();
                    error_log("Insert exception: " . $e->getMessage());
                }
            }
        } elseif ($action === 'delete') {
            $plan_id = filter_input(INPUT_POST, 'plan_id', FILTER_VALIDATE_INT);
            if (!$plan_id || $plan_id <= 0) {
                $errors[] = "Invalid lesson plan ID.";
            } else {
                $conn->begin_transaction();
                try {
                    $check_sql = "SELECT id FROM teacher_notes WHERE id = ? AND teacher_id = ? AND format = 'oxford'";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bind_param("ii", $plan_id, $teacher_id);
                    $check_stmt->execute();
                    if ($check_stmt->get_result()->num_rows === 0) {
                        $errors[] = "Lesson plan not found or unauthorized.";
                        $conn->rollback();
                    } else {
                        $delete_sql = "DELETE FROM teacher_notes WHERE id = ? AND teacher_id = ? AND format = 'oxford'";
                        $delete_stmt = $conn->prepare($delete_sql);
                        $delete_stmt->bind_param("ii", $plan_id, $teacher_id);
                        if ($delete_stmt->execute()) {
                            $log_desc = "Teacher deleted Oxford lesson plan ID: $plan_id";
                            $log_stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, description, ip_address, user_agent, created_at) VALUES (?, 'Deleted Oxford Lesson Plan', ?, ?, ?, NOW())");
                            $log_stmt->bind_param("isss", $teacher_id, $log_desc, $ip_address, $user_agent);
                            $log_stmt->execute() ?: error_log("Log deletion failed: " . $log_stmt->error);
                            $log_stmt->close();

                            $success_msg = "Oxford lesson plan deleted successfully!";
                            $plans_stmt = $conn->prepare($plans_sql);
                            $plans_stmt->bind_param("i", $teacher_id);
                            $plans_stmt->execute();
                            $plans = $plans_stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
                            $plans_stmt->close();

                            $conn->commit();
                        } else {
                            $errors[] = "Failed to delete lesson plan.";
                            error_log("Delete error: " . $conn->error);
                            $conn->rollback();
                        }
                        $delete_stmt->close();
                    }
                    $check_stmt->close();
                } catch (Exception $e) {
                    $conn->rollback();
                    $errors[] = "Error deleting lesson plan: " . $e->getMessage();
                    error_log("Delete exception: " . $e->getMessage());
                }
            }
        } elseif ($action === 'cancel') {
            $success_msg = "Lesson plan creation cancelled.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Oxford Lesson Plan creation for QueensPa School teachers.">
    <meta name="keywords" content="QueensPa School, Oxford lesson plan, teacher portal, Oxford curriculum">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="QueensPa School Oxford Lesson Plan">
    <meta property="og:description" content="Teacher interface for creating Oxford curriculum lesson plans.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://qpmsedu.com/teacher/oxford_lesson_plan.php">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="QueensPa School Oxford Lesson Plan">
    <meta name="twitter:description" content="Teacher interface for creating Oxford curriculum lesson plans.">
    <title>Create Oxford Lesson Plan - QueensPa School</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
        }
        .card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .btn-primary {
            background-color: #ff4500;
            color: white;
            transition: background-color 0.3s ease;
        }
        .btn-primary:hover {
            background-color: #e03e00;
        }
        .btn-secondary {
            background-color: #6b7280;
            color: white;
        }
        .btn-secondary:hover {
            background-color: #4b5563;
        }
        .btn-danger {
            background-color: #dc2626;
            color: white;
        }
        .btn-danger:hover {
            background-color: #b91c1c;
        }
        .form-control {
            border: 1px solid #d1d5db;
            border-radius: 0.5rem;
            padding: 0.75rem;
            transition: border-color 0.3s ease;
        }
        .form-control:focus {
            border-color: #ff4500;
            box-shadow: 0 0 0 3px rgba(255, 69, 0, 0.1);
            outline: none;
        }
        .alert {
            border-radius: 0.5rem;
            padding: 1rem;
        }
        .loading-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .character-counter.warning {
            color: #ff4500;
        }
    </style>
</head>
<body class="min-h-screen">
    <div id="loading-overlay" class="loading-overlay">
        <i class="fas fa-spinner fa-spin text-4xl text-orange-500"></i>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>
    <div class="container mx-auto px-4 py-8">
        <?php if (!empty($errors)): ?>
            <div class="alert bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                <?php foreach ($errors as $error): ?>
                    <p class="mb-0"><?php echo htmlspecialchars($error); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($success_msg): ?>
            <div class="alert bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6" role="alert">
                <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>
        <section class="card p-6 mb-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Oxford Lesson Plans</h2>
            <?php if (empty($plans)): ?>
                <div class="alert bg-blue-100 border-l-4 border-blue-500 text-blue-700 p-4" role="alert">
                    No Oxford lesson plans created yet.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full table-auto border-collapse">
                        <thead>
                            <tr class="bg-gray-100">
                                <th class="px-4 py-2 text-left">Class</th>
                                <th class="px-4 py-2 text-left">Week</th>
                                <th class="px-4 py-2 text-left">Term</th>
                                <th class="px-4 py-2 text-left">Created At</th>
                                <th class="px-4 py-2 text-left">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($plans as $plan): ?>
                                <tr class="border-b">
                                    <td class="px-4 py-2"><?php echo htmlspecialchars($plan['class_name']); ?></td>
                                    <td class="px-4 py-2"><?php echo htmlspecialchars($plan['week']); ?></td>
                                    <td class="px-4 py-2"><?php echo htmlspecialchars($plan['term_name']); ?></td>
                                    <td class="px-4 py-2"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($plan['created_at']))); ?></td>
                                    <td class="px-4 py-2 flex space-x-2">
                                        <a href="view_oxford_lesson_plan.php?id=<?php echo $plan['id']; ?>" class="btn-primary px-3 py-1 rounded text-sm" title="View">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        <a href="edit_oxford_plan.php?id=<?php echo $plan['id']; ?>" class="bg-yellow-500 text-white px-3 py-1 rounded text-sm" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this Oxford lesson plan?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="plan_id" value="<?php echo $plan['id']; ?>">
                                            <button type="submit" class="btn-danger px-3 py-1 rounded text-sm" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
        <section class="card p-6 mb-8">
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Create New Oxford Lesson Plan</h2>
            <?php if (empty($schedules)): ?>
                <div class="alert bg-red-100 border-l-4 border-red-500 text-red-700 p-4" role="alert">
                    No active Oxford schedules available. Please contact your administrator.
                </div>
            <?php else: ?>
                <form id="oxfordLessonPlanForm" method="POST" action="" class="space-y-6" onsubmit="return validateForm()">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="submit">
                    <!-- Basic Information -->
                    <div class="bg-gray-50 p-6 rounded-lg">
                        <h3 class="text-xl font-semibold text-gray-700 mb-4">Basic Information</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="schedule_id" class="block text-sm font-medium text-gray-700">Schedule <span class="text-red-500">*</span></label>
                                <select id="schedule_id" name="schedule_id" class="form-control w-full mt-1" required>
                                    <option value="">Select Schedule</option>
                                    <?php foreach ($schedules as $schedule): ?>
                                        <option value="<?php echo $schedule['id']; ?>">
                                            <?php echo htmlspecialchars($schedule['class_name'] . ' - ' . 
                                                ($schedule['subject_name'] ?? 'N/A') . ' - ' . 
                                                $schedule['term_name'] . ' (' . $schedule['time_slot'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="loading-spinner hidden mt-2"><i class="fas fa-spinner fa-spin text-orange-500"></i></span>
                                <p class="text-red-500 text-sm mt-1 hidden invalid-feedback">Please select a schedule.</p>
                            </div>
                            <div>
                                <label for="week" class="block text-sm font-medium text-gray-700">Week <span class="text-red-500">*</span></label>
                                <select id="week" name="week" class="form-control w-full mt-1" required>
                                    <option value="">Select Week</option>
                                    <?php for ($i = 1; $i <= 16; $i++): ?>
                                        <option value="<?php echo $i; ?>">Week <?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                                <p class="text-red-500 text-sm mt-1 hidden invalid-feedback">Please select a week.</p>
                            </div>
                        </div>
                        <input type="hidden" id="class_id" name="class_id" required>
                        <input type="hidden" id="subject_id" name="subject_id">
                        <input type="hidden" id="term_id" name="term_id" required>
                        <input type="hidden" id="department_id" name="department_id" required>
                        <div id="schedule-container" class="mt-4"></div>
                    </div>
                    <!-- Oxford Curriculum Details -->
                    <div class="bg-gray-50 p-6 rounded-lg">
                        <h3 class="text-xl font-semibold text-gray-700 mb-4">Oxford Curriculum Details</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="unit" class="block text-sm font-medium text-gray-700">Unit</label>
                                <input type="text" id="unit" name="unit" class="form-control w-full mt-1" placeholder="Enter Oxford unit (optional)">
                            </div>
                            <div>
                                <label for="topic" class="block text-sm font-medium text-gray-700">Topic</label>
                                <input type="text" id="topic" name="topic" class="form-control w-full mt-1" placeholder="Enter Oxford topic (optional)">
                            </div>
                        </div>
                    </div>
                    <!-- Lesson Details -->
                    <div class="bg-gray-50 p-6 rounded-lg">
                        <h3 class="text-xl font-semibold text-gray-700 mb-4">Lesson Details</h3>
                        <div class="space-y-4">
                            <div>
                                <label for="objectives" class="block text-sm font-medium text-gray-700">Objectives <span class="text-red-500">*</span></label>
                                <textarea id="objectives" name="objectives" class="form-control w-full mt-1" rows="4" required maxlength="2000"></textarea>
                                <p class="text-red-500 text-sm mt-1 hidden invalid-feedback">Objectives are required.</p>
                            </div>
                            <div>
                                <label for="reference" class="block text-sm font-medium text-gray-700">Reference <span class="text-red-500">*</span></label>
                                <textarea id="reference" name="reference" class="form-control w-full mt-1" rows="4" required maxlength="2000"></textarea>
                                <p class="text-red-500 text-sm mt-1 hidden invalid-feedback">Reference is required.</p>
                            </div>
                            <div>
                                <label for="resources" class="block text-sm font-medium text-gray-700">Resources <span class="text-red-500">*</span></label>
                                <textarea id="resources" name="resources" class="form-control w-full mt-1" rows="4" required maxlength="2000"></textarea>
                                <p class="text-red-500 text-sm mt-1 hidden invalid-feedback">Resources are required.</p>
                            </div>
                            <div>
                                <label for="keywords" class="block text-sm font-medium text-gray-700">Keywords <span class="text-red-500">*</span></label>
                                <input type="text" id="keywords" name="keywords" class="form-control w-full mt-1" required maxlength="500" placeholder="e.g., critical thinking, collaboration">
                                <p class="text-red-500 text-sm mt-1 hidden invalid-feedback">Keywords are required.</p>
                            </div>
                        </div>
                    </div>
                    <!-- Lesson Delivery -->
                    <div class="bg-gray-50 p-6 rounded-lg">
                        <h3 class="text-xl font-semibold text-gray-700 mb-4">Lesson Delivery</h3>
                        <div class="space-y-4">
                            <div>
                                <label for="starter" class="block text-sm font-medium text-gray-700">Starter <span class="text-red-500">*</span></label>
                                <textarea id="starter" name="starter" class="form-control w-full mt-1" rows="4" required maxlength="2000"></textarea>
                                <p class="text-red-500 text-sm mt-1 hidden invalid-feedback">Starter is required.</p>
                            </div>
                            <div>
                                <label for="presentation" class="block text-sm font-medium text-gray-700">Presentation <span class="text-red-500">*</span></label>
                                <textarea id="presentation" name="presentation" class="form-control w-full mt-1" rows="6" required maxlength="5000"></textarea>
                                <p class="text-red-500 text-sm mt-1 hidden invalid-feedback">Presentation is required.</p>
                                <p id="presentation-counter" class="text-sm text-gray-500 mt-1">0/5000 characters</p>
                            </div>
                            <div>
                                <label for="summary" class="block text-sm font-medium text-gray-700">Summary <span class="text-red-500">*</span></label>
                                <textarea id="summary" name="summary" class="form-control w-full mt-1" rows="4" required maxlength="2000"></textarea>
                                <p class="text-red-500 text-sm mt-1 hidden invalid-feedback">Summary is required.</p>
                            </div>
                        </div>
                    </div>
                    <!-- Form Actions -->
                    <div class="flex space-x-4">
                        <button type="submit" id="submit-btn" class="btn-primary px-6 py-2 rounded-lg disabled:opacity-50" disabled>Submit Oxford Plan</button>
                        <button type="submit" name="action" value="cancel" class="btn-secondary px-6 py-2 rounded-lg">Cancel</button>
                    </div>
                </form>
            <?php endif; ?>
        </section>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script>
        function showLoading() {
            document.getElementById('loading-overlay').style.display = 'flex';
            return true;
        }

        function validateForm() {
            const form = document.getElementById('oxfordLessonPlanForm');
            const inputs = form.querySelectorAll('[required]');
            let valid = true;

            inputs.forEach(input => {
                if (!input.value.trim()) {
                    input.classList.add('border-red-500');
                    input.nextElementSibling?.classList.remove('hidden');
                    valid = false;
                } else {
                    input.classList.remove('border-red-500');
                    input.nextElementSibling?.classList.add('hidden');
                }
            });

            const classId = $('#class_id').val();
            const termId = $('#term_id').val();
            const departmentId = $('#department_id').val();
            if (!classId || !termId || !departmentId) {
                alert('Please select a valid Oxford schedule.');
                $('#schedule_id').focus();
                return false;
            }

            if (!valid) return false;
            return confirm('Are you sure you want to submit this Oxford lesson plan?');
        }

        document.addEventListener('DOMContentLoaded', () => {
            document.getElementById('loading-overlay').style.display = 'none';

            const presentationTextarea = document.getElementById('presentation');
            const counter = document.getElementById('presentation-counter');
            function updateCounter() {
                const length = presentationTextarea.value.length;
                counter.textContent = `${length}/5000 characters`;
                counter.classList.toggle('warning', length > 4500);
            }
            presentationTextarea.addEventListener('input', updateCounter);
            updateCounter();

            const textareas = document.querySelectorAll('textarea[maxlength]');
            textareas.forEach(textarea => {
                textarea.addEventListener('input', () => {
                    const max = parseInt(textarea.getAttribute('maxlength'));
                    if (textarea.value.length > max) {
                        textarea.value = textarea.value.substring(0, max);
                    }
                });
            });
        });

        $(document).ready(function() {
            $('#schedule_id').on('change', function() {
                const scheduleId = $(this).val();
                const scheduleContainer = $('#schedule-container');
                const classInput = $('#class_id');
                const subjectInput = $('#subject_id');
                const termInput = $('#term_id');
                const departmentInput = $('#department_id');
                const scheduleSpinner = $('.loading-spinner');
                const submitBtn = $('#submit-btn');

                classInput.val('');
                subjectInput.val('');
                termInput.val('');
                departmentInput.val('');
                scheduleContainer.empty();
                submitBtn.prop('disabled', true);

                if (scheduleId) {
                    scheduleSpinner.removeClass('hidden');
                    $.ajax({
                        url: '<?php echo $_SERVER['PHP_SELF']; ?>?action=get_schedule_details',
                        type: 'GET',
                        data: { schedule_id: scheduleId },
                        dataType: 'json',
                        success: function(response) {
                            scheduleSpinner.addClass('hidden');
                            if (response.error) {
                                scheduleContainer.html(
                                    `<div class="alert bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4">${response.error}</div>`
                                );
                            } else if (response.schedule) {
                                classInput.val(response.schedule.class_id);
                                subjectInput.val(response.schedule.subject_id || '');
                                termInput.val(response.schedule.term_id);
                                departmentInput.val(response.schedule.department_id);

                                const html = `
                                    <div class="bg-gray-100 p-4 rounded-lg">
                                        <h4 class="text-lg font-semibold text-gray-700">Selected Oxford Schedule</h4>
                                        <p><strong>Class:</strong> ${response.schedule.class_name}</p>
                                        <p><strong>Subject:</strong> ${response.schedule.subject_name}</p>
                                        <p><strong>Department:</strong> ${response.schedule.department_name}</p>
                                        <p><strong>Term:</strong> ${response.schedule.term_name}</p>
                                        <p><strong>Time:</strong> ${response.schedule.time_slot}</p>
                                    </div>
                                `;
                                scheduleContainer.html(html);
                                submitBtn.prop('disabled', !response.schedule.department_id);
                            } else {
                                scheduleContainer.html(
                                    '<div class="alert bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4">No Oxford schedule details available.</div>'
                                );
                            }
                        },
                        error: function() {
                            scheduleSpinner.addClass('hidden');
                            scheduleContainer.html(
                                '<div class="alert bg-red-100 border-l-4 border-red-500 text-red-700 p-4">Error fetching schedule details.</div>'
                            );
                        }
                    });
                }
            });
        });
    </script>
</body>
</html>
<?php
mysqli_close($conn);
ob_end_flush();
?>