<?php
declare(strict_types=1);

/**
 * CLI Telegram Configuration & Interactive Test Utility
 */

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain');
    die("This configuration script must be run via the CLI terminal.\nCommand: php scripts/setup_telegram.php\n");
}

$envPath = __DIR__ . '/../.env';

echo "\n======================================================\n";
echo "       💎 MicroPMS Telegram Configuration Setup 💎\n";
echo "======================================================\n\n";

echo "This interactive wizard will configure and verify your Telegram Integration.\n\n";
echo "👉 STEP 1: How to get your Telegram Bot Token:\n";
echo "   1. Open Telegram and search for '@BotFather'.\n";
echo "   2. Send '/newbot' and follow the prompts to name your bot.\n";
echo "   3. BotFather will provide your 'HTTP API token' (looks like 123456789:ABC...).\n\n";

echo "👉 STEP 2: How to get your Chat ID:\n";
echo "   1. Start your new bot by searching for its username and clicking 'Start'.\n";
echo "   2. Search for '@userinfobot' or '@GetMyChatID_Bot' on Telegram and send '/start'.\n";
echo "   3. It will reply with your personal Chat ID (a 9 or 10-digit number).\n\n";

// Prompt for Bot Token
echo "🔑 Enter your Telegram Bot Token: ";
$botToken = trim(fgets(STDIN));

if (empty($botToken)) {
    echo "❌ Token cannot be empty. Aborted.\n";
    exit(1);
}

// Prompt for Chat ID
echo "👤 Enter your Telegram Chat ID: ";
$chatId = trim(fgets(STDIN));

if (empty($chatId)) {
    echo "❌ Chat ID cannot be empty. Aborted.\n";
    exit(1);
}

echo "\nSaving configuration to .env...\n";

$envContent = '';
if (file_exists($envPath)) {
    $envContent = file_get_contents($envPath);
}

// Update or append TELEGRAM_BOT_TOKEN
if (preg_match('/^TELEGRAM_BOT_TOKEN=/m', $envContent)) {
    $envContent = preg_replace('/^TELEGRAM_BOT_TOKEN=.*/m', 'TELEGRAM_BOT_TOKEN=' . $botToken, $envContent);
} else {
    $envContent .= "\nTELEGRAM_BOT_TOKEN=" . $botToken;
}

// Update or append TELEGRAM_CHAT_ID
if (preg_match('/^TELEGRAM_CHAT_ID=/m', $envContent)) {
    $envContent = preg_replace('/^TELEGRAM_CHAT_ID=.*/m', 'TELEGRAM_CHAT_ID=' . $chatId, $envContent);
} else {
    $envContent .= "\nTELEGRAM_CHAT_ID=" . $chatId;
}

file_put_contents($envPath, trim($envContent) . "\n");
echo "✅ Config updated in .env!\n\n";

// Clear cached environment variables for the current run
putenv("TELEGRAM_BOT_TOKEN=$botToken");
putenv("TELEGRAM_CHAT_ID=$chatId");

echo "🔄 Testing the connection by sending a welcome alert...\n";

$url = "https://api.telegram.org/bot{$botToken}/sendMessage";
$payload = [
    'chat_id' => $chatId,
    'text' => "⚡ <b>MicroPMS Integration Live!</b>\n\nYour property management system has been successfully connected to Telegram.\nTime: " . date('Y-m-d H:i:s') . "\nProperty: <b>" . (defined('PROPERTY_NAME') ? PROPERTY_NAME : 'My Hotel') . "</b>",
    'parse_mode' => 'HTML'
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);

$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode >= 200 && $httpCode < 300) {
    echo "🎉 SUCCESS! Check your Telegram app. You should have received a confirmation message.\n";
} else {
    echo "❌ FAILED (HTTP {$httpCode}). Response from Telegram: " . $res . "\n";
    echo "Please make sure your token and Chat ID are correct and that you have clicked 'Start' on the bot.\n";
}
echo "\n";
