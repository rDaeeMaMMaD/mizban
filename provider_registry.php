<?php
/**
 * میزبان - Provider Registry (Plugin System)
 * ------------------------------------------------------------------
 * Dispatcher و Worker هرگز_driver های خاصی رو نمی‌شناسن.
 * فقط با ProviderRegistry کار می‌کنن.
 *
 * برای افزودن ارائه‌دهنده جدید:
 *   ۱. یک فایل driver بساز (مثلاً driver_anthropic.php)
 *   ۲. کلاس driver از ProviderDriver ارث ببره
 *   ۳. در اینجا ثبت کن: ProviderRegistry::register('anthropic', AnthropicDriver::class)
 *
 * نه if/else، نه تغییر Dispatcher، نه تغییر Worker.
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/providers.php';

class ProviderRegistry implements ProviderRegistryInterface {
    /** @var array<string, class-string<ProviderDriver>> type → driver class */
    private array $drivers = [];
    private bool $initialized = false;
    private static ?ProviderRegistry $instance = null;

    public function __construct() {
        $this->init();
    }

    /**
     * ثبت یک driver برای یک type.
     */
    public function register(string $type, string $driverClass): void {
        if (!is_subclass_of($driverClass, ProviderDriver::class)) {
            throw new InvalidArgumentException("{$driverClass} باید از ProviderDriver ارث ببره");
        }
        $this->drivers[$type] = $driverClass;
    }

    /**
     * Auto Discovery — اسکن خودکار providers/ folder.
     */
    public function init(): void {
        if ($this->initialized) return;
        $this->initialized = true;

        $this->register('chat', OpenAICompatibleDriver::class);
        $this->register('gapgpt', GapGPTDriver::class);
        $this->register('gemini', GeminiDriver::class);
        $this->register('rapidapi', RapidAPIDriver::class);

        $providersDir = __DIR__ . '/providers';
        if (is_dir($providersDir)) {
            $dirs = scandir($providersDir);
            foreach ($dirs as $dir) {
                if ($dir === '.' || $dir === '..') continue;
                $providerDir = $providersDir . '/' . $dir;
                $manifestFile = $providerDir . '/manifest.json';
                $driverFile = $providerDir . '/driver.php';

                if (!file_exists($manifestFile)) continue;

                $manifest = json_decode(file_get_contents($manifestFile), true);
                if (!$manifest || !isset($manifest['type'])) continue;

                if (file_exists($driverFile)) {
                    require_once $driverFile;
                }

                $type = $manifest['type'];
                $driverClass = $manifest['driver'] ?? null;

                if ($driverClass && class_exists($driverClass)) {
                    $this->register($type, $driverClass);
                }
            }
        }
    }

    /**
     * ساخت driver مناسب برای یک ارائه‌دهنده.
     */
    public function create(array $provider, string $apiKey, string $defaultModel): ProviderDriverInterface {
        $type = $provider['type'] ?? 'chat';
        if (!isset($this->drivers[$type])) {
            $type = 'chat';
        }
        $class = $this->drivers[$type] ?? $this->drivers['chat'];
        return new $class($provider, $apiKey, $defaultModel);
    }

    /**
     * لیست همه type های ثبت شده.
     */
    public function getRegisteredTypes(): array {
        return array_keys($this->drivers);
    }
}

/**
 * DriverFactory حذف شد — ProviderRegistry جایگزین آن.
 * Dispatcher فقط ProviderRegistryInterface رو می‌شناسه.
 */

// پایان provider_registry.php
