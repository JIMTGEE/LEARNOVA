<?php
// Start the session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the user is logged in and has an admin role
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    error_log("Redirecting to login: user_id not set or role not admin. Role: " . ($_SESSION['user_role'] ?? 'not set'));
    header("Location: ../login.php");
    exit();
}

// Include database connection
require_once '../config/db.php';
$db = db(); // Use the helper function to get PDO instance

// Include helpers and functions
require_once '../includes/helpers.php';
require_once '../functions/csrf.php';
require_once '../functions/log_activity.php';

// Get session_id from GET
$session_id = intval($_GET['session_id'] ?? 0);
if ($session_id <= 0) {
    $_SESSION['error'] = 'Invalid chat session.';
    header("Location: dashboard.php"); // Redirect to dashboard or chat list
    exit();
}

// Fetch chat session details
$stmt = $db->prepare("SELECT * FROM chat_sessions WHERE id = ? AND status = 'active'");
$stmt->execute([$session_id]);
$chat_session = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$chat_session) {
    $_SESSION['error'] = 'Chat session not found or inactive.';
    header("Location: dashboard.php");
    exit();
}

// Check if admin is a participant
$part_stmt = $db->prepare("SELECT * FROM chat_participants WHERE chat_session_id = ? AND user_id = ?");
$part_stmt->execute([$session_id, $_SESSION['user_id']]);
if (!$part_stmt->fetch()) {
    $_SESSION['error'] = 'You are not a participant in this chat.';
    header("Location: dashboard.php");
    exit();
}

// Fetch participants
$part_stmt = $db->prepare("SELECT u.id, u.name, u.profile_picture FROM chat_participants cp JOIN users u ON cp.user_id = u.id WHERE cp.chat_session_id = ?");
$part_stmt->execute([$session_id]);
$participants = $part_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle message sending (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    $message = trim($_POST['message'] ?? '');
    $media = $_FILES['media'] ?? null;

    if (empty($message) && !$media) {
        echo json_encode(['success' => false, 'message' => 'Message or media required.']);
        exit();
    }

    $media_path = null;
    if ($media && $media['error'] === 0) {
        // Validate and upload media
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'video/mp4', 'audio/mpeg'];
        if (in_array($media['type'], $allowed_types) && $media['size'] <= 10 * 1024 * 1024) { // 10MB limit
            $upload_dir = '../Uploads/chat/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $media_path = $upload_dir . bin2hex(random_bytes(8)) . '_' . basename($media['name']);
            move_uploaded_file($media['tmp_name'], $media_path);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid media type or size.']);
            exit();
        }
    }

    // Insert message
    $stmt = $db->prepare("INSERT INTO chat_messages (chat_session_id, user_id, message, message_media, read_status, created_at, updated_at) VALUES (?, ?, ?, ?, 'unread', NOW(), NOW())");
    $stmt->execute([$session_id, $_SESSION['user_id'], $message, $media_path]);

    log_activity($_SESSION['user_id'], 'send_chat_message', "Sent message in chat session ID: $session_id");

    echo json_encode(['success' => true, 'message' => 'Message sent.']);
    exit();
}

// Handle typing indicator (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'typing') {
    // Update last_activity or a typing flag (simplified: update last_activity)
    $stmt = $db->prepare("UPDATE chat_messages SET last_activity = NOW() WHERE chat_session_id = ? AND user_id = ?");
    $stmt->execute([$session_id, $_SESSION['user_id']]);
    echo json_encode(['success' => true]);
    exit();
}

