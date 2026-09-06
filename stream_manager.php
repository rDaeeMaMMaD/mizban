<?php
/**
 * میزبان v5.0.0 - Stream Manager
 * ------------------------------------------------------------------
 * Streaming: Worker → Chunk → SSE → Client
 *
 * جریان:
 *   ۱. کلاینت درخواست با stream=true می‌فرسته
 *   ۲. Worker شروع به پردازش می‌کنه
 *   ۳. هر chunk که از Provider میاد، تو جدول stream_chunks ذخیره می‌شه
 *   ۴. کلاینت با SSE به stream.php وصل می‌شه و chunk‌ها رو می‌خونه
 *
 * یا:
 *   Worker مستقیماً SSE رو به کلاینت استریم می‌کنه (در حالت sync)
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';

class StreamManager {
    private PDO $db;

    public function __construct() { $this->db = db(); }

    /**
     * شروع یک session استریم.
     * @return string session_id
     */
    public function startSession(int $requestId): string {
        $sessionId = bin2hex(random_bytes(16));
        $this->db->prepare("
            INSERT INTO stream_sessions (session_id, request_id, status, created_at)
            VALUES (?, ?, 'streaming', NOW())
        ")->execute([$sessionId, $requestId]);
        return $sessionId;
    }

    /**
     * افزودن یک chunk.
     */
    public function addChunk(string $sessionId, string $content, bool $isFinal = false): void {
        $this->db->prepare("
            INSERT INTO stream_chunks (session_id, content, is_final, created_at)
            VALUES (?, ?, ?, NOW())
        ")->execute([$sessionId, $content, $isFinal ? 1 : 0]);

        if ($isFinal) {
            $this->db->prepare("UPDATE stream_sessions SET status = 'completed', completed_at = NOW() WHERE session_id = ?")
                ->execute([$sessionId]);
        }
    }

    /**
     * خوندن chunk‌های جدید (برای polling یا SSE).
     * @param int $afterId آخرین chunk ID که کلاینت خونده
     * @return array ['chunks' => [...], 'is_completed' => bool]
     */
    public function getChunks(string $sessionId, int $afterId = 0): array {
        $stmt = $this->db->prepare("
            SELECT id, content, is_final, created_at FROM stream_chunks
            WHERE session_id = ? AND id > ?
            ORDER BY id ASC
        ");
        $stmt->execute([$sessionId, $afterId]);
        $chunks = $stmt->fetchAll();

        $stmt2 = $this->db->prepare("SELECT status FROM stream_sessions WHERE session_id = ?");
        $stmt2->execute([$sessionId]);
        $session = $stmt2->fetch();

        return [
            'chunks' => $chunks,
            'is_completed' => $session && $session['status'] === 'completed',
        ];
    }

    /**
     * ارسال SSE به کلاینت (استریم زنده).
     * این متد HTTP header‌های SSE رو set می‌کنه و chunk‌ها رو می‌فرسته.
     */
    public function sendSSE(string $sessionId): void {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // nginx

        $lastId = 0;
        $timeout = time() + 120; // 2 دقیقه timeout

        while (time() < $timeout) {
            $data = $this->getChunks($sessionId, $lastId);

            foreach ($data['chunks'] as $chunk) {
                echo "data: " . json_encode([
                    'id' => (int)$chunk['id'],
                    'content' => $chunk['content'],
                    'is_final' => (int)$chunk['is_final'],
                ], JSON_UNESCAPED_UNICODE) . "\n\n";

                ob_flush();
                flush();

                $lastId = (int)$chunk['id'];

                if ((int)$chunk['is_final'] === 1) {
                    echo "event: done\ndata: {\"status\":\"completed\"}\n\n";
                    ob_flush();
                    flush();
                    return;
                }
            }

            if ($data['is_completed']) {
                echo "event: done\ndata: {\"status\":\"completed\"}\n\n";
                ob_flush();
                flush();
                return;
            }

            // heartbeat
            echo ": heartbeat\n\n";
            ob_flush();
            flush();

            usleep(100000); // 100ms
        }

        echo "event: timeout\ndata: {\"status\":\"timeout\"}\n\n";
        ob_flush();
        flush();
    }

    /**
     * استریم مستقیم از Provider به کلاینت (sync mode).
     * Worker Provider رو با stream=true صدا می‌زنه و chunk‌ها رو مستقیم می‌فرسته.
     */
    public function streamFromProvider(string $sessionId, $ch): void {
        // این متد با CURLOPT_WRITEFUNCTION کار می‌کنه
        // هر chunk که از Provider میاد، هم به کلاینت فرستاده می‌شه و هم در DB ذخیره
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use ($sessionId) {
            $this->addChunk($sessionId, $data);
            echo "data: " . json_encode(['content' => $data], JSON_UNESCAPED_UNICODE) . "\n\n";
            ob_flush();
            flush();
            return strlen($data);
        });
    }
}

// پایان stream_manager.php
