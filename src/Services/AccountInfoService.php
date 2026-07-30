<?php

namespace App\Services;

use App\Config\Database;
use App\Helpers\ValidationHelper;
use Exception;
use PDO;

class AccountInfoService {
    private static function getDb(): PDO {
        return Database::getInstance()->getConnection();
    }

    public static function getById(int $accountId): array {
        $db = self::getDb();
        $stmt = $db->prepare("SELECT id, user_id, api_key, api_secret, active, created_date FROM account_info WHERE id = :id");
        $stmt->execute(['id' => $accountId]);
        $account = $stmt->fetch();
        if (!$account) {
            throw new Exception("Account info with ID {$accountId} not found", 404);
        }
        
        // Cast active to bool
        $account['active'] = (bool)$account['active'];
        $account['user_id'] = (int)$account['user_id'];
        $account['id'] = (int)$account['id'];
        return $account;
    }

    public static function getAll(?int $userId = null, int $skip = 0, int $limit = 100): array {
        $db = self::getDb();
        $sql = "SELECT id, user_id, api_key, api_secret, active, created_date FROM account_info";
        $params = [];
        
        if ($userId !== null) {
            $sql .= " WHERE user_id = :user_id";
            $params['user_id'] = $userId;
        }

        $sql .= " LIMIT :limit OFFSET :offset";
        
        $stmt = $db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . $key, $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $skip, PDO::PARAM_INT);
        $stmt->execute();
        
        $results = $stmt->fetchAll();
        foreach ($results as &$row) {
            $row['active'] = (bool)$row['active'];
            $row['user_id'] = (int)$row['user_id'];
            $row['id'] = (int)$row['id'];
        }
        return $results;
    }

    public static function create(array $data): array {
        $db = self::getDb();

        $userId = ValidationHelper::validatePositiveInt($data['user_id'] ?? null, 'user_id');
        $apiKey = ValidationHelper::validateNonEmptyStrip($data['api_key'] ?? '', 'api_key');
        $apiSecret = ValidationHelper::validateNonEmptyStrip($data['api_secret'] ?? '', 'api_secret');
        $active = isset($data['active']) ? (bool)$data['active'] : true;

        // Verify user exists
        try {
            UserService::getById($userId);
        } catch (Exception $e) {
            throw new Exception("User with ID {$userId} does not exist", 400);
        }

        $stmt = $db->prepare("INSERT INTO account_info (user_id, api_key, api_secret, active) VALUES (:user_id, :api_key, :api_secret, :active)");
        $stmt->execute([
            'user_id' => $userId,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'active' => $active ? 1 : 0
        ]);

        $newId = (int)$db->lastInsertId();
        return self::getById($newId);
    }

    public static function update(int $accountId, array $data): array {
        $db = self::getDb();
        $account = self::getById($accountId); // Ensures exists or throws 404

        $updateFields = [];
        $params = ['id' => $accountId];

        if (isset($data['api_key'])) {
            $apiKey = ValidationHelper::validateNonEmptyStrip($data['api_key'], 'api_key');
            $updateFields[] = "api_key = :api_key";
            $params['api_key'] = $apiKey;
        }

        if (isset($data['api_secret'])) {
            $apiSecret = ValidationHelper::validateNonEmptyStrip($data['api_secret'], 'api_secret');
            $updateFields[] = "api_secret = :api_secret";
            $params['api_secret'] = $apiSecret;
        }

        if (isset($data['active'])) {
            $active = (bool)$data['active'] ? 1 : 0;
            $updateFields[] = "active = :active";
            $params['active'] = $active;
        }

        if (empty($updateFields)) {
            return $account;
        }

        $sql = "UPDATE account_info SET " . implode(", ", $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return self::getById($accountId);
    }

    public static function delete(int $accountId): array {
        $account = self::getById($accountId); // Ensures exists or throws 404
        $db = self::getDb();
        $stmt = $db->prepare("DELETE FROM account_info WHERE id = :id");
        $stmt->execute(['id' => $accountId]);
        return $account;
    }
}
