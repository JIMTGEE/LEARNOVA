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

// Handle form submission to start new chat
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token.';
        header("Location: start_new_chat.php");
        exit();
    }

    $chat_type = $_POST['chat_type'] ?? '';
    $title = trim($_POST['title'] ?? '');
    $recipients = $_POST['recipients'] ?? []; // Array of user_ids
    $role = $_POST['role'] ?? ''; // For broadcast

    if (!in_array($chat_type, ['private', 'group', 'broadcast']) || (empty($recipients) && $chat_type !== 'broadcast') || ($chat_type === 'broadcast' && empty($role))) {
        $_SESSION['error'] = 'Invalid input. Please select valid options.';
        header("Location: start_new_chat.php");
        exit();
    }

    if ($chat_type === 'broadcast') {
        // Fetch users by role
        $stmt = $db->prepare("SELECT id FROM users WHERE role = ?");
        $stmt->execute([$role]);
        $recipients = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'id');
        $title = "Broadcast to " . ucfirst($role) . "s";
    }

    if (empty($recipients)) {
        $_SESSION['error'] = 'No recipients selected.';
        header("Location: start_new_chat.php");
        exit();
    }

    // Create chat session
    $session_token = bin2hex(random_bytes(32));
    $is_real_time = 1;
    $status = 'active';

    $stmt = $db->prepare("INSERT INTO chat_sessions (session_token, title, status, is_real_time, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())");
    $stmt->execute([$session_token, $title, $status, $is_real_time]);
    $session_id = $db->lastInsertId();

    // Add admin as participant
    $part_stmt = $db->prepare("INSERT INTO chat_participants (chat_session_id, user_id, joined_at) VALUES (?, ?, NOW())");
    $part_stmt->execute([$session_id, $_SESSION['user_id']]);

    // Add recipients
    foreach ($recipients as $user_id) {
        if ($user_id != $_SESSION['user_id']) { // Avoid duplicate
            $part_stmt->execute([$session_id, $user_id]);
        }
    }

    log_activity($_SESSION['user_id'], 'start_chat', "Started new chat session ID: $session_id of type $chat_type");

    // Redirect to chat interface
    $_SESSION['success'] = 'New chat started successfully.';
    header("Location: chat.php?session_id=$session_id");
    exit();
}

// Fetch users for selection
$users_stmt = $db->query("SELECT id, name, role FROM users WHERE role IN ('teacher', 'student', 'parent') ORDER BY name ASC");
$users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group users by role for easier selection
$teachers = array_filter($users, fn($u) => $u['role'] === 'teacher');
$students = array_filter($users, fn($u) => $u['role'] === 'student');
$parents = array_filter($users, fn($u) => $u['role'] === 'parent');

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="start-new-chat">
        <h1 class="page-title">Start New Chat</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <form method="POST" class="chat-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <label for="chat_type">Chat Type:</label>
            <select name="chat_type" id="chat_type" required onchange="toggleFields(this.value)">
                <option value="">Select Type</option>
                <option value="private">Private (1-on-1)</option>
                <option value="group">Group</option>
                <option value="broadcast">Broadcast (to Role)</option>
            </select>

            <div id="title-field" style="display: none;">
                <label for="title">Chat Title (for Group/Broadcast):</label>
                <input type="text" name="title" id="title" placeholder="Enter chat title">
            </div>

            <div id="recipients-field" style="display: none;">
                <label>Select Recipients:</label>
                <input type="text" id="user-search" placeholder="Search users by name...">
                <div class="user-list">
                    <h3>Teachers</h3>
                    <?php foreach ($teachers as $user): ?>
                        <label><input type="checkbox" name="recipients[]" value="<?php echo $user['id']; ?>"> <?php echo htmlspecialchars($user['name']); ?></label>
                    <?php endforeach; ?>

                    <h3>Students</h3>
                    <?php foreach ($students as $user): ?>
                        <label><input type="checkbox" name="recipients[]" value="<?php echo $user['id']; ?>"> <?php echo htmlspecialchars($user['name']); ?></label>
                    <?php endforeach; ?>

                    <h3>Parents</h3>
                    <?php foreach ($parents as $user): ?>
                        <label><input type="checkbox" name="recipients[]" value="<?php echo $user['id']; ?>"> <?php echo htmlspecialchars($user['name']); ?></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="role-field" style="display: none;">
                <label for="role">Select Role for Broadcast:</label>
                <select name="role" id="role">
                    <option value="teacher">Teachers</option>
                    <option value="student">Students</option>
                    <option value="parent">Parents</option>
                </select>
            </div>

            <button type="submit">Start Chat</button>
        </form>
    </section>

    <script>
        function toggleFields(type) {
            document.getElementById('title-field').style.display = (type === 'group' || type === 'broadcast') ? 'block' : 'none';
            document.getElementById('recipients-field').style.display = (type === 'private' || type === 'group') ? 'block' : 'none';
            document.getElementById('role-field').style.display = (type === 'broadcast') ? 'block' : 'none';

            // For private, limit to one checkbox
            const checkboxes = document.querySelectorAll('input[name="recipients[]"]');
            checkboxes.forEach(cb => {
                cb.disabled = false;
            });
            if (type === 'private') {
                checkboxes.forEach(cb => {
                    cb.type = 'radio';
                    cb.name = 'recipients[]'; // Still array for consistency
                });
            } else {
                checkboxes.forEach(cb => {
                    cb.type = 'checkbox';
                });
            }
        }

        // User search filter
        document.getElementById('user-search').addEventListener('input', function() {
            const query = this.value.toLowerCase();
            document.querySelectorAll('.user-list label').forEach(label => {
                label.style.display = label.textContent.toLowerCase().includes(query) ? 'block' : 'none';
            });
        });
    </script>

    <style>
        /* Start New Chat Styles */
        .start-new-chat { padding: 20px; max-width: 800px; margin: 0 auto; }
        .chat-form { background: var(--primary-white); padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .chat-form label { display: block; margin-bottom: 8px; font-weight: 500; }
        .chat-form select, .chat-form input[type="text"] { width: 100%; padding: 10px; margin-bottom: 16px; border: 1px solid var(--accent-slate-gray); border-radius: 4px; }
        .user-list { max-height: 300px; overflow-y: auto; padding: 10px; background: #f8f9fa; border-radius: 4px; }
        .user-list h3 { margin-top: 10px; }
        .user-list label { display: block; }
        .chat-form button { background: var(--primary-sea-blue); color: var(--primary-white); padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; }
        .alert { padding: 10px; margin-bottom: 20px; border-radius: 4px; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.error { background: #f8d7da; color: #721c24; }
    </style>

<?php include '../templates/admin_footer.php'; ?>