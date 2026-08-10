<?php

// 1. Setup PSR-4 Autoloading
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

use App\Config\DotEnv;
use App\Config\Database;
use App\Common\EmailService;
use App\Helpers\DeltaClient;
use App\Services\UserService;

// Load environment configurations
DotEnv::load(__DIR__ . '/.env');

// Set default timezone to Indian Standard Time (Asia/Kolkata)
date_default_timezone_set('Asia/Kolkata');

define('BASE_URL', "https://api.india.delta.exchange");

function log_message(string $msg, string $level = "INFO"): void {
    $timestamp = date("Y-m-d H:i:s");
    $formatted = "{$timestamp} [{$level}] {$msg}\n";
    echo $formatted;
    error_log(trim($formatted));
}

function make_public_request_with_retry(string $url, int $timeout = 15, int $retries = 3): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);

    for ($attempt = 1; $attempt <= $retries; $attempt++) {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response !== false && ($httpCode === 200 || $httpCode === 201)) {
            $data = json_decode($response, true);
            curl_close($ch);
            if ($data !== null) {
                return $data;
            }
        }

        $err = curl_error($ch);
        log_message("Public request to {$url} failed (attempt {$attempt}/{$retries}): {$err}", "WARNING");
        if ($attempt < $retries) {
            sleep(2);
        }
    }
    curl_close($ch);
    throw new Exception("Public request failed to retrieve valid data from {$url}");
}

function fetch_btc_price(): ?float {
    $url = BASE_URL . "/v2/tickers/BTCUSD";
    try {
        $res = make_public_request_with_retry($url);
        if (isset($res['success']) && $res['success']) {
            $ticker = $res['result'] ?? [];
            return floatval($ticker['spot_price'] ?? $ticker['mark_price'] ?? $ticker['close'] ?? 0.0);
        }
    } catch (Exception $e) {
        log_message("Failed to fetch BTCUSD ticker spot price: " . $e->getMessage(), "ERROR");
    }
    return null;
}

function fetch_active_products(): array {
    $url = BASE_URL . "/v2/products?contract_types=call_options,put_options&states=live&page_size=1000";
    try {
        $res = make_public_request_with_retry($url);
        if (isset($res['success']) && $res['success']) {
            return $res['result'] ?? [];
        }
    } catch (Exception $e) {
        log_message("Failed to fetch active products from Delta Exchange: " . $e->getMessage(), "ERROR");
    }
    return [];
}

