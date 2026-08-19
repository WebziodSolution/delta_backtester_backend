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
    $subject = "Intraday Option Buying Strategy Error Alert - Entry Service";
    $html = "<h3>An error occurred in Intraday Option Buying Strategy Service</h3>"
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
        log_message("Public request to {$url} failed (attempt {$attempt}/{$retries}) - HTTP {$httpCode}: {$err}", "WARNING");
        if ($attempt < $retries) {
            sleep(2);
        }
    }
    curl_close($ch);
    throw new Exception("Public request failed to retrieve valid data from {$url}");
}

function fetch_spot_price(string $asset): ?float {
    $url = BASE_URL . "/v2/tickers/" . $asset . "USD";
    try {
        $res = make_public_request_with_retry($url);
        if (isset($res['success']) && $res['success']) {
            $ticker = $res['result'] ?? [];
            $price = floatval($ticker['spot_price'] ?? $ticker['mark_price'] ?? $ticker['close'] ?? 0.0);
            if ($price <= 0.0) {
                log_message("{$asset}USD ticker returned but price fields were empty/zero: " . json_encode($ticker), "ERROR");
                return null;
            }
            return $price;
        }
        log_message("{$asset}USD ticker request returned success=false: " . json_encode($res), "ERROR");
    } catch (Exception $e) {
        log_message("Failed to fetch {$asset}USD ticker spot price: " . $e->getMessage(), "ERROR");
    }
    return null;
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

function fetch_candles(string $symbol, string $resolution, int $startSec, int $endSec): array {
    $url = BASE_URL . "/v2/history/candles?resolution=" . $resolution . "&symbol=" . $symbol . "&start=" . $startSec . "&end=" . $endSec;
    try {
        $res = make_public_request_with_retry($url);
        if (isset($res['result']) && is_array($res['result']) && count($res['result']) > 0) {
            $candles = $res['result'];
            usort($candles, function($a, $b) {
                return $a['time'] <=> $b['time'];
            });
            return $candles;
        }
    } catch (Exception $e) {
        log_message("Failed to fetch {$resolution} candles for {$symbol}: " . $e->getMessage(), "ERROR");
    }
    return [];
}

function fetch_options_for_expiry(string $expiryDateStr): array {
    $url = BASE_URL . "/v2/products?contract_types=call_options,put_options&states=live&expiry=" . $expiryDateStr . "&page_size=1000";
    try {
        $res = make_public_request_with_retry($url);
        if (isset($res['success']) && $res['success']) {
            return $res['result'] ?? [];
        }
    } catch (Exception $e) {
        log_message("Failed to fetch option products for expiry {$expiryDateStr}: " . $e->getMessage(), "ERROR");
    }
    return [];
}

function get_closest_option_by_type(array $optionsList, float $targetStrike, string $type): ?array {
    $filtered = [];
    foreach ($optionsList as $opt) {
        if (($opt['contract_type'] ?? '') === $type) {
            $filtered[] = $opt;
        }
    }
    if (empty($filtered)) {
        return null;
    }
    $closestOpt = null;
    $minDiff = INF;
    foreach ($filtered as $opt) {
        $strike = floatval($opt['strike_price'] ?? 0);
        $diff = abs($strike - $targetStrike);
        if ($diff < $minDiff) {
            $minDiff = $diff;
            $closestOpt = $opt;
        }
    }
    return $closestOpt;
}

// Indicator calculation functions
function calculate_ema(array $data, int $period): array {
    $k = 2 / ($period + 1);
    $ema = [];
    if (empty($data)) return $ema;

    $currentEma = floatval($data[0]['close'] ?? $data[0]['open']);
    $ema[] = $currentEma;

    for ($i = 1; $i < count($data); $i++) {
        $price = floatval($data[$i]['close'] ?? $data[$i]['open']);
        $currentEma = $price * $k + $currentEma * (1 - $k);
        $ema[] = $currentEma;
    }
    return $ema;
}

function calculate_rsi(array $data, int $period): array {
    $count = count($data);
    if ($count < $period) {
        return array_fill(0, $count, 50.0);
    }

    $rsi = [];
    $gains = 0.0;
    $losses = 0.0;

    for ($i = 1; $i <= $period; $i++) {
        $diff = floatval($data[$i]['close'] ?? $data[$i]['open']) - floatval($data[$i - 1]['close'] ?? $data[$i - 1]['open']);
        if ($diff > 0) {
            $gains += $diff;
        } else {
            $losses -= $diff;
        }
    }

    $avgGain = $gains / $period;
    $avgLoss = $losses / $period;

    $rsi[] = ($avgLoss == 0.0) ? 100.0 : (100.0 - 100.0 / (1.0 + $avgGain / $avgLoss));

    for ($i = $period + 1; $i < $count; $i++) {
        $diff = floatval($data[$i]['close'] ?? $data[$i]['open']) - floatval($data[$i - 1]['close'] ?? $data[$i - 1]['open']);
        $gain = ($diff > 0) ? $diff : 0.0;
        $loss = ($diff < 0) ? -$diff : 0.0;

        $avgGain = ($avgGain * ($period - 1) + $gain) / $period;
        $avgLoss = ($avgLoss * ($period - 1) + $loss) / $period;

        $rsi[] = ($avgLoss == 0.0) ? 100.0 : (100.0 - 100.0 / (1.0 + $avgGain / $avgLoss));
    }

    $padding = array_fill(0, $period, 50.0);
    return array_merge($padding, $rsi);
}

function run_intraday_option_buying_strategy(): void {
    log_message("Starting Intraday Option Buying execution service...");

    $db = Database::getInstance()->getConnection();

    // 1. Fetch active accounts
    $stmt = $db->query("SELECT id, user_id, api_key, api_secret, active FROM account_info WHERE active = 1");
    $activeAccounts = $stmt->fetchAll();

    log_message("Identified " . count($activeAccounts) . " active accounts to process.");

    // Caching variables to avoid hitting public endpoints repeatedly for the same asset
    $cachedCandles = [];
    $cachedEMA = [];
    $cachedRSI = [];

    foreach ($activeAccounts as $account) {
        $userId = intval($account['user_id']);

        // Load user email and info
        $userEmail = null;
        $username = 'Trader';
        try {
            $user = UserService::getById($userId);
            $userEmail = $user['email'];
            $username = $user['username'];
        } catch (Exception $e) {
            log_message("Account ID {$account['id']} has no valid user. Skipping.", "WARNING");
            continue;
        }

        try {
            if (empty($account['api_key']) || empty($account['api_secret'])) {
                log_message("Account ID {$account['id']} has missing API credentials. Skipping.", "WARNING");
                continue;
            }

            // 2. Fetch trade configuration from strategy subscription (strategy_id = 1)
            $subStmt = $db->prepare("SELECT asset, margin_allocation, leverage, peak_balance, allocated_balance, current_balance FROM subscribe_strategys WHERE user_id = :user_id AND strategy_id = :strat_id");
            $subStmt->execute(['user_id' => $userId, 'strat_id' => STRATEGY_ID]);
            $subscription = $subStmt->fetch();

            if (!$subscription) {
                log_message("User {$username} is not subscribed to Intraday Option Buying strategy (strategy_id = " . STRATEGY_ID . "). Skipping.");
                continue;
            }

            $asset = strtoupper(trim($subscription['asset']));
            if (empty($asset)) {
                log_message("Subscription asset is empty for user {$username}. Skipping.", "WARNING");
                continue;
            }

            $allocationPercent = intval($subscription['margin_allocation'] ?? 50);
            $leverage = intval($subscription['leverage'] ?? 10);
            $peakBalance = $subscription['peak_balance'] !== null ? floatval($subscription['peak_balance']) : null;
            $allocatedBalance = $subscription['allocated_balance'] !== null ? floatval($subscription['allocated_balance']) : null;
            $currentBalance = $subscription['current_balance'] !== null ? floatval($subscription['current_balance']) : null;

            // 3. One position at a time check (no overlapping trades)
            $openCheckStmt = $db->prepare("SELECT id FROM orders_info WHERE user_id = :user_id AND strategy_id = :strat_id AND status = 'open'");
            $openCheckStmt->execute(['user_id' => $userId, 'strat_id' => STRATEGY_ID]);
            if ($openCheckStmt->fetch()) {
                log_message("User {$username} already has an open Intraday Option Buying trade. Skipping entry checks.");
                continue;
            }

            // 4. Daily trade limit check (max 3 trades)
            $todayStart = date('Y-m-d 00:00:00');
            $countStmt = $db->prepare("SELECT COUNT(*) as trade_count FROM orders_info WHERE user_id = :user_id AND strategy_id = :strat_id AND created_at >= :today_start");
            $countStmt->execute(['user_id' => $userId, 'strat_id' => STRATEGY_ID, 'today_start' => $todayStart]);
            $tradeCountRow = $countStmt->fetch();
            $tradeCount = intval($tradeCountRow['trade_count'] ?? 0);

            if ($tradeCount >= 3) {
                log_message("User {$username} has reached the daily limit of 3 Intraday Option Buying trades today. Skipping.");
                continue;
            }

            // 5. Fetch spot candles for analysis (or load from cache)
            if (!isset($cachedCandles[$asset])) {
                $endSec = time();
                $startSec = $endSec - 300 * 900; // Last 300 candles of 15m (about 3.1 days)
                log_message("Fetching spot candles for {$asset}USD...");
                $spotCandles = fetch_candles($asset . "USD", "15m", $startSec, $endSec);

                if (count($spotCandles) < 200) {
                    log_message("Insufficient candle history for {$asset}USD (found " . count($spotCandles) . " candles, need at least 200). Skipping.", "WARNING");
                    continue;
                }

                $cachedCandles[$asset] = $spotCandles;
                $cachedEMA[$asset] = calculate_ema($spotCandles, 200);
                $cachedRSI[$asset] = calculate_rsi($spotCandles, 14);
            }

            $spotCandles = $cachedCandles[$asset];
            $ema200 = $cachedEMA[$asset];
            $rsis = $cachedRSI[$asset];

            // Analyze last completed candle
            $idx = count($spotCandles) - 2;
            $candle = $spotCandles[$idx];
            $currentSpot = floatval($candle['close'] ?? $candle['open']);
            $trendEma = $ema200[$idx];
            $rsiVal = $rsis[$idx];
            $prevRsiVal = $rsis[$idx - 1];

            // Identify Trend
            $trend = ($currentSpot > $trendEma) ? "UPTREND" : "DOWNTREND";

            // Fixed Logic: RSI Breakout (cross 50) only
            $entrySignal = null;
            if ($trend === "UPTREND") {
                if ($rsiVal > 50.0 && $prevRsiVal <= 50.0) {
                    $entrySignal = "CALL";
                }
            } else if ($trend === "DOWNTREND") {
                if ($rsiVal < 50.0 && $prevRsiVal >= 50.0) {
                    $entrySignal = "PUT";
                }
            }

            if ($entrySignal === null) {
                log_message("No breakout signal detected for user {$username} on asset {$asset}. Trend: {$trend}, Spot: {$currentSpot}, EMA: {$trendEma}, RSI: {$rsiVal}, Prev RSI: {$prevRsiVal}");
                continue;
            }

            log_message("Breakout signal [{$entrySignal}] detected for user {$username} on asset {$asset}!");

            // 6. Select Option Contract
            $expiryDateTime = new DateTime('+1 day');
            $expiryDateStr = $expiryDateTime->format('Y-m-d');
            log_message("Fetching active options chain for expiry: {$expiryDateStr}...");
            $optionsList = fetch_options_for_expiry($expiryDateStr);

            if (empty($optionsList)) {
                log_message("No option contracts returned for expiry {$expiryDateStr}. Skipping.", "WARNING");
                continue;
            }

            $strikeInterval = ($asset === "BTC") ? 100 : 10;
            $targetStrike = round($currentSpot / $strikeInterval) * $strikeInterval;

            $contractType = ($entrySignal === "CALL") ? "call_options" : "put_options";
            $chosenOption = get_closest_option_by_type($optionsList, $targetStrike, $contractType);

            if (!$chosenOption) {
                log_message("Could not identify closest {$contractType} option at strike {$targetStrike}. Skipping.", "WARNING");
                continue;
            }

            log_message("Selected Option Contract: {$chosenOption['symbol']} (Strike: {$chosenOption['strike_price']}, Type: {$contractType})");

            // 7. Retrieve entry price
            $ticker = fetch_ticker_for_symbol($chosenOption['symbol']);
            $entryPrice = floatval($ticker['mark_price'] ?? $ticker['close'] ?? $ticker['spot_price'] ?? 0.0);

            if (!$entryPrice || $entryPrice <= 0) {
                log_message("Could not retrieve a valid ticker price for contract {$chosenOption['symbol']}. Skipping.", "WARNING");
                continue;
            }

            // 8. Wallet Balance Tracking (Actual vs Virtual)
            $deltaClient = new DeltaClient($account['api_key'], $account['api_secret']);
            list($status, $balancesResp) = $deltaClient->getBalances();

            if ($status !== 200 || !isset($balancesResp['success']) || !$balancesResp['success']) {
                log_message("Failed to fetch balance details from exchange for account ID {$account['id']}. Status: {$status}.", "ERROR");
                continue;
            }

            $balances = $balancesResp['result'] ?? [];
            $exchangeUsdAvailable = 0.0;
            foreach ($balances as $bal) {
                if (intval($bal['asset_id'] ?? 0) === 14) { // USD/USDT
                    $exchangeUsdAvailable = floatval($bal['available_balance'] ?? 0.0);
                    break;
                }
            }

            log_message("Actual Exchange Available Wallet Balance: \${$exchangeUsdAvailable} USDT");

            if ($exchangeUsdAvailable <= 0.0) {
                log_message("Account ID {$account['id']} has zero or negative available exchange balance. Skipping.", "WARNING");
                continue;
            }

            // Determine USD available for this strategy
            if ($allocatedBalance !== null && $allocatedBalance > 0.0) {
                // Initialize current balance if it hasn't been set
                if ($currentBalance === null) {
                    $currentBalance = $allocatedBalance;
                    $db->prepare("UPDATE subscribe_strategys SET current_balance = :current WHERE user_id = :uid AND strategy_id = :strat_id")
                       ->execute(['current' => $currentBalance, 'uid' => $userId, 'strat_id' => STRATEGY_ID]);
                    log_message("Initialized virtual running balance to {$currentBalance} for user {$userId}");
                }

                // Cap the virtual balance by the actual exchange balance to prevent margin errors
                $usdAvailable = min($currentBalance, $exchangeUsdAvailable);
                log_message("Using Virtual Balance: \${$currentBalance} USDT (Capped by exchange balance: \${$usdAvailable} USDT)");
            } else {
                // Fallback to total exchange balance if no virtual balance is set
                $usdAvailable = $exchangeUsdAvailable;
                log_message("No virtual balance set. Using full Exchange Balance: \${$usdAvailable} USDT");
            }

            if ($usdAvailable <= 0.0) {
                log_message("Calculated USD available for trade is zero or negative. Skipping.", "WARNING");
                if ($userEmail) {
                    $subject = "Account Balance Insufficient - Intraday Option Buying";
                    $html = "<p>Dear {$username},</p>"
                          . "<p>The Intraday Option Buying strategy could not execute because your strategy balance is \$" . number_format($usdAvailable, 2) . " USDT.</p>"
                          . "<p>Please deposit more funds or allocate more margin to the strategy.</p>"
                          . "<p>Best regards,<br/>Delta Backtester Automation Service</p>";
                    try {
                        EmailService::send($userEmail, $subject, $html);
                    } catch (Exception $mailEx) {}
                }
                continue;
            }

            // Update peak balance in DB
            if ($peakBalance === null || $peakBalance <= 0.0) {
                $peakBalance = $usdAvailable;
                $db->prepare("UPDATE subscribe_strategys SET peak_balance = :peak WHERE user_id = :uid AND strategy_id = :strat_id")
                   ->execute(['peak' => $peakBalance, 'uid' => $userId, 'strat_id' => STRATEGY_ID]);
                log_message("Initialized peak balance to {$peakBalance} for user {$userId}");
            } elseif ($usdAvailable > $peakBalance) {
                $peakBalance = $usdAvailable;
                $db->prepare("UPDATE subscribe_strategys SET peak_balance = :peak WHERE user_id = :uid AND strategy_id = :strat_id")
                   ->execute(['peak' => $peakBalance, 'uid' => $userId, 'strat_id' => STRATEGY_ID]);
                log_message("Updated peak balance to new high {$peakBalance} for user {$userId}");
            }

            // Position Sizing based on Allocation per Trade (%) of Strategy Balance
            $allocatedUsd = $usdAvailable * ($allocationPercent / 100.0);
            $rawQty = $allocatedUsd / $entryPrice;
            $lotMult = ($asset === "BTC") ? 1000 : 100;
            $minQty = ($asset === "BTC") ? 0.001 : 0.01;

            $qtyLots = floor($rawQty * $lotMult);
            if ($qtyLots < 1) {
                $qtyLots = 1;
            }
            $actualQty = $qtyLots / $lotMult;

            // Purchase Cost Safeguard Check
            $purchaseCost = $actualQty * $entryPrice;
            if ($purchaseCost > $usdAvailable) {
                $reductionRatio = $usdAvailable / $purchaseCost;
                $scaledQtyLots = floor(($actualQty * $reductionRatio) * $lotMult);
                if ($scaledQtyLots < 1) {
                    log_message("Insufficient balance even for minimum contract size (1 lot). Skipping trade.", "WARNING");
                    if ($userEmail) {
                        $subject = "Account Balance Insufficient - Intraday Option Buying";
                        $html = "<p>Dear {$username},</p>"
                              . "<p>The Intraday Option Buying strategy could not execute because your balance is insufficient to cover the purchase cost for the minimum contract size (1 lot).</p>"
                              . "<p><strong>Available Balance:</strong> \$" . number_format($usdAvailable, 2) . " USDT</p>"
                              . "<p><strong>Required Premium Cost (for 1 lot):</strong> \$" . number_format($entryPrice / $lotMult, 4) . " USDT</p>"
                              . "<p>Please deposit more funds or allocate more margin to the strategy.</p>"
                              . "<p>Best regards,<br/>Delta Backtester Automation Service</p>";
                        try {
                            EmailService::send($userEmail, $subject, $html);
                        } catch (Exception $mailEx) {}
                    }
                    continue;
                }
                $qtyLots = $scaledQtyLots;
                $actualQty = $qtyLots / $lotMult;
                $purchaseCost = $actualQty * $entryPrice;
            }

            // Dynamic Stop Loss (1% of balance) and Take Profit (3% of balance)
            $riskBalance = ($currentBalance !== null && $currentBalance > 0.0) ? $currentBalance : $usdAvailable;
            $stopLossAmount = $riskBalance * 0.01;
            $tpAmount = $stopLossAmount * 3.0;

            $slPrice = $entryPrice - ($stopLossAmount / $actualQty);
            if ($slPrice < 0) {
                $slPrice = 0.0;
            }
            $tpPrice = $entryPrice + ($tpAmount / $actualQty);

            log_message("Executing order sizing: Allocation: {$allocationPercent}%, Qty: {$qtyLots} lots ({$actualQty} {$asset}). Cost: \${$purchaseCost} USDT. Target TP: \${$tpPrice}, SL: \${$slPrice}");

            // 9. Set contract leverage
            $prodId = $chosenOption['id'];
            list($levStatus, $levResp) = $deltaClient->setLeverage($prodId, $leverage);

            if ($levStatus !== 200 && $levStatus !== 201) {
                if (is_array($levResp) && ($levResp['error']['code'] ?? '') === 'unsupported') {
                    log_message("Note: Account is in PM mode; leverage is managed automatically for {$chosenOption['symbol']}.");
                } else {
                    log_message("Failed to set leverage to {$leverage}x for {$chosenOption['symbol']}. Response: " . json_encode($levResp), "WARNING");
                }
            }

            // 10. Place live entry market order (BUY)
            $side = "buy";
            log_message("Placing live entry BUY market order for {$qtyLots} contracts of {$chosenOption['symbol']}...");
            list($orderStatus, $orderResp) = $deltaClient->placeOrder($prodId, $qtyLots, $side, "market_order");

            if (($orderStatus === 200 || $orderStatus === 201) && isset($orderResp['success']) && $orderResp['success']) {
                $placedOrder = $orderResp['result'] ?? [];
                $orderId = $placedOrder['id'] ?? '';
                log_message("Entry order successfully placed on Exchange. Order ID: {$orderId}");

                // Wait 1.5 seconds for fill execution details
                usleep(1500000);

                list($fillStatus, $fillResp) = $deltaClient->getFills();
                $fills = ($fillStatus === 200 && isset($fillResp['success']) && $fillResp['success']) ? ($fillResp['result'] ?? []) : [];
                
                $totalSize = 0.0;
                $totalVal = 0.0;
                $totalFee = 0.0;

                foreach ($fills as $f) {
                    if (strval($f['order_id'] ?? '') === strval($orderId)) {
                        $sz = floatval($f['size'] ?? 0.0);
                        $pr = floatval($f['price'] ?? 0.0);
                        $fe = floatval($f['commission'] ?? $f['fee'] ?? 0.0);
                        
                        $totalSize += $sz;
                        $totalVal += ($pr * $sz);
                        $totalFee += $fe;
                    }
                }

                $avgFillPrice = $totalSize > 0 ? ($totalVal / $totalSize) : $entryPrice;

                // Re-calculate SL/TP prices with the exact average fill price
                $slPrice = $avgFillPrice - ($stopLossAmount / $actualQty);
                if ($slPrice < 0) {
                    $slPrice = 0.0;
                }
                $tpPrice = $avgFillPrice + ($tpAmount / $actualQty);

                log_message("Filled Entry Avg Price: \${$avgFillPrice}. Re-calculated TP Price: \${$tpPrice}. Target SL Price: \${$slPrice}");

                // 11. Place Direct Exchange SL/TP Limit Orders
                // A. Take Profit Limit Order
                $tpParams = [
                    "limit_price" => strval(round($tpPrice, 4))
                ];
                log_message("Placing live Take Profit Limit Order at \${$tpPrice}...");
                list($tpStatus, $tpResp) = $deltaClient->placeOrder($prodId, $qtyLots, "sell", "limit_order", true, $tpParams);
                
                $tpOrderId = null;
                if (($tpStatus === 200 || $tpStatus === 201) && isset($tpResp['success']) && $tpResp['success']) {
                    $tpOrderId = $tpResp['result']['id'] ?? null;
                    log_message("Take Profit limit order placed successfully. Order ID: {$tpOrderId}");
                } else {
                    $tpError = $tpResp['message'] ?? $tpResp['error']['message'] ?? json_encode($tpResp);
                    log_message("Failed to place Take Profit limit order on Exchange: {$tpError}", "ERROR");
                }

                // B. Stop Loss Stop Limit Order
                $slParams = [
                    "stop_order_type" => "stop_loss_order",
                    "stop_price" => strval(round($slPrice, 4)),
                    "limit_price" => strval(round($slPrice, 4))
                ];
                log_message("Placing live Stop Loss Stop Limit Order at \${$slPrice}...");
                list($slStatus, $slResp) = $deltaClient->placeOrder($prodId, $qtyLots, "sell", "limit_order", true, $slParams);

                $slOrderId = null;
                if (($slStatus === 200 || $slStatus === 201) && isset($slResp['success']) && $slResp['success']) {
                    $slOrderId = $slResp['result']['id'] ?? null;
                    log_message("Stop Loss stop-limit order placed successfully. Order ID: {$slOrderId}");
                } else {
                    $slError = $slResp['message'] ?? $slResp['error']['message'] ?? json_encode($slResp);
                    log_message("Failed to place Stop Loss stop-limit order on Exchange: {$slError}", "ERROR");
                }

                // 12. Record transaction in orders_info
                $insertStmt = $db->prepare("INSERT INTO orders_info (order_id, order_name, order_type, entry_amount, exit_amount, pnl, broker_fees, qty, status, account_info_id, user_id, strategy_id, tp_price, sl_price, trade_action, tp_order_id, sl_order_id) 
                                            VALUES (:order_id, :order_name, 'buy', :entry_amount, 0.0, 0.0, :broker_fees, :qty, 'open', :account_info_id, :user_id, :strat_id, :tp_price, :sl_price, 'BUY', :tp_order_id, :sl_order_id)");
                $insertStmt->execute([
                    'order_id' => strval($orderId),
                    'order_name' => $chosenOption['symbol'],
                    'entry_amount' => round($avgFillPrice, 4),
                    'broker_fees' => round($totalFee, 4),
                    'qty' => $qtyLots,
                    'account_info_id' => $account['id'],
                    'user_id' => $userId,
                    'strat_id' => STRATEGY_ID,
                    'tp_price' => round($tpPrice, 4),
                    'sl_price' => round($slPrice, 4),
                    'tp_order_id' => $tpOrderId ? strval($tpOrderId) : null,
                    'sl_order_id' => $slOrderId ? strval($slOrderId) : null
                ]);

                log_message("Saved order details in database orders_info table.");

                // 13. Send trade placement email
                if ($userEmail) {
                    $subject = "Intraday Option Buying Strategy Entry Successful - Delta Exchange";
                    $html = "<p>Dear {$username},</p>"
                          . "<p>A new Intraday Option Buying live order has been successfully placed on Delta Exchange.</p>"
                          . "<h3>Trade Details:</h3>"
                          . "<ul>"
                          . "<li><strong>Option Contract:</strong> {$chosenOption['symbol']}</li>"
                          . "<li><strong>Trade Action:</strong> BUY ({$entrySignal} breakout setup)</li>"
                          . "<li><strong>Order ID:</strong> {$orderId}</li>"
                          . "<li><strong>Quantity (Lots):</strong> {$qtyLots} contracts ({$actualQty} {$asset})</li>"
                          . "<li><strong>Average Entry Price:</strong> \$" . number_format($avgFillPrice, 4) . " USD</li>"
                          . "<li><strong>Direct Take Profit Limit Order:</strong> " . ($tpOrderId ? "\${$tpPrice} (Order ID: {$tpOrderId})" : "FAILED to place on Exchange (will monitor manually)") . "</li>"
                          . "<li><strong>Direct Stop Loss Stop Limit Order:</strong> " . ($slOrderId ? "\${$slPrice} (Order ID: {$slOrderId})" : "FAILED to place on Exchange (will monitor manually)") . "</li>"
                          . "<li><strong>Allocated Margin Limit:</strong> \$" . number_format($allocatedUsd, 2) . " USD</li>"
                          . "<li><strong>Required Margin:</strong> \$" . number_format($purchaseCost, 4) . " USD</li>"
                          . "</ul>"
                          . "<p>Best regards,<br/>Delta Backtester Automation Service</p>";
                    try {
                        EmailService::send($userEmail, $subject, $html);
                        log_message("Confirmation email sent to {$userEmail}");
                    } catch (Exception $mailEx) {
                        log_message("Failed to send execution email: " . $mailEx->getMessage(), "ERROR");
                    }
                }

            } else {
                $errorMsg = $orderResp['message'] ?? $orderResp['error']['message'] ?? "Unknown API response error";
                log_message("Failed to place entry market order for user {$username} on {$chosenOption['symbol']}. Error: {$errorMsg}", "ERROR");

                if ($userEmail) {
                    $subject = "Intraday Option Buying Placement Failed - {$chosenOption['symbol']}";
                    $html = "<p>Dear {$username},</p>"
                          . "<p>We failed to execute the Intraday Option Buying order on Delta Exchange for contract <strong>{$chosenOption['symbol']}</strong>.</p>"
                          . "<p><strong>Error details:</strong> {$errorMsg}</p>"
                          . "<p>Please check your integration credentials or account status.</p>";
                    try {
                        EmailService::send($userEmail, $subject, $html);
                    } catch (Exception $mailEx) {}
                }
            }
        } catch (Exception $accountEx) {
            $errStr = "Error processing account ID {$account['id']} (User: {$username}): " . $accountEx->getMessage() . "\nStack trace:\n" . $accountEx->getTraceAsString();
            log_message($errStr, "ERROR");
            email_error($errStr, $userEmail);
        }
    }

    log_message("Intraday option buying execution service complete.");
}

// Execution block
try {
    run_intraday_option_buying_strategy();
} catch (Exception $e) {
    $errStr = "Fatal Error during strategy execution: " . $e->getMessage() . "\nStack trace:\n" . $e->getTraceAsString();
    log_message($errStr, "ERROR");
    email_error($errStr);
}
