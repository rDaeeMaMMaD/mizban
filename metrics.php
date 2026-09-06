<?php
/**
 * میزبان - Metrics Service
 * ------------------------------------------------------------------
 * جمع‌آوری و ارائه metrics برای Load Balancer و داشبورد:
 *   - avg_response_time (ms)
 *   - avg_queue_time (ms)
 *   - total_requests
 *   - error_rate (%)
 *   - throughput (req/sec)
 *   - requests_per_minute/hour/day
 *
 * خوراک اصلی Load Balancer است.
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/interfaces.php';

class MetricsService implements MetricsInterface {
    private PDO $db;

    public function __construct() { $this->db = db(); }

    /**
     * Metrics کلی سیستم.
     */
    public function getSystemMetrics(): array {
        // avg response time (1 ساعت گذشته)
        $avgResponse = (float)$this->db->query("
            SELECT AVG(latency_ms) FROM requests
            WHERE status = 'completed' AND latency_ms IS NOT NULL
              AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")->fetchColumn();

        // avg queue time
        $avgQueue = (float)$this->db->query("
            SELECT AVG(queue_time_ms) FROM requests
            WHERE status = 'completed' AND queue_time_ms IS NOT NULL
              AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")->fetchColumn();

        // avg total time
        $avgTotal = (float)$this->db->query("
            SELECT AVG(total_time_ms) FROM requests
            WHERE status = 'completed' AND total_time_ms IS NOT NULL
              AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")->fetchColumn();

        // total requests (1 ساعت گذشته)
        $totalReq = (int)$this->db->query("
            SELECT COUNT(*) FROM requests WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")->fetchColumn();

        // error count
        $errorCount = (int)$this->db->query("
            SELECT COUNT(*) FROM requests
            WHERE status IN ('failed','dead') AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")->fetchColumn();

        // throughput (req/sec در ۵ دقیقه گذشته)
        $recent5min = (int)$this->db->query("
            SELECT COUNT(*) FROM requests WHERE created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ")->fetchColumn();
        $throughput = round($recent5min / 300, 2);

        // success rate
        $successCount = (int)$this->db->query("
            SELECT COUNT(*) FROM requests
            WHERE status = 'completed' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")->fetchColumn();
        $successRate = $totalReq > 0 ? round($successCount / $totalReq * 100, 2) : 100;

        // active workers
        $activeWorkers = (int)$this->db->query("
            SELECT COUNT(*) FROM worker_status
            WHERE status = 'working' AND last_heartbeat > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ")->fetchColumn();
        $idleWorkers = (int)$this->db->query("
            SELECT COUNT(*) FROM worker_status
            WHERE status = 'idle' AND last_heartbeat > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ")->fetchColumn();

        // active API keys (کلیدهای فعال که در cooldown نیستن)
        $activeKeys = (int)$this->db->query("
            SELECT COUNT(*) FROM api_keys
            WHERE status = 1 AND (cooldown_until IS NULL OR cooldown_until < NOW())
        ")->fetchColumn();

        // requests per minute (آخرین ۱۰ دقیقه)
        $rpmRows = $this->db->query("
            SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i') as minute, COUNT(*) as c
            FROM requests
            WHERE created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
            GROUP BY minute ORDER BY minute DESC LIMIT 10
        ")->fetchAll();

        return [
            'avg_response_time_ms' => round($avgResponse),
            'avg_queue_time_ms' => round($avgQueue),
            'avg_total_time_ms' => round($avgTotal),
            'total_requests_hour' => $totalReq,
            'error_count_hour' => $errorCount,
            'success_count_hour' => $successCount,
            'error_rate' => $totalReq > 0 ? round($errorCount / $totalReq * 100, 2) : 0,
            'success_rate' => $successRate,
            'throughput_rps' => $throughput,
            'active_workers' => $activeWorkers,
            'idle_workers' => $idleWorkers,
            'total_workers' => $activeWorkers + $idleWorkers,
            'active_api_keys' => $activeKeys,
            'requests_per_minute' => array_reverse($rpmRows),
        ];
    }

    /**
     * Metrics یک ارائه‌دهنده خاص.
     */
    public function getProviderMetrics(int $providerId): array {
        $p = $this->db->prepare("SELECT * FROM providers WHERE id = ?");
        $p->execute([$providerId]);
        $provider = $p->fetch();
        if (!$provider) return [];

        // مدل‌های این provider
        $models = $this->db->prepare("SELECT model_id FROM models WHERE provider_id = ? AND status = 1");
        $models->execute([$providerId]);
        $modelIds = $models->fetchAll(PDO::FETCH_COLUMN);

        if (empty($modelIds)) return ['provider_id' => $providerId, 'name' => $provider['name']];

        $placeholders = implode(',', array_fill(0, count($modelIds), '?'));

        // avg latency
        $stmt = $this->db->prepare("SELECT AVG(latency_ms) FROM requests WHERE model IN ($placeholders) AND status = 'completed' AND latency_ms IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->execute($modelIds);
        $avgLat = round((float)$stmt->fetchColumn());

        // total requests
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM requests WHERE model IN ($placeholders) AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->execute($modelIds);
        $total = (int)$stmt->fetchColumn();

        // errors
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM requests WHERE model IN ($placeholders) AND status IN ('failed','dead') AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
        $stmt->execute($modelIds);
        $errors = (int)$stmt->fetchColumn();

        // active
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM requests WHERE model IN ($placeholders) AND status IN ('queued','running','locked')");
        $stmt->execute($modelIds);
        $active = (int)$stmt->fetchColumn();

        return [
            'provider_id' => $providerId,
            'name' => $provider['name'],
            'slug' => $provider['slug'],
            'avg_response_time_ms' => $avgLat,
            'total_requests_hour' => $total,
            'error_count_hour' => $errors,
            'error_rate' => $total > 0 ? round($errors / $total * 100, 2) : 0,
            'active_requests' => $active,
            'health_score' => (int)$provider['health_score'],
            'circuit_state' => $provider['circuit_state'],
        ];
    }

    /**
     * Metrics یک API Key خاص.
     */
    public function getKeyMetrics(int $keyId): array {
        $stmt = $this->db->prepare("SELECT * FROM api_keys WHERE id = ?");
        $stmt->execute([$keyId]);
        $k = $stmt->fetch();
        if (!$k) return [];
        return [
            'key_id' => $keyId,
            'label' => $k['label'],
            'status' => (int)$k['status'],
            'request_count' => (int)$k['request_count'],
            'error_count' => (int)$k['error_count'],
            'consecutive_errors' => (int)$k['consecutive_errors'],
            'active_requests' => (int)$k['active_requests'],
            'avg_latency_ms' => (int)$k['avg_latency_ms'],
            'cooldown_until' => $k['cooldown_until'],
        ];
    }

    /**
     * ثبت آمار تجمیعی در جدول statistics.
     */
    public function recordStatistics(): void {
        $metrics = $this->getSystemMetrics();
        $now = date('Y-m-d H:i:00');
        $stmt = $this->db->prepare("
            INSERT INTO statistics (stat_key, stat_value, period, recorded_at)
            VALUES (?, ?, '5min', ?)
            ON DUPLICATE KEY UPDATE stat_value = ?
        ");
        foreach ($metrics as $key => $value) {
            if (is_numeric($value)) {
                $stmt->execute(['sys_' . $key, $value, $now, $value]);
            }
        }
        // ثبت آمار هر provider
        $providers = $this->db->query("SELECT id FROM providers WHERE status = 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($providers as $pid) {
            $pm = $this->getProviderMetrics((int)$pid);
            foreach (['avg_response_time_ms', 'total_requests_hour', 'error_rate', 'active_requests', 'health_score'] as $k) {
                if (isset($pm[$k])) {
                    $stmt->execute(['provider_' . $pm['slug'] . '_' . $k, $pm[$k], $now, $pm[$k]]);
                }
            }
        }
    }
}

// پایان metrics.php
