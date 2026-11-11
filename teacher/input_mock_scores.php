<?php
// Disable error reporting for production
error_reporting(0);
ini_set('display_errors', 0);
ini_set('max_execution_time', 60);
ini_set('post_max_size', '16M');
ini_set('upload_max_filesize', '16M');
// Start output buffering and session
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']) ? '1' : '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
}
// Restrict to teachers only
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'teacher') {
    error_log("Unauthorized access attempt: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset'));
    $_SESSION['error_msg'] = "Please log in as a teacher.";
    header("Location: /login.php");
    exit();
}
// Include database connection
$db_path = $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
if (!file_exists($db_path)) {
    error_log("Database config file missing: $db_path");
    $_SESSION['error_msg'] = "System error: Server configuration error.";
    header("Location: /login.php");
    exit();
}
require_once($db_path);
// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}
// Validate user_id
$teacher_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id FROM users WHERE id = ? AND role = 'teacher'";
$user_check_stmt = $conn->prepare($user_check_sql);
if (!$user_check_stmt) {
    error_log("Prepare failed for user check: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to validate user.";
    header("Location: /login.php");
    exit();
}
$user_check_stmt->bind_param("i", $teacher_id);
$user_check_stmt->execute();
if ($user_check_stmt->get_result()->num_rows === 0) {
    error_log("Invalid teacher_id: $teacher_id");
    $_SESSION['error_msg'] = "Invalid user session.";
    header("Location: /login.php");
    exit();
}
$user_check_stmt->close();
// CSRF Token Management
function generateCsrfToken($conn, $user_id) {
    $token = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+1 hour'));
    $insert_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), ?)";
    $stmt = $conn->prepare($insert_sql);
    if ($stmt) {
        $stmt->bind_param("iss", $user_id, $token, $expires_at);
        if ($stmt->execute()) {
            $stmt->close();
            return $token;
        }
        $stmt->close();
    }
    error_log("Failed to generate CSRF token for user_id $user_id");
    return bin2hex(random_bytes(32));
}
function validateCsrfToken($conn, $user_id, $token) {
    if (empty($token)) return false;
    $sql = "SELECT token FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $user_id, $token);
        $stmt->execute();
        $result = $stmt->get_result();
        $valid = $result->num_rows > 0;
        if ($valid) {
            $delete_sql = "DELETE FROM csrf_tokens WHERE user_id = ? AND token = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            if ($delete_stmt) {
                $delete_stmt->bind_param("is", $user_id, $token);
                $delete_stmt->execute();
                $delete_stmt->close();
            }
        }
        $stmt->close();
        return $valid;
    }
    error_log("CSRF validation failed for user_id $user_id");
    return false;
}
$_SESSION['csrf_token'] = generateCsrfToken($conn, $teacher_id);
// Current date
$current_date = date('Y-m-d');
$current_timestamp = date('Y-m-d H:i:s');
// Fetch current term
$term_query = "SELECT id, year FROM academic_calendar WHERE start_date <= ? AND end_date >= ?";
$term_stmt = $conn->prepare($term_query);
$term_stmt->bind_param("ss", $current_date, $current_date);
$term_stmt->execute();
$term_result = $term_stmt->get_result();
$current_term = $term_result->fetch_assoc();
$term_stmt->close();
if (!$current_term) {
    error_log("No active term found for date: $current_date");
    $_SESSION['error_msg'] = "No active term found.";
    header("Location: view_progress.php");
    exit();
}
$term_id = $current_term['id'];
$academic_year = $current_term['year'];

// === CHANGE 1: Only Majesty class ===
$classes_query = "SELECT id, class_name, department_id FROM classes WHERE class_name = 'Majesty'";
$classes_result = $conn->query($classes_query);
$classes = [];
$majesty_department_id = 1; // fallback
while ($row = $classes_result->fetch_assoc()) {
    $classes[] = $row;
    $majesty_department_id = (int)$row['department_id'];
}

