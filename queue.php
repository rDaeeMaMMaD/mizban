<?php
/**
 * میزبان - Multi-Level Queue Manager
 * ------------------------------------------------------------------
 * صف‌های مجزا:
 *   Waiting  — در انتظار worker (priority-sorted)
 *   Delayed  — زمان‌بندی شده برای آینده
 *   Running  — در دست worker (locked)
 *   Retry    — منتظر backoff
 *   Failed   — ناموفق دائمی
 *   Dead     — تمام تلاش‌ها شکست خورده
 *
 * هر worker با FOR UPDATE SKIP LOCKED یک job می‌گیره تا
 * دو worker همزمان یک job رو بردارن.
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/interfaces.php';

class QueueManager implements QueueInterface {
    private PDO $db;

    public function __construct() { $this->db = db(); }

    /**
     * افزودن به صف Waiting.
     */
    public function enqueue(int $clientId, string $mode, string $model, string $action,
                            string $payload, int $priority, ?string $callbackUrl,
                            ?string $idempotencyKey, ?string $correlationId,
                            int $streamMode = 0, ?string $scheduledAt = null): int {
        $status = $scheduledAt ? 'delayed' : 'queued';
        $maxAttempts = DEAD_QUEUE_MAX_ATTEMPTS;
        $stmt = $this->db->prepare("
            INSERT INTO requests (client_id, mode, model, action, payload, priority, status,
                                  callback_url, idempotency_key, correlation_id, max_attempts,
                                  stream_mode, scheduled_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$clientId, $mode, $model, $action, $payload, $priority, $status,
                        $callbackUrl, $idempotencyKey, $correlationId, $maxAttempts,
                        $streamMode, $scheduledAt]);
        $id = (int)$this->db->lastInsertId();
        $this->updatePosition($id, $priority);
        return $id;
    }

    /**
     * محاسبه موقعیت در صف.
     */
    private function updatePosition(int $id, int $priority): void {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM requests
            WHERE status = 'queued'
              AND (priority > ? OR (priority = ? AND created_at <= (SELECT created_at FROM requests WHERE id = ?)))
        ");
        $stmt->execute([$priority, $priority, $id]);
        $pos = (int)$stmt->fetchColumn();
        $this->db->prepare("UPDATE requests SET queue_position = ? WHERE id = ?")->execute([$pos, $id]);
    }

    /**
     * گرفتن یک job از صف Waiting به صورت atomic.
     * از FOR UPDATE SKIP LOCKED استفاده می‌کنه تا چند worker همزمان
     * یک job رو بردارن.
     *
     * @param string $workerId شناسه worker
     * @return array|null job یا null
     */
    public function acquireJob(string $workerId): ?array {
        $this->db->beginTransaction();
        try {
            // FOR UPDATE — بدون SKIP LOCKED (سازگار با MySQL 5.7 و MariaDB)
            $stmt = $this->db->prepare("
                SELECT * FROM requests
                WHERE status = 'queued'
                  AND attempts <= max_attempts
                ORDER BY priority DESC, created_at ASC
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute();
            $job = $stmt->fetch();
            if (!$job) {
                $this->db->commit();
                return null;
            }
            $this->db->prepare("
                UPDATE requests
                SET status = 'running', worker_id = ?, running_at = NOW(),
                    locked_at = NOW(),
                    queue_time_ms = TIMESTAMPDIFF(MICROSECOND, created_at, NOW()) DIV 1000
                WHERE id = ? AND status = 'queued'
            ")->execute([$workerId, $job['id']]);
            $this->db->commit();
            return $job;
        } catch (Exception $e) {
            $this->db->rollBack();
            return null;
        }
    }

    /**
     * گرفتن یک job خاص با ID (برای sync mode).
     */
    public function acquireJobById(int $id, string $workerId): ?array {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare("SELECT * FROM requests WHERE id = ? FOR UPDATE");
            $stmt->execute([$id]);
            $job = $stmt->fetch();
            if (!$job) { $this->db->commit(); return null; }
            $upd = $this->db->prepare("
                UPDATE requests
                SET status = 'running', worker_id = ?, running_at = NOW(), locked_at = NOW(),
                    queue_time_ms = TIMESTAMPDIFF(MICROSECOND, created_at, NOW()) DIV 1000
                WHERE id = ? AND status IN ('queued', 'retry')
            ");
            $upd->execute([$workerId, $id]);
            $this->db->commit();
            $stmt = $this->db->prepare("SELECT * FROM requests WHERE id = ?");
            $stmt->execute([$id]);
            return $stmt->fetch() ?: null;
        } catch (Exception $e) {
            $this->db->rollBack();
            return null;
        }
    }

    /**
     * تکمیل موفق job.
     */
    public function complete(int $id, string $response, string $provider, int $keyId,
                             ?string $fallbackUsed, int $latencyMs, int $totalMs): void {
        $this->db->prepare("
            UPDATE requests
            SET status = 'completed', response = ?, provider_slug = ?, api_key_id = ?,
                fallback_used = ?, latency_ms = ?, total_time_ms = ?, processed_at = NOW()
            WHERE id = ?
        ")->execute([$response, $provider, $keyId, $fallbackUsed, $latencyMs, $totalMs, $id]);
        // بروزرسانی آمار provider
        $this->db->prepare("UPDATE providers SET total_requests = total_requests + 1 WHERE slug = ?")->execute([$provider]);
    }

    /**
     * انتقال به صف Retry با exponential backoff.
     */
    public function moveToRetry(int $id, string $error, string $errorType): void {
        $stmt = $this->db->prepare("SELECT attempts FROM requests WHERE id = ?");
        $stmt->execute([$id]);
        $attempts = (int)$stmt->fetchColumn() + 1;
        $delay = min(RETRY_MAX_DELAY, (int)(RETRY_BASE_DELAY * pow(2, $attempts - 1)));
        $scheduledAt = date('Y-m-d H:i:s', time() + $delay);
        $this->db->prepare("
            UPDATE requests SET status = 'retry', error = ?, error_type = ?, attempts = ?,
                scheduled_at = ?, worker_id = NULL, locked_at = NULL, running_at = NULL
            WHERE id = ?
        ")->execute([$error, $errorType, $attempts, $scheduledAt, $id]);
    }

    /**
     * انتقال به Dead Queue (تمام تلاش‌ها شکست خورده).
     */
    public function moveToDead(int $id, string $error, string $errorType): void {
        $stmt = $this->db->prepare("SELECT attempts FROM requests WHERE id = ?");
        $stmt->execute([$id]);
        $attempts = (int)$stmt->fetchColumn() + 1;
        $this->db->prepare("
            UPDATE requests SET status = 'dead', error = ?, error_type = ?, attempts = ?,
                processed_at = NOW(), worker_id = NULL, locked_at = NULL, running_at = NULL
            WHERE id = ?
        ")->execute([$error, $errorType, $attempts, $id]);
    }

    /**
     * انتقال به Failed (خطای غیرقابل retry).
     */
    public function moveToFailed(int $id, string $error, string $errorType): void {
        $stmt = $this->db->prepare("SELECT attempts FROM requests WHERE id = ?");
        $stmt->execute([$id]);
        $attempts = (int)$stmt->fetchColumn() + 1;
        $this->db->prepare("
            UPDATE requests SET status = 'failed', error = ?, error_type = ?, attempts = ?,
                processed_at = NOW(), worker_id = NULL, locked_at = NULL, running_at = NULL
            WHERE id = ?
        ")->execute([$error, $errorType, $attempts, $id]);
    }

    /**
     * انتقال درخواست‌های Retry و Delayed که scheduled_at گذشته به Waiting.
     */
    public function promotePending(): array {
        $this->db->exec("
            UPDATE requests SET status = 'queued', scheduled_at = NULL
            WHERE status = 'retry' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()
        ");
        $retry = (int)$this->db->query("SELECT ROW_COUNT()")->fetchColumn();
        $this->db->exec("
            UPDATE requests SET status = 'queued', scheduled_at = NULL
            WHERE status = 'delayed' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()
        ");
        $delayed = (int)$this->db->query("SELECT ROW_COUNT()")->fetchColumn();
        return ['retry_promoted' => $retry, 'delayed_promoted' => $delayed];
    }

    /**
     * @deprecated از promotePending استفاده کنید
     */
    public function promoteRetryToWaiting(): int {
        $this->db->exec("
            UPDATE requests SET status = 'queued', scheduled_at = NULL
            WHERE status = 'retry' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()
        ");
        return (int)$this->db->query("SELECT ROW_COUNT()")->fetchColumn();
    }

    /**
     * @deprecated از promotePending استفاده کنید
     */
    public function promoteDelayedToWaiting(): int {
        $this->db->exec("
            UPDATE requests SET status = 'queued', scheduled_at = NULL
            WHERE status = 'delayed' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()
        ");
        return (int)$this->db->query("SELECT ROW_COUNT()")->fetchColumn();
    }

    /**
     * تعداد درخواست‌ها در هر صف.
     */
    public function getQueueBreakdown(): array {
        $rows = $this->db->query("SELECT status, COUNT(*) as c FROM requests GROUP BY status")->fetchAll();
        $breakdown = ['waiting' => 0, 'delayed' => 0, 'running' => 0, 'retry' => 0, 'failed' => 0, 'dead' => 0, 'completed' => 0];
        foreach ($rows as $r) {
            $status = $r['status'];
            if ($status === 'queued') $breakdown['waiting'] = (int)$r['c'];
            elseif (isset($breakdown[$status])) $breakdown[$status] = (int)$r['c'];
        }
        return $breakdown;
    }

    /**
     * بازیابی درخواست‌های گیر کرده — RUNNING jobs با heartbeat منقضی.
     * این درخواست‌ها احتمالاً متعلق به workerهایی هستن که crash شدن.
     * برمی‌گردونن به queued تا worker دیگه‌ای ببرتشون.
     *
     * @param int $timeoutSeconds مدت زمانی که بدون heartbeat بوده
     * @return int تعداد درخواست‌های بازیابی شده
     */
    public function recoverStuckJobs(int $timeoutSeconds = 120): int {
        $this->db->exec("
            UPDATE requests
            SET status = 'queued', worker_id = NULL, locked_at = NULL, running_at = NULL
            WHERE status = 'running'
              AND locked_at IS NOT NULL
              AND locked_at < DATE_SUB(NOW(), INTERVAL {$timeoutSeconds} SECOND)
        ");
        return (int)$this->db->query("SELECT ROW_COUNT()")->fetchColumn();
    }

    /**
     * محاسبه ETA بر اساس موقعیت و میانگین latency.
     */
    public function estimateTime(int $position): int {
        $avgLat = (int)$this->db->query("SELECT AVG(latency_ms) FROM requests WHERE status = 'completed' AND latency_ms IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();
        if (!$avgLat) $avgLat = 2000;
        return (int)(($position / max(1, WORKER_CONCURRENCY)) * $avgLat);
    }
}

// پایان queue.php
