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
use App\Common\ApiResponse;
use App\Controllers\AuthController;
use App\Controllers\UserController;
use App\Controllers\AccountInfoController;
use App\Controllers\TradeConfigController;
use App\Controllers\OrdersInfoController;
use App\Controllers\StrategyController;
use App\Controllers\SubscribeStrategyController;

// 2. Load .env Configurations
DotEnv::load(__DIR__ . '/.env');

// 3. Global Exception Handler
set_exception_handler(function (Throwable $exception) {
    $code = $exception->getCode();
    // Default to 500 for non-HTTP exception codes
    if (!is_int($code) || $code < 100 || $code > 599) {
        $code = 500;
    }
    
    // Log exception details
    error_log("Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    
    ApiResponse::send($code, $exception->getMessage());
});

// 4. Configure CORS Headers
// $httpOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
// $allowedOrigins = ["http://localhost:3000", "http://127.0.0.1:3000","https://deltabacktester.netlify.app"];
// if (in_array($httpOrigin, $allowedOrigins)) {
//     header("Access-Control-Allow-Origin: $httpOrigin");
// } else {
//     // If request has no HTTP_ORIGIN (e.g. Postman), send default or none
//     header("Access-Control-Allow-Origin: https://deltabacktester.netlify.app");
// }
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Handle Pre-flight OPTIONS Requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// 5. Initialize Database (also checks and creates tables automatically)
Database::getInstance();

// 6. Request Parsing
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];

// Strip query parameters from URI
if (($pos = strpos($requestUri, '?')) !== false) {
    $requestUri = substr($requestUri, 0, $pos);
}

// Extract path relative to /api/ or fallback to root /
$apiPath = '/';
$apiPos = strpos($requestUri, '/api/');
if ($apiPos !== false) {
    $apiPath = '/' . substr($requestUri, $apiPos + 5);
} elseif ($requestUri === '/' || $requestUri === '/deltabacktester' || $requestUri === '/deltabacktester/') {
    $apiPath = '/';
}

// Read incoming JSON body
$inputData = [];
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $rawBody = file_get_contents('php://input');
    $inputData = json_decode($rawBody, true) ?? [];
} else {
    $inputData = $_POST;
}

// 7. Routing Table
// Root/healthcheck route
if ($apiPath === '/' && $requestMethod === 'GET') {
    ApiResponse::send(200, "Backend API is running", ["status" => "healthy"]);
}

// Auth Routes
if ($apiPath === '/auth/login' && $requestMethod === 'POST') {
    (new AuthController())->login($inputData);
}
if ($apiPath === '/auth/forgot-password' && $requestMethod === 'POST') {
    (new AuthController())->forgotPassword($inputData);
}
if ($apiPath === '/auth/verify-reset-code' && $requestMethod === 'POST') {
    (new AuthController())->verifyResetCode($inputData);
}
if ($apiPath === '/auth/reset-password' && $requestMethod === 'POST') {
    (new AuthController())->resetPassword($inputData);
}
if ($apiPath === '/auth/logout' && $requestMethod === 'POST') {
    (new AuthController())->logout();
}
if ($apiPath === '/auth/me' && $requestMethod === 'GET') {
    (new AuthController())->me();
}

// Users CRUD Routes
if ($apiPath === '/users' && $requestMethod === 'POST') {
    (new UserController())->create($inputData);
}
if ($apiPath === '/users' && $requestMethod === 'GET') {
    (new UserController())->list($_GET);
}
if (preg_match('/^\/users\/(\d+)$/', $apiPath, $matches)) {
    $id = (int)$matches[1];
    if ($requestMethod === 'GET') {
        (new UserController())->retrieve($id);
    }
    if ($requestMethod === 'PUT') {
        (new UserController())->update($id, $inputData);
    }
    if ($requestMethod === 'DELETE') {
        (new UserController())->delete($id);
    }
}

