<?php
// admin_bot.php - Coffee Friend Admin Bot v6.2
// Comprehensive admin panel with efficient navigation, ban by ID, and broadcast features

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/admin_errors.log');

date_default_timezone_set('Africa/Addis_Ababa');

// Configuration
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    error_log("Configuration file not found!");
    http_response_code(200);
    exit;
}

$config = require $configPath;

// Constants
define('ADMIN_BOT_TOKEN', $config['ADMIN_BOT_TOKEN']);
define('ADMIN_TELEGRAM_ID', $config['ADMIN_TELEGRAM_ID']);
define('ADMIN_API_URL', 'https://api.telegram.org/bot' . ADMIN_BOT_TOKEN . '/');
define('DB_HOST', $config['DB_HOST']);
define('DB_PORT', $config['DB_PORT']);
define('DB_USER', $config['DB_USER']);
define('DB_PASS', $config['DB_PASS']);
define('DB_NAME', $config['DB_NAME']);
define('MAIN_BOT_TOKEN', $config['BOT_TOKEN']);
define('MAIN_BOT_API', 'https://api.telegram.org/bot' . MAIN_BOT_TOKEN . '/');

class CoffeeFriendAdminBot {
    private ?PDO $pdo = null;
    private ?array $update = null;
    private ?int $chatId = null;
    private ?int $userId = null;
    private string $messageText = '';
    private ?int $messageId = null;
    private ?string $callbackData = null;

    public function __construct() {
        $this->connectDatabase();
        $this->createAdminTables();
        $this->getUpdate();
    }

