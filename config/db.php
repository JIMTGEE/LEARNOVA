<?php
/**
 * Reusable PDO connection for Learnova.
 * Configure LEARNOVA_DB_HOST, LEARNOVA_DB_NAME, LEARNOVA_DB_USER, and
 * LEARNOVA_DB_PASSWORD in the deployment environment.
 */
require_once __DIR__ . '/branding.php';

class Database
{
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $charset = 'utf8mb4';

    private $pdo;
    private static $instance = null;

    private function __construct()
    {
        $this->host = getenv('LEARNOVA_DB_HOST') ?: 'localhost';
        $this->dbname = APP_DATABASE_NAME;
        $this->username = getenv('LEARNOVA_DB_USER') ?: 'root';
        $this->password = getenv('LEARNOVA_DB_PASSWORD') ?: '';

        $dsn = "mysql:host={$this->host};dbname={$this->dbname};charset={$this->charset}";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_PERSISTENT => true,
        ];

        try {
            $this->pdo = new PDO($dsn, $this->username, $this->password, $options);
        } catch (PDOException $e) {
            error_log('Learnova database connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('We are experiencing technical difficulties. Please try again later.');
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->pdo;
    }

    private function __clone() {}

    public function __wakeup()
    {
        throw new Exception('Cannot unserialize singleton');
    }
}

function db(): PDO
{
    return Database::getInstance()->getConnection();
}
?>
