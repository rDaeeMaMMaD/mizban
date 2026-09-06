<?php
/**
 * میزبان - Smart Load Balancer
 * ------------------------------------------------------------------
 * امتیاز هر ارائه‌دهنده بر اساس چند معیار محاسبه می‌شه:
 *
 *   Score = (health_score × 0.30)
 *         + (latency_score × 0.20)
 *         + (load_score × 0.20)
 *         + (error_score × 0.15)
 *         + (availability_score × 0.15)
 *
 *   health_score:     0-100 از health check
 *   latency_score:    هرچه کمتر بهتر (برعکس می‌شه)
 *   load_score:       هرچه active_requests کمتر بهتر
 *   error_score:      هرچه failure rate کمتر بهتر
 *   availability_score: circuit breaker state + key availability
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/interfaces.php';

class LoadBalancer implements LoadBalancerInterface {
    private PDO $db;
    private MetricsInterface $metrics;

    /**
     * Dependency Injection: MetricsService خوراک LoadBalancer است.
     */
    public function __construct(?MetricsInterface $metrics = null) {
        $this->db = db();
        $this->metrics = $metrics ?? new MetricsService();
    }

    /**
     * محاسبه composite score برای یک ارائه‌دهنده.
     * از MetricsService برای داده‌های زنده استفاده می‌کنه.
     * @return array ['score' => 0-100, 'details' => [...]]
     */
    public function computeScore(array $provider): array {
        // گرفتن metrics زنده از MetricsService
        $liveMetrics = $this->metrics->getProviderMetrics((int)$provider['id']);

        $health = (int)($liveMetrics['health_score'] ?? $provider['health_score'] ?? 100);

        // Latency score: 0ms=100, 5000ms=0
        $avgLat = (int)($liveMetrics['avg_response_time_ms'] ?? $provider['avg_latency_ms'] ?? 0);
        $latencyScore = max(0, 100 - ($avgLat / 50));

        // Load score: از active_requests زنده
        $active = (int)($liveMetrics['active_requests'] ?? $provider['active_requests'] ?? 0);
        $loadScore = max(0, 100 - ($active * 2));

        // Error score: از error_rate زنده
        $errorRate = (float)($liveMetrics['error_rate'] ?? 0);
        $errorScore = max(0, 100 - ($errorRate * 2));

        // Availability score: circuit breaker + key availability
        $cbState = $liveMetrics['circuit_state'] ?? $provider['circuit_state'] ?? 'closed';
        $availScore = 100;
        if ($cbState === 'open') $availScore = 0;
        elseif ($cbState === 'half-open') $availScore = 50;

        // Throughput bonus: throughput بالاتر = امتیاز بیشتر (تا حدی)
        $throughputBonus = 0; // می‌تونه از metrics بیاد

        $composite = ($health * 0.30) + ($latencyScore * 0.20) + ($loadScore * 0.20)
                   + ($errorScore * 0.15) + ($availScore * 0.15) + $throughputBonus;

        return [
            'score' => round(min(100, $composite)),
            'details' => [
                'health' => $health,
                'latency' => round($latencyScore),
                'load' => round($loadScore),
                'error' => round($errorScore),
                'availability' => round($availScore),
                'active_requests' => $active,
                'avg_latency_ms' => $avgLat,
                'error_rate' => $errorRate,
            ],
        ];
    }

    /**
     * انتخاب بهترین ارائه‌دهنده برای حالت General.
     * الگوریتم:
     *   ۱. فقط ارائه‌دهنده‌های فعال با circuit != open
     *   ۲. گروه‌بندی بر اساس priority (کمتر=اول)
     *   ۳. در هر گروه، بالاترین composite score
     *
     * @return array|null ['provider' => row, 'model' => model_id, 'score' => n]
     */
    public function selectProvider(array $excludedIds = []): ?array {
        $exclStr = '';
        $params = [];
        if (!empty($excludedIds)) {
            $exclStr = ' AND id NOT IN (' . implode(',', array_fill(0, count($excludedIds), '?')) . ')';
            $params = $excludedIds;
        }
        $stmt = $this->db->prepare("
            SELECT * FROM providers
            WHERE status = 1 AND circuit_state != 'open'{$exclStr}
            ORDER BY priority ASC, sort_order ASC
        ");
        $stmt->execute($params);
        $providers = $stmt->fetchAll();
        if (!$providers) return null;

        // گروه‌بندی بر اساس priority
        $byPriority = [];
        foreach ($providers as $p) {
            $byPriority[(int)$p['priority']][] = $p;
        }
        ksort($byPriority);

        // در هر گروه، بالاترین score
        foreach ($byPriority as $pri => $group) {
            $best = null;
            $bestScore = -1;
            foreach ($group as $p) {
                $scoreInfo = $this->computeScore($p);
                if ($scoreInfo['score'] > $bestScore) {
                    $bestScore = $scoreInfo['score'];
                    $best = $p;
                }
            }
            if ($best) {
                // پیدا کردن مدل پیش‌فرض
                $modelId = $this->getDefaultModel((int)$best['id']);
                if (!$modelId) {
                    $m = $this->db->prepare("SELECT model_id FROM models WHERE provider_id = ? AND status = 1 LIMIT 1");
                    $m->execute([$best['id']]);
                    $modelId = $m->fetchColumn() ?: null;
                }
                if ($modelId) {
                    return ['provider' => $best, 'model' => $modelId, 'score' => $bestScore];
                }
            }
        }
        return null;
    }

    private function getDefaultModel(int $providerId): ?string {
        $stmt = $this->db->prepare("SELECT model_id FROM models WHERE provider_id = ? AND is_default = 1 AND status = 1 LIMIT 1");
        $stmt->execute([$providerId]);
        $r = $stmt->fetchColumn();
        return $r ?: null;
    }
}

// پایان load_balancer.php
