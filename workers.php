<?php
/**
 * میزبان (Host) - Workers
 * ------------------------------------------------------------------
 * دو ورکر اصلی:
 *   ۱) run_worker_chat    — ورکر یکپارچه برای همه ارائه‌دهنده‌های سازگار با OpenAI
 *                            (zai, deepseek, nvidia, openrouter, gapgpt-chat)
 *   ۲) run_worker_gapgpt  — ورکر گپ جی‌پی‌تی برای endpoint های خاص
 *                            (responses, models, images, transcriptions, speech, embeddings)
 *
 * برای افزودن ارائه‌دهنده جدید که OpenAI-compatible است:
 *   - نیاز به تغییر کد نیست! فقط از پنل ادمین ارائه‌دهنده + کلید + مدل اضافه کنید.
 *   - type ارائه‌دهنده را 'chat' بگذارید.
 *
 * برای افزودن ارائه‌دهنده با فرمت غیراستاندارد:
 *   - یک تابع run_worker_xxx اینجا اضافه کنید
 *   - در core.php → call_worker نوع جدید را اضافه کنید
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }

/**
 * ورکر یکپارچه Chat — سازگار با OpenAI /chat/completions
 *
 * @param array  $modelRow ردیف مدل از دیتابیس (شامل baseurl, type, provider_slug)
 * @param string $apiKey   کلید API (رمزگشایی شده)
 * @param string $action   اکشن: chat | models
 * @param array  $payload  پارامترهای درخواست
 * @return array ['ok', 'response', 'error']
 */
function run_worker_chat(array $modelRow, string $apiKey, string $action, array $payload): array {
    $base = rtrim($modelRow['baseurl'], '/');
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];

    switch ($action) {
        case 'chat':
            $url = $base . '/chat/completions';
            $body = [
                'model'    => $payload['model'] ?? $modelRow['model_id'],
                'messages' => $payload['messages'] ?? [['role' => 'user', 'content' => $payload['input'] ?? '']],
            ];
            // پارامترهای استاندارد OpenAI
            foreach (['temperature', 'max_tokens', 'stream', 'top_p', 'frequency_penalty', 'presence_penalty', 'tools', 'tool_choice', 'stop'] as $opt) {
                if (isset($payload[$opt])) $body[$opt] = $payload[$opt];
            }
            // پارامترهای خاص مدل‌های reasoning (Z.ai, DeepSeek)
            foreach (['thinking', 'reasoning_effort', 'chat_template_kwargs'] as $opt) {
                if (isset($payload[$opt])) $body[$opt] = $payload[$opt];
            }
            $r = http_request('POST', $url, $headers, $body);
            return _result($r);

        case 'models':
            $r = http_request('GET', $base . '/models', $headers, null);
            return _result($r);

        default:
            return ['ok' => false, 'response' => '', 'error' => mtr('err.chatAction', ['{action}' => $action])];
    }
}

/**
 * ورکر GapGPT — endpoint های خاص گپ جی‌پی‌تی
 * (chat و models هم از run_worker_chat عبور می‌کنند، ولی این ورکر
 *  responses, images, audio, embeddings را مدیریت می‌کند)
 *
 * @param array  $modelRow ردیف مدل از دیتابیس
 * @param string $apiKey   کلید API
 * @param string $action   اکشن
 * @param array  $payload  پارامترها
 * @return array
 */
function run_worker_gapgpt(array $modelRow, string $apiKey, string $action, array $payload): array {
    $base = rtrim($modelRow['baseurl'], '/');
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];
    $defaultModel = $modelRow['model_id'] ?? 'gpt-4o-mini';

    switch ($action) {
        // chat و models از ورکر یکپارچه استفاده می‌کنند
        case 'chat':
        case 'models':
            return run_worker_chat($modelRow, $apiKey, $action, $payload);

        case 'responses':
            $url = $base . '/responses';
            $body = [
                'model' => $payload['model'] ?? $defaultModel,
                'input' => $payload['input'] ?? '',
            ];
            foreach (['temperature', 'max_output_tokens', 'stream', 'instructions', 'tools'] as $opt) {
                if (isset($payload[$opt])) $body[$opt] = $payload[$opt];
            }
            return _result(http_request('POST', $url, $headers, $body));

        case 'images':
            $url = $base . '/images/generations';
            $body = [
                'model'  => $payload['model'] ?? 'gpt-image-2',
                'prompt' => $payload['prompt'] ?? '',
                'size'   => $payload['size'] ?? '1024x1024',
            ];
            foreach (['n', 'response_format', 'quality', 'style'] as $opt) {
                if (isset($payload[$opt])) $body[$opt] = $payload[$opt];
            }
            return _result(http_request('POST', $url, $headers, $body));

        case 'transcriptions':
            $url = $base . '/audio/transcriptions';
            $filePath = $payload['file_path'] ?? '';
            // امنیت: فقط فایل‌های داخل پوشه uploads مجازند (جلوگیری از خواندن فایل‌های سرور)
            if (!is_allowed_upload_path($filePath)) {
                return ['ok' => false, 'response' => '', 'error' => mtr('err.pathNotAllowed')];
            }
            if (!file_exists($filePath)) {
                return ['ok' => false, 'response' => '', 'error' => mtr('err.audioNotFound', ['{path}' => $filePath])];
            }
            $mime = mime_content_type($filePath) ?: 'audio/mpeg';
            $multipart = [
                'model' => $payload['model'] ?? 'gapgpt/whisper-1',
                'file'  => ['isFile' => true, 'path' => $filePath, 'mime' => $mime, 'name' => basename($filePath)],
            ];
            foreach (['language', 'prompt', 'response_format', 'temperature'] as $opt) {
                if (isset($payload[$opt])) $multipart[$opt] = $payload[$opt];
            }
            return _result(http_request('POST', $url, $headers, $multipart));

        case 'speech':
            $url = $base . '/audio/speech';
            $body = [
                'model' => $payload['model'] ?? 'tts-1',
                'input' => $payload['input'] ?? '',
                'voice' => $payload['voice'] ?? 'alloy',
            ];
            $r = http_request('POST', $url, $headers, $body);
            if ($r['error']) return ['ok' => false, 'response' => '', 'error' => mtr('err.conn', ['{err}' => $r['error']])];
            if ($r['status'] >= 200 && $r['status'] < 300) {
                return ['ok' => true, 'response' => json_encode(['audio_base64' => base64_encode($r['body']), 'format' => $payload['response_format'] ?? 'mp3']), 'error' => ''];
            }
            return _result($r);

        case 'embeddings':
            $url = $base . '/embeddings';
            $body = [
                'model' => $payload['model'] ?? 'gemini-embedding-001',
                'input' => $payload['input'] ?? '',
            ];
            foreach (['encoding_format', 'dimensions'] as $opt) {
                if (isset($payload[$opt])) $body[$opt] = $payload[$opt];
            }
            return _result(http_request('POST', $url, $headers, $body));

        default:
            return ['ok' => false, 'response' => '', 'error' => mtr('err.invalidAction', ['{action}' => $action])];
    }
}

