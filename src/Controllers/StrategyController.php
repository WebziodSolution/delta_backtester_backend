<?php

namespace App\Controllers;

use App\Common\ApiResponse;
use App\Services\StrategyService;
use Exception;

class StrategyController {
    public function create(array $data): void {
        try {
            $strategy = StrategyService::create($data);
            ApiResponse::send(201, "Strategy created successfully", $strategy);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }

    public function list(array $params): void {
        $skip = isset($params['skip']) ? intval($params['skip']) : 0;
        $limit = isset($params['limit']) ? intval($params['limit']) : 100;

        try {
            $strategies = StrategyService::getAll($skip, $limit);
            ApiResponse::send(200, "Strategies retrieved successfully", $strategies);
        } catch (Exception $e) {
            ApiResponse::send(500, $e->getMessage());
        }
    }

    public function retrieve(int $id): void {
        try {
            $strategy = StrategyService::getById($id);
            ApiResponse::send(200, "Strategy retrieved successfully", $strategy);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 404, $e->getMessage());
        }
    }

    public function update(int $id, array $data): void {
        try {
            $strategy = StrategyService::update($id, $data);
            ApiResponse::send(200, "Strategy updated successfully", $strategy);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }

    public function delete(int $id): void {
        try {
            $strategy = StrategyService::delete($id);
            ApiResponse::send(200, "Strategy deleted successfully", $strategy);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 404, $e->getMessage());
        }
    }
}
