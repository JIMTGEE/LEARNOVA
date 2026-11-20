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

// Handle actions: edit, delete, bulk
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token.';
        header("Location: manage_chats.php");
        exit();
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'edit':
                $session_id = intval($_POST['session_id'] ?? 0);
                $title = trim($_POST['title'] ?? '');
                $status = $_POST['status'] ?? 'active';

                if ($session_id > 0 && !empty($title) && in_array($status, ['draft', 'active', 'closed'])) {
                    $stmt = $db->prepare("UPDATE chat_sessions SET title = ?, status = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$title, $status, $session_id]);
                    log_activity($_SESSION['user_id'], 'edit_chat_session', "Edited chat session ID: $session_id");
                    $_SESSION['success'] = 'Chat session updated successfully.';
                } else {
                    $_SESSION['error'] = 'Invalid input.';
                }
                break;

            case 'delete':
                $session_id = intval($_POST['session_id'] ?? 0);
                if ($session_id > 0) {
                    // Delete session, participants, messages
                    $db->beginTransaction();
                    try {
                        $db->prepare("DELETE FROM chat_messages WHERE chat_session_id = ?")->execute([$session_id]);
                        $db->prepare("DELETE FROM chat_participants WHERE chat_session_id = ?")->execute([$session_id]);
                        $db->prepare("DELETE FROM chat_sessions WHERE id = ?")->execute([$session_id]);
                        $db->commit();
                        log_activity($_SESSION['user_id'], 'delete_chat_session', "Deleted chat session ID: $session_id");
                        $_SESSION['success'] = 'Chat session deleted successfully.';
                    } catch (Exception $e) {
                        $db->rollBack();
                        $_SESSION['error'] = 'Error deleting session: ' . $e->getMessage();
                    }
                }
                break;

            case 'bulk_delete':
                $session_ids = array_filter(array_map('intval', $_POST['session_ids'] ?? []));
                if (!empty($session_ids)) {
                    $placeholders = implode(',', array_fill(0, count($session_ids), '?'));
                    $db->beginTransaction();
                    try {
                        $db->prepare("DELETE FROM chat_messages WHERE chat_session_id IN ($placeholders)")->execute($session_ids);
                        $db->prepare("DELETE FROM chat_participants WHERE chat_session_id IN ($placeholders)")->execute($session_ids);
                        $db->prepare("DELETE FROM chat_sessions WHERE id IN ($placeholders)")->execute($session_ids);
                        $db->commit();
                        log_activity($_SESSION['user_id'], 'bulk_delete_chats', "Deleted chat sessions: " . implode(',', $session_ids));
                        $_SESSION['success'] = 'Selected chat sessions deleted.';
                    } catch (Exception $e) {
                        $db->rollBack();
                        $_SESSION['error'] = 'Error in bulk delete: ' . $e->getMessage();
                    }
                }
                break;

            case 'bulk_close':
                $session_ids = array_filter(array_map('intval', $_POST['session_ids'] ?? []));
                if (!empty($session_ids)) {
                    $placeholders = implode(',', array_fill(0, count($session_ids), '?'));
                    $stmt = $db->prepare("UPDATE chat_sessions SET status = 'closed', updated_at = NOW() WHERE id IN ($placeholders)");
                    $stmt->execute($session_ids);
                    log_activity($_SESSION['user_id'], 'bulk_close_chats', "Closed chat sessions: " . implode(',', $session_ids));
                    $_SESSION['success'] = 'Selected chat sessions closed.';
                }
                break;
        }
        header("Location: manage_chats.php");
        exit();
    }
}

