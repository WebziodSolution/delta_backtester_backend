<?php

namespace App\Services;

use App\Config\Database;
use App\Helpers\ValidationHelper;
use Exception;
use PDO;

class TradeConfigService {
    private static function getDb(): PDO {
        return Database::getInstance()->getConnection();
    }

    public static function getById(int $configId): array {
        $db = self::getDb();
        $stmt = $db->prepare("SELECT id, user_id, lot, leverage FROM trade_config WHERE id = :id");
        $stmt->execute(['id' => $configId]);
        $config = $stmt->fetch();
        if (!$config) {
            throw new Exception("Trade config with ID {$configId} not found", 404);
        }
        
        $config['id'] = (int)$config['id'];
        $config['user_id'] = (int)$config['user_id'];
        $config['lot'] = (int)$config['lot'];
        $config['leverage'] = (int)$config['leverage'];
        return $config;
    }

    public static function getAll(?int $userId = null, int $skip = 0, int $limit = 100): array {
        $db = self::getDb();
        $sql = "SELECT id, user_id, lot, leverage FROM trade_config";
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
            $row['id'] = (int)$row['id'];
            $row['user_id'] = (int)$row['user_id'];
            $row['lot'] = (int)$row['lot'];
            $row['leverage'] = (int)$row['leverage'];
        }
        return $results;
    }

    public static function create(array $data): array {
        $db = self::getDb();

        $userId = ValidationHelper::validatePositiveInt($data['user_id'] ?? null, 'user_id');
        $lot = ValidationHelper::validatePositiveInt($data['lot'] ?? null, 'lot');
        $leverage = ValidationHelper::validatePositiveInt($data['leverage'] ?? null, 'leverage');

        // Verify user exists
        try {
            UserService::getById($userId);
        } catch (Exception $e) {
            throw new Exception("User with ID {$userId} does not exist", 400);
        }

        $stmt = $db->prepare("INSERT INTO trade_config (user_id, lot, leverage) VALUES (:user_id, :lot, :leverage)");
        $stmt->execute([
            'user_id' => $userId,
            'lot' => $lot,
            'leverage' => $leverage
        ]);

        $newId = (int)$db->lastInsertId();
        return self::getById($newId);
    }

    public static function update(int $configId, array $data): array {
        $db = self::getDb();
        $config = self::getById($configId); // Ensures exists or throws 404

        $updateFields = [];
        $params = ['id' => $configId];

        if (isset($data['lot'])) {
            $lot = ValidationHelper::validatePositiveInt($data['lot'], 'lot');
            $updateFields[] = "lot = :lot";
            $params['lot'] = $lot;
        }

        if (isset($data['leverage'])) {
            $leverage = ValidationHelper::validatePositiveInt($data['leverage'], 'leverage');
            $updateFields[] = "leverage = :leverage";
            $params['leverage'] = $leverage;
        }

        if (empty($updateFields)) {
            return $config;
        }

        $sql = "UPDATE trade_config SET " . implode(", ", $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return self::getById($configId);
    }

    public static function delete(int $configId): array {
        $config = self::getById($configId); // Ensures exists or throws 404
        $db = self::getDb();
        $stmt = $db->prepare("DELETE FROM trade_config WHERE id = :id");
        $stmt->execute(['id' => $configId]);
        return $config;
    }
}
