<?php

namespace App\Helpers;

use Exception;

class ValidationHelper {
    public static function validatePasswordComplexity(string $v): string {
        if (strlen($v) < 8) {
            throw new Exception("Password must be at least 8 characters long");
        }
        if (!preg_match('/[A-Z]/', $v)) {
            throw new Exception("Password must contain at least one uppercase letter");
        }
        if (!preg_match('/[0-9]/', $v)) {
            throw new Exception("Password must contain at least one number");
        }
        
        // Find if there is at least one character that is not alphanumeric
        $hasSpecial = false;
        for ($i = 0; $i < strlen($v); $i++) {
            if (!ctype_alnum($v[$i])) {
                $hasSpecial = true;
                break;
            }
        }
        if (!$hasSpecial) {
            throw new Exception("Password must contain at least one special character");
        }
        return $v;
    }

    public static function validateEmail(string $email): string {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Invalid email format");
        }
        return $email;
    }

    public static function validatePositiveInt($v, string $fieldName): int {
        if ($v === null || $v === '') {
            throw new Exception("{$fieldName} cannot be empty");
        }
        $val = filter_var($v, FILTER_VALIDATE_INT);
        if ($val === false || $val <= 0) {
            throw new Exception("{$fieldName} must be a positive integer greater than 0");
        }
        return $val;
    }

    public static function validateNonEmptyStrip($v, string $fieldName): string {
        if ($v === null || $v === '') {
            throw new Exception("{$fieldName} cannot be empty or only whitespace");
        }
        $stripped = trim((string)$v);
        if ($stripped === '') {
            throw new Exception("{$fieldName} cannot be empty or only whitespace");
        }
        if (strlen($stripped) > 255) {
            throw new Exception("{$fieldName} must be 255 characters or fewer");
        }
        return $stripped;
    }
}
