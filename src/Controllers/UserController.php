<?php

namespace App\Controllers;

use App\Common\ApiResponse;
use App\Services\UserService;
use Exception;

class UserController {
    public function create(array $data): void {
        try {
            $user = UserService::create($data);
            ApiResponse::send(201, "User registered successfully", $user); // 201 Created
            // Wait, Python APIResponse status code uses 201 standard status code. Let's make sure it's 201!
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }

    public function list(array $params): void {
        $skip = isset($params['skip']) ? intval($params['skip']) : 0;
        $limit = isset($params['limit']) ? intval($params['limit']) : 100;

        try {
            $users = UserService::getAll($skip, $limit);
            ApiResponse::send(200, "Users retrieved successfully", $users);
        } catch (Exception $e) {
            ApiResponse::send(500, $e->getMessage());
        }
    }

    public function retrieve(int $id): void {
        try {
            $user = UserService::getById($id);
            ApiResponse::send(200, "User retrieved successfully", $user);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 404, $e->getMessage());
        }
    }

    public function update(int $id, array $data): void {
        try {
            $user = UserService::update($id, $data);
            ApiResponse::send(200, "User updated successfully", $user);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }

    public function delete(int $id): void {
        try {
            $user = UserService::delete($id);
            ApiResponse::send(200, "User deleted successfully", $user);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 404, $e->getMessage());
        }
    }
}
