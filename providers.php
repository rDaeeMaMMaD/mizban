<?php
/**
 * میزبان - Provider Driver Interface
 * ------------------------------------------------------------------
 * معماری Driver:
 *
 *   ProviderDriver (abstract interface)
 *       ↓
 *   OpenAICompatibleDriver  — همه API های سازگار با OpenAI (DeepSeek, Z.ai, NVIDIA, OpenRouter)
 *       ↓
 *   GapGPTDriver            — endpoint های خاص گپ‌جی‌پی‌تی (responses, images, audio)
 *
 * برای افزودن ارائه‌دهنده جدید با فرمت غیراستاندارد:
 *   ۱. یک پوشه در providers/ بساز
 *   ۲. manifest.json + driver.php در اون پوشه بذار
 *   ۳. ProviderRegistry خودکار شناسایی می‌کنه (Auto Discovery)
 * نه if/else، نه DriverFactory، نه تغییر هسته.
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/interfaces.php';

/**
 * Provider Driver Interface (abstract).
 * هر driver باید این متدها رو پیاده‌سازی کنه.
 */
abstract class ProviderDriver implements ProviderDriverInterface {
    protected string $baseUrl;
    protected string $apiKey;
    protected string $defaultModel;
    protected array $provider;

    public function __construct(array $provider, string $apiKey, string $defaultModel) {
        $this->baseUrl = rtrim($provider['baseurl'], '/');
        $this->apiKey = $apiKey;
        $this->defaultModel = $defaultModel;
        $this->provider = $provider;
    }

    /**
     * اجرای درخواست.
     * @param string $action اکشن (chat, models, responses, images, ...)
     * @param array $payload پارامترها
     * @return array ['ok' => bool, 'response' => string, 'error' => string, 'http_code' => int]
     */
    abstract public function execute(string $action, array $payload): array;

    /**
     * لیست اکشن‌های پشتیبانی‌شده.
     */
    abstract public function getSupportedActions(): array;

    /**
     * HTTP request کمکی.
     */
    protected function http(string $method, string $url, array $headers, $body = null, int $timeout = 0): array {
        return http_request($method, $url, $headers, $body, $timeout);
    }

    protected function headers(): array {
        return ['Content-Type: application/json', 'Authorization: Bearer ' . $this->apiKey];
    }

    protected function processResult(array $r): array {
        if ($r['error']) return ['ok' => false, 'response' => '', 'error' => mtr('err.conn', ['{err}' => $r['error']]), 'http_code' => 0];
        if ($r['status'] >= 200 && $r['status'] < 300) return ['ok' => true, 'response' => $r['body'], 'error' => '', 'http_code' => $r['status']];
        $json = json_decode($r['body'], true);
        $msg = "HTTP {$r['status']}";
        if (is_array($json)) {
            if (isset($json['error']['message'])) $msg .= ': ' . $json['error']['message'];
            elseif (isset($json['message'])) $msg .= ': ' . $json['message'];
            elseif (isset($json['detail'])) $msg .= ': ' . (is_string($json['detail']) ? $json['detail'] : json_encode($json['detail']));
        } else {
            // پاسخ HTML یا غیر JSON — متن رو extract کن
            $body = $r['body'] ?? '';
            // اگه HTML بود، tagها رو حذف کن و فقط متن قابل‌خواندن رو نگه دار
            if ($body !== '' && preg_match('/<\w+[^>]*>/i', $body)) {
                // حذف script و style و comment
                $text = preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $body);
                $text = preg_replace('#<!--.*?-->#s', ' ', $text);
                // تبدیل <br> و <p> به newline
                $text = preg_replace('#<(br|/p|/div|/h[1-6])[^>]*>#i', "\n", $text);
                // حذف سایر tagها
                $text = strip_tags($text);
                // decode موجودیت‌های HTML
                $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                // فشرده‌سازی whitespace
                $text = trim(preg_replace('/[ \t]+/', ' ', $text));
                $text = preg_replace('/\n{3,}/', "\n\n", $text);
                $msg .= ' (HTML): ' . mb_substr($text, 0, 300);
            } else {
                $msg .= ': ' . substr($body, 0, 300);
            }
        }
        return ['ok' => false, 'response' => $r['body'], 'error' => $msg, 'http_code' => $r['status']];
    }
}

