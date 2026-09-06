<?php
/**
 * میزبان - Configuration Manager
 * ------------------------------------------------------------------
 * تنظیمات از دیتابیس (جدول settings) خوانده می‌شه، نه از فایل config.
 * پنل ادمین می‌تونه تنظیمات رو بدون تغییر فایل تغییر بده.
 *
 * اولویت: DB > config.php (DB برنده است)
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/interfaces.php';

class ConfigManager implements ConfigInterface {
    private PDO $db;
    private static ?array $cache = null;

    public function __construct() { $this->db = db(); }

    /**
     * گرفتن یک تنظیم از DB.
     * @param string $key کلید
     * @param mixed $default مقدار پیش‌فرض
     * @return mixed
     */
    public function get(string $key, $default = null) {
        $this->loadCache();
        return self::$cache[$key] ?? $default;
    }

    /**
     * تنظیم یک مقدار در DB.
     */
    public function set(string $key, $value): void {
        $v = is_scalar($value) ? (string)$value : json_encode($value);
        $this->db->prepare("
            INSERT INTO settings (skey, svalue, updated_at) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE svalue = ?, updated_at = NOW()
        ")->execute([$key, $v, $v]);
        self::$cache[$key] = $value;
    }

    /**
     * گرفتن همه تنظیمات.
     */
    public function all(): array {
        $this->loadCache();
        return self::$cache;
    }

    /**
     * گرفتن تنظیمات مرتبط با یک بخش.
     */
    public function getByPrefix(string $prefix): array {
        $this->loadCache();
        $result = [];
        foreach (self::$cache as $k => $v) {
            if (strpos($k, $prefix) === 0) $result[$k] = $v;
        }
        return $result;
    }

    private function loadCache(): void {
        if (self::$cache !== null) return;
        self::$cache = [];
        try {
            $rows = $this->db->query("SELECT skey, svalue FROM settings")->fetchAll();
            foreach ($rows as $r) {
                $val = $r['svalue'];
                // تلاش برای parse JSON
                $decoded = json_decode($val, true);
                self::$cache[$r['skey']] = ($decoded !== null) ? $decoded : $val;
            }
        } catch (Exception $e) {
            // جدول ممکنه هنوز ساخته نشده باشه
        }
    }

    /**
     * seed تنظیمات پیش‌فرض در DB (اگه خالی باشه).
     */
    public function seedDefaults(): void {
        $defaults = [
            'queue.sync_process' => QUEUE_SYNC_PROCESS ? '1' : '0',
            'queue.concurrency' => (string)QUEUE_CONCURRENCY,
            'queue.batch_size' => (string)QUEUE_BATCH_SIZE,
            'queue.retry_max' => (string)QUEUE_RETRY_MAX,
            'queue.retention_days' => (string)QUEUE_RETENTION_DAYS,
            'cb.failure_threshold' => (string)CB_FAILURE_THRESHOLD,
            'cb.cooldown_seconds' => (string)CB_COOLDOWN_SECONDS,
            'ack.enabled' => ACK_ENABLED ? '1' : '0',
            'security.hmac_enabled' => SECURITY_HMAC_ENABLED ? '1' : '0',
            'health.check_interval' => (string)HEALTH_CHECK_INTERVAL,
        ];
        foreach ($defaults as $k => $v) {
            $this->db->prepare("
                INSERT IGNORE INTO settings (skey, svalue, updated_at) VALUES (?, ?, NOW())
            ")->execute([$k, $v]);
        }
        self::$cache = null; // پاک کردن cache
    }
}

// پایان config_manager.php
