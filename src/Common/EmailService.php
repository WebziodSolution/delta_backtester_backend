<?php

namespace App\Common;

use Exception;

class EmailService {
    public static function send(string $to, string $subject, string $htmlContent): bool {
        $host = $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com';
        $port = intval($_ENV['SMTP_PORT'] ?? 587);
        $username = $_ENV['SMTP_USERNAME'] ?? '';
        $password = $_ENV['SMTP_PASSWORD'] ?? '';

        if (empty($username) || empty($password)) {
            error_log("SMTP credentials not configured. Skipping email send.");
            return false;
        }

        $timeout = 15;
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if (!$socket) {
            error_log("Failed to connect to SMTP host: $errstr ($errno)");
            throw new Exception("Failed to connect to SMTP host: $errstr ($errno)");
        }

        // Helper closures to parse and assert SMTP response codes
        $readResponse = function($socket, int $expectedCode) use ($to) {
            $response = '';
            while ($line = fgets($socket, 515)) {
                $response .= $line;
                if (substr($line, 3, 1) == ' ') {
                    break;
                }
            }
            $code = intval(substr($response, 0, 3));
            if ($code !== $expectedCode) {
                throw new Exception("SMTP Error: Expected code $expectedCode, got $code. Response: $response");
            }
            return $response;
        };

        try {
            $readResponse($socket, 220);
            
            fwrite($socket, "EHLO localhost\r\n");
            $readResponse($socket, 250);

            if ($port === 587) {
                fwrite($socket, "STARTTLS\r\n");
                $readResponse($socket, 220);
                
                // Upgrade TCP connection to TLS client socket connection
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                // For modern PHP versions, TLS v1.2 / v1.3 method is preferred
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                
                if (!stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
                    throw new Exception("Failed to start TLS socket encryption");
                }
                
                fwrite($socket, "EHLO localhost\r\n");
                $readResponse($socket, 250);
            }

            fwrite($socket, "AUTH LOGIN\r\n");
            $readResponse($socket, 334);
            
            fwrite($socket, base64_encode($username) . "\r\n");
            $readResponse($socket, 334);
            
            fwrite($socket, base64_encode($password) . "\r\n");
            $readResponse($socket, 235);

            fwrite($socket, "MAIL FROM: <$username>\r\n");
            $readResponse($socket, 250);
            
            fwrite($socket, "RCPT TO: <$to>\r\n");
            $readResponse($socket, 250);

            fwrite($socket, "DATA\r\n");
            $readResponse($socket, 354);

            $boundary = md5(uniqid((string)time(), true));
            
            // Build raw mime headers and body
            $headers = [
                "MIME-Version: 1.0",
                "From: $username",
                "To: $to",
                "Subject: $subject",
                "Content-Type: multipart/alternative; boundary=\"$boundary\""
            ];

            $body = "--$boundary\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $body .= $htmlContent . "\r\n";
            $body .= "--$boundary--\r\n";

            $message = implode("\r\n", $headers) . "\r\n\r\n" . $body . "\r\n.\r\n";
            fwrite($socket, $message);
            $readResponse($socket, 250);

            fwrite($socket, "QUIT\r\n");
            fclose($socket);
            
            error_log("SMTP: Successfully sent email to $to");
            return true;
        } catch (Exception $e) {
            error_log("SMTP Error: Failed to send email to $to. Detail: " . $e->getMessage());
            if (is_resource($socket)) {
                fclose($socket);
            }
            throw $e;
        }
    }
}
