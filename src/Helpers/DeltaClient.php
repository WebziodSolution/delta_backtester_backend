<?php

namespace App\Helpers;

use Exception;

class DeltaClient {
    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl;
    private int $timeOffsetSec = 0;

    public function __construct(string $apiKey, string $apiSecret, string $baseUrl = "https://api.india.delta.exchange") {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->baseUrl = $baseUrl;
    }

    public function request(string $path, string $method = "GET", ?array $body = null, bool $retryOnDrift = true): array {
        $url = $this->baseUrl . $path;
        $timestamp = (string)(time() + $this->timeOffsetSec);
        
        $payload = "";
        if ($body !== null && in_array(strtoupper($method), ["POST", "PUT"])) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Signature: METHOD + TIMESTAMP + PATH + PAYLOAD
        $signaturePayload = strtoupper($method) . $timestamp . $path . $payload;
        $signature = hash_hmac('sha256', $signaturePayload, $this->apiSecret);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');

        $headers = [
            'api-key: ' . $this->apiKey,
            'timestamp: ' . $timestamp,
            'signature: ' . $signature,
            'Accept: application/json'
        ];

        if ($body !== null && in_array(strtoupper($method), ["POST", "PUT"])) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [500, ["success" => false, "message" => $curlError]];
        }

        $resData = json_decode($response, true);
        if ($resData === null) {
            return [$httpCode, ["success" => false, "message" => "Invalid JSON: " . $response]];
        }

        // Handle Clock Drift signature expiry
        if (is_array($resData) && isset($resData['error']['code']) && $resData['error']['code'] === 'expired_signature') {
            if ($retryOnDrift) {
                $serverTime = $resData['error']['context']['server_time'] ?? null;
                if ($serverTime) {
                    $this->timeOffsetSec = intval($serverTime) - time();
                    error_log("Adjusted clock drift offset by {$this->timeOffsetSec} seconds. Retrying request...");
                    return $this->request($path, $method, $body, false);
                }
            }
        }

        return [$httpCode, $resData];
    }

    public function getOrderById($orderId): array {
        return $this->request("/v2/orders/" . $orderId);
    }

    public function placeOrder($productId, int $size, string $side = "sell", string $orderType = "market_order", bool $reduceOnly = false): array {
        $path = "/v2/orders";
        $body = [
            "product_id" => intval($productId),
            "size" => $size,
            "side" => $side,
            "order_type" => $orderType
        ];
        if ($reduceOnly) {
            $body["reduce_only"] = true;
        }
        return $this->request($path, "POST", $body);
    }

    public function getFills(): array {
        return $this->request("/v2/fills");
    }

    public function getBalances(): array {
        return $this->request("/v2/wallet/balances");
    }

    public function setLeverage($productId, int $leverage): array {
        $path = "/v2/products/" . $productId . "/orders/leverage";
        return $this->request($path, "POST", ["leverage" => $leverage]);
    }
}