// Handle class selection
$selected_class_id = intval($_GET['class_id'] ?? $_SESSION['mock_selected_class_id'] ?? 0);
if ($selected_class_id > 0) {
    $_SESSION['mock_selected_class_id'] = $selected_class_id;
}

// === CHANGE 2: Use Majesty department_id for new subjects ===
$bece_subjects = [
    ['name' => 'English Language', 'code' => '001'],
    ['name' => 'Mathematics', 'code' => '030'],
    ['name' => 'Science (General/Integrated)', 'code' => '034'],
    ['name' => 'Social Studies', 'code' => '020'],
    ['name' => 'Religious & Moral Education (RME)', 'code' => '022'],
    ['name' => 'Ghanaian Language', 'code' => '003-013'],
    ['name' => 'Career Technology', 'code' => '040'],
    ['name' => 'Creative Art & Design', 'code' => '041'],
    ['name' => 'Computing', 'code' => '071'],
    ['name' => 'French', 'code' => '002', 'optional' => true],
    ['name' => 'Arabic', 'code' => '014', 'optional' => true]
];

// Fetch or create subject IDs — using Majesty's department_id
$subjects = [];
foreach ($bece_subjects as $bs) {
    $sub_name = $bs['name'];
    $sub_query = "SELECT id FROM subjects WHERE subject_name = ?";
    $sub_stmt = $conn->prepare($sub_query);
    $sub_stmt->bind_param("s", $sub_name);
    $sub_stmt->execute();
    $sub_result = $sub_stmt->get_result();
    if ($row = $sub_result->fetch_assoc()) {
        $subjects[] = ['id' => $row['id'], 'subject_name' => $sub_name, 'code' => $bs['code'], 'optional' => $bs['optional'] ?? false];
    } else {
        $code = $bs['code'] ?? 'N/A';
        // Use Majesty department_id instead of 1
        $insert_sub = "INSERT INTO subjects (subject_name, subject_code, department_id, created_at) VALUES (?, ?, ?, NOW())";
        $insert_stmt = $conn->prepare($insert_sub);
        $insert_stmt->bind_param("ssi", $sub_name, $code, $majesty_department_id);
        if ($insert_stmt->execute()) {
            $new_id = $conn->insert_id;
            $subjects[] = ['id' => $new_id, 'subject_name' => $sub_name, 'code' => $code, 'optional' => $bs['optional'] ?? false];
        }
        $insert_stmt->close();
    }
    $sub_stmt->close();
}

// Detect edit mode
$edit_mock_number = intval($_GET['edit_mock'] ?? 0);
$is_edit_mode = $edit_mock_number > 0;

// Pre-load existing scores
$existing_scores = [];
if ($is_edit_mode && $selected_class_id > 0) {
    $load_query = "SELECT student_id, subject_id, mock_raw as score FROM academic_progress_entries
                   WHERE class_id = ? AND term_id = ? AND mock_number = ? AND mock_raw >= 0";
    $load_stmt = $conn->prepare($load_query);
    if ($load_stmt) {
        $load_stmt->bind_param("iii", $selected_class_id, $term_id, $edit_mock_number);
        $load_stmt->execute();
        $load_result = $load_stmt->get_result();
        while ($row = $load_result->fetch_assoc()) {
            $key = "{$row['student_id']}_{$row['subject_id']}";
            $existing_scores[$key] = $row['score'];
        }
        $load_stmt->close();
    }
}

// Calculate next mock number
$next_mock_number = 1;
if ($selected_class_id > 0) {
    $max_mock_query = "SELECT MAX(mock_number) as max_num FROM academic_progress_entries
                       WHERE class_id = ? AND term_id = ? AND mock_number > 0";
    $max_stmt = $conn->prepare($max_mock_query);
    if ($max_stmt) {
        $max_stmt->bind_param("ii", $selected_class_id, $term_id);
        $max_stmt->execute();
        $max_result = $max_stmt->get_result();
        if ($row = $max_result->fetch_assoc()) {
            $next_mock_number = max(1, intval($row['max_num']) + 1);
        }
        $max_stmt->close();
    }
}

