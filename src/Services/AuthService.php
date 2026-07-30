<?php

namespace App\Services;

use App\Config\Database;
use App\Common\EmailService;
use App\Helpers\JwtHelper;
use App\Helpers\ValidationHelper;
use Exception;
use PDO;

class AuthService {
    private static function getDb(): PDO {
        return Database::getInstance()->getConnection();
    }

    public static function authenticateUser(string $usernameOrEmail, string $password): array {
        // Try loading by email
        $user = UserService::getByEmail($usernameOrEmail);
        if (!$user) {
            // Try loading by username
            $user = UserService::getByUsername($usernameOrEmail);
        }

        if (!$user) {
            throw new Exception("Invalid username or email", 401);
        }

        if (!password_verify($password, $user['password'])) {
            throw new Exception("Invalid password", 401);
        }

        return $user;
    }

    public static function initiateForgotPassword(string $email): string {
        $db = self::getDb();
        
        // 1. Verify user exists
        $user = UserService::getByEmail($email);
        if (!$user) {
            throw new Exception("No account associated with this email address", 404);
        }

        // 2. Generate a 6-digit code
        $resetCode = (string)rand(100000, 999999);

        // 3. Clean up existing codes
        $stmt = $db->prepare("DELETE FROM password_resets WHERE email = :email");
        $stmt->execute(['email' => $email]);

        // 4. Save new code (valid for 15 minutes)
        // Store in local system time to match SQL server calculations or UTC. Python used datetime.utcnow().
        // Let's use UTC standard for database dates
        $expiresAt = date('Y-m-d H:i:s', time() + (15 * 60)); // 15 mins from now
        
        $stmt = $db->prepare("INSERT INTO password_resets (email, code, expires_at) VALUES (:email, :code, :expires_at)");
        $stmt->execute([
            'email' => $email,
            'code' => $resetCode,
            'expires_at' => $expiresAt
        ]);

        // 5. Send recovery email
        $htmlBody = "
        <html>
            <body style=\"font-family: Arial, sans-serif; background-color: #f3f4f6; padding: 20px; color: #1f2937; margin: 0;\">
                <div style=\"max-width: 600px; margin: 0 auto; background-color: #ffffff; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); border: 1px solid #e5e7eb;\">
                    <div style=\"text-align: center; margin-bottom: 25px;\">
                        <h2 style=\"color: #2563eb; margin: 0; font-size: 24px; font-weight: bold;\">Delta Backtester</h2>
                        <p style=\"font-size: 13px; color: #6b7280; margin: 5px 0 0 0;\">Password Recovery Verification</p>
                    </div>
                    <hr style=\"border: 0; border-top: 1px solid #e5e7eb; margin-bottom: 20px;\" />
                    <p style=\"font-size: 15px; line-height: 1.5;\">Hello,</p>
                    <p style=\"font-size: 15px; line-height: 1.5;\">We received a request to reset your password. Use the following 6-digit verification code to complete process:</p>
                    <div style=\"text-align: center; margin: 30px 0;\">
                        <span style=\"font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #111827; background-color: #f3f4f6; padding: 12px 24px; border-radius: 8px; border: 1px solid #e5e7eb; display: inline-block;\">
                            {$resetCode}
                        </span>
                    </div>
                    <p style=\"font-size: 13px; color: #ef4444; font-weight: 500;\">This verification code will expire in 15 minutes.</p>
                    <p style=\"font-size: 13px; color: #6b7280; line-height: 1.4;\">If you did not make this request, you can safely ignore this email.</p>
                    <hr style=\"border: 0; border-top: 1px solid #e5e7eb; margin-top: 30px; margin-bottom: 15px;\" />
                    <p style=\"font-size: 11px; color: #9ca3af; text-align: center; margin: 0;\">&copy; Delta Backtester. Secure Session.</p>
                </div>
            </body>
        </html>
        ";

        try {
            EmailService::send(
                $email,
                "Delta Backtester - Password Reset Verification Code",
                $htmlBody
            );
        } catch (Exception $e) {
            throw new Exception("Failed to dispatch recovery email: " . $e->getMessage(), 500);
        }

        return $resetCode;
    }

    public static function verifyResetCode(string $email, string $code): bool {
        $db = self::getDb();

        $stmt = $db->prepare("SELECT id, expires_at FROM password_resets WHERE email = :email AND code = :code");
        $stmt->execute(['email' => $email, 'code' => $code]);
        $record = $stmt->fetch();

        if (!$record) {
            throw new Exception("Invalid verification code or email", 400);
        }

        // Check expiration
        $expiresAt = strtotime($record['expires_at']);
        if ($expiresAt < time()) {
            // Delete expired record
            $delStmt = $db->prepare("DELETE FROM password_resets WHERE id = :id");
            $delStmt->execute(['id' => $record['id']]);
            throw new Exception("Verification code has expired. Please request a new one.", 400);
        }

        return true;
    }

    public static function verifyAndResetPassword(string $email, string $code, string $newPassword): void {
        $db = self::getDb();

        // 1. Double check user exists
        $user = UserService::getByEmail($email);
        if (!$user) {
            throw new Exception("No account associated with this email address", 404);
        }

        // 2. Verify code exists and is not expired
        self::verifyResetCode($email, $code);

        // 3. Validate password complexity
        ValidationHelper::validatePasswordComplexity($newPassword);

        // 4. Hash and update
        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $db->prepare("UPDATE users SET password = :password WHERE email = :email");
        $stmt->execute(['password' => $hashedPassword, 'email' => $email]);

        // 5. Delete reset code (cannot be reused)
        $delStmt = $db->prepare("DELETE FROM password_resets WHERE email = :email");
        $delStmt->execute(['email' => $email]);
    }
}