function close_trade(PDO $db, array $account, array $order, bool $isProfit): void {
    $orderName = $order['order_name'];
    $orderId = $order['order_id'];
    $userId = intval($order['user_id']);

    log_message("Closing order {$orderName} (ID: {$orderId}) for user {$userId} (Reason: Book " . ($isProfit ? 'Profit' : 'Loss') . ").");

    // Load User Email
    $userEmail = null;
    $username = 'Trader';
    try {
        $user = UserService::getById($userId);
        $userEmail = $user['email'];
        $username = $user['username'];
    } catch (Exception $e) {}

    $deltaClient = new DeltaClient($account['api_key'], $account['api_secret']);

    try {
        // 1. Fetch order details from Delta Exchange to get product_id and size
        list($status, $orderDetail) = $deltaClient->getOrderById($orderId);
        
        $productId = null;
        $size = null;

        if ($status !== 200 || !isset($orderDetail['success']) || !$orderDetail['success']) {
            log_message("Failed to fetch order details from exchange for order_id {$orderId}. Response: " . json_encode($orderDetail) . ". Attempting fallback product lookup...", "WARNING");
            
            // Fallback product lookup
            $activeProducts = fetch_active_products();
            $matchingProd = null;
            foreach ($activeProducts as $p) {
                if (($p['symbol'] ?? '') === $orderName) {
                    $matchingProd = $p;
                    break;
                }
            }

            if (!$matchingProd) {
                throw new Exception("Failed to locate product ID for symbol {$orderName} on Delta Exchange.");
            }

            $productId = $matchingProd['id'];
            log_message("Found fallback product ID for symbol {$orderName}: {$productId}");

            // Fallback size from trade config
            $configStmt = $db->prepare("SELECT lot FROM trade_config WHERE user_id = :user_id");
            $configStmt->execute(['user_id' => $userId]);
            $tradeConfig = $configStmt->fetch();

            if (!$tradeConfig || empty($tradeConfig['lot'])) {
                throw new Exception("Failed to get original size for order {$orderId} (no trade config found).");
            }
            
            $size = intval($order['qty'] ?: $tradeConfig['lot']);
        } else {
            $orderInfo = $orderDetail['result'] ?? [];
            $productId = $orderInfo['product_id'] ?? null;
            $size = isset($orderInfo['size']) ? intval($orderInfo['size']) : null;
        }

        if (!$productId || !$size) {
            throw new Exception("Could not retrieve valid product_id or size for order {$orderId}.");
        }

        // 2. Place opposite order (BUY) to close position
        log_message("Placing buy close order for product_id {$productId} and size {$size}");
        list($closeStatus, $closeResp) = $deltaClient->placeOrder($productId, $size, "buy", "market_order", true);

        if (($closeStatus === 200 || $closeStatus === 201) && isset($closeResp['success']) && $closeResp['success']) {
            $placedCloseOrder = $closeResp['result'] ?? [];
            $closeOrderId = $placedCloseOrder['id'] ?? '';
            log_message("Placed buy close order. Close Order ID: {$closeOrderId} on Delta Exchange.");

            // Wait 1.5 seconds to query fills and fee details
            usleep(1500000);

            list($fillStatus, $fillResp) = $deltaClient->getFills();
            $fills = ($fillStatus === 200 && isset($fillResp['success']) && $fillResp['success']) ? ($fillResp['result'] ?? []) : [];
            
            $totalSize = 0.0;
            $totalVal = 0.0;
            $totalFee = 0.0;

            foreach ($fills as $f) {
                if (strval($f['order_id'] ?? '') === strval($closeOrderId)) {
                    $sz = floatval($f['size'] ?? 0.0);
                    $pr = floatval($f['price'] ?? 0.0);
                    $fe = floatval($f['commission'] ?? $f['fee'] ?? 0.0);
                    
                    $totalSize += $sz;
                    $totalVal += ($pr * $sz);
                    $totalFee += $fe;
                }
            }

            $avgExitPrice = $totalSize > 0 ? ($totalVal / $totalSize) : 0.0;

            // Sum up original entry fees + exit fees
            $originalFees = floatval($order['broker_fees'] ?? 0.0);
            $brokerFeesVal = $originalFees + $totalFee;

            // Calculate PnL (Short strangle position: entry - exit) in USDT
            // PnL = (Entry price - Exit price) * size * 0.001
            $entryAmountVal = floatval($order['entry_amount'] ?? 0.0);
            $pnlVal = ($entryAmountVal - $avgExitPrice) * ($size / 1000.0);

            // 3. Update database row
            $updateStmt = $db->prepare("UPDATE orders_info SET 
                                            status = 'closed', 
                                            exit_amount = :exit_amount, 
                                            broker_fees = :broker_fees, 
                                            pnl = :pnl, 
                                            updated_at = CURRENT_TIMESTAMP 
                                        WHERE id = :id");
            $updateStmt->execute([
                'exit_amount' => round($avgExitPrice, 4),
                'broker_fees' => round($brokerFeesVal, 4),
                'pnl' => round($pnlVal, 4),
                'id' => $order['id']
            ]);

            log_message("Database successfully updated. Order ID {$orderId} is marked CLOSED.");

            // 4. Send success email
            if ($userEmail) {
                $subject = "Options Trade Closed Successfully - " . ($isProfit ? 'Profit Booked' : 'Loss Booked');
                $reasonStr = $isProfit ? "target holding period (12h 00m) was reached while remaining in range" : "BTC spot price breached the safe range bounds";
                
                $html = "<p>Dear {$username},</p>"
                      . "<p>Your option selling contract has been closed successfully to book <strong>" . ($isProfit ? 'profit' : 'loss') . "</strong>.</p>"
                      . "<p><strong>Reason for closure:</strong> {$reasonStr}</p>"
                      . "<h3>Trade Details:</h3>"
                      . "<ul>"
                      . "<li><strong>Option Contract:</strong> {$orderName}</li>"
                      . "<li><strong>Original Order ID:</strong> {$orderId}</li>"
                      . "<li><strong>Closing Order ID:</strong> {$closeOrderId}</li>"
                      . "<li><strong>Quantity (Lots):</strong> {$size}</li>"
                      . "<li><strong>Entry Price:</strong> \$" . number_format($entryAmountVal, 4) . " USD</li>"
                      . "<li><strong>Exit Price:</strong> \$" . number_format($avgExitPrice, 4) . " USD</li>"
                      . "<li><strong>Total Fees (Entry + Exit):</strong> \$" . number_format($brokerFeesVal, 4) . " USD</li>"
                      . "<li><strong>Net Trade PnL:</strong> \$" . number_format($pnlVal, 4) . " USD</li>"
                      . "</ul>"
                      . "<p>Best regards,<br/>Delta Backtester Automation Service</p>";
                try {
                    EmailService::send($userEmail, $subject, $html);
                } catch (Exception $mailEx) {
                    log_message("Failed to send success email: " . $mailEx->getMessage(), "ERROR");
                }
            }
        } else {
            $errorDetail = $closeResp['message'] ?? $closeResp['error']['message'] ?? "Unknown API response error";
            throw new Exception($errorDetail);
        }
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        log_message("Failed to close order {$orderId} for user {$userId}: {$errorMsg}", "ERROR");

        // Send failure notification email
        if ($userEmail) {
            $subject = "Failed to Close Option Position - {$orderName}";
            $html = "<p>Dear {$username},</p>"
                  . "<p>An error occurred while attempting to close your option position for contract <strong>{$orderName}</strong> (Order ID: {$orderId}).</p>"
                  . "<p><strong>Error Details:</strong> {$errorMsg}</p>"
                  . "<p>Please review your open positions on Delta Exchange directly to prevent unintended loss.</p>"
                  . "<p>Best regards,<br/>Delta Backtester Automation Service</p>";
            try {
                EmailService::send($userEmail, $subject, $html);
            } catch (Exception $mailEx) {}
        }
    }
}

function check_and_monitor_trades(): void {
    $db = Database::getInstance()->getConnection();
    
    // Fetch active accounts
    $stmt = $db->query("SELECT id, user_id, api_key, api_secret, active FROM account_info WHERE active = 1");
    $activeAccounts = $stmt->fetchAll();

    if (empty($activeAccounts)) {
        log_message("No active accounts found in database.");
        return;
    }

    // Fetch current BTC price
    $currentBtcPrice = fetch_btc_price();
    log_message("Current BTC Price: {$currentBtcPrice}");

    if (!$currentBtcPrice) {
        log_message("Failed to retrieve current BTC spot price. Aborting trade monitor sweep.", "ERROR");
        return;
    }

    log_message("Checking " . count($activeAccounts) . " active accounts. Current BTC Price: {$currentBtcPrice}");

    foreach ($activeAccounts as $account) {
        // Query open orders for this account
        $orderStmt = $db->prepare("SELECT id, order_id, order_name, order_type, entry_amount, exit_amount, pnl, broker_fees, qty, status, account_info_id, user_id, strategy_id , created_at, updated_at FROM orders_info WHERE account_info_id = :account_info_id AND status = 'open' AND strategy_id = 2");
        $orderStmt->execute(['account_info_id' => $account['id']]);
        $openOrders = $orderStmt->fetchAll();

        if (empty($openOrders)) {
            continue;
        }

        log_message("Found " . count($openOrders) . " open orders for account {$account['id']}");

        // Group open orders by expiry date suffix (e.g. C-BTC-61800-290726 -> suffix is 290726)
        $ordersByExpiry = [];
        foreach ($openOrders as $order) {
            $parts = explode('-', $order['order_name']);
            $expiry = isset($parts[3]) ? $parts[3] : 'unknown';
            $ordersByExpiry[$expiry][] = $order;
        }

        // Process each expiry group
        foreach ($ordersByExpiry as $expiry => $groupOrders) {
            $callStrike = null;
            $putStrike = null;

            foreach ($groupOrders as $order) {
                $parts = explode('-', $order['order_name']);
                if (count($parts) >= 3) {
                    $strike = floatval($parts[2]);
                    if (strpos($order['order_name'], 'C-') === 0) {
                        $callStrike = $strike;
                    } elseif (strpos($order['order_name'], 'P-') === 0) {
                        $putStrike = $strike;
                    }
                }
            }

            $lowerBound = $putStrike !== null ? $putStrike : 0.0;
            $upperBound = $callStrike !== null ? $callStrike : INF;

            // Check if current price is within safe strangle bounds
            if ($currentBtcPrice >= $lowerBound && $currentBtcPrice <= $upperBound) {
                log_message("Account ID {$account['id']}: BTC price {$currentBtcPrice} is IN RANGE ({$lowerBound} - {$upperBound}) for expiry suffix {$expiry}.");

                // Check gap >= 12h 00m
                foreach ($groupOrders as $order) {
                    $createdAtUnix = (new DateTime($order['created_at'], new DateTimeZone('UTC')))->getTimestamp();
                    $gapSeconds = time() - $createdAtUnix;

                    // 12 hours = 43200 seconds
                    if ($gapSeconds >= 12 * 3600) {
                        log_message("Target duration met (gap seconds: {$gapSeconds}). Booking profit for {$order['order_name']}...");
                        close_trade($db, $account, $order, true);
                    } else {
                        log_message("Order {$order['order_name']} is inside range. Gap seconds: {$gapSeconds} < 43200. Keeping trade open.");
                    }
                }
            } else {
                log_message("Account ID {$account['id']}: BTC price {$currentBtcPrice} is OUT OF RANGE ({$lowerBound} - {$upperBound}) for expiry suffix {$expiry}. Booking loss for all orders in group!", "WARNING");
                foreach ($groupOrders as $order) {
                    close_trade($db, $account, $order, false);
                }
            }
        }
    }
}

// Execution block
try {
    check_and_monitor_trades();
} catch (Exception $e) {
    log_message("Error during monitor strategy sweep: " . $e->getMessage(), "ERROR");
}
