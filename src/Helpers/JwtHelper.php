<?php

namespace App\Helpers;

class JwtHelper {
    private static function base64UrlEncode(string $data): string {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private static function base64UrlDecode(string $data): string {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode(str_replace(['-', '_'], ['+', '/'], $data));
    }

    public static function encode(array $payload, string $secret, string $algo = 'HS256'): string {
        $header = ['alg' => $algo, 'typ' => 'JWT'];
        
        $headerBase64 = self::base64UrlEncode((string)json_encode($header));
        $payloadBase64 = self::base64UrlEncode((string)json_encode($payload));

        $signature = hash_hmac('sha256', "$headerBase64.$payloadBase64", $secret, true);
        $signatureBase64 = self::base64UrlEncode($signature);

        return "$headerBase64.$payloadBase64.$signatureBase64";
    }

    public static function decode(string $token, string $secret): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        list($headerBase64, $payloadBase64, $signatureBase64) = $parts;

        $signature = self::base64UrlDecode($signatureBase64);
        $expectedSignature = hash_hmac('sha256', "$headerBase64.$payloadBase64", $secret, true);

        if (!hash_equals($signature, $expectedSignature)) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($payloadBase64), true);
        if (!is_array($payload)) {
            return null;
        }
        
        // Validate expiry if present
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }
}
