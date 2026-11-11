<?php
ob_start();
session_start();
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');

// Ensure logging directory exists
$log_dir = $_SERVER['DOCUMENT_ROOT'] . '/logs/';
$log_file = $log_dir . 'auth_errors.log';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}
if (!is_writable($log_dir)) {
    $log_file = sys_get_temp_dir() . '/qpmseduc_auth_errors.log';
}

// Check user authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'student') {
    file_put_contents($log_file, date('Y-m-d H:i:s') . " - Authentication failed: user_id=" . ($_SESSION['user_id'] ?? 'unset') . ", role=" . ($_SESSION['user_role'] ?? 'unset') . "\n", FILE_APPEND);
    header("Location: /login.php?error=unauthenticated");
    exit;
}

$user_id = $_SESSION['user_id'];
$errors = [];
$success_message = '';

// Generate or reuse CSRF token
if (!isset($_SESSION['csrf_token']) || empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];
$stmt = $conn->prepare("INSERT INTO csrf_tokens (user_id, token, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR)) ON DUPLICATE KEY UPDATE token = ?, expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)");
$stmt->bind_param("iss", $user_id, $csrf_token, $csrf_token);
if (!$stmt->execute()) {
    error_log("CSRF token insertion failed: " . $stmt->error);
    $errors[] = "Internal error with security token. Please try again later.";
}
$stmt->close();

// Custom sanitization function
function sanitize_string($input) {
    if (!is_string($input)) {
        return '';
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Fetch current academic year and term
$academic_year = '2024/2025';
$term_query = $conn->prepare("SELECT id, term_name, start_date, end_date FROM academic_calendar WHERE year = ? AND start_date <= CURDATE() AND end_date >= CURDATE()");
$term_query->bind_param("s", $academic_year);
$term_query->execute();
$term_result = $term_query->get_result();
$current_term = $term_result->fetch_assoc() ?: ['id' => null, 'term_name' => 'No Active Term'];
$term_query->close();

// Handle session joining via token
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['join_session'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $errors[] = "Invalid CSRF token. Please refresh and try again.";
        error_log("CSRF token mismatch on join_session: submitted=" . ($_POST['csrf_token'] ?? 'unset') . ", expected=$csrf_token");
    } else {
        $session_token = sanitize_string($_POST['session_token'] ?? '');
        if (!$session_token || strlen($session_token) > 64) {
            $errors[] = "Invalid session token.";
        } else {
            $stmt = $conn->prepare("SELECT cs.id, cs.class_id FROM chat_sessions cs 
                                    JOIN student_classes sc ON cs.class_id = sc.class_id 
                                    WHERE cs.session_token = ? AND sc.user_id = ? AND cs.term_id = ? AND cs.status IN ('active', 'closed')");
            $stmt->bind_param("sii", $session_token, $user_id, $current_term['id']);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->num_rows > 0) {
                $session = $result->fetch_assoc();
                $session_id = $session['id'];
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header("Location: quizzes.php?session_id=$session_id");
                exit;
            } else {
                $errors[] = "Invalid or inaccessible session token.";
                error_log("Session join failed: token=$session_token, user_id=$user_id");
            }
            $stmt->close();
        }
    }
}

// Handle message submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_message'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        $errors[] = "Invalid CSRF token. Please refresh and try again.";
        error_log("CSRF token mismatch on send_message: submitted=" . ($_POST['csrf_token'] ?? 'unset') . ", expected=$csrf_token");
    } else {
        $session_id = filter_input(INPUT_POST, 'session_id', FILTER_VALIDATE_INT);
        $message = sanitize_string($_POST['message'] ?? '');
        if (!$session_id || !$message || strlen($message) > 65535) {
            $errors[] = "Invalid session or message (max 65,535 characters).";
        } else {
            // Verify student access to session
            $stmt = $conn->prepare("SELECT cs.id FROM chat_sessions cs 
                                    JOIN student_classes sc ON cs.class_id = sc.class_id 
                                    WHERE cs.id = ? AND sc.user_id = ? AND cs.status = 'active'");
            $stmt->bind_param("ii", $session_id, $user_id);
            $stmt->execute();
            if ($stmt->get_result()->num_rows == 0) {
                $errors[] = "You cannot send messages in this session.";
                $stmt->close();
            } else {
                $stmt->close();
                $media_path = null;
                if (!empty($_FILES['media']['name'])) {
                    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/chat/';
                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0755, true);
                    }
                    $file_ext = strtolower(pathinfo($_FILES['media']['name'], PATHINFO_EXTENSION));
                    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'webm', 'ogg'];
                    if (!in_array($file_ext, $allowed_exts) || $_FILES['media']['size'] > 10 * 1024 * 1024) {
                        $errors[] = "Invalid file type or size (max 10MB, allowed: jpg, png, gif, mp4, webm, ogg).";
                    } else {
                        $media_path = '/uploads/chat/' . time() . '_' . basename($_FILES['media']['name']);
                        $full_path = $_SERVER['DOCUMENT_ROOT'] . $media_path;
                        if (!move_uploaded_file($_FILES['media']['tmp_name'], $full_path)) {
                            $errors[] = "Failed to upload media.";
                            error_log("Media upload failed: " . $_FILES['media']['error']);
                        }
                    }
                }
                if (empty($errors)) {
                    $stmt = $conn->prepare("INSERT INTO chat_messages (chat_session_id, user_id, message, message_media, is_active, last_activity, created_at) VALUES (?, ?, ?, ?, 1, NOW(), NOW())");
                    $stmt->bind_param("iiss", $session_id, $user_id, $message, $media_path);
                    if ($stmt->execute()) {
                        $success_message = "Message sent successfully!";
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                        header("Location: quizzes.php?session_id=$session_id");
                        exit;
                    } else {
                        $errors[] = "Failed to send message.";
                        error_log("Message insertion failed: " . $stmt->error);
                    }
                    $stmt->close();
                }
            }
        }
    }
}

