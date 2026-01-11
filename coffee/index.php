<?php

// coffee_friend_bot.php
// Optimized with Telegram Stars payment integration ONLY

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/bot_errors.log');

date_default_timezone_set('Africa/Addis_Ababa');

// Load configuration from secure file
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    error_log("Configuration file not found!");
    die("Service temporarily unavailable");
}
$config = require $configPath;

// Define constants from config
define('DB_HOST', $config['DB_HOST']);
define('DB_PORT', $config['DB_PORT']);
define('DB_USER', $config['DB_USER']);
define('DB_PASS', $config['DB_PASS']);
define('DB_NAME', $config['DB_NAME']);
define('BOT_TOKEN', $config['BOT_TOKEN']);
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('ENCRYPTION_KEY', $config['ENCRYPTION_KEY']);
define('ADMIN_USERNAME', $config['ADMIN_USERNAME']);

// Bot Configuration
define('MATCH_RADIUS_KM', 48);
define('INITIAL_FREE_CUPS', 3);
define('ENCRYPTION_METHOD', 'aes-256-cbc');
define('MIN_AGE', 18);
define('MAX_AGE', 100);
define('REPORT_BAN_THRESHOLD', 3);

// ⭐ TELEGRAM STARS PRICING - SINGLE PACKAGE
define('PLAN_STANDARD', ['searches' => 100, 'stars' => 100, 'price_usd' => 1, 'label' => '100 Searches Pack']);

class CoffeeFriendBot {
    private PDO|null $pdo = null;
    private array|null $update = null;
    private ?int $chatId = null;
    private ?int $userId = null;
    private string $messageText = '';
    private ?string $callbackData = null;
    private ?string $username = null;
    private ?int $messageId = null;

    public function __construct() {
        $this->connectDatabase();
        $this->createTables();
        $this->getUpdate();
    }

