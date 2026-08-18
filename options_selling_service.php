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

define('BASE_URL', "https://api.india.delta.exchange"); // switched from api.delta.exchange — BTCUSD only resolves on the India domain
define('FALLBACK_RANGE', 1200);
define('SEARCH_LIMIT_PCT', 0.030); // 3.0%

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
        log_message("Public request to {$url} failed (attempt {$attempt}/{$retries}) - HTTP {$httpCode}: {$err}", "WARNING");
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
            $price = floatval($ticker['spot_price'] ?? $ticker['mark_price'] ?? $ticker['close'] ?? 0.0);

            if ($price <= 0.0) {
                log_message("BTCUSD ticker returned but price fields were empty/zero: " . json_encode($ticker), "ERROR");
                return null;
            }

            return $price;
        }

        // FIX: previously this branch logged nothing and just fell through to `return null`,
        // which is why the caller only ever saw "BTC spot price could not be retrieved"
        // with no explanation. Now we log Delta's actual response.
        log_message("BTCUSD ticker request returned success=false: " . json_encode($res), "ERROR");
    } catch (Exception $e) {
        log_message("Failed to fetch BTCUSD ticker spot price: " . $e->getMessage(), "ERROR");
    }
    return null;
}

function fetch_option_tickers(): array {
    $url = BASE_URL . "/v2/tickers?contract_types=call_options,put_options";
    try {
        $res = make_public_request_with_retry($url);
        if (isset($res['success']) && $res['success']) {
            return $res['result'] ?? [];
        }
        log_message("Option tickers request returned success=false: " . json_encode($res), "ERROR");
    } catch (Exception $e) {
        log_message("Failed to fetch option tickers from Delta Exchange: " . $e->getMessage(), "ERROR");
    }
    return [];
}

function fetch_active_products(): array {
    $url = BASE_URL . "/v2/products?contract_types=call_options,put_options&states=live&page_size=1000";
    try {
        $res = make_public_request_with_retry($url);
        if (isset($res['success']) && $res['success']) {
            return $res['result'] ?? [];
        }
        log_message("Active products request returned success=false: " . json_encode($res), "ERROR");
    } catch (Exception $e) {
        log_message("Failed to fetch active products from Delta Exchange: " . $e->getMessage(), "ERROR");
    }
    return [];
}

