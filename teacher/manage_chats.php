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

// Enable error logging
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/error.log');

// Include database connection
require_once($_SERVER['DOCUMENT_ROOT'] . '/config/db.php');
if (!isset($conn) || !$conn || mysqli_connect_error()) {
    error_log("Database connection failed: " . (mysqli_connect_error() ?: "Unknown error"));
    $_SESSION['error_msg'] = "System error: Database connection failed.";
    header("Location: /login.php");
    exit();
}

// Include logging function
require_once($_SERVER['DOCUMENT_ROOT'] . '/functions/log_activity.php');

// Validate teacher_id
$teacher_id = (int)$_SESSION['user_id'];
$user_check_sql = "SELECT id, name, profile_picture FROM users WHERE id = ? AND role = 'teacher'";
$user_check_stmt = $conn->prepare($user_check_sql);
$user_check_stmt->bind_param("i", $teacher_id);
$user_check_stmt->execute();
$teacher_info = $user_check_stmt->get_result()->fetch_assoc();
if (!$teacher_info) {
    error_log("Invalid teacher_id: $teacher_id");
    $_SESSION['error_msg'] = "Invalid user session.";
    header("Location: /login.php");
    exit();
}
$user_check_stmt->close();

// Session timeout (30 minutes)
$timeout_duration = 1800;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout_duration) {
    session_unset();
    session_destroy();
    $_SESSION['error_msg'] = "Session timed out.";
    header("Location: /login.php");
    exit();
}
$_SESSION['last_activity'] = time();

