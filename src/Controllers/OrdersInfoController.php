<?php

namespace App\Controllers;

use App\Common\ApiResponse;
use App\Services\OrdersInfoService;
use Exception;

class OrdersInfoController {
    public function create(array $data): void {
        try {
            $order = OrdersInfoService::create($data);
            ApiResponse::send(201, "Order created successfully", $order);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }

    public function list(array $params): void {
        $userId = isset($params['user_id']) ? intval($params['user_id']) : null;
        $accountInfoId = isset($params['account_info_id']) ? intval($params['account_info_id']) : null;
        $skip = isset($params['skip']) ? intval($params['skip']) : 0;
        $limit = isset($params['limit']) ? intval($params['limit']) : 100;
        $startDate = isset($params['start_date']) ? $params['start_date'] : null;
        $endDate = isset($params['end_date']) ? $params['end_date'] : null;
        $timeZone = isset($params['time_zone']) ? $params['time_zone'] : null;

        try {
            $orders = OrdersInfoService::getAll($userId, $accountInfoId, $skip, $limit, $startDate, $endDate, $timeZone);
            ApiResponse::send(200, "Orders retrieved successfully", $orders);
        } catch (Exception $e) {
            ApiResponse::send(500, $e->getMessage());
        }
    }

    public function retrieve(int $id): void {
        try {
            $timeZone = isset($_GET['time_zone']) ? $_GET['time_zone'] : null;
            $order = OrdersInfoService::getById($id, $timeZone);
            ApiResponse::send(200, "Order retrieved successfully", $order);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 404, $e->getMessage());
        }
    }

    public function update(int $id, array $data): void {
        try {
            $order = OrdersInfoService::update($id, $data);
            ApiResponse::send(200, "Order updated successfully", $order);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }

    public function delete(int $id): void {
        try {
            $order = OrdersInfoService::delete($id);
            ApiResponse::send(200, "Order deleted successfully", $order);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 404, $e->getMessage());
        }
    }
}
