<?php
/**
 * میزبان (Host) - Cost Engine
 * ------------------------------------------------------------------
 * موتور هزینه: تخمین هزینه هر مدل به ازای ۱۰۰۰ توکن و پیدا کردن
 * ارزان‌ترین مدل برای یک قابلیت خاص.
 *
 * استفاده:
 *   $cost = new CostEngine();
 *   $per1k = $cost->estimateCost('gpt-4o-mini');       // هزینه per 1K tokens
 *   $cheapest = $cost->getCheapestModel('chat');        // ارزان‌ترین مدل chat
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';

class CostEngine {
    /**
     * جدول هزینه‌ها به ازای ۱۰۰۰ توکن (input+output میانگین) به USD.
     * این مقادیر تقریبی هستند و بر اساس قیمت‌های عمومی provider ها.
     * کلید = model_id، مقدار = USD per 1K tokens.
     */
    private const COST_TABLE = [
        // GapGPT
        'gpt-4o-mini'              => 0.002,
        'gapgpt-qwen-3.6'          => 0.003,
        'gapgpt-qwen-3.6-thinking' => 0.005,

        // Z.ai (GLM)
        'glm-5.2'                  => 0.004,
        'glm-5.1'                  => 0.004,
        'glm-5'                    => 0.004,
        'glm-5-turbo'              => 0.006,
        'glm-4.7'                  => 0.004,
        'glm-4.6'                  => 0.004,
        'glm-4.5'                  => 0.004,
        'glm-4-plus'               => 0.006,
        'glm-4-32b-0414-128k'      => 0.005,
        'glm-4.5-air'              => 0.002,
        'glm-4.5-airx'             => 0.002,
        'glm-4.5-flash'            => 0.001,
        'glm-4.7-flash'            => 0.001,
        'glm-4.7-flashx'           => 0.001,
        'glm-4.6v'                 => 0.008,
        'glm-4.6v-flashx'          => 0.003,
        'glm-4.5v'                 => 0.008,
        'glm-5v-turbo'             => 0.010,
        'glm-ocr'                  => 0.005,
        'autoglm-phone-multilingual' => 0.020,
        'web-reader'               => 0.005,
        'search-prime'             => 0.005,

        // DeepSeek
        'deepseek-v4-flash'        => 0.002,
        'deepseek-v4-pro'          => 0.008,

        // NVIDIA
        'z-ai/glm-5.2'                  => 0.005,
        'minimaxai/minimax-m3'          => 0.010,
        'minimaxai/minimax-m2.7'        => 0.008,
        'deepseek-ai/deepseek-v4-flash' => 0.002,
        'deepseek-ai/deepseek-v4-pro'   => 0.008,

        // OpenRouter
        'tencent/hy3:free'              => 0.000,  // free
        'poolside/laguna-xs-2.1:free'   => 0.000,  // free

        // Gemini
        'gemini-3.5-flash'              => 0.001,

        // RapidAPI
        'chatgpt-4-2'                   => 0.010,
        'deepseek-reasoner'             => 0.015,
        'claude-3-7-sonnet'             => 0.020,
    ];

    /** هزینه پیش‌فرض اگه مدل در جدول نبود */
    private const DEFAULT_COST = 0.005;

    /**
     * تخمین هزینه یک مدل به ازای ۱۰۰۰ توکن.
     *
     * @param string $modelId شناسه مدل
     * @return float هزینه به USD per 1K tokens
     */
    public function estimateCost(string $modelId): float {
        if (isset(self::COST_TABLE[$modelId])) {
            return self::COST_TABLE[$modelId];
        }
        return self::DEFAULT_COST;
    }

    /**
     * پیدا کردن ارزان‌ترین مدل برای یک قابلیت (capability/modality).
     *
     * @param string $capability chat|vision|ocr|agent|tool|embedding|image_gen|audio_tts|audio_stt
     * @return array|null ['model_id' => str, 'display_name' => str, 'provider_slug' => str, 'cost_per_1k' => float]
     */
    public function getCheapestModel(string $capability = 'chat'): ?array {
        try {
            $pdo = db();
            // پیدا کردن همه مدل‌های فعال با این modality
            $stmt = $pdo->prepare("
                SELECT m.model_id, m.display_name, m.modality, p.slug AS provider_slug
                FROM models m
                JOIN providers p ON m.provider_id = p.id
                WHERE m.status = 1 AND p.status = 1
                  AND (m.modality = ? OR m.modality LIKE ? OR m.modality LIKE ?)
                ORDER BY m.id
            ");
            $stmt->execute([$capability, '%' . $capability . '%', $capability . ',%']);
            $models = $stmt->fetchAll();
        } catch (Throwable $e) {
            return null;
        }

        if (empty($models)) return null;

        $cheapest = null;
        $cheapestCost = PHP_FLOAT_MAX;
        foreach ($models as $m) {
            $cost = $this->estimateCost($m['model_id']);
            if ($cost < $cheapestCost) {
                $cheapestCost = $cost;
                $cheapest = $m;
            }
        }

        if (!$cheapest) return null;
        return [
            'model_id' => $cheapest['model_id'],
            'display_name' => $cheapest['display_name'],
            'provider_slug' => $cheapest['provider_slug'],
            'cost_per_1k' => $cheapestCost,
        ];
    }

    /**
     * لیست همه مدل‌ها با هزینه‌شان، مرتب از ارزان به گران.
     *
     * @param string $capability فیلتر بر اساس modality (اختیاری)
     * @return array لیست مدل‌ها با هزینه
     */
    public function listModelsWithCost(string $capability = ''): array {
        try {
            if ($capability) {
                $stmt = db()->prepare("
                    SELECT m.model_id, m.display_name, m.modality, p.slug AS provider_slug, m.status
                    FROM models m
                    JOIN providers p ON m.provider_id = p.id
                    WHERE m.status = 1 AND p.status = 1
                      AND (m.modality = ? OR m.modality LIKE ?)
                    ORDER BY m.id
                ");
                $stmt->execute([$capability, '%' . $capability . '%']);
            } else {
                $stmt = db()->query("
                    SELECT m.model_id, m.display_name, m.modality, p.slug AS provider_slug, m.status
                    FROM models m
                    JOIN providers p ON m.provider_id = p.id
                    WHERE m.status = 1 AND p.status = 1
                    ORDER BY p.sort_order, m.sort_order
                ");
            }
            $models = $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }

        $result = [];
        foreach ($models as $m) {
            $result[] = [
                'model_id' => $m['model_id'],
                'display_name' => $m['display_name'],
                'modality' => $m['modality'],
                'provider_slug' => $m['provider_slug'],
                'cost_per_1k' => $this->estimateCost($m['model_id']),
            ];
        }

        // مرتب‌سازی بر اساس هزینه (ارزان به گران)
        usort($result, fn($a, $b) => $a['cost_per_1k'] <=> $b['cost_per_1k']);
        return $result;
    }

    /**
     * تخمین هزینه کل یک درخواست بر اساس مدل و تعداد توکن.
     *
     * @param string $modelId شناسه مدل
     * @param int    $tokens تعداد توکن (input + output)
     * @return float هزینه به USD
     */
    public function estimateRequestCost(string $modelId, int $tokens): float {
        $per1k = $this->estimateCost($modelId);
        return ($tokens / 1000.0) * $per1k;
    }
}

// پایان cost_engine.php