// Log page access
$ip_address = filter_var($_SERVER['REMOTE_ADDR'], FILTER_VALIDATE_IP) ?: 'unknown';
$user_agent = filter_var($_SERVER['HTTP_USER_AGENT'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: 'unknown';
logActivity($conn, $teacher_id, "Accessed Manage Chats", "Teacher accessed chats from IP: $ip_address, User-Agent: $user_agent");

// Generate CSRF token
$conn->begin_transaction();
try {
    $csrf_token = bin2hex(random_bytes(32));
    $csrf_sql = "INSERT INTO csrf_tokens (user_id, token, created_at, expires_at) VALUES (?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR))";
    $csrf_stmt = $conn->prepare($csrf_sql);
    $csrf_stmt->bind_param("is", $teacher_id, $csrf_token);
    $csrf_stmt->execute();
    $csrf_stmt->close();
    $conn->query("DELETE FROM csrf_tokens WHERE expires_at < NOW()");
    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    error_log("CSRF token error: " . $e->getMessage());
    $_SESSION['error_msg'] = "Security error.";
    header("Location: /login.php");
    exit();
}

// Handle message sending
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        error_log("CSRF validation failed for teacher_id: $teacher_id");
        $_SESSION['error_msg'] = "Security error.";
        header("Location: /login.php");
        exit();
    }

    $chat_session_id = isset($_POST['chat_session_id']) ? (int)$_POST['chat_session_id'] : 0;
    $is_private = isset($_POST['is_private']) && $_POST['is_private'] === '1';
    $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $reply_to_id = isset($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : null;
    $message_media = '';

    // Validate file upload
    if (isset($_FILES['message_media']) && $_FILES['message_media']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/chats/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $file_name = uniqid() . '_' . preg_replace("/[^A-Za-z0-9._-]/", '', $_FILES['message_media']['name']);
        $file_path = $upload_dir . $file_name;
        $max_file_size = 10 * 1024 * 1024; // 10MB
        if ($_FILES['message_media']['size'] <= $max_file_size) {
            if (move_uploaded_file($_FILES['message_media']['tmp_name'], $file_path)) {
                $message_media = '/uploads/chats/' . $file_name;
            } else {
                error_log("File upload failed for teacher_id: $teacher_id, file: " . $_FILES['message_media']['name']);
                $_SESSION['error_msg'] = "Failed to upload file.";
            }
        } else {
            error_log("File size exceeds limit for teacher_id: $teacher_id, size: " . $_FILES['message_media']['size']);
            $_SESSION['error_msg'] = "File size exceeds 10MB limit.";
        }
    }

    if ($message || $message_media) {
        $table = $is_private ? 'private_chat_messages' : 'chat_messages';
        $sql = "INSERT INTO $table (chat_session_id, user_id, message, message_media, " . ($is_private ? '' : 'read_status, ') . "is_active, created_at) 
                VALUES (?, ?, ?, ?, " . ($is_private ? '' : "'unread', ") . "1, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("iiss", $chat_session_id, $teacher_id, $message, $message_media);
        if ($stmt->execute()) {
            $message_id = $stmt->insert_id;
            $stmt->close();

            // Store reply_to_id (for message replies)
            if ($reply_to_id) {
                $reply_sql = "INSERT INTO message_replies (message_id, reply_to_id, is_private) VALUES (?, ?, ?)";
                $reply_stmt = $conn->prepare($reply_sql);
                $reply_stmt->bind_param("iii", $message_id, $reply_to_id, $is_private);
                $reply_stmt->execute();
                $reply_stmt->close();
            }

            // Create notifications
            if ($is_private) {
                $recipient_sql = "SELECT sender_id, recipient_id FROM private_chat_sessions WHERE id = ?";
                $recipient_stmt = $conn->prepare($recipient_sql);
                $recipient_stmt->bind_param("i", $chat_session_id);
                $recipient_stmt->execute();
                $recipient = $recipient_stmt->get_result()->fetch_assoc();
                $recipient_id = ($recipient['sender_id'] == $teacher_id) ? $recipient['recipient_id'] : $recipient['sender_id'];
                $recipient_stmt->close();

                $notif_sql = "INSERT INTO notifications (user_id, title, message, type, category, link, created_at) 
                              VALUES (?, ?, ?, 'info', 'academic', ?, NOW())";
                $notif_stmt = $conn->prepare($notif_sql);
                $title = "New Private Message";
                $notif_message = "New message from " . htmlspecialchars($teacher_info['name']) . ".";
                $link = "/chat.php?private_chat_session_id=$chat_session_id";
                $notif_stmt->bind_param("isss", $recipient_id, $title, $notif_message, $link);
                $notif_stmt->execute();
                $notif_stmt->close();
            } else {
                $notif_sql = "INSERT INTO notifications (user_id, title, message, type, category, link, created_at) 
                              SELECT cp.user_id, ?, ?, 'info', 'academic', ?, NOW() 
                              FROM chat_participants cp 
                              WHERE cp.chat_session_id = ? AND cp.user_id != ?";
                $notif_stmt = $conn->prepare($notif_sql);
                $title = "New Group Message";
                $notif_message = "New message in group chat: " . htmlspecialchars($_POST['chat_title']);
                $link = "/chat.php?chat_session_id=$chat_session_id";
                $notif_stmt->bind_param("sssi", $title, $notif_message, $link, $chat_session_id, $teacher_id);
                $notif_stmt->execute();
                $notif_stmt->close();
            }

            logActivity($conn, $teacher_id, "Sent Message", "Sent message in " . ($is_private ? "private" : "group") . " chat ID $chat_session_id");
        } else {
            error_log("Message insert failed for teacher_id: $teacher_id, chat_id: $chat_session_id");
            $_SESSION['error_msg'] = "Failed to send message.";
        }
    }
}

// Handle message deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_message') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $csrf_token) {
        error_log("CSRF validation failed for message deletion by teacher_id: $teacher_id");
        $_SESSION['error_msg'] = "Security error.";
        header("Location: /login.php");
        exit();
    }

    $message_id = (int)$_POST['message_id'];
    $is_private = isset($_POST['is_private']) && $_POST['is_private'] === '1';
    $table = $is_private ? 'private_chat_messages' : 'chat_messages';
    $sql = "UPDATE $table SET is_active = 0 WHERE id = ? AND user_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $message_id, $teacher_id);
    $stmt->execute();
    $stmt->close();
    logActivity($conn, $teacher_id, "Deleted Message", "Deleted message ID $message_id in " . ($is_private ? "private" : "group") . " chat");
}

// Handle typing indicator
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'typing') {
    // Store typing status in session (temporary, could use Redis for production)
    $_SESSION['typing_' . $_POST['chat_session_id'] . '_' . ($is_private ? 'private' : 'group')] = time();
}

// Handle AJAX message fetch
if (isset($_GET['fetch_messages']) && $_GET['fetch_messages'] == 1) {
    header('Content-Type: application/json');
    $chat_session_id = isset($_GET['chat_session_id']) ? (int)$_GET['chat_session_id'] : 0;
    $is_private = isset($_GET['is_private']) && $_GET['is_private'] === '1';
    $messages = [];

    if ($chat_session_id) {
        $table = $is_private ? 'private_chat_messages' : 'chat_messages';
        $sql = "SELECT m.id, m.message, m.message_media, " . ($is_private ? "'read' AS read_status" : "m.read_status") . ", m.created_at, u.name, u.profile_picture,
                       (SELECT mr.reply_to_id FROM message_replies mr WHERE mr.message_id = m.id AND mr.is_private = ?) AS reply_to_id
                FROM $table m 
                JOIN users u ON m.user_id = u.id 
                WHERE m.chat_session_id = ? AND m.is_active = 1 
                ORDER BY m.created_at ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ii", $is_private, $chat_session_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $messages[] = $row;
        }
        $stmt->close();

        if (!$is_private) {
            $update_sql = "UPDATE chat_messages SET read_status = 'read' WHERE chat_session_id = ? AND user_id != ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("ii", $chat_session_id, $teacher_id);
            $update_stmt->execute();
            $update_stmt->close();
        }

        // Fetch typing users
        $typing_users = [];
        $typing_key = 'typing_' . $chat_session_id . '_' . ($is_private ? 'private' : 'group');
        foreach ($_SESSION as $key => $time) {
            if (strpos($key, $typing_key) === 0 && (time() - $time) < 10) {
                $user_id = (int)str_replace($typing_key . '_', '', $key);
                if ($user_id != $teacher_id) {
                    $user_sql = "SELECT name FROM users WHERE id = ?";
                    $user_stmt = $conn->prepare($user_sql);
                    $user_stmt->bind_param("i", $user_id);
                    $user_stmt->execute();
                    $typing_users[] = $user_stmt->get_result()->fetch_assoc()['name'];
                    $user_stmt->close();
                }
            }
        }
        $messages['typing_users'] = $typing_users;
    }

    echo json_encode($messages);
    exit();
}