    private function connectDatabase(): void {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => true,
            ]);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            $this->pdo = null;
        }
    }

    private function createTables(): void {
        if (!$this->pdo) return;

        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    user_id BIGINT PRIMARY KEY,
                    telegram_username VARCHAR(255),
                    first_name VARCHAR(255),
                    last_name VARCHAR(255),
                    age INT NOT NULL,
                    gender ENUM('male', 'female'),
                    latitude DECIMAL(10, 8),
                    longitude DECIMAL(11, 8),
                    coffee_cups INT DEFAULT 0,
                    has_power_pack BOOLEAN DEFAULT 0,
                    power_pack_expires_at TIMESTAMP NULL,
                    registration_step VARCHAR(50) DEFAULT 'new',
                    terms_accepted BOOLEAN DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_gender_location (gender, latitude, longitude),
                    INDEX idx_cups (coffee_cups),
                    INDEX idx_age (age),
                    INDEX idx_power_pack (has_power_pack, power_pack_expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS cup_transactions (
                    transaction_id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT,
                    amount INT,
                    transaction_type ENUM('initial_free', 'search_used', 'stars_purchase'),
                    description VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user_date (user_id, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS stars_payments (
                    payment_id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT NOT NULL,
                    telegram_payment_charge_id VARCHAR(255) UNIQUE,
                    provider_payment_charge_id VARCHAR(255),
                    invoice_payload VARCHAR(255) NOT NULL,
                    currency VARCHAR(10) DEFAULT 'XTR',
                    total_amount INT NOT NULL,
                    searches_purchased INT NOT NULL,
                    plan_type VARCHAR(50) NOT NULL,
                    payment_status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'completed',
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_user (user_id),
                    INDEX idx_charge_id (telegram_payment_charge_id),
                    INDEX idx_payload (invoice_payload)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS temp_registration (
                    user_id BIGINT PRIMARY KEY,
                    first_name VARCHAR(255),
                    last_name VARCHAR(255),
                    age INT,
                    gender ENUM('male', 'female'),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS match_queue (
                    queue_id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT UNIQUE,
                    gender ENUM('male', 'female'),
                    age INT NOT NULL,
                    latitude DECIMAL(10, 8),
                    longitude DECIMAL(11, 8),
                    telegram_username VARCHAR(255),
                    first_name VARCHAR(255),
                    last_name VARCHAR(255),
                    has_priority BOOLEAN DEFAULT 0,
                    searching_since TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_priority (has_priority, searching_since),
                    INDEX idx_gender_location (gender, latitude, longitude),
                    INDEX idx_user (user_id),
                    INDEX idx_age (age)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS chat_sessions (
                    session_id INT AUTO_INCREMENT PRIMARY KEY,
                    user1_id BIGINT,
                    user2_id BIGINT,
                    user1_gender ENUM('male', 'female'),
                    user2_gender ENUM('male', 'female'),
                    is_active BOOLEAN DEFAULT 1,
                    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    ended_at TIMESTAMP NULL,
                    INDEX idx_active_users (is_active, user1_id, user2_id),
                    INDEX idx_match_history (user1_id, user2_id),
                    UNIQUE KEY unique_active_match (user1_id, user2_id, is_active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS username_shares (
                    share_id INT AUTO_INCREMENT PRIMARY KEY,
                    session_id INT,
                    shared_by BIGINT,
                    shared_to BIGINT,
                    shared_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_session (session_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS reports (
                    report_id INT AUTO_INCREMENT PRIMARY KEY,
                    reporter_id BIGINT,
                    reported_user_id BIGINT,
                    session_id INT,
                    report_type ENUM('underage', 'false_identity', 'sexual_content', 'harassment', 'safety_concern', 'hate_speech'),
                    details TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    status ENUM('pending', 'reviewed', 'action_taken') DEFAULT 'pending',
                    INDEX idx_reported (reported_user_id),
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS match_history (
                    history_id INT AUTO_INCREMENT PRIMARY KEY,
                    user1_id BIGINT,
                    user2_id BIGINT,
                    matched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY unique_pair (user1_id, user2_id),
                    INDEX idx_user1 (user1_id),
                    INDEX idx_user2 (user2_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS admin_states (
                    admin_id BIGINT PRIMARY KEY,
                    state VARCHAR(100),
                    data TEXT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS admin_actions (
                    action_id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_id BIGINT NOT NULL,
                    action_type VARCHAR(50) NOT NULL,
                    target_user_id BIGINT,
                    details TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_admin (admin_id),
                    INDEX idx_date (created_at),
                    INDEX idx_target (target_user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS banned_users (
                    user_id BIGINT PRIMARY KEY,
                    banned_by BIGINT NOT NULL,
                    reason TEXT,
                    banned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_banned_by (banned_by)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS user_states (
                    user_id BIGINT PRIMARY KEY,
                    current_state VARCHAR(100),
                    state_data TEXT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

        } catch (Throwable $e) {
            error_log("Error creating tables: " . $e->getMessage());
        }
    }

    private function getUserState(): ?array {
        if (!$this->pdo || !$this->userId) return null;
        
        try {
            $stmt = $this->pdo->prepare("SELECT current_state, state_data FROM user_states WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            $result = $stmt->fetch();
            
            if ($result) {
                return [
                    'state' => $result['current_state'],
                    'data' => $result['state_data'] ? json_decode($result['state_data'], true) : []
                ];
            }
            return null;
        } catch (Throwable $e) {
            error_log("Error getting user state: " . $e->getMessage());
            return null;
        }
    }

    private function setUserState(string $state, array $data = []): void {
        if (!$this->pdo || !$this->userId) return;
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO user_states (user_id, current_state, state_data, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE current_state = ?, state_data = ?, updated_at = NOW()
            ");
            $jsonData = json_encode($data);
            $stmt->execute([$this->userId, $state, $jsonData, $state, $jsonData]);
        } catch (Throwable $e) {
            error_log("Error setting user state: " . $e->getMessage());
        }
    }

    private function clearUserState(): void {
        if (!$this->pdo || !$this->userId) return;
        
        try {
            $stmt = $this->pdo->prepare("DELETE FROM user_states WHERE user_id = ?");
            $stmt->execute([$this->userId]);
        } catch (Throwable $e) {
            error_log("Error clearing user state: " . $e->getMessage());
        }
    }

    private function isUserBanned(int $userId): bool {
        if (!$this->pdo) return false;
        
        try {
            $stmt = $this->pdo->prepare("SELECT user_id FROM banned_users WHERE user_id = ?");
            $stmt->execute([$userId]);
            return $stmt->fetch() !== false;
        } catch (Throwable $e) {
            error_log("Error checking ban status: " . $e->getMessage());
            return false;
        }
    }

    private function sendBannedMessage(): void {
        $message = "🚫 Account suspended.\n\nContact " . ADMIN_USERNAME;
        $this->sendMessage($message);
    }

    private function validateAge(int $age): bool {
        return $age >= MIN_AGE && $age <= MAX_AGE;
    }

    private function calculateAgeRange(int $userAge): array {
        $minAge = (int)floor(($userAge / 2) + 7);
        $maxAge = (int)floor(($userAge - 7) * 2);
        
        $minAge = max($minAge, MIN_AGE);
        $maxAge = min($maxAge, MAX_AGE);
        
        if ($minAge > $maxAge) {
            $minAge = MIN_AGE;
            $maxAge = $userAge + 5;
        }
        
        return ['min' => $minAge, 'max' => $maxAge];
    }

    private function encryptMessage(string $plaintext): string {
        try {
            $ivLength = openssl_cipher_iv_length(ENCRYPTION_METHOD);
            $iv = openssl_random_pseudo_bytes($ivLength);
            
            $encrypted = openssl_encrypt(
                $plaintext,
                ENCRYPTION_METHOD,
                ENCRYPTION_KEY,
                0,
                $iv
            );
            
            return base64_encode($iv . $encrypted);
        } catch (Throwable $e) {
            error_log("Encryption error: " . $e->getMessage());
            return '';
        }
    }

    private function decryptMessage(string $ciphertext): string {
        try {
            $data = base64_decode($ciphertext);
            $ivLength = openssl_cipher_iv_length(ENCRYPTION_METHOD);
            
            $iv = substr($data, 0, $ivLength);
            $encrypted = substr($data, $ivLength);
            
            $decrypted = openssl_decrypt(
                $encrypted,
                ENCRYPTION_METHOD,
                ENCRYPTION_KEY,
                0,
                $iv
            );
            
            return $decrypted !== false ? $decrypted : '';
        } catch (Throwable $e) {
            error_log("Decryption error: " . $e->getMessage());
            return '';
        }
    }

    private function sanitizeText(string $text): string {
        $text = strip_tags($text);
        $text = trim($text);
        return $text;
    }

    private function validateUsername(?string $username): bool {
        if (empty($username)) return false;
        return preg_match('/^[a-zA-Z0-9_]{5,32}$/', $username) === 1;
    }

    private function validateName(string $name): bool {
        $sanitized = $this->sanitizeText($name);
        
        if (!preg_match('/^[a-zA-Z\s]+$/', $sanitized)) {
            return false;
        }
        
        return mb_strlen($sanitized) >= 2 && mb_strlen($sanitized) <= 50;
    }

    private function getUpdate(): void {
        $content = file_get_contents("php://input");
        if (empty($content)) return;

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            return;
        }

        $this->update = $decoded;

        if (isset($decoded['message'])) {
            $msg = $decoded['message'];
            $this->chatId = $msg['chat']['id'] ?? null;
            $this->userId = $msg['from']['id'] ?? null;
            $this->messageText = $msg['text'] ?? '';
            $this->username = $msg['from']['username'] ?? null;
            $this->messageId = $msg['message_id'] ?? null;
        } elseif (isset($decoded['callback_query'])) {
            $cb = $decoded['callback_query'];
            $this->callbackData = $cb['data'] ?? null;
            $this->chatId = $cb['message']['chat']['id'] ?? null;
            $this->userId = $cb['from']['id'] ?? null;
            $this->username = $cb['from']['username'] ?? null;
            $this->messageId = $cb['message']['message_id'] ?? null;
        } elseif (isset($decoded['edited_message'])) {
            $msg = $decoded['edited_message'];
            $this->chatId = $msg['chat']['id'] ?? null;
            $this->userId = $msg['from']['id'] ?? null;
            $this->messageText = $msg['text'] ?? '';
            $this->username = $msg['from']['username'] ?? null;
            $this->messageId = $msg['message_id'] ?? null;
        }
    }

    public function processUpdate(): void {
        if (!$this->update || !$this->userId) {
            http_response_code(200);
            return;
        }

        try {
            // ⭐ HANDLE PRE-CHECKOUT QUERY
            if (isset($this->update['pre_checkout_query'])) {
                $this->handlePreCheckoutQuery();
                http_response_code(200);
                return;
            }

            // ⭐ HANDLE SUCCESSFUL PAYMENT
            if (isset($this->update['message']['successful_payment'])) {
                $this->handleSuccessfulPayment();
                http_response_code(200);
                return;
            }

            if ($this->isUserBanned($this->userId)) {
                $this->sendBannedMessage();
                http_response_code(200);
                return;
            }

            $this->autoUpdateUsername();
            
            if (isset($this->update['message']) || isset($this->update['edited_message'])) {
                $this->handleMessage();
            } elseif (isset($this->update['callback_query'])) {
                $this->handleCallback();
            }
        } catch (Throwable $e) {
            error_log("Error: " . $e->getMessage());
            if ($this->chatId) {
                $this->safeSendMessage($this->chatId, "Something went wrong. Try /start");
            }
        }

        http_response_code(200);
    }

    // ⭐⭐⭐ TELEGRAM STARS PAYMENT HANDLERS START ⭐⭐⭐

    private function handlePreCheckoutQuery(): void {
        $preCheckoutQuery = $this->update['pre_checkout_query'];
        $queryId = $preCheckoutQuery['id'];
        $invoicePayload = $preCheckoutQuery['invoice_payload'] ?? '';
        $currency = $preCheckoutQuery['currency'] ?? '';
        $totalAmount = $preCheckoutQuery['total_amount'] ?? 0;

        error_log("Pre-checkout query received: " . json_encode($preCheckoutQuery));

        if ($currency !== 'XTR') {
            $this->answerPreCheckoutQuery($queryId, false, "Invalid currency. Please use Telegram Stars.");
            return;
        }

        // Validate payload format: plan_standard_USERID_TIMESTAMP
        if (!preg_match('/^plan_standard_(\d+)_(\d+)$/', $invoicePayload, $matches)) {
            $this->answerPreCheckoutQuery($queryId, false, "Invalid payment information.");
            return;
        }

        $userId = (int)$matches[1];
        $timestamp = (int)$matches[2];

        if ($userId !== $preCheckoutQuery['from']['id']) {
            $this->answerPreCheckoutQuery($queryId, false, "Payment verification failed.");
            return;
        }

        if (time() - $timestamp > 3600) {
            $this->answerPreCheckoutQuery($queryId, false, "Payment request expired. Please try again.");
            return;
        }

        $expectedStars = PLAN_STANDARD['stars'];
        if ($totalAmount !== $expectedStars) {
            $this->answerPreCheckoutQuery($queryId, false, "Payment amount mismatch.");
            return;
        }

        $this->answerPreCheckoutQuery($queryId, true);
    }

    private function answerPreCheckoutQuery(string $queryId, bool $ok, string $errorMessage = ''): void {
        $params = [
            'pre_checkout_query_id' => $queryId,
            'ok' => $ok
        ];

        if (!$ok && $errorMessage) {
            $params['error_message'] = $errorMessage;
        }

        $this->makeApiRequest('answerPreCheckoutQuery', $params);
    }

    private function handleSuccessfulPayment(): void {
        $payment = $this->update['message']['successful_payment'];
        
        $currency = $payment['currency'] ?? '';
        $totalAmount = $payment['total_amount'] ?? 0;
        $invoicePayload = $payment['invoice_payload'] ?? '';
        $telegramPaymentChargeId = $payment['telegram_payment_charge_id'] ?? '';
        $providerPaymentChargeId = $payment['provider_payment_charge_id'] ?? '';

        error_log("Successful payment received: " . json_encode($payment));

        if ($currency !== 'XTR') {
            error_log("Invalid currency in successful payment: $currency");
            $this->sendMessage("❌ Payment error: Invalid currency");
            return;
        }

        if (!preg_match('/^plan_standard_(\d+)_(\d+)$/', $invoicePayload, $matches)) {
            error_log("Invalid payload in successful payment: $invoicePayload");
            $this->sendMessage("❌ Payment error: Invalid payment data");
            return;
        }

        $userId = (int)$matches[1];

        if ($userId !== $this->userId) {
            error_log("User ID mismatch in payment: expected $userId, got {$this->userId}");
            $this->sendMessage("❌ Payment error: User verification failed");
            return;
        }

        if ($this->isDuplicatePayment($telegramPaymentChargeId)) {
            error_log("Duplicate payment attempt: $telegramPaymentChargeId");
            $this->sendMessage("⚠️ This payment was already processed.");
            return;
        }

        try {
            $this->pdo->beginTransaction();

            $searchesToAdd = PLAN_STANDARD['searches'];

            // Store payment record
            $stmt = $this->pdo->prepare("
                INSERT INTO stars_payments (
                    user_id, telegram_payment_charge_id, provider_payment_charge_id,
                    invoice_payload, currency, total_amount, searches_purchased, plan_type, payment_status
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'standard', 'completed')
            ");
            $stmt->execute([
                $userId,
                $telegramPaymentChargeId,
                $providerPaymentChargeId,
                $invoicePayload,
                $currency,
                $totalAmount,
                $searchesToAdd
            ]);

            // Add searches (coffee cups) to user account
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET coffee_cups = coffee_cups + ? 
                WHERE user_id = ?
            ");
            $stmt->execute([$searchesToAdd, $userId]);

            // Log transaction
            $this->logCupTransaction(
                $userId, 
                $searchesToAdd, 
                'stars_purchase', 
                "Purchased 100 Searches with {$totalAmount} Stars"
            );

            $this->pdo->commit();

            // Get new balance
            $newBalance = $this->getUserCoffeeCups();

            // Send success message
            $message = "✅ *Payment Successful!*\n\n";
            $message .= "⭐ Paid: {$totalAmount} Stars\n";
            $message .= "🔍 Added: {$searchesToAdd} searches\n";
            $message .= "💰 Balance: *{$newBalance} searches*\n\n";
            $message .= "Ready to find matches! 🔍";

            $this->sendMessage($message);
            $this->showMainMenu();

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Error processing successful payment: " . $e->getMessage());
            $this->sendMessage("❌ Error processing payment. Contact support: " . ADMIN_USERNAME);
        }
    }

    private function isDuplicatePayment(string $chargeId): bool {
        if (!$this->pdo || empty($chargeId)) return false;

        try {
            $stmt = $this->pdo->prepare("
                SELECT payment_id FROM stars_payments 
                WHERE telegram_payment_charge_id = ?
            ");
            $stmt->execute([$chargeId]);
            return $stmt->fetch() !== false;
        } catch (Throwable $e) {
            error_log("Error checking duplicate payment: " . $e->getMessage());
            return false;
        }
    }

    private function sendStarsInvoice(): void {
        $plan = PLAN_STANDARD;
        $starsAmount = $plan['stars'];
        $searches = $plan['searches'];
        $label = $plan['label'];
        
        $description = "Get {$searches} searches to find matches and meet new people!";

        $payload = "plan_standard_{$this->userId}_" . time();

        $params = [
            'chat_id' => $this->chatId,
            'title' => "🔍 {$label}",
            'description' => $description,
            'payload' => $payload,
            'currency' => 'XTR',
            'provider_token' => '',
            'prices' => json_encode([
                ['label' => $label, 'amount' => $starsAmount]
            ])
        ];

        $result = $this->makeApiRequest('sendInvoice', $params);
        
        if (!$result) {
            error_log("Failed to send invoice for standard plan");
            $this->sendMessage("❌ Error creating payment. Please try again.");
        }
    }

    // ⭐⭐⭐ TELEGRAM STARS PAYMENT HANDLERS END ⭐⭐⭐

    private function autoUpdateUsername(): void {
        if (!$this->pdo || !$this->userId || !$this->username) return;

        try {
            $stmt = $this->pdo->prepare("SELECT telegram_username FROM users WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            $user = $stmt->fetch();

            if ($user && $user['telegram_username'] !== $this->username) {
                $stmt = $this->pdo->prepare("UPDATE users SET telegram_username = ? WHERE user_id = ?");
                $stmt->execute([$this->username, $this->userId]);
            }
        } catch (Throwable $e) {
            error_log("Error auto-updating username: " . $e->getMessage());
        }
    }

    private function getUserCoffeeCups(): int {
        if (!$this->pdo) return 0;

        try {
            $stmt = $this->pdo->prepare("SELECT coffee_cups FROM users WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            $user = $stmt->fetch();
            return (int)($user['coffee_cups'] ?? 0);
        } catch (Throwable $e) {
            return 0;
        }
    }

    private function getUserGender(): ?string {
        if (!$this->pdo) return null;

        try {
            $stmt = $this->pdo->prepare("SELECT gender FROM users WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            $user = $stmt->fetch();
            return $user['gender'] ?? null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function deductCoffeeCups(int $amount): bool {
        if (!$this->pdo) return false;

        try {
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET coffee_cups = coffee_cups - ? 
                WHERE user_id = ? AND coffee_cups >= ?
            ");
            $stmt->execute([$amount, $this->userId, $amount]);

            if ($stmt->rowCount() > 0) {
                $this->logCupTransaction($this->userId, -$amount, 'search_used', 'Used for match search');
                return true;
            }
            return false;
        } catch (Throwable $e) {
            error_log("Error deducting coffee cups: " . $e->getMessage());
            return false;
        }
    }

    private function logCupTransaction(int $userId, int $amount, string $type, string $description): void {
        if (!$this->pdo) return;

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO cup_transactions (user_id, amount, transaction_type, description)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$userId, $amount, $type, $description]);
        } catch (Throwable $e) {
            error_log("Error logging transaction: " . $e->getMessage());
        }
    }

    private function showCoffeeCupsInfo(): void {
        $cups = $this->getUserCoffeeCups();

        $plan = PLAN_STANDARD;
        
        $message = "☕ *Your Balance*\n\n";
        $message .= "🔍 {$cups} searches\n\n";
        $message .= "💰 *Get More Searches*\n\n";
        $message .= "⭐ {$plan['searches']} searches = {$plan['stars']} Stars (\${$plan['price_usd']})\n\n";
        $message .= "_1 search = 1 match attempt_";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '⭐ Buy 100 Searches', 'callback_data' => 'buy_stars']],
                [['text' => '◀️ Back', 'callback_data' => 'back_to_menu']]
            ]
        ];

        $this->sendMessage($message, $keyboard);
    }

    private function showReportMenu(): void {
        $message = "🚨 *Report Issue*\n\nWhat's wrong?";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🔞 Underage', 'callback_data' => 'report_underage']],
                [['text' => '🎭 Fake Profile', 'callback_data' => 'report_false_identity']],
                [['text' => '⚠️ Harassment', 'callback_data' => 'report_harassment']],
                [['text' => '🚨 Safety Concern', 'callback_data' => 'report_safety_concern']],
                [['text' => '❌ Cancel', 'callback_data' => 'cancel_report']]
            ]
        ];

        $this->sendMessage($message, $keyboard);
    }

    private function handleReport(string $reportType): void {
        if (!$this->pdo) return;

        try {
            $stmt = $this->pdo->prepare("
                SELECT session_id, user1_id, user2_id 
                FROM chat_sessions 
                WHERE (user1_id = ? OR user2_id = ?) AND is_active = 1
            ");
            $stmt->execute([$this->userId, $this->userId]);
            $session = $stmt->fetch();

            if (!$session) {
                $this->sendMessage("❌ No active chat to report.");
                return;
            }

            $reportedUserId = ($session['user1_id'] == $this->userId) ? $session['user2_id'] : $session['user1_id'];

            $stmt = $this->pdo->prepare("
                INSERT INTO reports (reporter_id, reported_user_id, session_id, report_type, status)
                VALUES (?, ?, ?, ?, 'pending')
            ");
            $stmt->execute([$this->userId, $reportedUserId, $session['session_id'], $reportType]);

            $this->checkReportThreshold($reportedUserId);

            $this->editMessageReplyMarkup();

            $message = "✅ Report submitted. Thanks for keeping our community safe.";
            $this->sendMessage($message);
            $this->endChatSession(true);

        } catch (Throwable $e) {
            error_log("Error handling report: " . $e->getMessage());
            $this->sendMessage("❌ Error submitting report. Try again.");
        }
    }

    private function checkReportThreshold(int $userId): void {
        if (!$this->pdo) return;

        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as report_count 
                FROM reports 
                WHERE reported_user_id = ? AND status = 'pending'
            ");
            $stmt->execute([$userId]);
            $result = $stmt->fetch();

            if ($result && $result['report_count'] >= REPORT_BAN_THRESHOLD) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO banned_users (user_id, banned_by, reason)
                    VALUES (?, 0, 'Automatic ban: Multiple reports threshold reached')
                    ON DUPLICATE KEY UPDATE reason = 'Automatic ban: Multiple reports threshold reached'
                ");
                $stmt->execute([$userId]);
            }
        } catch (Throwable $e) {
            error_log("Error checking report threshold: " . $e->getMessage());
        }
    }

    private function handleMessage(): void {
        $text = trim((string)$this->messageText);

        if (isset($this->update['message']['location'])) {
            $this->saveLocation();
            return;
        }

        if ($text === '/start') {
            $this->clearUserState();
            $this->handleStart();
            return;
        }

        $userState = $this->getUserState();
        if ($userState && isset($userState['state'])) {
            $this->handleEditState($userState, $text);
            return;
        }

        if ($this->isInActiveChat()) {
            $this->routeChatMessage();
            return;
        }

        if ($text === '🔍 Find Match') {
            $this->startSearching();
            return;
        }
        if ($text === '☕ My Balance') {
            $this->showCoffeeCupsInfo();
            return;
        }
        if ($text === '✏️ Edit Profile') {
            $this->showUpdateProfileMenu();
            return;
        }
        if ($text === '🚫 Cancel Search') {
            $this->stopSearching();
            return;
        }

        $this->handleRegistrationStep();
    }

    private function handleEditState(array $userState, string $text): void {
        $state = $userState['state'];
        
        switch ($state) {
            case 'editing_first_name':
                $this->processFirstNameUpdate($text);
                break;
            case 'editing_last_name':
                $this->processLastNameUpdate($text);
                break;
            case 'editing_age':
                $this->processAgeUpdate($text);
                break;
            default:
                $this->clearUserState();
                $this->sendMessage("Something went wrong. Try again.");
                $this->showMainMenu();
                break;
        }
    }

    private function handleCallback(): void {
        $this->answerCallbackQuery();

        // ⭐ HANDLE STARS PAYMENT CALLBACK
        if ($this->callbackData === 'buy_stars') {
            $this->editMessageReplyMarkup();
            $this->sendStarsInvoice();
            return;
        }

        if (strpos($this->callbackData, 'report_') === 0) {
            $reportType = str_replace('report_', '', $this->callbackData);
            if ($reportType !== 'menu') {
                $this->handleReport($reportType);
                return;
            }
        }

        switch ($this->callbackData) {
            case 'accept_terms':
                $this->acceptTerms();
                break;
            case 'i_set_username':
                $this->recheckUsername();
                break;
            case 'gender_male':
                $this->setGender('male');
                break;
            case 'gender_female':
                $this->setGender('female');
                break;
            case 'share_username':
                $this->shareUsername();
                break;
            case 'leave_chat':
                $this->endChatSession();
                break;
            case 'report_user':
                $this->showReportMenu();
                break;
            case 'cancel_report':
                $this->editMessageReplyMarkup();
                $this->sendMessage("Report cancelled.");
                break;
            case 'update_first_name':
                $this->startUpdateFirstName();
                break;
            case 'update_last_name':
                $this->startUpdateLastName();
                break;
            case 'update_age':
                $this->startUpdateAge();
                break;
            case 'update_location':
                $this->startUpdateLocation();
                break;
            case 'view_cups':
                $this->showCoffeeCupsInfo();
                break;
            case 'back_to_menu':
                $this->editMessageReplyMarkup();
                $this->clearUserState();
                $this->showMainMenu();
                break;
        }
    }

    private function handleStart(): void {
        if (!$this->pdo) {
            $this->sendMessage("Service unavailable. Try again later!");
            return;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            $user = $stmt->fetch();
        } catch (Throwable $e) {
            error_log("Error in handleStart: " . $e->getMessage());
            $user = false;
        }

        if ($user && $user['registration_step'] === 'completed') {
            $this->showMainMenu();
            return;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM temp_registration WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            $tempReg = $stmt->fetch();

            if ($tempReg) {
                $this->continueRegistration($tempReg);
                return;
            }
        } catch (Throwable $e) {
            error_log("Error checking temp registration: " . $e->getMessage());
        }

        $this->startRegistration();
    }

    private function continueRegistration(array $tempReg): void {
        if (empty($tempReg['first_name'])) {
            $this->sendMessage("What's your first name?");
        } elseif (empty($tempReg['last_name'])) {
            $this->sendMessage("What's your last name?");
        } elseif (empty($tempReg['age'])) {
            $this->sendMessage("How old are you?");
        } elseif (empty($tempReg['gender'])) {
            $this->askGender();
        } else {
            $this->askLocation();
        }
    }

    private function startRegistration(): void {
        if (!$this->username) {
            $this->requestUsername();
            return;
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO temp_registration (user_id, created_at)
                VALUES (?, NOW())
                ON DUPLICATE KEY UPDATE created_at = NOW()
            ");
            $stmt->execute([$this->userId]);
        } catch (Throwable $e) {
            error_log("Error creating temp registration: " . $e->getMessage());
        }

        $this->showTermsAndConditions();
    }

    private function requestUsername(): void {
        $message = "☕ *Welcome!*\n\n";
        $message .= "Please set a Telegram username first:\n\n";
        $message .= "Settings ⚙️ → Username\n\n";
        $message .= "Then tap below!";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✅ Done!', 'callback_data' => 'i_set_username']]
            ]
        ];
        $this->sendMessage($message, $keyboard);
    }

    private function recheckUsername(): void {
        $this->editMessageReplyMarkup();
        $userInfo = $this->getUserInfo($this->userId);
        $username = $userInfo['username'] ?? null;

        if (!$username) {
            $this->sendMessage("Username not found. Please set one first.");
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '✅ Try Again', 'callback_data' => 'i_set_username']]
                ]
            ];
            $this->sendMessage("Ready?", $keyboard);
            return;
        }

        $this->username = $username;
        $this->showTermsAndConditions();
    }

    private function showTermsAndConditions(): void {
        $message = "☕ *Terms & Safety*\n\n";
        $message .= "By continuing, you confirm:\n\n";
        $message .= "• You are 18+ years old\n";
        $message .= "• You're responsible for your safety\n";
        $message .= "• We only connect you online\n";
        $message .= "• Be respectful - no harassment\n";
        $message .= "• Multiple reports = ban\n\n";
        $message .= "_Bot owners are not liable for user actions_";
        
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✅ I Accept (18+)', 'callback_data' => 'accept_terms']]
            ]
        ];
        $this->sendMessage($message, $keyboard);
    }

    private function acceptTerms(): void {
        $this->editMessageReplyMarkup();
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO temp_registration (user_id, created_at)
                VALUES (?, NOW())
                ON DUPLICATE KEY UPDATE created_at = NOW()
            ");
            $stmt->execute([$this->userId]);
        } catch (Throwable $e) {
            error_log("Error initializing temp registration: " . $e->getMessage());
        }
        
        $this->sendMessage("Great! What's your first name?");
    }

    private function handleRegistrationStep(): void {
        if (!$this->pdo) return;

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM temp_registration WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            $temp = $stmt->fetch();
        } catch (Throwable $e) {
            error_log("Error getting temp registration: " . $e->getMessage());
            return;
        }

        if (!$temp) return;

        $text = $this->sanitizeText(trim($this->messageText));

        if (!isset($temp['first_name']) || $temp['first_name'] === null) {
            $this->saveFirstName($text);
        } elseif (!isset($temp['last_name']) || $temp['last_name'] === null) {
            $this->saveLastName($text);
        } elseif (!isset($temp['age']) || $temp['age'] === null) {
            $this->saveAge($text);
        }
    }

    private function saveFirstName(string $name): void {
        if (!$this->validateName($name)) {
            $this->sendMessage("Please use only letters (2-50 characters)");
            return;
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE temp_registration SET first_name = ? WHERE user_id = ?");
            $stmt->execute([$name, $this->userId]);
        } catch (Throwable $e) {
            error_log("Error saving first name: " . $e->getMessage());
            return;
        }

        $this->sendMessage("Nice to meet you, {$name}!\n\nWhat's your last name?");
    }

    private function saveLastName(string $name): void {
        if (!$this->validateName($name)) {
            $this->sendMessage("Please use only letters (2-50 characters)");
            return;
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE temp_registration SET last_name = ? WHERE user_id = ?");
            $stmt->execute([$name, $this->userId]);
        } catch (Throwable $e) {
            error_log("Error saving last name: " . $e->getMessage());
            return;
        }

        $this->sendMessage("Perfect! How old are you?");
    }

    private function saveAge(string $ageInput): void {
        if (!is_numeric($ageInput)) {
            $this->sendMessage("Please enter just a number (e.g., 25)");
            return;
        }

        $age = (int)$ageInput;

        if (!$this->validateAge($age)) {
            $this->sendMessage("You must be at least " . MIN_AGE . " to use this service");
            return;
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE temp_registration SET age = ? WHERE user_id = ?");
            $stmt->execute([$age, $this->userId]);
        } catch (Throwable $e) {
            error_log("Error saving age: " . $e->getMessage());
            return;
        }

        $this->askGender();
    }

    private function askGender(): void {
        $message = "Are you male or female?\n\n";
        $message .= "⚠️ *Important:* You can't change this later!\n\n";
        $message .= "_We match you with opposite gender_";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '👨 Male', 'callback_data' => 'gender_male'],
                    ['text' => '👩 Female', 'callback_data' => 'gender_female']
                ]
            ]
        ];
        $this->sendMessage($message, $keyboard);
    }

    private function setGender(string $gender): void {
        if (!in_array($gender, ['male', 'female'])) {
            error_log("Invalid gender value attempted: $gender");
            return;
        }

        $this->editMessageReplyMarkup();

        try {
            $stmt = $this->pdo->prepare("UPDATE temp_registration SET gender = ? WHERE user_id = ?");
            $stmt->execute([$gender, $this->userId]);
        } catch (Throwable $e) {
            error_log("Error setting gender: " . $e->getMessage());
            return;
        }

        $this->askLocation();
    }

    private function askLocation(): void {
        $message = "Almost done!\n\n";
        $message .= "Share your location to find people nearby.\n\n";
        $message .= "⚠️ *Important:*\n";
        $message .= "• Turn ON device location\n";
        $message .= "• Use official Telegram app\n\n";
        $message .= "_Your exact location stays private! 🔒_";
        
        $keyboard = [
            'keyboard' => [
                [['text' => '📍 Share Location', 'request_location' => true]]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ];
        $this->sendMessage($message, $keyboard);
    }

    private function saveLocation(): void {
        if (!isset($this->update['message']['location'])) {
            $message = "⚠️ Location not received.\n\n";
            $message .= "Please:\n";
            $message .= "• Turn ON device location\n";
            $message .= "• Use official Telegram app\n";
            $message .= "• Tap '📍 Share Location' button";
            
            $keyboard = [
                'keyboard' => [
                    [['text' => '📍 Share Location', 'request_location' => true]]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true
            ];
            $this->sendMessage($message, $keyboard);
            return;
        }

        $location = $this->update['message']['location'];
        $lat = (float)($location['latitude'] ?? 0);
        $lon = (float)($location['longitude'] ?? 0);

        if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 || $lat == 0 || $lon == 0) {
            $this->sendMessage("Invalid location. Try sharing again?");
            return;
        }

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            $existingUser = $stmt->fetch();

            if ($existingUser && $existingUser['registration_step'] === 'completed') {
                $stmt = $this->pdo->prepare("UPDATE users SET latitude = ?, longitude = ? WHERE user_id = ?");
                $stmt->execute([$lat, $lon, $this->userId]);
                
                $this->clearUserState();
                $this->sendMessage("✅ Location updated!");
                $this->showMainMenu();
                return;
            }

            $stmt = $this->pdo->prepare("SELECT * FROM temp_registration WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            $temp = $stmt->fetch();

            if (!$temp || empty($temp['first_name']) || empty($temp['last_name']) || empty($temp['age']) || empty($temp['gender'])) {
                $this->sendMessage("Something went wrong. Type /start");
                if ($temp) {
                    $stmt = $this->pdo->prepare("DELETE FROM temp_registration WHERE user_id = ?");
                    $stmt->execute([$this->userId]);
                }
                return;
            }

            $currentUsername = $this->username ?? $this->getUserInfo($this->userId)['username'] ?? null;

            if (!$currentUsername || !$this->validateUsername($currentUsername)) {
                $this->sendMessage("Need a valid username. Set one and type /start");
                return;
            }

            $stmt = $this->pdo->prepare("
                INSERT INTO users (
                    user_id, telegram_username, first_name, last_name, age, gender, 
                    latitude, longitude, registration_step, terms_accepted, coffee_cups
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'completed', 1, ?)
            ");
            
            $result = $stmt->execute([
                $this->userId,
                $currentUsername,
                $temp['first_name'],
                $temp['last_name'],
                $temp['age'],
                $temp['gender'],
                $lat,
                $lon,
                INITIAL_FREE_CUPS
            ]);

            if (!$result) {
                $this->sendMessage("Something went wrong. Try /start again?");
                return;
            }

            $this->logCupTransaction($this->userId, INITIAL_FREE_CUPS, 'initial_free', 'Initial free searches');

            $stmt = $this->pdo->prepare("DELETE FROM temp_registration WHERE user_id = ?");
            $stmt->execute([$this->userId]);

            $message = "🎉 All set!\n\n";
            $message .= "You got " . INITIAL_FREE_CUPS . " free searches 🔍\n\n";
            $message .= "Ready to meet someone?";

            $this->sendMessage($message);
            $this->showMainMenu();

        } catch (Throwable $e) {
            error_log("Error saving location: " . $e->getMessage());
            $this->sendMessage("Something went wrong. Try /start");
        }
    }

    private function showMainMenu(): void {
        $cups = $this->getUserCoffeeCups();
        
        $message = "☕ *Coffee Friend*\n\n";
        $message .= "🔍 {$cups} searches\n\n";
        $message .= "What would you like to do?";
        
        $keyboard = [
            'keyboard' => [
                [['text' => '🔍 Find Match']],
                [['text' => '☕ My Balance'], ['text' => '✏️ Edit Profile']]
            ],
            'resize_keyboard' => true
        ];
        $this->sendMessage($message, $keyboard);
    }

    private function startSearching(): void {
        if (!$this->pdo) {
            $this->sendMessage("Service is down. Try again soon!");
            return;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT u.*, 
                       EXISTS(SELECT 1 FROM chat_sessions WHERE (user1_id = ? OR user2_id = ?) AND is_active = 1) as in_chat,
                       EXISTS(SELECT 1 FROM match_queue WHERE user_id = ?) as in_queue
                FROM users u
                WHERE u.user_id = ?
            ");
            $stmt->execute([$this->userId, $this->userId, $this->userId, $this->userId]);
            $user = $stmt->fetch();

            if (!$user || $user['registration_step'] !== 'completed') {
                $this->sendMessage("Please finish setting up your profile first!");
                return;
            }

            if ($user['in_chat']) {
                $this->sendMessage("You're already chatting! 😊");
                return;
            }

            if ($user['in_queue']) {
                $this->sendMessage("Already searching for a match!");
                return;
            }

            $cups = (int)$user['coffee_cups'];
            if ($cups < 1) {
                $plan = PLAN_STANDARD;
                
                $message = "❌ *Out of searches!*\n\n";
                $message .= "⭐ {$plan['searches']} searches = {$plan['stars']} Stars (\${$plan['price_usd']})\n\n";
                $message .= "_Tap below to buy!_";

                $keyboard = [
                    'inline_keyboard' => [
                        [['text' => '⭐ Buy Searches', 'callback_data' => 'buy_stars']]
                    ]
                ];

                $this->sendMessage($message, $keyboard);
                return;
            }

            if (!$this->deductCoffeeCups(1)) {
                $this->sendMessage("Error processing search. Try again?");
                return;
            }

            $newBalance = $this->getUserCoffeeCups();
            $this->sendMessage("Searching... Balance: {$newBalance} 🔍");

            $stmt = $this->pdo->prepare("
                INSERT INTO match_queue (user_id, gender, age, latitude, longitude, telegram_username, first_name, last_name, has_priority)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)
            ");
            $stmt->execute([
                $this->userId,
                $user['gender'],
                $user['age'],
                $user['latitude'],
                $user['longitude'],
                $user['telegram_username'],
                $user['first_name'],
                $user['last_name']
            ]);

            $this->sendMessage("☕ Looking for your match...\n\nWe'll notify you when found!", [
                'keyboard' => [
                    [['text' => '🚫 Cancel Search']]
                ],
                'resize_keyboard' => true
            ]);

            $this->findMatch($user);

        } catch (Throwable $e) {
            error_log("Error in startSearching: " . $e->getMessage());
            $this->sendMessage("Error starting search. Try again?");
        }
    }

    private function findMatch(array $user): void {
        if (!$this->pdo) return;

        try {
            $oppositeGender = ($user['gender'] === 'male') ? 'female' : 'male';
            $userAge = (int)$user['age'];
            
            $ageRange = $this->calculateAgeRange($userAge);
            $minAge = $ageRange['min'];
            $maxAge = $ageRange['max'];

            $stmt = $this->pdo->prepare("
                SELECT 
                    q.user_id, q.gender, q.age, q.latitude, q.longitude, q.telegram_username, q.first_name, q.last_name,
                    (6371 * acos(
                        cos(radians(?)) * cos(radians(q.latitude)) *
                        cos(radians(q.longitude) - radians(?)) +
                        sin(radians(?)) * sin(radians(q.latitude))
                    )) AS distance
                FROM match_queue q
                WHERE q.user_id != ?
                  AND q.gender = ?
                  AND q.age BETWEEN ? AND ?
                  AND ? BETWEEN ((q.age / 2) + 7) AND ((q.age - 7) * 2)
                  AND NOT EXISTS (
                      SELECT 1 FROM match_history h 
                      WHERE (h.user1_id = ? AND h.user2_id = q.user_id)
                         OR (h.user1_id = q.user_id AND h.user2_id = ?)
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM banned_users b
                      WHERE b.user_id = q.user_id
                  )
                HAVING distance <= ?
                ORDER BY distance ASC, q.searching_since ASC
                LIMIT 1
            ");
            
            $stmt->execute([
                $user['latitude'],
                $user['longitude'],
                $user['latitude'],
                $user['user_id'],
                $oppositeGender,
                $minAge,
                $maxAge,
                $userAge,
                $user['user_id'],
                $user['user_id'],
                MATCH_RADIUS_KM
            ]);

            $match = $stmt->fetch();

            if ($match) {
                $matchAge = (int)$match['age'];
                $matchAgeRange = $this->calculateAgeRange($matchAge);
                
                if ($userAge >= $matchAgeRange['min'] && $userAge <= $matchAgeRange['max']) {
                    $this->createMatch($user, $match);
                }
            }

        } catch (Throwable $e) {
            error_log("Error in findMatch: " . $e->getMessage());
        }
    }

    private function createMatch(array $user1, array $user2): void {
        if (!$this->pdo) return;

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare("DELETE FROM match_queue WHERE user_id IN (?, ?)");
            $stmt->execute([$user1['user_id'], $user2['user_id']]);

            $stmt = $this->pdo->prepare("
                INSERT INTO chat_sessions (user1_id, user2_id, user1_gender, user2_gender, is_active)
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $user1['user_id'],
                $user2['user_id'],
                $user1['gender'],
                $user2['gender']
            ]);

            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO match_history (user1_id, user2_id)
                VALUES (?, ?)
            ");
            $stmt->execute([
                min($user1['user_id'], $user2['user_id']),
                max($user1['user_id'], $user2['user_id'])
            ]);

            $this->pdo->commit();

            $warningMsg = "⚠️ *Safety First*\n\n";
            $warningMsg .= "• This is a stranger\n";
            $warningMsg .= "• Meet in public places\n";
            $warningMsg .= "• Trust your instincts";

            $message = "🎉 *Match Found!*\n\n";
            $message .= "Start chatting! They can't see who you are unless you share your username 🔒";

            $keyboard = [
                'keyboard' => [
                    [['text' => '📤 Share Username']],
                    [['text' => '🚨 Report'], ['text' => '👋 Leave']]
                ],
                'resize_keyboard' => true
            ];

            $this->sendMessageToUser($user1['user_id'], $warningMsg);
            $this->sendMessageToUser($user1['user_id'], $message, $keyboard);
            
            $this->sendMessageToUser($user2['user_id'], $warningMsg);
            $this->sendMessageToUser($user2['user_id'], $message, $keyboard);

        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Error creating match: " . $e->getMessage());
        }
    }

    private function stopSearching(): void {
        try {
            $stmt = $this->pdo->prepare("DELETE FROM match_queue WHERE user_id = ?");
            $stmt->execute([$this->userId]);
        } catch (Throwable $e) {
            error_log("Error stopping search: " . $e->getMessage());
        }

        $this->sendMessage("Search cancelled ✅");
        $this->showMainMenu();
    }

    private function isInActiveChat(): bool {
        if (!$this->pdo) return false;
        try {
            $stmt = $this->pdo->prepare("
                SELECT session_id FROM chat_sessions 
                WHERE (user1_id = ? OR user2_id = ?) AND is_active = 1
            ");
            $stmt->execute([$this->userId, $this->userId]);
            return $stmt->fetch() !== false;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function routeChatMessage(): void {
        if (!$this->pdo) return;

        $text = trim($this->messageText);

        if ($text === '📤 Share Username') {
            $this->shareUsername();
            return;
        }
        if ($text === '👋 Leave') {
            $this->endChatSession();
            return;
        }
        if ($text === '🚨 Report') {
            $this->showReportMenu();
            return;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT session_id, user1_id, user2_id 
                FROM chat_sessions 
                WHERE (user1_id = ? OR user2_id = ?) AND is_active = 1
            ");
            $stmt->execute([$this->userId, $this->userId]);
            $session = $stmt->fetch();

            if (!$session) {
                $this->sendMessage("You're not in an active chat.");
                $this->showMainMenu();
                return;
            }

            $otherUserId = ($session['user1_id'] == $this->userId) ? $session['user2_id'] : $session['user1_id'];
            $text = $this->sanitizeText($text);

            if ($text === '') return;

            $encryptedText = $this->encryptMessage($text);

            if (empty($encryptedText)) {
                error_log("Failed to encrypt message from user {$this->userId}");
                $this->sendMessage("Couldn't send that. Try again?");
                return;
            }

            $keyboard = [
                'keyboard' => [
                    [['text' => '📤 Share Username']],
                    [['text' => '🚨 Report'], ['text' => '👋 Leave']]
                ],
                'resize_keyboard' => true
            ];

            $messageToSend = "☕ *Message:*\n\n" . $text;
            $this->sendMessageToUser($otherUserId, $messageToSend, $keyboard);

        } catch (Throwable $e) {
            error_log("Error routing message: " . $e->getMessage());
        }
    }

    private function shareUsername(): void {
        if (!$this->pdo) return;

        try {
            $stmt = $this->pdo->prepare("
                SELECT session_id, user1_id, user2_id 
                FROM chat_sessions 
                WHERE (user1_id = ? OR user2_id = ?) AND is_active = 1
            ");
            $stmt->execute([$this->userId, $this->userId]);
            $session = $stmt->fetch();

            if (!$session) {
                $this->sendMessage("You're not in a chat.");
                return;
            }

            $stmt = $this->pdo->prepare("
                SELECT share_id FROM username_shares 
                WHERE session_id = ? AND shared_by = ?
            ");
            $stmt->execute([$session['session_id'], $this->userId]);
            if ($stmt->fetch()) {
                $this->sendMessage("You already shared your username!");
                return;
            }

            $stmt = $this->pdo->prepare("SELECT telegram_username FROM users WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            $row = $stmt->fetch();

            if (!$row || empty($row['telegram_username'])) {
                $this->sendMessage("You don't have a username set.");
                return;
            }

            $otherUserId = ($session['user1_id'] == $this->userId) ? $session['user2_id'] : $session['user1_id'];

            $stmt = $this->pdo->prepare("
                INSERT INTO username_shares (session_id, shared_by, shared_to)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$session['session_id'], $this->userId, $otherUserId]);

            $sanitizedUsername = $this->sanitizeText($row['telegram_username']);
            
            $this->sendMessage("✅ Username shared!");
            
            $keyboard = [
                'keyboard' => [
                    [['text' => '📤 Share Username']],
                    [['text' => '🚨 Report'], ['text' => '👋 Leave']]
                ],
                'resize_keyboard' => true
            ];
            
            $message = "📱 *They shared their username!*\n\n@{$sanitizedUsername}\n\nYou can connect outside the bot!";
            $this->sendMessageToUser($otherUserId, $message, $keyboard);

        } catch (Throwable $e) {
            error_log("Error sharing username: " . $e->getMessage());
            $this->sendMessage("Error sharing username. Try again?");
        }
    }

    private function endChatSession(bool $fromReport = false): void {
        if (!$this->pdo) return;

        try {
            $stmt = $this->pdo->prepare("
                SELECT session_id, user1_id, user2_id 
                FROM chat_sessions 
                WHERE (user1_id = ? OR user2_id = ?) AND is_active = 1
            ");
            $stmt->execute([$this->userId, $this->userId]);
            $session = $stmt->fetch();

            if (!$session) {
                $this->sendMessage("You're not in a chat.");
                $this->showMainMenu();
                return;
            }

            $stmt = $this->pdo->prepare("UPDATE chat_sessions SET is_active = 0, ended_at = NOW() WHERE session_id = ?");
            $stmt->execute([$session['session_id']]);

            $otherUserId = ($session['user1_id'] == $this->userId) ? $session['user2_id'] : $session['user1_id'];

            $this->sendMessage("Chat ended. Thanks for chatting! ☕");
            $this->showMainMenu();

            $message = "👋 Your match left.\n\nFind another match anytime!";
            $this->sendMessageToUser($otherUserId, $message);
            $this->showMainMenuToUser($otherUserId);

        } catch (Throwable $e) {
            error_log("Error ending chat session: " . $e->getMessage());
        }
    }

    private function showUpdateProfileMenu(): void {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM users WHERE user_id = ?");
            $stmt->execute([$this->userId]);
            $user = $stmt->fetch();
        } catch (Throwable $e) {
            $user = null;
        }

        if (!$user) {
            $this->sendMessage("Profile not found. Type /start");
            return;
        }

        $genderEmoji = $user['gender'] === 'male' ? '👨' : '👩';
        
        $message = "✏️ *Your Profile*\n\n";
        $message .= "Name: {$user['first_name']} {$user['last_name']}\n";
        $message .= "Age: {$user['age']}\n";
        $message .= "{$genderEmoji} " . ucfirst($user['gender']) . "\n\n";
        $message .= "What to change?";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '✏️ First Name', 'callback_data' => 'update_first_name']],
                [['text' => '✏️ Last Name', 'callback_data' => 'update_last_name']],
                [['text' => '🎂 Age', 'callback_data' => 'update_age']],
                [['text' => '📍 Location', 'callback_data' => 'update_location']],
                [['text' => '◀️ Back', 'callback_data' => 'back_to_menu']]
            ]
        ];
        $this->sendMessage($message, $keyboard);
    }

    private function startUpdateFirstName(): void {
        $this->editMessageReplyMarkup();
        $this->setUserState('editing_first_name');
        $this->sendMessage("Enter new first name:\n\n(Type /start to cancel)");
    }

    private function processFirstNameUpdate(string $name): void {
        if (!$this->validateName($name)) {
            $this->sendMessage("Please use only letters (2-50 characters)\n\n(Type /start to cancel)");
            return;
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE users SET first_name = ? WHERE user_id = ?");
            $stmt->execute([$name, $this->userId]);

            $this->clearUserState();
            $this->sendMessage("✅ First name updated to *{$name}*");
            $this->showMainMenu();
        } catch (Throwable $e) {
            error_log("Error updating first name: " . $e->getMessage());
            $this->sendMessage("Error updating. Try again?");
        }
    }

    private function startUpdateLastName(): void {
        $this->editMessageReplyMarkup();
        $this->setUserState('editing_last_name');
        $this->sendMessage("Enter new last name:\n\n(Type /start to cancel)");
    }

    private function processLastNameUpdate(string $name): void {
        if (!$this->validateName($name)) {
            $this->sendMessage("Please use only letters (2-50 characters)\n\n(Type /start to cancel)");
            return;
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE users SET last_name = ? WHERE user_id = ?");
            $stmt->execute([$name, $this->userId]);

            $this->clearUserState();
            $this->sendMessage("✅ Last name updated to *{$name}*");
            $this->showMainMenu();
        } catch (Throwable $e) {
            error_log("Error updating last name: " . $e->getMessage());
            $this->sendMessage("Error updating. Try again?");
        }
    }

    private function startUpdateAge(): void {
        $this->editMessageReplyMarkup();
        $this->setUserState('editing_age');
        $this->sendMessage("Enter new age:\n\n(Type /start to cancel)");
    }

    private function processAgeUpdate(string $ageInput): void {
        if (!is_numeric($ageInput)) {
            $this->sendMessage("Please enter just a number (e.g., 25)\n\n(Type /start to cancel)");
            return;
        }

        $age = (int)$ageInput;

        if (!$this->validateAge($age)) {
            $this->sendMessage("You must be at least " . MIN_AGE . " to use this service");
            $this->clearUserState();
            return;
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE users SET age = ? WHERE user_id = ?");
            $stmt->execute([$age, $this->userId]);

            $this->clearUserState();
            $this->sendMessage("✅ Age updated to *{$age}*");
            $this->showMainMenu();
        } catch (Throwable $e) {
            error_log("Error updating age: " . $e->getMessage());
            $this->sendMessage("Error updating. Try again?");
        }
    }

    private function startUpdateLocation(): void {
        $this->editMessageReplyMarkup();
        $message = "📍 *Update Location*\n\n";
        $message .= "⚠️ *Important:*\n";
        $message .= "• Turn ON device location\n";
        $message .= "• Use official Telegram app\n\n";
        $message .= "(Type /start to cancel)";
        
        $keyboard = [
            'keyboard' => [
                [['text' => '📍 Share Location', 'request_location' => true]]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ];
        $this->sendMessage($message, $keyboard);
    }

    private function getUserInfo(int $userId): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, API_URL . "getChat?chat_id=$userId");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("CURL error in getUserInfo: " . $curlError);
            return [];
        }
        
        if ($httpCode !== 200) {
            error_log("HTTP $httpCode in getUserInfo for user $userId");
            return [];
        }
        
        if (!$response) {
            error_log("Empty response in getUserInfo");
            return [];
        }
        
        $data = json_decode($response, true);
        return $data['result'] ?? [];
    }

    private function sendMessage(string $text, array|null $keyboard = null): void {
        if (!$this->chatId) {
            error_log("Cannot send message: chatId is null");
            return;
        }
        
        $text = $this->sanitizeText($text);
        if (empty($text)) {
            error_log("Cannot send empty message");
            return;
        }
        
        $params = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = json_encode($keyboard);
        }
        
        $this->makeApiRequest('sendMessage', $params);
    }

    private function safeSendMessage(int $chatId, string $text, array|null $keyboard = null): void {
        if (!$chatId) {
            error_log("Cannot send message: chatId is null");
            return;
        }
        
        $text = $this->sanitizeText($text);
        if (empty($text)) {
            error_log("Cannot send empty message");
            return;
        }
        
        $params = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = json_encode($keyboard);
        }
        
        $this->makeApiRequest('sendMessage', $params);
    }

    private function sendMessageToUser(int $userId, string $text, array|null $keyboard = null): void {
        if (!$userId) {
            error_log("Cannot send message: userId is null");
            return;
        }
        
        $text = $this->sanitizeText($text);
        if (empty($text)) {
            error_log("Cannot send empty message");
            return;
        }
        
        $params = [
            'chat_id' => $userId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];
        
        if ($keyboard) {
            $params['reply_markup'] = json_encode($keyboard);
        }
        
        $this->makeApiRequest('sendMessage', $params);
    }

    private function showMainMenuToUser(int $userId): void {
        $cups = 0;
        
        try {
            $stmt = $this->pdo->prepare("SELECT coffee_cups FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            $cups = (int)($user['coffee_cups'] ?? 0);
        } catch (Throwable $e) {
            error_log("Error getting user info: " . $e->getMessage());
        }

        $message = "☕ *Coffee Friend*\n\n";
        $message .= "🔍 {$cups} searches\n\n";
        $message .= "Ready for another match?";
        
        $keyboard = [
            'keyboard' => [
                [['text' => '🔍 Find Match']],
                [['text' => '☕ My Balance'], ['text' => '✏️ Edit Profile']]
            ],
            'resize_keyboard' => true
        ];
        $this->sendMessageToUser($userId, $message, $keyboard);
    }

    private function editMessageReplyMarkup(): void {
        if (!$this->chatId || !$this->messageId) return;
        
        $params = [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'reply_markup' => json_encode(['inline_keyboard' => []])
        ];
        
        $this->makeApiRequest('editMessageReplyMarkup', $params);
    }

    private function answerCallbackQuery(): void {
        if (isset($this->update['callback_query']['id'])) {
            $params = [
                'callback_query_id' => $this->update['callback_query']['id']
            ];
            $this->makeApiRequest('answerCallbackQuery', $params);
        }
    }

    private function makeApiRequest(string $method, array $params): mixed {
        if (empty($method)) {
            error_log("Empty method name in makeApiRequest");
            return false;
        }
        
        $url = API_URL . $method;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            error_log("CURL error in $method: " . $curlError);
            return false;
        }

        if ($httpCode !== 200) {
            error_log("API $method failed: HTTP $httpCode - Response: " . substr($response, 0, 200));
            return false;
        }

        if (!$response) {
            error_log("Empty response from API $method");
            return false;
        }

        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error in $method: " . json_last_error_msg());
            return false;
        }
        
        if (!isset($result['ok'])) {
            error_log("Invalid response structure from API $method");
            return false;
        }
        
        if (!$result['ok']) {
            error_log("API $method returned ok=false: " . ($result['description'] ?? 'No description'));
            return false;
        }

        return $result['result'] ?? true;
    }
}

try {
    $bot = new CoffeeFriendBot();
    $bot->processUpdate();
} catch (Throwable $e) {
    error_log("Fatal: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
}

http_response_code(200);