<?php
/**
 * میزبان - Cron Worker (هر ثانیه / هر دقیقه)
 * ------------------------------------------------------------------
 * این فایل صف رو پردازش می‌کنه. می‌تونه از cron هر دقیقه صدا زده بشه
 * یا در یک loop طولانی اجرا بشه.
 *
 * استفاده:
 *   php cron.php worker          — پردازش صف (batch)
 *   php cron.php worker --daemon — اجرای دائمی (loop)
 *
 * Cron در cPanel:
 *   * * * * * php /home/USER/public_html/cron.php worker
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/load_balancer.php';
require_once __DIR__ . '/key_pool.php';
require_once __DIR__ . '/providers.php';
require_once __DIR__ . '/provider_registry.php';
require_once __DIR__ . '/circuit_breaker.php';
require_once __DIR__ . '/response_manager.php';
require_once __DIR__ . '/config_manager.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/queue_cleaner.php';
require_once __DIR__ . '/scheduler.php';
require_once __DIR__ . '/worker.php';
require_once __DIR__ . '/dispatcher.php';
require_once __DIR__ . '/lease_manager.php';
require_once __DIR__ . '/event_bus.php';
require_once __DIR__ . '/queue_stats_service.php';
db_init();

$token = $argv[2] ?? $_GET['token'] ?? '';
$job = $argv[1] ?? 'worker';

// اعتبارسنجی token (اگه از HTTP صدا زده بشه) — توکن خالی هرگز مجاز نیست
if (php_sapi_name() !== 'cli') {
    if (!cron_token_ok($token)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

if ($job === 'worker') {
    $daemon = in_array('--daemon', $argv);
    $dispatcher = new Dispatcher();
    $leaseManager = new LeaseManager();
    $workerId = 'worker_' . gethostname() . '_' . getmypid();
    worker_register($workerId);

    // ترویج retry و delayed به queued (همیشه قبل از پردازش)
    $dispatcher->promotePending();

    // بازیابی leaseهای منقضی (workerهای crash شده)
    $leaseManager->recoverExpiredLeases();

    if ($daemon) {
        // حالت daemon - اجرای دائمی با Worker مستقل
        $worker = new Worker($workerId);
        while (true) {
            // ترویج retry و delayed + recovery lease (هر چرخه)
            $dispatcher->promotePending();
            $leaseManager->recoverExpiredLeases();
            $r = $worker->processOne();
            if (!$r['processed']) sleep(1);
        }
    } else {
        // حالت batch - WorkerPool پردازش می‌کنه
        $start = microtime(true);
        $dispatcher->promotePending();
        $leaseManager->recoverExpiredLeases();
        $results = $dispatcher->dispatchBatch(QUEUE_BATCH_SIZE);
        $duration = (int)((microtime(true) - $start) * 1000);
        $processed = count(array_filter($results, fn($r) => !empty($r['processed'])));
        cron_record_run('worker', $duration, $processed > 0 ? 'ok' : 'idle');
        worker_unregister($workerId);
        if (php_sapi_name() !== 'cli') {
            json_response(['ok' => true, 'processed' => $processed, 'duration_ms' => $duration, 'promoted' => true, 'recovered' => true]);
        } else {
            echo "Worker: processed={$processed}, duration={$duration}ms\n";
        }
    }
}

// Retry Scheduler — ترویج درخواست‌های retry/delayed به queued
// (هر ثانیه از cron صدا زده می‌شه تا درخواست‌های retry به‌موقع به queued برگردن)
// استفاده: php cron.php retry_scheduler
if ($job === 'retry_scheduler') {
    $start = microtime(true);
    $leaseManager = new LeaseManager();
    $qm = new QueueManager();
    // Recover expired leases (workerهای crash شده)
    $recovered = $leaseManager->recoverExpiredLeases();
    // Promote retry/delayed
    $promoted = $qm->promotePending();
    $duration = (int)((microtime(true) - $start) * 1000);
    cron_record_run('retry_scheduler', $duration, 'ok');
    if (php_sapi_name() !== 'cli') {
        json_response(['ok' => true, 'promoted' => $promoted, 'recovered' => $recovered, 'duration_ms' => $duration]);
    } else {
        $total = ($promoted['retry_promoted'] ?? 0) + ($promoted['delayed_promoted'] ?? 0);
        echo "RetryScheduler: promoted={$total}, recovered={$recovered}, duration={$duration}ms\n";
    }
}

// پردازش درخواست‌های retry شده (باید scheduled_at گذشته باشه)
if ($job === 'retry') {
    $start = microtime(true);
    $pdo = db();
    // انتقال retry → queued (اگه scheduled_at گذشته)
    $pdo->exec("UPDATE requests SET status = 'queued' WHERE status = 'retry' AND scheduled_at IS NOT NULL AND scheduled_at <= NOW()");
    $moved = $pdo->query("SELECT ROW_COUNT()")->fetchColumn();
    $duration = (int)((microtime(true) - $start) * 1000);
    cron_record_run('retry', $duration, 'ok');
    if (php_sapi_name() !== 'cli') {
        json_response(['ok' => true, 'moved_to_queue' => (int)$moved, 'duration_ms' => $duration]);
    } else {
        echo "Retry: moved={$moved} to queue, duration={$duration}ms\n";
    }
}

// Lease Recovery — بازیابی درخواست‌های گیر کرده (lease منقضی شده)
// (هر ثانیه از cron صدا زده می‌شه تا jobهای workerهای crash شده به صف برگردن)
// استفاده: php cron.php lease_recovery
if ($job === 'lease_recovery') {
    $start = microtime(true);
    $leaseManager = new LeaseManager();
    $recovered = $leaseManager->recoverExpiredLeases();
    $duration = (int)((microtime(true) - $start) * 1000);
    cron_record_run('lease_recovery', $duration, $recovered > 0 ? 'ok' : 'idle');
    if (php_sapi_name() !== 'cli') {
        json_response(['ok' => true, 'recovered' => $recovered, 'duration_ms' => $duration]);
    } else {
        echo "Lease recovery: recovered={$recovered}, duration={$duration}ms\n";
    }
}

// پاکسازی — از QueueCleaner class
if ($job === 'cleanup') {
    $start = microtime(true);
    $cleaner = new QueueCleaner();
    $result = $cleaner->cleanAll();
    $duration = (int)((microtime(true) - $start) * 1000);
    cron_record_run('cleanup', $duration, 'ok');
    if (php_sapi_name() !== 'cli') {
        json_response(['ok' => true, 'cleaned' => $result, 'duration_ms' => $duration]);
    } else {
        echo "Cleanup: " . json_encode($result) . ", duration={$duration}ms\n";
    }
}

// Health check همه ارائه‌دهنده‌ها
if ($job === 'health') {
    $start = microtime(true);
    $providers = provider_list();
    $checked = 0;
    foreach ($providers as $p) {
        if ($p['status'] == 1) {
            health_check_provider((int)$p['id']);
            $checked++;
        }
    }
    $duration = (int)((microtime(true) - $start) * 1000);
    cron_record_run('health', $duration, 'ok');
    if (php_sapi_name() !== 'cli') {
        json_response(['ok' => true, 'checked' => $checked, 'duration_ms' => $duration]);
    } else {
        echo "Health: checked={$checked} providers, duration={$duration}ms\n";
    }
}

// ثبت آمار تجمیعی — از MetricsService
if ($job === 'stats') {
    $start = microtime(true);
    $metrics = new MetricsService();
    $metrics->recordStatistics();
    $duration = (int)((microtime(true) - $start) * 1000);
    cron_record_run('stats', $duration, 'ok');
    if (php_sapi_name() !== 'cli') {
        json_response(['ok' => true, 'duration_ms' => $duration]);
    } else {
        echo "Stats: recorded, duration={$duration}ms\n";
    }
}

// پایان cron.php