// Fetch chats with caching
$cache_key = "teacher_chats_$teacher_id";
$chats = apcu_fetch($cache_key);
if ($chats === false) {
    $chats = [];
    // Group chats
    $group_sql = "SELECT cs.id, cs.title, cs.group_picture, cs.status, cs.class_id, cs.term_id, cs.is_pinned,
                         (SELECT COUNT(*) FROM chat_messages cm WHERE cm.chat_session_id = cs.id AND cm.read_status = 'unread' AND cm.user_id != ?) as unread_count,
                         (SELECT cm2.message FROM chat_messages cm2 WHERE cm2.chat_session_id = cs.id ORDER BY cm2.created_at DESC LIMIT 1) as last_message,
                         (SELECT cm2.created_at FROM chat_messages cm2 WHERE cm2.chat_session_id = cs.id ORDER BY cm2.created_at DESC LIMIT 1) as last_message_time
                  FROM chat_sessions cs 
                  JOIN chat_participants cp ON cs.id = cp.chat_session_id 
                  WHERE cp.user_id = ? AND cs.status = 'active' 
                  ORDER BY cs.is_pinned DESC, last_message_time DESC";
    $group_stmt = $conn->prepare($group_sql);
    $group_stmt->bind_param("ii", $teacher_id, $teacher_id);
    $group_stmt->execute();
    $group_result = $group_stmt->get_result();
    while ($row = $group_result->fetch_assoc()) {
        $row['type'] = 'group';
        $chats[] = $row;
    }
    $group_stmt->close();

    // Private chats
    $private_sql = "SELECT pcs.id, pcs.title, pcs.session_token, pcs.status, pcs.class_id, pcs.term_id, u.name as recipient_name, u.profile_picture, 
                           (SELECT COUNT(*) FROM private_chat_messages pcm WHERE pcm.chat_session_id = pcs.id AND pcm.user_id != ?) as unread_count,
                           (SELECT pcm2.message FROM private_chat_messages pcm2 WHERE pcm2.chat_session_id = pcs.id ORDER BY pcm2.created_at DESC LIMIT 1) as last_message,
                           (SELECT pcm2.created_at FROM private_chat_messages pcm2 WHERE pcm2.chat_session_id = pcs.id ORDER BY pcm2.created_at DESC LIMIT 1) as last_message_time
                    FROM private_chat_sessions pcs 
                    JOIN users u ON (pcs.sender_id = ? AND pcs.recipient_id = u.id) OR (pcs.recipient_id = ? AND pcs.sender_id = u.id) 
                    WHERE pcs.status = 'active' 
                    ORDER BY last_message_time DESC";
    $private_stmt = $conn->prepare($private_sql);
    $private_stmt->bind_param("iii", $teacher_id, $teacher_id, $teacher_id);
    $private_stmt->execute();
    $private_result = $private_stmt->get_result();
    while ($row = $private_result->fetch_assoc()) {
        $row['type'] = 'private';
        $row['is_pinned'] = 0; // Private chats not pinned by default
        $chats[] = $row;
    }
    $private_stmt->close();

    // Sort chats
    usort($chats, function($a, $b) {
        if ($a['is_pinned'] != $b['is_pinned']) {
            return $b['is_pinned'] - $a['is_pinned'];
        }
        return strtotime($b['last_message_time'] ?? '1970-01-01') <=> strtotime($a['last_message_time'] ?? '1970-01-01');
    });

    apcu_store($cache_key, $chats, 300); // Cache for 5 minutes
}

// Fetch classes and terms for filters
$classes_sql = "SELECT id, class_name FROM classes WHERE teacher_id = ?";
$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->bind_param("i", $teacher_id);
$classes_stmt->execute();
$classes = $classes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$classes_stmt->close();

