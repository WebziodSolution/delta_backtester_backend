<?php

namespace App\Middleware;

use App\Helpers\JwtHelper;
use App\Services\UserService;
use Exception;

class AuthMiddleware {
    public static function getCurrentUser(): array {
        $token = null;

        // 1. Check cookies
        if (isset($_COOKIE['tms_token'])) {
            $token = $_COOKIE['tms_token'];
        }

        // 2. Fallback: check Authorization header
        if (!$token) {
            $headers = self::getRequestHeaders();
            if (isset($headers['Authorization'])) {
                $authHeader = $headers['Authorization'];
                if (strpos($authHeader, 'Bearer ') === 0) {
                    $token = substr($authHeader, 7);
                }
            } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
                $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
                if (strpos($authHeader, 'Bearer ') === 0) {
                    $token = substr($authHeader, 7);
                }
            }
        }

        if (!$token) {
            throw new Exception("Not authenticated. Please log in.", 401);
        }

        $secret = $_ENV['JWT_SECRET'] ?? 'super_secret_delta_backtester_key_12345';
        $payload = JwtHelper::decode($token, $secret);

        if (!$payload) {
            throw new Exception("Session expired or invalid token. Please log in again.", 401);
        }

        $userId = $payload['sub'] ?? null;
        if (!$userId) {
            throw new Exception("Invalid token claims.", 401);
        }

        try {
            $user = UserService::getById((int)$userId);
            return $user;
        } catch (Exception $e) {
            throw new Exception("Authenticated user no longer exists.", 401);
        }
    }

    private static function getRequestHeaders(): array {
        if (function_exists('apache_request_headers')) {
            return apache_request_headers();
        }
        
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) <> 'HTTP_') {
                continue;
            }
            $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
            $headers[$header] = $value;
        }
        return $headers;
    }
}
