<?php

namespace App\Controllers;

use App\Common\ApiResponse;
use App\Services\SubscribeStrategyService;
use Exception;

class SubscribeStrategyController {
    public function create(array $data): void {
        try {
            $subscription = SubscribeStrategyService::create($data);
            ApiResponse::send(201, "Strategy subscription created successfully", $subscription);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }

    public function list(array $params): void {
        $userId = isset($params['user_id']) ? intval($params['user_id']) : null;
        $strategyId = isset($params['strategy_id']) ? intval($params['strategy_id']) : null;
        $skip = isset($params['skip']) ? intval($params['skip']) : 0;
        $limit = isset($params['limit']) ? intval($params['limit']) : 100;

        try {
            $subscriptions = SubscribeStrategyService::getAll($userId, $strategyId, $skip, $limit);
            ApiResponse::send(200, "Strategy subscriptions retrieved successfully", $subscriptions);
        } catch (Exception $e) {
            ApiResponse::send(500, $e->getMessage());
        }
    }

    public function retrieve(int $id): void {
        try {
            $subscription = SubscribeStrategyService::getById($id);
            ApiResponse::send(200, "Strategy subscription retrieved successfully", $subscription);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 404, $e->getMessage());
        }
    }

    public function update(int $id, array $data): void {
        try {
            $subscription = SubscribeStrategyService::update($id, $data);
            ApiResponse::send(200, "Strategy subscription updated successfully", $subscription);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }

    public function delete(int $id): void {
        try {
            $subscription = SubscribeStrategyService::delete($id);
            ApiResponse::send(200, "Strategy subscription deleted successfully", $subscription);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 404, $e->getMessage());
        }
    }
}
