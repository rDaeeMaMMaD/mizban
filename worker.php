<?php
/**
 * میزبان - Worker Pool
 * ------------------------------------------------------------------
 * هر Worker یک process مستقل است که:
 *   ۱. Job از QueueManager می‌گیره (FOR UPDATE SKIP LOCKED)
 *   ۲. Scheduler مسیریابی می‌کنه (provider + key + driver)
 *   ۳. Driver اجرا می‌کنه
 *   ۴. نتیجه رو در QueueManager ثبت می‌کنه
 *   ۵. خودش رو آزاد می‌کنه و job بعدی رو می‌گیره
 *
 * Failover (Model → Key → Provider):
 *   هر job با چندین تلاش پردازش می‌شه:
 *     - هر تلاش: یک (model, key, driver) از Scheduler
 *     - اگه کلید fail شد: recordFailure + cooldown → تلاش بعدی
 *     - Scheduler خودش کلید بعدی رو می‌ده (چون قبلی در cooldown است)
 *     - اگه همه کلیدهای یک مدل fail شدند: Scheduler → fallback model
 *     - اگه همه fallback ها fail شدند: Scheduler → general mode (provider دیگر)
 *     - هرگز loop روی همه providers×keys×models نمی‌کنه
 *
 * WorkerPool چند worker رو مدیریت می‌کنه:
 *   - worker_register / worker_heartbeat / worker_unregister
 *   - پردازش batch با همزمانی
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/interfaces.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/load_balancer.php';
require_once __DIR__ . '/key_pool.php';
require_once __DIR__ . '/providers.php';
require_once __DIR__ . '/provider_registry.php';
require_once __DIR__ . '/circuit_breaker.php';
require_once __DIR__ . '/response_manager.php';
require_once __DIR__ . '/scheduler.php';
require_once __DIR__ . '/lease_manager.php';
require_once __DIR__ . '/event_bus.php';

/**
 * Worker Stateless — هیچ state داخلی نداره.
 * همه چیز در Database یا Queue است.
 * هر job با یک transaction کامل پردازش می‌شه.
 */
class Worker {
    private string $id;
    private QueueInterface $queue;
    private SchedulerInterface $scheduler;
    private ResponseManagerInterface $responseMgr;
    private CircuitBreakerInterface $cb;
    private KeyPoolInterface $keyPool;
    private LeaseManager $leaseManager;
    private PDO $db;

    /**
     * Dependency Injection — همه از خارج تزریق می‌شن.
     */
    public function __construct(
        ?string $id = null,
        ?QueueInterface $queue = null,
        ?SchedulerInterface $scheduler = null,
        ?ResponseManagerInterface $responseMgr = null,
        ?CircuitBreakerInterface $cb = null,
        ?KeyPoolInterface $keyPool = null
    ) {
        $this->id = $id ?: ('worker_' . gethostname() . '_' . getmypid() . '_' . substr(uniqid(), -4));
        $this->queue = $queue ?? new QueueManager();
        $this->scheduler = $scheduler ?? new Scheduler();
        $this->responseMgr = $responseMgr ?? new ResponseManager();
        $this->cb = $cb ?? new CircuitBreaker();
        $this->keyPool = $keyPool ?? new KeyPool();
        $this->leaseManager = new LeaseManager();
        $this->db = db();
    }

    public function getId(): string { return $this->id; }

    /**
     * پردازش یک job از صف.
     * جریان: acquireJob → grantLease → executeJob → releaseLease
     *
     * @return array ['processed' => bool, 'id' => n, 'status' => str, ...]
     */
    public function processOne(): array {
        worker_heartbeat($this->id, 'idle');
        $job = $this->queue->acquireJob($this->id);
        if (!$job) return ['processed' => false, 'reason' => 'empty'];

        // اعطای lease — اگه worker دیگه‌ای job رو گرفته، رد کن
        $leaseToken = $this->leaseManager->grantLease((int)$job['id'], $this->id);
        if (!$leaseToken) {
            // worker دیگه‌ای job رو گرفته — این worker رد می‌شه
            return ['processed' => false, 'reason' => 'lease_denied'];
        }

        EventBus::emit(Events::WORKER_STARTED, ['worker_id' => $this->id, 'job_id' => $job['id']]);
        return $this->executeJob($job, $leaseToken);
    }

