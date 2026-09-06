<?php
/**
 * میزبان - Service Interfaces (Contract-based Architecture)
 * ------------------------------------------------------------------
 * هر کلاس فقط یک مسئولیت (SRP).
 * هیچ کلاسی مستقیماً کلاس دیگر را نمی‌سازد (new).
 * تمام وابستگی‌ها از طریق Interface تزریق می‌شوند (DI).
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }

// ================================================================
// Queue Interface
// ================================================================
interface QueueInterface {
    public function enqueue(int $clientId, string $mode, string $model, string $action,
                            string $payload, int $priority, ?string $callbackUrl,
                            ?string $idempotencyKey, ?string $correlationId,
                            int $streamMode = 0, ?string $scheduledAt = null): int;
    public function acquireJob(string $workerId): ?array;
    public function acquireJobById(int $id, string $workerId): ?array;
    public function complete(int $id, string $response, string $provider, int $keyId,
                             ?string $fallbackUsed, int $latencyMs, int $totalMs): void;
    public function moveToRetry(int $id, string $error, string $errorType): void;
    public function moveToDead(int $id, string $error, string $errorType): void;
    public function moveToFailed(int $id, string $error, string $errorType): void;
    public function promotePending(): array;
    public function getQueueBreakdown(): array;
    public function estimateTime(int $position): int;
}

// ================================================================
// Provider Driver Interface
// ================================================================
interface ProviderDriverInterface {
    public function execute(string $action, array $payload): array;
    public function getSupportedActions(): array;
}

// ================================================================
// Provider Registry Interface
// Dispatcher فقط این interface رو می‌شناسه، نه driver های خاص رو.
// ================================================================
interface ProviderRegistryInterface {
    public function register(string $type, string $driverClass): void;
    public function create(array $provider, string $apiKey, string $defaultModel): ProviderDriverInterface;
    public function getRegisteredTypes(): array;
}

// ================================================================
// Key Pool Interface
// ================================================================
interface KeyPoolInterface {
    public function selectKey(int $providerId, string $strategy = 'least_busy'): ?array;
    public function recordSuccess(int $keyId, int $latencyMs): void;
    public function recordFailure(int $keyId): void;
    public function incrementActive(int $keyId): void;
}

// ================================================================
// Load Balancer Interface
// ================================================================
interface LoadBalancerInterface {
    public function computeScore(array $provider): array;
    public function selectProvider(array $excludedIds = []): ?array;
}

// ================================================================
// Circuit Breaker Interface
// برای هر Provider و هر API Key.
// ================================================================
interface CircuitBreakerInterface {
    public function checkProvider(int $providerId): array;
    public function checkKey(int $keyId): array;
    public function recordProviderSuccess(int $providerId): void;
    public function recordProviderFailure(int $providerId): void;
    public function recordKeySuccess(int $keyId): void;
    public function recordKeyFailure(int $keyId): void;
    public function resetProvider(int $providerId): bool;
    public function resetKey(int $keyId): bool;
    public function getAllStates(): array;
}

// ================================================================
// Metrics Interface
// خوراک Load Balancer و Dashboard.
// ================================================================
interface MetricsInterface {
    public function getSystemMetrics(): array;
    public function getProviderMetrics(int $providerId): array;
    public function getKeyMetrics(int $keyId): array;
    public function recordStatistics(): void;
}

// ================================================================
// Response Manager Interface
// ACK + Callback + Polling + Streaming.
// ================================================================
interface ResponseManagerInterface {
    public function sendACK(int $requestId, string $status, string $message,
                            array $extra = [], ?string $callbackUrl = null): array;
    public function deliverResult(array $job, array $result): void;
    public function buildSyncResponse(int $requestId, string $mode, int $priority,
                                       array $result, ?string $correlationId = null): array;
}

// ================================================================
// Config Interface
// تمام تنظیمات از DB.
// ================================================================
interface ConfigInterface {
    public function get(string $key, $default = null);
    public function set(string $key, $value): void;
    public function all(): array;
    public function getByPrefix(string $prefix): array;
}

// ================================================================
// Scheduler Interface
// ================================================================
interface SchedulerInterface {
    public function route(string $model, string $mode, array $visited = []): array;
}

// ================================================================
// Queue Cleaner Interface
// ================================================================
interface QueueCleanerInterface {
    public function cleanAll(): array;
    public function cleanOldRequests(): int;
    public function cleanExpiredNonces(): int;
    public function cleanDeadWorkers(): int;
}

// ================================================================
// Health Check Interface
// ================================================================
interface HealthCheckInterface {
    public function checkProvider(int $providerId): array;
    public function checkAll(): array;
}

// پایان interfaces.php
