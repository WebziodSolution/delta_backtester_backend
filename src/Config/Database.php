<?php

namespace App\Config;

use PDO;
use PDOException;

class Database {
    private static ?Database $instance = null;
    private PDO $conn;

    private function __construct() {
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $port = $_ENV['DB_PORT'] ?? '3306';
        $db = $_ENV['DB_NAME'] ?? 'delta_backtester';
        $user = $_ENV['DB_USER'] ?? 'root';
        $pass = $_ENV['DB_PASSWORD'] ?? '';

        try {
            // Connect to MySQL server without selecting a specific database first to check/create the DB
            $dsn = "mysql:host=$host;port=$port;charset=utf8mb4";
            $tempConn = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            $tempConn->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $tempConn = null;

            // Now connect to the target database
            $this->conn = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Auto-initialize tables
            $this->initializeTables();
        } catch (PDOException $e) {
            throw new PDOException("Database Connection Error: " . $e->getMessage());
        }
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection(): PDO {
        return $this->conn;
    }

    private function initializeTables(): void {
        $sql = "
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(100) NOT NULL UNIQUE,
                email VARCHAR(255) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS account_info (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                api_key TEXT NOT NULL,
                api_secret TEXT NOT NULL,
                current_margin INT NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                created_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS trade_config (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                lot INT NOT NULL,
                leverage INT NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS strategys (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description VARCHAR(255) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS subscribe_strategys (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                strategy_id INT NOT NULL,
                asset VARCHAR(100) NOT NULL,
                margin_allocation INT NULL,
                leverage INT NULL,
                lot_size INT NULL,
                peak_balance DOUBLE NULL,
                allocated_balance DOUBLE NULL,
                current_balance DOUBLE NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE ON UPDATE RESTRICT,
                FOREIGN KEY (strategy_id) REFERENCES strategys(id) ON DELETE CASCADE ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS orders_info (
                id INT AUTO_INCREMENT PRIMARY KEY,
                order_id VARCHAR(255) NULL,
                order_name VARCHAR(255) NULL,
                order_type VARCHAR(255) NULL,
                entry_amount FLOAT NULL,
                exit_amount FLOAT NULL,
                pnl FLOAT NULL,
                broker_fees FLOAT NULL,
                tp_price DOUBLE NULL,
                sl_price DOUBLE NULL,
                trade_action VARCHAR(20) NULL,
                tp_order_id VARCHAR(255) NULL,
                sl_order_id VARCHAR(255) NULL,
                qty INT NULL,
                status VARCHAR(100) NULL,
                account_info_id INT NULL,
                user_id INT NULL,
                strategy_id INT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (account_info_id) REFERENCES account_info(id) ON DELETE CASCADE,
                FOREIGN KEY (strategy_id) REFERENCES strategys(id) ON DELETE CASCADE ON UPDATE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

            CREATE TABLE IF NOT EXISTS password_resets (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                code VARCHAR(6) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";

        $this->conn->exec($sql);
    }
}