/**
 * OpenAI-Compatible Driver.
 * برای همه API هایی که /chat/completions و /models دارن:
 * DeepSeek, Z.ai (GLM), NVIDIA NIM, OpenRouter, و غیره.
 */
class OpenAICompatibleDriver extends ProviderDriver {
    public function getSupportedActions(): array {
        return ['chat', 'models'];
    }

    public function execute(string $action, array $payload): array {
        switch ($action) {
            case 'chat':
                $body = [
                    'model' => $payload['model'] ?? $this->defaultModel,
                    'messages' => $payload['messages'] ?? [['role' => 'user', 'content' => $payload['input'] ?? '']],
                ];
                foreach (['temperature', 'max_tokens', 'stream', 'top_p', 'frequency_penalty', 'presence_penalty', 'tools', 'tool_choice', 'stop'] as $opt) {
                    if (isset($payload[$opt])) $body[$opt] = $payload[$opt];
                }
                // پارامترهای reasoning (Z.ai, DeepSeek)
                foreach (['thinking', 'reasoning_effort', 'chat_template_kwargs'] as $opt) {
                    if (isset($payload[$opt])) $body[$opt] = $payload[$opt];
                }
                $r = $this->http('POST', $this->baseUrl . '/chat/completions', $this->headers(), $body);
                return $this->processResult($r);

            case 'models':
                $r = $this->http('GET', $this->baseUrl . '/models', $this->headers());
                return $this->processResult($r);

            default:
                return ['ok' => false, 'response' => '', 'error' => "اکشن '{$action}' پشتیبانی نمی‌شود", 'http_code' => 0];
        }
    }
}

/**
 * GapGPT Driver.
 * Endpoint های خاص گپ‌جی‌پی‌تی: responses, images, audio, embeddings.
 * chat و models هم از OpenAI-compatible استفاده می‌کنه.
 */
class GapGPTDriver extends ProviderDriver {
    public function getSupportedActions(): array {
        return ['chat', 'models', 'responses', 'images', 'transcriptions', 'speech', 'embeddings'];
    }

    public function execute(string $action, array $payload): array {
        // chat و models از OpenAI-compatible
        if ($action === 'chat' || $action === 'models') {
            return (new OpenAICompatibleDriver($this->provider, $this->apiKey, $this->defaultModel))->execute($action, $payload);
        }

        switch ($action) {
            case 'responses':
                $body = ['model' => $payload['model'] ?? $this->defaultModel, 'input' => $payload['input'] ?? ''];
                foreach (['temperature', 'max_output_tokens', 'stream', 'instructions'] as $opt) {
                    if (isset($payload[$opt])) $body[$opt] = $payload[$opt];
                }
                $r = $this->http('POST', $this->baseUrl . '/responses', $this->headers(), $body);
                return $this->processResult($r);

            case 'images':
                $body = ['model' => $payload['model'] ?? 'gpt-image-2', 'prompt' => $payload['prompt'] ?? '', 'size' => $payload['size'] ?? '1024x1024'];
                foreach (['n', 'response_format', 'quality'] as $opt) {
                    if (isset($payload[$opt])) $body[$opt] = $payload[$opt];
                }
                $r = $this->http('POST', $this->baseUrl . '/images/generations', $this->headers(), $body);
                return $this->processResult($r);

            case 'speech':
                $body = ['model' => $payload['model'] ?? 'tts-1', 'input' => $payload['input'] ?? '', 'voice' => $payload['voice'] ?? 'alloy'];
                $r = $this->http('POST', $this->baseUrl . '/audio/speech', $this->headers(), $body);
                if ($r['error']) return ['ok' => false, 'response' => '', 'error' => mtr('err.conn', ['{err}' => $r['error']]), 'http_code' => 0];
                if ($r['status'] >= 200 && $r['status'] < 300) {
                    return ['ok' => true, 'response' => json_encode(['audio_base64' => base64_encode($r['body'])]), 'error' => '', 'http_code' => $r['status']];
                }
                return $this->processResult($r);

            case 'embeddings':
                $body = ['model' => $payload['model'] ?? 'gemini-embedding-001', 'input' => $payload['input'] ?? ''];
                $r = $this->http('POST', $this->baseUrl . '/embeddings', $this->headers(), $body);
                return $this->processResult($r);

            case 'transcriptions':
                return ['ok' => false, 'response' => '', 'error' => mtr('err.sttUpload'), 'http_code' => 0];

            default:
                return ['ok' => false, 'response' => '', 'error' => mtr('err.invalidAction', ['{action}' => $action]), 'http_code' => 0];
        }
    }
}