    private function connectDatabase(): void {
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', 
                DB_HOST, DB_PORT, DB_NAME);
            $this->pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_PERSISTENT => true,
            ]);
        } catch (PDOException $e) {
            error_log("Admin DB connection failed: " . $e->getMessage());
            $this->pdo = null;
        }
    }

    private function createAdminTables(): void {
        if (!$this->pdo) return;

        try {
            // Ensure admin_states table exists
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS admin_states (
                    admin_id BIGINT PRIMARY KEY,
                    state VARCHAR(100),
                    data TEXT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Ensure admin_actions table exists
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

            // Ensure banned_users table exists
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS banned_users (
                    user_id BIGINT PRIMARY KEY,
                    banned_by BIGINT NOT NULL,
                    reason TEXT,
                    banned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_banned_by (banned_by)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");

            // Ensure broadcast_logs table exists
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS broadcast_logs (
                    broadcast_id INT AUTO_INCREMENT PRIMARY KEY,
                    admin_id BIGINT NOT NULL,
                    message TEXT NOT NULL,
                    total_users INT DEFAULT 0,
                    successful INT DEFAULT 0,
                    failed INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_admin (admin_id),
                    INDEX idx_date (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
        } catch (Throwable $e) {
            error_log("Error creating admin tables: " . $e->getMessage());
        }
    }

    private function getUpdate(): void {
        $content = file_get_contents("php://input");
        if (empty($content)) return;

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) return;

        $this->update = $decoded;

        if (isset($decoded['message'])) {
            $msg = $decoded['message'];
            $this->chatId = $msg['chat']['id'] ?? null;
            $this->userId = $msg['from']['id'] ?? null;
            $this->messageText = trim($msg['text'] ?? '');
            $this->messageId = $msg['message_id'] ?? null;
        } elseif (isset($decoded['callback_query'])) {
            $cb = $decoded['callback_query'];
            $this->callbackData = $cb['data'] ?? null;
            $this->chatId = $cb['message']['chat']['id'] ?? null;
            $this->userId = $cb['from']['id'] ?? null;
            $this->messageId = $cb['message']['message_id'] ?? null;
        }
    }

    public function processUpdate(): void {
        if (!$this->update || !$this->userId) {
            http_response_code(200);
            return;
        }

        // Security check - only admin can use this bot
        if ($this->userId != ADMIN_TELEGRAM_ID) {
            $this->sendMessage("⛔ Unauthorized access");
            http_response_code(200);
            return;
        }

        try {
            if (isset($this->update['message'])) {
                $this->handleMessage();
            } elseif (isset($this->update['callback_query'])) {
                $this->handleCallback();
            }
        } catch (Throwable $e) {
            error_log("Admin bot error: " . $e->getMessage());
            $this->sendMessage("❌ Error: " . $e->getMessage());
        }

        http_response_code(200);
    }

    private function handleMessage(): void {
        $text = $this->messageText;

        // Check if admin is in a state
        $state = $this->getAdminState();

        if ($state && $state['state']) {
            $this->handleStateInput($state, $text);
            return;
        }

        // Main menu commands
        switch ($text) {
            case '/start':
            case '🏠 Main Menu':
                $this->showMainMenu();
                break;

            case '📊 View Statistics':
                $this->showStatistics();
                break;

            case '💰 Credit User':
                $this->startCreditUser();
                break;

            case '🚫 Ban User':
                $this->startBanUser();
                break;

            case '📋 Banned Users':
                $this->showBannedUsers(0);
                break;

            case '🚨 View Reports':
                $this->showPendingReports();
                break;

            case '🔍 Find User':
                $this->startFindUser();
                break;

            case '📢 Broadcast':
                $this->startBroadcast();
                break;

            case '◀️ Back':
                $this->showMainMenu();
                break;

            default:
                $this->sendMessage("Use the menu buttons below 👇");
                break;
        }
    }

    private function handleCallback(): void {
        $this->answerCallbackQuery();
        $data = $this->callbackData;

        if (strpos($data, 'ban_from_report_') === 0) {
            $reportId = (int)str_replace('ban_from_report_', '', $data);
            $this->banUserFromReport($reportId);
        } elseif (strpos($data, 'dismiss_report_') === 0) {
            $reportId = (int)str_replace('dismiss_report_', '', $data);
            $this->dismissReport($reportId);
        } elseif (strpos($data, 'unban_') === 0) {
            $userId = (int)str_replace('unban_', '', $data);
            $this->unbanUser($userId);
        } elseif (strpos($data, 'banned_page_') === 0) {
            $page = (int)str_replace('banned_page_', '', $data);
            $this->editMessageReplyMarkup();
            $this->showBannedUsers($page);
        } elseif (strpos($data, 'confirm_broadcast') === 0) {
            $this->confirmBroadcast();
        } elseif (strpos($data, 'cancel_broadcast') === 0) {
            $this->cancelBroadcast();
        } elseif ($data === 'back_to_menu') {
            $this->editMessageReplyMarkup();
            $this->showMainMenu();
        }
    }

    private function handleStateInput(array $state, string $input): void {
        switch ($state['state']) {
            case 'awaiting_user_id_credit':
                $this->processUserIdForCredit($input);
                break;

            case 'awaiting_credit_amount':
                $this->processCreditAmount($input, $state['data']);
                break;

            case 'awaiting_user_id_search':
                $this->searchUserById($input);
                break;

            case 'awaiting_user_id_ban':
                $this->processUserIdForBan($input);
                break;

            case 'awaiting_ban_reason':
                $this->processBanReason($input, $state['data']);
                break;

            case 'awaiting_broadcast_message':
                $this->processBroadcastMessage($input);
                break;

            default:
                $this->clearAdminState();
                $this->showMainMenu();
                break;
        }
    }

    // ==================== MAIN MENU ====================

    private function showMainMenu(): void {
        $this->clearAdminState();

        $message = "☕️ *Coffee Friend Admin Panel*\n\n";
        $message .= "Welcome, Admin! Choose an action:";

        $keyboard = [
            'keyboard' => [
                [['text' => '📊 View Statistics']],
                [['text' => '💰 Credit User'], ['text' => '🔍 Find User']],
                [['text' => '🚫 Ban User'], ['text' => '📋 Banned Users']],
                [['text' => '🚨 View Reports'], ['text' => '📢 Broadcast']]
            ],
            'resize_keyboard' => true
        ];

        $this->sendMessage($message, $keyboard);
    }

    // ==================== STATISTICS ====================

    private function showStatistics(): void {
        if (!$this->pdo) {
            $this->sendMessage("❌ Database connection error");
            return;
        }

        try {
            // Total users
            $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM users WHERE registration_step = 'completed'");
            $totalUsers = $stmt->fetch()['total'] ?? 0;

            // Gender ratio
            $stmt = $this->pdo->query("
                SELECT 
                    SUM(CASE WHEN gender = 'male' THEN 1 ELSE 0 END) as males,
                    SUM(CASE WHEN gender = 'female' THEN 1 ELSE 0 END) as females
                FROM users WHERE registration_step = 'completed'
            ");
            $genderData = $stmt->fetch();
            $males = $genderData['males'] ?? 0;
            $females = $genderData['females'] ?? 0;

            // Credits deposited this month
            $stmt = $this->pdo->query("
                SELECT COALESCE(SUM(amount), 0) as total_credits
                FROM cup_transactions
                WHERE transaction_type = 'manual_add'
                AND MONTH(created_at) = MONTH(CURRENT_DATE())
                AND YEAR(created_at) = YEAR(CURRENT_DATE())
            ");
            $creditsThisMonth = $stmt->fetch()['total_credits'] ?? 0;

            // Total matches made
            $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM match_history");
            $totalMatches = $stmt->fetch()['total'] ?? 0;

            // Total reports
            $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM reports");
            $totalReports = $stmt->fetch()['total'] ?? 0;

            // Pending reports
            $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM reports WHERE status = 'pending'");
            $pendingReports = $stmt->fetch()['total'] ?? 0;

            // Banned users
            $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM banned_users");
            $bannedUsers = $stmt->fetch()['total'] ?? 0;

            // Total broadcasts
            $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM broadcast_logs");
            $totalBroadcasts = $stmt->fetch()['total'] ?? 0;

            $message = "📊 *System Statistics*\n\n";
            $message .= "👥 *Users*\n";
            $message .= "Total: {$totalUsers}\n";
            $message .= "👨 Male: {$males}\n";
            $message .= "👩 Female: {$females}\n\n";

            $message .= "💰 *Credits*\n";
            $message .= "Deposited this month: {$creditsThisMonth} cups\n\n";

            $message .= "💑 *Matches*\n";
            $message .= "Total matches: {$totalMatches}\n\n";

            $message .= "🚨 *Reports & Moderation*\n";
            $message .= "Total reports: {$totalReports}\n";
            $message .= "⏳ Pending: {$pendingReports}\n";
            $message .= "🚫 Banned users: {$bannedUsers}\n\n";

            $message .= "📢 *Broadcasts*\n";
            $message .= "Total sent: {$totalBroadcasts}\n";

            $this->sendMessage($message);

        } catch (Throwable $e) {
            error_log("Error getting statistics: " . $e->getMessage());
            $this->sendMessage("❌ Error loading statistics");
        }
    }

    // ==================== CREDIT USER ====================

    private function startCreditUser(): void {
        $this->setAdminState('awaiting_user_id_credit', '');
        
        $message = "💰 *Credit User Account*\n\n";
        $message .= "Enter the user ID (e.g., 8096988441 or CF0008096988441)\n";
        $message .= "Or send /cancel to go back";

        $keyboard = [
            'keyboard' => [
                [['text' => '◀️ Back']]
            ],
            'resize_keyboard' => true
        ];

        $this->sendMessage($message, $keyboard);
    }

    private function processUserIdForCredit(string $input): void {
        if ($input === '◀️ Back' || $input === '/cancel') {
            $this->showMainMenu();
            return;
        }

        // Parse user ID
        $userId = $this->parseUserId($input);

        if (!$userId) {
            $this->sendMessage("❌ Invalid user ID format\n\nTry again or send /cancel");
            return;
        }

        // Check if user exists
        try {
            $stmt = $this->pdo->prepare("SELECT user_id, first_name, last_name FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user) {
                $this->sendMessage("❌ User not found\n\nTry again or send /cancel");
                return;
            }

            $this->setAdminState('awaiting_credit_amount', (string)$userId);

            $message = "💰 *Credit User*\n\n";
            $message .= "User: {$user['first_name']} {$user['last_name']}\n";
            $message .= "ID: CF" . str_pad($userId, 10, '0', STR_PAD_LEFT) . "\n\n";
            $message .= "How many cups to credit?\n";
            $message .= "Or send /cancel to go back";

            $this->sendMessage($message);

        } catch (Throwable $e) {
            error_log("Error checking user: " . $e->getMessage());
            $this->sendMessage("❌ Database error\n\nTry again or send /cancel");
        }
    }

    private function processCreditAmount(string $input, string $userIdStr): void {
        if ($input === '◀️ Back' || $input === '/cancel') {
            $this->showMainMenu();
            return;
        }

        if (!is_numeric($input)) {
            $this->sendMessage("❌ Please enter a valid number\n\nOr send /cancel");
            return;
        }

        $amount = (int)$input;
        $userId = (int)$userIdStr;

        if ($amount <= 0 || $amount > 1000) {
            $this->sendMessage("❌ Amount must be between 1 and 1000\n\nTry again or send /cancel");
            return;
        }

        try {
            // Credit the user
            $stmt = $this->pdo->prepare("UPDATE users SET coffee_cups = coffee_cups + ? WHERE user_id = ?");
            $stmt->execute([$amount, $userId]);

            // Log transaction
            $stmt = $this->pdo->prepare("
                INSERT INTO cup_transactions (user_id, amount, transaction_type, description)
                VALUES (?, ?, 'manual_add', ?)
            ");
            $description = "Admin credit by " . ADMIN_TELEGRAM_ID;
            $stmt->execute([$userId, $amount, $description]);

            // Log admin action
            $this->logAdminAction('credit_user', $userId, "Credited {$amount} cups");

            // Get updated balance
            $stmt = $this->pdo->prepare("SELECT coffee_cups, first_name FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            $newBalance = $user['coffee_cups'] ?? 0;

            // Notify user via main bot
            $this->notifyUserCredited($userId, $amount);

            $this->clearAdminState();

            $message = "✅ *Credit Successful*\n\n";
            $message .= "User: {$user['first_name']}\n";
            $message .= "Credited: {$amount} cups\n";
            $message .= "New balance: {$newBalance} cups\n\n";
            $message .= "User has been notified ✉️";

            $this->sendMessage($message);
            $this->showMainMenu();

        } catch (Throwable $e) {
            error_log("Error crediting user: " . $e->getMessage());
            $this->sendMessage("❌ Error processing credit");
            $this->showMainMenu();
        }
    }

    private function notifyUserCredited(int $userId, int $amount): void {
        $message = "🎉 *Great News!*\n\n";
        $message .= "You've been credited with *{$amount} coffee cups* ☕\n\n";
        $message .= "Enjoy finding more matches!";

        $this->sendToMainBot($userId, $message);
    }

    // ==================== BAN USER BY ID ====================

    private function startBanUser(): void {
        $this->setAdminState('awaiting_user_id_ban', '');
        
        $message = "🚫 *Ban User*\n\n";
        $message .= "Enter the user ID to ban (e.g., 8096988441 or CF0008096988441)\n";
        $message .= "Or send /cancel to go back";

        $keyboard = [
            'keyboard' => [
                [['text' => '◀️ Back']]
            ],
            'resize_keyboard' => true
        ];

        $this->sendMessage($message, $keyboard);
    }

    private function processUserIdForBan(string $input): void {
        if ($input === '◀️ Back' || $input === '/cancel') {
            $this->showMainMenu();
            return;
        }

        // Parse user ID
        $userId = $this->parseUserId($input);

        if (!$userId) {
            $this->sendMessage("❌ Invalid user ID format\n\nTry again or send /cancel");
            return;
        }

        // Check if user exists
        try {
            $stmt = $this->pdo->prepare("SELECT user_id, first_name, last_name FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user) {
                $this->sendMessage("❌ User not found\n\nTry again or send /cancel");
                return;
            }

            // Check if already banned
            $stmt = $this->pdo->prepare("SELECT user_id FROM banned_users WHERE user_id = ?");
            $stmt->execute([$userId]);
            
            if ($stmt->fetch()) {
                $this->sendMessage("⚠️ User is already banned\n\nTry another ID or send /cancel");
                return;
            }

            $this->setAdminState('awaiting_ban_reason', (string)$userId);

            $message = "🚫 *Ban User*\n\n";
            $message .= "User: {$user['first_name']} {$user['last_name']}\n";
            $message .= "ID: CF" . str_pad($userId, 10, '0', STR_PAD_LEFT) . "\n\n";
            $message .= "Enter ban reason (or type 'skip' for no reason):\n";
            $message .= "Or send /cancel to go back";

            $this->sendMessage($message);

        } catch (Throwable $e) {
            error_log("Error checking user for ban: " . $e->getMessage());
            $this->sendMessage("❌ Database error\n\nTry again or send /cancel");
        }
    }

    private function processBanReason(string $input, string $userIdStr): void {
        if ($input === '◀️ Back' || $input === '/cancel') {
            $this->showMainMenu();
            return;
        }

        $userId = (int)$userIdStr;
        $reason = strtolower(trim($input)) === 'skip' ? 'No reason provided' : trim($input);

        if (strlen($reason) > 500) {
            $this->sendMessage("❌ Reason too long (max 500 characters)\n\nTry again or send /cancel");
            return;
        }

        try {
            // Ban user
            $stmt = $this->pdo->prepare("
                INSERT INTO banned_users (user_id, banned_by, reason)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$userId, ADMIN_TELEGRAM_ID, $reason]);

            // End any active chats
            $stmt = $this->pdo->prepare("
                UPDATE chat_sessions 
                SET is_active = 0, ended_at = NOW() 
                WHERE (user1_id = ? OR user2_id = ?) AND is_active = 1
            ");
            $stmt->execute([$userId, $userId]);

            // Log action
            $this->logAdminAction('ban_user', $userId, "Banned: {$reason}");

            // Get user info
            $stmt = $this->pdo->prepare("SELECT first_name, last_name FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            // Notify user
            $message = "🚫 *Account Suspended*\n\n";
            $message .= "Your account has been suspended.\n\n";
            if ($reason !== 'No reason provided') {
                $message .= "Reason: {$reason}\n\n";
            }
            $message .= "If you believe this is a mistake, please contact support.";
            $this->sendToMainBot($userId, $message);

            $this->clearAdminState();

            $userIdFormatted = "CF" . str_pad($userId, 10, '0', STR_PAD_LEFT);
            $userName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
            
            $confirmMessage = "✅ *User Banned Successfully*\n\n";
            $confirmMessage .= "User: {$userName}\n";
            $confirmMessage .= "ID: {$userIdFormatted}\n";
            $confirmMessage .= "Reason: {$reason}\n\n";
            $confirmMessage .= "User has been notified ✉️";

            $this->sendMessage($confirmMessage);
            $this->showMainMenu();

        } catch (Throwable $e) {
            error_log("Error banning user: " . $e->getMessage());
            $this->sendMessage("❌ Error processing ban");
            $this->showMainMenu();
        }
    }

    // ==================== BANNED USERS ====================

    private function showBannedUsers(int $page = 0): void {
        if (!$this->pdo) {
            $this->sendMessage("❌ Database error");
            return;
        }

        try {
            $limit = 10;
            $offset = $page * $limit;

            // Get total count
            $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM banned_users");
            $totalBanned = $stmt->fetch()['total'] ?? 0;

            if ($totalBanned == 0) {
                $message = "🚫 *Banned Users*\n\n";
                $message .= "No banned users at the moment ✨";
                $this->sendMessage($message);
                return;
            }

            // Get paginated banned users
            $stmt = $this->pdo->prepare("
                SELECT b.user_id, b.reason, b.banned_at, u.first_name, u.last_name
                FROM banned_users b
                LEFT JOIN users u ON b.user_id = u.user_id
                ORDER BY b.banned_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$limit, $offset]);
            $bannedUsers = $stmt->fetchAll();

            $totalPages = (int)ceil($totalBanned / $limit);
            $currentPage = $page + 1;

            $message = "🚫 *Banned Users*\n";
            $message .= "Page {$currentPage} of {$totalPages} • Total: {$totalBanned}\n\n";

            foreach ($bannedUsers as $user) {
                $userId = "CF" . str_pad($user['user_id'], 10, '0', STR_PAD_LEFT);
                $name = trim(($user['first_name'] ?? 'Unknown') . ' ' . ($user['last_name'] ?? ''));
                $reason = $user['reason'] ?? 'No reason provided';
                $date = date('M j, Y', strtotime($user['banned_at']));

                $message .= "━━━━━━━━━━━━━━\n";
                $message .= "👤 {$name}\n";
                $message .= "ID: `{$userId}`\n";
                $message .= "Reason: {$reason}\n";
                $message .= "Date: {$date}\n\n";
            }

            // Create pagination keyboard
            $keyboard = ['inline_keyboard' => []];
            $navButtons = [];

            if ($page > 0) {
                $navButtons[] = ['text' => '⬅️ Previous', 'callback_data' => 'banned_page_' . ($page - 1)];
            }

            if ($currentPage < $totalPages) {
                $navButtons[] = ['text' => 'Next ➡️', 'callback_data' => 'banned_page_' . ($page + 1)];
            }

            if (!empty($navButtons)) {
                $keyboard['inline_keyboard'][] = $navButtons;
            }

            // Add unban buttons for each user (show first 3 only to avoid button overflow)
            $maxUnbanButtons = min(3, count($bannedUsers));
            for ($i = 0; $i < $maxUnbanButtons; $i++) {
                $user = $bannedUsers[$i];
                $name = trim(($user['first_name'] ?? 'User') . ' ' . ($user['last_name'] ?? ''));
                $keyboard['inline_keyboard'][] = [
                    ['text' => "✅ Unban {$name}", 'callback_data' => 'unban_' . $user['user_id']]
                ];
            }

            $this->sendMessage($message, $keyboard);

        } catch (Throwable $e) {
            error_log("Error getting banned users: " . $e->getMessage());
            $this->sendMessage("❌ Error loading banned users");
        }
    }

    private function unbanUser(int $userId): void {
        if (!$this->pdo) return;

        $this->editMessageReplyMarkup();

        try {
            $stmt = $this->pdo->prepare("DELETE FROM banned_users WHERE user_id = ?");
            $stmt->execute([$userId]);

            if ($stmt->rowCount() > 0) {
                $this->logAdminAction('unban_user', $userId, "User unbanned");

                // Notify user
                $message = "✅ *Account Restored*\n\n";
                $message .= "Your account has been unbanned.\n";
                $message .= "Welcome back! Please follow our community guidelines.";
                $this->sendToMainBot($userId, $message);

                $userIdFormatted = "CF" . str_pad($userId, 10, '0', STR_PAD_LEFT);
                $this->sendMessage("✅ User {$userIdFormatted} has been unbanned");
            } else {
                $this->sendMessage("❌ User was not banned");
            }

        } catch (Throwable $e) {
            error_log("Error unbanning user: " . $e->getMessage());
            $this->sendMessage("❌ Error unbanning user");
        }
    }

    // ==================== BROADCAST MESSAGE ====================

    private function startBroadcast(): void {
        $this->setAdminState('awaiting_broadcast_message', '');
        
        $message = "📢 *Broadcast Message*\n\n";
        $message .= "This will send a message to ALL active users.\n\n";
        $message .= "⚠️ Use carefully!\n\n";
        $message .= "Enter your broadcast message:\n";
        $message .= "Or send /cancel to go back";

        $keyboard = [
            'keyboard' => [
                [['text' => '◀️ Back']]
            ],
            'resize_keyboard' => true
        ];

        $this->sendMessage($message, $keyboard);
    }

    private function processBroadcastMessage(string $input): void {
        if ($input === '◀️ Back' || $input === '/cancel') {
            $this->showMainMenu();
            return;
        }

        if (strlen($input) < 10) {
            $this->sendMessage("❌ Message too short (minimum 10 characters)\n\nTry again or send /cancel");
            return;
        }

        if (strlen($input) > 4000) {
            $this->sendMessage("❌ Message too long (maximum 4000 characters)\n\nTry again or send /cancel");
            return;
        }

        try {
            // Get total user count
            $stmt = $this->pdo->query("SELECT COUNT(*) as total FROM users WHERE registration_step = 'completed'");
            $totalUsers = $stmt->fetch()['total'] ?? 0;

            if ($totalUsers == 0) {
                $this->sendMessage("❌ No users to broadcast to");
                $this->showMainMenu();
                return;
            }

            // Store message in state for confirmation
            $this->setAdminState('confirming_broadcast', $input);

            $previewMessage = "📢 *Broadcast Preview*\n\n";
            $previewMessage .= "━━━━━━━━━━━━━━\n";
            $previewMessage .= $input . "\n";
            $previewMessage .= "━━━━━━━━━━━━━━\n\n";
            $previewMessage .= "📊 Will be sent to: *{$totalUsers} users*\n\n";
            $previewMessage .= "⚠️ Are you sure you want to send this?";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '✅ Yes, Send Broadcast', 'callback_data' => 'confirm_broadcast']],
                    [['text' => '❌ Cancel', 'callback_data' => 'cancel_broadcast']]
                ]
            ];

            $this->sendMessage($previewMessage, $keyboard);

        } catch (Throwable $e) {
            error_log("Error preparing broadcast: " . $e->getMessage());
            $this->sendMessage("❌ Error preparing broadcast");
            $this->showMainMenu();
        }
    }

    private function confirmBroadcast(): void {
        if (!$this->pdo) return;

        $this->editMessageReplyMarkup();

        $state = $this->getAdminState();
        if (!$state || $state['state'] !== 'confirming_broadcast') {
            $this->sendMessage("❌ Broadcast session expired");
            $this->showMainMenu();
            return;
        }

        $message = $state['data'];

        try {
            // Get all active users
            $stmt = $this->pdo->query("SELECT user_id FROM users WHERE registration_step = 'completed'");
            $users = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $totalUsers = count($users);
            $successful = 0;
            $failed = 0;

            $this->sendMessage("📢 Broadcasting to {$totalUsers} users...\n\nThis may take a few moments ⏳");

            // Send to each user with a small delay to avoid rate limiting
            foreach ($users as $userId) {
                $broadcastMessage = "📢 *Broadcast Message*\n\n";
                $broadcastMessage .= $message;

                $result = $this->sendToMainBot($userId, $broadcastMessage);
                
                if ($result) {
                    $successful++;
                } else {
                    $failed++;
                }

                // Small delay to avoid hitting rate limits (50 messages per second max)
                usleep(25000); // 25ms delay = ~40 messages per second
            }

            // Log broadcast
            $stmt = $this->pdo->prepare("
                INSERT INTO broadcast_logs (admin_id, message, total_users, successful, failed)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([ADMIN_TELEGRAM_ID, $message, $totalUsers, $successful, $failed]);

            $this->logAdminAction('broadcast', null, "Sent to {$successful}/{$totalUsers} users");

            $this->clearAdminState();

            $resultMessage = "✅ *Broadcast Complete*\n\n";
            $resultMessage .= "📊 Results:\n";
            $resultMessage .= "Total users: {$totalUsers}\n";
            $resultMessage .= "✅ Successful: {$successful}\n";
            $resultMessage .= "❌ Failed: {$failed}\n\n";
            
            $successRate = $totalUsers > 0 ? round(($successful / $totalUsers) * 100, 1) : 0;
            $resultMessage .= "Success rate: {$successRate}%";

            $this->sendMessage($resultMessage);
            $this->showMainMenu();

        } catch (Throwable $e) {
            error_log("Error sending broadcast: " . $e->getMessage());
            $this->sendMessage("❌ Error sending broadcast");
            $this->showMainMenu();
        }
    }

    private function cancelBroadcast(): void {
        $this->editMessageReplyMarkup();
        $this->clearAdminState();
        $this->sendMessage("❌ Broadcast cancelled");
        $this->showMainMenu();
    }

    // ==================== REPORTS ====================

    private function showPendingReports(): void {
        if (!$this->pdo) {
            $this->sendMessage("❌ Database error");
            return;
        }

        try {
            $stmt = $this->pdo->query("
                SELECT r.*, 
                       u1.first_name as reporter_fname, u1.last_name as reporter_lname,
                       u2.first_name as reported_fname, u2.last_name as reported_lname
                FROM reports r
                LEFT JOIN users u1 ON r.reporter_id = u1.user_id
                LEFT JOIN users u2 ON r.reported_user_id = u2.user_id
                WHERE r.status = 'pending'
                ORDER BY r.created_at DESC
                LIMIT 10
            ");
            $reports = $stmt->fetchAll();

            if (empty($reports)) {
                $message = "✅ *No Pending Reports*\n\n";
                $message .= "All caught up! No reports to review.";
                $this->sendMessage($message);
                return;
            }

            $this->sendMessage("📋 *Pending Reports* (" . count($reports) . ")\n\nReviewing...");

            foreach ($reports as $report) {
                $this->displayReport($report, true);
            }

        } catch (Throwable $e) {
            error_log("Error getting pending reports: " . $e->getMessage());
            $this->sendMessage("❌ Error loading reports");
        }
    }

    private function displayReport(array $report, bool $showActions = true): void {
        $reportTypes = [
            'underage' => '🔞 Underage',
            'false_identity' => '🎭 False Identity',
            'sexual_content' => '🔞 Sexual Content',
            'harassment' => '⚠️ Harassment',
            'safety_concern' => '🚨 Safety Concern',
            'hate_speech' => '💔 Hate Speech'
        ];

        $reporterName = trim(($report['reporter_fname'] ?? 'Unknown') . ' ' . ($report['reporter_lname'] ?? ''));
        $reportedName = trim(($report['reported_fname'] ?? 'Unknown') . ' ' . ($report['reported_lname'] ?? ''));
        
        $reporterId = "CF" . str_pad($report['reporter_id'], 10, '0', STR_PAD_LEFT);
        $reportedId = "CF" . str_pad($report['reported_user_id'], 10, '0', STR_PAD_LEFT);

        $message = "🚨 *Report #" . $report['report_id'] . "*\n\n";
        $message .= "Type: " . ($reportTypes[$report['report_type']] ?? $report['report_type']) . "\n";
        $message .= "Status: " . ucfirst($report['status']) . "\n";
        $message .= "Date: " . date('M j, Y H:i', strtotime($report['created_at'])) . "\n\n";
        
        $message .= "👤 *Reported User*\n";
        $message .= "Name: {$reportedName}\n";
        $message .= "ID: `{$reportedId}`\n\n";
        
        $message .= "👮 *Reporter*\n";
        $message .= "Name: {$reporterName}\n";
        $message .= "ID: `{$reporterId}`\n";

        if (!empty($report['details'])) {
            $message .= "\n📝 Details: " . $report['details'];
        }

        if ($showActions) {
            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '🚫 Ban User', 'callback_data' => 'ban_from_report_' . $report['report_id']]],
                    [['text' => '❌ Dismiss Report', 'callback_data' => 'dismiss_report_' . $report['report_id']]]
                ]
            ];
        } else {
            $keyboard = null;
        }

        $this->sendMessage($message, $keyboard);
    }

    private function banUserFromReport(int $reportId): void {
        if (!$this->pdo) return;

        $this->editMessageReplyMarkup();

        try {
            $stmt = $this->pdo->prepare("SELECT reported_user_id, report_type FROM reports WHERE report_id = ?");
            $stmt->execute([$reportId]);
            $report = $stmt->fetch();

            if (!$report) {
                $this->sendMessage("❌ Report not found");
                return;
            }

            $userId = $report['reported_user_id'];
            $reason = "Banned due to report: " . $report['report_type'];

            // Ban user
            $stmt = $this->pdo->prepare("
                INSERT INTO banned_users (user_id, banned_by, reason)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE reason = ?, banned_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$userId, ADMIN_TELEGRAM_ID, $reason, $reason]);

            // Update report status
            $stmt = $this->pdo->prepare("UPDATE reports SET status = 'action_taken' WHERE report_id = ?");
            $stmt->execute([$reportId]);

            // End any active chats
            $stmt = $this->pdo->prepare("
                UPDATE chat_sessions 
                SET is_active = 0, ended_at = NOW() 
                WHERE (user1_id = ? OR user2_id = ?) AND is_active = 1
            ");
            $stmt->execute([$userId, $userId]);

            // Log action
            $this->logAdminAction('ban_user', $userId, "Banned from report #{$reportId}");

            // Notify user
            $message = "🚫 *Account Suspended*\n\n";
            $message .= "Your account has been suspended due to violating our community guidelines.\n\n";
            $message .= "If you believe this is a mistake, contact the admin.";
            $this->sendToMainBot($userId, $message);

            $userIdFormatted = "CF" . str_pad($userId, 10, '0', STR_PAD_LEFT);
            $this->sendMessage("✅ User {$userIdFormatted} has been banned");

        } catch (Throwable $e) {
            error_log("Error banning user from report: " . $e->getMessage());
            $this->sendMessage("❌ Error processing ban");
        }
    }

    private function dismissReport(int $reportId): void {
        if (!$this->pdo) return;

        $this->editMessageReplyMarkup();

        try {
            $stmt = $this->pdo->prepare("UPDATE reports SET status = 'reviewed' WHERE report_id = ?");
            $stmt->execute([$reportId]);

            if ($stmt->rowCount() > 0) {
                $this->sendMessage("✅ Report #{$reportId} dismissed");
            } else {
                $this->sendMessage("❌ Report not found");
            }

        } catch (Throwable $e) {
            error_log("Error dismissing report: " . $e->getMessage());
            $this->sendMessage("❌ Error dismissing report");
        }
    }

    // ==================== FIND USER ====================

    private function startFindUser(): void {
        $this->setAdminState('awaiting_user_id_search', '');
        
        $message = "🔍 *Find User*\n\n";
        $message .= "Enter user ID (e.g., 8096988441 or CF0008096988441)\n";
        $message .= "Or send /cancel to go back";

        $keyboard = [
            'keyboard' => [
                [['text' => '◀️ Back']]
            ],
            'resize_keyboard' => true
        ];

        $this->sendMessage($message, $keyboard);
    }

    private function searchUserById(string $input): void {
        if ($input === '◀️ Back' || $input === '/cancel') {
            $this->showMainMenu();
            return;
        }

        $userId = $this->parseUserId($input);

        if (!$userId) {
            $this->sendMessage("❌ Invalid user ID format\n\nEnter just the numbers (e.g., 8096988441)\nTry again or send /cancel");
            return;
        }

        try {
            $stmt = $this->pdo->prepare("
                SELECT u.*,
                       (SELECT COUNT(*) FROM reports WHERE reported_user_id = u.user_id) as report_count,
                       (SELECT COUNT(*) FROM chat_sessions WHERE (user1_id = u.user_id OR user2_id = u.user_id) AND is_active = 1) as active_chats,
                       (SELECT COUNT(*) FROM match_history WHERE user1_id = u.user_id OR user2_id = u.user_id) as total_matches,
                       b.banned_at, b.reason as ban_reason
                FROM users u
                LEFT JOIN banned_users b ON u.user_id = b.user_id
                WHERE u.user_id = ?
            ");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user) {
                $this->sendMessage("❌ User not found\n\nMake sure you entered the correct ID\nTry again or send /cancel");
                return;
            }

            $this->clearAdminState();
            $this->displayUserDetails($user);
            $this->showMainMenu();

        } catch (Throwable $e) {
            error_log("Error searching user: " . $e->getMessage());
            $this->sendMessage("❌ Database error\n\nTry again or send /cancel");
        }
    }

    private function displayUserDetails(array $user): void {
        $userIdFormatted = "CF" . str_pad($user['user_id'], 10, '0', STR_PAD_LEFT);
        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

        $message = "👤 *User Profile*\n\n";
        $message .= "ID: `{$userIdFormatted}`\n";
        $message .= "Name: {$name}\n";
        $message .= "Username: @{$user['telegram_username']}\n";
        $message .= "Age: {$user['age']}\n";
        $message .= "Gender: " . ucfirst($user['gender']) . "\n\n";

        $message .= "☕️ *Account Info*\n";
        $message .= "Coffee Cups: {$user['coffee_cups']}\n";
        $message .= "Status: " . ($user['registration_step'] === 'completed' ? '✅ Active' : '⏳ Incomplete') . "\n";
        $message .= "Joined: " . date('M j, Y', strtotime($user['created_at'])) . "\n\n";

        $message .= "📊 *Activity*\n";
        $message .= "Total Matches: {$user['total_matches']}\n";
        $message .= "Active Chats: {$user['active_chats']}\n";
        $message .= "Reports Against: {$user['report_count']}\n";

        if ($user['banned_at']) {
            $message .= "\n🚫 *BANNED*\n";
            $message .= "Date: " . date('M j, Y', strtotime($user['banned_at'])) . "\n";
            $message .= "Reason: {$user['ban_reason']}";

            $keyboard = [
                'inline_keyboard' => [
                    [['text' => '✅ Unban This User', 'callback_data' => 'unban_' . $user['user_id']]]
                ]
            ];
        } else {
            $keyboard = null;
        }

        $this->sendMessage($message, $keyboard);
    }

    // ==================== HELPER METHODS ====================

    private function parseUserId(string $input): ?int {
        $input = trim($input);
        
        // Remove "CF" prefix if present (case insensitive)
        if (stripos($input, 'CF') === 0) {
            $input = substr($input, 2);
        }

        // Remove all non-numeric characters
        $input = preg_replace('/[^0-9]/', '', $input);

        // Validate
        if (empty($input) || !is_numeric($input)) {
            return null;
        }

        $userId = (int)$input;
        
        return $userId > 0 ? $userId : null;
    }

    private function setAdminState(string $state, string $data): void {
        if (!$this->pdo) return;

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO admin_states (admin_id, state, data, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE state = ?, data = ?, updated_at = NOW()
            ");
            $stmt->execute([ADMIN_TELEGRAM_ID, $state, $data, $state, $data]);
        } catch (Throwable $e) {
            error_log("Error setting admin state: " . $e->getMessage());
        }
    }

    private function getAdminState(): ?array {
        if (!$this->pdo) return null;

        try {
            $stmt = $this->pdo->prepare("SELECT state, data FROM admin_states WHERE admin_id = ?");
            $stmt->execute([ADMIN_TELEGRAM_ID]);
            return $stmt->fetch() ?: null;
        } catch (Throwable $e) {
            error_log("Error getting admin state: " . $e->getMessage());
            return null;
        }
    }

    private function clearAdminState(): void {
        if (!$this->pdo) return;

        try {
            $stmt = $this->pdo->prepare("DELETE FROM admin_states WHERE admin_id = ?");
            $stmt->execute([ADMIN_TELEGRAM_ID]);
        } catch (Throwable $e) {
            error_log("Error clearing admin state: " . $e->getMessage());
        }
    }

    private function logAdminAction(string $actionType, ?int $targetUserId, string $details): void {
        if (!$this->pdo) return;

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO admin_actions (admin_id, action_type, target_user_id, details)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([ADMIN_TELEGRAM_ID, $actionType, $targetUserId, $details]);
        } catch (Throwable $e) {
            error_log("Error logging admin action: " . $e->getMessage());
        }
    }

    private function sendToMainBot(int $userId, string $message): bool {
        return $this->makeApiRequest(MAIN_BOT_API, 'sendMessage', [
            'chat_id' => $userId,
            'text' => $message,
            'parse_mode' => 'Markdown'
        ]);
    }

    private function sendMessage(string $text, ?array $keyboard = null): void {
        if (!$this->chatId) return;

        $params = [
            'chat_id' => $this->chatId,
            'text' => $text,
            'parse_mode' => 'Markdown'
        ];

        if ($keyboard) {
            $params['reply_markup'] = json_encode($keyboard);
        }

        $this->makeApiRequest(ADMIN_API_URL, 'sendMessage', $params);
    }

    private function editMessageReplyMarkup(): void {
        if (!$this->chatId || !$this->messageId) return;

        $this->makeApiRequest(ADMIN_API_URL, 'editMessageReplyMarkup', [
            'chat_id' => $this->chatId,
            'message_id' => $this->messageId,
            'reply_markup' => json_encode(['inline_keyboard' => []])
        ]);
    }

    private function answerCallbackQuery(string $text = ''): void {
        if (!isset($this->update['callback_query']['id'])) return;

        $params = ['callback_query_id' => $this->update['callback_query']['id']];
        if ($text) {
            $params['text'] = $text;
        }

        $this->makeApiRequest(ADMIN_API_URL, 'answerCallbackQuery', $params);
    }

    private function makeApiRequest(string $apiUrl, string $method, array $params): bool {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl . $method);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("API request failed: $method - HTTP $httpCode");
            return false;
        }

        return true;
    }
}

// Initialize and run
try {
    $adminBot = new CoffeeFriendAdminBot();
    $adminBot->processUpdate();
} catch (Throwable $e) {
    error_log("Admin bot fatal error: " . $e->getMessage());
}

http_response_code(200);