    /**
     * پردازش یک job خاص با ID (برای sync mode).
     */
    public function processById(int $id): array {
        $job = $this->queue->acquireJobById($id, $this->id);
        if (!$job) return ['processed' => false, 'reason' => 'not_found'];

        $leaseToken = $this->leaseManager->grantLease((int)$job['id'], $this->id);
        if (!$leaseToken) {
            return ['processed' => false, 'reason' => 'lease_denied'];
        }

        EventBus::emit(Events::WORKER_STARTED, ['worker_id' => $this->id, 'job_id' => $job['id']]);
        return $this->executeJob($job, $leaseToken);
    }

    /**
     * اجرای یک job با Failover کامل (Model → Key → Provider):
     *
     *   for attempt in 0..MAX_FAILOVERS:
     *     route = scheduler.route(model, mode, visited)
     *     if !route.ok: break
     *     result = driver.execute(action, payload)
     *     if result.ok: save + return success
     *     else:
     *       recordFailure(key)  → key در cooldown
     *       visited[] = currentModel  → scheduler در تلاش بعدی
     *                                   کلید بعدی یا fallback model می‌ده
     *
     * الگوریتم NOT nested loops over all providers×keys×models.
     * هر تلاش فقط یک مسیر رو امتحان می‌کنه. Scheduler تصمیم می‌گیره.
     */
    private function executeJob(array $job, string $leaseToken = ''): array {
        worker_heartbeat($this->id, 'working', (int)$job['id']);

        $mode = $job['mode'] ?? 'specific';
        $payload = json_decode($job['payload'] ?: '{}', true) ?: [];
        $action = $job['action'] ?? 'chat';
        $totalStart = microtime(true);

        $visited = [];          // مدل‌های امتحان شده (برای جلوگیری از loop)
        $excludedProviders = []; // provider های fail شده (برای general mode)
        $providerFailuresRecorded = []; // CB هر provider فقط یک‌بار در هر درخواست شمارش شود (B1)
        $lastError = '';
        $lastHttpCode = 0;
        $maxAttempts = FAILOVER_MAX_PROVIDERS + 1; // حداکثر تعداد تلاش

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            // مسیریابی: model + key + driver از Scheduler
            $route = $this->scheduler->route($job['model'], $mode, $visited, $excludedProviders);
            if (!$route['ok']) {
                // هیچ مسیر دیگری موجود نیست
                break;
            }

            $currentModel = $route['model'];
            $currentProviderId = (int)$route['provider']['id'];
            $currentKeyId = $route['key_id'];
            $currentFallbackUsed = $route['fallback_used'];

            EventBus::emit(Events::PROVIDER_SELECTED, [
                'job_id' => $job['id'], 'provider' => $route['provider']['slug'],
                'model' => $currentModel, 'attempt' => $attempt + 1,
            ]);

            // ثبت مدل فعلی در visited (برای جلوگیری از loop)
            if (!in_array($currentModel, $visited)) {
                $visited[] = $currentModel;
            }

            // افزایش active_requests (در داخل transaction کوتاه)
            try {
                $this->keyPool->incrementActive($currentKeyId);
                $this->db->prepare("UPDATE providers SET active_requests = active_requests + 1 WHERE id = ?")
                    ->execute([$currentProviderId]);
            } catch (Exception $e) {
                log_error("Worker {$this->id} - increment active error: " . $e->getMessage());
            }

            // تمدید lease قبل از HTTP call (مدت طولانی‌تر برای عملیات شبکه)
            if ($leaseToken) {
                $this->leaseManager->renewLease((int)$job['id'], $leaseToken, 120);
            }

            // اجرای Driver (HTTP call) — با try/finally تا شمارنده active همیشه کم شود
            // حتی اگر driver استثنا بدهد (جلوگیری از نشتی active_requests — B3)
            try {
                $result = $route['driver']->execute($action, $payload);
            } finally {
                $this->db->prepare("UPDATE providers SET active_requests = GREATEST(0, active_requests - 1) WHERE id = ?")
                    ->execute([$currentProviderId]);
                $this->db->prepare("UPDATE api_keys SET active_requests = GREATEST(0, active_requests - 1) WHERE id = ?")
                    ->execute([$currentKeyId]);
            }

            if ($result['ok']) {
                // ===== موفقیت =====
                $totalMs = (int)((microtime(true) - $totalStart) * 1000);
                $latency = $totalMs;

                $this->db->beginTransaction();
                try {
                    $this->queue->complete(
                        (int)$job['id'], $result['response'], $route['provider']['slug'],
                        $currentKeyId, $currentFallbackUsed, $latency, $totalMs
                    );
                    $this->keyPool->recordSuccess($currentKeyId, $latency);
                    $this->cb->recordProviderSuccess($currentProviderId);
                    $this->cb->recordKeySuccess($currentKeyId);
                    $this->db->commit();
                } catch (Exception $e) {
                    $this->db->rollBack();
                    log_error("Worker {$this->id} - commit error: " . $e->getMessage());
                }

                // آزادسازی lease (job تکمیل شد)
                $this->leaseManager->releaseLease((int)$job['id']);

                EventBus::emit(Events::WORKER_COMPLETED, [
                    'worker_id' => $this->id, 'job_id' => $job['id'],
                    'provider' => $route['provider']['slug'], 'latency_ms' => $latency,
                    'total_time_ms' => $totalMs, 'attempts' => $attempt + 1,
                ]);

                // تحویل نتیجه به کلاینت via ResponseManager
                $this->responseMgr->deliverResult($job, [
                    'status' => 'completed',
                    'provider' => $route['provider']['slug'],
                    'fallback' => $currentFallbackUsed,
                    'latency_ms' => $latency,
                    'total_time_ms' => $totalMs,
                    'response' => $result['response'],
                    'worker_id' => $this->id,
                ]);

                log_info("Worker {$this->id} - درخواست #{$job['id']} تکمیل شد", [
                    'model' => $currentModel, 'provider' => $route['provider']['slug'],
                    'latency_ms' => $latency, 'total_ms' => $totalMs,
                    'fallback' => $currentFallbackUsed, 'attempts' => $attempt + 1,
                ]);
                worker_heartbeat($this->id, 'idle');
                return ['processed' => true, 'id' => (int)$job['id'], 'status' => 'completed',
                        'provider' => $route['provider']['slug'], 'fallback' => $currentFallbackUsed,
                        'latency_ms' => $latency, 'total_time_ms' => $totalMs, 'worker_id' => $this->id];
            }

            // ===== شکست — record + ادامه failover =====
            $lastError = $result['error'];
            $lastHttpCode = $result['http_code'] ?? 0;

            // کلید همیشه cooldown می‌شود (هرگز disable نمی‌شه)
            $this->keyPool->recordFailure($currentKeyId);
            $this->cb->recordKeyFailure($currentKeyId);
            $this->db->prepare("UPDATE providers SET total_errors = total_errors + 1 WHERE id = ?")
                ->execute([$currentProviderId]);

            // Circuit breaker: هر provider فقط یک‌بار در هر درخواست شمارش می‌شود تا
            // یک درخواست تنها نتواند CB را باز کند (B1). سپس provider از این درخواست کنار می‌رود.
            if (!in_array($currentProviderId, $providerFailuresRecorded, true)) {
                $this->cb->recordProviderFailure($currentProviderId);
                $providerFailuresRecorded[] = $currentProviderId;
            }
            if (!in_array($currentProviderId, $excludedProviders, true)) {
                $excludedProviders[] = $currentProviderId;
            }

            log_warn("Worker {$this->id} - کلید #{$currentKeyId} ({$route['provider']['slug']}/{$currentModel}) شکست خورد: {$lastError}. تلاش با مسیر بعدی (Model→Key→Provider).", [
                'http_code' => $lastHttpCode, 'attempt' => $attempt + 1,
            ]);

            // تمدید lease برای تلاش بعدی
            if ($leaseToken) {
                $this->leaseManager->renewLease((int)$job['id'], $leaseToken);
            }

            // Loop continues → scheduler در تلاش بعدی کلید بعدی یا fallback model می‌ده
            // NO auto-disable! keys only cooldown.
        }

