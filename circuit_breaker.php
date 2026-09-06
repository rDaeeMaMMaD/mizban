<?php
/**
 * میزبان - Circuit Breaker (State Machine)
 * ------------------------------------------------------------------
 * سه حالت:
 *   Closed    → عادی، درخواست‌ها ارسال می‌شن
 *   Open      → قطع، درخواست‌ها فوراً به fallback می‌رن (هیچ تلاشی نمی‌شه)
 *   HalfOpen  → تست، چند درخواست تست ارسال می‌شه
 *
 *过渡:
 *   Closed → Open:      بعد از N خطای متوالی
 *   Open → HalfOpen:    بعد از cooldown
 *   HalfOpen → Closed:  بعد از موفقیت
 *   HalfOpen → Open:    بعد از شکست مجدد
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/interfaces.php';

class CircuitBreaker implements CircuitBreakerInterface {
    private PDO $db;

    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half-open';

    public function __construct() { $this->db = db(); }

    // ===== Provider-level Circuit Breaker =====

    /**
     * بررسی آیا provider در دسترس است.
     */
    public function checkProvider(int $providerId): array {
        $stmt = $this->db->prepare("SELECT circuit_state, circuit_opened_at, failure_count FROM providers WHERE id = ?");
        $stmt->execute([$providerId]);
        $p = $stmt->fetch();
        if (!$p) return ['state' => self::STATE_CLOSED, 'available' => true];

        $state = $p['circuit_state'];
        if ($state === self::STATE_CLOSED) return ['state' => self::STATE_CLOSED, 'available' => true];

        if ($state === self::STATE_OPEN) {
            if ($p['circuit_opened_at']) {
                $opened = strtotime($p['circuit_opened_at']);
                if (time() - $opened >= CB_COOLDOWN_SECONDS) {
                    $this->transitionProvider($providerId, self::STATE_HALF_OPEN);
                    return ['state' => self::STATE_HALF_OPEN, 'available' => true];
                }
            }
            return ['state' => self::STATE_OPEN, 'available' => false];
        }
        return ['state' => self::STATE_HALF_OPEN, 'available' => true];
    }

    public function recordProviderSuccess(int $providerId): void {
        $this->db->prepare("UPDATE providers SET circuit_state = ?, circuit_opened_at = NULL, failure_count = 0 WHERE id = ?")
            ->execute([self::STATE_CLOSED, $providerId]);
    }

    public function recordProviderFailure(int $providerId): void {
        $this->db->prepare("UPDATE providers SET failure_count = failure_count + 1 WHERE id = ?")->execute([$providerId]);
        $stmt = $this->db->prepare("SELECT failure_count, circuit_state FROM providers WHERE id = ?");
        $stmt->execute([$providerId]);
        $p = $stmt->fetch();
        if (!$p) return;
        $fc = (int)$p['failure_count'];
        if ($p['circuit_state'] === self::STATE_HALF_OPEN) {
            $this->transitionProvider($providerId, self::STATE_OPEN);
            log_warn("Circuit breaker half-open → open for provider #{$providerId}");
            return;
        }
        if ($fc >= CB_FAILURE_THRESHOLD) {
            $this->transitionProvider($providerId, self::STATE_OPEN);
            log_warn("Circuit breaker closed → open for provider #{$providerId} ({$fc} errors)");
        }
    }

    public function resetProvider(int $providerId): bool {
        return $this->db->prepare("UPDATE providers SET circuit_state = ?, circuit_opened_at = NULL, failure_count = 0 WHERE id = ?")
            ->execute([self::STATE_CLOSED, $providerId]);
    }

    private function transitionProvider(int $providerId, string $newState): void {
        if ($newState === self::STATE_OPEN) {
            $this->db->prepare("UPDATE providers SET circuit_state = ?, circuit_opened_at = NOW() WHERE id = ?")->execute([$newState, $providerId]);
        } else {
            $this->db->prepare("UPDATE providers SET circuit_state = ? WHERE id = ?")->execute([$newState, $providerId]);
        }
    }

    // ===== Key-level Circuit Breaker =====

    /**
     * بررسی circuit breaker برای یک API Key خاص.
     * کلیدها از cooldown_until استفاده می‌کنن به جای circuit_state.
     */
    public function checkKey(int $keyId): array {
        $stmt = $this->db->prepare("SELECT status, consecutive_errors, cooldown_until FROM api_keys WHERE id = ?");
        $stmt->execute([$keyId]);
        $k = $stmt->fetch();
        if (!$k) return ['state' => self::STATE_CLOSED, 'available' => false];

        if ((int)$k['status'] === 0) return ['state' => self::STATE_OPEN, 'available' => false];
        if (!empty($k['cooldown_until']) && strtotime($k['cooldown_until']) > time()) {
            return ['state' => self::STATE_OPEN, 'available' => false];
        }
        if ((int)$k['consecutive_errors'] >= KEY_MAX_CONSECUTIVE_ERRORS) {
            return ['state' => self::STATE_OPEN, 'available' => false];
        }
        return ['state' => self::STATE_CLOSED, 'available' => true];
    }

    public function recordKeySuccess(int $keyId): void {
        $this->db->prepare("UPDATE api_keys SET consecutive_errors = 0, cooldown_until = NULL WHERE id = ?")->execute([$keyId]);
    }

    public function recordKeyFailure(int $keyId): void {
        $this->db->prepare("UPDATE api_keys SET consecutive_errors = consecutive_errors + 1, cooldown_until = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?")
            ->execute([KEY_COOLDOWN_SECONDS, $keyId]);
        // کلیدها فقط cooldown می‌شن، هرگز غیرفعال نمی‌شن (auto-disable حذف شد)
    }

    public function resetKey(int $keyId): bool {
        return $this->db->prepare("UPDATE api_keys SET status = 1, consecutive_errors = 0, cooldown_until = NULL WHERE id = ?")->execute([$keyId]);
    }

    public function getAllStates(): array {
        return $this->db->query("SELECT id, name, slug, circuit_state, failure_count, circuit_opened_at FROM providers ORDER BY priority ASC")->fetchAll();
    }

    // ===== Legacy compat =====
    public function check(int $providerId): array { return $this->checkProvider($providerId); }
    public function recordSuccess(int $providerId): void { $this->recordProviderSuccess($providerId); }
    public function recordFailure(int $providerId): void { $this->recordProviderFailure($providerId); }
    public function reset(int $providerId): bool { return $this->resetProvider($providerId); }
}

// پایان circuit_breaker.php
