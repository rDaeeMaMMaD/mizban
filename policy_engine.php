<?php
/**
 * میزبان (Host) - Policy Engine
 * ------------------------------------------------------------------
 * موتور سیاست‌ها: بر اساس درجه کلاینت (degree 1-10) محدودیت‌های
 * دسترسی به مدل‌ها و ارائه‌دهنده‌ها تعیین می‌شه.
 *
 *   Degree 1-3  (پایه):    فقط مدل‌های پایه (gpt-4o-mini, glm-4.5-flash)
 *   Degree 4-7  (متوسط):   همه مدل‌های chat
 *   Degree 8-10 (ویژه):    همه مدل‌ها شامل vision/ocr/agent/tool
 *
 * استفاده:
 *   $policy = new PolicyEngine();
 *   if (!$policy->canUseModel($client, $modelId)) {
 *       json_response(['error' => 'دسترسی غیرمجاز به مدل'], 403);
 *   }
 *   $allowed = $policy->getPolicy($client);
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';

class PolicyEngine {
    /** مدل‌های پایه - در دسترس همه کلاینت‌ها (degree >= 1) */
    private const BASIC_MODELS = [
        'gpt-4o-mini',
        'glm-4.5-flash',
        'glm-4.7-flash',
        'deepseek-v4-flash',
        'tencent/hy3:free',
    ];

    /** مدل‌های vision/ocr/agent/tool - فقط degree >= 8 */
    private const PREMIUM_MODALITIES = ['vision', 'ocr', 'agent', 'tool'];

    /**
     * گرفتن سیاست یک کلاینت.
     *
     * @param array $client ردیف کلاینت (حداقل degree)
     * @return array [
     *   'degree' => int,
     *   'tier' => 'basic'|'standard'|'premium',
     *   'allowed_providers' => string[]|null (null = همه),
     *   'allowed_models' => string[]|null (null = همه مدل‌های مجاز tier),
     *   'blocked_modalities' => string[],
     *   'max_priority' => int,
     * ]
     */
    public function getPolicy(array $client): array {
        $degree = max(1, min(10, (int)($client['degree'] ?? 5)));

        if ($degree <= 3) {
            // Tier پایه: فقط مدل‌های پایه
            return [
                'degree' => $degree,
                'tier' => 'basic',
                'allowed_providers' => null, // همه provider ها (اما فقط مدل‌های پایه)
                'allowed_models' => self::BASIC_MODELS,
                'blocked_modalities' => ['vision', 'ocr', 'agent', 'tool', 'image_gen', 'audio_tts', 'audio_stt', 'embedding'],
                'max_priority' => $degree,
                'description' => mtr('policy.basic'),
            ];
        }

        if ($degree <= 7) {
            // Tier متوسط: همه مدل‌های chat (modality = chat/text)
            return [
                'degree' => $degree,
                'tier' => 'standard',
                'allowed_providers' => null,
                'allowed_models' => null, // همه مدل‌های chat مجازند
                'blocked_modalities' => ['vision', 'ocr', 'agent', 'tool', 'image_gen', 'audio_tts', 'audio_stt'],
                'max_priority' => $degree,
                'description' => mtr('policy.standard'),
            ];
        }

        // Tier ویژه: همه مدل‌ها شامل vision/ocr/agent/tool
        return [
            'degree' => $degree,
            'tier' => 'premium',
            'allowed_providers' => null,
            'allowed_models' => null, // همه مدل‌ها مجازند
            'blocked_modalities' => [],
            'max_priority' => $degree,
            'description' => mtr('policy.premium'),
        ];
    }

    /**
     * بررسی آیا کلاینت می‌تواند از یک مدل خاص استفاده کنه.
     *
     * @param array  $client ردیف کلاینت
     * @param string $modelId شناسه مدل
     * @return bool
     */
    public function canUseModel(array $client, string $modelId): bool {
        $policy = $this->getPolicy($client);

        // Tier premium: همه مدل‌ها مجازند
        if ($policy['tier'] === 'premium') return true;

        // Tier basic: فقط مدل‌های پایه
        if ($policy['tier'] === 'basic') {
            return in_array($modelId, $policy['allowed_models'], true);
        }

        // Tier standard: همه مدل‌های chat مجازند، vision/ocr/agent/tool مسدودند
        // بررسی modality مدل از دیتابیس
        $modality = $this->getModelModality($modelId);
        if ($modality && in_array($modality, $policy['blocked_modalities'], true)) {
            return false;
        }
        return true;
    }

    /**
     * فیلتر کردن لیست مدل‌ها بر اساس سیاست کلاینت.
     *
     * @param array $client ردیف کلاینت
     * @param array $models لیست مدل‌ها از model_list()
     * @return array مدل‌های مجاز
     */
    public function filterModels(array $client, array $models): array {
        $policy = $this->getPolicy($client);

        if ($policy['tier'] === 'premium') {
            return $models; // همه مجازند
        }

        return array_values(array_filter($models, function($m) use ($policy) {
            $modelId = $m['model_id'] ?? '';
            $modality = $m['modality'] ?? 'chat';

            // Tier basic: فقط مدل‌های پایه
            if ($policy['tier'] === 'basic') {
                return in_array($modelId, $policy['allowed_models'], true);
            }

            // Tier standard: مسدود کردن modalities غیر chat
            if (in_array($modality, $policy['blocked_modalities'], true)) {
                return false;
            }
            return true;
        }));
    }

    /**
     * گرفتن modality یک مدل از دیتابیس.
     */
    private function getModelModality(string $modelId): ?string {
        try {
            $stmt = db()->prepare("SELECT modality FROM models WHERE model_id = ? LIMIT 1");
            $stmt->execute([$modelId]);
            $r = $stmt->fetchColumn();
            return $r ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

// پایان policy_engine.php