// Account Info CRUD Routes
if ($apiPath === '/account-info' && $requestMethod === 'POST') {
    (new AccountInfoController())->create($inputData);
}
if ($apiPath === '/account-info' && $requestMethod === 'GET') {
    (new AccountInfoController())->list($_GET);
}
if (preg_match('/^\/account-info\/(\d+)$/', $apiPath, $matches)) {
    $id = (int)$matches[1];
    if ($requestMethod === 'GET') {
        (new AccountInfoController())->retrieve($id);
    }
    if ($requestMethod === 'PUT') {
        (new AccountInfoController())->update($id, $inputData);
    }
    if ($requestMethod === 'DELETE') {
        (new AccountInfoController())->delete($id);
    }
}
if ($apiPath === '/account-info/sync-margin' && $requestMethod === 'POST') {
    (new AccountInfoController())->syncMargin($inputData);
}

// Trade Config CRUD Routes
if ($apiPath === '/trade-config' && $requestMethod === 'POST') {
    (new TradeConfigController())->create($inputData);
}
if ($apiPath === '/trade-config' && $requestMethod === 'GET') {
    (new TradeConfigController())->list($_GET);
}
if (preg_match('/^\/trade-config\/(\d+)$/', $apiPath, $matches)) {
    $id = (int)$matches[1];
    if ($requestMethod === 'GET') {
        (new TradeConfigController())->retrieve($id);
    }
    if ($requestMethod === 'PUT') {
        (new TradeConfigController())->update($id, $inputData);
    }
    if ($requestMethod === 'DELETE') {
        (new TradeConfigController())->delete($id);
    }
}

// Orders Info CRUD Routes
if ($apiPath === '/orders-info' && $requestMethod === 'POST') {
    (new OrdersInfoController())->create($inputData);
}
if ($apiPath === '/orders-info' && $requestMethod === 'GET') {
    (new OrdersInfoController())->list($_GET);
}
if (preg_match('/^\/orders-info\/(\d+)$/', $apiPath, $matches)) {
    $id = (int)$matches[1];
    if ($requestMethod === 'GET') {
        (new OrdersInfoController())->retrieve($id);
    }
    if ($requestMethod === 'PUT') {
        (new OrdersInfoController())->update($id, $inputData);
    }
    if ($requestMethod === 'DELETE') {
        (new OrdersInfoController())->delete($id);
    }
}

// Strategy CRUD Routes
if ($apiPath === '/strategys' && $requestMethod === 'POST') {
    (new StrategyController())->create($inputData);
}
if ($apiPath === '/strategys' && $requestMethod === 'GET') {
    (new StrategyController())->list($_GET);
}
if (preg_match('/^\/strategys\/(\d+)$/', $apiPath, $matches)) {
    $id = (int)$matches[1];
    if ($requestMethod === 'GET') {
        (new StrategyController())->retrieve($id);
    }
    if ($requestMethod === 'PUT') {
        (new StrategyController())->update($id, $inputData);
    }
    if ($requestMethod === 'DELETE') {
        (new StrategyController())->delete($id);
    }
}
if (preg_match('/^\/strategys\/(\d+)\/performance$/', $apiPath, $matches)) {
    $id = (int)$matches[1];
    if ($requestMethod === 'GET') {
        (new OrdersInfoController())->performance($id);
    }
}

// Subscribe Strategy CRUD Routes
if ($apiPath === '/subscribe-strategys' && $requestMethod === 'POST') {
    (new SubscribeStrategyController())->create($inputData);
}
if ($apiPath === '/subscribe-strategys' && $requestMethod === 'GET') {
    (new SubscribeStrategyController())->list($_GET);
}
if (preg_match('/^\/subscribe-strategys\/(\d+)$/', $apiPath, $matches)) {
    $id = (int)$matches[1];
    if ($requestMethod === 'GET') {
        (new SubscribeStrategyController())->retrieve($id);
    }
    if ($requestMethod === 'PUT') {
        (new SubscribeStrategyController())->update($id, $inputData);
    }
    if ($requestMethod === 'DELETE') {
        (new SubscribeStrategyController())->delete($id);
    }
}

// 404 Route Not Found
ApiResponse::send(404, "Route not found: {$requestMethod} {$apiPath}");
// C:\xampp\php\php.exe -f C:\xampp\htdocs\deltabacktester\monitor_trade_service.php
// C:\xampp\php\php.exe -f C:\xampp\htdocs\deltabacktester\options_selling_service.php

// cd domains/
// cd bhargavdetroja.com/
// cd public_html/
// cd delta/