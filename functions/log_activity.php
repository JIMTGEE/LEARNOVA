<?php
// functions/log_activity.php - Complete and Secure Implementation

// Define the log_activity function only if it doesn't already exist
if (!function_exists('log_activity')) {
    function log_activity($user_id, $action, $description) {
        global $db; // Assumes $db is the PDO instance from config/db.php (loaded in headers)

        // Safely get IP and User Agent
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

        // Truncate long fields if necessary (based on schema: description TEXT, user_agent TEXT)
        $description = substr($description, 0, 65535);
        $user_agent = substr($user_agent, 0, 65535);

        try {
            $stmt = $db->prepare("
                INSERT INTO user_logs 
                (user_id, action, description, ip_address, user_agent, created_at) 
                VALUES (:user_id, :action, :description, :ip_address, :user_agent, NOW())
            ");
            $stmt->execute([
                ':user_id' => (int)$user_id,
                ':action' => $action,
                ':description' => $description,
                ':ip_address' => $ip_address,
                ':user_agent' => $user_agent
            ]);
        } catch (PDOException $e) {
            // Log error silently to avoid exposing details
            error_log("Activity logging failed: " . $e->getMessage());
        }
    }
}