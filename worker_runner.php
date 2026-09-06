<?php
/**
 * میزبان - Worker Runner (Real Multi-Process Execution)
 * ------------------------------------------------------------------
 * این فایل یک Worker واقعی است که به صورت پردازه مستقل اجرا می‌شه.
 * برای پردازش همزمان، چندین نسخه از این فایل رو اجرا کنید:
 *
 *   php worker_runner.php                    — یک batch پردازش و خروج
 *   php worker_runner.php --daemon           — اجرای دائمی (loop)
 *   php worker_runner.php --daemon --id=w1   — با شناسه مشخص
 *
 * در cPanel با cron هر دقیقه چند بار اجرا می‌شه:
 *   * * * * * php /home/USER/public_html/worker_runner.php
 *   * * * * * sleep 15 && php /home/USER/public_html/worker_runner.php
 *   * * * * * sleep 30 && php /home/USER/public_html/worker_runner.php
 *   * * * * * sleep 45 && php /home/USER/public_html/worker_runner.php
 *
 * یا با daemon mode:
 *   nohup php worker_runner.php --daemon --id=w1 &
 *   nohup php worker_runner.php --daemon --id=w2 &
 *   nohup php worker_runner.php --daemon --id=w3 &
 *
 * FOR UPDATE SKIP LOCKED تضمین می‌کنه که دو worker
 * همزمان یک job رو بردارن.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/interfaces.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/load_balancer.php';
require_once __DIR__ . '/key_pool.php';
require_once __DIR__ . '/providers.php';
require_once __DIR__ . '/provider_registry.php';
require_once __DIR__ . '/circuit_breaker.php';
require_once __DIR__ . '/response_manager.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/queue_cleaner.php';
require_once __DIR__ . '/scheduler.php';
require_once __DIR__ . '/worker.php';

db_init();

// اجرا از HTTP فقط با توکن cron مجاز است (worker یک عملیات سنگین است)
if (php_sapi_name() !== 'cli') {
    $tok = (string)($_GET['token'] ?? '');
    if (!cron_token_ok($tok)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

// پارامترهای خط فرمان
$daemon = in_array('--daemon', $argv);
$workerIdArg = null;
foreach ($argv as $a) {
    if (strpos($a, '--id=') === 0) $workerIdArg = substr($a, 5);
}
$workerId = $workerIdArg ?: ('w_' . gethostname() . '_' . getmypid());

// ثبت worker
worker_register($workerId);
log_info("Worker {$workerId} started (daemon=" . ($daemon ? 'yes' : 'no') . ")");

// ساخت Worker با DI
$worker = new Worker($workerId);

if ($daemon) {
    // ===== حالت Daemon — اجرای دائمی =====
    $idleCount = 0;
    while (true) {
        // heartbeat
        worker_heartbeat($workerId, 'idle');

        // ترویج retry و delayed به waiting
        $queue = new QueueManager();
        $queue->promoteRetryToWaiting();
        $queue->promoteDelayedToWaiting();

        // پردازش یک job
        $result = $worker->processOne();

        if ($result['processed']) {
            $idleCount = 0;
            // بلافاصله job بعدی رو بگیر (بدون sleep)
        } else {
            $idleCount++;
            // اگه صف خالی بود، تأخیر افزایشی
            $sleepTime = min(5, 1 + (int)($idleCount / 10));
            sleep($sleepTime);
        }
    }
} else {
    // ===== حالت Batch — یک بار اجرا =====
    $start = microtime(true);
    $processed = 0;

    // ترویج retry و delayed
    $queue = new QueueManager();
    $queue->promoteRetryToWaiting();
    $queue->promoteDelayedToWaiting();

    // پردازش تا QUEUE_BATCH_SIZE job
    for ($i = 0; $i < QUEUE_BATCH_SIZE; $i++) {
        $result = $worker->processOne();
        if (!$result['processed']) break;
        $processed++;
    }

    $duration = (int)((microtime(true) - $start) * 1000);

    // heartbeat نهایی
    worker_heartbeat($workerId, 'idle');

    // ثبت آمار cron
    cron_record_run('worker', $duration, $processed > 0 ? 'ok' : 'idle');

    // خروج
    log_info("Worker {$workerId} finished: processed={$processed}, duration={$duration}ms");

    // در حالت CLI، خروجی چاپ کن
    if (php_sapi_name() === 'cli') {
        echo json_encode([
            'worker_id' => $workerId,
            'processed' => $processed,
            'duration_ms' => $duration,
            'timestamp' => date('c'),
        ]) . "\n";
    } else {
        // در حالت HTTP (cron via curl)
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => true,
            'worker_id' => $workerId,
            'processed' => $processed,
            'duration_ms' => $duration,
        ]);
    }
}

// پایان worker_runner.php
