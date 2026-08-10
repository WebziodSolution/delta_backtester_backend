<?php

namespace App\Services;

use App\Config\Database;
use Exception;
use PDO;

class OrdersInfoService {
    private static function getDb(): PDO {
        return Database::getInstance()->getConnection();
    }

    public static function getById(int $id, ?string $timeZone = null): array {
        $db = self::getDb();
        $stmt = $db->prepare("SELECT id, order_id, order_name, order_type, entry_amount, exit_amount, pnl, broker_fees, qty, status, account_info_id, user_id, strategy_id, created_at, updated_at FROM orders_info WHERE id = :id");
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
        $order['strategy_id'] = $order['strategy_id'] !== null ? (int)$order['strategy_id'] : null;

        if ($order['created_at'] !== null) {
            try {
                $dt = new \DateTime($order['created_at'], new \DateTimeZone('UTC'));
                if ($timeZone) {
                    $dt->setTimezone(new \DateTimeZone($timeZone));
                }
                $order['created_at'] = $dt->format('Y-m-d\TH:i:s');
            } catch (\Exception $e) {
                $order['created_at'] = str_replace(' ', 'T', $order['created_at']);
            }
        }
        if ($order['updated_at'] !== null) {
            try {
                $dt = new \DateTime($order['updated_at'], new \DateTimeZone('UTC'));
                if ($timeZone) {
                    $dt->setTimezone(new \DateTimeZone($timeZone));
                }
                $order['updated_at'] = $dt->format('Y-m-d\TH:i:s');
            } catch (\Exception $e) {
                $order['updated_at'] = str_replace(' ', 'T', $order['updated_at']);
            }
        }
        return $order;
    }

