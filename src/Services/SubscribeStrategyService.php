<?php

namespace App\Services;

use App\Config\Database;
use App\Helpers\ValidationHelper;
use Exception;
use PDO;

class SubscribeStrategyService {
    private static function getDb(): PDO {
        return Database::getInstance()->getConnection();
    }

    public static function getById(int $id): array {
        $db = self::getDb();
        $stmt = $db->prepare("SELECT id, user_id, strategy_id, asset, margin_allocation, leverage, lot_size FROM subscribe_strategys WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $subscription = $stmt->fetch();
        
        if (!$subscription) {
            throw new Exception("Subscription with ID {$id} not found", 404);
        }

        // Cast values
        $subscription['id'] = (int)$subscription['id'];
        $subscription['user_id'] = (int)$subscription['user_id'];
        $subscription['strategy_id'] = (int)$subscription['strategy_id'];
        $subscription['margin_allocation'] = $subscription['margin_allocation'] !== null ? (int)$subscription['margin_allocation'] : null;
        $subscription['leverage'] = $subscription['leverage'] !== null ? (int)$subscription['leverage'] : null;
        $subscription['lot_size'] = $subscription['lot_size'] !== null ? (int)$subscription['lot_size'] : null;

        return $subscription;
    }

    public static function getAll(?int $userId = null, ?int $strategyId = null, int $skip = 0, int $limit = 100): array {
        $db = self::getDb();
        $sql = "SELECT id, user_id, strategy_id, asset, margin_allocation, leverage, lot_size FROM subscribe_strategys";
        $where = [];
        $params = [];

        if ($userId !== null) {
            $where[] = "user_id = :user_id";
            $params['user_id'] = $userId;
        }

        if ($strategyId !== null) {
            $where[] = "strategy_id = :strategy_id";
            $params['strategy_id'] = $strategyId;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
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
            $row['strategy_id'] = (int)$row['strategy_id'];
            $row['margin_allocation'] = $row['margin_allocation'] !== null ? (int)$row['margin_allocation'] : null;
            $row['leverage'] = $row['leverage'] !== null ? (int)$row['leverage'] : null;
            $row['lot_size'] = $row['lot_size'] !== null ? (int)$row['lot_size'] : null;
        }
        return $results;
    }

    public static function create(array $data): array {
        $db = self::getDb();

        $userId = ValidationHelper::validatePositiveInt($data['user_id'] ?? null, 'user_id');
        $strategyId = ValidationHelper::validatePositiveInt($data['strategy_id'] ?? null, 'strategy_id');
        
        $asset = ValidationHelper::validateNonEmptyStrip($data['asset'] ?? '', 'asset');
        if (strlen($asset) > 100) {
            throw new Exception("asset must be 100 characters or fewer", 400);
        }

        $marginAllocation = null;
        if (isset($data['margin_allocation']) && $data['margin_allocation'] !== null && $data['margin_allocation'] !== '') {
            $marginAllocation = ValidationHelper::validatePositiveInt($data['margin_allocation'], 'margin_allocation');
        }

        $leverage = null;
        if (isset($data['leverage']) && $data['leverage'] !== null && $data['leverage'] !== '') {
            $leverage = ValidationHelper::validatePositiveInt($data['leverage'], 'leverage');
        }

        $lotSize = null;
        if (isset($data['lot_size']) && $data['lot_size'] !== null && $data['lot_size'] !== '') {
            $lotSize = ValidationHelper::validatePositiveInt($data['lot_size'], 'lot_size');
        }

        // Verify user exists
        try {
            UserService::getById($userId);
        } catch (Exception $e) {
            throw new Exception("User with ID {$userId} does not exist", 400);
        }

        // Verify strategy exists
        try {
            StrategyService::getById($strategyId);
        } catch (Exception $e) {
            throw new Exception("Strategy with ID {$strategyId} does not exist", 400);
        }

        // Check if already subscribed to prevent duplicates
        $stmt = $db->prepare("SELECT id FROM subscribe_strategys WHERE user_id = :user_id AND strategy_id = :strategy_id");
        $stmt->execute(['user_id' => $userId, 'strategy_id' => $strategyId]);
        if ($stmt->fetch()) {
            throw new Exception("User is already subscribed to this strategy", 400);
        }

        $stmt = $db->prepare("INSERT INTO subscribe_strategys (user_id, strategy_id, asset, margin_allocation, leverage, lot_size) VALUES (:user_id, :strategy_id, :asset, :margin_allocation, :leverage, :lot_size)");
        $stmt->execute([
            'user_id' => $userId,
            'strategy_id' => $strategyId,
            'asset' => $asset,
            'margin_allocation' => $marginAllocation,
            'leverage' => $leverage,
            'lot_size' => $lotSize
        ]);

        $newId = (int)$db->lastInsertId();
        return self::getById($newId);
    }

    public static function update(int $id, array $data): array {
        $db = self::getDb();
        $subscription = self::getById($id); // Ensures exists or throws 404

        $updateFields = [];
        $params = ['id' => $id];

        if (isset($data['user_id'])) {
            $userId = ValidationHelper::validatePositiveInt($data['user_id'], 'user_id');
            // Verify user exists
            try {
                UserService::getById($userId);
            } catch (Exception $e) {
                throw new Exception("User with ID {$userId} does not exist", 400);
            }
            $updateFields[] = "user_id = :user_id";
            $params['user_id'] = $userId;
        }

        if (isset($data['strategy_id'])) {
            $strategyId = ValidationHelper::validatePositiveInt($data['strategy_id'], 'strategy_id');
            // Verify strategy exists
            try {
                StrategyService::getById($strategyId);
            } catch (Exception $e) {
                throw new Exception("Strategy with ID {$strategyId} does not exist", 400);
            }
            $updateFields[] = "strategy_id = :strategy_id";
            $params['strategy_id'] = $strategyId;
        }

        if (isset($data['asset'])) {
            $asset = ValidationHelper::validateNonEmptyStrip($data['asset'], 'asset');
            if (strlen($asset) > 100) {
                throw new Exception("asset must be 100 characters or fewer", 400);
            }
            $updateFields[] = "asset = :asset";
            $params['asset'] = $asset;
        }

        if (array_key_exists('margin_allocation', $data)) {
            $marginAllocation = null;
            if ($data['margin_allocation'] !== null && $data['margin_allocation'] !== '') {
                $marginAllocation = ValidationHelper::validatePositiveInt($data['margin_allocation'], 'margin_allocation');
            }
            $updateFields[] = "margin_allocation = :margin_allocation";
            $params['margin_allocation'] = $marginAllocation;
        }

        if (array_key_exists('leverage', $data)) {
            $leverage = null;
            if ($data['leverage'] !== null && $data['leverage'] !== '') {
                $leverage = ValidationHelper::validatePositiveInt($data['leverage'], 'leverage');
            }
            $updateFields[] = "leverage = :leverage";
            $params['leverage'] = $leverage;
        }

        if (array_key_exists('lot_size', $data)) {
            $lotSize = null;
            if ($data['lot_size'] !== null && $data['lot_size'] !== '') {
                $lotSize = ValidationHelper::validatePositiveInt($data['lot_size'], 'lot_size');
            }
            $updateFields[] = "lot_size = :lot_size";
            $params['lot_size'] = $lotSize;
        }

        if (empty($updateFields)) {
            return $subscription;
        }

        // If both are updated or one is updated, check for duplicates
        $checkUserId = isset($params['user_id']) ? $params['user_id'] : $subscription['user_id'];
        $checkStrategyId = isset($params['strategy_id']) ? $params['strategy_id'] : $subscription['strategy_id'];
        if ($checkUserId !== $subscription['user_id'] || $checkStrategyId !== $subscription['strategy_id']) {
            $stmt = $db->prepare("SELECT id FROM subscribe_strategys WHERE user_id = :user_id AND strategy_id = :strategy_id AND id != :id");
            $stmt->execute(['user_id' => $checkUserId, 'strategy_id' => $checkStrategyId, 'id' => $id]);
            if ($stmt->fetch()) {
                throw new Exception("User is already subscribed to this strategy", 400);
            }
        }

        $sql = "UPDATE subscribe_strategys SET " . implode(", ", $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return self::getById($id);
    }

    public static function delete(int $id): array {
        $subscription = self::getById($id); // Get details to return or throw 404
        $db = self::getDb();
        $stmt = $db->prepare("DELETE FROM subscribe_strategys WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $subscription;
    }
}
