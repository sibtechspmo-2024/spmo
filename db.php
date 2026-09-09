<?php
// Siguraduhing ma-start ang session kung wala pa
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$host = "localhost";
$user = "root";
$pass = "";
$dbname = "sibtech_inventory";

class SQLiteDBResult {
    private $pdo_stmt;
    public $num_rows = 0;
    private $rows = [];
    private $pointer = 0;

    public function __construct($pdo_stmt) {
        if ($pdo_stmt) {
            $this->rows = $pdo_stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->num_rows = count($this->rows);
        }
    }

    public function fetch_assoc() {
        if ($this->pointer < $this->num_rows) {
            return $this->rows[$this->pointer++];
        }
        return null;
    }

    public function fetch_all($mode = MYSQLI_ASSOC) {
        return $this->rows;
    }

    public function data_seek($offset) {
        if ($offset >= 0 && $offset < $this->num_rows) {
            $this->pointer = $offset;
            return true;
        }
        return false;
    }
}

class SQLiteDBStmt {
    private $pdo;
    private $sql;
    private $params = [];
    public $affected_rows = 0;

    public function __construct($pdo, $sql) {
        $this->pdo = $pdo;
        $this->sql = $sql;
    }

    public function bind_param($types, ...$args) {
        $this->params = $args;
    }

