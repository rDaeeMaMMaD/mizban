<?php
/**
 * میزبان - API Key Pool
 * ------------------------------------------------------------------
 * استراتژی‌های انتخاب کلید:
 *   least_busy: کمترین active_requests
 *   round_robin: نوبت چرخشی
 *   weighted: بر اساس weight کلید
 *
 * هر کلید:
 *   - active_requests: درخواست‌های در حال پردازش
 *   - avg_latency_ms: میانگین زمان پاسخ
 *   - consecutive_errors: خطاهای متوالی
 *   - cooldown_until: زمان خنک‌سازی
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/interfaces.php';

class KeyPool implements KeyPoolInterface {
    private PDO $db;

    public function __construct() { $this->db = db(); }

    /**
     * انتخاب بهترین کلید برای یک ارائه‌دهنده.
     * هر کلید وضعیت مستقل داره: open | busy | cooldown | 429 | disabled
     *
     * @param int $providerId
     * @param string $strategy least_busy|round_robin|weighted|health
     * @return array|null ['key_id' => n, 'api_key' => decrypted, 'key_row' => row, 'state' => str]
     */
    public function selectKey(int $providerId, string $strategy = 'least_busy'): ?array {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->db->prepare("
            SELECT * FROM api_keys
            WHERE provider_id = ? AND status = 1
              AND (cooldown_until IS NULL OR cooldown_until < ?)
            ORDER BY consecutive_errors ASC, request_count ASC
        ");
        $stmt->execute([$providerId, $now]);
        $keys = $stmt->fetchAll();
        if (!$keys) return null;

        // فیلتر کردن کلیدهای در حالت disabled یا 429
        $availableKeys = array_filter($keys, function($k) {
            $state = $this->getKeyState($k);
            return $state !== 'disabled' && $state !== '429';
        });
        if (empty($availableKeys)) return null;

        $selected = null;
        switch ($strategy) {
            case 'round_robin':
                $selected = $this->selectRoundRobin(array_values($availableKeys), $providerId);
                break;
            case 'weighted':
                $selected = $this->selectWeighted(array_values($availableKeys));
                break;
            case 'health':
                $selected = $this->selectHealthBased(array_values($availableKeys));
                break;
            case 'least_busy':
            default:
                $selected = $this->selectLeastBusy(array_values($availableKeys));
                break;
        }

        if (!$selected) return null;

        $apiKey = decrypt_key($selected['key_encrypted']);
        if (!$apiKey) return null;

        return [
            'key_id' => (int)$selected['id'],
            'api_key' => $apiKey,
            'key_row' => $selected,
            'state' => $this->getKeyState($selected),
        ];
    }

    /**
     * تشخیص وضعیت یک کلید.
     * @return string open|busy|cooldown|429|disabled
     */
    private function getKeyState(array $key): string {
        if ((int)$key['status'] === 0) return 'disabled';
        if (!empty($key['cooldown_until']) && strtotime($key['cooldown_until']) > time()) {
            // اگه consecutive_errors بالا باشه و timeout اخیر → 429
            if ((int)$key['consecutive_errors'] >= 3) return '429';
            return 'cooldown';
        }
        if ((int)$key['consecutive_errors'] >= KEY_MAX_CONSECUTIVE_ERRORS) return 'disabled';
        if ((int)$key['active_requests'] > 0) return 'busy';
        return 'open';
    }

    /**
     * Health-based: انتخاب بر اساس کمترین error_rate و کمترین latency.
     */
    private function selectHealthBased(array $keys): ?array {
        usort($keys, function($a, $b) {
            $aScore = (int)$a['consecutive_errors'] * 10 + (int)$a['avg_latency_ms'] / 100;
            $bScore = (int)$b['consecutive_errors'] * 10 + (int)$b['avg_latency_ms'] / 100;
            return $aScore <=> $bScore;
        });
        return $keys[0] ?? null;
    }

    /**
     * Least Busy: کمترین active_requests، سپس کمترین request_count.
     */
    private function selectLeastBusy(array $keys): ?array {
        usort($keys, function($a, $b) {
            $aActive = (int)$a['active_requests'];
            $bActive = (int)$b['active_requests'];
            if ($aActive !== $bActive) return $aActive - $bActive;
            $aErr = (int)$a['consecutive_errors'];
            $bErr = (int)$b['consecutive_errors'];
            if ($aErr !== $bErr) return $aErr - $bErr;
            return (int)$a['request_count'] - (int)$b['request_count'];
        });
        return $keys[0] ?? null;
    }

    /**
     * Round Robin: نوبت چرخشی بر اساس last_used_at.
     */
    private function selectRoundRobin(array $keys, int $providerId): ?array {
        usort($keys, function($a, $b) {
            $aTime = $a['last_used_at'] ? strtotime($a['last_used_at']) : 0;
            $bTime = $b['last_used_at'] ? strtotime($b['last_used_at']) : 0;
            return $aTime - $bTime;
        });
        return $keys[0] ?? null;
    }

    /**
     * Weighted: انتخاب تصادفی با احتمال متناسب با weight.
     */
    private function selectWeighted(array $keys): ?array {
        $totalWeight = 0;
        foreach ($keys as $k) $totalWeight += max(1, (int)$k['weight']);
        $rand = mt_rand(1, $totalWeight);
        $cumulative = 0;
        foreach ($keys as $k) {
            $cumulative += max(1, (int)$k['weight']);
            if ($rand <= $cumulative) return $k;
        }
        return $keys[0] ?? null;
    }

    /**
     * ثبت موفقیت یک کلید.
     */
    public function recordSuccess(int $keyId, int $latencyMs): void {
        $this->db->prepare("
            UPDATE api_keys
            SET request_count = request_count + 1, consecutive_errors = 0,
                last_used_at = NOW(), cooldown_until = NULL,
                active_requests = GREATEST(0, active_requests - 1),
                avg_latency_ms = (avg_latency_ms + ?) DIV 2
            WHERE id = ?
        ")->execute([$latencyMs, $keyId]);
    }

    /**
     * ثبت شکست یک کلید (قرار در cooldown).
     */
    public function recordFailure(int $keyId): void {
        $this->db->prepare("
            UPDATE api_keys
            SET request_count = request_count + 1, error_count = error_count + 1,
                consecutive_errors = consecutive_errors + 1,
                last_used_at = NOW(),
                active_requests = GREATEST(0, active_requests - 1),
                cooldown_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
            WHERE id = ?
        ")->execute([KEY_COOLDOWN_SECONDS, $keyId]);
    }

    /**
     * افزایش شمارنده active_requests (وقتی worker شروع به پردازش می‌کنه).
     */
    public function incrementActive(int $keyId): void {
        $this->db->prepare("UPDATE api_keys SET active_requests = active_requests + 1 WHERE id = ?")->execute([$keyId]);
        $this->db->prepare("UPDATE api_keys SET last_used_at = NOW() WHERE id = ?")->execute([$keyId]);
    }
}

// پایان key_pool.php
