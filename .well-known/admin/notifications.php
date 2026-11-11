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
// Handle POST actions: delete, bulk actions for notifications, chat sessions, messages with enhanced validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: notifications.php");
        exit();
    }
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'delete_notification':
                $notification_id = intval($_POST['notification_id'] ?? 0);
                if ($notification_id > 0) {
                    $stmt = $db->prepare("DELETE FROM notifications WHERE id = ?");
                    $stmt->execute([$notification_id]);
                    log_activity($_SESSION['user_id'], 'delete_notification', "Deleted notification ID: $notification_id");
                    $_SESSION['success'] = 'Notification deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid notification ID.';
                }
                break;
            case 'bulk_delete_notifications':
                if (isset($_POST['notification_ids']) && is_array($_POST['notification_ids'])) {
                    $notification_ids = array_filter(array_map('intval', $_POST['notification_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($notification_ids) {
                        $placeholders = implode(',', array_fill(0, count($notification_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM notifications WHERE id IN ($placeholders)");
                        $stmt->execute($notification_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_notifications', "Deleted notifications: " . implode(',', $notification_ids));
                        $_SESSION['success'] = 'Selected notifications deleted.';
                    }
                }
                break;
            case 'delete_chat_session':
                $session_id = intval($_POST['session_id'] ?? 0);
                if ($session_id > 0) {
                    $stmt = $db->prepare("DELETE FROM private_chat_sessions WHERE id = ?");
                    $stmt->execute([$session_id]);
                    log_activity($_SESSION['user_id'], 'delete_chat_session', "Deleted chat session ID: $session_id");
                    $_SESSION['success'] = 'Chat session deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid chat session ID.';
                }
                break;
            case 'bulk_delete_chat_sessions':
                if (isset($_POST['session_ids']) && is_array($_POST['session_ids'])) {
                    $session_ids = array_filter(array_map('intval', $_POST['session_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($session_ids) {
                        $placeholders = implode(',', array_fill(0, count($session_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM private_chat_sessions WHERE id IN ($placeholders)");
                        $stmt->execute($session_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_chat_sessions', "Deleted chat sessions: " . implode(',', $session_ids));
                        $_SESSION['success'] = 'Selected chat sessions deleted.';
                    }
                }
                break;
            case 'delete_message':
                $message_id = intval($_POST['message_id'] ?? 0);
                if ($message_id > 0) {
                    $stmt = $db->prepare("DELETE FROM private_chat_messages WHERE id = ?");
                    $stmt->execute([$message_id]);
                    log_activity($_SESSION['user_id'], 'delete_message', "Deleted message ID: $message_id");
                    $_SESSION['success'] = 'Message deleted successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid message ID.';
                }
                break;
            case 'bulk_delete_messages':
                if (isset($_POST['message_ids']) && is_array($_POST['message_ids'])) {
                    $message_ids = array_filter(array_map('intval', $_POST['message_ids']), function($id) {
                        return $id > 0;
                    });
                    if ($message_ids) {
                        $placeholders = implode(',', array_fill(0, count($message_ids), '?'));
                        $stmt = $db->prepare("DELETE FROM private_chat_messages WHERE id IN ($placeholders)");
                        $stmt->execute($message_ids);
                        log_activity($_SESSION['user_id'], 'bulk_delete_messages', "Deleted messages: " . implode(',', $message_ids));
                        $_SESSION['success'] = 'Selected messages deleted.';
                    }
                }
                break;
            // Enhanced: Add bulk export actions (simulate with session flag for now; actual export in separate script)
            case 'bulk_export_notifications':
                $_SESSION['export_type'] = 'notifications';
                $_SESSION['export_ids'] = $_POST['notification_ids'] ?? [];
                header("Location: export_communications.php");
                exit();
                break;
            case 'bulk_export_chat_sessions':
                $_SESSION['export_type'] = 'chat_sessions';
                $_SESSION['export_ids'] = $_POST['session_ids'] ?? [];
                header("Location: export_communications.php");
                exit();
                break;
            case 'bulk_export_messages':
                $_SESSION['export_type'] = 'messages';
                $_SESSION['export_ids'] = $_POST['message_ids'] ?? [];
                header("Location: export_communications.php");
                exit();
                break;
        }
        header("Location: notifications.php");
        exit();
    }
}
// Define pagination limit
$limit = 20;
// Fetch notifications with search, filters, sorting, and pagination
$notification_search = isset($_GET['notification_search']) ? '%' . trim($_GET['notification_search']) . '%' : '%';
$notification_type_filter = isset($_GET['notification_type']) ? $_GET['notification_type'] : '';
$notification_read_filter = isset($_GET['notification_read']) ? intval($_GET['notification_read']) : -1;
$notification_sort = isset($_GET['notification_sort']) ? $_GET['notification_sort'] : 'created_at';
$notification_order = isset($_GET['notification_order']) ? strtoupper($_GET['notification_order']) : 'DESC';
$notification_page = max(1, intval($_GET['notification_page'] ?? 1));
$notification_offset = ($notification_page - 1) * $limit;
$notification_query = "SELECT n.*, u.name AS user_name FROM notifications n LEFT JOIN users u ON n.user_id = u.id WHERE (n.title LIKE ? OR n.message LIKE ?)";
$notification_params = [$notification_search, $notification_search];
if ($notification_type_filter) {
    $notification_query .= " AND n.type = ?";
    $notification_params[] = $notification_type_filter;
}
if ($notification_read_filter >= 0) {
    $notification_query .= " AND n.is_read = ?";
    $notification_params[] = $notification_read_filter;
}
$notification_query .= " ORDER BY $notification_sort $notification_order LIMIT ? OFFSET ?";
$notification_params[] = $limit;
$notification_params[] = $notification_offset;
$stmt = $db->prepare($notification_query);
$stmt->execute($notification_params);
$notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for notifications pagination
$notification_count_query = "SELECT COUNT(*) FROM notifications WHERE (title LIKE ? OR message LIKE ?)";
$notification_count_params = [$notification_search, $notification_search];
if ($notification_type_filter) {
    $notification_count_query .= " AND type = ?";
    $notification_count_params[] = $notification_type_filter;
}
if ($notification_read_filter >= 0) {
    $notification_count_query .= " AND is_read = ?";
    $notification_count_params[] = $notification_read_filter;
}
$notification_count_stmt = $db->prepare($notification_count_query);
$notification_count_stmt->execute($notification_count_params);
$total_notifications = $notification_count_stmt->fetchColumn() ?? 0;
$total_notification_pages = ceil($total_notifications / $limit);
// Fetch private chat sessions with search, filters, sorting, pagination
$chat_search = isset($_GET['chat_search']) ? '%' . trim($_GET['chat_search']) . '%' : '%';
$chat_status_filter = isset($_GET['chat_status']) ? $_GET['chat_status'] : '';
$chat_sort = isset($_GET['chat_sort']) ? $_GET['chat_sort'] : 'start_time';
$chat_order = isset($_GET['chat_order']) ? strtoupper($_GET['chat_order']) : 'DESC';
$chat_page = max(1, intval($_GET['chat_page'] ?? 1));
$chat_offset = ($chat_page - 1) * $limit;
$chat_query = "SELECT pcs.*, us.name AS sender_name, ur.name AS recipient_name FROM private_chat_sessions pcs JOIN users us ON pcs.sender_id = us.id JOIN users ur ON pcs.recipient_id = ur.id WHERE pcs.title LIKE ?";
$chat_params = [$chat_search];
if ($chat_status_filter && in_array($chat_status_filter, ['active', 'closed'])) {
    $chat_query .= " AND pcs.status = ?";
    $chat_params[] = $chat_status_filter;
}
$chat_query .= " ORDER BY $chat_sort $chat_order LIMIT ? OFFSET ?";
$chat_params[] = $limit;
$chat_params[] = $chat_offset;
$stmt = $db->prepare($chat_query);
$stmt->execute($chat_params);
$chat_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for chat sessions
$chat_count_query = "SELECT COUNT(*) FROM private_chat_sessions WHERE title LIKE ?";
$chat_count_params = [$chat_search];
if ($chat_status_filter) {
    $chat_count_query .= " AND status = ?";
    $chat_count_params[] = $chat_status_filter;
}
$chat_count_stmt = $db->prepare($chat_count_query);
$chat_count_stmt->execute($chat_count_params);
$total_chats = $chat_count_stmt->fetchColumn() ?? 0;
$total_chat_pages = ceil($total_chats / $limit);
// Fetch chat messages with search, sorting, pagination (upgraded from recent to full section)
$message_search = isset($_GET['message_search']) ? '%' . trim($_GET['message_search']) . '%' : '%';
$message_read_filter = isset($_GET['message_read']) ? $_GET['message_read'] : '';
$message_sort = isset($_GET['message_sort']) ? $_GET['message_sort'] : 'created_at';
$message_order = isset($_GET['message_order']) ? strtoupper($_GET['message_order']) : 'DESC';
$message_page = max(1, intval($_GET['message_page'] ?? 1));
$message_offset = ($message_page - 1) * $limit;
$message_query = "SELECT pcm.*, u.name AS user_name FROM private_chat_messages pcm JOIN users u ON pcm.user_id = u.id WHERE pcm.message LIKE ?";
$message_params = [$message_search];
if ($message_read_filter) {
    $message_query .= " AND pcm.read_status = ?";
    $message_params[] = $message_read_filter;
}
$message_query .= " ORDER BY $message_sort $message_order LIMIT ? OFFSET ?";
$message_params[] = $limit;
$message_params[] = $message_offset;
$stmt = $db->prepare($message_query);
$stmt->execute($message_params);
$chat_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Total count for messages
$message_count_query = "SELECT COUNT(*) FROM private_chat_messages WHERE message LIKE ?";
$message_count_params = [$message_search];
if ($message_read_filter) {
    $message_count_query .= " AND read_status = ?";
    $message_count_params[] = $message_read_filter;
}
$message_count_stmt = $db->prepare($message_count_query);
$message_count_stmt->execute($message_count_params);
$total_messages = $message_count_stmt->fetchColumn() ?? 0;
$total_message_pages = ceil($total_messages / $limit);
// Fetch enhanced KPIs
$unread_notifications = $db->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn() ?? 0;
$active_chats = $db->query("SELECT COUNT(*) FROM private_chat_sessions WHERE status = 'active'")->fetchColumn() ?? 0;
$total_notifications_sent = $db->query("SELECT COUNT(*) FROM notifications")->fetchColumn() ?? 0;
$total_messages = $db->query("SELECT COUNT(*) FROM private_chat_messages")->fetchColumn() ?? 0;
// Fetch audit logs (filtered by notification and chat-related actions, with pagination if needed)
$audit_logs = $db->query("SELECT ul.*, u.name AS actor_name FROM user_logs ul JOIN users u ON ul.user_id = u.id WHERE ul.action LIKE '%notification%' OR ul.action LIKE '%chat%' ORDER BY ul.created_at DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
// Generate CSRF token
$csrf_token = generate_csrf_token();
?>
<?php include '../templates/admin_header.php'; ?>
    <section class="notifications-management">
        <h1 class="page-title">Notifications & Communications Dashboard</h1>
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success" role="alert"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error" role="alert"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        <!-- Enhanced KPIs -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <i class="fas fa-bell icon"></i>
                <div class="value"><?php echo $unread_notifications; ?></div>
                <div class="label">Unread Notifications</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-comments icon"></i>
                <div class="value"><?php echo $active_chats; ?></div>
                <div class="label">Active Chats</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-envelope icon"></i>
                <div class="value"><?php echo $total_notifications_sent; ?></div>
                <div class="label">Total Notifications</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-comment-dots icon"></i>
                <div class="value"><?php echo $total_messages; ?></div>
                <div class="label">Total Messages</div>
            </div>
        </div>
        <!-- Quick Actions (Grid layout with enhanced buttons) -->
        <div class="quick-actions">
            <a href="send_notification.php" class="action-button"><i class="fas fa-bullhorn"></i> Send Notification</a>
            <a href="start_chat.php" class="action-button"><i class="fas fa-comment-dots"></i> Start New Chat</a>
            <a href="manage_chats.php" class="action-button"><i class="fas fa-users"></i> Manage Chat Sessions</a>
            <a href="export_communications.php" class="action-button"><i class="fas fa-download"></i> Export Data</a>
        </div>
        <!-- Notifications Section -->
        <div class="section">
            <h2>Notifications</h2>
            <!-- Enhanced Search and Filters for Notifications -->
            <form class="search-form" method="GET">
                <input type="text" name="notification_search" placeholder="Search by title or message..." value="<?php echo htmlspecialchars($_GET['notification_search'] ?? ''); ?>" aria-label="Search Notifications">
                <select name="notification_type" aria-label="Filter by Type">
                    <option value="">All Types</option>
                    <option value="info" <?php echo $notification_type_filter === 'info' ? 'selected' : ''; ?>>Info</option>
                    <!-- Add more types based on DB data -->
                </select>
                <select name="notification_read" aria-label="Filter by Read Status">
                    <option value="-1">All Read Status</option>
                    <option value="0" <?php echo $notification_read_filter === 0 ? 'selected' : ''; ?>>Unread</option>
                    <option value="1" <?php echo $notification_read_filter === 1 ? 'selected' : ''; ?>>Read</option>
                </select>
                <select name="notification_sort" aria-label="Sort By">
                    <option value="created_at" <?php echo $notification_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                    <option value="title" <?php echo $notification_sort === 'title' ? 'selected' : ''; ?>>Title</option>
                    <option value="is_read" <?php echo $notification_sort === 'is_read' ? 'selected' : ''; ?>>Read Status</option>
                </select>
                <select name="notification_order" aria-label="Order">
                    <option value="DESC" <?php echo $notification_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $notification_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Notifications Table -->
            <form method="POST" id="notifications-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-notifications" aria-label="Select All Notifications"></th>
                            <th>ID</th>
                            <th>User Name</th>
                            <th>Target Role</th>
                            <th>Title</th>
                            <th>Message</th>
                            <th>Type</th>
                            <th>Is Read</th>
                            <th>Category</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notifications as $notification): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="notification_ids[]" value="<?php echo $notification['id']; ?>" aria-label="Select Notification <?php echo $notification['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($notification['id']); ?></td>
                                <td data-label="User Name"><?php echo htmlspecialchars($notification['user_name'] ?? 'N/A'); ?></td>
                                <td data-label="Target Role"><?php echo htmlspecialchars($notification['target_role'] ?? 'N/A'); ?></td>
                                <td data-label="Title"><?php echo htmlspecialchars($notification['title']); ?></td>
                                <td data-label="Message"><?php echo htmlspecialchars(substr($notification['message'], 0, 100)); ?>...</td>
                                <td data-label="Type"><?php echo htmlspecialchars($notification['type']); ?></td>
                                <td data-label="Is Read"><?php echo $notification['is_read'] ? 'Yes' : 'No'; ?></td>
                                <td data-label="Category"><?php echo htmlspecialchars($notification['category']); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($notification['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_notification.php?id=<?php echo $notification['id']; ?>" class="action-icon edit" aria-label="Edit Notification"><i class="fas fa-edit"></i></a>
                                    <a href="view_notification.php?id=<?php echo $notification['id']; ?>" class="action-icon view" aria-label="View Notification"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this notification?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_notification">
                                        <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Notification"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Enhanced Pagination for Notifications -->
                <div class="pagination">
                    <?php if ($notification_page > 1): ?>
                        <a href="?notification_page=<?php echo $notification_page - 1; ?>&notification_search=<?php echo urlencode($_GET['notification_search'] ?? ''); ?>&notification_type=<?php echo urlencode($notification_type_filter); ?>&notification_read=<?php echo $notification_read_filter; ?>&notification_sort=<?php echo $notification_sort; ?>&notification_order=<?php echo $notification_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $notification_page; ?> of <?php echo $total_notification_pages; ?></span>
                    <?php if ($notification_page < $total_notification_pages): ?>
                        <a href="?notification_page=<?php echo $notification_page + 1; ?>&notification_search=<?php echo urlencode($_GET['notification_search'] ?? ''); ?>&notification_type=<?php echo urlencode($notification_type_filter); ?>&notification_read=<?php echo $notification_read_filter; ?>&notification_sort=<?php echo $notification_sort; ?>&notification_order=<?php echo $notification_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Enhanced Bulk Actions for Notifications -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Notifications">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_notifications">Delete Selected</option>
                        <option value="bulk_export_notifications">Export Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Chat Sessions Section -->
        <div class="section">
            <h2>Chat Sessions</h2>
            <!-- Enhanced Search and Filters for Chat Sessions -->
            <form class="search-form" method="GET">
                <input type="text" name="chat_search" placeholder="Search by title..." value="<?php echo htmlspecialchars($_GET['chat_search'] ?? ''); ?>" aria-label="Search Chat Sessions">
                <select name="chat_status" aria-label="Filter by Status">
                    <option value="">All Statuses</option>
                    <option value="active" <?php echo $chat_status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="closed" <?php echo $chat_status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
                </select>
                <select name="chat_sort" aria-label="Sort By">
                    <option value="start_time" <?php echo $chat_sort === 'start_time' ? 'selected' : ''; ?>>Start Time</option>
                    <option value="title" <?php echo $chat_sort === 'title' ? 'selected' : ''; ?>>Title</option>
                    <option value="status" <?php echo $chat_sort === 'status' ? 'selected' : ''; ?>>Status</option>
                </select>
                <select name="chat_order" aria-label="Order">
                    <option value="DESC" <?php echo $chat_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $chat_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Chat Sessions Table -->
            <form method="POST" id="chats-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-chats" aria-label="Select All Chat Sessions"></th>
                            <th>ID</th>
                            <th>Sender Name</th>
                            <th>Recipient Name</th>
                            <th>Title</th>
                            <th>Status</th>
                            <th>Start Time</th>
                            <th>End Time</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($chat_sessions as $session): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="session_ids[]" value="<?php echo $session['id']; ?>" aria-label="Select Chat Session <?php echo $session['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($session['id']); ?></td>
                                <td data-label="Sender Name"><?php echo htmlspecialchars($session['sender_name']); ?></td>
                                <td data-label="Recipient Name"><?php echo htmlspecialchars($session['recipient_name']); ?></td>
                                <td data-label="Title"><?php echo htmlspecialchars($session['title']); ?></td>
                                <td data-label="Status"><span class="status-badge <?php echo htmlspecialchars($session['status']); ?>"><?php echo ucfirst(htmlspecialchars($session['status'])); ?></span></td>
                                <td data-label="Start Time"><?php echo htmlspecialchars($session['start_time']); ?></td>
                                <td data-label="End Time"><?php echo htmlspecialchars($session['end_time'] ?? 'N/A'); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($session['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="edit_chat_session.php?id=<?php echo $session['id']; ?>" class="action-icon edit" aria-label="Edit Chat Session"><i class="fas fa-edit"></i></a>
                                    <a href="view_chat_session.php?id=<?php echo $session['id']; ?>" class="action-icon view" aria-label="View Chat Session"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this chat session?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_chat_session">
                                        <input type="hidden" name="session_id" value="<?php echo $session['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Chat Session"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Chat Sessions -->
                <div class="pagination">
                    <?php if ($chat_page > 1): ?>
                        <a href="?chat_page=<?php echo $chat_page - 1; ?>&chat_search=<?php echo urlencode($_GET['chat_search'] ?? ''); ?>&chat_status=<?php echo urlencode($chat_status_filter); ?>&chat_sort=<?php echo $chat_sort; ?>&chat_order=<?php echo $chat_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $chat_page; ?> of <?php echo $total_chat_pages; ?></span>
                    <?php if ($chat_page < $total_chat_pages): ?>
                        <a href="?chat_page=<?php echo $chat_page + 1; ?>&chat_search=<?php echo urlencode($_GET['chat_search'] ?? ''); ?>&chat_status=<?php echo urlencode($chat_status_filter); ?>&chat_sort=<?php echo $chat_sort; ?>&chat_order=<?php echo $chat_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Enhanced Bulk Actions for Chat Sessions -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Chat Sessions">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_chat_sessions">Delete Selected</option>
                        <option value="bulk_export_chat_sessions">Export Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Chat Messages Section (Upgraded from Recent to Full) -->
        <div class="section">
            <h2>Chat Messages</h2>
            <!-- Search and Filters for Messages -->
            <form class="search-form" method="GET">
                <input type="text" name="message_search" placeholder="Search by message content..." value="<?php echo htmlspecialchars($_GET['message_search'] ?? ''); ?>" aria-label="Search Messages">
                <select name="message_read" aria-label="Filter by Read Status">
                    <option value="">All Read Status</option>
                    <!-- Assume read_status values from DB, e.g. -->
                    <option value="unread" <?php echo $message_read_filter === 'unread' ? 'selected' : ''; ?>>Unread</option>
                    <option value="read" <?php echo $message_read_filter === 'read' ? 'selected' : ''; ?>>Read</option>
                </select>
                <select name="message_sort" aria-label="Sort By">
                    <option value="created_at" <?php echo $message_sort === 'created_at' ? 'selected' : ''; ?>>Created At</option>
                    <option value="read_status" <?php echo $message_sort === 'read_status' ? 'selected' : ''; ?>>Read Status</option>
                </select>
                <select name="message_order" aria-label="Order">
                    <option value="DESC" <?php echo $message_order === 'DESC' ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo $message_order === 'ASC' ? 'selected' : ''; ?>>Ascending</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
            </form>
            <!-- Chat Messages Table -->
            <form method="POST" id="messages-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th><input type="checkbox" id="select-all-messages" aria-label="Select All Messages"></th>
                            <th>ID</th>
                            <th>User Name</th>
                            <th>Message</th>
                            <th>Read Status</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($chat_messages as $message): ?>
                            <tr>
                                <td data-label="Select"><input type="checkbox" name="message_ids[]" value="<?php echo $message['id']; ?>" aria-label="Select Message <?php echo $message['id']; ?>"></td>
                                <td data-label="ID"><?php echo htmlspecialchars($message['id']); ?></td>
                                <td data-label="User Name"><?php echo htmlspecialchars($message['user_name']); ?></td>
                                <td data-label="Message"><?php echo htmlspecialchars(substr($message['message'], 0, 100)); ?>...</td>
                                <td data-label="Read Status"><?php echo htmlspecialchars($message['read_status']); ?></td>
                                <td data-label="Created At"><?php echo htmlspecialchars($message['created_at']); ?></td>
                                <td data-label="Actions">
                                    <a href="view_message.php?id=<?php echo $message['id']; ?>" class="action-icon view" aria-label="View Message"><i class="fas fa-eye"></i></a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this message?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action" value="delete_message">
                                        <input type="hidden" name="message_id" value="<?php echo $message['id']; ?>">
                                        <button type="submit" class="action-icon delete" aria-label="Delete Message"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <!-- Pagination for Messages -->
                <div class="pagination">
                    <?php if ($message_page > 1): ?>
                        <a href="?message_page=<?php echo $message_page - 1; ?>&message_search=<?php echo urlencode($_GET['message_search'] ?? ''); ?>&message_read=<?php echo urlencode($message_read_filter); ?>&message_sort=<?php echo $message_sort; ?>&message_order=<?php echo $message_order; ?>">&laquo; Previous</a>
                    <?php endif; ?>
                    <span>Page <?php echo $message_page; ?> of <?php echo $total_message_pages; ?></span>
                    <?php if ($message_page < $total_message_pages): ?>
                        <a href="?message_page=<?php echo $message_page + 1; ?>&message_search=<?php echo urlencode($_GET['message_search'] ?? ''); ?>&message_read=<?php echo urlencode($message_read_filter); ?>&message_sort=<?php echo $message_sort; ?>&message_order=<?php echo $message_order; ?>">Next &raquo;</a>
                    <?php endif; ?>
                </div>
                <!-- Bulk Actions for Messages -->
                <div class="bulk-actions">
                    <select name="action" aria-label="Bulk Actions for Messages">
                        <option value="">Bulk Actions</option>
                        <option value="bulk_delete_messages">Delete Selected</option>
                        <option value="bulk_export_messages">Export Selected</option>
                    </select>
                    <button type="submit">Apply</button>
                </div>
            </form>
        </div>
        <!-- Audit Logs -->
        <div class="section audit-logs">
            <h2>Audit Logs</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Description</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audit_logs as $log): ?>
                        <tr>
                            <td data-label="Time"><?php echo htmlspecialchars($log['created_at']); ?></td>
                            <td data-label="Actor"><?php echo htmlspecialchars($log['actor_name']); ?></td>
                            <td data-label="Action"><?php echo htmlspecialchars($log['action']); ?></td>
                            <td data-label="Description"><?php echo htmlspecialchars($log['description']); ?></td>
                            <td data-label="IP Address"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
    <script>
        // Enhanced Select All Checkbox for Notifications
        document.getElementById('select-all-notifications').addEventListener('change', function() {
            document.querySelectorAll('input[name="notification_ids[]"]').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        // Select All Checkbox for Chats
        document.getElementById('select-all-chats').addEventListener('change', function() {
            document.querySelectorAll('input[name="session_ids[]"]').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        // Select All Checkbox for Messages
        document.getElementById('select-all-messages').addEventListener('change', function() {
            document.querySelectorAll('input[name="message_ids[]"]').forEach(checkbox => {
                checkbox.checked = this.checked;
            });
        });
        // Confirm Bulk Actions and Deletes with enhanced messages
        document.getElementById('notifications-form').addEventListener('submit', function(e) {
            const action = this.action.value;
            if (action && !confirm(`Are you sure you want to ${action.replace('bulk_', '').replace('_', ' ')} the selected notifications? This action cannot be undone.`)) {
                e.preventDefault();
            }
        });
        document.getElementById('chats-form').addEventListener('submit', function(e) {
            const action = this.action.value;
            if (action && !confirm(`Are you sure you want to ${action.replace('bulk_', '').replace('_', ' ')} the selected chat sessions? This action cannot be undone.`)) {
                e.preventDefault();
            }
        });
        document.getElementById('messages-form').addEventListener('submit', function(e) {
            const action = this.action.value;
            if (action && !confirm(`Are you sure you want to ${action.replace('bulk_', '').replace('_', ' ')} the selected messages? This action cannot be undone.`)) {
                e.preventDefault();
            }
        });
    </script>
    <style>
        /* Premium Modern Styles - Enhanced for International Standards (Accessibility, Responsiveness) */
        :root {
            --primary-sea-blue: #007bff;
            --accent-soft-cyan: #17a2b8;
            --accent-deep-navy: #343a40;
            --accent-slate-gray: #6c757d;
            --primary-white: #ffffff;
            --primary-black: #000000;
            --success-green: #28a745;
            --error-red: #dc3545;
            --warning-yellow: #ffc107;
            --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        body {
            font-family: var(--font-family);
            line-height: 1.6;
            color: var(--primary-black);
            background-color: #f8f9fa;
        }
        .notifications-management {
            display: grid;
            gap: 32px;
            padding: 32px;
            max-width: 1800px;
            margin: 0 auto;
        }
        .page-title {
            font-size: 32px;
            font-weight: 700;
            color: var(--accent-deep-navy);
            text-align: center;
        }
        .section {
            background-color: var(--primary-white);
            border-radius: 16px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            padding: 24px;
            transition: box-shadow 0.3s;
        }
        .section:hover {
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
        }
        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-weight: 500;
            transition: opacity 0.3s;
        }
        .alert.success {
            background-color: rgba(40, 167, 69, 0.1);
            color: var(--success-green);
            border: 1px solid var(--success-green);
        }
        .alert.error {
            background-color: rgba(220, 53, 69, 0.1);
            color: var(--error-red);
            border: 1px solid var(--error-red);
        }
        .search-form {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .search-form input, .search-form select {
            padding: 12px 16px;
            border: 1px solid var(--accent-slate-gray);
            border-radius: 8px;
            flex: 1;
            min-width: 180px;
            transition: border-color 0.2s;
        }
        .search-form input:focus, .search-form select:focus {
            border-color: var(--primary-sea-blue);
            outline: none;
        }
        .search-form button {
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
        }
        .search-form button:hover {
            background-color: var(--accent-soft-cyan);
            transform: translateY(-2px);
        }
        .data-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0 8px;
            font-size: 14px;
            margin-bottom: 24px;
        }
        .data-table th, .data-table td {
            padding: 16px;
            text-align: left;
            background-color: var(--primary-white);
        }
        .data-table th {
            background-color: var(--accent-deep-navy);
            color: var(--primary-white);
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .data-table tbody tr {
            transition: background-color 0.2s;
        }
        .data-table tbody tr:hover {
            background-color: #f1f3f5;
        }
        .data-table td {
            border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        }
        .status-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            color: var(--primary-white);
            display: inline-block;
            min-width: 60px;
            text-align: center;
        }
        .status-badge.active { background-color: #28a745; }
        .status-badge.closed { background-color: #dc3545; }
        .action-icon {
            margin-right: 12px;
            color: var(--primary-sea-blue);
            text-decoration: none;
            font-size: 18px;
            transition: color 0.2s, transform 0.1s;
        }
        .action-icon:hover {
            color: var(--accent-soft-cyan);
            transform: scale(1.1);
        }
        .action-icon.delete {
            color: var(--error-red);
            background: none;
            border: none;
            cursor: pointer;
        }
        .action-icon.delete:hover {
            color: #c82333;
        }
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 16px;
            margin-top: 16px;
        }
        .pagination a {
            padding: 10px 20px;
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            border-radius: 8px;
            text-decoration: none;
            transition: background-color 0.2s, transform 0.1s;
        }
        .pagination a:hover {
            background-color: var(--accent-soft-cyan);
            transform: translateY(-2px);
        }
        .pagination span {
            font-weight: 600;
            color: var(--accent-deep-navy);
        }
        .bulk-actions {
            display: flex;
            gap: 16px;
            margin-top: 16px;
            align-items: center;
        }
        .bulk-actions select, .bulk-actions button {
            padding: 12px 16px;
            border-radius: 8px;
            border: 1px solid var(--accent-slate-gray);
            transition: border-color 0.2s;
        }
        .bulk-actions select:focus, .bulk-actions button:focus {
            border-color: var(--primary-sea-blue);
        }
        .bulk-actions button {
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            border: none;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
        }
        .bulk-actions button:hover {
            background-color: var(--accent-soft-cyan);
            transform: translateY(-2px);
        }
        .audit-logs {
            margin-top: 32px;
        }
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 24px;
        }
        .kpi-card {
            background-color: var(--primary-white);
            border-radius: 16px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
            padding: 24px;
            text-align: center;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .kpi-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
        }
        .kpi-card .icon {
            font-size: 40px;
            color: var(--primary-sea-blue);
            margin-bottom: 12px;
            display: block;
        }
        .kpi-card .value {
            font-size: 32px;
            font-weight: 700;
            color: var(--primary-black);
        }
        .kpi-card .label {
            font-size: 16px;
            color: var(--accent-slate-gray);
            text-transform: uppercase;
        }
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        .action-button {
            background-color: var(--primary-sea-blue);
            color: var(--primary-white);
            padding: 16px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: background-color 0.2s, transform 0.1s;
        }
        .action-button:hover {
            background-color: var(--accent-soft-cyan);
            transform: translateY(-4px);
        }
        /* Enhanced Responsiveness for Mobile/Tablet */
        @media (max-width: 1200px) {
            .notifications-management {
                padding: 24px;
            }
            .kpi-grid {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
        }
        @media (max-width: 992px) {
            .quick-actions {
                grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            }
            .kpi-grid {
                grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            }
        }
        @media (max-width: 768px) {
            .search-form {
                flex-direction: column;
                align-items: stretch;
            }
            .search-form input, .search-form select, .search-form button {
                width: 100%;
                min-width: auto;
                margin-bottom: 8px;
            }
            .data-table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
            .data-table thead {
                display: none;
            }
            .data-table tbody tr {
                display: block;
                margin-bottom: 16px;
                border: 1px solid rgba(0, 0, 0, 0.1);
                border-radius: 8px;
                padding: 16px;
                box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
            }
            .data-table td {
                display: flex;
                justify-content: space-between;
                text-align: right;
                position: relative;
                padding: 8px 0;
                border-bottom: 1px solid rgba(0, 0, 0, 0.05);
            }
            .data-table td:before {
                content: attr(data-label);
                font-weight: bold;
                flex: 1;
                text-align: left;
            }
            .data-table td:last-child {
                border-bottom: none;
            }
            .bulk-actions {
                flex-direction: column;
            }
            .bulk-actions select, .bulk-actions button {
                width: 100%;
                margin-bottom: 8px;
            }
            .pagination {
                flex-direction: column;
                gap: 8px;
            }
        }
        @media (max-width: 576px) {
            .page-title {
                font-size: 28px;
            }
            .action-icon {
                font-size: 16px;
            }
            .kpi-card .value {
                font-size: 28px;
            }
            .kpi-card .icon {
                font-size: 36px;
            }
            .section {
                padding: 16px;
            }
        }
        /* Accessibility Enhancements */
        [role="alert"] {
            animation: fadeIn 0.5s;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        /* Dark Mode Support (Optional, toggle via JS or preference) */
        @media (prefers-color-scheme: dark) {
            body {
                background-color: #121212;
                color: #e0e0e0;
            }
            .section, .kpi-card {
                background-color: #1e1e1e;
            }
            .data-table th {
                background-color: #333;
            }
            .data-table tbody tr:hover {
                background-color: #2a2a2a;
            }
            .alert.success {
                background-color: rgba(40, 167, 69, 0.2);
            }
            .alert.error {
                background-color: rgba(220, 53, 69, 0.2);
            }
        }
    </style>
<?php include '../templates/admin_footer.php'; ?>