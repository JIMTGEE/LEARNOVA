<?php
/**
 * db.php - Database Connection Configuration
 * 
 * This file establishes a secure, reusable connection to the EduSync database.
 * Designed for local development and easy migration to production.
 * 
 * Database: edusync
 * Host: localhost
 * Charset: utf8mb4 (supports emojis, full Unicode)
 * 
 * @author Prince Seed × NerdSpring Dev Team
 * @version 1.0.0
 * @since 2025
 */

class Database {
    private $host = 'localhost';
    private $dbname = 'edusync';
    private $username = 'root';        // Change in production
    private $password = '';            // Change in production
    private $charset = 'utf8mb4';
    
    private $pdo;
    private static $instance = null;

    // Private constructor to prevent direct instantiation
    private function __construct() {
        $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
        
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Return associative arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Use real prepared statements
            PDO::ATTR_PERSISTENT         => true,                   // Persistent connections for performance
        ];

        try {
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            // In production, log this instead of displaying
            error_log("Database Connection Failed: " . $e->getMessage());
            die("We are experiencing technical difficulties. Please try again later.");
        }
    }

    // Singleton pattern: Ensure only one connection exists
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Get the PDO instance
    public function getConnection() {
        return $this->pdo;
    }

    // Prevent cloning
    private function __clone() {}

    // Prevent unserialization
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

// Global function for quick access (optional, for procedural code)
function db(): PDO {
    return Database::getInstance()->getConnection();
}

/**
 * USAGE EXAMPLES:
 * 
 * 1. Using the class:
 *    $db = Database::getInstance()->getConnection();
 *    $stmt = $db->query("SELECT * FROM users");
 * 
 * 2. Using the helper function:
 *    $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
 *    $stmt->execute([1]);
 * 
 * 3. In login.php (later):
 *    $stmt = db()->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
 */

?>