<?php
/**
 * میزبان - LeaseManager
 * ------------------------------------------------------------------
 * مدیریت leaseهای قابل تمدید برای ownership یک job توسط worker.
 *
 * مزایای lease-based ownership:
 *   - جلوگیری از دو worker که همزمان یک job رو پردازش می‌کنن
 *   - بازیابی خودکار jobهای workerهای crash شده
 *   - تمدید lease برای عملیات طولانی (HTTP streaming, multi-step)
 *   - token تصادفی برای جلوگیری از hijack
 *
 * جریان:
 *   Worker.acquireJob() → LeaseManager.grantLease(job, worker)
 *   Worker در حین اجرا → LeaseManager.renewLease() (هر 30 ثانیه)
 *   Worker در پایان → LeaseManager.releaseLease()
 *   Cron هر ثانیه → LeaseManager.recoverExpiredLeases()
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';

class LeaseManager {
    private PDO $db;

    public function __construct() { $this->db = db(); }

    /**
     * اعطای lease روی یک job به یک worker.
     * اگه job قبلاً lease فعال داره (توسط worker دیگه)، null برمی‌گرده.
     *
     * @param int $jobId شناسه job
     * @param string $workerId شناسه worker
     * @param int $durationSeconds مدت lease (پیش‌فرض از LEASE_DURATION)
     * @return string|null token lease یا null اگه lease اعطا نشد
     */
    public function grantLease(int $jobId, string $workerId, int $durationSeconds = 0): ?string {
        if ($durationSeconds <= 0) $durationSeconds = defined('LEASE_DURATION') ? LEASE_DURATION : 60;
        $token = bin2hex(random_bytes(16));
        $expiresAt = date('Y-m-d H:i:s', time() + $durationSeconds);
        $stmt = $this->db->prepare("
            UPDATE requests
            SET worker_id = ?, locked_at = NOW(), lease_token = ?, lease_until = ?
            WHERE id = ? AND (lease_until IS NULL OR lease_until < NOW())
        ");
        $stmt->execute([$workerId, $token, $expiresAt, $jobId]);
        if ($stmt->rowCount() > 0) return $token;
        return null;
    }

    /**
     * تمدید یک lease موجود — فقط با token معتبر.
     *
     * @param int $jobId شناسه job
     * @param string $token token lease (دارنده فعلی)
     * @param int $durationSeconds مدت جدید (پیش‌فرض از LEASE_DURATION)
     * @return bool آیا تمدید موفق بود
     */
    public function renewLease(int $jobId, string $token, int $durationSeconds = 0): bool {
        if ($durationSeconds <= 0) $durationSeconds = defined('LEASE_DURATION') ? LEASE_DURATION : 60;
        $expiresAt = date('Y-m-d H:i:s', time() + $durationSeconds);
        $stmt = $this->db->prepare("UPDATE requests SET lease_until = ? WHERE id = ? AND lease_token = ?");
        $stmt->execute([$expiresAt, $jobId, $token]);
        return $stmt->rowCount() > 0;
    }

    /**
     * آزادسازی lease — job تکمیل یا شکست خورده.
     * پاک کردن token و تاریخ انقضا.
     */
    public function releaseLease(int $jobId): void {
        $this->db->prepare("UPDATE requests SET lease_token = NULL, lease_until = NULL WHERE id = ?")
            ->execute([$jobId]);
    }

    /**
     * پیدا کردن jobهای با lease منقضی و برگرداندن به صف queued.
     * این کار برای workerهایی که crash شدن انجام می‌شه.
     *
     * @return int تعداد jobهای بازیابی شده
     */
    public function recoverExpiredLeases(): int {
        $this->db->exec("
            UPDATE requests
            SET status = 'queued', worker_id = NULL, locked_at = NULL,
                lease_token = NULL, lease_until = NULL, running_at = NULL
            WHERE status = 'running'
              AND lease_until IS NOT NULL
              AND lease_until < NOW()
        ");
        return (int)$this->db->query("SELECT ROW_COUNT()")->fetchColumn();
    }

    /**
     * بررسی اعتبار lease.
     * برای worker که می‌خواد مطمئن شه هنوز مالک job هست.
     *
     * @param int $jobId شناسه job
     * @param string $token token lease
     * @return bool آیا lease معتبر است
     */
    public function isValidLease(int $jobId, string $token): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM requests WHERE id = ? AND lease_token = ? AND lease_until > NOW()");
        $stmt->execute([$jobId, $token]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * گرفتن اطلاعات lease یک job.
     *
     * @return array|null اطلاعات lease یا null
     */
    public function getLeaseInfo(int $jobId): ?array {
        $stmt = $this->db->prepare("SELECT worker_id, lease_token, lease_until, locked_at FROM requests WHERE id = ?");
        $stmt->execute([$jobId]);
        $r = $stmt->fetch();
        return $r ?: null;
    }
}

// پایان lease_manager.php
