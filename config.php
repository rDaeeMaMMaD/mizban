<?php
/**
 * میزبان (Host) - Configuration
 * ------------------------------------------------------------------
 * تمام تنظیمات سیستم در این یک فایل متمرکز است.
 * ارائه‌دهنده‌ها، کلیدها و مدل‌ها در دیتابیس ذخیره می‌شوند و از پنل ادمین مدیریت می‌شوند.
 *
 * مقادیر حساس (رمز دیتابیس، MASTER_SECRET، ...) از فایل `.env` یا
 * Environment Variables سیستم خوانده می‌شوند — هیچ راز واقعی در این فایل نیست.
 *
 * یک نمونه از فایل `.env` در `.env.example` موجود است:
 *   cp .env.example .env
 */

// -----------------------------------------------------------------
// ۰) بارگذاری .env (اختیاری) + توابع کمکی Environment
// -----------------------------------------------------------------

/**
 * بارگذاری یک فایل .env ساده (KEY=VALUE).
 * متغیرهای از قبل موجود در Environment سیستم بازنویسی نمی‌شوند.
 */
function _mizban_load_env(string $file): void {
    if (!is_file($file) || !is_readable($file)) return;
    $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if ($k === '') continue;
        // حذف نقل‌قول‌های دور مقدار
        $len = strlen($v);
        if ($len >= 2 && (($v[0] === '"' && $v[$len - 1] === '"') || ($v[0] === "'" && $v[$len - 1] === "'"))) {
            $v = substr($v, 1, -1);
        }
        if (getenv($k) === false) {
            @putenv($k . '=' . $v);
        }
        $_ENV[$k] = $v;
    }
}
_mizban_load_env(__DIR__ . '/.env');

/**
 * خواندن یک متغیر Environment با مقدار پیش‌فرض.
 */
function _mizban_env(string $key, $default = '') {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
}