function get_closest_option(array $optionsList, float $targetStrike): ?array {
    if (empty($optionsList)) {
        return null;
    }
    $closestOpt = null;
    $minDiff = INF;
    foreach ($optionsList as $opt) {
        $strike = floatval($opt['strike_price'] ?? 0);
        $diff = abs($strike - $targetStrike);
        if ($diff < $minDiff) {
            $minDiff = $diff;
            $closestOpt = $opt;
        }
    }
    return $closestOpt;
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

function run_option_selling_strategy(): void {
    log_message("Starting option selling strategy service execution...");

    // 1. Fetch spot BTC price
    $btcPrice = fetch_btc_price();
    if (!$btcPrice) {
        log_message("Unable to execute strategy: BTC spot price could not be retrieved.", "ERROR");
        return;
    }

    log_message("Current BTC Spot Price: {$btcPrice}");

    // Calculate current IST date and tomorrow's expiry details
    $currentDate = new DateTime();
    $expiryDate = clone $currentDate;
    $expiryDate->modify('+1 day');

    $expiryDateStr = $expiryDate->format('Y-m-d');
    $deltaFormattedExpiry = $expiryDate->format('dmy'); // DDMMYY (e.g. 290726)

    log_message("Current date (IST): " . $currentDate->format('Y-m-d') . ", Strategy Expiry target: {$expiryDateStr} (suffix: {$deltaFormattedExpiry})");

    // 2. Fetch Open Interest options ticks to determine strike boundaries
    $optionTickers = fetch_option_tickers();

    $maxCallOi = 0.0;
    $maxCallStrike = $btcPrice + FALLBACK_RANGE;
    $maxPutOi = 0.0;
    $maxPutStrike = $btcPrice - FALLBACK_RANGE;

    $lowerLimitPrice = $btcPrice * (1.0 - SEARCH_LIMIT_PCT);
    $upperLimitPrice = $btcPrice * (1.0 + SEARCH_LIMIT_PCT);

    foreach ($optionTickers as $ticker) {
        $symbol = $ticker['symbol'] ?? '';
        if (strpos($symbol, 'BTC') !== false && strpos($symbol, $deltaFormattedExpiry) !== false) {
            $oi = floatval($ticker['oi'] ?? 0.0);
            $strike = floatval($ticker['strike_price'] ?? 0.0);

            if (strpos($symbol, 'C-') === 0 && $strike > $btcPrice && $strike <= $upperLimitPrice) {
                if ($oi > $maxCallOi) {
                    $maxCallOi = $oi;
                    $maxCallStrike = $strike;
                }
            } elseif (strpos($symbol, 'P-') === 0 && $strike >= $lowerLimitPrice && $strike < $btcPrice) {
                if ($oi > $maxPutOi) {
                    $maxPutOi = $oi;
                    $maxPutStrike = $strike;
                }
            }
        }
    }

    if ($maxCallOi > 0 && $maxPutOi > 0) {
        $lowerBound = $maxPutStrike;
        $upperBound = $maxCallStrike;
        log_message("Dynamic OI Bounds identified - Lower: {$lowerBound} (OI: {$maxPutOi}), Upper: {$upperBound} (OI: {$maxCallOi})");
    } else {
        $lowerBound = $btcPrice - FALLBACK_RANGE;
        $upperBound = $btcPrice + FALLBACK_RANGE;
        log_message("Open Interest details unavailable or zero. Falling back to default range. Lower: {$lowerBound}, Upper: {$upperBound}");
    }

    // 3. Fetch active options chain products
    $activeProducts = fetch_active_products();
    $btcExpiryOptions = [];
    foreach ($activeProducts as $p) {
        $symbol = $p['symbol'] ?? '';
        if (($p['contract_unit_currency'] ?? '') === 'BTC' && substr($symbol, -strlen($deltaFormattedExpiry)) === $deltaFormattedExpiry) {
            $btcExpiryOptions[] = $p;
        }
    }

    if (empty($btcExpiryOptions)) {
        log_message("No active option contracts found on Delta Exchange for expiry: {$deltaFormattedExpiry}", "ERROR");
        return;
    }

    $putOptionsList = [];
    $callOptionsList = [];
    foreach ($btcExpiryOptions as $p) {
        if (($p['contract_type'] ?? '') === 'put_options') {
            $putOptionsList[] = $p;
        } elseif (($p['contract_type'] ?? '') === 'call_options') {
            $callOptionsList[] = $p;
        }
    }

    $putContract = get_closest_option($putOptionsList, $lowerBound);
    $callContract = get_closest_option($callOptionsList, $upperBound);

    if (!$putContract || !$callContract) {
        log_message("Unable to identify closest Put or Call option contracts from option chain.", "ERROR");
        return;
    }

    log_message("Selected Put Option: {$putContract['symbol']} (strike: {$putContract['strike_price']}, ID: {$putContract['id']})");
    log_message("Selected Call Option: {$callContract['symbol']} (strike: {$callContract['strike_price']}, ID: {$callContract['id']})");

    // 4. Fetch the initial premium amount
    $putTicker = fetch_ticker_for_symbol($putContract['symbol']);
    $callTicker = fetch_ticker_for_symbol($callContract['symbol']);

    $defaultPutPrice = floatval($putTicker['mark_price'] ?? $putTicker['close'] ?? 0.0);
    $defaultCallPrice = floatval($callTicker['mark_price'] ?? $callTicker['close'] ?? 0.0);

    // 5. Connect DB and process integrations
    $db = Database::getInstance()->getConnection();

    // Fetch active accounts
    $stmt = $db->query("SELECT id, user_id, api_key, api_secret, active FROM account_info WHERE active = 1");
    $activeAccounts = $stmt->fetchAll();

    log_message("Identified " . count($activeAccounts) . " active accounts to process.");

    foreach ($activeAccounts as $account) {
        $userId = intval($account['user_id']);

        // Load User email
        try {
            $user = UserService::getById($userId);
            $userEmail = $user['email'];
        } catch (Exception $e) {
            log_message("Account ID {$account['id']} has no valid user. Skipping.", "WARNING");
            continue;
        }

        if (empty($account['api_key']) || empty($account['api_secret'])) {
            log_message("Account ID {$account['id']} has missing API credentials.", "WARNING");
            if ($userEmail) {
                $subject = "API Credentials Missing - Delta Backtester";
                $html = "<p>Dear {$user['username']},</p>"
                      . "<p>The daily options selling script could not run because your integration credentials "
                      . "(API Key or Secret) are missing in your account settings. Please update your configurations.</p>";
                try {
                    EmailService::send($userEmail, $subject, $html);
                } catch (Exception $mailEx) {}
            }
            continue;
        }

        // Fetch trade configuration from strategy subscription (strategy_id = 2)
        $configStmt = $db->prepare("SELECT lot_size as lot, leverage, peak_balance, allocated_balance, current_balance FROM subscribe_strategys WHERE user_id = :user_id AND strategy_id = 2");
        $configStmt->execute(['user_id' => $userId]);
        $tradeConfig = $configStmt->fetch();

        if (!$tradeConfig || empty($tradeConfig['lot']) || empty($tradeConfig['leverage'])) {
            log_message("User ID {$userId} has invalid or incomplete trade configurations (leverage/lot).", "WARNING");
            if ($userEmail) {
                $subject = "Strategy Trade Configuration Invalid - Delta Backtester";
                $html = "<p>Dear {$user['username']},</p>"
                      . "<p>The option selling strategy could not execute because your strategy "
                      . "Lot Size or Leverage parameters are not defined or set to 0. Please configure them inside settings.</p>";
                try {
                    EmailService::send($userEmail, $subject, $html);
                } catch (Exception $mailEx) {}
            }
            continue;
        }

        $lot = intval($tradeConfig['lot']);
        $leverage = intval($tradeConfig['leverage']);
        $peakBalance = $tradeConfig['peak_balance'] !== null ? floatval($tradeConfig['peak_balance']) : null;
        $allocatedBalance = $tradeConfig['allocated_balance'] !== null ? floatval($tradeConfig['allocated_balance']) : null;
        $currentBalance = $tradeConfig['current_balance'] !== null ? floatval($tradeConfig['current_balance']) : null;

        // Check available account balance using DeltaClient
        $deltaClient = new DeltaClient($account['api_key'], $account['api_secret']);
        list($status, $balancesResp) = $deltaClient->getBalances();

        if ($status !== 200 || !isset($balancesResp['success']) || !$balancesResp['success']) {
            log_message("Failed to fetch balances for account ID {$account['id']}. Status: {$status}, Response: " . json_encode($balancesResp), "ERROR");
            if ($userEmail) {
                $subject = "Delta Exchange Authentication Error";
                $html = "<p>Dear {$user['username']},</p>"
                      . "<p>We failed to fetch your balances from Delta Exchange due to authentication errors. "
                      . "Please verify that your API key and Secret are correct and active.</p>";
                try {
                    EmailService::send($userEmail, $subject, $html);
                } catch (Exception $mailEx) {}
            }
            continue;
        }

        $balances = $balancesResp['result'] ?? [];
        $exchangeUsdAvailable = 0.0;
        foreach ($balances as $bal) {
            if (intval($bal['asset_id'] ?? 0) === 14) { // Asset ID 14 is USD/USDT
                $exchangeUsdAvailable = floatval($bal['available_balance'] ?? 0.0);
                break;
            }
        }

        log_message("Account ID {$account['id']} (User: {$user['username']}) - Actual Exchange Available USD: {$exchangeUsdAvailable}");

        if ($exchangeUsdAvailable <= 0.0) {
            log_message("Account ID {$account['id']} has zero or negative exchange balance. Skipping.", "WARNING");
            if ($userEmail) {
                $subject = "Account Balance Insufficient - Option Selling Strategy";
                $html = "<p>Dear {$user['username']},</p>"
                      . "<p>The option selling strategy could not execute because your Delta Exchange available balance is \$" . number_format($exchangeUsdAvailable, 2) . " USDT.</p>"
                      . "<p>Please deposit more funds to your exchange account.</p>"
                      . "<p>Best regards,<br/>Delta Backtester Automation Service</p>";
                try {
                    EmailService::send($userEmail, $subject, $html);
                } catch (Exception $mailEx) {}
            }
            continue;
        }

        // Determine USD available for this strategy
        if ($allocatedBalance !== null && $allocatedBalance > 0.0) {
            // Initialize current balance if it hasn't been set
            if ($currentBalance === null) {
                $currentBalance = $allocatedBalance;
                $db->prepare("UPDATE subscribe_strategys SET current_balance = :current WHERE user_id = :uid AND strategy_id = 2")
                   ->execute(['current' => $currentBalance, 'uid' => $userId]);
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
                $subject = "Account Balance Insufficient - Option Selling Strategy";
                $html = "<p>Dear {$user['username']},</p>"
                      . "<p>The option selling strategy (strategy_id = 2) could not execute because your strategy balance is \$" . number_format($usdAvailable, 2) . " USDT.</p>"
                      . "<p>Please deposit more funds or allocate more margin to the strategy.</p>"
                      . "<p>Best regards,<br/>Delta Backtester Automation Service</p>";
                try {
                    EmailService::send($userEmail, $subject, $html);
                } catch (Exception $mailEx) {}
            }
            continue;
        }

        // Update peak balance in DB for strategy_id = 2
        if ($peakBalance === null || $peakBalance <= 0.0) {
            $peakBalance = $usdAvailable;
            $db->prepare("UPDATE subscribe_strategys SET peak_balance = :peak WHERE user_id = :uid AND strategy_id = 2")
               ->execute(['peak' => $peakBalance, 'uid' => $userId]);
            log_message("Initialized peak balance to {$peakBalance} for user {$userId}");
        } elseif ($usdAvailable > $peakBalance) {
            $peakBalance = $usdAvailable;
            $db->prepare("UPDATE subscribe_strategys SET peak_balance = :peak WHERE user_id = :uid AND strategy_id = 2")
               ->execute(['peak' => $peakBalance, 'uid' => $userId]);
            log_message("Updated peak balance to new high {$peakBalance} for user {$userId}");
        }

        // Required Margin Calculation: (BTC Price * BTC Quantity * 2) / Leverage
        // 1 lot = 0.001 BTC
        $btcQty = $lot / 1000.0;
        $notionalValueBothLegs = $btcPrice * $btcQty * 2.0;
        $requiredMargin = $notionalValueBothLegs / $leverage;

        if ($usdAvailable >= $requiredMargin) {
            log_message("Executing trade for user {$user['username']} with lot size: {$lot}, leverage: {$leverage}. Required margin: \${$requiredMargin}");

            $contractsToTrade = [
                ["contract" => $putContract, "default_price" => $defaultPutPrice, "type" => "Put"],
                ["contract" => $callContract, "default_price" => $defaultCallPrice, "type" => "Call"]
            ];

            $placedOrdersInfo = [];
            $portfolioModeMessages = [];

            foreach ($contractsToTrade as $item) {
                $contract = $item['contract'];
                $optionType = $item['type'];
                $defaultPrice = $item['default_price'];
                $symbol = $contract['symbol'];
                $prodId = $contract['id'];

                // A. Set leverage
                list($levStatus, $levResp) = $deltaClient->setLeverage($prodId, $leverage);
                if ($levStatus !== 200 && $levStatus !== 201) {
                    if (is_array($levResp) && ($levResp['error']['code'] ?? '') === 'unsupported') {
                        $msg = "Account is in Portfolio Margin mode; manual leverage adjustment is unsupported and managed automatically for {$symbol}.";
                        log_message($msg);
                        $portfolioModeMessages[] = $msg;
                    } else {
                        log_message("Failed to set leverage to {$leverage} for {$symbol}. Response: " . json_encode($levResp), "WARNING");
                    }
                }

                // B. Place sell market order
                list($orderStatus, $orderResp) = $deltaClient->placeOrder($prodId, $lot, "sell", "market_order");

                if (($orderStatus === 200 || $orderStatus === 201) && isset($orderResp['success']) && $orderResp['success']) {
                    $placedOrder = $orderResp['result'] ?? [];
                    $placedOrderId = $placedOrder['id'] ?? '';
                    log_message("Order successfully placed. Order ID: {$placedOrderId} for contract {$symbol}");

                    // Wait 1.5 seconds for transaction fills
                    usleep(1500000);

                    list($fillStatus, $fillResp) = $deltaClient->getFills();
                    $fills = ($fillStatus === 200 && isset($fillResp['success']) && $fillResp['success']) ? ($fillResp['result'] ?? []) : [];

                    $totalSize = 0.0;
                    $totalVal = 0.0;
                    $totalFee = 0.0;

                    foreach ($fills as $f) {
                        if (strval($f['order_id'] ?? '') === strval($placedOrderId)) {
                            $sz = floatval($f['size'] ?? 0.0);
                            $pr = floatval($f['price'] ?? 0.0);
                            $fe = floatval($f['commission'] ?? $f['fee'] ?? 0.0);

                            $totalSize += $sz;
                            $totalVal += ($pr * $sz);
                            $totalFee += $fe;
                        }
                    }

                    $avgFillPrice = $totalSize > 0 ? ($totalVal / $totalSize) : $defaultPrice;

                    // C. Record transaction details in the database
                    $insertStmt = $db->prepare("INSERT INTO orders_info (order_id, order_name, order_type, entry_amount, exit_amount, pnl, broker_fees, qty, status, account_info_id, user_id, strategy_id) 
                                                VALUES (:order_id, :order_name, :order_type, :entry_amount, :exit_amount, :pnl, :broker_fees, :qty, :status, :account_info_id, :user_id, :strategy_id)");
                    $insertStmt->execute([
                        'order_id' => strval($placedOrderId),
                        'order_name' => $symbol,
                        'order_type' => 'sell',
                        'entry_amount' => round($avgFillPrice, 4),
                        'exit_amount' => 0.0,
                        'pnl' => 0.0,
                        'broker_fees' => round($totalFee, 4),
                        'qty' => $lot,
                        'status' => 'open',
                        'account_info_id' => $account['id'],
                        'user_id' => $userId,
                        'strategy_id' => 2
                    ]);

                    log_message("Saved order {$placedOrderId} to database orders_info table.");

                    $placedOrdersInfo[] = [
                        "symbol" => $symbol,
                        "order_id" => $placedOrderId,
                        "avg_price" => $avgFillPrice,
                        "fee" => $totalFee,
                        "size" => $lot,
                        "type" => $optionType
                    ];
                } else {
                    $errorMsg = $orderResp['message'] ?? $orderResp['error']['message'] ?? "Unknown API error";
                    log_message("Failed to place sell order for user {$user['username']} on {$symbol}. Error: {$errorMsg}", "ERROR");

                    if ($userEmail) {
                        $subject = "Options Trade Placement Failed - {$symbol}";
                        $html = "<p>Dear {$user['username']},</p>"
                              . "<p>We failed to place the sell market order on Delta Exchange for contract <strong>{$symbol}</strong>.</p>"
                              . "<p><strong>Error details:</strong> {$errorMsg}</p>"
                              . "<p>Please review your account details or check market liquidity.</p>";
                        try {
                            EmailService::send($userEmail, $subject, $html);
                        } catch (Exception $mailEx) {}
                    }
                }
            }

            // Send execution email success notification
            if (!empty($placedOrdersInfo) && $userEmail) {
                $subject = "Options Strategy Execution Successful - Delta Backtester";
                $ordersHtml = "";
                foreach ($placedOrdersInfo as $o) {
                    $ordersHtml .= "<li>"
                                 . "<strong>{$o['type']} Option Contract:</strong> {$o['symbol']}<br/>"
                                 . "<strong>Order ID:</strong> {$o['order_id']}<br/>"
                                 . "<strong>Size:</strong> {$o['size']} lots<br/>"
                                 . "<strong>Avg Fill Price:</strong> \$" . number_format($o['avg_price'], 2) . " USD<br/>"
                                 . "</li><br/>";
                }

                $notesHtml = "";
                if (!empty($portfolioModeMessages)) {
                    $notesHtml = "<h3>System Notes:</h3><ul>";
                    foreach ($portfolioModeMessages as $m) {
                        $notesHtml .= "<li>{$m}</li>";
                    }
                    $notesHtml .= "</ul>";
                }

                $htmlBody = "<p>Dear {$user['username']},</p>"
                          . "<p>The option selling strategy has been executed successfully for your account.</p>"
                          . "<h3>Placed Order Details:</h3>"
                          . "<ul>{$ordersHtml}</ul>"
                          . "{$notesHtml}"
                          . "<p>Best regards,<br/>Delta Backtester Automation Service</p>";

                try {
                    EmailService::send($userEmail, $subject, $htmlBody);
                } catch (Exception $mailEx) {
                    log_message("Mail failed: " . $mailEx->getMessage(), "ERROR");
                }
            }
        } else {
            if ($userEmail) {
                $subject = "Account Balance Insufficient - Delta Backtester";
                $html = "<p>Dear {$user['username']},</p>"
                      . "<p>The option selling strategy could not execute because your account balance is insufficient to cover the required margin.</p>"
                      . "<p><strong>Available Balance:</strong> \$" . number_format($usdAvailable, 2) . " USD</p>"
                      . "<p><strong>Required Margin:</strong> \$" . number_format($requiredMargin, 2) . " USD (for Lot Size: {$lot} and Leverage: {$leverage}x)</p>"
                      . "<p>Please deposit more funds into your account.</p>";
                try {
                    EmailService::send($userEmail, $subject, $html);
                } catch (Exception $mailEx) {}
            }
            log_message("Skipping trade execution for user ID {$userId}: available balance is too low (\${$usdAvailable} < required margin \${$requiredMargin}).");
        }
    }

    log_message("Option selling strategy service execution complete.");
}

// Execution block
try {
    run_option_selling_strategy();
} catch (Exception $e) {
    log_message("Fatal Error during strategy run: " . $e->getMessage(), "ERROR");
}
// C:\xampp\php\php.exe -l options_selling_service.php