/* --------------------------------------------------------------
   POST – per-student submission + DELETE ENTIRE STUDENT MOCK
   -------------------------------------------------------------- */
$success_message = '';
$redirect_url = "input_mock_scores.php?class_id=$selected_class_id";
$draft_key_to_clear = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($conn, $teacher_id, $csrf_token)) {
        error_log("Invalid CSRF token");
        header("Location: $redirect_url"); exit();
    }
    $class_id = (int)($_POST['class_id'] ?? 0);
    $student_id = (int)($_POST['student_id'] ?? 0);
    $mock_number = (int)($_POST['mock_number'] ?? 0);
    if ($class_id <= 0 || $student_id <= 0 || $mock_number < 1 || $mock_number > 14) {
        header("Location: $redirect_url"); exit();
    }
    if ($selected_class_id && $selected_class_id != $class_id) {
        header("Location: $redirect_url"); exit();
    }
    // === DELETE ENTIRE STUDENT MOCK ===
    if (!empty($_POST['delete_student_mock'])) {
        $del = $conn->prepare("
            DELETE FROM academic_progress_entries
            WHERE class_id = ? AND term_id = ? AND mock_number = ? AND student_id = ? AND teacher_id = ?
        ");
        if ($del) {
            $del->bind_param("iiiii", $class_id, $term_id, $mock_number, $student_id, $teacher_id);
            $del->execute();
            $deleted = $del->affected_rows;
            $success_message = $deleted ? "Deleted all scores for this student (Mock #$mock_number)." : "No scores to delete.";
            $del->close();
        } else {
            $success_message = "Delete failed.";
            error_log("Delete prepare failed: " . $conn->error);
        }
        $extra = $is_edit_mode ? "&edit_mock=$mock_number" : '';
        header("Location: $redirect_url$extra");
        exit();
    }
    // === VALIDATE CLASS & STUDENT — Only Majesty allowed ===
    $chk = $conn->prepare("SELECT 1 FROM classes WHERE id=? AND class_name = 'Majesty'");
    $chk->bind_param("i",$class_id); $chk->execute();
    if ($chk->get_result()->num_rows === 0) { $chk->close(); header("Location: $redirect_url"); exit(); }
    $chk->close();
    $chk = $conn->prepare("SELECT 1 FROM users u JOIN student_classes sc ON u.id=sc.user_id WHERE sc.class_id=? AND u.id=? AND u.role='student'");
    $chk->bind_param("ii",$class_id,$student_id); $chk->execute();
    if ($chk->get_result()->num_rows === 0) { $chk->close(); header("Location: $redirect_url"); exit(); }
    $chk->close();
    // === CLEAN OLD MOCK (NEW ONLY) ===
    $skip_dup = $is_edit_mode && $mock_number == $edit_mock_number;
    if (!$skip_dup) {
        $dup = $conn->prepare("SELECT COUNT(*) AS c FROM academic_progress_entries WHERE class_id=? AND term_id=? AND mock_number=?");
        $dup->bind_param("iii",$class_id,$term_id,$mock_number);
        $dup->execute(); $dup->bind_result($cnt); $dup->fetch(); $dup->close();
        if ($cnt) {
            $clean = $conn->prepare("DELETE FROM academic_progress_entries WHERE class_id=? AND term_id=? AND mock_number=?");
            $clean->bind_param("iii",$class_id,$term_id,$mock_number);
            $clean->execute(); $cleaned = $clean->affected_rows; $clean->close();
        }
    }
    // === SAVE SCORES ===
    $conn->autocommit(FALSE);
    $updated = $deleted = 0;
    $ok = TRUE;
    foreach ($subjects as $subject) {
        if (!$ok) break;
        $score_key = "score_{$student_id}_{$subject['id']}";
        $score = round((float)($_POST[$score_key] ?? 0), 2);
        $key = "{$student_id}_{$subject['id']}";
        $exists = isset($existing_scores[$key]);
        if ($score > 0 && $score <= 100) {
            $grade = get_bece_grade($score);
            $comment = "Mock Exam #$mock_number Score: " . number_format($score,2) . "% ($grade)";
            if ($is_edit_mode && $exists) {
                $sql = "UPDATE academic_progress_entries SET mock_raw=?, mock_grade=?, mock_comments=?, updated_at=NOW()
                        WHERE class_id=? AND term_id=? AND mock_number=? AND student_id=? AND subject_id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("dsssiiiii", $score, $grade, $comment, $class_id, $term_id, $mock_number, $student_id, $subject['id']);
                $ok = $stmt->execute(); if ($ok) $updated++; $stmt->close();
            } else {
                $sql = "INSERT INTO academic_progress_entries
                        (student_id, teacher_id, class_id, subject_id, term_id, mock_number, academic_year, entry_period,
                         period_start_date, period_end_date, mock_raw, mock_max, mock_grade, mock_comments,
                         comments, status, bulk_max_input, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'bi-weekly', ?, ?, ?, 100.00, ?, ?, '', 'submitted', 0, NOW(), NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiiiiiissdss",
                    $student_id, $teacher_id, $class_id, $subject['id'], $term_id,
                    $mock_number, $academic_year, $current_date, $current_date,
                    $score, $grade, $comment
                );
                $ok = $stmt->execute(); if ($ok) $updated++; $stmt->close();
            }
        } elseif ($is_edit_mode && $exists) {
            $sql = "DELETE FROM academic_progress_entries WHERE class_id=? AND term_id=? AND mock_number=? AND student_id=? AND subject_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiiii", $class_id, $term_id, $mock_number, $student_id, $subject['id']);
            $ok = $stmt->execute(); if ($ok) $deleted += $stmt->affected_rows; $stmt->close();
        }
    }
    if ($ok && $conn->commit()) {
        $msg = "Saved $updated score(s)";
        if ($deleted) $msg .= " – removed $deleted blank(s)";
        if (isset($cleaned)) $msg .= " – cleared $cleaned old row(s)";
        $success_message = $msg;
        $draft_key_to_clear = "mock_draft_{$class_id}_{$student_id}_".($is_edit_mode?'edit':'new');
        if (!$is_edit_mode) $_SESSION['mock_submitted_new'] = true;
    } else {
        $conn->rollback();
        $success_message = "Error – nothing saved.";
    }
    $conn->autocommit(TRUE);
    $extra = $is_edit_mode ? "&edit_mock=$mock_number" : '';
    header("Location: $redirect_url$extra");
    exit();
}

