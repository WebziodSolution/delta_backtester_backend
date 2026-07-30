<?php

namespace App\Common;

class ApiResponse {
    public int $status;
    public string $message;
    public mixed $result;

    public function __construct(int $status, string $message, mixed $result = null) {
        $this->status = $status;
        $this->message = $message;
        $this->result = $result;
    }

    public static function send(int $status, string $message, mixed $result = null): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        
        $response = [
            'status' => $status,
            'message' => $message,
            'result' => $result
        ];

        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}
