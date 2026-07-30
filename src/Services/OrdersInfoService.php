<?php

namespace App\Services;

use App\Config\Database;
use Exception;
use PDO;

class OrdersInfoService {
    private static function getDb(): PDO {
        return Database::getInstance()->getConnection();
    }

    public static function getById(int $id): array {
        $db = self::getDb();
        $stmt = $db->prepare("SELECT id, order_id, order_name, order_type, entry_amount, exit_amount, pnl, broker_fees, qty, status, account_info_id, user_id, created_at, updated_at FROM orders_info WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $order = $stmt->fetch();
        if (!$order) {
            throw new Exception("Order with ID {$id} not found", 404);
        }
        
        // Cast numerical values
        $order['id'] = (int)$order['id'];
        $order['entry_amount'] = $order['entry_amount'] !== null ? (float)$order['entry_amount'] : null;
        $order['exit_amount'] = $order['exit_amount'] !== null ? (float)$order['exit_amount'] : null;
        $order['pnl'] = $order['pnl'] !== null ? (float)$order['pnl'] : null;
        $order['broker_fees'] = $order['broker_fees'] !== null ? (float)$order['broker_fees'] : null;
        $order['qty'] = $order['qty'] !== null ? (int)$order['qty'] : null;
        $order['account_info_id'] = $order['account_info_id'] !== null ? (int)$order['account_info_id'] : null;
        $order['user_id'] = $order['user_id'] !== null ? (int)$order['user_id'] : null;
        $order['created_at'] = $order['created_at'] !== null ? str_replace(' ', 'T', $order['created_at']) : null;
        $order['updated_at'] = $order['updated_at'] !== null ? str_replace(' ', 'T', $order['updated_at']) : null;
        return $order;
    }

    public static function getAll(?int $userId = null, ?int $accountInfoId = null, int $skip = 0, int $limit = 100, ?string $startDate = null, ?string $endDate = null): array {
        $db = self::getDb();
        $sql = "SELECT id, order_id, order_name, order_type, entry_amount, exit_amount, pnl, broker_fees, qty, status, account_info_id, user_id, created_at, updated_at FROM orders_info";
        $where = [];
        $params = [];

        if ($userId !== null) {
            $where[] = "user_id = :user_id";
            $params['user_id'] = $userId;
        }

        if ($accountInfoId !== null) {
            $where[] = "account_info_id = :account_info_id";
            $params['account_info_id'] = $accountInfoId;
        }

        if ($startDate !== null) {
            $where[] = "DATE(created_at) >= :start_date";
            $params['start_date'] = $startDate;
        }

        if ($endDate !== null) {
            $where[] = "DATE(created_at) <= :end_date";
            $params['end_date'] = $endDate;
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
            $row['entry_amount'] = $row['entry_amount'] !== null ? (float)$row['entry_amount'] : null;
            $row['exit_amount'] = $row['exit_amount'] !== null ? (float)$row['exit_amount'] : null;
            $row['pnl'] = $row['pnl'] !== null ? (float)$row['pnl'] : null;
            $row['broker_fees'] = $row['broker_fees'] !== null ? (float)$row['broker_fees'] : null;
            $row['qty'] = $row['qty'] !== null ? (int)$row['qty'] : null;
            $row['account_info_id'] = $row['account_info_id'] !== null ? (int)$row['account_info_id'] : null;
            $row['user_id'] = $row['user_id'] !== null ? (int)$row['user_id'] : null;
            $row['created_at'] = $row['created_at'] !== null ? str_replace(' ', 'T', $row['created_at']) : null;
            $row['updated_at'] = $row['updated_at'] !== null ? str_replace(' ', 'T', $row['updated_at']) : null;
        }
        return $results;
    }

    public static function create(array $data): array {
        $db = self::getDb();

        $userId = isset($data['user_id']) ? (int)$data['user_id'] : null;
        $accountInfoId = isset($data['account_info_id']) ? (int)$data['account_info_id'] : null;

        // Verify user exists if provided
        if ($userId !== null) {
            try {
                UserService::getById($userId);
            } catch (Exception $e) {
                throw new Exception("User with ID {$userId} does not exist", 400);
            }
        }

        // Verify account info exists if provided
        if ($accountInfoId !== null) {
            try {
                AccountInfoService::getById($accountInfoId);
            } catch (Exception $e) {
                throw new Exception("AccountInfo with ID {$accountInfoId} does not exist", 400);
            }
        }

        $stmt = $db->prepare("INSERT INTO orders_info (order_id, order_name, order_type, entry_amount, exit_amount, pnl, broker_fees, qty, status, account_info_id, user_id) 
                              VALUES (:order_id, :order_name, :order_type, :entry_amount, :exit_amount, :pnl, :broker_fees, :qty, :status, :account_info_id, :user_id)");
        
        $stmt->execute([
            'order_id' => $data['order_id'] ?? null,
            'order_name' => $data['order_name'] ?? null,
            'order_type' => $data['order_type'] ?? null,
            'entry_amount' => isset($data['entry_amount']) ? (float)$data['entry_amount'] : null,
            'exit_amount' => isset($data['exit_amount']) ? (float)$data['exit_amount'] : 0.0,
            'pnl' => isset($data['pnl']) ? (float)$data['pnl'] : 0.0,
            'broker_fees' => isset($data['broker_fees']) ? (float)$data['broker_fees'] : 0.0,
            'qty' => isset($data['qty']) ? (int)$data['qty'] : null,
            'status' => $data['status'] ?? 'open',
            'account_info_id' => $accountInfoId,
            'user_id' => $userId
        ]);

        $newId = (int)$db->lastInsertId();
        return self::getById($newId);
    }

    public static function update(int $id, array $data): array {
        $db = self::getDb();
        $order = self::getById($id); // Ensures exists or throws 404

        $userId = isset($data['user_id']) ? (int)$data['user_id'] : null;
        $accountInfoId = isset($data['account_info_id']) ? (int)$data['account_info_id'] : null;

        // Verify user exists if provided
        if ($userId !== null) {
            try {
                UserService::getById($userId);
            } catch (Exception $e) {
                throw new Exception("User with ID {$userId} does not exist", 400);
            }
        }

        // Verify account info exists if provided
        if ($accountInfoId !== null) {
            try {
                AccountInfoService::getById($accountInfoId);
            } catch (Exception $e) {
                throw new Exception("AccountInfo with ID {$accountInfoId} does not exist", 400);
            }
        }

        $allowedFields = [
            'order_id', 'order_name', 'order_type', 'entry_amount', 'exit_amount',
            'pnl', 'broker_fees', 'qty', 'status', 'account_info_id', 'user_id'
        ];

        $updateFields = [];
        $params = ['id' => $id];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updateFields[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }

        if (empty($updateFields)) {
            return $order;
        }

        $sql = "UPDATE orders_info SET " . implode(", ", $updateFields) . " WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);

        return self::getById($id);
    }

    public static function delete(int $id): array {
        $order = self::getById($id); // Ensures exists or throws 404
        $db = self::getDb();
        $stmt = $db->prepare("DELETE FROM orders_info WHERE id = :id");
        $stmt->execute(['id' => $id]);
        return $order;
    }
}