/**
 * پردازش نتیجه HTTP و بازگشت فرمت استاندارد ورکر.
 */
function _result(array $r): array {
    if ($r['error']) return ['ok' => false, 'response' => '', 'error' => mtr('err.conn', ['{err}' => $r['error']])];
    $code = $r['status']; $body = $r['body'];
    if ($code >= 200 && $code < 300) return ['ok' => true, 'response' => $body, 'error' => ''];
    $json = json_decode($body, true);
    $msg = "HTTP {$code}";
    if (is_array($json)) {
        if (isset($json['error']['message'])) $msg .= ': ' . $json['error']['message'];
        elseif (isset($json['message'])) $msg .= ': ' . $json['message'];
        elseif (isset($json['detail'])) $msg .= ': ' . (is_string($json['detail']) ? $json['detail'] : json_encode($json['detail']));
        elseif (isset($json['error'])) $msg .= ': ' . (is_string($json['error']) ? $json['error'] : json_encode($json['error']));
    } else {
        $msg .= ': ' . substr($body, 0, 300);
    }
    return ['ok' => false, 'response' => $body, 'error' => $msg];
}

// ================================================================
// توابع ساخت HTTP request (برای پردازش موازی با curl_multi)
// ================================================================

/**
 * ساخت HTTP request برای chat worker (OpenAI-compatible).
 * @return array|null ['url', 'method', 'headers', 'body'] یا null
 */
function build_chat_request(string $base, string $defaultModel, string $action, array $payload, array $headers): ?array {
    switch ($action) {
        case 'chat':
            $body = [
                'model' => $payload['model'] ?? $defaultModel,
                'messages' => $payload['messages'] ?? [['role' => 'user', 'content' => $payload['input'] ?? '']],
            ];
            foreach (['temperature', 'max_tokens', 'stream', 'top_p', 'frequency_penalty', 'presence_penalty', 'tools', 'tool_choice', 'stop', 'thinking', 'reasoning_effort', 'chat_template_kwargs'] as $opt) {
                if (isset($payload[$opt])) $body[$opt] = $payload[$opt];
            }
            return ['url' => $base . '/chat/completions', 'method' => 'POST', 'headers' => $headers, 'body' => $body];
        case 'models':
            return ['url' => $base . '/models', 'method' => 'GET', 'headers' => $headers, 'body' => null];
        default:
            return null;
    }
}

/**
 * ساخت HTTP request برای gapgpt worker.
 */
function build_gapgpt_request(string $base, string $defaultModel, string $action, array $payload, array $headers): ?array {
    // chat و models از build_chat_request استفاده می‌کنند
    if ($action === 'chat' || $action === 'models') {
        return build_chat_request($base, $defaultModel, $action, $payload, $headers);
    }

    switch ($action) {
        case 'responses':
            $body = ['model' => $payload['model'] ?? $defaultModel, 'input' => $payload['input'] ?? ''];
            foreach (['temperature', 'max_output_tokens', 'stream', 'instructions'] as $opt) {
                if (isset($payload[$opt])) $body[$opt] = $payload[$opt];
            }
            return ['url' => $base . '/responses', 'method' => 'POST', 'headers' => $headers, 'body' => $body];
        case 'images':
            $body = ['model' => $payload['model'] ?? 'gpt-image-2', 'prompt' => $payload['prompt'] ?? '', 'size' => $payload['size'] ?? '1024x1024'];
            foreach (['n', 'response_format', 'quality'] as $opt) {
                if (isset($payload[$opt])) $body[$opt] = $payload[$opt];
            }
            return ['url' => $base . '/images/generations', 'method' => 'POST', 'headers' => $headers, 'body' => $body];
        case 'speech':
            $body = ['model' => $payload['model'] ?? 'tts-1', 'input' => $payload['input'] ?? '', 'voice' => $payload['voice'] ?? 'alloy'];
            return ['url' => $base . '/audio/speech', 'method' => 'POST', 'headers' => $headers, 'body' => $body];
        case 'embeddings':
            $body = ['model' => $payload['model'] ?? 'gemini-embedding-001', 'input' => $payload['input'] ?? ''];
            return ['url' => $base . '/embeddings', 'method' => 'POST', 'headers' => $headers, 'body' => $body];
        case 'transcriptions':
            // STT نیاز به multipart دارد - در حالت موازی پشتیبانی نمی‌شود
            return null;
        default:
            return null;
    }
}

// پایان workers.php