$terms_sql = "SELECT id, term_name, year FROM academic_calendar ORDER BY start_date DESC";
$terms = $conn->query($terms_sql)->fetch_all(MYSQLI_ASSOC);

// Create message_replies table if not exists
$conn->query("
    CREATE TABLE IF NOT EXISTS message_replies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        reply_to_id INT NOT NULL,
        is_private TINYINT(1) NOT NULL,
        FOREIGN KEY (message_id) REFERENCES " . ($is_private ? 'private_chat_messages' : 'chat_messages') . "(id),
        FOREIGN KEY (reply_to_id) REFERENCES " . ($is_private ? 'private_chat_messages' : 'chat_messages') . "(id)
    )
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Teacher chat management for QueensPa School, supporting group and private chats with media, replies, and typing indicators.">
    <meta name="keywords" content="QueensPa School, teacher chat, messaging, school management">
    <meta name="author" content="QueensPa School">
    <meta name="robots" content="noindex, nofollow">
    <title>Manage Chats - QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/emoji-picker-element@^1"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui'],
                        orbitron: ['Orbitron', 'sans-serif'],
                    },
                    colors: {
                        primary: {
                            50: '#fff7ed', 100: '#ffedd5', 200: '#fed7aa', 300: '#fdba74',
                            400: '#fb923c', 500: '#f97316', 600: '#ea580c', 700: '#c2410c',
                            800: '#9a3412', 900: '#7c2d12',
                        },
                        accent: { 500: '#1e3a8a' },
                    },
                    animation: {
                        'fade-in': 'fadeIn 0.6s ease-out',
                        'zoom-in': 'zoomIn 0.6s ease-out',
                        'glow': 'glow 2s ease-in-out infinite',
                        'pulse-slow': 'pulseSlow 2s ease-in-out infinite',
                    },
                    keyframes: {
                        fadeIn: { '0%': { opacity: '0' }, '100%': { opacity: '1' } },
                        zoomIn: { '0%': { opacity: '0', transform: 'scale(0.95)' }, '100%': { opacity: '1', transform: 'scale(1)' } },
                        glow: {
                            '0%, 100%': { boxShadow: '0 0 5px rgba(249, 115, 22, 0.5)' },
                            '50%': { boxShadow: '0 0 20px rgba(249, 115, 22, 0.8)' },
                        },
                        pulseSlow: {
                            '0%, 100%': { transform: 'scale(1)' },
                            '50%': { transform: 'scale(1.1)' },
                        },
                    },
                    backdropBlur: { xs: '2px' },
                },
            },
            darkMode: 'class',
        }
    </script>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            line-height: 1.6;
        }
        .glassmorphism {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .dark .glassmorphism {
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }
        h1, h2, h3, h4, h5, h6 { font-family: 'Orbitron', sans-serif; }
        .chat-message { transition: all 0.3s ease; }
        .emoji-picker { display: none; position: absolute; bottom: 60px; z-index: 1000; }
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #f97316; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #ea580c; }
        .dark ::-webkit-scrollbar-track { background: #1f2937; }
        .dark ::-webkit-scrollbar-thumb { background: #f97316; }
        .dark ::-webkit-scrollbar-thumb:hover { background: #ea580c; }
    </style>
</head>
<body class="min-h-screen bg-gradient-to-br from-gray-900 via-gray-800 to-orange-900 dark:bg-gradient-to-br dark:from-gray-900 dark:via-gray-800 dark:to-orange-900">
    <div id="loading-overlay" class="fixed inset-0 bg-black bg-opacity-80 z-50 flex justify-center items-center hidden">
        <i class="fas fa-spinner fa-spin text-orange-500 text-4xl animate-pulse-slow"></i>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_header.php'; ?>

    <div class="content" id="content">
        <main class="container mx-auto px-4 py-8 max-w-7xl">
            <div class="flex flex-col md:flex-row justify-between items-center mb-8 animate-fade-in">
                <h1 class="text-3xl md:text-4xl font-bold text-white dark:text-white flex-grow mb-4 md:mb-0 animate-glow">
                    <i class="fas fa-comments mr-2"></i> Manage Chats
                </h1>
                <div class="flex space-x-4">
                    <a href="/teacher/create_chat.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors duration-300 glassmorphism">
                        <i class="fas fa-plus mr-2"></i> Create New Chat
                    </a>
                    <button class="bg-accent-500 hover:bg-accent-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors duration-300 glassmorphism" id="theme-toggle">
                        <i class="fas fa-moon mr-2"></i> Toggle Theme
                    </button>
                </div>
            </div>

            <div class="grid md:grid-cols-[30%_70%] gap-4">
                <!-- Sidebar: Chat List -->
                <section class="glassmorphism rounded-2xl shadow-lg animate-zoom-in">
                    <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl">
                        <h3 class="text-xl font-semibold mb-0">Chats</h3>
                    </div>
                    <div class="p-6">
                        <input type="text" id="chat-search" class="w-full px-4 py-2 mb-4 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 glassmorphism bg-opacity-50 text-white placeholder-gray-400" placeholder="Search chats..." aria-label="Search chats">
                        <div class="flex flex-col md:flex-row gap-4 mb-4">
                            <select id="class-filter" class="px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 glassmorphism bg-opacity-50 text-white" aria-label="Filter by class">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?= $class['id'] ?>"><?= htmlspecialchars($class['class_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="term-filter" class="px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 glassmorphism bg-opacity-50 text-white" aria-label="Filter by term">
                                <option value="">All Terms</option>
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?= $term['id'] ?>"><?= htmlspecialchars($term['term_name'] . ' (' . $term['year'] . ')') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="role-filter" class="px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 glassmorphism bg-opacity-50 text-white" aria-label="Filter by role">
                                <option value="">All Roles</option>
                                <option value="student">Students</option>
                                <option value="parent">Parents</option>
                                <option value="teacher">Teachers</option>
                            </select>
                        </div>
                        <div class="max-h-[calc(100vh-200px)] overflow-y-auto">
                            <?php if (empty($chats)): ?>
                                <div class="text-center text-gray-300">No chats found. <a href="/teacher/create_chat.php" class="text-orange-400 hover:text-orange-500">Create one now</a>.</div>
                            <?php else: ?>
                                <div id="chat-list">
                                    <?php foreach ($chats as $chat): ?>
                                        <div class="chat-item flex items-center p-4 hover:bg-gray-700 rounded-lg cursor-pointer transition-colors duration-200 <?= $chat['unread_count'] > 0 ? 'animate-glow' : '' ?>" 
                                             data-chat-id="<?= $chat['id'] ?>" 
                                             data-type="<?= $chat['type'] ?>" 
                                             data-title="<?= htmlspecialchars($chat['title'] ?: $chat['recipient_name']) ?>" 
                                             data-class-id="<?= $chat['class_id'] ?? '' ?>" 
                                             data-term-id="<?= $chat['term_id'] ?? '' ?>">
                                            <img src="<?= htmlspecialchars($chat['group_picture'] ?: $chat['profile_picture'] ?: '/images/default-avatar.png') ?>" class="w-10 h-10 rounded-full mr-3" alt="Chat avatar">
                                            <div class="flex-1">
                                                <div class="flex justify-between">
                                                    <h4 class="font-semibold text-white"><?= htmlspecialchars($chat['title'] ?: $chat['recipient_name']) ?><?php if ($chat['is_pinned']): ?> <i class="fas fa-thumbtack text-orange-400 ml-2"></i><?php endif; ?></h4>
                                                    <span class="text-sm text-gray-400"><?= $chat['last_message_time'] ? date('H:i', strtotime($chat['last_message_time'])) : '' ?></span>
                                                </div>
                                                <p class="text-sm text-gray-300 truncate"><?= htmlspecialchars($chat['last_message'] ?: 'No messages yet') ?></p>
                                            </div>
                                            <?php if ($chat['unread_count'] > 0): ?>
                                                <span class="bg-orange-500 text-white text-xs font-semibold px-2 py-1 rounded-full"><?= $chat['unread_count'] ?></span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <!-- Main Chat Window -->
                <section class="glassmorphism rounded-2xl shadow-lg animate-zoom-in">
                    <div class="bg-gradient-to-r from-orange-500 to-red-500 text-white px-6 py-4 rounded-t-2xl flex justify-between items-center">
                        <h3 class="text-xl font-semibold mb-0" id="chat-title">Select a chat</h3>
                        <div class="flex space-x-4">
                            <button id="back-to-chats" class="md:hidden bg-gray-500 hover:bg-gray-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism">
                                <i class="fas fa-arrow-left"></i> Back
                            </button>
                            <button id="pin-chat" class="hidden bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism" title="Pin/unpin chat">
                                <i class="fas fa-thumbtack"></i>
                            </button>
                            <a id="manage-participants" href="#" class="hidden bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm transition-colors duration-300 glassmorphism">
                                <i class="fas fa-users"></i> Manage Participants
                            </a>
                        </div>
                    </div>
                    <div class="p-6">
                        <div id="typing-indicator" class="text-sm text-gray-400 mb-2 hidden"></div>
                        <div id="chat-messages" class="max-h-[calc(100vh-300px)] overflow-y-auto mb-4"></div>
                        <form id="message-form" method="POST" enctype="multipart/form-data" class="flex items-center gap-4 relative">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                            <input type="hidden" name="action" value="send_message">
                            <input type="hidden" id="chat-session-id" name="chat_session_id" value="">
                            <input type="hidden" id="is-private" name="is_private" value="0">
                            <input type="hidden" id="reply-to-id" name="reply_to_id" value="">
                            <emoji-picker class="emoji-picker"></emoji-picker>
                            <button type="button" id="emoji-toggle" class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-2 rounded-lg transition-colors duration-300 glassmorphism" aria-label="Toggle emoji picker">
                                <i class="fas fa-smile"></i>
                            </button>
                            <div id="reply-preview" class="hidden absolute -top-16 bg-gray-700 text-white p-2 rounded-lg max-w-full">
                                <span>Replying to: <span id="reply-text"></span></span>
                                <button type="button" id="cancel-reply" class="ml-2 text-orange-400 hover:text-orange-500">Cancel</button>
                            </div>
                            <input type="text" id="message-input" name="message" class="flex-1 px-4 py-2 border border-gray-500 rounded-lg focus:ring-2 focus:ring-orange-500 focus:border-orange-500 glassmorphism bg-opacity-50 text-white placeholder-gray-400" placeholder="Type a message..." aria-label="Message input">
                            <label class="bg-gray-500 hover:bg-gray-600 text-white px-3 py-2 rounded-lg cursor-pointer transition-colors duration-300 glassmorphism">
                                <i class="fas fa-paperclip"></i>
                                <input type="file" name="message_media" class="hidden" accept="*/*">
                            </label>
                            <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white px-3 py-2 rounded-lg transition-colors duration-300 glassmorphism" aria-label="Send message">
                                <i class="fas fa-paper-plane"></i>
                            </button>
                        </form>
                    </div>
                </section>
            </div>
        </main>
    </div>

    <?php include $_SERVER['DOCUMENT_ROOT'] . '/templates/teacher_footer.php'; ?>
    <?php mysqli_close($conn); ob_end_flush(); ?>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const loadingOverlay = document.getElementById('loading-overlay');
            const chatList = document.getElementById('chat-list');
            const chatMessages = document.getElementById('chat-messages');
            const chatTitle = document.getElementById('chat-title');
            const messageForm = document.getElementById('message-form');
            const chatSessionIdInput = document.getElementById('chat-session-id');
            const isPrivateInput = document.getElementById('is-private');
            const replyToIdInput = document.getElementById('reply-to-id');
            const manageParticipants = document.getElementById('manage-participants');
            const pinChat = document.getElementById('pin-chat');
            const backToChats = document.getElementById('back-to-chats');
            const chatSearch = document.getElementById('chat-search');
            const classFilter = document.getElementById('class-filter');
            const termFilter = document.getElementById('term-filter');
            const roleFilter = document.getElementById('role-filter');
            const emojiToggle = document.getElementById('emoji-toggle');
            const emojiPicker = document.querySelector('emoji-picker');
            const replyPreview = document.getElementById('reply-preview');
            const replyText = document.getElementById('reply-text');
            const cancelReply = document.getElementById('cancel-reply');
            const typingIndicator = document.getElementById('typing-indicator');

            let currentChatId = null;
            let currentChatType = null;
            let pollingInterval = null;
            let lastFetchTime = 0;
            let typingTimeout = null;

            // Dark mode toggle
            const themeToggle = document.getElementById('theme-toggle');
            if (localStorage.getItem('theme') === 'dark') {
                document.body.classList.add('dark');
                themeToggle.innerHTML = '<i class="fas fa-sun mr-2"></i> Toggle Theme';
            }
            themeToggle.addEventListener('click', () => {
                document.body.classList.toggle('dark');
                const isDark = document.body.classList.contains('dark');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                themeToggle.innerHTML = isDark ? '<i class="fas fa-sun mr-2"></i> Toggle Theme' : '<i class="fas fa-moon mr-2"></i> Toggle Theme';
            });

            // Emoji picker
            emojiToggle.addEventListener('click', () => {
                emojiPicker.style.display = emojiPicker.style.display === 'block' ? 'none' : 'block';
            });
            emojiPicker.addEventListener('emoji-click', (event) => {
                const input = document.getElementById('message-input');
                input.value += event.detail.unicode;
                emojiPicker.style.display = 'none';
            });

            // Chat selection
            document.querySelectorAll('.chat-item').forEach(item => {
                item.addEventListener('click', () => {
                    currentChatId = item.dataset.chatId;
                    currentChatType = item.dataset.type;
                    chatSessionIdInput.value = currentChatId;
                    isPrivateInput.value = currentChatType === 'private' ? '1' : '0';
                    chatTitle.textContent = item.dataset.title;
                    manageParticipants.classList.toggle('hidden', currentChatType !== 'group');
                    pinChat.classList.toggle('hidden', currentChatType !== 'group');
                    manageParticipants.href = `/teacher/manage_participants.php?chat_session_id=${currentChatId}`;
                    chatMessages.innerHTML = '';
                    document.querySelector('.md\\:grid-cols-\\[30\\%_70\\%\\]').classList.add('md:grid-cols-[0_100%]');
                    document.querySelector('.md\\:grid-cols-\\[30\\%_70\\%\\] > section:first-child').classList.add('hidden', 'md:hidden');
                    fetchMessages();
                    startPolling();
                });
            });

            // Back to chat list (mobile)
            backToChats.addEventListener('click', () => {
                document.querySelector('.md\\:grid-cols-\\[30\\%_70\\%\\]').classList.remove('md:grid-cols-[0_100%]');
                document.querySelector('.md\\:grid-cols-\\[30\\%_70\\%\\] > section:first-child').classList.remove('hidden', 'md:hidden');
                clearInterval(pollingInterval);
                chatMessages.innerHTML = '';
                chatTitle.textContent = 'Select a chat';
                currentChatId = null;
                currentChatType = null;
                replyPreview.classList.add('hidden');
                replyToIdInput.value = '';
            });

            // Pin chat
            pinChat.addEventListener('click', async () => {
                if (currentChatType !== 'group') return;
                try {
                    const response = await fetch('/teacher/manage_chats.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=pin_chat&chat_session_id=${currentChatId}&csrf_token=${encodeURIComponent('<?= $csrf_token ?>')}`
                    });
                    if (response.ok) {
                        location.reload(); // Refresh to update pinned status
                    }
                } catch (error) {
                    console.error('Error pinning chat:', error);
                }
            });

            // Fetch messages
            async function fetchMessages() {
                if (!currentChatId) return;
                const now = Date.now();
                if (now - lastFetchTime < 2000) return; // Rate limit to 2 seconds
                lastFetchTime = now;

                try {
                    const response = await fetch(`/teacher/manage_chats.php?fetch_messages=1&chat_session_id=${currentChatId}&is_private=${isPrivateInput.value}`);
                    const data = await response.json();
                    const messages = Array.isArray(data) ? data : data.messages || [];
                    const typingUsers = data.typing_users || [];

                    // Update typing indicator
                    typingIndicator.classList.toggle('hidden', !typingUsers.length);
                    typingIndicator.textContent = typingUsers.length ? `${typingUsers.join(', ')} ${typingUsers.length > 1 ? 'are' : 'is'} typing...` : '';

                    const currentMessages = new Set(Array.from(chatMessages.children).map(m => m.dataset.messageId));
                    messages.forEach(msg => {
                        if (!currentMessages.has(msg.id.toString())) {
                            const messageDiv = document.createElement('div');
                            messageDiv.className = `chat-message p-3 rounded-lg mb-2 animate-fade-in ${msg.user_id == <?= $teacher_id ?> ? 'ml-auto bg-orange-500 text-white' : 'mr-auto bg-gray-700 text-white'} max-w-[70%]`;
                            messageDiv.dataset.messageId = msg.id;
                            messageDiv.innerHTML = `
                                <div class="flex items-center mb-1">
                                    <img src="${msg.profile_picture || '/images/default-avatar.png'}" class="w-6 h-6 rounded-full mr-2" alt="User avatar">
                                    <span class="font-semibold">${msg.name}</span>
                                </div>
                                ${msg.reply_to_id ? `
                                    <div class="bg-gray-800 p-2 rounded-lg mb-2 text-sm opacity-80 cursor-pointer" data-reply-id="${msg.reply_to_id}">
                                        <span>Replying to: ${getMessagePreview(msg.reply_to_id)}</span>
                                    </div>
                                ` : ''}
                                <p>${msg.message || ''}</p>
                                ${msg.message_media ? `
                                    ${msg.message_media.match(/\.(jpg|jpeg|png|gif)$/i) ? 
                                        `<img src="${msg.message_media}" class="max-w-xs rounded-lg mt-2 cursor-pointer" onclick="window.open('${msg.message_media}', '_blank')" alt="Chat media">` : 
                                        msg.message_media.match(/\.(mp4|webm|ogg)$/i) ? 
                                        `<video src="${msg.message_media}" controls class="max-w-xs rounded-lg mt-2"></video>` : 
                                        `<a href="${msg.message_media}" class="text-orange-400 hover:text-orange-500 mt-2 block" target="_blank">Download File</a>`}
                                ` : ''}
                                <div class="text-xs text-gray-400 mt-1 flex justify-between">
                                    <span>${new Date(msg.created_at).toLocaleTimeString()}</span>
                                    ${msg.user_id == <?= $teacher_id ?> ? 
                                        `<span>${msg.read_status === 'read' ? '<i class="fas fa-check-double text-blue-400"></i>' : '<i class="fas fa-check"></i>'}</span>` : 
                                        `<button class="reply-btn text-orange-400 hover:text-orange-500" data-message-id="${msg.id}" data-message-text="${msg.message || 'Media'}">Reply</button>`}
                                    ${msg.user_id == <?= $teacher_id ?> ? 
                                        `<button class="delete-btn text-red-400 hover:text-red-500 ml-2" data-message-id="${msg.id}">Delete</button>` : ''}
                                </div>
                            `;
                            chatMessages.appendChild(messageDiv);
                            chatMessages.scrollTop = chatMessages.scrollHeight;
                        }
                    });
                } catch (error) {
                    console.error('Error fetching messages:', error);
                }
            }

            // Get message preview for replies
            function getMessagePreview(messageId) {
                const message = Array.from(chatMessages.children).find(m => m.dataset.messageId == messageId);
                if (message) {
                    const text = message.querySelector('p')?.textContent || 'Media';
                    return text.substring(0, 50) + (text.length > 50 ? '...' : '');
                }
                return 'Message';
            }

            // Polling for new messages
            function startPolling() {
                clearInterval(pollingInterval);
                pollingInterval = setInterval(fetchMessages, 5000);
            }

            // Typing indicator
            document.getElementById('message-input').addEventListener('input', () => {
                if (!currentChatId) return;
                clearTimeout(typingTimeout);
                fetch('/teacher/manage_chats.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `action=typing&chat_session_id=${currentChatId}&is_private=${isPrivateInput.value}&csrf_token=${encodeURIComponent('<?= $csrf_token ?>')}`
                });
                typingTimeout = setTimeout(() => {
                    fetch('/teacher/manage_chats.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=stop_typing&chat_session_id=${currentChatId}&is_private=${isPrivateInput.value}&csrf_token=${encodeURIComponent('<?= $csrf_token ?>')}`
                    });
                }, 5000);
            });

            // Reply to message
            chatMessages.addEventListener('click', (e) => {
                if (e.target.classList.contains('reply-btn')) {
                    const messageId = e.target.dataset.messageId;
                    const messageText = e.target.dataset.messageText;
                    replyToIdInput.value = messageId;
                    replyText.textContent = messageText.substring(0, 50) + (messageText.length > 50 ? '...' : '');
                    replyPreview.classList.remove('hidden');
                }
                if (e.target.classList.contains('delete-btn')) {
                    const messageId = e.target.dataset.messageId;
                    if (confirm('Delete this message?')) {
                        const formData = new FormData();
                        formData.append('action', 'delete_message');
                        formData.append('message_id', messageId);
                        formData.append('is_private', isPrivateInput.value);
                        formData.append('csrf_token', '<?= $csrf_token ?>');
                        fetch('/teacher/manage_chats.php', {
                            method: 'POST',
                            body: formData
                        }).then(() => {
                            e.target.closest('.chat-message').remove();
                        });
                    }
                }
            });

            cancelReply.addEventListener('click', () => {
                replyPreview.classList.add('hidden');
                replyToIdInput.value = '';
            });

            // Search and filter chats
            function filterChats() {
                const searchTerm = chatSearch.value.toLowerCase();
                const classId = classFilter.value;
                const termId = termFilter.value;
                const role = roleFilter.value;
                document.querySelectorAll('.chat-item').forEach(item => {
                    const title = item.dataset.title.toLowerCase();
                    const matchesSearch = title.includes(searchTerm);
                    const matchesClass = !classId || item.dataset.classId === classId;
                    const matchesTerm = !termId || item.dataset.termId === termId;
                    const matchesRole = !role || (item.dataset.type === 'private' && item.dataset.title.toLowerCase().includes(role));
                    item.style.display = matchesSearch && matchesClass && matchesTerm && matchesRole ? 'flex' : 'none';
                });
            }

            chatSearch.addEventListener('input', filterChats);
            classFilter.addEventListener('change', filterChats);
            termFilter.addEventListener('change', filterChats);
            roleFilter.addEventListener('change', filterChats);

            // Form submission
            messageForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                if (!currentChatId) return;
                loadingOverlay.classList.remove('hidden');
                try {
                    const formData = new FormData(messageForm);
                    formData.append('chat_title', chatTitle.textContent);
                    const response = await fetch('/teacher/manage_chats.php', {
                        method: 'POST',
                        body: formData
                    });
                    if (response.ok) {
                        document.getElementById('message-input').value = '';
                        document.querySelector('input[name="message_media"]').value = '';
                        replyPreview.classList.add('hidden');
                        replyToIdInput.value = '';
                        fetchMessages();
                    }
                } catch (error) {
                    console.error('Error sending message:', error);
                } finally {
                    loadingOverlay.classList.add('hidden');
                }
            });
        });
    </script>
</body>
</html>