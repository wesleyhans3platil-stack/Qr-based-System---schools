<?php
/**
 * Database Session Handler — stores PHP sessions in MySQL.
 * Prevents session loss on Railway.app (ephemeral filesystem).
 */

if (defined('DB_SESSION_HANDLER_LOADED')) return;
define('DB_SESSION_HANDLER_LOADED', true);

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
        $this->lifetime = (int)(ini_get('session.gc_maxlifetime') ?: 86400);
    }

    public function open($savePath, $sessionName): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function read($id) {
        $stmt = $this->conn->prepare("SELECT sess_data FROM php_sessions WHERE sess_id = ? AND sess_time > ?");
        if (!$stmt) return '';
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
        if (!$stmt) return false;
        $stmt->bind_param("ssii", $id, $data, $this->lifetime, $time);
        return $stmt->execute();
    }

    public function destroy($id): bool {
        $stmt = $this->conn->prepare("DELETE FROM php_sessions WHERE sess_id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("s", $id);
        return $stmt->execute();
    }

    #[\ReturnTypeWillChange]
    public function gc($maxlifetime) {
        $minTime = time() - $maxlifetime;
        $stmt = $this->conn->prepare("DELETE FROM php_sessions WHERE sess_time < ?");
        if (!$stmt) return false;
        $stmt->bind_param("i", $minTime);
        $stmt->execute();
        return $stmt->affected_rows;
    }
}
