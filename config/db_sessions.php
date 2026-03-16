<?php
/**
 * ══════════════════════════════════════════════════════════════════
 * DATABASE SESSION HANDLER
 * ══════════════════════════════════════════════════════════════════
 * 
 * Stores PHP sessions in MySQL instead of the filesystem.
 * This prevents session loss on Railway.app where the container
 * filesystem resets on every deploy.
 * 
 * Usage: require this file BEFORE session_start() in any entry point.
 *        The database.php config must be loaded first.
 */

// Only initialize once
if (defined('DB_SESSION_HANDLER_LOADED')) return;
define('DB_SESSION_HANDLER_LOADED', true);

/**
 * Create the sessions table if it doesn't exist.
 */
function ensureSessionTable($conn) {
    static $checked = false;
    if ($checked) return;
    $checked = true;
    
    $conn->query("CREATE TABLE IF NOT EXISTS php_sessions (
        sess_id VARCHAR(128) NOT NULL PRIMARY KEY,
        sess_data MEDIUMBLOB NOT NULL,
        sess_lifetime INT UNSIGNED NOT NULL,
        sess_time INT UNSIGNED NOT NULL,
        INDEX idx_sess_time (sess_time)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

class DbSessionHandler implements SessionHandlerInterface
{
    private $conn;
    private $lifetime;

    public function __construct($conn) {
        $this->conn = $conn;
        $this->lifetime = (int)(ini_get('session.gc_maxlifetime') ?: 86400); // 24 hours default
    }

    public function open($savePath, $sessionName): bool {
        ensureSessionTable($this->conn);
        return true;
    }

    public function close(): bool {
        return true;
    }

    public function read($id): string|false {
        $stmt = $this->conn->prepare("SELECT sess_data FROM php_sessions WHERE sess_id = ? AND sess_time > ?");
        $minTime = time() - $this->lifetime;
        $stmt->bind_param("si", $id, $minTime);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return $row['sess_data'];
        }
        return '';
    }

    public function write($id, $data): bool {
        $time = time();
        $stmt = $this->conn->prepare(
            "INSERT INTO php_sessions (sess_id, sess_data, sess_lifetime, sess_time) 
             VALUES (?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE sess_data = VALUES(sess_data), sess_lifetime = VALUES(sess_lifetime), sess_time = VALUES(sess_time)"
        );
        $stmt->bind_param("ssii", $id, $data, $this->lifetime, $time);
        return $stmt->execute();
    }

    public function destroy($id): bool {
        $stmt = $this->conn->prepare("DELETE FROM php_sessions WHERE sess_id = ?");
        $stmt->bind_param("s", $id);
        return $stmt->execute();
    }

    public function gc($maxlifetime): int|false {
        $minTime = time() - $maxlifetime;
        $stmt = $this->conn->prepare("DELETE FROM php_sessions WHERE sess_time < ?");
        $stmt->bind_param("i", $minTime);
        $stmt->execute();
        return $stmt->affected_rows;
    }
}

/**
 * Initialize DB-based sessions. Call this AFTER database.php is loaded
 * but BEFORE session_start().
 */
function initDbSessions() {
    if (session_status() === PHP_SESSION_ACTIVE) return; // Already started
    
    $conn = $GLOBALS['db_conn'] ?? null;
    if (!$conn) return; // No DB connection, fall back to file sessions
    
    $handler = new DbSessionHandler($conn);
    session_set_save_handler($handler, true);
    
    // Set session cookie params for better persistence
    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    
    session_set_cookie_params([
        'lifetime' => 86400,    // 24 hours
        'path' => '/',
        'domain' => '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    
    // Increase PHP session lifetime 
    ini_set('session.gc_maxlifetime', 86400); // 24 hours
}
