<?php

namespace App\Controllers;

use App\Common\ApiResponse;
use App\Services\AccountInfoService;
use Exception;

class AccountInfoController {
    public function create(array $data): void {
        try {
            $account = AccountInfoService::create($data);
            ApiResponse::send(201, "Account info created successfully", $account);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }

    public function list(array $params): void {
        $userId = isset($params['user_id']) ? intval($params['user_id']) : null;
        $skip = isset($params['skip']) ? intval($params['skip']) : 0;
        $limit = isset($params['limit']) ? intval($params['limit']) : 100;

        try {
            $accounts = AccountInfoService::getAll($userId, $skip, $limit);
            ApiResponse::send(200, "Account info list retrieved successfully", $accounts);
        } catch (Exception $e) {
            ApiResponse::send(500, $e->getMessage());
        }
    }

    public function retrieve(int $id): void {
        try {
            $account = AccountInfoService::getById($id);
            ApiResponse::send(200, "Account info retrieved successfully", $account);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 404, $e->getMessage());
        }
    }

    public function update(int $id, array $data): void {
        try {
            $account = AccountInfoService::update($id, $data);
            ApiResponse::send(200, "Account info updated successfully", $account);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }

    public function delete(int $id): void {
        try {
            $account = AccountInfoService::delete($id);
            ApiResponse::send(200, "Account info deleted successfully", $account);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 404, $e->getMessage());
        }
    }

    public function syncMargin(array $data): void {
        $userId = isset($data['user_id']) ? intval($data['user_id']) : null;
        if ($userId === null || $userId <= 0) {
            ApiResponse::send(400, "A valid positive integer user_id is required");
            return;
        }

        try {
            $totalMargin = AccountInfoService::syncCurrentMargin($userId);
            ApiResponse::send(200, "Account margin synchronized successfully", [
                "user_id" => $userId,
                "current_margin" => $totalMargin
            ]);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }
}