    public static function getAll(?int $userId = null, ?int $accountInfoId = null, int $skip = 0, int $limit = 100, ?string $startDate = null, ?string $endDate = null, ?string $timeZone = null, ?int $strategyId = null): array {
        $db = self::getDb();
        $sql = "SELECT id, order_id, order_name, order_type, entry_amount, exit_amount, pnl, broker_fees, qty, status, account_info_id, user_id, strategy_id, created_at, updated_at FROM orders_info";
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
            $row['entry_amount'] = $row['entry_amount'] !== null ? (float)$row['entry_amount'] : null;
            $row['exit_amount'] = $row['exit_amount'] !== null ? (float)$row['exit_amount'] : null;
            $row['pnl'] = $row['pnl'] !== null ? (float)$row['pnl'] : null;
            $row['broker_fees'] = $row['broker_fees'] !== null ? (float)$row['broker_fees'] : null;
            $row['qty'] = $row['qty'] !== null ? (int)$row['qty'] : null;
            $row['account_info_id'] = $row['account_info_id'] !== null ? (int)$row['account_info_id'] : null;
            $row['user_id'] = $row['user_id'] !== null ? (int)$row['user_id'] : null;
            $row['strategy_id'] = $row['strategy_id'] !== null ? (int)$row['strategy_id'] : null;

            if ($row['created_at'] !== null) {
                try {
                    $dt = new \DateTime($row['created_at'], new \DateTimeZone('UTC'));
                    if ($timeZone) {
                        $dt->setTimezone(new \DateTimeZone($timeZone));
                    }
                    $row['created_at'] = $dt->format('Y-m-d\TH:i:s');
                } catch (\Exception $e) {
                    $row['created_at'] = str_replace(' ', 'T', $row['created_at']);
                }
            }
            if ($row['updated_at'] !== null) {
                try {
                    $dt = new \DateTime($row['updated_at'], new \DateTimeZone('UTC'));
                    if ($timeZone) {
                        $dt->setTimezone(new \DateTimeZone($timeZone));
                    }
                    $row['updated_at'] = $dt->format('Y-m-d\TH:i:s');
                } catch (\Exception $e) {
                    $row['updated_at'] = str_replace(' ', 'T', $row['updated_at']);
                }
            }
        }
        return $results;
    }

    public static function create(array $data): array {
        $db = self::getDb();

        $userId = isset($data['user_id']) ? (int)$data['user_id'] : null;
        $accountInfoId = isset($data['account_info_id']) ? (int)$data['account_info_id'] : null;
        $strategyId = isset($data['strategy_id']) ? (int)$data['strategy_id'] : null;

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

        // Verify strategy exists if provided
        if ($strategyId !== null) {
            try {
                StrategyService::getById($strategyId);
            } catch (Exception $e) {
                throw new Exception("Strategy with ID {$strategyId} does not exist", 400);
            }
        }

        $stmt = $db->prepare("INSERT INTO orders_info (order_id, order_name, order_type, entry_amount, exit_amount, pnl, broker_fees, qty, status, account_info_id, user_id, strategy_id) 
                              VALUES (:order_id, :order_name, :order_type, :entry_amount, :exit_amount, :pnl, :broker_fees, :qty, :status, :account_info_id, :user_id, :strategy_id)");
        
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
            'user_id' => $userId,
            'strategy_id' => $strategyId
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
            'pnl', 'broker_fees', 'qty', 'status', 'account_info_id', 'user_id', 'strategy_id'
        ];

        // Verify strategy exists if provided
        if (isset($data['strategy_id'])) {
            $strategyId = (int)$data['strategy_id'];
            try {
                StrategyService::getById($strategyId);
            } catch (Exception $e) {
                throw new Exception("Strategy with ID {$strategyId} does not exist", 400);
            }
        }

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

    public static function getPerformanceByStrategyId(int $strategyId, ?int $userId = null): array {
        $db = self::getDb();

        $capitalBase = 0;
        if ($userId !== null) {
            $stmt = $db->prepare("SELECT SUM(current_margin) as total_margin FROM account_info WHERE user_id = :user_id AND active = 1");
            $stmt->execute(['user_id' => $userId]);
            $res = $stmt->fetch();
            $capitalBase = isset($res['total_margin']) ? (int)$res['total_margin'] : 0;

            if ($capitalBase <= 0) {
                try {
                    $stmt = $db->prepare("SELECT margin_allocation FROM subscribe_strategys WHERE strategy_id = :strategy_id AND user_id = :user_id");
                    $stmt->execute(['strategy_id' => $strategyId, 'user_id' => $userId]);
                    $sub = $stmt->fetch();
                    $capitalBase = isset($sub['margin_allocation']) ? (int)$sub['margin_allocation'] : 0;
                } catch (Exception $e) {
                    // Ignore
                }
            }
        }

        if ($capitalBase <= 0) {
            throw new Exception("Capital base (margin) is not configured for user. Please set margin_allocation on the strategy subscription or synchronize current_margin.", 400);
        }

        $sql = "SELECT id, pnl FROM orders_info WHERE strategy_id = :strategy_id AND status = 'closed'";
        $params = ['strategy_id' => $strategyId];
        if ($userId !== null) {
            $sql .= " AND user_id = :user_id";
            $params['user_id'] = $userId;
        }
        $sql .= " ORDER BY id ASC";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $trades = $stmt->fetchAll();

        $totalTrades = count($trades);
        $totalPnl = 0.0;
        $winCount = 0;
        
        $winningReturnsSum = 0.0;
        $winningCount = 0;
        
        $losingReturnsSum = 0.0;
        $losingCount = 0;

        $equity = (float)$capitalBase;
        $peak = (float)$capitalBase;
        $maxDrawdown = 0.0;

        foreach ($trades as $trade) {
            $pnl = (float)($trade['pnl'] ?? 0.0);
            $totalPnl += $pnl;

            $tradeReturnPct = ($pnl / $capitalBase) * 100;

            if ($pnl > 0) {
                $winCount++;
                $winningReturnsSum += $tradeReturnPct;
                $winningCount++;
            } elseif ($pnl < 0) {
                $losingReturnsSum += $tradeReturnPct;
                $losingCount++;
            }

            // MDD calculation
            $equity += $pnl;
            if ($equity > $peak) {
                $peak = $equity;
            }
            if ($peak > 0) {
                $drawdown = (($peak - $equity) / $peak) * 100;
                if ($drawdown > $maxDrawdown) {
                    $maxDrawdown = $drawdown;
                }
            }
        }

        $winRate = $totalTrades > 0 ? ($winCount / $totalTrades) * 100 : 0.0;
        $avgProfit = $winningCount > 0 ? ($winningReturnsSum / $winningCount) : 0.0;
        $avgLoss = $losingCount > 0 ? ($losingReturnsSum / $losingCount) : 0.0;

        return [
            "strategy_id" => $strategyId,
            "user_id" => $userId,
            "capital_base" => $capitalBase,
            "total_pnl" => round($totalPnl, 4),
            "no_of_trades" => $totalTrades,
            "win_rate_pct" => round($winRate, 2),
            "avg_profit_per_trade_pct" => round($avgProfit, 4),
            "avg_loss_per_trade_pct" => round($avgLoss, 4),
            "max_drawdown_pct" => round($maxDrawdown, 4)
        ];
    }
}
