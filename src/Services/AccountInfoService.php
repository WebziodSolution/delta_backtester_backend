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
        $stmt = $db->prepare("SELECT id, user_id, api_key, api_secret, current_margin, active, created_date FROM account_info WHERE id = :id");
        $stmt->execute(['id' => $accountId]);
        $account = $stmt->fetch();
        if (!$account) {
            throw new Exception("Account info with ID {$accountId} not found", 404);
        }
        
        // Cast values
        $account['active'] = (bool)$account['active'];
        $account['user_id'] = (int)$account['user_id'];
        $account['id'] = (int)$account['id'];
        $account['current_margin'] = $account['current_margin'] !== null ? (int)$account['current_margin'] : null;
        return $account;
    }

    public static function getAll(?int $userId = null, int $skip = 0, int $limit = 100): array {
        $db = self::getDb();
        $sql = "SELECT id, user_id, api_key, api_secret, current_margin, active, created_date FROM account_info";
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
            $row['current_margin'] = $row['current_margin'] !== null ? (int)$row['current_margin'] : null;
        }
        return $results;
    }

    public static function create(array $data): array {
        $db = self::getDb();

        $userId = ValidationHelper::validatePositiveInt($data['user_id'] ?? null, 'user_id');
        $apiKey = ValidationHelper::validateNonEmptyStrip($data['api_key'] ?? '', 'api_key');
        $apiSecret = ValidationHelper::validateNonEmptyStrip($data['api_secret'] ?? '', 'api_secret');
        $active = isset($data['active']) ? (bool)$data['active'] : true;

        $currentMargin = isset($data['current_margin']) && $data['current_margin'] !== null ? (int)$data['current_margin'] : null;

        // Verify user exists
        try {
            UserService::getById($userId);
        } catch (Exception $e) {
            throw new Exception("User with ID {$userId} does not exist", 400);
        }

        $stmt = $db->prepare("INSERT INTO account_info (user_id, api_key, api_secret, current_margin, active) VALUES (:user_id, :api_key, :api_secret, :current_margin, :active)");
        $stmt->execute([
            'user_id' => $userId,
            'api_key' => $apiKey,
            'api_secret' => $apiSecret,
            'current_margin' => $currentMargin,
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

        if (array_key_exists('current_margin', $data)) {
            $currentMargin = $data['current_margin'] !== null ? (int)$data['current_margin'] : null;
            $updateFields[] = "current_margin = :current_margin";
            $params['current_margin'] = $currentMargin;
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

    public static function syncCurrentMargin(int $userId): int {
        $db = self::getDb();
        
        // Verify user exists first
        UserService::getById($userId);

        $stmt = $db->prepare("SELECT id, api_key, api_secret FROM account_info WHERE user_id = :user_id AND active = 1");
        $stmt->execute(['user_id' => $userId]);
        $accounts = $stmt->fetchAll();

        $totalMargin = 0;

        foreach ($accounts as $account) {
            $apiKey = $account['api_key'];
            $apiSecret = $account['api_secret'];
            
            // Call Delta Exchange API to get balance
            $deltaClient = new \App\Helpers\DeltaClient($apiKey, $apiSecret);
            list($status, $balancesResp) = $deltaClient->getBalances();

            $margin = 0;
            if ($status === 200 && isset($balancesResp['success']) && $balancesResp['success']) {
                $balances = $balancesResp['result'] ?? [];
                foreach ($balances as $bal) {
                    if (intval($bal['asset_id'] ?? 0) === 14) {
                        $margin = (int)($bal['balance'] ?? $bal['available_balance'] ?? 0.0);
                        break;
                    }
                }
            } else {
                $errorMsg = null;
                if (is_array($balancesResp)) {
                    $errorMsg = $balancesResp['message'] 
                        ?? $balancesResp['error']['message'] 
                        ?? $balancesResp['error']['code'] 
                        ?? (isset($balancesResp['error']) && is_string($balancesResp['error']) ? $balancesResp['error'] : null);
                }
                if ($errorMsg === null) {
                    $errorMsg = json_encode($balancesResp);
                }
                throw new Exception("Failed to sync balance for account ID {$account['id']}: {$errorMsg}", $status ?: 400);
            }

            // Update DB with current margin
            $upStmt = $db->prepare("UPDATE account_info SET current_margin = :current_margin WHERE id = :id");
            $upStmt->execute([
                'current_margin' => $margin,
                'id' => $account['id']
            ]);

            $totalMargin += $margin;
        }

        return $totalMargin;
    }
}
