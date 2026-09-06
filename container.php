<?php
/**
 * میزبان - DI Container
 * ------------------------------------------------------------------
 * هیچ کلاسی مستقیماً `new` نمی‌کنه.
 * همه از Container گرفته می‌شن.
 * وابستگی‌ها از طریق constructor تزریق می‌شن.
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/interfaces.php';
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

class Container {
    private static array $instances = [];
    private static array $bindings = [];

    /**
     * ثبت یک interface → implementation.
     */
    public static function bind(string $interface, string $class): void {
        self::$bindings[$interface] = $class;
    }

    /**
     * گرفتن یک نمونه (singleton).
     */
    public static function get(string $interface): object {
        if (isset(self::$instances[$interface])) {
            return self::$instances[$interface];
        }
        $class = self::$bindings[$interface] ?? $interface;
        $instance = self::build($class);
        self::$instances[$interface] = $instance;
        return $instance;
    }

    /**
     * ساخت یک نمونه جدید (non-singleton).
     */
    public static function make(string $class): object {
        return self::build($class);
    }

    /**
     * ساخت با dependency injection.
     */
    private static function build(string $class): object {
        if (!class_exists($class)) {
            throw new RuntimeException("Class {$class} not found");
        }
        $reflection = new ReflectionClass($class);
        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            return new $class();
        }
        $params = $constructor->getParameters();
        $args = [];
        foreach ($params as $param) {
            $type = $param->getType();
            if ($type && !$type->isBuiltin()) {
                $typeName = $type->getName();
                // اگه interface هست، از Container بگیر
                if (interface_exists($typeName)) {
                    $args[] = self::get($typeName);
                } elseif (class_exists($typeName)) {
                    $args[] = self::get($typeName);
                }
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                $args[] = null;
            }
        }
        return new $class(...$args);
    }
}

// ثبت binding های پیش‌فرض
Container::bind(QueueInterface::class, QueueManager::class);
Container::bind(LoadBalancerInterface::class, LoadBalancer::class);
Container::bind(KeyPoolInterface::class, KeyPool::class);
Container::bind(ProviderRegistryInterface::class, ProviderRegistry::class);
Container::bind(CircuitBreakerInterface::class, CircuitBreaker::class);
Container::bind(MetricsInterface::class, MetricsService::class);
Container::bind(ResponseManagerInterface::class, ResponseManager::class);
Container::bind(ConfigInterface::class, ConfigManager::class);
Container::bind(QueueCleanerInterface::class, QueueCleaner::class);

// پایان container.php
