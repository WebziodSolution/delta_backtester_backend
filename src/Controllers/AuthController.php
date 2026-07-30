<?php

namespace App\Controllers;

use App\Common\ApiResponse;
use App\Services\AuthService;
use App\Middleware\AuthMiddleware;
use App\Helpers\JwtHelper;
use Exception;

class AuthController {
    public function login(array $data): void {
        $usernameOrEmail = $data['username_or_email'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($usernameOrEmail) || empty($password)) {
            ApiResponse::send(400, "Username/Email and Password are required");
        }

        try {
            $user = AuthService::authenticateUser($usernameOrEmail, $password);

            // Generate JWT Token (12 Hours)
            $expiresIn = 12 * 60 * 60; // 43200 seconds
            $payload = [
                'sub' => (string)$user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'exp' => time() + $expiresIn
            ];

            $secret = $_ENV['JWT_SECRET'] ?? 'super_secret_delta_backtester_key_12345';
            $accessToken = JwtHelper::encode($payload, $secret);

            // Set cookies (matches FastAPI samesite=lax, httponly=False)
            // In PHP, setcookie options array is available in PHP 7.3+
            setcookie('tms_token', $accessToken, [
                'expires' => time() + $expiresIn,
                'path' => '/',
                'domain' => '',
                'secure' => false, // false because local development is typically http
                'httponly' => false,
                'samesite' => 'Lax'
            ]);

            setcookie('tms_user', $user['username'], [
                'expires' => time() + $expiresIn,
                'path' => '/',
                'domain' => '',
                'secure' => false,
                'httponly' => false,
                'samesite' => 'Lax'
            ]);

            $tokenData = [
                'access_token' => $accessToken,
                'token_type' => 'bearer',
                'username' => $user['username'],
                'email' => $user['email'],
                'id' => (int)$user['id']
            ];

            ApiResponse::send(200, "Login successful", $tokenData);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 401, $e->getMessage());
        }
    }

    public function forgotPassword(array $data): void {
        $email = $data['email'] ?? '';
        if (empty($email)) {
            ApiResponse::send(400, "Email address is required");
        }

        try {
            $resetCode = AuthService::initiateForgotPassword($email);
            ApiResponse::send(200, "Password reset code generated and stored. Please check server console logs.", [
                'email' => $email,
                'reset_code' => $resetCode
            ]);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }

    public function verifyResetCode(array $data): void {
        $email = $data['email'] ?? '';
        $code = $data['code'] ?? '';

        if (empty($email) || empty($code)) {
            ApiResponse::send(400, "Email and reset code are required");
        }

        try {
            AuthService::verifyResetCode($email, $code);
            ApiResponse::send(200, "Verification code is valid");
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 400, $e->getMessage());
        }
    }

    public function resetPassword(array $data): void {
        $email = $data['email'] ?? '';
        $code = $data['code'] ?? '';
        $newPassword = $data['new_password'] ?? '';

        if (empty($email) || empty($code) || empty($newPassword)) {
            ApiResponse::send(400, "Email, code, and new_password are required");
        }

        try {
            AuthService::verifyAndResetPassword($email, $code, $newPassword);
            ApiResponse::send(200, "Password has been reset successfully");
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 422, $e->getMessage());
        }
    }

    public function logout(): void {
        // Clear cookies by setting their expiration in the past
        setcookie('tms_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax'
        ]);

        setcookie('tms_user', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => false,
            'samesite' => 'Lax'
        ]);

        ApiResponse::send(200, "Logout successful");
    }

    public function me(): void {
        try {
            $currentUser = AuthMiddleware::getCurrentUser();
            // Remove sensitive fields
            unset($currentUser['password']);
            ApiResponse::send(200, "Current user retrieved successfully", $currentUser);
        } catch (Exception $e) {
            ApiResponse::send($e->getCode() ?: 401, $e->getMessage());
        }
    }
}
