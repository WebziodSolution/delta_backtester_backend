<?php

namespace App\Services;

use App\Config\Database;
use App\Helpers\ValidationHelper;
use Exception;
use PDO;

class UserService {
    private static function getDb(): PDO {
        return Database::getInstance()->getConnection();
    }

    public static function getById(int $userId): array {
        $db = self::getDb();
        $stmt = $db->prepare("SELECT id, username, email FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new Exception("User with ID {$userId} not found", 404);
        }
        return $user;
    }

    public static function getByUsername(string $username): ?array {
        $db = self::getDb();
        $stmt = $db->prepare("SELECT id, username, email, password FROM users WHERE username = :username");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function getByEmail(string $email): ?array {
        $db = self::getDb();
        $stmt = $db->prepare("SELECT id, username, email, password FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function getAll(int $skip = 0, int $limit = 100): array {
        $db = self::getDb();
        // Since PDO default binds might treat values as string, we cast as integer or bindParam with type
        $stmt = $db->prepare("SELECT id, username, email FROM users LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $skip, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public static function create(array $data): array {
        $db = self::getDb();
        
        $username = ValidationHelper::validateNonEmptyStrip($data['username'] ?? '', 'username');
        if (strlen($username) < 3) {
            throw new Exception("username must be at least 3 characters", 400);
        }
        $email = ValidationHelper::validateEmail($data['email'] ?? '');
        $password = ValidationHelper::validatePasswordComplexity($data['password'] ?? '');

        // Check uniqueness
        if (self::getByUsername($username)) {
            throw new Exception("Username already registered", 400);
        }
        if (self::getByEmail($email)) {
            throw new Exception("Email already registered", 400);
        }

        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $db->prepare("INSERT INTO users (username, email, password) VALUES (:username, :email, :password)");
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'password' => $hashedPassword
        ]);

        $newId = (int)$db->lastInsertId();
        return [
            'id' => $newId,
            'username' => $username,
            'email' => $email
        ];
    }

    public static function update(int $userId, array $data): array {
        $db = self::getDb();
        $user = self::getById($userId); // Ensures user exists or throws 404

        $updateFields = [];
        $params = ['id' => $userId];

        // Check username uniqueness if changing
        if (isset($data['username'])) {
            $username = ValidationHelper::validateNonEmptyStrip($data['username'], 'username');
            if (strlen($username) < 3) {
                throw new Exception("username must be at least 3 characters", 400);
            }
            if ($username !== $user['username']) {
                if (self::getByUsername($username)) {
                    throw new Exception("Username already registered", 400);
                }
            }
            $updateFields[] = "username = :username";
            $params['username'] = $username;
        }

        // Check email uniqueness if changing
        if (isset($data['email'])) {
            $email = ValidationHelper::validateEmail($data['email']);
            if ($email !== $user['email']) {
                if (self::getByEmail($email)) {
                    throw new Exception("Email already registered", 400);
                }
            }
            $updateFields[] = "email = :email";
            $params['email'] = $email;
        }

        // Hash and update password if provided
        if (!empty($data['password'])) {
            $password = ValidationHelper::validatePasswordComplexity($data['password']);
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $updateFields[] = "password = :password";
            $params['password'] = $hashedPassword;
        }

        if (empty($updateFields)) {
            return $user;
        }

        $sql = "UPDATE users SET " . implode(", ", $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return self::getById($userId);
    }

    public static function delete(int $userId): array {
        $user = self::getById($userId); // Get details to return or throw 404
        $db = self::getDb();
        $stmt = $db->prepare("DELETE FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        return $user;
    }
}