    public function execute() {
        try {
            $stmt = $this->pdo->prepare($this->sql);
            $res = $stmt->execute($this->params);
            $this->affected_rows = $stmt->rowCount();
            return $res;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function get_result() {
        try {
            $stmt = $this->pdo->prepare($this->sql);
            $stmt->execute($this->params);
            $this->affected_rows = $stmt->rowCount();
            return new SQLiteDBResult($stmt);
        } catch (\Exception $e) {
            return new SQLiteDBResult(null);
        }
    }
}

class SQLiteDBConn {
    public $connect_error = null;
    private $pdo;

    public function __construct($db_path) {
        try {
            $this->pdo = new PDO("sqlite:" . $db_path);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (\Exception $e) {
            $this->connect_error = $e->getMessage();
        }
    }

    public function set_charset($charset) {}

    public function query($sql) {
        try {
            $sql_clean = preg_replace('/ENGINE=InnoDB/i', '', $sql);
            $sql_clean = preg_replace('/INT AUTO_INCREMENT PRIMARY KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql_clean);
            $sql_clean = preg_replace('/DATETIME DEFAULT CURRENT_TIMESTAMP/i', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $sql_clean);

            if (stripos(trim($sql_clean), 'ALTER TABLE') === 0) {
                try {
                    $this->pdo->exec($sql_clean);
                } catch (\Exception $e) {
                    // Column already exists or alter table unsupported
                }
                return true;
            }

            if (stripos(trim($sql_clean), 'SELECT') === 0) {
                $stmt = $this->pdo->query($sql_clean);
                return new SQLiteDBResult($stmt);
            } else {
                $this->pdo->exec($sql_clean);
                return true;
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    public function prepare($sql) {
        return new SQLiteDBStmt($this->pdo, $sql);
    }

    public function escape_string($str) {
        return addslashes($str);
    }

    public function real_escape_string($str) {
        return addslashes($str);
    }

    public function begin_transaction() {
        return $this->pdo->beginTransaction();
    }

    public function commit() {
        return $this->pdo->commit();
    }

    public function rollback() {
        return $this->pdo->rollBack();
    }
}

// Gumawa ng connection sa MySQL Database
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $user, $pass, $dbname);

// Suriin kung may error sa connection, fallback sa SQLite
if ($conn->connect_error) {
    $conn = new SQLiteDBConn(__DIR__ . '/sibtech.sqlite');
}

if (!$conn->connect_error) {
    $conn->set_charset("utf8mb4");

    // Tiyaking umiiral ang users table
    $conn->query("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fullname TEXT NOT NULL,
            username TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user'
        );
    ");

    // Tiyaking umiiral ang items table
    $conn->query("
        CREATE TABLE IF NOT EXISTS items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_name TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'Office',
            unit TEXT DEFAULT 'pcs',
            actual_stocks INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Tiyaking umiiral ang maintenance_items table
    $conn->query("
        CREATE TABLE IF NOT EXISTS maintenance_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_name TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'Maintenance',
            unit TEXT DEFAULT 'pcs',
            actual_stocks INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Tiyaking umiiral ang supply_requests table
    $conn->query("
        CREATE TABLE IF NOT EXISTS supply_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INT NOT NULL,
            request_group_id TEXT NOT NULL,
            requisitioner_name TEXT NOT NULL,
            department TEXT NOT NULL,
            item_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            purpose TEXT NULL,
            date_needed DATE NULL,
            scheduled_time TEXT NULL DEFAULT '09:00 AM - 10:00 AM',
            status TEXT NOT NULL DEFAULT 'Pending',
            approved_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Tiyaking umiiral ang maintenance_requests table
    $conn->query("
        CREATE TABLE IF NOT EXISTS maintenance_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INT NOT NULL,
            request_group_id TEXT NOT NULL,
            requisitioner_name TEXT NOT NULL,
            department TEXT NOT NULL,
            item_id INT NOT NULL,
            quantity INT NOT NULL DEFAULT 1,
            purpose TEXT NULL,
            date_needed DATE NULL,
            scheduled_time TEXT NULL DEFAULT '09:00 AM - 10:00 AM',
            status TEXT NOT NULL DEFAULT 'Pending',
            approved_at DATETIME NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Tiyaking umiiral ang stock_history table
    $conn->query("
        CREATE TABLE IF NOT EXISTS stock_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INT NOT NULL,
            item_name TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'Office',
            previous_stock INT NOT NULL DEFAULT 0,
            new_stock INT NOT NULL DEFAULT 0,
            added_qty INT NOT NULL DEFAULT 0,
            updated_by TEXT NOT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Tiyaking umiiral ang notifications table
    $conn->query("
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INT NOT NULL,
            message TEXT NOT NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Tiyaking umiiral ang borrow_requests table
    $conn->query("
        CREATE TABLE IF NOT EXISTS borrow_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INT NOT NULL,
            request_group_id TEXT NOT NULL,
            requisitioner_name TEXT NOT NULL,
            department TEXT NOT NULL,
            item_id INT NOT NULL DEFAULT 0,
            item_name TEXT NULL,
            quantity INT NOT NULL DEFAULT 1,
            borrow_date DATE NOT NULL,
            expected_return_date DATE NOT NULL,
            scheduled_time TEXT NULL DEFAULT '09:00 AM - 10:00 AM',
            purpose TEXT NULL,
            status TEXT NOT NULL DEFAULT 'Pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    @$conn->query("ALTER TABLE borrow_requests ADD COLUMN item_name TEXT NULL");

    // Add scheduled_time column to supply_requests and maintenance_requests if they exist
    @$conn->query("ALTER TABLE supply_requests ADD COLUMN scheduled_time TEXT NULL DEFAULT '09:00 AM - 10:00 AM'");
    @$conn->query("ALTER TABLE maintenance_requests ADD COLUMN scheduled_time TEXT NULL DEFAULT '09:00 AM - 10:00 AM'");

    // Tiyaking umiiral ang document_printing_requests table
    $conn->query("
        CREATE TABLE IF NOT EXISTS document_printing_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INT NOT NULL,
            request_group_id TEXT NOT NULL,
            requisitioner_name TEXT NOT NULL,
            department TEXT NOT NULL,
            document_file TEXT NOT NULL,
            paper_size TEXT NOT NULL DEFAULT 'A4',
            print_color TEXT NOT NULL DEFAULT 'Black & White',
            print_sides TEXT NOT NULL DEFAULT 'Single-sided',
            binding_option TEXT NOT NULL DEFAULT 'None',
            page_count INT NOT NULL DEFAULT 1,
            copies INT NOT NULL DEFAULT 1,
            total_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            purpose TEXT NULL,
            date_needed DATE NULL,
            scheduled_time TEXT NULL DEFAULT '09:00 AM - 10:00 AM',
            status TEXT NOT NULL DEFAULT 'Pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Tiyaking umiiral ang calendar_schedules table para sa admin schedule management
    $conn->query("
        CREATE TABLE IF NOT EXISTS calendar_schedules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title TEXT NOT NULL,
            department TEXT NULL,
            event_date DATE NOT NULL,
            scheduled_time TEXT NULL,
            details TEXT NULL,
            created_by TEXT DEFAULT 'Admin',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Seed default admin and user if users table is empty
    $check_users = $conn->query("SELECT COUNT(*) as cnt FROM users");
    $user_count = 0;
    if ($check_users && $row = $check_users->fetch_assoc()) {
        $user_count = intval($row['cnt'] ?? $row['COUNT(*)'] ?? 0);
    }
    if ($user_count === 0) {
        $admin_pass = password_hash('admin123', PASSWORD_BCRYPT);
        $user_pass = password_hash('user123', PASSWORD_BCRYPT);
        $conn->query("INSERT INTO users (fullname, username, password, role) VALUES ('System Admin', 'admin', '$admin_pass', 'admin')");
        $conn->query("INSERT INTO users (fullname, username, password, role) VALUES ('Regular User', 'user', '$user_pass', 'user')");
    }
}
?>