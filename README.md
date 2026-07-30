# Delta Backtester Backend - Core PHP Version

This is the Core PHP version of the Delta Backtester Backend, migrated from the FastAPI python application. It provides CRUD REST APIs, JWT authentication, and automated options strangle selling strategy services.

## Tech Stack
- **Core PHP**: Pure PHP 7.4+ object-oriented code with clean separation of controllers, services, and middleware.
- **Apache Rewrite (.htaccess)**: For clean MVC-style route dispatching to a single `index.php` front-controller.
- **PDO (PHP Data Objects)**: Secure MySQL database drivers using prepared statements.
- **Built-in Hashing**: Native `password_hash` and `password_verify` (using bcrypt algorithm).
- **Socket SMTP**: Low-level network socket email dispatcher implementing STARTTLS and authentication.
- **Native JWT**: HMAC-SHA256 based JWT encoding and validation.

## Project Structure
```text
deltabacktester/
├── .htaccess                       # Apache URL rewrite router engine
├── .env                            # Environment configurations (ignored by git)
├── index.php                       # Application entrypoint & global request handler
├── options_selling_service.php     # CLI Strategy: Option selling execution
├── monitor_trade_service.php       # CLI Strategy: Open position management
├── README.md                       # Setup and documentation reference
└── src/                            # Source classes folder (Namespace App\)
    ├── Config/
    │   ├── Database.php            # PDO driver and auto-table initialization
    │   └── DotEnv.php              # Env config parser
    ├── Common/
    │   ├── ApiResponse.php         # Standardized JSON response handler
    │   └── EmailService.php        # Native Socket SMTP client
    ├── Middleware/
    │   └── AuthMiddleware.php      # Authenticate endpoints via cookie or bearer header
    ├── Helpers/
    │   ├── JwtHelper.php           # Native JWT encode/decode helper
    │   ├── DeltaClient.php         # Delta Exchange request signing client
    │   └── ValidationHelper.php    # Password, email, and input validation
    ├── Controllers/
    │   ├── AuthController.php      # Login, logout, forgot-password endpoints
    │   ├── UserController.php      # Users CRUD operations
    │   ├── AccountInfoController.php# Account Info CRUD operations
    │   ├── TradeConfigController.php# Trade settings CRUD operations
    │   └── OrdersInfoController.php # Orders database tracking CRUD operations
    └── Services/
        ├── UserService.php         # User entity DB logic
        ├── AuthService.php         # Verification codes and resets
        ├── AccountInfoService.php  # API keys storage DB logic
        ├── TradeConfigService.php  # Lots and leverage settings DB logic
        └── OrdersInfoService.php   # Placed trades DB logic
```

## Setup & Installation

### Prerequisites
- **XAMPP** (with PHP 7.4+ and Apache, MySQL modules enabled)
- The folder must be placed inside your `htdocs` directory at: `C:\xampp\htdocs\deltabacktester`

### Database Setup
No manual database or table setup is needed! 
1. Make sure MySQL is running in XAMPP on port `3306`.
2. When the PHP application boots (e.g., when the first API call hits `index.php`), the `Database` class automatically creates the `delta_backtester` database and initializes all tables (`users`, `account_info`, `trade_config`, `orders_info`, `password_resets`) if they do not exist.

### Configuration
1. Open `C:\xampp\htdocs\deltabacktester\.env`
2. Update the configurations (database port, username, password, or SMTP credentials) as needed.

### Running APIs Locally
Ensure Apache is started in XAMPP, and you can interact with the endpoints at:
`http://localhost/deltabacktester/api/...`

For example:
- Check API health: `GET http://localhost/deltabacktester/api/`
- Register user: `POST http://localhost/deltabacktester/api/users`
- Current user: `GET http://localhost/deltabacktester/api/auth/me`

---

## Background Services Execution

The background scripts can be run directly from the command line using PHP CLI:

### 1. Place Option Strangle Trades
Run the option selling strategy script to look up live Delta Exchange option contracts, verify user margins, and submit sell market orders:
```bash
php -f C:\xampp\htdocs\deltabacktester\options_selling_service.php
```

### 2. Monitor Open Positions
Monitor existing open orders to verify if they have completed the 12-hour holding period to book profit, or if the spot price breached safety bounds to book loss:
```bash
php -f C:\xampp\htdocs\deltabacktester\monitor_trade_service.php
```

These services can be scheduled on Windows Task Scheduler to run at desired intervals (e.g., options selling at 10:30 PM Indian Time).
