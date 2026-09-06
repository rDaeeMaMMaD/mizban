<?php
/**
 * میزبان - Response Manager
 * ------------------------------------------------------------------
 * مسئول تحویل پاسخ به کلاینت در دو حالت:
 *
 *   Sync:  جواب کامل همون لحظه در پاسخ HTTP برمی‌گرده
 *   Async: ACK فوری → بعداً نتیجه از طریق callback یا polling
 *
 * جریان Async:
 *   Client → Request → [ResponseManager.sendACK] → Client (ACK)
 *                                        ↓
 *                                   Queue → Worker → Provider
 *                                        ↓
 *                              [ResponseManager.deliverResult] → Client (callback)
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/interfaces.php';

class ResponseManager implements ResponseManagerInterface {
    private PDO $db;

    public function __construct() { $this->db = db(); }

    /**
     * ارسال ACK فوری به کلاینت (HTTP response + callback).
     */
    public function sendACK(int $requestId, string $status, string $message,
                            array $extra = [], ?string $callbackUrl = null): array {
        $ack = array_merge([
            'type' => 'ack',
            'request_id' => $requestId,
            'status' => $status,
            'message' => $message,
            'timestamp' => date('c'),
        ], $extra);

        // ارسال ACK به callback (اگه وجود داشته باشه)
        if ($callbackUrl && ACK_CALLBACK) {
            $this->sendCallback($callbackUrl, $ack);
        }
        return $ack;
    }

    /**
     * تحویل نتیجه نهایی به کلاینت (via callback).
     */
    public function deliverResult(array $job, array $result): void {
        if (empty($job['callback_url'])) return;

        $payload = [
            'type' => 'result',
            'request_id' => (int)$job['id'],
            'status' => $result['status'] ?? 'completed',
            'model' => $job['model'],
            'mode' => $job['mode'] ?? 'specific',
            'provider' => $result['provider'] ?? null,
            'fallback_used' => $result['fallback'] ?? null,
            'latency_ms' => $result['latency_ms'] ?? null,
            'total_time_ms' => $result['total_time_ms'] ?? null,
            'queue_time_ms' => $job['queue_time_ms'] ?? null,
            'worker_id' => $result['worker_id'] ?? null,
            'error' => $result['error'] ?? null,
            'error_type' => $result['error_type'] ?? null,
            'timestamp' => date('c'),
        ];

        // اضافه کردن response در صورت موفقیت
        if (($result['status'] ?? '') === 'completed' && !empty($result['response'])) {
            $decoded = json_decode($result['response'], true);
            $payload['response'] = $decoded !== null ? $decoded : $result['response'];
        }

        $this->sendCallback($job['callback_url'], $payload);
    }

    /**
     * تحویل نتیجه sync در پاسخ HTTP.
     */
    public function buildSyncResponse(int $requestId, string $mode, int $priority,
                                       array $result, ?string $correlationId = null): array {
        $req = queue_status($requestId);
        $response = null;
        if (!empty($req['response'])) {
            $decoded = json_decode($req['response'], true);
            $response = $decoded !== null ? $decoded : $req['response'];
        }

        if (($result['status'] ?? '') === 'completed') {
            return [
                'ok' => true, 'ack' => true, 'request_id' => $requestId,
                'mode' => $mode, 'priority' => $priority,
                'status' => 'completed', 'sync' => true,
                'provider' => $result['provider'] ?? null,
                'fallback_used' => $result['fallback'] ?? null,
                'latency_ms' => $result['latency_ms'] ?? null,
                'total_time_ms' => $result['total_time_ms'] ?? null,
                'queue_time_ms' => $req['queue_time_ms'] ?? null,
                'correlation_id' => $correlationId,
                'worker_id' => $result['worker_id'] ?? null,
                'response' => $response,
                'message' => mtr('msg.processed'),
            ];
        }

        return [
            'ok' => false, 'ack' => true, 'request_id' => $requestId,
            'mode' => $mode, 'status' => $result['status'] ?? 'failed',
            'sync' => true, 'correlation_id' => $correlationId,
            'error' => $result['error'] ?? mtr('msg.processingFailed'),
            'error_type' => $result['error_type'] ?? null,
            'message' => mtr('msg.processingFailed'),
        ];
    }

    /**
     * ارسال callback با retry.
     */
    private function sendCallback(string $url, array $data): void {
        if (!is_valid_url($url)) return;
        send_callback_robust($url, $data);
    }
}

// پایان response_manager.php
