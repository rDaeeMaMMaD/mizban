<?php
/**
 * میزبان v5.0.0 - Capability Router
 * ------------------------------------------------------------------
 * قبل از انتخاب Provider، قابلیت درخواست تشخیص داده می‌شه:
 *   chat, vision, embedding, audio_tts, audio_stt, image_gen, file
 *
 * سپس فقط Providerهایی که اون Capability رو دارن وارد Load Balancing می‌شن.
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';

class CapabilityRouter {
    private PDO $db;

    public function __construct() { $this->db = db(); }

    /**
     * تشخیص capability از درخواست.
     * @return string chat|vision|embedding|audio_tts|audio_stt|image_gen|file
     */
    public function detectCapability(string $action, array $payload): string {
        // Image generation
        if ($action === 'images' || isset($payload['prompt']) && $action === 'images') {
            return 'image_gen';
        }
        // TTS
        if ($action === 'speech' || $action === 'audio_speech') {
            return 'audio_tts';
        }
        // STT
        if ($action === 'transcriptions' || $action === 'audio_transcriptions') {
            return 'audio_stt';
        }
        // Embeddings
        if ($action === 'embeddings' || $action === 'embedding') {
            return 'embedding';
        }
        // Vision: attachments شامل image هست
        if (!empty($payload['attachments'])) {
            foreach ($payload['attachments'] as $att) {
                $mime = $att['mime'] ?? $att['type'] ?? '';
                if (strpos($mime, 'image/') === 0) return 'vision';
                if (strpos($mime, 'application/pdf') === 0) return 'file';
                if (strpos($mime, 'audio/') === 0) return 'audio_stt';
                if (strpos($mime, 'video/') === 0) return 'file';
            }
        }
        // Base64 image in messages
        if (!empty($payload['messages'])) {
            foreach ($payload['messages'] as $msg) {
                $content = $msg['content'] ?? '';
                if (is_array($content)) {
                    foreach ($content as $part) {
                        if (($part['type'] ?? '') === 'image_url') return 'vision';
                    }
                }
            }
        }
        return 'chat';
    }

    /**
     * پیدا کردن Providerهایی که یک capability رو پشتیبانی می‌کنن.
     * @param string $capability
     * @param array $excludedIds Providerهای excluded
     * @return array لیست Providerها
     */
    public function getProvidersByCapability(string $capability, array $excludedIds = []): array {
        $exclStr = '';
        $params = [$capability];
        if (!empty($excludedIds)) {
            $exclStr = ' AND p.id NOT IN (' . implode(',', array_fill(0, count($excludedIds), '?')) . ')';
            $params = array_merge([$capability], $excludedIds);
        }
        $stmt = $this->db->prepare("
            SELECT DISTINCT p.*, m.model_id, m.display_name, m.fallback_model, m.is_default
            FROM providers p
            JOIN models m ON m.provider_id = p.id AND m.status = 1
            WHERE p.status = 1
              AND p.circuit_state != 'open'
              AND m.modality LIKE ?
              {$exclStr}
            ORDER BY p.priority ASC, p.sort_order ASC
        ");
        // modality field: 'chat' or 'chat,vision' or 'chat,vision,embedding' etc
        $stmt->execute(['%' . $capability . '%']);
        return $stmt->fetchAll();
    }

    /**
     * بررسی آیا یک Provider یک capability رو پشتیبانی می‌کنه.
     */
    public function providerHasCapability(int $providerId, string $capability): bool {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM models m
            WHERE m.provider_id = ? AND m.status = 1 AND m.modality LIKE ?
        ");
        $stmt->execute([$providerId, '%' . $capability . '%']);
        return (int)$stmt->fetchColumn() > 0;
    }
}

// پایان capability_router.php
