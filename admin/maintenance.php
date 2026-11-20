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

// Include database connection
require_once '../config/db.php';
$db = db(); // Use the helper function to get PDO instance

// Include helpers and functions with upgraded error handling
try {
    require_once '../includes/helpers.php';
    require_once '../functions/csrf.php';
    require_once '../functions/log_activity.php';
} catch (Exception $e) {
    error_log("Error loading helpers/functions: " . $e->getMessage());
    $_SESSION['error'] = 'System error: Unable to load required files. Please check logs.';
    header("Location: dashboard.php");
    exit();
}

// Handle POST actions for maintenance tasks with CSRF validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        $_SESSION['error'] = 'Invalid CSRF token. Action blocked for security.';
        header("Location: maintenance.php");
        exit();
    }

    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'backup_database':
            // Simulate backup (use mysqldump in production if allowed)
            $backup_dir = __DIR__ . '/../backups/';
            if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);
            $backup_file = 'edusync_backup_' . date('Y-m-d_H-i-s') . '.sql';
            $full_path = $backup_dir . $backup_file;
            // Placeholder: exec('mysqldump -u user -p pass edusync > ' . $full_path);
            file_put_contents($full_path, '-- Placeholder backup content --');
            log_activity($_SESSION['user_id'], 'backup_database', "Created backup: $backup_file");
            $_SESSION['success'] = "Database backup created: $backup_file";
            break;

        case 'restore_database':
            $backup_file = $_POST['backup_file'] ?? '';
            $backup_path = __DIR__ . '/../backups/' . $backup_file;
            if (file_exists($backup_path)) {
                // Placeholder: exec('mysql -u user -p pass edusync < ' . $backup_path);
                log_activity($_SESSION['user_id'], 'restore_database', "Restored from: $backup_file");
                $_SESSION['success'] = "Database restored from: $backup_file";
            } else {
                $_SESSION['error'] = 'Backup file not found.';
            }
            break;

        case 'cleanup_logs':
            $stmt = $db->prepare("DELETE FROM activity_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            $stmt->execute();
            $deleted = $stmt->rowCount();
            log_activity($_SESSION['user_id'], 'cleanup_logs', "Deleted $deleted old activity logs");
            $_SESSION['success'] = "$deleted old logs cleaned up.";
            break;

        case 'cleanup_files':
            // Cleanup unused uploads (placeholder logic)
            // Scan /Uploads and delete old files
            $upload_dir = __DIR__ . '/../Uploads/';
            $files = glob($upload_dir . '**/*');
            $deleted = 0;
            foreach ($files as $file) {
                if (is_file($file) && time() - filemtime($file) > 30*24*60*60) { // 30 days
                    unlink($file);
                    $deleted++;
                }
            }
            log_activity($_SESSION['user_id'], 'cleanup_files', "Deleted $deleted unused files");
            $_SESSION['success'] = "$deleted unused files cleaned up.";
            break;

        case 'migrate_data':
            // Placeholder for data migration, e.g., profile pictures
            // Assume moving from old to new path
            $old_dir = __DIR__ . '/../old_uploads/profiles/';
            $new_dir = __DIR__ . '/../Uploads/profiles/';
            if (!is_dir($new_dir)) mkdir($new_dir, 0755, true);
            $files = glob($old_dir . '*');
            foreach ($files as $file) {
                rename($file, $new_dir . basename($file));
            }
            // Update DB paths
            $db->exec("UPDATE users SET profile_picture = REPLACE(profile_picture, '/old_uploads/', '/Uploads/')");
            log_activity($_SESSION['user_id'], 'migrate_data', "Migrated profile pictures");
            $_SESSION['success'] = 'Data migration completed.';
            break;

        case 'optimize_db':
            $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $db->exec("OPTIMIZE TABLE `$table`");
            }
            log_activity($_SESSION['user_id'], 'optimize_db', "Optimized all database tables");
            $_SESSION['success'] = 'Database tables optimized.';
            break;
    }
    header("Location: maintenance.php");
    exit();
}

// Fetch system stats for dashboard
$total_users = $db->query("SELECT COUNT(*) FROM users")->fetchColumn() ?? 0;
$active_users = $db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn() ?? 0;
$recent_logins = $db->query("SELECT COUNT(*) FROM users WHERE last_login > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn() ?? 0;
$log_count = $db->query("SELECT COUNT(*) FROM activity_logs")->fetchColumn() ?? 0;
$backup_dir = __DIR__ . '/../backups/';
if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);
$backups = array_filter(scandir($backup_dir), fn($f) => pathinfo($f, PATHINFO_EXTENSION) === 'sql');

// Generate CSRF token
$csrf_token = generate_csrf_token();

?>

