<?php
// Enable error reporting for debugging (remove in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session
session_start();

// Restrict to teachers only
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'teacher') {
    $_SESSION['error_msg'] = "Access denied. You must be a teacher to access this page.";
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
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

$teacher_id = $_SESSION['user_id'];

// Log access
logActivity($conn, $teacher_id, 'Accessed Manage Behavior Comments', 'Teacher accessed behavior comments page');

// Generate CSRF token
$csrf_token = bin2hex(random_bytes(32));
$stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
if (!$stmt) {
    error_log("Prepare failed for CSRF token: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to generate CSRF token.";
    header("Location: /login.php");
    exit();
}
$stmt->bind_param("is", $teacher_id, $csrf_token);
$stmt->execute();
$stmt->close();

// Fetch teacher details
$stmt = $conn->prepare("SELECT name, profile_picture FROM users WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed for teacher details: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch teacher details.";
    header("Location: /login.php");
    exit();
}
$stmt->bind_param("i", $teacher_id);
$stmt->execute();
$teacher = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch academic terms
$terms_query = "SELECT id, term_name, year FROM academic_calendar ORDER BY start_date DESC";
$terms_result = $conn->query($terms_query);
if (!$terms_result) {
    error_log("Query failed for terms: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch terms.";
    header("Location: /login.php");
    exit();
}

// Fetch all classes
$classes_query = "SELECT id, class_name, subject_id FROM classes ORDER BY class_name";
$classes_result = $conn->query($classes_query);
if (!$classes_result) {
    error_log("Query failed for classes: " . $conn->error);
    $_SESSION['error_msg'] = "System error: Unable to fetch classes.";
    header("Location: /login.php");
    exit();
}

// Fetch students for the selected class
$students_result = null;
$selected_class_id = isset($_POST['class_id']) ? intval($_POST['class_id']) : 0;
$selected_term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : ($terms_result->num_rows > 0 ? $terms_result->fetch_assoc()['id'] : 0);
$selected_student_id = isset($_POST['student_id']) ? intval($_POST['student_id']) : 0;
if ($selected_class_id) {
    $students_query = "
        SELECT DISTINCT u.id, u.name, u.email 
        FROM users u
        INNER JOIN student_classes sc ON u.id = sc.user_id
        INNER JOIN classes c ON sc.class_id = c.id
        WHERE u.role = 'student' AND c.id = ?
        ORDER BY u.name
    ";
    $stmt = $conn->prepare($students_query);
    if (!$stmt) {
        error_log("Prepare failed for students: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to fetch students.";
        header("Location: /login.php");
        exit();
    }
    $stmt->bind_param("i", $selected_class_id);
    $stmt->execute();
    $students_result = $stmt->get_result();
    $stmt->close();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $posted_csrf = $_POST['csrf_token'] ?? '';
    $stmt = $conn->prepare("SELECT id FROM csrf_tokens WHERE user_id = ? AND token = ? AND expires_at > NOW()");
    if (!$stmt) {
        error_log("Prepare failed for CSRF validation: " . $conn->error);
        $_SESSION['error_msg'] = "System error: CSRF validation failed.";
    } else {
        $stmt->bind_param("is", $teacher_id, $posted_csrf);
        $stmt->execute();
        $csrf_valid = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$csrf_valid) {
            $_SESSION['error_msg'] = "Invalid CSRF token.";
        } else {
            if ($_POST['action'] === 'add_comment') {
                $class_id = intval($_POST['class_id'] ?? 0);
                $student_id = intval($_POST['student_id'] ?? 0);
                $term_id = intval($_POST['term_id'] ?? 0);
                $comment = trim($_POST['comment'] ?? '');

                if (!$class_id || !$student_id || !$term_id || !$comment) {
                    $_SESSION['error_msg'] = "All fields are required.";
                } elseif (strlen($comment) > 65535) {
                    $_SESSION['error_msg'] = "Comment is too long (max 65535 characters).";
                } else {
                    // Validate class
                    $stmt = $conn->prepare("SELECT id FROM classes WHERE id = ?");
                    if (!$stmt) {
                        error_log("Prepare failed for class validation: " . $conn->error);
                        $_SESSION['error_msg'] = "System error: Unable to validate class.";
                    } else {
                        $stmt->bind_param("i", $class_id);
                        $stmt->execute();
                        $class_exists = $stmt->get_result()->num_rows > 0;
                        $stmt->close();

                        // Validate student
                        $stmt = $conn->prepare("SELECT user_id FROM student_classes WHERE user_id = ? AND class_id = ?");
                        if (!$stmt) {
                            error_log("Prepare failed for student validation: " . $conn->error);
                            $_SESSION['error_msg'] = "System error: Unable to validate student.";
                        } else {
                            $stmt->bind_param("ii", $student_id, $class_id);
                            $stmt->execute();
                            $student_exists = $stmt->get_result()->num_rows > 0;
                            $stmt->close();

                            // Validate term
                            $stmt = $conn->prepare("SELECT id FROM academic_calendar WHERE id = ?");
                            if (!$stmt) {
                                error_log("Prepare failed for term validation: " . $conn->error);
                                $_SESSION['error_msg'] = "System error: Unable to validate term.";
                            } else {
                                $stmt->bind_param("i", $term_id);
                                $stmt->execute();
                                $term_exists = $stmt->get_result()->num_rows > 0;
                                $stmt->close();

                                if (!$class_exists) {
                                    $_SESSION['error_msg'] = "Invalid class selected.";
                                } elseif (!$student_exists) {
                                    $_SESSION['error_msg'] = "Selected student is not enrolled in the chosen class.";
                                } elseif (!$term_exists) {
                                    $_SESSION['error_msg'] = "Invalid term selected.";
                                } else {
                                    $stmt = $conn->prepare("
                                        INSERT INTO behavior_comments (class_id, user_id, term_id, comment, created_at) 
                                        VALUES (?, ?, ?, ?, NOW())
                                    ");
                                    if (!$stmt) {
                                        error_log("Prepare failed for insert comment: " . $conn->error);
                                        $_SESSION['error_msg'] = "System error: Unable to add comment.";
                                    } else {
                                        $stmt->bind_param("iiss", $class_id, $student_id, $term_id, $comment);
                                        if ($stmt->execute()) {
                                            $_SESSION['success_msg'] = "Comment added successfully.";
                                            logActivity($conn, $teacher_id, 'Added Behavior Comment', "Comment added for student ID $student_id in class ID $class_id");
                                            unset($_SESSION['behavior_comments_' . $teacher_id]);
                                        } else {
                                            $_SESSION['error_msg'] = "Failed to add comment: " . $conn->error;
                                            logActivity($conn, $teacher_id, 'Database Error', "Failed to add comment: " . $conn->error);
                                        }
                                        $stmt->close();
                                    }
                                }
                            }
                        }
                    }
                }
            } elseif ($_POST['action'] === 'update_comment') {
                $comment_id = intval($_POST['comment_id'] ?? 0);
                $term_id = intval($_POST['term_id'] ?? 0);
                $comment = trim($_POST['comment'] ?? '');

                if (!$comment_id || !$term_id || !$comment) {
                    $_SESSION['error_msg'] = "All fields are required.";
                } elseif (strlen($comment) > 65535) {
                    $_SESSION['error_msg'] = "Comment is too long (max 65535 characters).";
                } else {
                    $stmt = $conn->prepare("SELECT id FROM behavior_comments WHERE id = ?");
                    if (!$stmt) {
                        error_log("Prepare failed for comment validation: " . $conn->error);
                        $_SESSION['error_msg'] = "System error: Unable to validate comment.";
                    } else {
                        $stmt->bind_param("i", $comment_id);
                        $stmt->execute();
                        $comment_exists = $stmt->get_result()->num_rows > 0;
                        $stmt->close();

                        $stmt = $conn->prepare("SELECT id FROM academic_calendar WHERE id = ?");
                        if (!$stmt) {
                            error_log("Prepare failed for term validation: " . $conn->error);
                            $_SESSION['error_msg'] = "System error: Unable to validate term.";
                        } else {
                            $stmt->bind_param("i", $term_id);
                            $stmt->execute();
                            $term_exists = $stmt->get_result()->num_rows > 0;
                            $stmt->close();

                            if (!$comment_exists) {
                                $_SESSION['error_msg'] = "Invalid comment.";
                            } elseif (!$term_exists) {
                                $_SESSION['error_msg'] = "Invalid term selected.";
                            } else {
                                $stmt = $conn->prepare("UPDATE behavior_comments SET term_id = ?, comment = ?, updated_at = NOW() WHERE id = ?");
                                if (!$stmt) {
                                    error_log("Prepare failed for update comment: " . $conn->error);
                                    $_SESSION['error_msg'] = "System error: Unable to update comment.";
                                } else {
                                    $stmt->bind_param("isi", $term_id, $comment, $comment_id);
                                    if ($stmt->execute()) {
                                        $_SESSION['success_msg'] = "Comment updated successfully.";
                                        logActivity($conn, $teacher_id, 'Updated Behavior Comment', "Comment ID $comment_id updated");
                                        unset($_SESSION['behavior_comments_' . $teacher_id]);
                                    } else {
                                        $_SESSION['error_msg'] = "Failed to update comment: " . $conn->error;
                                        logActivity($conn, $teacher_id, 'Database Error', "Failed to update comment: " . $conn->error);
                                    }
                                    $stmt->close();
                                }
                            }
                        }
                    }
                }
            } elseif ($_POST['action'] === 'delete_comment') {
                $comment_id = intval($_POST['comment_id'] ?? 0);
                $stmt = $conn->prepare("SELECT id FROM behavior_comments WHERE id = ?");
                if (!$stmt) {
                    error_log("Prepare failed for comment deletion validation: " . $conn->error);
                    $_SESSION['error_msg'] = "System error: Unable to validate comment.";
                } else {
                    $stmt->bind_param("i", $comment_id);
                    $stmt->execute();
                    $comment_exists = $stmt->get_result()->num_rows > 0;
                    $stmt->close();

                    if (!$comment_exists) {
                        $_SESSION['error_msg'] = "Invalid comment.";
                    } else {
                        $stmt = $conn->prepare("DELETE FROM behavior_comments WHERE id = ?");
                        if (!$stmt) {
                            error_log("Prepare failed for delete comment: " . $conn->error);
                            $_SESSION['error_msg'] = "System error: Unable to delete comment.";
                        } else {
                            $stmt->bind_param("i", $comment_id);
                            if ($stmt->execute()) {
                                $_SESSION['success_msg'] = "Comment deleted successfully.";
                                logActivity($conn, $teacher_id, 'Deleted Behavior Comment', "Comment ID $comment_id deleted");
                                unset($_SESSION['behavior_comments_' . $teacher_id]);
                            } else {
                                $_SESSION['error_msg'] = "Failed to delete comment: " . $conn->error;
                                logActivity($conn, $teacher_id, 'Database Error', "Failed to delete comment: " . $conn->error);
                            }
                            $stmt->close();
                        }
                    }
                }
            }
        }
    }
}

// Cache comments (5-minute cache)
$cache_key = 'behavior_comments_' . $teacher_id;
$cache_duration = 300; // 5 minutes
$comments = [];
if (isset($_SESSION[$cache_key]) && (time() - $_SESSION[$cache_key]['timestamp']) < $cache_duration) {
    $comments = $_SESSION[$cache_key]['data'];
} else {
    $stmt = $conn->prepare("
        SELECT bc.id, bc.class_id, c.class_name, bc.user_id, u.name AS student_name, bc.term_id, ac.term_name, bc.comment, bc.created_at
        FROM behavior_comments bc
        JOIN classes c ON bc.class_id = c.id
        JOIN users u ON bc.user_id = u.id
        JOIN academic_calendar ac ON bc.term_id = ac.id
        ORDER BY bc.created_at DESC
    ");
    if (!$stmt) {
        error_log("Prepare failed for comments: " . $conn->error);
        $_SESSION['error_msg'] = "System error: Unable to fetch comments.";
        header("Location: /login.php");
        exit();
    }
    $stmt->execute();
    $comments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $_SESSION[$cache_key] = ['data' => $comments, 'timestamp' => time()];
}

// Pagination
$per_page = 5;
$total_comments = count($comments);
$total_pages = ceil($total_comments / $per_page);
$page = isset($_GET['page']) ? max(1, min($total_pages, (int)$_GET['page'])) : 1;
$offset = ($page - 1) * $per_page;
$paginated_comments = array_slice($comments, $offset, $per_page);

try {
    include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php';
} catch (Exception $e) {
    logActivity($conn, $teacher_id, 'Template Error', 'Failed to load teacher_header.php: ' . $e->getMessage());
    $_SESSION['error_msg'] = "System error: Unable to load template.";
    header("Location: /login.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Behavior Comments - QPMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">
    <!-- Header -->
    <header class="bg-gradient-to-r from-orange-500/80 to-orange-700/80 text-white p-4 shadow-md backdrop-blur-sm">
        <div class="container mx-auto flex justify-between items-center">
            <h1 class="text-2xl font-bold">Manage Behavior Comments</h1>
            <nav>
                <a href="dashboard.php" class="hover:underline">Back to Dashboard</a>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="container mx-auto p-6 flex-grow">
        <div class="bg-white rounded-lg shadow-lg p-6 max-w-4xl mx-auto">
            <h2 class="text-xl font-semibold mb-4 text-gray-800">Add Behavior Comment</h2>
            <?php if (isset($_SESSION['error_msg'])): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4" role="alert">
                    <?php echo htmlspecialchars($_SESSION['error_msg']); unset($_SESSION['error_msg']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success_msg'])): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4" role="alert">
                    <?php echo htmlspecialchars($_SESSION['success_msg']); unset($_SESSION['success_msg']); ?>
                </div>
            <?php endif; ?>
            <form id="commentForm" method="POST" action="" class="mb-6">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="action" value="add_comment">
                <div class="relative mb-4">
                    <select name="term_id" id="termSelect" 
                            class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:outline-none appearance-none text-gray-800"
                            onchange="this.form.submit()" required>
                        <option value="">-- Select a Term --</option>
                        <?php
                        if ($terms_result->num_rows > 0) {
                            $terms_result->data_seek(0);
                            while ($row = $terms_result->fetch_assoc()) {
                                $selected = ($row['id'] == $selected_term_id) ? 'selected' : '';
                                echo "<option value='{$row['id']}' $selected>" . htmlspecialchars($row['term_name'] . ' (' . $row['year'] . ')') . "</option>";
                            }
                        } else {
                            echo "<option value=''>No terms found</option>";
                        }
                        ?>
                    </select>
                    <i class="fas fa-chevron-down absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
                </div>
                <div class="relative mb-4">
                    <select name="class_id" id="classSelect" 
                            class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:outline-none appearance-none text-gray-800"
                            onchange="this.form.submit()" required>
                        <option value="">-- Select a Class --</option>
                        <?php
                        if ($classes_result->num_rows > 0) {
                            while ($row = $classes_result->fetch_assoc()) {
                                $selected = ($row['id'] == $selected_class_id) ? 'selected' : '';
                                echo "<option value='{$row['id']}' $selected>" . htmlspecialchars($row['class_name']) . "</option>";
                            }
                        } else {
                            echo "<option value=''>No classes found</option>";
                        }
                        ?>
                    </select>
                    <i class="fas fa-chevron-down absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
                </div>
                <?php if ($selected_class_id): ?>
                    <div class="relative mb-4">
                        <select name="student_id" id="studentSelect" 
                                class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:outline-none appearance-none text-gray-800"
                                required>
                            <option value="">-- Select a Student --</option>
                            <?php
                            if ($students_result && $students_result->num_rows > 0) {
                                while ($row = $students_result->fetch_assoc()) {
                                    $selected = ($row['id'] == $selected_student_id) ? 'selected' : '';
                                    $display = $row['name'] ?: $row['email'];
                                    echo "<option value='{$row['id']}' $selected>" . htmlspecialchars($display) . "</option>";
                                }
                            } else {
                                echo "<option value=''>No students found</option>";
                            }
                            ?>
                        </select>
                        <i class="fas fa-chevron-down absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
                    </div>
                    <div class="mb-4">
                        <label for="comment" class="block text-sm font-medium text-gray-700">Comment</label>
                        <textarea name="comment" id="comment" rows="4" 
                                  class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:outline-none text-gray-800"
                                  required maxlength="65535"><?php echo isset($_POST['comment']) ? htmlspecialchars($_POST['comment']) : ''; ?></textarea>
                        <p class="text-sm text-gray-500 mt-1">Max 65535 characters</p>
                    </div>
                    <button type="submit" id="submitBtn"
                            class="w-full bg-gradient-to-r from-orange-500/80 to-orange-700/80 text-white p-3 rounded-lg hover:from-orange-600/80 hover:to-orange-800/80 transition duration-200 disabled:bg-gray-400 disabled:cursor-not-allowed">
                        Submit Comment
                    </button>
                <?php endif; ?>
            </form>

            <!-- Comments Table -->
            <h2 class="text-xl font-semibold mb-4 text-gray-800">Existing Comments</h2>
            <div class="relative mb-4">
                <input type="text" id="searchInput" 
                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:outline-none" 
                       placeholder="Search by student or comment..." aria-label="Search comments">
                <i class="fas fa-search absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
            </div>
            <div class="relative mb-4">
                <select id="termFilter" 
                        class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:outline-none text-gray-800" 
                        aria-label="Filter by term">
                    <option value="">-- All Terms --</option>
                    <?php
                    $terms_result->data_seek(0);
                    while ($row = $terms_result->fetch_assoc()) {
                        echo "<option value='{$row['id']}'>" . htmlspecialchars($row['term_name'] . ' (' . $row['year'] . ')') . "</option>";
                    }
                    ?>
                </select>
                <i class="fas fa-chevron-down absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-500"></i>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full border-collapse border border-gray-300">
                    <thead>
                        <tr class="bg-gray-200">
                            <th class="p-3 text-left">Class</th>
                            <th class="p-3 text-left">Student</th>
                            <th class="p-3 text-left">Term</th>
                            <th class="p-3 text-left">Comment</th>
                            <th class="p-3 text-left">Date</th>
                            <th class="p-3 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="commentsTable">
                        <?php if (empty($paginated_comments)): ?>
                            <tr><td colspan="6" class="p-3 text-center text-gray-600">No comments found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($paginated_comments as $comment): ?>
                                <tr data-term-id="<?php echo $comment['term_id']; ?>">
                                    <td class="p-3 border border-gray-300"><?php echo htmlspecialchars($comment['class_name']); ?></td>
                                    <td class="p-3 border border-gray-300"><?php echo htmlspecialchars($comment['student_name']); ?></td>
                                    <td class="p-3 border border-gray-300"><?php echo htmlspecialchars($comment['term_name']); ?></td>
                                    <td class="p-3 border border-gray-300"><?php echo htmlspecialchars(substr($comment['comment'], 0, 50)) . (strlen($comment['comment']) > 50 ? '...' : ''); ?></td>
                                    <td class="p-3 border border-gray-300"><?php echo date('Y-m-d', strtotime($comment['created_at'])); ?></td>
                                    <td class="p-3 border border-gray-300">
                                        <button class="bg-blue-500 text-white px-3 py-1 rounded hover:bg-blue-600 transition duration-200 edit-comment"
                                                data-id="<?php echo $comment['id']; ?>"
                                                data-class-id="<?php echo $comment['class_id']; ?>"
                                                data-student-id="<?php echo $comment['user_id']; ?>"
                                                data-term-id="<?php echo $comment['term_id']; ?>"
                                                data-comment="<?php echo htmlspecialchars($comment['comment']); ?>"
                                                data-student-name="<?php echo htmlspecialchars($comment['student_name']); ?>"
                                                data-class-name="<?php echo htmlspecialchars($comment['class_name']); ?>">
                                            <i class="fas fa-edit"></i> Edit
                                        </button>
                                        <form action="" method="POST" class="inline">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="delete_comment">
                                            <input type="hidden" name="comment_id" value="<?php echo $comment['id']; ?>">
                                            <button type="submit" class="bg-red-500 text-white px-3 py-1 rounded hover:bg-red-600 transition duration-200"
                                                    onclick="return confirm('Are you sure you want to delete this comment?');">
                                                <i class="fas fa-trash"></i> Delete
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Pagination -->
            <div class="flex justify-center mt-4">
                <nav aria-label="Pagination">
                    <ul class="inline-flex space-x-2">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li>
                                <a href="?page=<?php echo $i; ?>" 
                                   class="px-3 py-1 rounded <?php echo $page == $i ? 'bg-orange-500 text-white' : 'bg-gray-200 hover:bg-gray-300'; ?> transition duration-200">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            </div>
        </div>

        <!-- Edit Comment Modal -->
        <div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden">
            <div class="bg-white rounded-lg p-6 max-w-lg w-full">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-semibold text-gray-800">Edit Behavior Comment</h3>
                    <button id="closeModal" class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="editCommentForm" method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="update_comment">
                    <input type="hidden" name="comment_id" id="edit_comment_id">
                    <div class="mb-4">
                        <label for="edit_class_id" class="block text-sm font-medium text-gray-700">Class</label>
                        <input type="text" id="edit_class_name" class="w-full p-3 border border-gray-300 rounded-lg bg-gray-100 text-gray-800" readonly>
                        <input type="hidden" name="class_id" id="edit_class_id">
                    </div>
                    <div class="mb-4">
                        <label for="edit_student_id" class="block text-sm font-medium text-gray-700">Student</label>
                        <input type="text" id="edit_student_name" class="w-full p-3 border border-gray-300 rounded-lg bg-gray-100 text-gray-800" readonly>
                        <input type="hidden" name="student_id" id="edit_student_id">
                    </div>
                    <div class="relative mb-4">
                        <label for="edit_term_id" class="block text-sm font-medium text-gray-700">Term</label>
                        <select name="term_id" id="edit_term_id" 
                                class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:outline-none appearance-none text-gray-800"
                                required>
                            <option value="">-- Select a Term --</option>
                            <?php
                            $terms_result->data_seek(0);
                            while ($row = $terms_result->fetch_assoc()) {
                                echo "<option value='{$row['id']}'>" . htmlspecialchars($row['term_name'] . ' (' . $row['year'] . ')') . "</option>";
                            }
                            ?>
                        </select>
                        <i class="fas fa-chevron-down absolute right-3 top-3/4 transform -translate-y-1/2 text-gray-500"></i>
                    </div>
                    <div class="mb-4">
                        <label for="edit_comment" class="block text-sm font-medium text-gray-700">Comment</label>
                        <textarea name="comment" id="edit_comment" rows="4" 
                                  class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-500 focus:outline-none text-gray-800"
                                  required maxlength="65535"></textarea>
                        <p class="text-sm text-gray-500 mt-1">Max 65535 characters</p>
                    </div>
                    <div class="flex justify-end space-x-2">
                        <button type="button" id="cancelEdit" class="bg-gray-300 text-gray-800 px-4 py-2 rounded hover:bg-gray-400 transition duration-200">
                            Cancel
                        </button>
                        <button type="submit" id="editSubmitBtn" 
                                class="bg-gradient-to-r from-orange-500/80 to-orange-700/80 text-white px-4 py-2 rounded hover:from-orange-600/80 hover:to-orange-800/80 transition duration-200 disabled:bg-gray-400 disabled:cursor-not-allowed">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>

    <!-- Footer -->
    <footer class="bg-gray-800 text-white p-4 mt-auto">
        <div class="container mx-auto text-center">
            <p>© <?php echo date('Y'); ?> QPMS. All rights reserved.</p>
        </div>
    </footer>

    <script>
        // Enable/disable submit button
        const commentForm = document.getElementById('commentForm');
        const studentSelect = document.getElementById('studentSelect');
        const submitBtn = document.getElementById('submitBtn');
        if (studentSelect && submitBtn) {
            function updateSubmitButton() {
                const termId = document.getElementById('termSelect').value;
                const classId = document.getElementById('classSelect').value;
                const studentId = studentSelect.value;
                const comment = document.getElementById('comment')?.value.trim() || '';
                submitBtn.disabled = !(termId && classId && studentId && comment && comment.length <= 65535);
                if (studentId) {
                    studentSelect.classList.add('text-gray-800');
                    studentSelect.classList.remove('text-gray-400');
                } else {
                    studentSelect.classList.add('text-gray-400');
                    studentSelect.classList.remove('text-gray-800');
                }
            }
            studentSelect.addEventListener('change', updateSubmitButton);
            commentForm.addEventListener('input', updateSubmitButton);
            updateSubmitButton();
        }

        // Search and filter comments
        function filterComments() {
            const search = document.getElementById('searchInput').value.toLowerCase();
            const termId = document.getElementById('termFilter').value;
            const rows = document.querySelectorAll('#commentsTable tr');

            rows.forEach(row => {
                const student = row.cells[1]?.textContent.toLowerCase() || '';
                const comment = row.cells[3]?.textContent.toLowerCase() || '';
                const rowTermId = row.dataset?.termId || '';
                const matchesSearch = student.includes(search) || comment.includes(search);
                const matchesTerm = !termId || rowTermId === termId;
                row.style.display = matchesSearch && matchesTerm ? '' : 'none';
            });
        }

        document.getElementById('searchInput').addEventListener('input', filterComments);
        document.getElementById('termFilter').addEventListener('change', filterComments);

        // Edit comment modal
        const editModal = document.getElementById('editModal');
        const closeModal = document.getElementById('closeModal');
        const cancelEdit = document.getElementById('cancelEdit');
        const editCommentForm = document.getElementById('editCommentForm');
        const editSubmitBtn = document.getElementById('editSubmitBtn');

        document.querySelectorAll('.edit-comment').forEach(button => {
            button.addEventListener('click', () => {
                const commentId = button.dataset.id;
                const classId = button.dataset.classId;
                const studentId = button.dataset.studentId;
                const termId = button.dataset.termId;
                const comment = button.dataset.comment;
                const className = button.dataset.className;
                const studentName = button.dataset.studentName;

                document.getElementById('edit_comment_id').value = commentId;
                document.getElementById('edit_class_id').value = classId;
                document.getElementById('edit_class_name').value = className;
                document.getElementById('edit_student_id').value = studentId;
                document.getElementById('edit_student_name').value = studentName;
                document.getElementById('edit_term_id').value = termId;
                document.getElementById('edit_comment').value = comment;

                editModal.classList.remove('hidden');
                updateEditSubmitButton();
            });
        });

        function updateEditSubmitButton() {
            const termId = document.getElementById('edit_term_id').value;
            const comment = document.getElementById('edit_comment').value.trim();
            editSubmitBtn.disabled = !(termId && comment && comment.length <= 65535);
        }

        editCommentForm.addEventListener('input', updateEditSubmitButton);

        closeModal.addEventListener('click', () => {
            editModal.classList.add('hidden');
        });

        cancelEdit.addEventListener('click', () => {
            editModal.classList.add('hidden');
        });

        editModal.addEventListener('click', (e) => {
            if (e.target === editModal) {
                editModal.classList.add('hidden');
            }
        });
    </script>
</body>
</html>

<?php
$conn->close();
?>