// Handle fetch messages (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'fetch_messages') {
    $last_message_id = intval($_GET['last_id'] ?? 0);
    $stmt = $db->prepare("SELECT cm.*, u.name, u.profile_picture FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.chat_session_id = ? AND cm.id > ? ORDER BY cm.created_at ASC");
    $stmt->execute([$session_id, $last_message_id]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mark as read for admin
    $update_stmt = $db->prepare("UPDATE chat_messages SET read_status = 'read' WHERE chat_session_id = ? AND user_id != ? AND read_status = 'unread'");
    $update_stmt->execute([$session_id, $_SESSION['user_id']]);

    // Fetch typing users (simplified: users with last_activity in last 5s)
    $typing_stmt = $db->prepare("SELECT DISTINCT u.name FROM chat_messages cm JOIN users u ON cm.user_id = u.id WHERE cm.chat_session_id = ? AND cm.user_id != ? AND cm.last_activity > DATE_SUB(NOW(), INTERVAL 5 SECOND)");
    $typing_stmt->execute([$session_id, $_SESSION['user_id']]);
    $typing_users = array_column($typing_stmt->fetchAll(PDO::FETCH_ASSOC), 'name');

    echo json_encode(['messages' => $messages, 'typing' => $typing_users]);
    exit();
}

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="chat-interface">
        <h1 class="chat-title"><?php echo htmlspecialchars($chat_session['title'] ?? 'Chat'); ?></h1>

        <div class="participants-list">
            <h3>Participants:</h3>
            <?php foreach ($participants as $part): ?>
                <div class="participant">
                    <img src="<?php echo htmlspecialchars($part['profile_picture'] ?? '/assets/images/default-profile.png'); ?>" alt="Profile" class="profile-pic">
                    <span><?php echo htmlspecialchars($part['name']); ?></span>
                </div>
            <?php endforeach; ?>
        </div>

        <div id="chat-messages" class="chat-messages">
            <!-- Messages loaded via JS -->
        </div>

        <div id="typing-indicator" class="typing-indicator"></div>

        <form id="chat-form" class="chat-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="send_message">
            <textarea name="message" id="message-input" placeholder="Type your message..." rows="2"></textarea>
            <div class="chat-actions">
                <label for="media-upload" class="action-btn"><i class="fas fa-paperclip"></i></label>
                <input type="file" id="media-upload" name="media" accept="image/*,video/mp4,audio/mpeg" style="display: none;">
                <button type="button" id="emoji-btn" class="action-btn"><i class="fas fa-smile"></i></button>
                <button type="submit" class="send-btn"><i class="fas fa-paper-plane"></i> Send</button>
            </div>
        </form>

        <!-- Emoji Picker (using emoji-mart or simple JS) -->
        <div id="emoji-picker" style="display: none;"></div>
    </section>

    <script>
        const sessionId = <?php echo $session_id; ?>;
        let lastMessageId = 0;
        let pollingInterval;

        // Fetch messages periodically
        function fetchMessages() {
            fetch(`chat.php?action=fetch_messages&session_id=${sessionId}&last_id=${lastMessageId}`)
                .then(response => response.json())
                .then(data => {
                    data.messages.forEach(msg => {
                        appendMessage(msg);
                        lastMessageId = Math.max(lastMessageId, msg.id);
                    });
                    // Typing indicator
                    const typing = data.typing;
                    document.getElementById('typing-indicator').innerHTML = typing.length > 0 ? `${typing.join(', ')} ${typing.length > 1 ? 'are' : 'is'} typing...` : '';
                    scrollToBottom();
                })
                .catch(err => console.error('Error fetching messages:', err));
        }

        // Append message to chat
        function appendMessage(msg) {
            const chat = document.getElementById('chat-messages');
            const isMe = msg.user_id === <?php echo $_SESSION['user_id']; ?>;
            const msgDiv = document.createElement('div');
            msgDiv.classList.add('message', isMe ? 'sent' : 'received');
            msgDiv.innerHTML = `
                <img src="${msg.profile_picture || '/assets/images/default-profile.png'}" alt="Profile" class="msg-profile">
                <div class="msg-content">
                    <span class="msg-name">${msg.name}</span>
                    <p>${msg.message.replace(/\n/g, '<br>')}</p>
                    ${msg.message_media ? getMediaHTML(msg.message_media) : ''}
                    <span class="msg-time">${new Date(msg.created_at).toLocaleTimeString()}</span>
                    <span class="msg-status">${msg.read_status === 'read' ? '<i class="fas fa-check-double"></i>' : '<i class="fas fa-check"></i>'}</span>
                </div>
            `;
            chat.appendChild(msgDiv);
        }

        // Get media HTML
        function getMediaHTML(path) {
            const ext = path.split('.').pop().toLowerCase();
            if (['jpg', 'jpeg', 'png', 'gif'].includes(ext)) {
                return `<img src="${path}" alt="Image" class="msg-media">`;
            } else if (ext === 'mp4') {
                return `<video src="${path}" controls class="msg-media"></video>`;
            } else if (ext === 'mp3') {
                return `<audio src="${path}" controls class="msg-media"></audio>`;
            }
            return '';
        }

        // Scroll to bottom
        function scrollToBottom() {
            const chat = document.getElementById('chat-messages');
            chat.scrollTop = chat.scrollHeight;
        }

        // Send message
        document.getElementById('chat-form').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            fetch('chat.php?session_id=' + sessionId, {
                method: 'POST',
                body: formData
            }).then(response => response.json())
              .then(data => {
                  if (data.success) {
                      this.reset(); // Clear form
                      fetchMessages(); // Refresh
                  } else {
                      alert(data.message);
                  }
              }).catch(err => console.error('Error sending message:', err));
        });

        // Typing indicator
        const messageInput = document.getElementById('message-input');
        let typingTimeout;
        messageInput.addEventListener('input', () => {
            clearTimeout(typingTimeout);
            fetch('chat.php?session_id=' + sessionId, {
                method: 'POST',
                body: new URLSearchParams({ action: 'typing', csrf_token: '<?php echo $csrf_token; ?>' })
            });
            typingTimeout = setTimeout(() => {
                // Stop typing after 3s inactivity
            }, 3000);
        });

        // Emoji button (simple picker, or integrate emoji-mart)
        document.getElementById('emoji-btn').addEventListener('click', () => {
            // Placeholder: Insert emoji to input
            const emojis = ['😀', '😂', '❤️', '👍', '🔥'];
            const picker = document.getElementById('emoji-picker');
            picker.innerHTML = emojis.map(e => `<span class="emoji" onclick="insertEmoji('${e}')">${e}</span>`).join('');
            picker.style.display = 'block';
        });

        function insertEmoji(emoji) {
            messageInput.value += emoji;
            document.getElementById('emoji-picker').style.display = 'none';
        }

        // Initial load and polling
        fetchMessages();
        pollingInterval = setInterval(fetchMessages, 2000); // Poll every 2s

        // Stop polling on unload
        window.addEventListener('beforeunload', () => clearInterval(pollingInterval));
    </script>

    <style>
        /* Chat Interface Styles - Ultra Modern, Better than FB/WA */
        .chat-interface {
            display: flex;
            flex-direction: column;
            height: 100vh;
            max-width: 800px;
            margin: 0 auto;
            background: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .chat-title {
            padding: 16px;
            background: var(--primary-sea-blue);
            color: var(--primary-white);
            text-align: center;
            font-size: 24px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .participants-list {
            padding: 16px;
            border-bottom: 1px solid var(--accent-slate-gray);
            overflow-x: auto;
            white-space: nowrap;
        }

        .participant {
            display: inline-flex;
            align-items: center;
            margin-right: 16px;
        }

        .profile-pic {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            margin-right: 8px;
        }

        .chat-messages {
            flex: 1;
            padding: 16px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }

        .message {
            max-width: 70%;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
        }

        .message.sent {
            align-self: flex-end;
            flex-direction: row-reverse;
        }

        .msg-profile {
            width: 40px;
            height: 40px;
            border-radius: 50%;
        }

        .msg-content {
            padding: 12px;
            border-radius: 20px;
            background: var(--accent-soft-cyan);
            color: var(--primary-black);
        }

        .message.sent .msg-content {
            background: var(--primary-sea-blue);
            color: var(--primary-white);
        }

        .msg-name {
            font-weight: bold;
            font-size: 14px;
        }

        .msg-time {
            font-size: 12px;
            color: var(--accent-slate-gray);
        }

        .msg-status {
            margin-left: 8px;
        }

        .msg-media {
            max-width: 100%;
            border-radius: 8px;
            margin-top: 8px;
        }

        .typing-indicator {
            padding: 8px 16px;
            font-style: italic;
            color: var(--accent-slate-gray);
            background: #f0f0f0;
        }

        .chat-form {
            display: flex;
            flex-direction: column;
            padding: 16px;
            border-top: 1px solid var(--accent-slate-gray);
            position: sticky;
            bottom: 0;
            background: var(--primary-white);
        }

        #message-input {
            resize: none;
            border: 1px solid var(--accent-slate-gray);
            border-radius: 20px;
            padding: 12px;
            margin-bottom: 8px;
        }

        .chat-actions {
            display: flex;
            align-items: center;
        }

        .action-btn {
            background: none;
            border: none;
            font-size: 20px;
            margin-right: 16px;
            cursor: pointer;
            color: var(--primary-sea-blue);
        }

        .send-btn {
            background: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 8px 16px;
            border-radius: 20px;
            border: none;
            cursor: pointer;
        }

        #emoji-picker {
            position: absolute;
            bottom: 60px;
            background: white;
            padding: 8px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .emoji {
            font-size: 24px;
            margin: 4px;
            cursor: pointer;
        }

        /* Animations */
        .message { animation: fadeIn 0.3s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>

<?php include '../templates/admin_footer.php'; ?>