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

function email_error(string $errorMessage, ?string $userEmail = null): void {
    $adminEmail = $_ENV['SMTP_USERNAME'] ?? 'webzoidsolution@gmail.com';
    $subject = "Option Scalping Strategy Error Alert - Entry Service";
    $html = "<h3>An error occurred in Option Scalping Strategy Service</h3>"
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

function calculate_macd(array $data, int $fastPeriod = 12, int $slowPeriod = 26, int $signalPeriod = 9): array {
    $fastEMA = calculate_ema($data, $fastPeriod);
    $slowEMA = calculate_ema($data, $slowPeriod);

    $macdLine = [];
    for ($i = 0; $i < count($data); $i++) {
        $macdLine[] = $fastEMA[$i] - $slowEMA[$i];
    }

    $k = 2 / ($signalPeriod + 1);
    $signalLine = [];
    if (!empty($macdLine)) {
        $currentSignal = $macdLine[0];
        $signalLine[] = $currentSignal;
        for ($i = 1; $i < count($macdLine); $i++) {
            $currentSignal = $macdLine[$i] * $k + $currentSignal * (1 - $k);
            $signalLine[] = $currentSignal;
        }
    }

    $histogram = [];
    for ($i = 0; $i < count($macdLine); $i++) {
        $histogram[] = $macdLine[$i] - $signalLine[$i];
    }

    return [
        'macdLine' => $macdLine,
        'signalLine' => $signalLine,
        'histogram' => $histogram
    ];
}

function run_option_scalping_strategy(): void {
    log_message("Starting option scalping execution service...");

    $db = Database::getInstance()->getConnection();

    // 1. Fetch active accounts
    $stmt = $db->query("SELECT id, user_id, api_key, api_secret, active FROM account_info WHERE active = 1");
    $activeAccounts = $stmt->fetchAll();

    log_message("Identified " . count($activeAccounts) . " active accounts to process.");

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
            $subStmt = $db->prepare("SELECT asset, margin_allocation, leverage, peak_balance FROM subscribe_strategys WHERE user_id = :user_id AND strategy_id = 1");
            $subStmt->execute(['user_id' => $userId]);
            $subscription = $subStmt->fetch();

            if (!$subscription) {
                log_message("User {$username} is not subscribed to Option Scalping strategy (strategy_id = 1). Skipping.");
                continue;
            }

            $asset = strtoupper(trim($subscription['asset']));
            if (empty($asset)) {
                log_message("Subscription asset is empty for user {$username}. Skipping.", "WARNING");
                continue;
            }

            $allocationPercent = intval($subscription['margin_allocation'] ?? 50);
            $leverage = intval($subscription['leverage'] ?? 25);
            $peakBalance = $subscription['peak_balance'] !== null ? floatval($subscription['peak_balance']) : null;

            // 3. One position at a time check (no overlapping trades)
            $openCheckStmt = $db->prepare("SELECT id FROM orders_info WHERE user_id = :user_id AND strategy_id = 1 AND status = 'open'");
            $openCheckStmt->execute(['user_id' => $userId]);
            if ($openCheckStmt->fetch()) {
                log_message("User {$username} already has an open Option Scalping trade. Skipping entry checks.");
                continue;
            }

            // 4. Daily trade limit check (max 3 trades)
            $todayStart = date('Y-m-d 00:00:00');
            $countStmt = $db->prepare("SELECT COUNT(*) as trade_count FROM orders_info WHERE user_id = :user_id AND strategy_id = 1 AND created_at >= :today_start");
            $countStmt->execute(['user_id' => $userId, 'today_start' => $todayStart]);
            $tradeCountRow = $countStmt->fetch();
            $tradeCount = intval($tradeCountRow['trade_count'] ?? 0);

            if ($tradeCount >= 3) {
                log_message("User {$username} has reached the daily limit of 3 Option Scalping trades today. Skipping.");
                continue;
            }

            // 5. Fetch spot candles for analysis
            $endSec = time();
            $startSec = $endSec - 300 * 900; // Last 300 candles of 15m (about 3.1 days)
            log_message("Fetching spot candles for {$asset}USD...");
            $spotCandles = fetch_candles($asset . "USD", "15m", $startSec, $endSec);

            if (count($spotCandles) < 200) {
                log_message("Insufficient candle history for {$asset}USD (found " . count($spotCandles) . " candles, need at least 200). Skipping.", "WARNING");
                continue;
            }

            // Calculate indicators
            $ema200 = calculate_ema($spotCandles, 200);
            $rsis = calculate_rsi($spotCandles, 14);
            $macd = calculate_macd($spotCandles, 12, 26, 9);

            // Analyze last completed candle
            $idx = count($spotCandles) - 2;
            $candle = $spotCandles[$idx];
            $currentSpot = floatval($candle['close'] ?? $candle['open']);
            $trendEma = $ema200[$idx];
            $rsiVal = $rsis[$idx];
            $macdVal = $macd['macdLine'][$idx];
            $sigVal = $macd['signalLine'][$idx];
            $prevMacdVal = $macd['macdLine'][$idx - 1];
            $prevSigVal = $macd['signalLine'][$idx - 1];

            // Identify Trend
            $trend = "SIDEWAYS";
            $emaDiffPercent = abs($currentSpot - $trendEma) / $trendEma;
            if ($emaDiffPercent > 0.002) {
                $trend = ($currentSpot > $trendEma) ? "UPTREND" : "DOWNTREND";
            }

            $signal = null;
            if ($trend === "UPTREND") {
                if ($macdVal > $sigVal && $prevMacdVal <= $prevSigVal && $rsiVal > 45) {
                    $signal = "CALL";
                }
            } else if ($trend === "DOWNTREND") {
                if ($macdVal < $sigVal && $prevMacdVal >= $prevSigVal && $rsiVal < 55) {
                    $signal = "PUT";
                }
            }

            if ($signal === null) {
                log_message("No trade signal detected for user {$username} on asset {$asset}. Trend: {$trend}, Spot: {$currentSpot}, EMA: {$trendEma}, RSI: {$rsiVal}, MACD: {$macdVal}, SignalLine: {$sigVal}");
                continue;
            }

            log_message("Trade signal [{$signal}] detected for user {$username} on asset {$asset}!");

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

            $isHighMomentum = ($signal === "CALL") ? ($rsiVal > 58) : ($rsiVal < 42);
            $dynamicTradeAction = $isHighMomentum ? "BUY" : "SELL";

            $contractType = ($dynamicTradeAction === "BUY")
                ? (($signal === "CALL") ? "call_options" : "put_options")
                : (($signal === "CALL") ? "put_options" : "call_options");

            $chosenOption = get_closest_option_by_type($optionsList, $targetStrike, $contractType);

            if (!$chosenOption) {
                log_message("Could not identify closest {$contractType} option at strike {$targetStrike}. Skipping.", "WARNING");
                continue;
            }

            log_message("Selected Option Contract: {$chosenOption['symbol']} (Strike: {$chosenOption['strike_price']}, Action: {$dynamicTradeAction})");

            // 7. Retrieve entry price
            $ticker = fetch_ticker_for_symbol($chosenOption['symbol']);
            $entryPrice = floatval($ticker['mark_price'] ?? $ticker['close'] ?? $ticker['spot_price'] ?? 0.0);

            if (!$entryPrice || $entryPrice <= 0) {
                log_message("Could not retrieve a valid ticker price for contract {$chosenOption['symbol']}. Skipping.", "WARNING");
                continue;
            }

            // 8. Wallet Balance & Peak Tracking (Drawdown Protection)
            $deltaClient = new DeltaClient($account['api_key'], $account['api_secret']);
            list($status, $balancesResp) = $deltaClient->getBalances();

            if ($status !== 200 || !isset($balancesResp['success']) || !$balancesResp['success']) {
                log_message("Failed to fetch balance details from exchange for account ID {$account['id']}. Status: {$status}.", "ERROR");
                continue;
            }

            $balances = $balancesResp['result'] ?? [];
            $usdAvailable = 0.0;
            foreach ($balances as $bal) {
                if (intval($bal['asset_id'] ?? 0) === 14) { // USD/USDT
                    $usdAvailable = floatval($bal['available_balance'] ?? 0.0);
                    break;
                }
            }

            log_message("Available Wallet Balance: \${$usdAvailable} USDT");

            if ($usdAvailable <= 0.0) {
                log_message("Account ID {$account['id']} has zero or negative available balance. Skipping.", "WARNING");
                continue;
            }

            // Update peak balance in DB
            if ($peakBalance === null || $peakBalance <= 0.0) {
                $peakBalance = $usdAvailable;
                $db->prepare("UPDATE subscribe_strategys SET peak_balance = :peak WHERE user_id = :uid AND strategy_id = 1")
                   ->execute(['peak' => $peakBalance, 'uid' => $userId]);
                log_message("Initialized peak balance to {$peakBalance} for user {$userId}");
            } elseif ($usdAvailable > $peakBalance) {
                $peakBalance = $usdAvailable;
                $db->prepare("UPDATE subscribe_strategys SET peak_balance = :peak WHERE user_id = :uid AND strategy_id = 1")
                   ->execute(['peak' => $peakBalance, 'uid' => $userId]);
                log_message("Updated peak balance to new high {$peakBalance} for user {$userId}");
            }

            // Drawdown scale calculation
            $currentDrawdown = ($peakBalance - $usdAvailable) / $peakBalance;
            $scaleFactor = 1.0;
            if ($currentDrawdown > 0) {
                $scaleFactor = max(0.05, 1.0 - ($currentDrawdown / 0.24));
            }

            $dynamicAllocation = $allocationPercent * $scaleFactor;
            $maxAlloc = $usdAvailable * ($dynamicAllocation / 100.0);

            // Position sizing based on action
            $tradeQty = 0.0;
            if ($dynamicTradeAction === "BUY") {
                $tradeQty = $maxAlloc / $entryPrice;
            } else {
                $tradeQty = ($maxAlloc * $leverage) / $targetStrike;
            }

            $lotMult = ($asset === "BTC") ? 1000 : 100; // BTC contracts have size 0.001, ETH contracts have size 0.01
            $qtyLots = floor($tradeQty * $lotMult);
            if ($qtyLots < 1) {
                $qtyLots = 1;
            }
            $actualQty = $qtyLots / $lotMult;

            // Margin Safeguard Check
            $requiredMargin = ($dynamicTradeAction === "BUY")
                ? ($actualQty * $entryPrice)
                : (($targetStrike * $actualQty) / $leverage);

            if ($requiredMargin > $usdAvailable) {
                $reductionRatio = $usdAvailable / $requiredMargin;
                $scaledQtyLots = floor(($actualQty * $reductionRatio) * $lotMult);
                if ($scaledQtyLots < 1) {
                    log_message("Insufficient margin even for minimum contract size (1 lot). Skipping trade.", "WARNING");
                    continue;
                }
                $qtyLots = $scaledQtyLots;
                $actualQty = $qtyLots / $lotMult;
                $requiredMargin = ($dynamicTradeAction === "BUY")
                    ? ($actualQty * $entryPrice)
                    : (($targetStrike * $actualQty) / $leverage);
            }

            log_message("Executing scalping order sizing: Allocation: {$dynamicAllocation}%, Scaled Qty: {$qtyLots} contracts ({$actualQty} {$asset}). Required Margin: \${$requiredMargin} USDT");

            // 9. Set contract leverage
            $prodId = $chosenOption['id'];
            list($levStatus, $levResp) = $deltaClient->setLeverage($prodId, $leverage);

            if ($levStatus !== 200 && $levStatus !== 201) {
                if (is_array($levResp) && ($levResp['error']['code'] ?? '') === 'unsupported') {
                    log_message("Note: Account is in Portfolio Margin mode; leverage was handled automatically by exchange for {$chosenOption['symbol']}.");
                } else {
                    log_message("Failed to set leverage to {$leverage}x for {$chosenOption['symbol']}. Response: " . json_encode($levResp), "WARNING");
                }
            }

            // 10. Place market order
            $side = ($dynamicTradeAction === "BUY") ? "buy" : "sell";
            log_message("Placing live {$side} market order for {$qtyLots} contracts of {$chosenOption['symbol']}...");
            list($orderStatus, $orderResp) = $deltaClient->placeOrder($prodId, $qtyLots, $side, "market_order");

            if (($orderStatus === 200 || $orderStatus === 201) && isset($orderResp['success']) && $orderResp['success']) {
                $placedOrder = $orderResp['result'] ?? [];
                $orderId = $placedOrder['id'] ?? '';
                log_message("Order successfully placed. Order ID: {$orderId}");

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

                // Calculate TP/SL targets (TP 50% / SL 25% for BUY; TP 60% / SL 30% for SELL)
                $dynamicTpPercent = ($dynamicTradeAction === "BUY") ? 50 : 60;
                $dynamicSlPercent = ($dynamicTradeAction === "BUY") ? 25 : 30;

                $tpPrice = 0.0;
                $slPrice = 0.0;
                if ($dynamicTradeAction === "BUY") {
                    $tpPrice = $avgFillPrice * (1 + $dynamicTpPercent / 100.0);
                    $slPrice = $avgFillPrice * (1 - $dynamicSlPercent / 100.0);
                } else {
                    $tpPrice = $avgFillPrice * (1 - $dynamicTpPercent / 100.0);
                    $slPrice = $avgFillPrice * (1 + $dynamicSlPercent / 100.0);
                }

                log_message("Filled Avg Price: \${$avgFillPrice}. Target TP Price: \${$tpPrice}. Target SL Price: \${$slPrice}");

                // Record transaction in orders_info
                $insertStmt = $db->prepare("INSERT INTO orders_info (order_id, order_name, order_type, entry_amount, exit_amount, pnl, broker_fees, qty, status, account_info_id, user_id, strategy_id, tp_price, sl_price, trade_action) 
                                            VALUES (:order_id, :order_name, :order_type, :entry_amount, 0.0, 0.0, :broker_fees, :qty, 'open', :account_info_id, :user_id, 1, :tp_price, :sl_price, :trade_action)");
                $insertStmt->execute([
                    'order_id' => strval($orderId),
                    'order_name' => $chosenOption['symbol'],
                    'order_type' => $side,
                    'entry_amount' => round($avgFillPrice, 4),
                    'broker_fees' => round($totalFee, 4),
                    'qty' => $qtyLots,
                    'account_info_id' => $account['id'],
                    'user_id' => $userId,
                    'tp_price' => round($tpPrice, 4),
                    'sl_price' => round($slPrice, 4),
                    'trade_action' => $dynamicTradeAction
                ]);

                log_message("Saved order details in database orders_info table.");

                // 11. Send trade placement email
                if ($userEmail) {
                    $subject = "Option Scalping Strategy Entry Successful - Delta Backtester";
                    $html = "<p>Dear {$username},</p>"
                          . "<p>A new Option Scalping live order has been successfully placed on Delta Exchange.</p>"
                          . "<h3>Trade Details:</h3>"
                          . "<ul>"
                          . "<li><strong>Option Contract:</strong> {$chosenOption['symbol']}</li>"
                          . "<li><strong>Trade Side / Action:</strong> {$dynamicTradeAction} ({$signal} setup)</li>"
                          . "<li><strong>Order ID:</strong> {$orderId}</li>"
                          . "<li><strong>Quantity (Lots):</strong> {$qtyLots} contracts ({$actualQty} {$asset})</li>"
                          . "<li><strong>Average Entry Price:</strong> \$" . number_format($avgFillPrice, 4) . " USD</li>"
                          . "<li><strong>Dynamic Take Profit:</strong> \$" . number_format($tpPrice, 4) . " USD ({$dynamicTpPercent}%)</li>"
                          . "<li><strong>Dynamic Stop Loss:</strong> \$" . number_format($slPrice, 4) . " USD ({$dynamicSlPercent}%)</li>"
                          . "<li><strong>Allocated Margin Limit:</strong> \$" . number_format($maxAlloc, 2) . " USD</li>"
                          . "<li><strong>Required Margin:</strong> \$" . number_format($requiredMargin, 4) . " USD</li>"
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
                log_message("Failed to place market order for user {$username} on {$chosenOption['symbol']}. Error: {$errorMsg}", "ERROR");

                if ($userEmail) {
                    $subject = "Option Scalping Placement Failed - {$chosenOption['symbol']}";
                    $html = "<p>Dear {$username},</p>"
                          . "<p>We failed to execute the Option Scalping order on Delta Exchange for contract <strong>{$chosenOption['symbol']}</strong>.</p>"
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

    log_message("Option scalping execution service complete.");
}

// Execution block
try {
    run_option_scalping_strategy();
} catch (Exception $e) {
    $errStr = "Fatal Error during strategy execution: " . $e->getMessage() . "\nStack trace:\n" . $e->getTraceAsString();
    log_message($errStr, "ERROR");
    email_error($errStr);
}