        // تمام مسیرهای failover ناموفق بودن
        // آزادسازی lease قبل از return (job به retry/dead/failed می‌ره)
        if ($leaseToken) {
            $this->leaseManager->releaseLease((int)$job['id']);
        }
        return $this->handleFailure($job, $lastError ?: mtr('err.allFailoverFailed'), $lastHttpCode, $totalStart);
    }

    /**
     * مدیریت شکست — Dead / Retry / Failed بر اساس نوع خطا و تعداد تلاش.
     * Worker فقط نتیجه رو برمی‌گردونه؛ QueueManager تغییر وضعیت رو انجام می‌ده.
     *
     *   attempts >= maxAttempts      → Dead Letter Queue
     *   errType.retryable === true   → Retry با exponential backoff
     *   errType.retryable === false  → Failed (دائمی)
     */
    private function handleFailure(array $job, string $error, int $httpCode, float $totalStart, ?array $route = null): array {
        $errType = classify_error($error, $httpCode);
        $attempts = (int)$job['attempts'] + 1;
        $maxAttempts = (int)($job['max_attempts'] ?: DEAD_QUEUE_MAX_ATTEMPTS);

        if ($attempts >= $maxAttempts) {
            // Dead Letter Queue — تمام تلاش‌ها شکست خورده
            $this->queue->moveToDead((int)$job['id'], $error, $errType['type']);
            EventBus::emit(Events::DEAD_LETTER, [
                'job_id' => $job['id'], 'error' => $error, 'error_type' => $errType['type'],
                'attempts' => $attempts,
            ]);
            $this->responseMgr->deliverResult($job, [
                'status' => 'dead', 'error' => $error, 'error_type' => $errType['type'],
                'worker_id' => $this->id,
            ]);
            log_error("Worker {$this->id} - درخواست #{$job['id']} dead letter: {$error}", ['attempts' => $attempts, 'type' => $errType['type']]);
            worker_heartbeat($this->id, 'idle');
            return ['processed' => true, 'id' => (int)$job['id'], 'status' => 'dead', 'error' => $error, 'error_type' => $errType['type'], 'worker_id' => $this->id];
        } elseif ($errType['retryable']) {
            // Retry با exponential backoff
            $this->queue->moveToRetry((int)$job['id'], $error, $errType['type']);
            EventBus::emit(Events::RETRY_SCHEDULED, [
                'job_id' => $job['id'], 'error' => $error, 'error_type' => $errType['type'],
                'attempts' => $attempts, 'max_attempts' => $maxAttempts,
            ]);
            log_warn("Worker {$this->id} - درخواست #{$job['id']} retry ({$attempts}/{$maxAttempts}): {$error}", ['type' => $errType['type']]);
            worker_heartbeat($this->id, 'idle');
            return ['processed' => true, 'id' => (int)$job['id'], 'status' => 'retry', 'error' => $error, 'error_type' => $errType['type'], 'worker_id' => $this->id];
        } else {
            // Failed (غیرقابل retry)
            $this->queue->moveToFailed((int)$job['id'], $error, $errType['type']);
            EventBus::emit(Events::WORKER_FAILED, [
                'job_id' => $job['id'], 'error' => $error, 'error_type' => $errType['type'],
            ]);
            $this->responseMgr->deliverResult($job, [
                'status' => 'failed', 'error' => $error, 'error_type' => $errType['type'],
                'worker_id' => $this->id,
            ]);
            log_error("Worker {$this->id} - درخواست #{$job['id']} failed: {$error}", ['type' => $errType['type']]);
            worker_heartbeat($this->id, 'idle');
            return ['processed' => true, 'id' => (int)$job['id'], 'status' => 'failed', 'error' => $error, 'error_type' => $errType['type'], 'worker_id' => $this->id];
        }
    }
}

/**
 * Worker Pool — مدیریت چند worker.
 */
class WorkerPool {
    /**
     * پردازش batch با N worker همزمان.
     * در PHP بدون async، این به صورت متوالی اجرا می‌شه ولی
     * با curl_multi می‌تونه همزمان باشه (در آینده).
     * فعلاً cron هر دقیقه چند بار اجرا می‌شه و هر بار batch رو پردازش می‌کنه.
     */
    public static function processBatch(int $batchSize = 0): array {
        $batchSize = $batchSize > 0 ? $batchSize : QUEUE_BATCH_SIZE;
        $results = [];
        for ($i = 0; $i < $batchSize; $i++) {
            $worker = new Worker();
            $r = $worker->processOne();
            $results[] = $r;
            if (!$r['processed']) break;
        }
        return $results;
    }

    /**
     * اجرای یک worker در حالت daemon (loop دائمی).
     */
    public static function runDaemon(string $workerId): void {
        $worker = new Worker($workerId);
        worker_register($workerId);
        while (true) {
            $r = $worker->processOne();
            if (!$r['processed']) sleep(1);
        }
    }
}

// پایان worker.php
