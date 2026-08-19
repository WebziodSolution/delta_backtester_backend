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
define('STRATEGY_ID', 1); // Dedicated Strategy ID for Intraday Option Buying

function log_message(string $msg, string $level = "INFO"): void {
    $timestamp = date("Y-m-d H:i:s");
    $formatted = "{$timestamp} [{$level}] {$msg}\n";
    echo $formatted;
    error_log(trim($formatted));
}

function email_error(string $errorMessage, ?string $userEmail = null): void {
    $adminEmail = $_ENV['SMTP_USERNAME'] ?? 'webzoidsolution@gmail.com';
    $subject = "Intraday Option Buying Strategy Error Alert - Monitor Service";
    $html = "<h3>An error occurred in Intraday Option Buying Strategy Monitor Service</h3>"
          . "<p><strong>Timestamp:</strong> " . date("Y-m-d H:i:s") . " (Asia/Kolkata)</p>"
          . "<p><strong>Error Details:</strong></p>"
          . "<pre style='background: #f8f9fa; padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($errorMessage) . "</pre>";

    // Send to admin
    try {
        EmailService::send($adminEmail, $subject, $html);
    } catch (Exception $e) {
        log_message("Failed to send error email to admin: " . $e->getMessage(), "ERROR");
    }

    // Send to user if available and different from admin
    if ($userEmail && $userEmail !== $adminEmail) {
        try {
            EmailService::send($userEmail, $subject, $html);
        } catch (Exception $e) {
            log_message("Failed to send error email to user {$userEmail}: " . $e->getMessage(), "ERROR");
        }
    }
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

function fetch_ticker_for_symbol(string $symbol): ?array {
    try {
        $url = BASE_URL . "/v2/tickers/" . $symbol;
        $res = make_public_request_with_retry($url);
        if (isset($res['success']) && $res['success']) {
            return $res['result'] ?? null;
        }
        log_message("Ticker request for {$symbol} returned success=false: " . json_encode($res), "ERROR");
    } catch (Exception $e) {
        log_message("Failed to fetch ticker for symbol {$symbol}: " . $e->getMessage(), "ERROR");
    }
    return null;
}

function close_option_buying_trade(PDO $db, array $account, array $order, string $exitReason): void {
    $orderName = $order['order_name'];
    $orderId = $order['order_id'];
    $userId = intval($order['user_id']);
    $qty = intval($order['qty']);
    $tradeAction = $order['trade_action'];
    $closeSide = 'sell'; // Closing a BUY option is always a SELL

    log_message("Closing Intraday Option Buying trade {$orderName} (ID: {$orderId}) for user {$userId} (Reason: {$exitReason}).");

    $userEmail = null;
    $username = 'Trader';
    try {
        $user = UserService::getById($userId);
        $userEmail = $user['email'];
        $username = $user['username'];
    } catch (Exception $e) {}

    $deltaClient = new DeltaClient($account['api_key'], $account['api_secret']);

    // Cancel TP and SL orders on the exchange first to avoid double fill
    if (!empty($order['tp_order_id'])) {
        try {
            log_message("Canceling Take Profit order {$order['tp_order_id']} on Exchange...");
            // Get product ID for cancelation
            $activeProducts = fetch_active_products();
            $productId = null;
            foreach ($activeProducts as $p) {
                if (($p['symbol'] ?? '') === $orderName) {
                    $productId = $p['id'];
                    break;
                }
            }
            if ($productId) {
                $deltaClient->cancelOrder($productId, $order['tp_order_id']);
            }
        } catch (Exception $e) {
            log_message("Note: Failed to cancel Take Profit limit order on exchange: " . $e->getMessage());
        }
    }
    if (!empty($order['sl_order_id'])) {
        try {
            log_message("Canceling Stop Loss order {$order['sl_order_id']} on Exchange...");
            // Get product ID for cancelation
            if (!isset($productId)) {
                $activeProducts = fetch_active_products();
                $productId = null;
                foreach ($activeProducts as $p) {
                    if (($p['symbol'] ?? '') === $orderName) {
                        $productId = $p['id'];
                        break;
                    }
                }
            }
            if ($productId) {
                $deltaClient->cancelOrder($productId, $order['sl_order_id']);
            }
        } catch (Exception $e) {
            log_message("Note: Failed to cancel Stop Loss stop-limit order on exchange: " . $e->getMessage());
        }
    }

    try {
        // Find product ID if not fetched already
        if (!isset($productId) || !$productId) {
            $activeProducts = fetch_active_products();
            $productId = null;
            foreach ($activeProducts as $p) {
                if (($p['symbol'] ?? '') === $orderName) {
                    $productId = $p['id'];
                    break;
                }
            }
        }

        if (!$productId) {
            throw new Exception("Failed to locate product ID for symbol {$orderName} on Delta Exchange.");
        }

        // Place opposite market order (SELL) to close the position
        log_message("Placing live market SELL close order for product_id {$productId} and size {$qty}");
        list($closeStatus, $closeResp) = $deltaClient->placeOrder($productId, $qty, $closeSide, "market_order", true);

        if (($closeStatus === 200 || $closeStatus === 201) && isset($closeResp['success']) && $closeResp['success']) {
            $placedCloseOrder = $closeResp['result'] ?? [];
            $closeOrderId = $placedCloseOrder['id'] ?? '';
            log_message("Close market order successfully placed. Close Order ID: {$closeOrderId} on Delta Exchange.");

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

            // Sum original entry fees + exit fees
            $originalFees = floatval($order['broker_fees'] ?? 0.0);
            $brokerFeesVal = $originalFees + $totalFee;

            // Calculate PnL (Exit price - Entry price) * qtyLots / lotMult
            $entryAmountVal = floatval($order['entry_amount'] ?? 0.0);
            $lotMult = (strpos($orderName, 'BTC') !== false) ? 1000 : 100;
            $sizeInAsset = $qty / $lotMult;

            $pnlVal = ($avgExitPrice - $entryAmountVal) * $sizeInAsset;
            $netTradePnl = $pnlVal - $brokerFeesVal;

            // Update database row
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

            // Update virtual running strategy balance
            $balanceUpdateStmt = $db->prepare("
                UPDATE subscribe_strategys 
                SET 
                    current_balance = COALESCE(current_balance, allocated_balance) + :net_pnl,
                    peak_balance = GREATEST(COALESCE(peak_balance, allocated_balance), COALESCE(current_balance, allocated_balance) + :net_pnl)
                WHERE user_id = :uid AND strategy_id = :strat_id AND allocated_balance IS NOT NULL
            ");
            $balanceUpdateStmt->execute([
                'net_pnl' => $netTradePnl,
                'uid' => $userId,
                'strat_id' => STRATEGY_ID
            ]);

            log_message("Database updated. Intraday Option Buying Order ID {$orderId} marked CLOSED. PnL: \${$pnlVal} USDT, Net PnL: \${$netTradePnl} USDT");

            // Send close email notification
            if ($userEmail) {
                $subject = "Intraday Option Buying Trade Closed - {$exitReason}";
                
                $html = "<p>Dear {$username},</p>"
                      . "<p>Your Intraday Option Buying position has been closed successfully.</p>"
                      . "<p><strong>Reason for closure:</strong> {$exitReason}</p>"
                      . "<h3>Trade Details:</h3>"
                      . "<ul>"
                      . "<li><strong>Option Contract:</strong> {$orderName}</li>"
                      . "<li><strong>Trade Action:</strong> {$tradeAction}</li>"
                      . "<li><strong>Original Order ID:</strong> {$orderId}</li>"
                      . "<li><strong>Closing Order ID:</strong> {$closeOrderId}</li>"
                      . "<li><strong>Quantity (Lots):</strong> {$qty}</li>"
                      . "<li><strong>Entry Price:</strong> \$" . number_format($entryAmountVal, 4) . " USD</li>"
                      . "<li><strong>Exit Price:</strong> \$" . number_format($avgExitPrice, 4) . " USD</li>"
                      . "<li><strong>Total Fees (Entry + Exit):</strong> \$" . number_format($brokerFeesVal, 4) . " USD</li>"
                      . "<li><strong>Net Trade PnL (excluding fees):</strong> \$" . number_format($pnlVal, 4) . " USDT</li>"
                      . "<li><strong>Net Trade PnL (including fees):</strong> \$" . number_format($netTradePnl, 4) . " USDT</li>"
                      . "</ul>"
                      . "<p>Best regards,<br/>Delta Backtester Automation Service</p>";
                try {
                    EmailService::send($userEmail, $subject, $html);
                    log_message("Close notification email sent to {$userEmail}");
                } catch (Exception $mailEx) {
                    log_message("Failed to send close email: " . $mailEx->getMessage(), "ERROR");
                }
            }
        } else {
            $errorDetail = $closeResp['message'] ?? $closeResp['error']['message'] ?? "Unknown API response error";
            throw new Exception($errorDetail);
        }
    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
        log_message("Failed to close Intraday Option Buying order {$orderId} for user {$userId}: {$errorMsg}", "ERROR");

        // Send failure notification email
        if ($userEmail) {
            $subject = "URGENT: Failed to Close Intraday Option Buying Position - {$orderName}";
            $html = "<p>Dear {$username},</p>"
                  . "<p>An error occurred while attempting to close your Intraday Option Buying position for contract <strong>{$orderName}</strong> (Order ID: {$orderId}).</p>"
                  . "<p><strong>Error Details:</strong> {$errorMsg}</p>"
                  . "<p>Please review your open positions on Delta Exchange directly to prevent unintended loss.</p>"
                  . "<p>Best regards,<br/>Delta Backtester Automation Service</p>";
            try {
                EmailService::send($userEmail, $subject, $html);
            } catch (Exception $mailEx) {}
        }
    }
}

function check_and_monitor_option_buying_trades(): void {
    $db = Database::getInstance()->getConnection();
    
    // Fetch all open Intraday Option Buying orders for active accounts
    $stmt = $db->prepare("
        SELECT 
            o.id, 
            o.order_id, 
            o.order_name, 
            o.order_type, 
            o.entry_amount, 
            o.exit_amount, 
            o.pnl, 
            o.broker_fees, 
            o.qty, 
            o.status, 
            o.account_info_id, 
            o.user_id, 
            o.strategy_id, 
            o.created_at, 
            o.updated_at, 
            o.tp_price, 
            o.sl_price, 
            o.trade_action,
            o.tp_order_id,
            o.sl_order_id,
            a.api_key,
            a.api_secret,
            a.active AS account_active
        FROM orders_info o
        JOIN account_info a ON o.account_info_id = a.id
        WHERE o.status = 'open' 
          AND o.strategy_id = :strat_id 
          AND a.active = 1
    ");
    $stmt->execute(['strat_id' => STRATEGY_ID]);
    $openOrders = $stmt->fetchAll();

    if (empty($openOrders)) {
        log_message("No open Intraday Option Buying orders found for active accounts.");
        return;
    }

    log_message("Starting Intraday Option Buying trade monitoring sweep for " . count($openOrders) . " open orders...");

    foreach ($openOrders as $order) {
        $userId = intval($order['user_id']);
        $userEmail = null;
        $username = 'Trader';
        try {
            $user = UserService::getById($userId);
            $userEmail = $user['email'];
            $username = $user['username'];
        } catch (Exception $e) {
            log_message("Order ID {$order['id']} (User ID {$userId}) has no valid user. Skipping.", "WARNING");
            continue;
        }

        $account = [
            'id' => $order['account_info_id'],
            'user_id' => $userId,
            'api_key' => $order['api_key'],
            'api_secret' => $order['api_secret'],
            'active' => intval($order['account_active'])
        ];

        try {
            $orderName = $order['order_name'];
            $orderId = $order['order_id'];
            $tpPrice = floatval($order['tp_price']);
            $slPrice = floatval($order['sl_price']);
            $tpOrderId = $order['tp_order_id'];
            $slOrderId = $order['sl_order_id'];
            $qty = intval($order['qty']);
            $tradeAction = $order['trade_action'];
            $createdAtUnix = strtotime($order['created_at']);
            $ageSeconds = time() - $createdAtUnix;

            log_message("Monitoring {$orderName} (ID: {$orderId}, Action: {$tradeAction}, Age: {$ageSeconds}s, TP: \${$tpPrice}, SL: \${$slPrice})");

            $deltaClient = new DeltaClient($account['api_key'], $account['api_secret']);

            // Step 1: Check Native Exchange Limit Order Exits (OCO checks)
            if (!empty($tpOrderId)) {
                list($status, $tpDetails) = $deltaClient->getOrderById($tpOrderId);
                if ($status === 200 && isset($tpDetails['success']) && $tpDetails['success']) {
                    $tpState = $tpDetails['result']['state'] ?? $tpDetails['result']['status'] ?? '';
                    if ($tpState === 'filled') {
                        log_message("Take Profit order {$tpOrderId} hit and FILLED natively on exchange. Closing trade.");
                        
                        // Close order details
                        $avgExitPrice = floatval($tpDetails['result']['avg_fill_price'] ?? $tpDetails['result']['price'] ?? $tpPrice);
                        $exitFees = floatval($tpDetails['result']['fee'] ?? 0.0);
                        $originalFees = floatval($order['broker_fees'] ?? 0.0);
                        $brokerFeesVal = $originalFees + $exitFees;

                        // Calculate PnL
                        $entryAmountVal = floatval($order['entry_amount'] ?? 0.0);
                        $lotMult = (strpos($orderName, 'BTC') !== false) ? 1000 : 100;
                        $sizeInAsset = $qty / $lotMult;
                        $pnlVal = ($avgExitPrice - $entryAmountVal) * $sizeInAsset;
                        $netTradePnl = $pnlVal - $brokerFeesVal;

                        // Cancel SL order on exchange
                        if (!empty($slOrderId)) {
                            try {
                                $activeProducts = fetch_active_products();
                                $productId = null;
                                foreach ($activeProducts as $p) {
                                    if (($p['symbol'] ?? '') === $orderName) {
                                        $productId = $p['id'];
                                        break;
                                    }
                                }
                                if ($productId) {
                                    $deltaClient->cancelOrder($productId, $slOrderId);
                                }
                            } catch (Exception $mailEx) {}
                        }

                        // Update database status to closed
                        $db->prepare("UPDATE orders_info SET status = 'closed', exit_amount = :exit_amount, broker_fees = :broker_fees, pnl = :pnl, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                           ->execute(['exit_amount' => round($avgExitPrice, 4), 'broker_fees' => round($brokerFeesVal, 4), 'pnl' => round($pnlVal, 4), 'id' => $order['id']]);

                        // Update virtual balance
                        $db->prepare("UPDATE subscribe_strategys SET current_balance = COALESCE(current_balance, allocated_balance) + :net_pnl, peak_balance = GREATEST(COALESCE(peak_balance, allocated_balance), COALESCE(current_balance, allocated_balance) + :net_pnl) WHERE user_id = :uid AND strategy_id = :strat_id AND allocated_balance IS NOT NULL")
                           ->execute(['net_pnl' => $netTradePnl, 'uid' => $userId, 'strat_id' => STRATEGY_ID]);

                        // Send success email
                        if ($userEmail) {
                            $subject = "Intraday Option Buying Closed - Take Profit Hit";
                            $html = "<p>Dear {$username},</p>"
                                  . "<p>Your Intraday Option Buying position has been closed successfully via a filled Take Profit limit order on Delta Exchange.</p>"
                                  . "<h3>Trade Details:</h3>"
                                  . "<ul>"
                                  . "<li><strong>Option Contract:</strong> {$orderName}</li>"
                                  . "<li><strong>Trade Action:</strong> {$tradeAction}</li>"
                                  . "<li><strong>Take Profit Order ID:</strong> {$tpOrderId}</li>"
                                  . "<li><strong>Exit Price:</strong> \$" . number_format($avgExitPrice, 4) . " USD</li>"
                                  . "<li><strong>Net Trade PnL (excluding fees):</strong> \$" . number_format($pnlVal, 4) . " USDT</li>"
                                  . "<li><strong>Net PnL (including fees):</strong> \$" . number_format($netTradePnl, 4) . " USDT</li>"
                                  . "</ul>";
                            try { EmailService::send($userEmail, $subject, $html); } catch (Exception $e) {}
                        }
                        continue;
                    }
                }
            }

            if (!empty($slOrderId)) {
                list($status, $slDetails) = $deltaClient->getOrderById($slOrderId);
                if ($status === 200 && isset($slDetails['success']) && $slDetails['success']) {
                    $slState = $slDetails['result']['state'] ?? $slDetails['result']['status'] ?? '';
                    if ($slState === 'filled') {
                        log_message("Stop Loss order {$slOrderId} hit and FILLED natively on exchange. Closing trade.");

                        // Close order details
                        $avgExitPrice = floatval($slDetails['result']['avg_fill_price'] ?? $slDetails['result']['price'] ?? $slPrice);
                        $exitFees = floatval($slDetails['result']['fee'] ?? 0.0);
                        $originalFees = floatval($order['broker_fees'] ?? 0.0);
                        $brokerFeesVal = $originalFees + $exitFees;

                        // Calculate PnL
                        $entryAmountVal = floatval($order['entry_amount'] ?? 0.0);
                        $lotMult = (strpos($orderName, 'BTC') !== false) ? 1000 : 100;
                        $sizeInAsset = $qty / $lotMult;
                        $pnlVal = ($avgExitPrice - $entryAmountVal) * $sizeInAsset;
                        $netTradePnl = $pnlVal - $brokerFeesVal;

                        // Cancel TP order on exchange
                        if (!empty($tpOrderId)) {
                            try {
                                $activeProducts = fetch_active_products();
                                $productId = null;
                                foreach ($activeProducts as $p) {
                                    if (($p['symbol'] ?? '') === $orderName) {
                                        $productId = $p['id'];
                                        break;
                                    }
                                }
                                if ($productId) {
                                    $deltaClient->cancelOrder($productId, $tpOrderId);
                                }
                            } catch (Exception $mailEx) {}
                        }

                        // Update database status to closed
                        $db->prepare("UPDATE orders_info SET status = 'closed', exit_amount = :exit_amount, broker_fees = :broker_fees, pnl = :pnl, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                           ->execute(['exit_amount' => round($avgExitPrice, 4), 'broker_fees' => round($brokerFeesVal, 4), 'pnl' => round($pnlVal, 4), 'id' => $order['id']]);

                        // Update virtual balance
                        $db->prepare("UPDATE subscribe_strategys SET current_balance = COALESCE(current_balance, allocated_balance) + :net_pnl, peak_balance = GREATEST(COALESCE(peak_balance, allocated_balance), COALESCE(current_balance, allocated_balance) + :net_pnl) WHERE user_id = :uid AND strategy_id = :strat_id AND allocated_balance IS NOT NULL")
                           ->execute(['net_pnl' => $netTradePnl, 'uid' => $userId, 'strat_id' => STRATEGY_ID]);

                        // Send email
                        if ($userEmail) {
                            $subject = "Intraday Option Buying Closed - Stop Loss Hit";
                            $html = "<p>Dear {$username},</p>"
                                  . "<p>Your Intraday Option Buying position has been closed successfully via a filled Stop Loss stop-limit order on Delta Exchange.</p>"
                                  . "<h3>Trade Details:</h3>"
                                  . "<ul>"
                                  . "<li><strong>Option Contract:</strong> {$orderName}</li>"
                                  . "<li><strong>Trade Action:</strong> {$tradeAction}</li>"
                                  . "<li><strong>Stop Loss Order ID:</strong> {$slOrderId}</li>"
                                  . "<li><strong>Exit Price:</strong> \$" . number_format($avgExitPrice, 4) . " USD</li>"
                                  . "<li><strong>Net Trade PnL (excluding fees):</strong> \$" . number_format($pnlVal, 4) . " USDT</li>"
                                  . "<li><strong>Net PnL (including fees):</strong> \$" . number_format($netTradePnl, 4) . " USDT</li>"
                                  . "</ul>";
                            try { EmailService::send($userEmail, $subject, $html); } catch (Exception $e) {}
                        }
                        continue;
                    }
                }
            }

            // Step 2: Safety Price Threshold Check (Self-Healing Fallback)
            $ticker = fetch_ticker_for_symbol($orderName);
            if ($ticker) {
                $currentOptionPrice = floatval($ticker['mark_price'] ?? $ticker['close'] ?? $ticker['spot_price'] ?? 0.0);
                if ($currentOptionPrice > 0.0) {
                    log_message("{$orderName} current market option price: \${$currentOptionPrice}");

                    if ($currentOptionPrice >= $tpPrice) {
                        log_message("Self-healing check: Take Profit price crossed but Exchange order not filled. Forcing close.");
                        close_option_buying_trade($db, $account, $order, "TP PRICE HUNT FALLBACK");
                        continue;
                    } elseif ($currentOptionPrice <= $slPrice) {
                        log_message("Self-healing check: Stop Loss price crossed but Exchange order not filled. Forcing close.");
                        close_option_buying_trade($db, $account, $order, "SL PRICE HUNT FALLBACK");
                        continue;
                    }
                }
            }

            // Step 3: Check Intraday UTC Constraint (End of UTC Day 23:59:00 UTC)
            $createdAt = new DateTime($order['created_at'], new DateTimeZone('Asia/Kolkata'));
            $createdAt->setTimezone(new DateTimeZone('UTC'));

            $year = $createdAt->format('Y');
            $month = $createdAt->format('m');
            $day = $createdAt->format('d');

            $endOfUtcDay = new DateTime("{$year}-{$month}-{$day} 23:59:00", new DateTimeZone('UTC'));
            $currentTime = new DateTime('now', new DateTimeZone('UTC'));

            if ($currentTime >= $endOfUtcDay) {
                log_message("Intraday constraint reached (Current UTC: " . $currentTime->format('Y-m-d H:i:s') . " >= End of UTC Day: " . $endOfUtcDay->format('Y-m-d H:i:s') . "). Forcing close.");
                close_option_buying_trade($db, $account, $order, "INTRADAY UTC CLOSE");
                continue;
            }

        } catch (Exception $orderEx) {
            $errStr = "Error monitoring order ID {$order['id']} (User: {$username}): " . $orderEx->getMessage() . "\nStack trace:\n" . $orderEx->getTraceAsString();
            log_message($errStr, "ERROR");
            email_error($errStr, $userEmail);
        }
    }

    log_message("Intraday Option Buying trade monitoring sweep complete.");
}

// Execution block
try {
    check_and_monitor_option_buying_trades();
} catch (Exception $e) {
    $errStr = "Fatal Error during monitor strategy sweep: " . $e->getMessage() . "\nStack trace:\n" . $e->getTraceAsString();
    log_message($errStr, "ERROR");
    email_error($errStr);
}
