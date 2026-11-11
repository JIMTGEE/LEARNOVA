<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
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

// Include logging function
$log_path = $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';
if (!file_exists($log_path)) {
    error_log("Log activity file missing: $log_path");
    function logActivity($conn, $user_id, $action, $description) {
        error_log("Activity: [$user_id] $action - $description");
    }
} else {
    require_once($log_path);
}

// Check database connection
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: 'Unknown error'));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Validate inputs
$teacher_id = (int)$_SESSION['user_id'];
$assignment_id = filter_input(INPUT_GET, 'assignment_id', FILTER_VALIDATE_INT);
if (!$assignment_id) {
    error_log("Invalid assignment_id: " . ($assignment_id ?? 'unset'));
    $_SESSION['error_msg'] = "Invalid assignment ID.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}

// Verify teacher ownership
$verify_sql = "SELECT id, title, max_points, is_group_assignment FROM assignments WHERE id = ? AND teacher_id = ?";
$verify_stmt = $conn->prepare($verify_sql);
if (!$verify_stmt) {
    error_log("Prepare failed for verify assignment query: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Failed to verify assignment.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}
$verify_stmt->bind_param("ii", $assignment_id, $teacher_id);
$verify_stmt->execute();
$assignment = $verify_stmt->get_result()->fetch_assoc();
$verify_stmt->close();

if (!$assignment) {
    error_log("Assignment not found or unauthorized: assignment_id=$assignment_id, teacher_id=$teacher_id");
    $_SESSION['error_msg'] = "Assignment not found or you do not have permission.";
    header("Location: /teacher/manage_assignments.php");
    exit();
}

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);
logActivity($conn, $teacher_id, "Accessed Manage Submissions Page", "Teacher accessed submissions for assignment ID $assignment_id");

// Handle grading submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->begin_transaction();
    try {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        $csrf_token = $_SESSION['csrf_token'];
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
            error_log("CSRF token validation failed: Expected " . ($csrf_token ?? 'none') . ", Received " . ($_POST['csrf_token'] ?? 'none'));
            throw new Exception("Invalid CSRF token.");
        }

        $submission_id = filter_input(INPUT_POST, 'submission_id', FILTER_VALIDATE_INT);
        $answers = json_decode($_POST['answers'] ?? '[]', true);
        $total_score = 0;
        $max_points = (float)$assignment['max_points'];

        if (!$submission_id || !is_array($answers)) {
            throw new Exception("Invalid submission ID or answers data.");
        }

        foreach ($answers as $answer) {
            $score = isset($answer['score']) ? (float)$answer['score'] : 0;
            $max_points_q = (float)($answer['max_points'] ?? 0);
            if ($score < 0 || $score > $max_points_q) {
                throw new Exception("Invalid score for question ID {$answer['question_id']}.");
            }
            $total_score += $score;
        }

        if ($total_score > $max_points) {
            throw new Exception("Total score ($total_score) exceeds maximum points ($max_points).");
        }

        $feedback = trim(filter_input(INPUT_POST, 'feedback', FILTER_UNSAFE_RAW) ?? '');
        $answers_json = json_encode($answers);

        $update_sql = "UPDATE assignment_submissions SET score = ?, feedback = ?, answers = ?, graded_by = ?, graded_at = NOW(), status = 'graded', is_graded = 1, updated_at = NOW() WHERE id = ? AND assignment_id = ?";
        $update_stmt = $conn->prepare($update_sql);
        if (!$update_stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        $update_stmt->bind_param("dssiii", $total_score, $feedback, $answers_json, $teacher_id, $submission_id, $assignment_id);
        $update_stmt->execute();
        $update_stmt->close();

        logActivity($conn, $teacher_id, "Graded Submission", "Graded submission ID $submission_id for assignment ID $assignment_id with score $total_score");
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $conn->commit();
        $_SESSION['success_msg'] = "Submission graded successfully.";
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Grading error: " . $e->getMessage());
        $_SESSION['error_msg'] = $e->getMessage();
    }
    header("Location: /teacher/manage_submissions.php?assignment_id=$assignment_id");
    exit();
}

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Manage assignment submissions for QueensPa School teachers.">
    <meta name="keywords" content="QueensPa School, manage submissions, grade assignments, teacher dashboard">
    <title>Manage Submissions - QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" integrity="sha512-Fo3rlrZj/k7ujTnHg4CGR2D7kSs0v4LLanw2qksYuRlEzO+tcaEPQogQ0KaoGN26/zrn20ImR1DfuLWnOo7aBA==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Poppins', 'ui-sans-serif', 'system-ui'] },
                    colors: { primary: { 500: '#0288d1' }, accent: { 500: '#4caf50' } },
                    animation: { 'fade-in': 'fadeIn 0.5s ease-out' },
                    keyframes: { fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } } }
                }
            }
        };
    </script>
    <style>
        .form-control:focus { box-shadow: 0 0 0 3px rgba(2, 136, 209, 0.1); border-color: #0288d1; }
        .is-invalid { border-color: #dc3545 !important; }
        .invalid-feedback { display: none; color: #dc3545; font-size: 0.875rem; }
        .is-invalid ~ .invalid-feedback { display: block; }
    </style>
</head>
<body class="min-h-screen bg-gray-50 font-sans">
    <div id="loading-overlay" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-primary-500 text-5xl"></i>
    </div>

    <?php
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
    if (file_exists($header_path)) {
        include $header_path;
    } else {
        error_log("Teacher header file missing: $header_path");
        echo '<header class="bg-primary-500 text-white p-4"><h1>QueensPa School</h1></header>';
    }
    ?>

    <main class="container mx-auto px-4 py-12 max-w-7xl">
        <div class="flex justify-between items-center mb-10">
            <h1 class="text-3xl font-bold text-gray-800 flex items-center">
                <i class="fas fa-clipboard-list text-primary-500 mr-3"></i> Manage Submissions
            </h1>
            <a href="/teacher/manage_assignments.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors flex items-center">
                <i class="fas fa-arrow-left mr-2"></i> Back to Assignments
            </a>
        </div>

        <?php if (isset($_SESSION['error_msg'])): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center" role="alert">
                <i class="fas fa-exclamation-circle mr-2"></i> <?= htmlspecialchars($_SESSION['error_msg']) ?>
                <?php unset($_SESSION['error_msg']); ?>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['success_msg'])): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center" role="alert">
                <i class="fas fa-check-circle mr-2"></i> <?= htmlspecialchars($_SESSION['success_msg']) ?>
                <?php unset($_SESSION['success_msg']); ?>
            </div>
        <?php endif; ?>

        <section id="submissions-list" class="bg-white rounded-xl shadow-md p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Submissions</h2>
            <div id="submissions-table" class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Submitter</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Date</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Status</th>
                            <th class="px-4 py-3 text-left text-sm font-medium text-gray-700">Score</th>
                            <th class="px-4 py-3 text-right text-sm font-medium text-gray-700">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200"></tbody>
                </table>
            </div>
        </section>

        <div id="submission-modal" class="fixed inset-0 bg-gray-900 bg-opacity-60 z-50 hidden flex items-center justify-center">
            <div class="bg-white rounded-xl shadow-2xl max-w-3xl w-full mx-4 p-6 overflow-y-auto max-h-[90vh]">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-xl font-semibold text-gray-800">Submission Details</h2>
                    <button id="close-modal" class="text-gray-500 hover:text-gray-700" aria-label="Close modal">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div id="submission-details" class="space-y-4"></div>
                <form id="grading-form" action="/teacher/manage_submissions.php?assignment_id=<?= $assignment_id ?>" method="POST" class="mt-6 space-y-4">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="submission_id" id="submission-id">
                    <input type="hidden" name="answers" id="answers-json">
                    <div id="answers-grading" class="space-y-4"></div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700">Total Score: <span id="total-score">0</span> / <span id="max-points"><?= htmlspecialchars($assignment['max_points']) ?></span></label>
                        <p id="score-error" class="invalid-feedback"></p>
                    </div>
                    <div>
                        <label for="feedback" class="block text-sm font-medium text-gray-700">Feedback</label>
                        <textarea id="feedback" name="feedback" class="form-control w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-primary-500 focus:border-primary-500" rows="4"></textarea>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" id="submit-grade" class="bg-accent-500 hover:bg-accent-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors">
                            <i class="fas fa-save mr-2"></i> Submit Grade
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <?php
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php';
    if (file_exists($footer_path)) {
        include $footer_path;
    } else {
        error_log("Teacher footer file missing: $footer_path");
        echo '<footer class="bg-gray-800 text-white p-4 text-center">© 2025 QueensPa School</footer>';
    }
    mysqli_close($conn);
    ob_end_flush();
    ?>

    <script>
        $(document).ready(function() {
            const $loadingOverlay = $('#loading-overlay');
            const $submissionsTable = $('#submissions-table tbody');
            const $submissionModal = $('#submission-modal');
            const $submissionDetails = $('#submission-details');
            const $closeModalBtn = $('#close-modal');
            const $gradingForm = $('#grading-form');
            const $answersGrading = $('#answers-grading');
            const $totalScore = $('#total-score');
            const $scoreError = $('#score-error');
            const $submissionIdInput = $('#submission-id');
            const $answersJsonInput = $('#answers-json');
            const $feedbackInput = $('#feedback');
            const $maxPoints = $('#max-points');
            const assignmentId = '<?php echo $assignment_id; ?>';
            let maxPoints = parseFloat($maxPoints.text());
            let currentAnswers = [];

            $loadingOverlay.removeClass('hidden');

            function fetchSubmissions() {
                $.ajax({
                    url: `/teacher/get_submissions.php?assignment_id=${assignmentId}`,
                    method: 'GET',
                    dataType: 'json',
                    success: function(response) {
                        if (response && response.submissions) {
                            renderSubmissions(response);
                        } else {
                            $submissionsTable.append('<tr><td colspan="5" class="px-4 py-3 text-center text-gray-500">No submissions found.</td></tr>');
                        }
                    },
                    error: function(xhr) {
                        console.error('AJAX error:', xhr.responseText);
                        $submissionsTable.append('<tr><td colspan="5" class="px-4 py-3 text-center text-red-500">Failed to load submissions.</td></tr>');
                    },
                    complete: function() {
                        $loadingOverlay.addClass('hidden');
                    }
                });
            }

            function renderSubmissions(data) {
                $submissionsTable.empty();
                const { submissions, assignment } = data;
                maxPoints = parseFloat(assignment.max_points);

                if (submissions.length === 0) {
                    $submissionsTable.append('<tr><td colspan="5" class="px-4 py-3 text-center text-gray-500">No submissions available.</td></tr>');
                    return;
                }

                submissions.forEach(sub => {
                    const scoreDisplay = sub.score !== null ? `${sub.score.toFixed(2)} / ${assignment.max_points}` : 'Not graded';
                    const statusClass = sub.status === 'late' ? 'text-red-500' : 'text-green-500';
                    const row = `
                        <tr>
                            <td class="px-4 py-3 text-sm text-gray-800">${sub.submitter_name}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">${new Date(sub.submission_date).toLocaleDateString()}</td>
                            <td class="px-4 py-3 text-sm ${statusClass}">${sub.status.charAt(0).toUpperCase() + sub.status.slice(1)}</td>
                            <td class="px-4 py-3 text-sm text-gray-600">${scoreDisplay}</td>
                            <td class="px-4 py-3 text-right text-sm">
                                <button class="view-submission-btn bg-primary-500 hover:bg-primary-600 text-white px-3 py-1 rounded-lg text-sm transition-colors" data-submission='${JSON.stringify(sub)}' aria-label="View submission">
                                    <i class="fas fa-eye mr-1"></i> View & Grade
                                </button>
                            </td>
                        </tr>
                    `;
                    $submissionsTable.append(row);
                });

                $('.view-submission-btn').on('click', function() {
                    const sub = JSON.parse($(this).data('submission'));
                    showSubmissionDetails(sub);
                });
            }

            function showSubmissionDetails(sub) {
                $submissionDetails.empty();
                $answersGrading.empty();
                $submissionIdInput.val(sub.id);
                $feedbackInput.val(sub.feedback || '');
                currentAnswers = sub.answers_data.map(a => ({
                    question_id: a.question_id,
                    question_text: a.question_text,
                    answer: a.answer,
                    max_points: a.max_points,
                    score: a.score !== null ? a.score : ''
                }));
                let total = calculateTotalScore();

                $submissionDetails.append(`
                    <div>
                        <p class="text-sm font-medium text-gray-700">Submitter</p>
                        <p class="text-gray-600">${sub.submitter_name}</p>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-700">Submission Date</p>
                        <p class="text-gray-600">${new Date(sub.submission_date).toLocaleDateString()}</p>
                    </div>
                `);

                if (sub.file_paths_array.length > 0) {
                    let filesHtml = '<p class="text-sm font-medium text-gray-700">Files</p><ul class="list-disc pl-5">';
                    sub.file_paths_array.forEach(file => {
                        filesHtml += `<li><a href="${file}" class="text-blue-500 hover:text-blue-600" target="_blank">Download File</a></li>`;
                    });
                    filesHtml += '</ul>';
                    $submissionDetails.append(filesHtml);
                }

                currentAnswers.forEach((ans, idx) => {
                    $answersGrading.append(`
                        <div>
                            <label class="block text-sm font-medium text-gray-700">Question ${idx + 1}: ${ans.question_text}</label>
                            <p class="text-gray-600 mb-2">${ans.answer}</p>
                            <input type="number" class="score-input form-control w-full px-3 py-2 border border-gray-300 rounded-lg" data-idx="${idx}" value="${ans.score}" min="0" max="${ans.max_points}" step="0.01" placeholder="Score (Max: ${ans.max_points})">
                            <p class="invalid-feedback">Score must be between 0 and ${ans.max_points}.</p>
                        </div>
                    `);
                });

                $totalScore.text(total.toFixed(2));
                validateTotalScore(total);
                $submissionModal.removeClass('hidden');

                $('.score-input').on('input', function() {
                    const idx = $(this).data('idx');
                    const score = parseFloat($(this).val()) || 0;
                    const max = parseFloat($(this).attr('max'));
                    if (score < 0 || score > max) {
                        $(this).addClass('is-invalid');
                    } else {
                        $(this).removeClass('is-invalid');
                        currentAnswers[idx].score = score;
                        const total = calculateTotalScore();
                        $totalScore.text(total.toFixed(2));
                        validateTotalScore(total);
                    }
                });
            }

            function calculateTotalScore() {
                return currentAnswers.reduce((sum, ans) => sum + (parseFloat(ans.score) || 0), 0);
            }

            function validateTotalScore(total) {
                if (total > maxPoints) {
                    $scoreError.text(`Total score (${total.toFixed(2)}) exceeds maximum points (${maxPoints}).`);
                    $scoreError.addClass('invalid-feedback');
                    return false;
                } else {
                    $scoreError.text('');
                    $scoreError.removeClass('invalid-feedback');
                    return true;
                }
            }

            $gradingForm.on('submit', function(e) {
                const total = calculateTotalScore();
                if (!validateTotalScore(total)) {
                    e.preventDefault();
                    return false;
                }
                $answersJsonInput.val(JSON.stringify(currentAnswers));
                $loadingOverlay.removeClass('hidden');
            });

            $closeModalBtn.on('click', function() {
                $submissionModal.addClass('hidden');
            });

            fetchSubmissions();
        });
    </script>
</body>
</html>