// Fetch available chat sessions
$chat_sessions = [];
$stmt = $conn->prepare("SELECT cs.id, cs.title, cs.status, cs.start_time, cs.end_time, cs.session_token, c.class_name 
                        FROM chat_sessions cs 
                        JOIN student_classes sc ON cs.class_id = sc.class_id 
                        JOIN classes c ON cs.class_id = c.id 
                        WHERE sc.user_id = ? AND cs.term_id = ? AND cs.status IN ('active', 'closed') 
                        ORDER BY cs.created_at DESC LIMIT 50");
$stmt->bind_param("ii", $user_id, $current_term['id']);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $chat_sessions[] = $row;
}
$stmt->close();

// Fetch current session data
$session_id = filter_input(INPUT_GET, 'session_id', FILTER_VALIDATE_INT);
$session_data = null;
$messages = [];
$active_learners = [];
$inactive_learners = [];
$participant_count = 0;
if ($session_id) {
    $stmt = $conn->prepare("SELECT cs.*, c.class_name FROM chat_sessions cs 
                            JOIN student_classes sc ON cs.class_id = sc.class_id 
                            JOIN classes c ON cs.class_id = c.id 
                            WHERE cs.id = ? AND sc.user_id = ?");
    $stmt->bind_param("ii", $session_id, $user_id);
    $stmt->execute();
    $session_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($session_data) {
        // Fetch messages
        $stmt = $conn->prepare("SELECT cm.*, u.name, u.role FROM chat_messages cm 
                                JOIN users u ON cm.user_id = u.id 
                                WHERE cm.chat_session_id = ? 
                                ORDER BY cm.created_at LIMIT 100");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Fetch active/inactive learners
        $stmt = $conn->prepare("SELECT u.name, cm.is_active, cm.last_activity 
                                FROM student_classes sc 
                                JOIN users u ON sc.user_id = u.id 
                                LEFT JOIN chat_messages cm ON cm.user_id = u.id AND cm.chat_session_id = ? 
                                WHERE sc.class_id = ? AND sc.academic_year = ?");
        $stmt->bind_param("iis", $session_id, $session_data['class_id'], $academic_year);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $participant_count++;
            if ($row['is_active'] && strtotime($row['last_activity']) >= time() - 300) {
                $active_learners[] = $row['name'];
            } else {
                $inactive_learners[] = $row['name'];
            }
        }
        $stmt->close();
    } else {
        $errors[] = "Invalid or inaccessible chat session.";
    }
}

// Handle AJAX refresh for chat
if (isset($_GET['ajax']) && $session_id) {
    ob_clean();
    ?>
    <div id="chatInteraction" class="bg-white rounded-lg shadow-lg p-8">
        <h2 class="text-2xl font-semibold text-gray-800 mb-6">Chat: <?php echo htmlspecialchars($session_data['title']); ?> (<?php echo htmlspecialchars($session_data['class_name']); ?>)</h2>
        <div class="chat-container border border-gray-200 p-6 rounded-lg mb-6">
            <?php if (empty($messages)): ?>
            <p class="text-gray-500 italic text-center">No messages yet. Start the conversation!</p>
            <?php else: ?>
            <?php foreach ($messages as $msg): ?>
            <div class="message <?php echo $msg['role'] == 'teacher' ? 'message-teacher' : 'message-student'; ?>">
                <div class="flex items-start">
                    <div class="flex-1">
                        <strong class="text-base font-medium"><?php echo htmlspecialchars($msg['name']); ?> (<?php echo $msg['role'] == 'teacher' ? 'Teacher' : 'Student'; ?>):</strong>
                        <p class="mt-1"><?php echo htmlspecialchars($msg['message']); ?></p>
                        <?php if ($msg['message_media']): ?>
                        <?php if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $msg['message_media'])): ?>
                        <img src="<?php echo $msg['message_media']; ?>" class="max-w-sm h-auto mt-3 rounded-lg" alt="Chat image">
                        <?php elseif (preg_match('/\.(mp4|webm|ogg)$/i', $msg['message_media'])): ?>
                        <video src="<?php echo $msg['message_media']; ?>" controls class="max-w-sm mt-3 rounded-lg" aria-label="Chat video"></video>
                        <?php endif; ?>
                        <?php endif; ?>
                        <small class="text-gray-500 block mt-2"><?php echo date('Y-m-d H:i:s', strtotime($msg['created_at'])); ?></small>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div class="mt-8">
            <div class="flex justify-between items-center">
                <h3 class="text-xl font-semibold text-gray-800">Active Learners (<?php echo count($active_learners); ?>)</h3>
                <span class="text-sm text-gray-500">Total Participants: <?php echo $participant_count; ?></span>
            </div>
            <ul class="list-disc pl-5 mt-4" id="activeLearnersList">
                <?php if (empty($active_learners)): ?>
                <li class="text-gray-500">No active learners.</li>
                <?php else: ?>
                <?php foreach ($active_learners as $learner): ?>
                <li><?php echo htmlspecialchars($learner); ?></li>
                <?php endforeach; ?>
                <?php endif; ?>
            </ul>
            <h3 class="text-xl font-semibold text-gray-800 mt-6">Inactive Learners (<?php echo count($inactive_learners); ?>)</h3>
            <ul class="list-disc pl-5 mt-4" id="inactiveLearnersList">
                <?php if (empty($inactive_learners)): ?>
                <li class="text-gray-500">No inactive learners.</li>
                <?php else: ?>
                <?php foreach ($inactive_learners as $learner): ?>
                <li><?php echo htmlspecialchars($learner); ?></li>
                <?php endforeach; ?>
                <?php endif; ?>
            </ul>
        </div>
    </div>
    <?php
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Student Live Chat Dashboard for QueensPa School Management System">
    <meta name="author" content="QueensPa School">
    <title>Student Live Chat - QueensPa School Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .chat-container {
            max-height: 600px;
            overflow-y: auto;
            background: #f9fafb;
            border-radius: 12px;
            padding: 20px;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
        }
        .message {
            margin: 12px 0;
            padding: 14px 18px;
            border-radius: 12px;
            max-width: 85%;
            word-wrap: break-word;
            transition: all 0.2s ease;
        }
        .message-teacher {
            background: #f97316;
            color: white;
            margin-left: auto;
            box-shadow: 0 3px 6px rgba(0,0,0,0.1);
        }
        .message-student {
            background: #e5e7eb;
            color: #1f2937;
            margin-right: auto;
            box-shadow: 0 3px 6px rgba(0,0,0,0.1);
        }
        .message:hover {
            transform: translateY(-2px);
        }
        .message img, .message video {
            border-radius: 8px;
            margin-top: 10px;
        }
        .loader {
            display: none;
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 40px;
            height: 40px;
            border: 4px solid #f97316;
            border-top: 4px solid transparent;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            z-index: 1000;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .error-message {
            background: #fef2f2;
            color: #dc2626;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: fadeIn 0.5s ease-in;
        }
        .success-message {
            background: #ecfdf5;
            color: #059669;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            animation: fadeIn 0.5s ease-in;
        }
        .chat-form input, .chat-form textarea, .chat-form select {
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }
        .chat-form input:focus, .chat-form textarea:focus, .chat-form select:focus {
            border-color: #f97316;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.15);
        }
        .btn-orange {
            background: #f97316;
            color: white;
            transition: background-color 0.3s ease, transform 0.2s ease;
        }
        .btn-orange:hover {
            background: #ea580c;
            transform: translateY(-1px);
        }
        .table-row:hover {
            background: #f3f4f6;
            transition: background-color 0.2s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .status-active {
            background: #d1fae5;
            color: #059669;
        }
        .status-closed {
            background: #fee2e2;
            color: #dc2626;
        }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <?php 
    $header_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_header.php';
    if (file_exists($header_path)) {
        include $header_path;
    } else {
        echo "<div class='error-message text-center'>Error: Header template not found at /templates/student_header.php. Please contact support.</div>";
    }
    ?>
    
    <div class="container mx-auto p-8 max-w-7xl">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-4xl font-bold text-orange-500">Student Live Chat Dashboard</h1>
            <span class="text-lg text-gray-600">Academic Term: <?php echo htmlspecialchars($current_term['term_name']); ?></span>
        </div>
        
        <!-- Display Errors or Success Messages -->
        <?php if (!empty($errors)): ?>
        <div class="error-message">
            <?php foreach ($errors as $error): ?>
            <p><?php echo htmlspecialchars($error); ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if ($success_message): ?>
        <div class="success-message">
            <p><?php echo htmlspecialchars($success_message); ?></p>
        </div>
        <?php endif; ?>

        <!-- Join Chat Session via Token -->
        <div class="bg-white rounded-lg shadow-lg p-8 mb-8">
            <h2 class="text-2xl font-semibold text-gray-800 mb-6">Join Chat Session by Token</h2>
            <form method="POST" class="chat-form space-y-6" id="joinSessionForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div>
                    <label for="session_token" class="block text-sm font-medium text-gray-700">Session Token</label>
                    <input type="text" id="session_token" name="session_token" class="mt-1 w-full p-3 border border-gray-300 rounded-lg focus:outline-none" placeholder="Enter session token" maxlength="64" required>
                    <p class="text-sm text-gray-500 mt-1">Enter the token provided by your teacher</p>
                </div>
                <button type="submit" name="join_session" class="btn-orange px-6 py-3 rounded-lg font-medium w-full sm:w-auto">Join Session</button>
            </form>
        </div>

        <!-- Available Chat Sessions -->
        <div class="bg-white rounded-lg shadow-lg p-8 mb-8">
            <h2 class="text-2xl font-semibold text-gray-800 mb-6">Available Chat Sessions</h2>
            <?php if (empty($chat_sessions)): ?>
            <p class="text-gray-500 italic">No chat sessions available. Use a session token or check with your teacher!</p>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full table-auto">
                    <thead>
                        <tr class="bg-gray-200 text-gray-700">
                            <th class="px-6 py-4 text-left">Title</th>
                            <th class="px-6 py-4 text-left">Class</th>
                            <th class="px-6 py-4 text-left">Status</th>
                            <th class="px-6 py-4 text-left">Start Time</th>
                            <th class="px-6 py-4 text-left">Participants</th>
                            <th class="px-6 py-4 text-left">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="sessionTable">
                        <?php foreach ($chat_sessions as $session): ?>
                        <tr class="table-row border-b">
                            <td class="px-6 py-4"><?php echo htmlspecialchars($session['title']); ?></td>
                            <td class="px-6 py-4"><?php echo htmlspecialchars($session['class_name']); ?></td>
                            <td class="px-6 py-4">
                                <span class="inline-block px-3 py-1 rounded-full text-sm font-medium status-<?php echo strtolower($session['status']); ?>">
                                    <?php echo htmlspecialchars($session['status']); ?>
                                </span>
                            </td>
                            <td class="px-6 py-4"><?php echo $session['start_time'] ? date('Y-m-d H:i', strtotime($session['start_time'])) : 'N/A'; ?></td>
                            <td class="px-6 py-4">
                                <?php
                                $stmt = $conn->prepare("SELECT COUNT(DISTINCT user_id) as count FROM chat_messages WHERE chat_session_id = ?");
                                $stmt->bind_param("i", $session['id']);
                                $stmt->execute();
                                $count = $stmt->get_result()->fetch_assoc()['count'];
                                $stmt->close();
                                echo $count;
                                ?>
                            </td>
                            <td class="px-6 py-4">
                                <a href="?session_id=<?php echo $session['id']; ?>" class="btn-orange px-4 py-2 rounded-lg text-sm font-medium inline-block">Join</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Chat Interaction Area -->
        <?php if ($session_id && $session_data): ?>
        <div id="chatInteraction" class="bg-white rounded-lg shadow-lg p-8">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-2xl font-semibold text-gray-800">Chat: <?php echo htmlspecialchars($session_data['title']); ?> (<?php echo htmlspecialchars($session_data['class_name']); ?>)</h2>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-500">Session Token: <?php echo htmlspecialchars($session_data['session_token']); ?></span>
                    <?php if ($session_data['status'] == 'active'): ?>
                    <span class="inline-block px-3 py-1 rounded-full text-sm font-medium status-active">Active</span>
                    <?php else: ?>
                    <span class="inline-block px-3 py-1 rounded-full text-sm font-medium status-closed">Closed</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="chat-container border border-gray-200 p-6 rounded-lg mb-6">
                <?php if (empty($messages)): ?>
                <p class="text-gray-500 italic text-center">No messages yet. Start the conversation!</p>
                <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                <div class="message <?php echo $msg['role'] == 'teacher' ? 'message-teacher' : 'message-student'; ?>">
                    <div class="flex items-start">
                        <div class="flex-1">
                            <strong class="text-base font-medium"><?php echo htmlspecialchars($msg['name']); ?> (<?php echo $msg['role'] == 'teacher' ? 'Teacher' : 'Student'; ?>):</strong>
                            <p class="mt-1"><?php echo htmlspecialchars($msg['message']); ?></p>
                            <?php if ($msg['message_media']): ?>
                            <?php if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $msg['message_media'])): ?>
                            <img src="<?php echo $msg['message_media']; ?>" class="max-w-sm h-auto mt-3 rounded-lg" alt="Chat image">
                            <?php elseif (preg_match('/\.(mp4|webm|ogg)$/i', $msg['message_media'])): ?>
                            <video src="<?php echo $msg['message_media']; ?>" controls class="max-w-sm mt-3 rounded-lg" aria-label="Chat video"></video>
                            <?php endif; ?>
                            <?php endif; ?>
                            <small class="text-gray-500 block mt-2"><?php echo date('Y-m-d H:i:s', strtotime($msg['created_at'])); ?></small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if ($session_data['status'] == 'active'): ?>
            <form method="POST" enctype="multipart/form-data" class="chat-form space-y-6" id="messageForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="session_id" value="<?php echo $session_id; ?>">
                <div>
                    <label for="message" class="block text-sm font-medium text-gray-700">Message</label>
                    <textarea id="message" name="message" class="mt-1 w-full p-3 border border-gray-300 rounded-lg focus:outline-none" rows="4" placeholder="Type your message..." maxlength="65535" required></textarea>
                    <p class="text-sm text-gray-500 mt-1">Max 65,535 characters</p>
                </div>
                <div>
                    <label for="media" class="block text-sm font-medium text-gray-700">Upload Media (Image/Video, max 10MB)</label>
                    <input type="file" id="media" name="media" accept="image/*,video/*" class="mt-1 w-full p-3 border border-gray-300 rounded-lg">
                    <p class="text-sm text-gray-500 mt-1">Supported: jpg, png, gif, mp4, webm, ogg</p>
                </div>
                <button type="submit" name="send_message" class="btn-orange px-6 py-3 rounded-lg font-medium w-full sm:w-auto">Send Message</button>
            </form>
            <?php else: ?>
            <p class="text-red-500 italic text-center">This session is closed. You cannot send messages.</p>
            <?php endif; ?>
            <div class="mt-8">
                <div class="flex justify-between items-center">
                    <h3 class="text-xl font-semibold text-gray-800">Active Learners (<?php echo count($active_learners); ?>)</h3>
                    <span class="text-sm text-gray-500">Total Participants: <?php echo $participant_count; ?></span>
                </div>
                <ul class="list-disc pl-5 mt-4" id="activeLearnersList">
                    <?php if (empty($active_learners)): ?>
                    <li class="text-gray-500">No active learners.</li>
                    <?php else: ?>
                    <?php foreach ($active_learners as $learner): ?>
                    <li><?php echo htmlspecialchars($learner); ?></li>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
                <h3 class="text-xl font-semibold text-gray-800 mt-6">Inactive Learners (<?php echo count($inactive_learners); ?>)</h3>
                <ul class="list-disc pl-5 mt-4" id="inactiveLearnersList">
                    <?php if (empty($inactive_learners)): ?>
                    <li class="text-gray-500">No inactive learners.</li>
                    <?php else: ?>
                    <?php foreach ($inactive_learners as $learner): ?>
                    <li><?php echo htmlspecialchars($learner); ?></li>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
                <canvas id="learnerChart" class="mt-8 max-w-md mx-auto"></canvas>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="loader"></div>

    <?php 
    $footer_path = $_SERVER['DOCUMENT_ROOT'] . '/templates/student_footer.php';
    if (file_exists($footer_path)) {
        include $footer_path;
    } else {
        echo "<div class='error-message text-center'>Error: Footer template not found at /templates/student_footer.php. Please contact support.</div>";
    }
    ?>

    <script>
        // Initialize chat container scroll
        <?php if ($session_id): ?>
        $('.chat-container').scrollTop($('.chat-container')[0].scrollHeight);

        // Real-time chat updates
        function updateChat() {
            $.ajax({
                url: 'quizzes.php',
                type: 'GET',
                data: { session_id: <?php echo $session_id; ?>, ajax: 1 },
                beforeSend: function() {
                    $('.loader').fadeIn(200);
                },
                success: function(response) {
                    let parser = new DOMParser();
                    let doc = parser.parseFromString(response, 'text/html');
                    $('#chatInteraction .chat-container').html($(doc).find('#chatInteraction .chat-container').html());
                    $('#activeLearnersList').html($(doc).find('#activeLearnersList').html());
                    $('#inactiveLearnersList').html($(doc).find('#inactiveLearnersList').html());
                    updateLearnerChart();
                    $('.chat-container').scrollTop($('.chat-container')[0].scrollHeight);
                },
                error: function(xhr, status, error) {
                    $('<div class="error-message text-center">Failed to refresh chat: ' + error + '. Please try again.</div>').insertAfter('h1').delay(5000).fadeOut(500);
                },
                complete: function() {
                    $('.loader').fadeOut(200);
                }
            });
        }

        // Update learner chart
        let learnerChart;
        function updateLearnerChart() {
            let activeCount = $('#activeLearnersList li:not(.text-gray-500)').length;
            let inactiveCount = $('#inactiveLearnersList li:not(.text-gray-500)').length;
            if (learnerChart) learnerChart.destroy();
            learnerChart = new Chart($('#learnerChart'), {
                type: 'pie',
                data: {
                    labels: ['Active Learners', 'Inactive Learners'],
                    datasets: [{
                        data: [activeCount, inactiveCount],
                        backgroundColor: ['#f97316', '#d1d5db'],
                        borderColor: ['#fff', '#fff'],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { font: { size: 14, family: 'Inter, sans-serif' } }
                        },
                        tooltip: {
                            backgroundColor: '#1f2937',
                            bodyFont: { size: 14 }
                        }
                    }
                }
            });
        }

        // Poll for updates every 3 seconds
        setInterval(updateChat, 3000);

        // Form validation
        $('#joinSessionForm, #messageForm').on('submit', function(e) {
            let $form = $(this);
            if ($form.find('input[name="session_token"]').length && !$form.find('input[name="session_token"]').val().trim()) {
                e.preventDefault();
                $('<div class="error-message">Session token is required.</div>').insertAfter('h1').delay(5000).fadeOut(500);
            }
            if ($form.find('textarea[name="message"]').length && !$form.find('textarea[name="message"]').val().trim()) {
                e.preventDefault();
                $('<div class="error-message">Message cannot be empty.</div>').insertAfter('h1').delay(5000).fadeOut(500);
            }
        });

        // Validate file size and type
        $('#media').change(function() {
            let file = this.files[0];
            if (file) {
                let fileSize = file.size / (1024 * 1024); // MB
                let fileExt = file.name.split('.').pop().toLowerCase();
                let allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'mp4', 'webm', 'ogg'];
                if (fileSize > 10) {
                    alert('File size exceeds 10MB limit.');
                    this.value = '';
                } else if (!allowedExts.includes(fileExt)) {
                    alert('Invalid file type. Allowed: jpg, png, gif, mp4, webm, ogg.');
                    this.value = '';
                }
            }
        });

        // Auto-focus message input
        $('#message').focus();

        // Smooth scroll to chat section on load
        if ($('#chatInteraction').length) {
            $('html, body').animate({
                scrollTop: $('#chatInteraction').offset().top - 100
            }, 500);
        }
        <?php endif; ?>
    </script>
</body>
</html>