<?php include '../templates/admin_header.php'; ?>

    <section class="maintenance">
        <h1 class="page-title">Maintenance & Utilities</h1>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <!-- System Analysis Dashboard -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <i class="fas fa-users icon"></i>
                <div class="value"><?php echo $total_users; ?></div>
                <div class="label">Total Users</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-user-check icon"></i>
                <div class="value"><?php echo $active_users; ?></div>
                <div class="label">Active Users</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-sign-in-alt icon"></i>
                <div class="value"><?php echo $recent_logins; ?></div>
                <div class="label">Recent Logins (7d)</div>
            </div>
            <div class="kpi-card">
                <i class="fas fa-file-alt icon"></i>
                <div class="value"><?php echo $log_count; ?></div>
                <div class="label">Activity Logs</div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="quick-actions">
            <h3>Quick Actions</h3>
            <div class="actions-grid">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="backup_database">
                    <button type="submit" class="action-button"><i class="fas fa-download"></i> Backup Database</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="cleanup_logs">
                    <button type="submit" class="action-button"><i class="fas fa-broom"></i> Cleanup Logs</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="cleanup_files">
                    <button type="submit" class="action-button"><i class="fas fa-trash"></i> Cleanup Files</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="migrate_data">
                    <button type="submit" class="action-button"><i class="fas fa-exchange-alt"></i> Migrate Data</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="optimize_db">
                    <button type="submit" class="action-button"><i class="fas fa-tools"></i> Optimize DB</button>
                </form>
            </div>
        </div>

        <!-- Backups List -->
        <div class="backups-card">
            <h3>Available Backups</h3>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th>Size</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($backups as $file): if ($file === '.' || $file === '..') continue; ?>
                        <tr>
                            <td><?php echo htmlspecialchars($file); ?></td>
                            <td><?php echo round(filesize($backup_dir . $file) / 1024, 2); ?> KB</td>
                            <td><?php echo date('Y-m-d H:i:s', filemtime($backup_dir . $file)); ?></td>
                            <td>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Restore this backup?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="restore_database">
                                    <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars($file); ?>">
                                    <button type="submit" class="action-icon restore"><i class="fas fa-upload"></i></button>
                                </form>
                                <a href="../backups/<?php echo htmlspecialchars($file); ?>" download class="action-icon download"><i class="fas fa-download"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </section>

    <style>
        /* Ultra Modern Styles for Maintenance Page */
        .maintenance {
            display: grid;
            gap: 24px;
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        .page-title {
            font-family: var(--font-ui);
            font-size: 32px;
            font-weight: 700;
            color: var(--primary-black);
            text-align: center;
            margin-bottom: 20px;
        }

        .alert {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
        }

        .alert.success { background: rgba(40, 167, 69, 0.1); color: #28a745; }
        .alert.error { background: rgba(220, 53, 69, 0.1); color: #dc3545; }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 16px;
        }

        .kpi-card {
            background: var(--primary-white);
            border-radius: 12px;
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            padding: 20px;
            text-align: center;
            transition: transform 0.2s ease-out;
        }

        .kpi-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 16px rgba(0,0,0,0.15);
        }

        .kpi-card .icon { font-size: 36px; color: var(--primary-sea-blue); margin-bottom: 8px; }
        .kpi-card .value { font-size: 28px; font-weight: 700; color: var(--primary-black); }
        .kpi-card .label { font-size: 14px; color: var(--accent-slate-gray); text-transform: uppercase; }

        .quick-actions { background: var(--primary-white); border-radius: 12px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); padding: 20px; }
        .actions-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 16px; }
        .action-button { background: var(--primary-sea-blue); color: var(--primary-white); padding: 16px; border-radius: 8px; text-decoration: none; font-size: 16px; transition: background 0.2s, transform 0.2s; display: flex; align-items: center; justify-content: center; }
        .action-button:hover { background: var(--accent-soft-cyan); transform: translateY(-2px); }
        .action-button i { margin-right: 12px; font-size: 18px; }

        .backups-card { background: var(--primary-white); border-radius: 12px; box-shadow: 0 4px 8px rgba(0,0,0,0.1); padding: 20px; overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .data-table th, .data-table td { padding: 12px; text-align: left; border-bottom: 1px solid rgba(0,0,0,0.1); }
        .data-table th { background: var(--accent-deep-navy); color: var(--primary-white); font-weight: 600; }

        .action-icon { margin-right: 8px; color: var(--primary-sea-blue); text-decoration: none; font-size: 18px; background: none; border: none; cursor: pointer; }
        .action-icon:hover { color: var(--accent-soft-cyan); }
        .action-icon.restore { color: #28a745; }
        .action-icon.restore:hover { color: #218838; }
        .action-icon.download { color: #ffc107; }
        .action-icon.download:hover { color: #e0a800; }

        @media (max-width: 768px) {
            .kpi-grid, .actions-grid { grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); }
            .data-table th, .data-table td { padding: 8px; font-size: 12px; }
        }
    </style>

<?php include '../templates/admin_footer.php'; ?>