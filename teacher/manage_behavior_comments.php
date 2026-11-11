<?php
// Start session only if not active
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Debug session variables
$session_debug = [
    'user_id_isset' => isset($_SESSION['user_id']),
    'user_id' => isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'not set',
    'user_role_isset' => isset($_SESSION['user_role']),
    'user_role' => isset($_SESSION['user_role']) ? $_SESSION['user_role'] : 'not set',
    'timestamp' => date('Y-m-d H:i:s')
];
error_log("Session Debug: " . json_encode($session_debug), 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');

// Include dependencies
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php';

// Check database connection
if (!$conn) {
    error_log("Database connection failed: " . mysqli_connect_error(), 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    die("<div class='container my-5'><div class='alert alert-danger'>System error. Please contact support.</div></div>");
}

// Verify user session and role
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role'])) {
    error_log("Session verification failed: user_id or user_role not set", 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    header("Location: /teacher/login_error.php?error=session_not_set");
    exit();
}

// Verify user exists and has teacher role
$stmt = $conn->prepare("SELECT id, role FROM users WHERE id = ? AND role = 'teacher' AND status = 'active'");
$stmt->bind_param("i", $_SESSION['user_id']);
if ($stmt->execute()) {
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        error_log("User verification failed: ID {$_SESSION['user_id']} not found or not a teacher", 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
        header("Location: /teacher/login_error.php?error=invalid_user");
        exit();
    }
    $stmt->close();
} else {
    error_log("User verification query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    header("Location: /teacher/login_error.php?error=database_error");
    exit();
}

// Log page access
logActivity($conn, $_SESSION['user_id'], "Accessed manage behavior comments page", "Teacher ID: " . $_SESSION['user_id']);

// Determine current academic year dynamically
$current_date = date('Y-m-d');
$stmt = $conn->prepare("SELECT year FROM academic_calendar WHERE end_date >= ? ORDER BY start_date ASC LIMIT 1");
$stmt->bind_param("s", $current_date);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $academic_year = $row['year'];
} else {
    // If no upcoming/active, get the latest year
    $stmt = $conn->prepare("SELECT year FROM academic_calendar ORDER BY start_date DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $academic_year = $row['year'];
    } else {
        $academic_year = '2025/2026'; // Fallback
        error_log("No academic year found in database", 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    }
}
$stmt->close();

// Handle form submission
$success_message = $error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_comment'])) {
    $student_id = $_POST['student_id'] ?? 0;
    $class_id = $_POST['class_id'] ?? 0;
    $term_id = $_POST['term_id'] ?? 0;
    $comment = trim($_POST['comment'] ?? '');

    // Validate inputs
    if ($student_id && $class_id && $term_id && $comment) {
        $stmt = $conn->prepare("INSERT INTO behavior_comments (class_id, user_id, term, comment, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("iissi", $class_id, $student_id, $term_id, $comment, $_SESSION['user_id']);
        if ($stmt->execute()) {
            $success_message = "Behavior comment added successfully.";
            logActivity($conn, $_SESSION['user_id'], "Added behavior comment for student ID: $student_id", "Comment: " . substr($comment, 0, 50));
        } else {
            $error_message = "Failed to add comment: " . $stmt->error;
            error_log("Add comment query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
        }
        $stmt->close();
    } else {
        $error_message = "All fields are required.";
    }
}

// Handle comment deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_comment'])) {
    $comment_id = $_POST['comment_id'] ?? 0;
    if ($comment_id) {
        $stmt = $conn->prepare("DELETE FROM behavior_comments WHERE id = ? AND created_by = ?");
        $stmt->bind_param("ii", $comment_id, $_SESSION['user_id']);
        if ($stmt->execute()) {
            $success_message = "Comment deleted successfully.";
            logActivity($conn, $_SESSION['user_id'], "Deleted behavior comment ID: $comment_id", "Teacher ID: {$_SESSION['user_id']}");
        } else {
            $error_message = "Failed to delete comment: " . $stmt->error;
            error_log("Delete comment query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
        }
        $stmt->close();
    }
}

// Fetch all classes for the academic year (join with student_classes since no academic_year in classes)
$classes = [];
$stmt = $conn->prepare("SELECT DISTINCT c.id, c.class_name 
                        FROM classes c 
                        JOIN student_classes sc ON c.id = sc.class_id 
                        WHERE sc.academic_year = ?
                        ORDER BY c.class_name");
$stmt->bind_param("s", $academic_year);
if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $classes[] = $row;
    }
    $stmt->close();
} else {
    error_log("Fetch classes query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
}

// Fetch terms for the academic year
$terms = [];
$stmt = $conn->prepare("SELECT id, term_name FROM academic_calendar WHERE year = ? ORDER BY start_date");
$stmt->bind_param("s", $academic_year);
if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $terms[] = $row;
    }
    $stmt->close();
} else {
    error_log("Fetch terms query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
}

// Fetch students for selected class
$selected_class_id = $_POST['class_id'] ?? ($classes ? $classes[0]['id'] : 0);
$students = [];
if ($selected_class_id) {
    $stmt = $conn->prepare("SELECT u.id, u.name 
                            FROM users u 
                            JOIN student_classes sc ON u.id = sc.user_id 
                            WHERE sc.class_id = ? AND sc.academic_year = ? AND u.role = 'student' AND u.status = 'active'
                            ORDER BY u.name");
    $stmt->bind_param("is", $selected_class_id, $academic_year);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }
        $stmt->close();
    } else {
        error_log("Fetch students query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
    }
}

// Pagination and search for comments
$selected_student_id = $_POST['student_id'] ?? 0;
$search_query = $_POST['search_query'] ?? '';
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 10;
$offset = ($page - 1) * $limit;
$comments = [];
$total_comments = 0;
$search_term = "%$search_query%";

if ($selected_student_id) {
    $stmt = $conn->prepare("SELECT bc.id, bc.comment, bc.created_at, ac.term_name as term, bc.is_read, c.class_name
                            FROM behavior_comments bc
                            JOIN classes c ON bc.class_id = c.id
                            JOIN academic_calendar ac ON bc.term = ac.id
                            WHERE bc.user_id = ? AND bc.created_by = ? AND bc.comment LIKE ?
                            ORDER BY bc.created_at DESC LIMIT ? OFFSET ?");
    $stmt->bind_param("iisii", $selected_student_id, $_SESSION['user_id'], $search_term, $limit, $offset);
} else {
    $stmt = $conn->prepare("SELECT bc.id, bc.comment, bc.created_at, ac.term_name as term, bc.is_read, u.name AS student_name, c.class_name
                            FROM behavior_comments bc
                            JOIN users u ON bc.user_id = u.id
                            JOIN classes c ON bc.class_id = c.id
                            JOIN academic_calendar ac ON bc.term = ac.id
                            WHERE bc.created_by = ? AND bc.comment LIKE ?
                            ORDER BY bc.created_at DESC LIMIT ? OFFSET ?");
    $stmt->bind_param("isii", $_SESSION['user_id'], $search_term, $limit, $offset);
}
if ($stmt->execute()) {
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $comments[] = $row;
    }
    $stmt->close();
} else {
    error_log("Fetch comments query failed: " . $stmt->error, 3, $_SERVER['DOCUMENT_ROOT'] . '/error.log');
}

// Count total comments for pagination
if ($selected_student_id) {
    $stmt = $conn->prepare("SELECT COUNT(*) as total 
                            FROM behavior_comments bc
                            JOIN academic_calendar ac ON bc.term = ac.id
                            WHERE bc.user_id = ? AND bc.created_by = ? AND bc.comment LIKE ?");
    $stmt->bind_param("iis", $selected_student_id, $_SESSION['user_id'], $search_term);
} else {
    $stmt = $conn->prepare("SELECT COUNT(*) as total 
                            FROM behavior_comments bc
                            JOIN academic_calendar ac ON bc.term = ac.id
                            WHERE bc.created_by = ? AND bc.comment LIKE ?");
    $stmt->bind_param("is", $_SESSION['user_id'], $search_term);
}
if ($stmt->execute()) {
    $total_comments = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
}
$total_pages = ceil($total_comments / $limit);
?>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

<style>
    body {
        background-color: #ffffff;
        color: #212529;
    }
    .card {
        background-color: #ffffff;
        border: 1px solid #dee2e6;
        border-radius: 8px;
    }
    .list-group-item {
        background-color: #f8f9fa;
    }
    .table {
        background-color: #ffffff;
    }
    .btn-primary {
        background: linear-gradient(to right, #ff4500, #ff6b00);
        border: none;
    }
    .btn-primary:hover {
        background: linear-gradient(to right, #ff6b00, #ff4500);
    }
    .btn-danger {
        background: linear-gradient(to right, #dc3545, #c82333);
        border: none;
    }
    .btn-danger:hover {
        background: linear-gradient(to right, #c82333, #dc3545);
    }
    .pagination .page-link {
        color: #ff4500;
    }
    .pagination .page-item.active .page-link {
        background-color: #ff4500;
        border-color: #ff4500;
        color: white;
    }
</style>

<div class="container-fluid my-5">
    <h1 class="display-5 fw-bold text-center mb-5 animate__animated animate__fadeIn" style="color: #ff4500;">
        <i class="fas fa-comment me-2"></i>Manage Behavior Comments
    </h1>

    <!-- Messages -->
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
            <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show animate__animated animate__fadeIn" role="alert">
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Add Behavior Comment Form -->
        <div class="card mb-4 shadow-sm animate__animated animate__fadeInUp">
            <div class="card-header" style="background: linear-gradient(to right, #ff4500, #ff6b00); color: white;">
                <h3 class="card-title mb-0 fs-5"><i class="fas fa-plus-circle me-2"></i>Add Behavior Comment</h3>
            </div>
            <div class="card-body">
                <form method="POST" action="" id="commentForm" onsubmit="return validateForm()">
                    <div class="mb-3">
                        <label for="class_id" class="form-label fs-6">Select Class</label>
                        <select name="class_id" id="class_id" class="form-select" onchange="this.form.submit()">
                            <option value="">-- Select Class --</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $selected_class_id == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['class_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($selected_class_id && $students): ?>
                        <div class="mb-3">
                            <label for="student_id" class="form-label fs-6">Select Student</label>
                            <select name="student_id" id="student_id" class="form-select">
                                <option value="">-- Select Student --</option>
                                <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>" <?php echo $selected_student_id == $student['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="term_id" class="form-label fs-6">Select Term</label>
                            <select name="term_id" id="term_id" class="form-select">
                                <option value="">-- Select Term --</option>
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?php echo $term['id']; ?>">
                                        <?php echo htmlspecialchars($term['term_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="comment" class="form-label fs-6">Comment</label>
                            <textarea name="comment" id="comment" class="form-control" rows="4" placeholder="Enter behavior comment"></textarea>
                            <small class="text-muted">Maximum 500 characters</small>
                        </div>
                        <button type="submit" name="add_comment" class="btn btn-primary">Add Comment</button>
                    <?php elseif ($selected_class_id && empty($students)): ?>
                        <p class="text-muted fs-6">No students found in this class.</p>
                    <?php else: ?>
                        <p class="text-muted fs-6">Please select a class to view students.</p>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Recent Behavior Comments -->
        <div class="card mb-4 shadow-sm animate__animated animate__fadeInUp">
            <div class="card-header" style="background: linear-gradient(to right, #ff4500, #ff6b00); color: white;">
                <h3 class="card-title mb-0 fs-5"><i class="fas fa-comment-alt me-2"></i>Recent Behavior Comments</h3>
            </div>
            <div class="card-body">
                <!-- Search Form -->
                <form method="POST" action="" class="mb-3">
                    <div class="input-group">
                        <input type="text" name="search_query" class="form-control" placeholder="Search comments..." value="<?php echo htmlspecialchars($search_query); ?>">
                        <button type="submit" class="btn btn-primary">Search</button>
                    </div>
                </form>

                <?php if ($comments): ?>
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <?php if (!$selected_student_id): ?>
                                        <th>Student</th>
                                    <?php endif; ?>
                                    <th>Class</th>
                                    <th>Term</th>
                                    <th>Comment</th>
                                    <th>Date</th>
                                    <th>Read</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($comments as $comment): ?>
                                    <tr>
                                        <?php if (!$selected_student_id): ?>
                                            <td class="fs-6"><?php echo htmlspecialchars($comment['student_name']); ?></td>
                                        <?php endif; ?>
                                        <td class="fs-6"><?php echo htmlspecialchars($comment['class_name']); ?></td>
                                        <td class="fs-6"><?php echo htmlspecialchars($comment['term']); ?></td>
                                        <td class="fs-6"><?php echo htmlspecialchars($comment['comment']); ?></td>
                                        <td class="fs-6"><?php echo date('d M Y', strtotime($comment['created_at'])); ?></td>
                                        <td class="fs-6"><?php echo $comment['is_read'] ? 'Yes' : 'No'; ?></td>
                                        <td>
                                            <form method="POST" action="" onsubmit="return confirm('Are you sure you want to delete this comment?');">
                                                <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                                                <button type="submit" name="delete_comment" class="btn btn-danger btn-sm">Delete</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav aria-label="Comments pagination">
                            <ul class="pagination justify-content-center">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>" aria-label="Previous">
                                        <span aria-hidden="true">&laquo;</span>
                                    </a>
                                </li>
                                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <li class="page-item <?php echo $page == $i ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>" aria-label="Next">
                                        <span aria-hidden="true">&raquo;</span>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-muted fs-6">No recent behavior comments.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function validateForm() {
    const classId = document.getElementById('class_id').value;
    const studentId = document.getElementById('student_id').value;
    const termId = document.getElementById('term_id').value;
    const comment = document.getElementById('comment').value.trim();

    if (!classId) {
        alert('Please select a class.');
        return false;
    }
    if (!studentId) {
        alert('Please select a student.');
        return false;
    }
    if (!termId) {
        alert('Please select a term.');
        return false;
    }
    if (!comment) {
        alert('Please enter a comment.');
        return false;
    }
    if (comment.length > 500) {
        alert('Comment cannot exceed 500 characters.');
        return false;
    }
    return true;
}
</script>

<?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>