/* --------------------------------------------------------------
   Fetch Mock History
   -------------------------------------------------------------- */
$numbered_mocks = [];
if ($selected_class_id > 0) {
    $history_query = "SELECT u.name as student_name, s.subject_name, ape.id as entry_id, ape.mock_raw as score, ape.mock_grade, ape.mock_comments, DATE(ape.created_at) as mock_date, ape.mock_number, ape.student_id
                      FROM academic_progress_entries ape
                      JOIN users u ON ape.student_id = u.id
                      JOIN subjects s ON ape.subject_id = s.id
                      WHERE ape.class_id = ? AND ape.term_id = ? AND ape.mock_raw > 0 AND ape.mock_number > 0
                      ORDER BY ape.mock_number ASC, ape.created_at ASC, u.name, s.subject_name";
    $history_stmt = $conn->prepare($history_query);
    if ($history_stmt) {
        $history_stmt->bind_param("ii", $selected_class_id, $term_id);
        $history_stmt->execute();
        $history_result = $history_stmt->get_result();
        $all_mocks = [];
        while ($row = $history_result->fetch_assoc()) {
            if (empty($row['mock_grade'])) $row['mock_grade'] = get_bece_grade($row['score']);
            $all_mocks[] = $row;
        }
        $history_stmt->close();
        $groups = [];
        foreach ($all_mocks as $row) {
            $num = $row['mock_number'];
            $date = $row['mock_date'];
            $student_id = $row['student_id'];
            if (!isset($groups[$num])) $groups[$num] = [];
            if (!isset($groups[$num][$date])) $groups[$num][$date] = [];
            if (!isset($groups[$num][$date][$student_id])) $groups[$num][$date][$student_id] = [];
            $groups[$num][$date][$student_id][] = $row;
        }
        ksort($groups);
        foreach ($groups as $mock_num => $dates) {
            $earliest_date = min(array_keys($dates));
            $formatted_date = date('M d, Y', strtotime($earliest_date));
            $title = "Mock Exam #$mock_num ($formatted_date)";
            $student_groups = [];
            foreach ($dates as $date_data) {
                foreach ($date_data as $student_id => $subjects_data) {
                    $student_name = $subjects_data[0]['student_name'];
                    if (!isset($student_groups[$student_name])) $student_groups[$student_name] = ['id' => $student_id, 'subjects' => []];
                    $student_groups[$student_name]['subjects'] = array_merge($student_groups[$student_name]['subjects'], $subjects_data);
                }
            }
            $numbered_mocks[$title] = [
                'mock_number' => $mock_num,
                'students' => $student_groups
            ];
        }
    }
}
function get_bece_grade($score) {
    if ($score >= 95) return 'Grade 1 | Highest (Excellent)';
    elseif ($score >= 85) return 'Grade 2 | Higher (Very Good)';
    elseif ($score >= 75) return 'Grade 3 | High (Good)';
    elseif ($score >= 65) return 'Grade 4 | High Average';
    elseif ($score >= 55) return 'Grade 5 | Average';
    elseif ($score >= 45) return 'Grade 6 | Low Average';
    elseif ($score >= 36) return 'Grade 7 | Low';
    elseif ($score >= 31) return 'Grade 8 | Lower';
    else return 'Grade 9 | Lowest';
}
// Fetch students
$students = [];
if ($selected_class_id > 0) {
    $students_query = "SELECT u.id, u.name FROM users u JOIN student_classes sc ON u.id = sc.user_id WHERE sc.class_id = ? AND u.role = 'student' ORDER BY u.name";
    $students_stmt = $conn->prepare($students_query);
    if ($students_stmt) {
        $students_stmt->bind_param("i", $selected_class_id);
        $students_stmt->execute();
        $students_result = $students_stmt->get_result();
        while ($row = $students_result->fetch_assoc()) {
            $students[] = $row;
        }
        $students_stmt->close();
    }
}
// Get class name
$selected_class_name = '';
if ($selected_class_id > 0) {
    $class_name_stmt = $conn->prepare("SELECT class_name FROM classes WHERE id = ?");
    $class_name_stmt->bind_param("i", $selected_class_id);
    $class_name_stmt->execute();
    $result = $class_name_stmt->get_result();
    if ($row = $result->fetch_assoc()) $selected_class_name = $row['class_name'];
    $class_name_stmt->close();
}
include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Input Mock Scores - QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        body {font-family:'Inter',sans-serif;background:linear-gradient(to bottom right,#1a202c,#2d3748);color:#e5e7eb;min-height:100vh;}
        .glassmorphism{background:rgba(255,255,255,0.1);backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.2);box-shadow:0 4px 6px rgba(0,0,0,0.1);}
        .glassmorphism:hover{transform:translateY(-4px);box-shadow:0 8px 16px rgba(0,0,0,0.2);}
        h1,h2,h3,h4{font-family:'Orbitron',sans-serif;}
        .form-input{background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.3);color:#fff;border-radius:8px;padding:.5rem;}
        .form-input:focus{border-color:#10b981;box-shadow:0 0 0 .25rem rgba(16,185,129,.25);}
        .table-row-hover:hover{background:rgba(16,185,129,.1);}
        .hidden{display:none;}
        .student-card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:12px;margin-bottom:1rem;overflow:hidden;}
        .student-card.collapsed{max-height:60px;}
        .student-card.expanded{max-height:1000px;}
        .student-header{background:rgba(16,185,129,.2);padding:1rem;border-bottom:1px solid rgba(255,255,255,.1);cursor:pointer;}
        .subjects-table th,.subjects-table td{padding:.5rem;text-align:left;border-bottom:1px solid rgba(255,255,255,.05);}
        .subjects-table th{background:rgba(0,0,0,.2);font-weight:600;}
        .edit-mode .form-input{background:rgba(34,197,94,.2);}
        .score-note{font-size:.75rem;color:#9ca3af;}
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-900 to-emerald-900">
    <div class="content">
        <main class="container mx-auto px-4 py-8 max-w-7xl">
            <div class="flex justify-between items-center mb-8">
                <h1 class="text-3xl font-bold text-white">Input Mock Scores (BECE Prep)</h1>
                <a href="view_progress.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg glassmorphism">
                    Back to Progress
                </a>
            </div>
            <?php if ($success_message): ?>
                <div class="bg-green-600 text-white p-4 rounded-lg mb-4 glassmorphism"><?= htmlspecialchars($success_message) ?></div>
                <?php if ($draft_key_to_clear): ?>
                    <script>localStorage.removeItem('<?= htmlspecialchars($draft_key_to_clear) ?>');</script>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (empty($students)): ?>
                <section class="glassmorphism rounded-2xl p-6 mb-8">
                    <h3 class="text-xl font-semibold mb-4 text-white">Select Class</h3>
                    <form method="GET" id="class-form">
                        <select name="class_id" class="form-input w-full md:w-64" required onchange="this.form.submit()">
                            <option value="">Select a Class</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['id'] ?>" <?= $selected_class_id == $class['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($class['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </section>
            <?php else: ?>
                <section class="glassmorphism rounded-2xl p-6 mb-8">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="text-xl font-semibold text-white">
                            <?= $is_edit_mode ? "Editing Mock Exam #$edit_mock_number" : "New Mock – Next: #$next_mock_number" ?>
                            for <?= htmlspecialchars($selected_class_name) ?>
                        </h3>
                        <button type="button" onclick="window.location.href='input_mock_scores.php?class_id=<?= $selected_class_id ?>'" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">
                            Refresh
                        </button>
                    </div>
                    <?php if (!$is_edit_mode): ?>
                        <div class="mb-4">
                            <label class="block text-sm font-medium text-white mb-2">Mock Exam Number</label>
                            <select id="global-mock-number" class="form-input w-32" onchange="updateMockNumbers()">
                                <?php for ($i = 1; $i <= 14; $i++): ?>
                                    <option value="<?= $i ?>" <?= $next_mock_number == $i ? 'selected' : '' ?>>#<?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                    <button type="button" onclick="clearAllForms()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm mb-4">
                        Clear All Forms
                    </button>
                    <div class="space-y-4">
                        <?php foreach ($students as $student): ?>
                            <div class="student-card collapsed glassmorphism" data-student-id="<?= $student['id'] ?>">
                                <div class="student-header" onclick="toggleStudentCard(<?= $student['id'] ?>)">
                                    <div class="flex justify-between items-center">
                                        <span class="font-semibold text-white"><?= htmlspecialchars($student['name']) ?></span>
                                        <i class="fas fa-chevron-down text-white transition-transform" id="chevron-<?= $student['id'] ?>"></i>
                                    </div>
                                </div>
                                <form method="POST" class="p-4 hidden" id="form-<?= $student['id'] ?>">
                                    <input type="hidden" name="student_id" value="<?= $student['id'] ?>">
                                    <input type="hidden" name="class_id" value="<?= $selected_class_id ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                    <input type="hidden" name="mock_number" value="<?= $is_edit_mode ? $edit_mock_number : $next_mock_number ?>">
                                    <div class="overflow-x-auto mb-4">
                                        <table class="subjects-table w-full text-xs text-gray-300">
                                            <thead><tr><th>Subject</th><th class="text-center">Score (%)</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($subjects as $subject): ?>
                                                    <tr class="table-row-hover">
                                                        <td><?= htmlspecialchars($subject['subject_name']) ?> (<?= $subject['code'] ?>)</td>
                                                        <td class="text-center">
                                                            <input type="number" name="score_<?= $student['id'] ?>_<?= $subject['id'] ?>"
                                                                   min="0" max="100" step="0.01" class="form-input w-20 text-center"
                                                                   value="<?= $existing_scores["{$student['id']}_{$subject['id']}"] ?? '' ?>">
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    <div class="flex justify-end space-x-2">
                                        <button type="button" onclick="clearStudentForm(<?= $student['id'] ?>)" class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-1 rounded text-xs">Clear</button>
                                        <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-1 rounded">
                                            Submit
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <!-- HISTORY WITH ONE DELETE PER STUDENT -->
                <?php foreach ($numbered_mocks as $title => $mock_info): ?>
                    <section class="glassmorphism rounded-2xl p-6 mb-8">
                        <div class="flex justify-between items-center mb-4">
                            <h4 class="text-lg font-semibold text-white"><?= htmlspecialchars($title) ?></h4>
                            <a href="?class_id=<?= $selected_class_id ?>&edit_mock=<?= $mock_info['mock_number'] ?>" class="bg-yellow-600 hover:bg-yellow-700 text-white px-4 py-2 rounded-lg text-sm">
                                Edit
                            </a>
                        </div>
                        <div class="space-y-4">
                            <?php foreach ($mock_info['students'] as $student_name => $data): ?>
                                <div class="student-card glassmorphism">
                                    <div class="student-header">
                                        <div class="flex justify-between items-center">
                                            <h5 class="text-md font-semibold text-white"><?= htmlspecialchars($student_name) ?></h5>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete ALL scores for <?= htmlspecialchars($student_name) ?> in Mock #<?= $mock_info['mock_number'] ?>?')">
                                                <input type="hidden" name="delete_student_mock" value="1">
                                                <input type="hidden" name="student_id" value="<?= $data['id'] ?>">
                                                <input type="hidden" name="mock_number" value="<?= $mock_info['mock_number'] ?>">
                                                <input type="hidden" name="class_id" value="<?= $selected_class_id ?>">
                                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-3 py-1 rounded text-xs">
                                                    Delete All
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <div class="p-4">
                                        <table class="subjects-table w-full text-xs text-gray-300">
                                            <thead><tr><th>Subject</th><th>Score</th><th>Grade</th><th>Comment</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($data['subjects'] as $s): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($s['subject_name']) ?></td>
                                                        <td class="text-center font-semibold"><?= number_format($s['score'], 2) ?></td>
                                                        <td class="text-center"><?= htmlspecialchars($s['mock_grade']) ?></td>
                                                        <td class="text-sm"><?= htmlspecialchars($s['mock_comments']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endforeach; ?>
                <!-- BECE Grading Key -->
                <section class="glassmorphism rounded-2xl p-6 mb-8">
                    <h3 class="text-xl font-semibold mb-4 text-white">BECE Grading Key</h3>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left text-gray-300 border border-gray-600 rounded-lg">
                            <thead class="text-xs uppercase bg-gray-700">
                                <tr><th class="px-4 py-3">Score Range</th><th>Grade</th><th>Description</th></tr>
                            </thead>
                            <tbody>
                                <tr class="border-b border-gray-600"><td class="px-4 py-3">95–100</td><td>Grade 1</td><td>Highest (Excellent)</td></tr>
                                <tr class="border-b border-gray-600"><td class="px-4 py-3">85–94</td><td>Grade 2</td><td>Higher (Very Good)</td></tr>
                                <tr class="border-b border-gray-600"><td class="px-4 py-3">75–84</td><td>Grade 3</td><td>High (Good)</td></tr>
                                <tr class="border-b border-gray-600"><td class="px-4 py-3">65–74</td><td>Grade 4</td><td>High Average</td></tr>
                                <tr class="border-b border-gray-600"><td class="px-4 py-3">55–64</td><td>Grade 5</td><td>Average</td></tr>
                                <tr class="border-b border-gray-600"><td class="px-4 py-3">45–54</td><td>Grade 6</td><td>Low Average</td></tr>
                                <tr class="border-b border-gray-600"><td class="px-4 py-3">36–44</td><td>Grade 7</td><td>Low</td></tr>
                                <tr class="border-b border-gray-600"><td class="px-4 py-3">31–35</td><td>Grade 8</td><td>Lower</td></tr>
                                <tr class="border-b border-gray-600"><td class="px-4 py-3">0–30</td><td>Grade 9</td><td>Lowest</td></tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </div>
    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <?php mysqli_close($conn); ob_end_flush(); ?>
    <script>
        const isEditMode = <?= json_encode($is_edit_mode) ?>;
        const selectedClassId = <?= json_encode($selected_class_id) ?>;
        function toggleStudentCard(id) {
            const card = document.querySelector(`[data-student-id="${id}"]`);
            const form = document.getElementById(`form-${id}`);
            const chevron = document.getElementById(`chevron-${id}`);
            card.classList.toggle('collapsed'); card.classList.toggle('expanded');
            form.classList.toggle('hidden');
            chevron.classList.toggle('fa-chevron-down'); chevron.classList.toggle('fa-chevron-up');
            if (!form.classList.contains('hidden')) loadStudentDraft(id);
        }
        function updateMockNumbers() {
            if (isEditMode) return;
            const num = document.getElementById('global-mock-number').value;
            document.querySelectorAll('input[name="mock_number"]').forEach(i => i.value = num);
        }
        function clearStudentForm(id) {
            document.querySelectorAll(`#form-${id} input[type="number"]`).forEach(i => i.value = '');
            localStorage.removeItem(`mock_draft_${selectedClassId}_${id}_${isEditMode?'edit':'new'}`);
        }
        function clearAllForms() {
            document.querySelectorAll('input[type="number"]').forEach(i => i.value = '');
            localStorage.clear();
        }
        document.addEventListener('input', e => {
            if (e.target.type === 'number') {
                const val = parseFloat(e.target.value) || 0;
                e.target.style.borderColor = (val < 0 || val > 100) ? '#ef4444' : 'rgba(255,255,255,0.3)';
                saveStudentDraft(e.target.closest('form').querySelector('input[name="student_id"]').value);
            }
        });
        function saveStudentDraft(id) {
            const form = document.getElementById(`form-${id}`);
            const data = new FormData(form);
            const draft = { mock_number: data.get('mock_number') };
            for (let [k, v] of data.entries()) if (k.startsWith('score_') && v > 0) draft[k] = v;
            localStorage.setItem(`mock_draft_${selectedClassId}_${id}_${isEditMode?'edit':'new'}`, JSON.stringify(draft));
        }
        function loadStudentDraft(id) {
            const key = `mock_draft_${selectedClassId}_${id}_${isEditMode?'edit':'new'}`;
            const draft = JSON.parse(localStorage.getItem(key) || '{}');
            if (draft.mock_number && !isEditMode) document.getElementById('global-mock-number').value = draft.mock_number;
            Object.keys(draft).forEach(k => {
                const inp = document.querySelector(`#form-${id} input[name="${k}"]`);
                if (inp && k.startsWith('score_')) inp.value = draft[k];
            });
        }
        window.addEventListener('load', () => {
            <?php if (!$is_edit_mode && isset($_SESSION['mock_submitted_new'])): unset($_SESSION['mock_submitted_new']); endif; ?>
        });
    </script>
</body>
</html>