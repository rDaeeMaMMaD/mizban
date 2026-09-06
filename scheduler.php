<?php
/**
 * میزبان - Scheduler
 * ------------------------------------------------------------------
 * Scheduler تصمیم می‌گیره:
 *   - کدام درخواست اولویت بالاتری داره (client degree + mode)
 *   - کدام ارائه‌دهنده انتخاب شه (via LoadBalancer)
 *   - کدام مدل استفاده شه
 *   - کدام کلید API استفاده شه (via KeyPool)
 *
 * اولویت‌ها (از بالا به پایین):
 *   ۱. Client Degree (۱-۱۰) — بالاتر = اولویت بیشتر در صف
 *   ۲. Provider Priority (کمتر=اول در general mode)
 *   ۳. Model fallback chain
 *   ۴. Key Pool strategy
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/interfaces.php';
require_once __DIR__ . '/load_balancer.php';
require_once __DIR__ . '/key_pool.php';
require_once __DIR__ . '/providers.php';
require_once __DIR__ . '/provider_registry.php';
require_once __DIR__ . '/circuit_breaker.php';

class Scheduler implements SchedulerInterface {
    private PDO $db;
    private LoadBalancerInterface $balancer;
    private KeyPoolInterface $keyPool;
    private CircuitBreakerInterface $cb;
    private ProviderRegistryInterface $registry;

    /**
     * Dependency Injection — هیچ new مستقیم نیست.
     */
    public function __construct(
        ?LoadBalancerInterface $balancer = null,
        ?KeyPoolInterface $keyPool = null,
        ?CircuitBreakerInterface $cb = null,
        ?ProviderRegistryInterface $registry = null
    ) {
        $this->db = db();
        $this->balancer = $balancer ?? new LoadBalancer();
        $this->keyPool = $keyPool ?? new KeyPool();
        $this->cb = $cb ?? new CircuitBreaker();
        $this->registry = $registry ?? new ProviderRegistry();
    }

    /**
     * مسیریابی یک درخواست:
     *   - general: LoadBalancer ارائه‌دهنده رو انتخاب می‌کنه
     *   - specific: مدل مشخص شده → ارائه‌دهنده اون مدل
     *
     * خروجی: ['provider' => row, 'model' => modelId, 'key' => keyInfo, 'fallback_used' => null]
     * یا در صورت failover: fallback chain
     *
     * @param string $model مدل درخواستی (یا __general__)
     * @param string $mode general|specific
     * @param array $visited مدل‌های امتحان شده (برای failover)
     * @return array ['ok' => bool, 'provider' => row, 'model' => str, 'key_id' => int, 'api_key' => str, 'driver' => ProviderDriver, 'fallback_used' => str|null, 'error' => str]
     */
    public function route(string $model, string $mode, array $visited = [], array $excludedProviders = []): array {
        if (count($visited) > FAILOVER_MAX_PROVIDERS + 1) {
            return ['ok' => false, 'error' => mtr('err.maxFailover')];
        }

        $provider = null;
        $modelId = null;
        $modelRow = null;

        if ($mode === 'general') {
            // General mode: LoadBalancer انتخاب می‌کنه (با حذف providerهای قبلی که fail شدن)
            $selection = $this->balancer->selectProvider($excludedProviders);
            if (!$selection) {
                // اگه هیچ provider فعالی نیست، fallback به specific با مدل‌های فعال
                return ['ok' => false, 'error' => mtr('err.noHealthyProvider')];
            }
            $provider = $selection['provider'];
            $modelId = $selection['model'];
            $modelRow = $this->findModel($modelId);
            $visited[] = $modelId;
        } else {
            // Specific mode: مدل مشخص شده
            $modelRow = $this->findModel($model);
            if (!$modelRow) {
                // اگه مدل پیدا نشد، fallback مدل رو امتحان کن
                $fallbackModel = $this->getFallbackModel($model);
                if ($fallbackModel && !in_array($fallbackModel, $visited)) {
                    log_warn("Model '{$model}' not active, switching to fallback '{$fallbackModel}'");
                    return $this->route($fallbackModel, 'specific', array_merge($visited, [$model]), $excludedProviders);
                }
                return ['ok' => false, 'error' => mtr('err.modelNotActiveOrMissing', ['{model}' => $model])];
            }
            $provider = $this->getProvider((int)$modelRow['provider_id']);
            if (!$provider || $provider['status'] != 1) {
                // Provider خاموشه → fallback مدل
                return $this->tryFallback($modelRow, $mode, $visited, mtr('err.providerInactive'), $excludedProviders);
            }
            $modelId = $modelRow['model_id'];
            $visited[] = $modelId;

            // بررسی circuit breaker
            $cbState = $this->cb->checkProvider((int)$provider['id']);
            if (!$cbState['available']) {
                return $this->tryFallback($modelRow, $mode, $visited, mtr('err.cbOpen'), $excludedProviders);
            }
        }

        // انتخاب کلید از KeyPool
        $strategy = 'least_busy';
        $keyInfo = $this->keyPool->selectKey((int)$provider['id'], $strategy);
        if (!$keyInfo) {
            if ($mode === 'general') {
                // General: provider بعدی رو امتحان کن
                $excludedProviders[] = (int)$provider['id'];
                log_warn("Provider {$provider['slug']} has no active key, trying next provider");
                return $this->route($model, $mode, $visited, $excludedProviders);
            }
            return $this->tryFallback($modelRow, $mode, $visited, mtr('err.noActiveKey'), $excludedProviders);
        }

        // بررسی circuit breaker برای کلید
        $keyCb = $this->cb->checkKey($keyInfo['key_id']);
        if (!$keyCb['available']) {
            if ($mode === 'general') {
                $excludedProviders[] = (int)$provider['id'];
                return $this->route($model, $mode, $visited, $excludedProviders);
            }
            return $this->tryFallback($modelRow, $mode, $visited, mtr('err.keyCbOpen'), $excludedProviders);
        }

        // ساخت Driver
        $driver = $this->registry->create($provider, $keyInfo['api_key'], $modelId);

        return [
            'ok' => true,
            'provider' => $provider,
            'model' => $modelId,
            'key_id' => $keyInfo['key_id'],
            'driver' => $driver,
            'fallback_used' => count($visited) > 1 ? implode(' → ', $visited) : null,
        ];
    }

    /**
     * Failover به مدل fallback.
     */
    private function tryFallback(?array $modelRow, string $mode, array $visited, string $lastError, array $excludedProviders = []): array {
        if (!$modelRow || !$modelRow['fallback_model']) {
            // اگه fallback نیست، general رو امتحان کن
            if ($mode === 'specific') {
                log_warn("No fallback available, switching to general mode");
                return $this->route('__general__', 'general', $visited, $excludedProviders);
            }
            return ['ok' => false, 'error' => $lastError];
        }
        $fallback = $modelRow['fallback_model'];
        if (in_array($fallback, $visited)) {
            // fallback قبلاً امتحان شده → general
            return $this->route('__general__', 'general', $visited, $excludedProviders);
        }
        log_warn("Failover: {$modelRow['model_id']} → {$fallback}", ['chain' => implode(' → ', $visited)]);
        return $this->route($fallback, 'specific', $visited, $excludedProviders);
    }

    /**
     * گرفتن مدل fallback یک مدل (بدون نیاز به provider فعال).
     */
    private function getFallbackModel(string $modelId): ?string {
        $stmt = $this->db->prepare("SELECT fallback_model FROM models WHERE model_id = ? AND status = 1 LIMIT 1");
        $stmt->execute([$modelId]);
        $r = $stmt->fetch();
        return $r && $r['fallback_model'] ? $r['fallback_model'] : null;
    }

    private function findModel(string $modelId): ?array {
        $stmt = $this->db->prepare("SELECT m.*, p.slug AS provider_slug FROM models m JOIN providers p ON m.provider_id = p.id WHERE m.model_id = ? AND m.status = 1 AND p.status = 1 LIMIT 1");
        $stmt->execute([$modelId]);
        $r = $stmt->fetch();
        return $r ?: null;
    }

    private function getProvider(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM providers WHERE id = ?");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        return $r ?: null;
    }
}

// پایان scheduler.php