// Fetch chat sessions with filters and pagination
$search = '%' . trim($_GET['search'] ?? '') . '%';
$status_filter = $_GET['status'] ?? '';
$type_filter = $_GET['type'] ?? ''; // Simplified type: private, group, class, etc.
$page = intval($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

$query = "SELECT cs.*, COUNT(cp.user_id) AS participant_count, GROUP_CONCAT(u.name SEPARATOR ', ') AS participants 
          FROM chat_sessions cs 
          LEFT JOIN chat_participants cp ON cs.id = cp.chat_session_id 
          LEFT JOIN users u ON cp.user_id = u.id 
          WHERE (cs.title LIKE ? OR cs.session_token LIKE ?) ";
$params = [$search, $search];

if ($status_filter && in_array($status_filter, ['draft', 'active', 'closed'])) {
    $query .= "AND cs.status = ? ";
    $params[] = $status_filter;
}

// Simplified type filter (based on fields)
if ($type_filter) {
    switch ($type_filter) {
        case 'private':
            $query .= "AND cs.recipient_id IS NOT NULL ";
            break;
        case 'group':
            $query .= "AND cs.assignment_id IS NULL AND cs.class_id IS NULL AND cs.live_class_id IS NULL AND cs.recipient_id IS NULL ";
            break;
        case 'class':
            $query .= "AND cs.class_id IS NOT NULL ";
            break;
        case 'live':
            $query .= "AND cs.live_class_id IS NOT NULL ";
            break;
        case 'assignment':
            $query .= "AND cs.assignment_id IS NOT NULL ";
            break;
    }
}

$query .= "GROUP BY cs.id ORDER BY cs.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;

$stmt = $db->prepare($query);
$stmt->execute($params);
$sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count total for pagination
$count_query = "SELECT COUNT(DISTINCT cs.id) FROM chat_sessions cs 
                LEFT JOIN chat_participants cp ON cs.id = cp.chat_session_id 
                LEFT JOIN users u ON cp.user_id = u.id 
                WHERE (cs.title LIKE ? OR cs.session_token LIKE ?) ";
$count_params = [$search, $search];
if ($status_filter) {
    $count_query .= "AND cs.status = ? ";
    $count_params[] = $status_filter;
}
if ($type_filter) {
    // Add same type conditions
    switch ($type_filter) {
        case 'private':
            $count_query .= "AND cs.recipient_id IS NOT NULL ";
            break;
        case 'group':
            $count_query .= "AND cs.assignment_id IS NULL AND cs.class_id IS NULL AND cs.live_class_id IS NULL AND cs.recipient_id IS NULL ";
            break;
        case 'class':
            $count_query .= "AND cs.class_id IS NOT NULL ";
            break;
        case 'live':
            $count_query .= "AND cs.live_class_id IS NOT NULL ";
            break;
        case 'assignment':
            $count_query .= "AND cs.assignment_id IS NOT NULL ";
            break;
    }
}
$count_stmt = $db->prepare($count_query);
$count_stmt->execute($count_params);
$total_sessions = $count_stmt->fetchColumn() ?? 0;
$total_pages = ceil($total_sessions / $limit);

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="manage-chats">
        <h1 class="page-title">Manage Chat Sessions</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <!-- Search and Filters -->
        <form class="search-form" method="GET">
            <input type="text" name="search" placeholder="Search by title or token..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
            <select name="status">
                <option value="">All Statuses</option>
                <option value="draft" <?php echo $status_filter === 'draft' ? 'selected' : ''; ?>>Draft</option>
                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="closed" <?php echo $status_filter === 'closed' ? 'selected' : ''; ?>>Closed</option>
            </select>
            <select name="type">
                <option value="">All Types</option>
                <option value="private" <?php echo $type_filter === 'private' ? 'selected' : ''; ?>>Private</option>
                <option value="group" <?php echo $type_filter === 'group' ? 'selected' : ''; ?>>Group</option>
                <option value="class" <?php echo $type_filter === 'class' ? 'selected' : ''; ?>>Class</option>
                <option value="live" <?php echo $type_filter === 'live' ? 'selected' : ''; ?>>Live Class</option>
                <option value="assignment" <?php echo $type_filter === 'assignment' ? 'selected' : ''; ?>>Assignment</option>
            </select>
            <button type="submit"><i class="fas fa-search"></i> Filter</button>
        </form>

        <!-- Sessions Table -->
        <form method="POST" id="sessions-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <table class="data-table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all"></th>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Participants</th>
                        <th>Created At</th>
                        <th>Updated At</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sessions as $session): ?>
                        <?php
                        // Determine type
                        $type = 'Group';
                        if ($session['recipient_id']) $type = 'Private';
                        elseif ($session['class_id']) $type = 'Class';
                        elseif ($session['live_class_id']) $type = 'Live';
                        elseif ($session['assignment_id']) $type = 'Assignment';
                        ?>
                        <tr>
                            <td><input type="checkbox" name="session_ids[]" value="<?php echo $session['id']; ?>"></td>
                            <td><?php echo htmlspecialchars($session['id']); ?></td>
                            <td><?php echo htmlspecialchars($session['title']); ?></td>
                            <td><?php echo $type; ?></td>
                            <td><span class="status-badge <?php echo htmlspecialchars($session['status']); ?>"><?php echo ucfirst(htmlspecialchars($session['status'])); ?></span></td>
                            <td><?php echo $session['participant_count']; ?> (<?php echo htmlspecialchars(substr($session['participants'], 0, 50) . '...'); ?>)</td>
                            <td><?php echo htmlspecialchars($session['created_at']); ?></td>
                            <td><?php echo htmlspecialchars($session['updated_at']); ?></td>
                            <td>
                                <a href="chat.php?session_id=<?php echo $session['id']; ?>" class="action-icon view"><i class="fas fa-eye"></i></a>
                                <button type="button" class="action-icon edit" onclick="openEditModal(<?php echo $session['id']; ?>, '<?php echo addslashes(htmlspecialchars($session['title'])); ?>', '<?php echo $session['status']; ?>')"><i class="fas fa-edit"></i></button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this session?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="session_id" value="<?php echo $session['id']; ?>">
                                    <button type="submit" class="action-icon delete"><i class="fas fa-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&status=<?php echo urlencode($status_filter); ?>&type=<?php echo urlencode($type_filter); ?>">&laquo; Previous</a>
                <?php endif; ?>
                <span>Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($_GET['search'] ?? ''); ?>&status=<?php echo urlencode($status_filter); ?>&type=<?php echo urlencode($type_filter); ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>

            <!-- Bulk Actions -->
            <div class="bulk-actions">
                <select name="action">
                    <option value="">Bulk Actions</option>
                    <option value="bulk_close">Close Selected</option>
                    <option value="bulk_delete">Delete Selected</option>
                </select>
                <button type="submit">Apply</button>
            </div>
        </form>

        <!-- Edit Modal -->
        <div id="edit-modal" class="modal" style="display: none;">
            <div class="modal-content">
                <h2>Edit Chat Session</h2>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="session_id" id="edit-session-id">
                    <label>Title:</label>
                    <input type="text" name="title" id="edit-title" required>
                    <label>Status:</label>
                    <select name="status" id="edit-status">
                        <option value="draft">Draft</option>
                        <option value="active">Active</option>
                        <option value="closed">Closed</option>
                    </select>
                    <button type="submit">Save Changes</button>
                    <button type="button" onclick="closeEditModal()">Cancel</button>
                </form>
            </div>
        </div>
    </section>

    <script>
        // Select All
        document.getElementById('select-all').addEventListener('change', function() {
            document.querySelectorAll('input[name="session_ids[]"]').forEach(cb => cb.checked = this.checked);
        });

        // Edit Modal
        function openEditModal(id, title, status) {
            document.getElementById('edit-session-id').value = id;
            document.getElementById('edit-title').value = title;
            document.getElementById('edit-status').value = status;
            document.getElementById('edit-modal').style.display = 'flex';
        }

        function closeEditModal() {
            document.getElementById('edit-modal').style.display = 'none';
        }
    </script>

    <style>
        /* Manage Chats Styles */
        .manage-chats { padding: 20px; }
        .search-form { display: flex; gap: 16px; margin-bottom: 20px; }
        .search-form input, .search-form select { flex: 1; padding: 12px; border-radius: 4px; border: 1px solid var(--accent-slate-gray); }
        .search-form button { background: var(--primary-sea-blue); color: var(--primary-white); padding: 12px; border: none; border-radius: 4px; cursor: pointer; }
        .data-table { width: 100%; border-collapse: collapse; }
        .data-table th, .data-table td { padding: 12px; text-align: left; border-bottom: 1px solid var(--accent-slate-gray); }
        .status-badge { padding: 4px 8px; border-radius: 4px; color: white; }
        .status-badge.active { background: #28a745; }
        .status-badge.draft { background: #ffc107; }
        .status-badge.closed { background: #dc3545; }
        .action-icon { color: var(--primary-sea-blue); margin-right: 8px; cursor: pointer; }
        .action-icon.delete { color: #dc3545; }
        .pagination { text-align: center; margin: 20px 0; }
        .pagination a { padding: 8px 16px; background: var(--primary-sea-blue); color: white; border-radius: 4px; text-decoration: none; margin: 0 8px; }
        .bulk-actions { display: flex; gap: 16px; }
        .bulk-actions select, .bulk-actions button { padding: 12px; border-radius: 4px; }
        .bulk-actions button { background: var(--primary-sea-blue); color: white; border: none; cursor: pointer; }
        .modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; }
        .modal-content { background: white; padding: 20px; border-radius: 8px; width: 400px; }
        .alert { padding: 16px; border-radius: 4px; margin-bottom: 16px; }
        .alert.success { background: rgba(40,167,69,0.1); color: #28a745; }
        .alert.error { background: rgba(220,53,69,0.1); color: #dc3545; }
    </style>

<?php include '../templates/admin_footer.php'; ?>