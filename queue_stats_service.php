<?php
/**
 * میزبان - QueueStatsService
 * ------------------------------------------------------------------
 * سرویس اختصاصی برای محاسبه آمار صف.
 *
 * مسئولیت‌ها:
 *   - شمارش per-state (queued/running/completed/failed/retry/dead/delayed)
 *   - محاسبه میانگین زمان صف و پردازش (1 ساعت اخیر)
 *   - محاسبه P50/P95/P99 latency
 *   - شمارش workerهای زنده و مشغول
 *   - محاسبه throughput (درخواست/دقیقه)
 *   - محاسبه نرخ retry و نرخ شکست
 *
 * جدا کردن از core.php (که تابع queue_stats/queue_detailed_stats دارد)
 * تا responsibility دقیق‌تر بشه و قابل test شدن.
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';

class QueueStatsService {
    private PDO $db;

    public function __construct() { $this->db = db(); }

    /**
     * شمارش تعداد درخواست‌ها در هر state.
     *
     * @return array کلید: state name، مقدار: count
     */
    public function getBreakdown(): array {
        $rows = $this->db->query("SELECT status, COUNT(*) as c FROM requests GROUP BY status")->fetchAll();
        $result = [
            'queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0,
            'retry' => 0, 'dead' => 0, 'delayed' => 0,
        ];
        foreach ($rows as $r) {
            $status = $r['status'];
            if (isset($result[$status])) $result[$status] = (int)$r['c'];
        }
        $result['total'] = array_sum($result);
        return $result;
    }

    /**
     * آمار تفصیلی — شامل percentile، throughput، worker health.
     *
     * @return array همه metrics
     */
    public function getDetailedStats(): array {
        $breakdown = $this->getBreakdown();

        // میانگین زمان صف و پردازش (آخرین ۱ ساعت)
        $avgQueue = (int)$this->db->query("SELECT COALESCE(AVG(queue_time_ms), 0) FROM requests WHERE status = 'completed' AND queue_time_ms IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();
        $avgProcess = (int)$this->db->query("SELECT COALESCE(AVG(total_time_ms), 0) FROM requests WHERE status = 'completed' AND total_time_ms IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();

        // P50, P95, P99 latency (آخرین ۱ ساعت)
        $p50 = $this->getPercentile('total_time_ms', 50);
        $p95 = $this->getPercentile('total_time_ms', 95);
        $p99 = $this->getPercentile('total_time_ms', 99);

        // Workerهای زنده (heartbeat < 30s) و مشغول
        $workersAlive = (int)$this->db->query("SELECT COUNT(*) FROM worker_status WHERE last_heartbeat > DATE_SUB(NOW(), INTERVAL 30 SECOND)")->fetchColumn();
        $workersBusy = (int)$this->db->query("SELECT COUNT(*) FROM worker_status WHERE status = 'working' AND last_heartbeat > DATE_SUB(NOW(), INTERVAL 30 SECOND)")->fetchColumn();

        // Throughput (تکمیل‌شده در دقیقه اخیر)
        $throughput = (int)$this->db->query("SELECT COUNT(*) FROM requests WHERE status = 'completed' AND processed_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)")->fetchColumn();

        // نرخ retry و نرخ شکست (نسبت به کل finished)
        $totalFinished = $breakdown['completed'] + $breakdown['failed'] + $breakdown['dead'];
        $retryRate = $totalFinished > 0 ? round(($breakdown['retry'] / $totalFinished) * 100, 2) : 0;
        $failureRate = $totalFinished > 0 ? round(($breakdown['failed'] / $totalFinished) * 100, 2) : 0;

        return array_merge($breakdown, [
            'avg_queue_time_ms'      => $avgQueue,
            'avg_processing_time_ms' => $avgProcess,
            'p50_latency_ms'         => $p50,
            'p95_latency_ms'         => $p95,
            'p99_latency_ms'         => $p99,
            'workers_alive'          => $workersAlive,
            'workers_busy'           => $workersBusy,
            'throughput_per_minute'  => $throughput,
            'retry_rate'             => $retryRate,
            'failure_rate'           => $failureRate,
        ]);
    }

    /**
     * محاسبه percentile یک column (مثلاً total_time_ms).
     * از sort + index استفاده می‌کنه (برای حجم پایین مناسب است).
     *
     * @param string $column نام ستون (مثلاً total_time_ms)
     * @param int $percentile percentile مورد نظر (0-100)
     * @return int مقدار percentile یا 0
     */
    private function getPercentile(string $column, int $percentile): int {
        try {
            // فقط columnهای مجاز — جلوگیری از SQL injection
            $allowed = ['total_time_ms', 'queue_time_ms', 'latency_ms'];
            if (!in_array($column, $allowed, true)) return 0;

            $sql = "SELECT COALESCE({$column}, 0) FROM requests WHERE status = 'completed' AND {$column} IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) ORDER BY {$column}";
            $rows = $this->db->query($sql)->fetchAll(PDO::FETCH_COLUMN);
            if (empty($rows)) return 0;
            $idx = (int)floor(count($rows) * $percentile / 100);
            if ($idx >= count($rows)) $idx = count($rows) - 1;
            if ($idx < 0) $idx = 0;
            return (int)$rows[$idx];
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * آمار همه workerها — برای dashboard.
     *
     * @return array لیست workerها با آخرین heartbeat
     */
    public function getWorkerStats(): array {
        return $this->db->query("SELECT * FROM worker_status ORDER BY last_heartbeat DESC")->fetchAll();
    }

    /**
     * محاسبه throughput در بازه‌های مختلف (1m, 5m, 15m, 1h).
     *
     * @return array throughput برای هر بازه
     */
    public function getThroughputWindows(): array {
        $windows = [];
        foreach (['1m' => 1, '5m' => 5, '15m' => 15, '1h' => 60] as $label => $minutes) {
            $count = (int)$this->db->query("SELECT COUNT(*) FROM requests WHERE status = 'completed' AND processed_at > DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)")->fetchColumn();
            $windows[$label] = $count;
        }
        return $windows;
    }
}

// پایان queue_stats_service.php
