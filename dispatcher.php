<?php
/**
 * میزبان - Dispatcher
 * ------------------------------------------------------------------
 * Dispatcher مهم‌ترین بخش سیستم است. تصمیم می‌گیره:
 *
 *   ۱. کدام Worker آزاد است
 *   ۲. کدام صف اولویت دارد (Waiting > Delayed > Retry)
 *   ۳. کدام Provider انتخاب شود (via LoadBalancer)
 *   ۴. کدام API Key انتخاب شود (via KeyPool)
 *
 * جریان:
 *
 *   Incoming Request
 *       ↓
 *   [Dispatcher] → ACK فوری
 *       ↓
 *   [QueueManager] → ثبت در صف
 *       ↓
 *   [Scheduler] → مسیریابی (provider + key + driver)
 *       ↓
 *   [Worker] → اجرای driver
 *       ↓
 *   [Response] → sync/callback
 *
 * Dispatcher هم برای sync mode (همون لحظه پردازش)
 * و هم برای async mode (cron worker) استفاده می‌شه.
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/scheduler.php';
require_once __DIR__ . '/worker.php';
require_once __DIR__ . '/providers.php';
require_once __DIR__ . '/lease_manager.php';
require_once __DIR__ . '/event_bus.php';

/**
 * Dispatcher (Enterprise — lightweight)
 * ------------------------------------------------------------------
 * مسئولیت‌های Dispatcher:
 *   ۱. دریافت درخواست از API
 *   ۲. اعتبارسنجی فیلدهای اساسی
 *   ۳. ثبت در QueueManager
 *   ۴. برگرداندن ACK فوری
 *   ۵. (برای sync mode) dispatch یک worker روی یک request_id
 *   ۶. (برای async mode) dispatch batch از صف با WorkerPool
 *   ۷. ترویج retry/delayed به queued
 *
 * منطق کسب‌وکار (failover، lease، retry policy) در Worker و QueueManager است.
 * Dispatcher فقط هماهنگ‌کننده است.
 */
class Dispatcher {
    private QueueManager $queue;
    private Scheduler $scheduler;

    public function __construct() {
        $this->queue = new QueueManager();
        $this->scheduler = new Scheduler();
    }

    /**
     * دریافت درخواست + ثبت در صف + ACK فوری.
     * این متد lightweight است — فقط validation + enqueue + ACK.
     *
     * @return array ACK data (includes request_id, queue_position, estimated_time_ms)
     */
    public function receive(array $client, string $mode, string $model, string $action,
                            array $payload, int $priority, ?string $callbackUrl,
                            ?string $idempotencyKey, ?string $correlationId,
                            int $streamMode = 0, ?string $scheduledAt = null): array {
        // ثبت در صف
        $id = $this->queue->enqueue(
            (int)$client['id'], $mode, $model, $action,
            json_encode($payload, JSON_UNESCAPED_UNICODE), $priority,
            $callbackUrl, $idempotencyKey, $correlationId, $streamMode, $scheduledAt
        );

        // محاسبه موقعیت و ETA
        $position = $this->getQueuePosition($id, $priority);
        $eta = $this->queue->estimateTime($position);

        $ack = [
            'ack' => true,
            'request_id' => $id,
            'status' => $scheduledAt ? 'delayed' : 'queued',
            'queue_position' => $position,
            'estimated_time_ms' => $eta,
            'message' => function_exists('t') ? t('api.queuedMsg') : 'درخواست شما ثبت شد و در صف است. منتظر پاسخ باشید.',
        ];

        // emit رویداد
        EventBus::emit(Events::REQUEST_QUEUED, [
            'request_id' => $id, 'client_id' => $client['id'],
            'mode' => $mode, 'model' => $model, 'priority' => $priority,
            'callback_url' => $callbackUrl, 'correlation_id' => $correlationId,
            'status' => $ack['status'], 'queue_position' => $position,
        ]);

        // ارسال ACK به callback
        if ($callbackUrl) {
            send_ack($callbackUrl, build_ack($id, $ack['status'], $ack['message'], [
                'mode' => $mode, 'priority' => $priority,
                'correlation_id' => $correlationId,
                'queue_position' => $position,
                'estimated_time_ms' => $eta,
            ]));
        }

        return ['id' => $id, 'position' => $position, 'eta' => $eta, 'ack' => $ack];
    }

    /**
     * پردازش sync — همون لحظه با یک worker.
     */
    public function dispatchSync(int $requestId): array {
        $worker = new Worker('sync_' . getmypid());
        worker_register($worker->getId());
        EventBus::emit(Events::JOB_ASSIGNED, ['job_id' => $requestId, 'worker_id' => $worker->getId(), 'mode' => 'sync']);
        $result = $worker->processById($requestId);
        worker_unregister($worker->getId());
        return $result;
    }

    /**
     * پردازش async — batch از صف با WorkerPool.
     */
    public function dispatchBatch(int $batchSize = 0): array {
        return WorkerPool::processBatch($batchSize);
    }

    /**
     * ترویج درخواست‌های retry و delayed به waiting.
     */
    public function promotePending(): array {
        $retry = $this->queue->promoteRetryToWaiting();
        $delayed = $this->queue->promoteDelayedToWaiting();
        return ['retry_promoted' => $retry, 'delayed_promoted' => $delayed];
    }

    private function getQueuePosition(int $id, int $priority): int {
        $stmt = db()->prepare("
            SELECT COUNT(*) FROM requests
            WHERE status IN ('queued', 'running', 'locked')
              AND (priority > ? OR (priority = ? AND created_at <= (SELECT created_at FROM requests WHERE id = ?)))
        ");
        $stmt->execute([$priority, $priority, $id]);
        return (int)$stmt->fetchColumn();
    }
}

// پایان dispatcher.php
