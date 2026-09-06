<?php
/**
 * میزبان - Queue Cleaner
 * ------------------------------------------------------------------
 * سرویس اختصاصی پاکسازی:
 *   - درخواست‌های قدیمی (completed/failed/dead) بعد از RETENTION_DAYS
 *   - retry های منقضی
 *   - nonce های منقضی
 *   - rate_limit های قدیمی
 *   - health_check های قدیمی
 *   - log های قدیمی
 *   - worker های مرده (heartbeat قدیمی)
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/interfaces.php';

class QueueCleaner implements QueueCleanerInterface {
    private PDO $db;

    public function __construct() { $this->db = db(); }

    /**
     * پاکسازی کامل — همه کارها.
     * @return array آمار پاکسازی
     */
    public function cleanAll(): array {
        $result = [];
        $result['old_requests'] = $this->cleanOldRequests();
        $result['expired_nonces'] = $this->cleanExpiredNonces();
        $result['old_rate_limits'] = $this->cleanOldRateLimits();
        $result['old_health_checks'] = $this->cleanOldHealthChecks();
        $result['old_logs'] = $this->cleanOldLogs();
        $result['dead_workers'] = $this->cleanDeadWorkers();
        $result['expired_cooldowns'] = $this->resetExpiredCooldowns();
        return $result;
    }

    /**
     * حذف درخواست‌های قدیمی (completed/failed/dead).
     */
    public function cleanOldRequests(): int {
        $cutoff = date('Y-m-d H:i:s', time() - QUEUE_RETENTION_DAYS * 86400);
        $stmt = $this->db->prepare("DELETE FROM requests WHERE status IN ('completed','failed','dead') AND created_at < ?");
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    /**
     * حذف nonce های منقضی (replay attack protection).
     */
    public function cleanExpiredNonces(): int {
        $stmt = $this->db->prepare("DELETE FROM nonces WHERE created_at < DATE_SUB(NOW(), INTERVAL ? HOUR)");
        $stmt->execute([SECURITY_NONCE_TTL_HOURS]);
        return $stmt->rowCount();
    }

    /**
     * حذف rate_limit های قدیمی.
     */
    public function cleanOldRateLimits(): int {
        $stmt = $this->db->exec("DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 40 DAY)");
        return $stmt;
    }

    /**
     * حذف health_check های قدیمی (۳۰ روز).
     */
    public function cleanOldHealthChecks(): int {
        return $this->db->exec("DELETE FROM health_checks WHERE checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
    }

    /**
     * حذف log های قدیمی (بر اساس فایل + DB).
     */
    public function cleanOldLogs(): int {
        // DB logs: نگه‌داری ۷ روز
        $count = $this->db->exec("DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        // فایل لاگ: چرخش اگه بزرگ شده
        if (file_exists(LOG_PATH) && filesize(LOG_PATH) > LOG_MAX_SIZE) {
            @rename(LOG_PATH, LOG_PATH . '.old');
        }
        return $count;
    }

    /**
     * حذف worker های مرده (heartbeat قدیمی‌تر از ۵ دقیقه).
     */
    public function cleanDeadWorkers(): int {
        return $this->db->exec("DELETE FROM worker_status WHERE last_heartbeat < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
    }

    /**
     * بازنشانی cooldown های منقضی‌شده.
     */
    public function resetExpiredCooldowns(): int {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("UPDATE api_keys SET cooldown_until = NULL WHERE cooldown_until IS NOT NULL AND cooldown_until < ?");
        $stmt->execute([$now]);
        return $stmt->rowCount();
    }

    /**
     * پاکسازی آمار قدیمی (statistics).
     */
    public function cleanOldStatistics(): int {
        return $this->db->exec("DELETE FROM statistics WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }
}

// پایان queue_cleaner.php
