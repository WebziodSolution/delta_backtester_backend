<?php

namespace App\Controllers;

use App\Common\ApiResponse;
use App\Services\TradeConfigService;
use Exception;

class TradeConfigController {
    public function create(array $data): void {
        try {
            $config = TradeConfigService::create($data);
            ApiResponse::send(201, "Trade config created successfully", $config);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }

    public function list(array $params): void {
        $userId = isset($params['user_id']) ? intval($params['user_id']) : null;
        $skip = isset($params['skip']) ? intval($params['skip']) : 0;
        $limit = isset($params['limit']) ? intval($params['limit']) : 100;

        try {
            $configs = TradeConfigService::getAll($userId, $skip, $limit);
            ApiResponse::send(200, "Trade configs retrieved successfully", $configs);
        } catch (Exception $e) {
            ApiResponse::send(500, $e->getMessage());
        }
    }

    public function retrieve(int $id): void {
        try {
            $config = TradeConfigService::getById($id);
            ApiResponse::send(200, "Trade config retrieved successfully", $config);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 404, $e->getMessage());
        }
    }

    public function update(int $id, array $data): void {
        try {
            $config = TradeConfigService::update($id, $data);
            ApiResponse::send(200, "Trade config updated successfully", $config);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }

    public function delete(int $id): void {
        try {
            $config = TradeConfigService::delete($id);
            ApiResponse::send(200, "Trade config deleted successfully", $config);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 404, $e->getMessage());
        }
    }
}