// -----------------------------------------------------------------
// ۱) تنظیمات اصلی
// -----------------------------------------------------------------
define('HOST_NAME',        _mizban_env('MIZBAN_HOST_NAME', 'Mizban'));
define('HOST_VERSION',     '10.6.1');
define('HOST_TIMEZONE',    _mizban_env('MIZBAN_TIMEZONE', 'Asia/Tehran'));
define('HOST_DEBUG',       filter_var(_mizban_env('MIZBAN_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN));
date_default_timezone_set(HOST_TIMEZONE);

// -----------------------------------------------------------------
// ۲) پایگاه داده (MySQL — مناسب کار سنگین)
// -----------------------------------------------------------------
// در cPanel: MySQL Database Wizard → دیتابیس + کاربر بسازید
// و مقادیر زیر را در فایل .env (یا Environment سیستم) تنظیم کنید.
define('DB_HOST',    _mizban_env('MIZBAN_DB_HOST', 'localhost'));
define('DB_NAME',    _mizban_env('MIZBAN_DB_NAME', 'api'));
define('DB_USER',    _mizban_env('MIZBAN_DB_USER', 'root'));
define('DB_PASS',    _mizban_env('MIZBAN_DB_PASS', ''));
define('DB_CHARSET', _mizban_env('MIZBAN_DB_CHARSET', 'utf8mb4'));

// -----------------------------------------------------------------
// ۳) امنیت — کلید اصلی (برای HMAC رمز کلاینت‌ها + رمزنگاری کلید API)
// -----------------------------------------------------------------
// ⚠️ در Production الزامی است: یک رشته تصادفی ۶۴ کاراکتری hex.
//    ساخت:  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
//    یا:    openssl rand -hex 32
// اگر خالی باشد، رمزنگاری کلیدهای API غیرفعال می‌شود (fail-closed).
define('MASTER_SECRET', _mizban_env('MIZBAN_MASTER_SECRET', ''));
define('HMAC_ALGO', 'sha256');

// روش رمزنگاری کلیدهای API در دیتابیس
define('ENCRYPT_METHOD', 'AES-256-CBC');
define('ENCRYPT_IV', MASTER_SECRET !== '' ? substr(hash('sha256', MASTER_SECRET), 0, 16) : '');

// محدودیت نرخ درخواست
define('RATE_LIMIT_WINDOW', 60);
define('RATE_LIMIT_MAX',    120);

// -----------------------------------------------------------------
// ۴) مدیر سیستم — از دیتابیس خوانده می‌شه (جدول admins)
// -----------------------------------------------------------------
// ادمین پیش‌فرض فقط در اولین اجرا ساخته می‌شود.
// پیش‌فرض: admin / admin123 — حتماً بعد از نصب از پنل عوض کنید.
define('MIZBAN_ADMIN_USER',      _mizban_env('MIZBAN_ADMIN_USER', 'admin'));
define('MIZBAN_ADMIN_PASSWORD',  _mizban_env('MIZBAN_ADMIN_PASSWORD', 'admin123'));
define('ADMIN_SESSION_NAME', 'mizban_admin');
define('ADMIN_SESSION_TTL',  7200);

// -----------------------------------------------------------------
// ۵) تنظیمات صف و پردازش
// -----------------------------------------------------------------
define('QUEUE_SYNC_PROCESS',   true);
define('QUEUE_SYNC_RESPONSE',  true);
define('QUEUE_BATCH_SIZE',     5);
define('QUEUE_CONCURRENCY',    5);
define('QUEUE_TIMEOUT',        45);
define('SYNC_TOTAL_DEADLINE', 120);
define('QUEUE_RETRY_MAX',      5);
// توکن cron: از Environment خوانده می‌شود؛ در اولین اجرا یک توکن تصادفی
// در جدول settings ذخیره می‌شود (get_cron_token آن را ترجیح می‌دهد).
define('CRON_TOKEN_DEFAULT',  _mizban_env('MIZBAN_CRON_TOKEN', ''));
define('QUEUE_RETENTION_DAYS', 30);

// تنظیمات Callback (ارسال پاسخ به مقصد - برای حالت async)
define('CALLBACK_TIMEOUT',     30);
define('CALLBACK_RETRY_MAX',   2);

// -----------------------------------------------------------------
// ۷) سیستم ACK (تاییدیه فوری دریافت درخواست)
// -----------------------------------------------------------------
define('ACK_ENABLED',          true);
define('ACK_CALLBACK',         true);

// -----------------------------------------------------------------
// ۸) Circuit Breaker (مدیریت قطعی ارائه‌دهنده)
// -----------------------------------------------------------------
define('CB_FAILURE_THRESHOLD', 10);
define('CB_COOLDOWN_SECONDS',  300);
define('CB_HALF_OPEN_MAX',     3);

// -----------------------------------------------------------------
// ۹) Idempotency (جلوگیری از پردازش تکراری)
// -----------------------------------------------------------------
define('IDEMPOTENCY_ENABLED',   true);
define('IDEMPOTENCY_TTL_HOURS', 24);

// -----------------------------------------------------------------
// ۱۰) امنیت پیشرفته — HMAC Signature + Nonce + Timestamp
// -----------------------------------------------------------------
define('SECURITY_HMAC_ENABLED',   false);
define('SECURITY_TIMESTAMP_WINDOW', 300);
define('SECURITY_NONCE_TTL_HOURS', 1);

// -----------------------------------------------------------------
// ۱۱) Rate Limiting چندلایه (Minute/Hour/Day/Month)
// -----------------------------------------------------------------
$GLOBALS['RATE_LIMITS'] = [
    'client' => ['minute' => 120, 'hour' => 5000, 'day' => 50000, 'month' => 1000000],
    'apikey' => ['minute' => 60,  'hour' => 3000, 'day' => 30000, 'month' => 500000],
];

// -----------------------------------------------------------------
// ۱۲) Health Check خودکار
// -----------------------------------------------------------------
define('HEALTH_CHECK_INTERVAL', 60);
define('HEALTH_CHECK_TIMEOUT',  10);
define('HEALTH_CHECK_MODEL',    'gpt-4o-mini');
define('HEALTH_SCORE_THRESHOLD', 50);

// -----------------------------------------------------------------
// ۱۳) صف چندلایه (Multi-Queue)
// -----------------------------------------------------------------
define('DEAD_QUEUE_MAX_ATTEMPTS', 6);
define('RETRY_BASE_DELAY', 2);
define('RETRY_MAX_DELAY', 600);

// -----------------------------------------------------------------
// ۱۵) Lease System — ownership قابل تمدید job
// -----------------------------------------------------------------
define('LEASE_DURATION', 60);
define('LEASE_RENEW_INTERVAL', 30);
define('LEASE_HTTP_EXTENSION', 120);

// -----------------------------------------------------------------
// ۱۴) Worker Pool
// -----------------------------------------------------------------
define('WORKER_CONCURRENCY', 10);
define('WORKER_TIMEOUT', 45);

// -----------------------------------------------------------------
// ۶) Load Balancing و Failover
// -----------------------------------------------------------------
define('KEY_COOLDOWN_SECONDS', 15);
define('KEY_MAX_CONSECUTIVE_ERRORS', 20);
define('FAILOVER_MAX_PROVIDERS', 10);

define('PROVIDER_WEIGHT_GAPGPT',  10);
define('PROVIDER_WEIGHT_DEEPSEEK', 8);
define('PROVIDER_WEIGHT_ZAI',     7);
define('PROVIDER_WEIGHT_NVIDIA',  5);

// -----------------------------------------------------------------
// ۷) HTTP
// -----------------------------------------------------------------
define('HTTP_USER_AGENT', 'Mizban/2.0');
define('HTTP_CONNECT_TIMEOUT', 10);
define('HTTP_TIMEOUT', QUEUE_TIMEOUT);

// -----------------------------------------------------------------
// ۸) لاگ‌گذاری
// -----------------------------------------------------------------
define('LOG_PATH', __DIR__ . '/mizban.log');
define('LOG_MAX_SIZE', 5242880);

// -----------------------------------------------------------------
// ۹) CORS
// -----------------------------------------------------------------
define('CORS_ENABLED', true);
// دامنه‌های مجاز برای فراخوانی مستقیم مرورگر (لیست جدا شده با کاما در .env).
// پنل ادمین هم‌مبدأ است؛ فراخوانی‌های server-to-server (مانند پلاگین وردپرس)
// اصلاً CORS نمی‌خواهند. مقدار خالی = غیرفعال‌کردن CORS برای همه دامنه‌ها.
// برای فعال‌کردن همه: MIZBAN_CORS_ORIGINS=*
$GLOBALS['CORS_ORIGINS'] = [];
$_corsEnv = trim((string)_mizban_env('MIZBAN_CORS_ORIGINS', ''));
if ($_corsEnv !== '') {
    $GLOBALS['CORS_ORIGINS'] = array_values(array_filter(array_map('trim', explode(',', $_corsEnv))));
}
define('CORS_ORIGINS', $GLOBALS['CORS_ORIGINS']);

// -----------------------------------------------------------------
// ۱۰) داده‌های اولیه (Seed) — ارائه‌دهنده‌ها، کلیدها، مدل‌ها
// -----------------------------------------------------------------
// داده‌های اولیه از schema.sql بارگذاری می‌شوند.
// کلیدهای واقعی API در هیچ فایلی ذخیره نمی‌شوند — فقط از پنل ادمین افزوده می‌شوند.

// پایان config.php
