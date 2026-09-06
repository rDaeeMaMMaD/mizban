<?php
/**
 * میزبان - EventBus
 * ------------------------------------------------------------------
 * یک Event Bus داخلی ساده برای ارتباط decoupled بین سرویس‌ها.
 *
 * مزایا:
 *   - کاهش coupling بین کلاس‌ها
 *   - قابلیت اضافه کردن listener بدون تغییر کد publisher
 *   - لاگ‌گذاری مرکزی همه رویدادها
 *   - قابلیت hook کردن برای metrics، alerting، analytics
 *
 * نکته: این event bus در همان process اجرا می‌شه (in-process).
 * برای cross-process eventing از Kafka/Redis/RabbitMQ استفاده کنید.
 *
 * استفاده:
 *   EventBus::on(Events::WORKER_COMPLETED, function($data) {
 *       // update metrics
 *   });
 *   EventBus::emit(Events::WORKER_COMPLETED, ['job_id' => 123, 'latency_ms' => 450]);
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }

class EventBus {
    /** @var array<string, callable[]> */
    private static array $listeners = [];

    /**
     * ثبت یک listener برای یک event.
     *
     * @param string $event نام رویداد (از کلاس Events)
     * @param callable $handler تابع پردازشگر — function(array $data): void
     */
    public static function on(string $event, callable $handler): void {
        if (!isset(self::$listeners[$event])) self::$listeners[$event] = [];
        self::$listeners[$event][] = $handler;
    }

    /**
     * emit یک رویداد — همه listenerهای ثبت‌شده صدا زده می‌شن.
     * اگه یک listener استثنا پرتاب کنه، بقیه باز هم اجرا می‌شن.
     *
     * @param string $event نام رویداد
     * @param array $data داده‌های رویداد
     */
    public static function emit(string $event, array $data = []): void {
        if (isset(self::$listeners[$event])) {
            foreach (self::$listeners[$event] as $handler) {
                try { $handler($data); } catch (Exception $e) {
                    // اگه log_error در دسترس نیست، فقط ادامه بده
                    if (function_exists('log_error')) {
                        log_error("EventBus error for {$event}: " . $e->getMessage());
                    }
                }
            }
        }
        // لاگ‌گذاری همه رویدادها برای audit trail
        if (function_exists('log_info')) {
            log_info("Event: {$event}", $data);
        }
    }

    /**
     * پاک کردن همه listenerها (برای تست).
     */
    public static function clear(): void {
        self::$listeners = [];
    }

    /**
     * تعداد listenerهای ثبت‌شده برای یک event.
     */
    public static function listenerCount(string $event): int {
        return isset(self::$listeners[$event]) ? count(self::$listeners[$event]) : 0;
    }
}

/**
 * تعریف نام همه رویدادهای سیستم به عنوان constant.
 * استفاده از constant از typo جلوگیری می‌کنه.
 */
class Events {
    const REQUEST_QUEUED     = 'request.queued';
    const JOB_ASSIGNED       = 'job.assigned';
    const PROVIDER_SELECTED  = 'provider.selected';
    const WORKER_STARTED     = 'worker.started';
    const WORKER_COMPLETED   = 'worker.completed';
    const WORKER_FAILED      = 'worker.failed';
    const RETRY_SCHEDULED    = 'retry.scheduled';
    const DEAD_LETTER        = 'dead.letter';
    const METRICS_UPDATED    = 'metrics.updated';
    const LEASE_EXPIRED      = 'lease.expired';
    const LEASE_GRANTED      = 'lease.granted';
    const LEASE_RENEWED      = 'lease.renewed';
    const LEASE_RELEASED     = 'lease.released';
    const CIRCUIT_OPEN       = 'circuit.open';
    const CIRCUIT_CLOSE      = 'circuit.close';
    const CIRCUIT_HALF_OPEN  = 'circuit.half_open';
}

// پایان event_bus.php