// DriverFactory حذف شد — از ProviderRegistry استفاده کنید (Auto Discovery)

class GeminiDriver extends ProviderDriver {
    public function getSupportedActions(): array { return ['chat', 'models']; }
    public function execute(string $action, array $payload): array {
        $headers = ['Content-Type: application/json', 'x-goog-api-key: ' . $this->apiKey];
        if ($action === 'models') {
            $r = $this->http('GET', $this->baseUrl . '/models?key=' . $this->apiKey, ['Content-Type: application/json']);
            return $this->processResult($r);
        }
        if ($action !== 'chat') return ['ok' => false, 'response' => '', 'error' => 'Unsupported', 'http_code' => 0];
        
        // Convert messages to Gemini format
        $contents = [];
        if (!empty($payload['messages'])) {
            foreach ($payload['messages'] as $m) {
                $role = ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user';
                $content = is_string($m['content']) ? $m['content'] : json_encode($m['content']);
                $contents[] = ['role' => $role, 'parts' => [['text' => $content]]];
            }
        } else {
            $input = $payload['input'] ?? '';
            $contents[] = ['role' => 'user', 'parts' => [['text' => $input]]];
        }
        
        $body = ['contents' => $contents];
        if (isset($payload['temperature'])) $body['generationConfig']['temperature'] = $payload['temperature'];
        if (isset($payload['max_tokens'])) $body['generationConfig']['maxOutputTokens'] = $payload['max_tokens'];
        
        // استاندارد Gemini API: /v1beta/models/{model}:generateContent
        // نکته: endpoint /interactions حذف شد چون endpoint رسمی Gemini نیست و
        // باعث خطای 403 مبهم می‌شد. حالا فقط generateContent استفاده می‌شه.
        $modelSlug = urlencode($this->defaultModel);
        $r = $this->http('POST', $this->baseUrl . '/models/' . $modelSlug . ':generateContent', $headers, $body);

        // generateContent response
        if ($r['error']) return ['ok' => false, 'response' => '', 'error' => 'Connection: ' . $r['error'], 'http_code' => 0];
        if ($r['status'] >= 200 && $r['status'] < 300) {
            $j = json_decode($r['body'], true);
            $text = '';
            if (is_array($j) && !empty($j['candidates'])) {
                foreach ($j['candidates'] as $candidate) {
                    if (!empty($candidate['content']['parts'])) {
                        foreach ($candidate['content']['parts'] as $part) {
                            if (!empty($part['text'])) $text .= $part['text'];
                        }
                    }
                }
            }
            $fmt = ['id' => 'gemini-' . time(), 'object' => 'chat.completion', 'created' => time(), 'model' => $this->defaultModel, 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']], 'usage' => $j['usageMetadata'] ?? []];
            return ['ok' => true, 'response' => json_encode($fmt), 'error' => '', 'http_code' => $r['status']];
        }
        return $this->processResult($r);
    }
}

class RapidAPIDriver extends ProviderDriver {
    public function getSupportedActions(): array { return ['chat']; }
    public function execute(string $action, array $payload): array {
        if ($action !== 'chat') return ['ok' => false, 'response' => '', 'error' => 'Unsupported', 'http_code' => 0];
        $messages = $payload['messages'] ?? [['role' => 'user', 'content' => $payload['input'] ?? '']];
        if (strpos($this->defaultModel, 'chatgpt') !== false) {
            $url = 'https://chatgpt-42.p.rapidapi.com/conversationgpt4-2'; $host = 'chatgpt-42.p.rapidapi.com';
            $body = ['messages' => $messages, 'system_prompt' => '', 'temperature' => 0.9, 'top_k' => 5, 'top_p' => 0.9, 'max_tokens' => 256, 'web_access' => false];
        } elseif (strpos($this->defaultModel, 'deepseek') !== false) {
            $url = 'https://deepseek-all-in-one.p.rapidapi.com/reasoner'; $host = 'deepseek-all-in-one.p.rapidapi.com'; $body = ['messages' => $messages];
        } elseif (strpos($this->defaultModel, 'claude') !== false) {
            $url = 'https://claude-3-7-sonnet.p.rapidapi.com/'; $host = 'claude-3-7-sonnet.p.rapidapi.com'; $body = ['model' => 'claude-3-7-sonnet', 'messages' => $messages];
        } else {
            $url = 'https://chatgpt-42.p.rapidapi.com/conversationgpt4-2'; $host = 'chatgpt-42.p.rapidapi.com';
            $body = ['messages' => $messages, 'system_prompt' => '', 'temperature' => 0.9, 'top_k' => 5, 'top_p' => 0.9, 'max_tokens' => 256, 'web_access' => false];
        }
        $headers = ['Content-Type: application/json', 'x-rapidapi-key: ' . $this->apiKey, 'x-rapidapi-host: ' . $host];
        $r = $this->http('POST', $url, $headers, $body);
        if ($r['error']) return ['ok' => false, 'response' => '', 'error' => 'Connection: ' . $r['error'], 'http_code' => 0];
        if ($r['status'] >= 200 && $r['status'] < 300) {
            $j = json_decode($r['body'], true); $text = '';
            if (is_string($j)) $text = $j;
            elseif (is_array($j)) {
                if (isset($j['result']) && is_string($j['result'])) $text = $j['result'];
                elseif (isset($j['response']) && is_string($j['response'])) $text = $j['response'];
                elseif (isset($j['choices'][0]['message']['content'])) $text = $j['choices'][0]['message']['content'];
                elseif (isset($j['content'])) $text = is_string($j['content']) ? $j['content'] : json_encode($j['content']);
                elseif (isset($j['text'])) $text = $j['text'];
                elseif (isset($j['answer'])) $text = $j['answer'];
                else $text = substr($r['body'], 0, 2000);
            } else $text = substr($r['body'], 0, 2000);
            $fmt = ['id' => 'rapidapi-' . time(), 'object' => 'chat.completion', 'created' => time(), 'model' => $this->defaultModel, 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']]];
            return ['ok' => true, 'response' => json_encode($fmt), 'error' => '', 'http_code' => $r['status']];
        }
        $json = json_decode($r['body'], true); $msg = "HTTP {$r['status']}";
        if (is_array($json)) { if (isset($json['error']['message'])) $msg .= ': ' . $json['error']['message']; elseif (isset($json['message'])) $msg .= ': ' . $json['message']; }
        elseif (strpos($r['body'], '<!DOCTYPE') !== false) $msg .= ': HTML response';
        else $msg .= ': ' . substr($r['body'], 0, 300);
        return ['ok' => false, 'response' => '', 'error' => $msg, 'http_code' => $r['status']];
    }
}

// پایان providers.php
