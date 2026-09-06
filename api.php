<?php
/**
 * میزبان (Host) - API Endpoint v2.0
 * ------------------------------------------------------------------
 * نقطه ورود همه درخواست‌های API.
 */

// خطاهای fatal رو به JSON تبدیل کن — بدون افشای جزئیات به کلاینت (مگر HOST_DEBUG)
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        $out = ['ok' => false, 'error' => function_exists('t') ? t('api.serverError') : 'Internal server error'];
        if (defined('HOST_DEBUG') && HOST_DEBUG) {
            $out['detail'] = [
                'message' => $err['message'] ?? '',
                'file'    => basename($err['file'] ?? ''),
                'line'    => $err['line'] ?? 0,
            ];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
    }
});

error_reporting(E_ALL);
ini_set('display_errors', (defined('HOST_DEBUG') && HOST_DEBUG) ? '1' : '0');
ini_set('log_errors', '1');
@set_time_limit(120);

// require با error handling
$requiredFiles = [
    __DIR__ . '/config.php', __DIR__ . '/lang.php', __DIR__ . '/core.php', __DIR__ . '/queue.php',
    __DIR__ . '/load_balancer.php', __DIR__ . '/key_pool.php', __DIR__ . '/providers.php',
    __DIR__ . '/provider_registry.php', __DIR__ . '/circuit_breaker.php',
    __DIR__ . '/response_manager.php', __DIR__ . '/config_manager.php',
    __DIR__ . '/metrics.php', __DIR__ . '/queue_cleaner.php',
    __DIR__ . '/scheduler.php', __DIR__ . '/worker.php', __DIR__ . '/dispatcher.php',
    __DIR__ . '/policy_engine.php', __DIR__ . '/cost_engine.php',
    __DIR__ . '/lease_manager.php', __DIR__ . '/event_bus.php', __DIR__ . '/queue_stats_service.php',
];
foreach ($requiredFiles as $f) {
    if (!file_exists($f)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        $fileMsg = function_exists('t') ? t('api.fileMissing', ['{file}' => basename($f)]) : 'File not found: ' . basename($f);
        echo json_encode(['ok' => false, 'error' => $fileMsg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    require_once $f;
}

apply_cors();
send_security_headers();

// session قبل از هر چیز (کوکی امن: HttpOnly + SameSite=Lax + Secure روی HTTPS)
secure_session_start(ADMIN_SESSION_NAME);

// db_init با error handling (بدون افشای جزئیات اتصال به کلاینت)
try {
    db_init();
} catch (Exception $e) {
    $err = ['ok' => false, 'error' => t('api.dbConnect')];
    if (HOST_DEBUG) $err['detail'] = $e->getMessage();
    json_response($err, 500);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($action) {
        // ===== مسیرهای عمومی =====
        case 'send':
            if ($method !== 'POST') json_response(['error' => t('api.badMethod')], 405);
            handle_send(); break;
        case 'status': handle_status(); break;
        case 'result': handle_result(); break;
        case 'process': handle_process(); break;
        case 'cleanup': handle_cleanup(); break;
        case 'health_check': require_admin(); handle_health_check(); break;
        case 'cron_status': require_admin(); json_response(['ok' => true, 'crons' => cron_status_list(), 'workers' => worker_list()]); break;
        // آمار و متریک‌ها فقط برای ادمین لاگین‌شده (اطلاعات داخلی سیستم)
        case 'stats': require_admin(); json_response(['ok' => true, 'stats' => queue_stats()]); break;
        case 'detailed_stats': {
            require_admin();
            $qss = new QueueStatsService();
            json_response(['ok' => true, 'data' => $qss->getDetailedStats()]);
        }
        case 'advanced_stats': require_admin(); json_response(['ok' => true, 'data' => advanced_stats()]); break;
        case 'metrics': require_admin(); json_response(['ok' => true, 'data' => (new MetricsService())->getSystemMetrics()]); break;
        case 'cb_reset': require_admin(); handle_cb_reset(); break;
        case 'migrate': require_admin(); require_once __DIR__ . '/migrate.php'; break;
        case 'models': {
            $models = model_list();
            $out = [];
            foreach ($models as $m) {
                if ($m['status'] == 1) {
                    $out[] = ['model_id' => $m['model_id'], 'name' => $m['display_name'], 'provider' => $m['provider_slug'], 'fallback' => $m['fallback_model']];
                }
            }
            json_response(['ok' => true, 'models' => $out]);
        }

        // ===== Policy Engine: سیاست کلاینت =====
        case 'policy': {
            $code = $_SERVER['HTTP_X_CLIENT_CODE'] ?? '';
            $secret = $_SERVER['HTTP_X_CLIENT_SECRET'] ?? '';
            $client = auth_client($code, $secret);
            if (!$client) json_response(['error' => t('api.authRequired')], 401);
            $policy = (new PolicyEngine())->getPolicy($client);
            json_response(['ok' => true, 'policy' => $policy]);
        }

        // ===== Cost Engine: هزینه مدل‌ها =====
        case 'cost_models': {
            $capability = $_GET['capability'] ?? '';
            $list = (new CostEngine())->listModelsWithCost($capability);
            json_response(['ok' => true, 'models' => $list]);
        }
        case 'cost_cheapest': {
            $capability = $_GET['capability'] ?? 'chat';
            $cheapest = (new CostEngine())->getCheapestModel($capability);
            json_response(['ok' => true, 'cheapest' => $cheapest]);
        }
        case 'cost_estimate': {
            $model = $_GET['model'] ?? '';
            $tokens = (int)($_GET['tokens'] ?? 1000);
            if (!$model) json_response(['error' => t('api.modelRequired')], 400);
            $engine = new CostEngine();
            json_response(['ok' => true, 'model' => $model, 'tokens' => $tokens, 'cost_per_1k' => $engine->estimateCost($model), 'total_cost' => $engine->estimateRequestCost($model, $tokens)]);
        }

        // ===== ادمین: احراز هویت =====
        case 'admin_login':
            if ($method !== 'POST') json_response(['error' => t('api.badMethod')], 405);
            handle_admin_login(); break;
        case 'admin_logout': {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                if (ini_get('session.use_cookies')) {
                    $p = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $p['path'] ?? '/', $p['domain'] ?? '', !empty($p['secure']), !empty($p['httponly']));
                }
                session_destroy();
            }
            json_response(['ok' => true]);
            break;
        }
        case 'admin_session': json_response(['ok' => true, 'logged_in' => admin_is_logged_in()]); break;
        case 'admin_change_password': require_admin(); handle_admin_change_password(); break;

        // ===== ادمین: ارائه‌دهنده‌ها =====
        case 'admin_providers': require_admin(); json_response(['ok' => true, 'providers' => provider_list()]); break;
        case 'admin_provider_create': require_admin(); handle_provider_create(); break;
        case 'admin_provider_update': require_admin(); handle_provider_update(); break;
        case 'admin_provider_delete': require_admin(); handle_provider_delete(); break;

        // ===== ادمین: کلیدها =====
        case 'admin_keys': require_admin(); {
            $pid = (int)($_GET['provider_id'] ?? 0);
            json_response(['ok' => true, 'keys' => key_list($pid)]);
        }
        case 'admin_key_create': require_admin(); handle_key_create(); break;
        case 'admin_key_update': require_admin(); handle_key_update(); break;
        case 'admin_key_delete': require_admin(); {
            $body = get_json_body();
            $ok = key_delete((int)($body['id'] ?? 0));
            json_response(['ok' => $ok]);
        }

        // ===== ادمین: مدل‌ها =====
        case 'admin_models': require_admin(); json_response(['ok' => true, 'models' => model_list()]); break;
        case 'admin_model_create': require_admin(); handle_model_create(); break;
        case 'admin_model_update': require_admin(); handle_model_update(); break;
        case 'admin_model_delete': require_admin(); {
            $body = get_json_body();
            $ok = model_delete((int)($body['id'] ?? 0));
            json_response(['ok' => $ok]);
        }

        // ===== ادمین: کلاینت‌ها =====
        case 'admin_clients': require_admin(); json_response(['ok' => true, 'clients' => client_list()]); break;
        case 'admin_client_create': require_admin(); handle_client_create(); break;
        case 'admin_client_update': require_admin(); handle_client_update(); break;
        case 'admin_client_delete': require_admin(); {
            $body = get_json_body();
            $ok = client_delete((int)($body['id'] ?? 0));
            json_response(['ok' => $ok]);
        }

        // ===== ادمین: درخواست‌ها =====
        case 'admin_requests': require_admin(); {
            $page = max(1, (int)($_GET['page'] ?? 1));
            $status = $_GET['status'] ?? '';
            $perPage = (int)($_GET['per_page'] ?? 50);
            json_response(['ok' => true, 'requests' => request_list($page, $perPage, $status), 'total' => request_count($status), 'page' => $page, 'per_page' => $perPage]);
        }
        case 'admin_reprocess': require_admin(); {
            if ($method !== 'POST') json_response(['error' => t('api.badMethod')], 405);
            $body = get_json_body();
            $id = (int)($body['id'] ?? 0);
            $stmt = db()->prepare("UPDATE requests SET status = 'queued', error = NULL, attempts = 0 WHERE id = ? AND status IN ('failed','completed')");
            $stmt->execute([$id]);
            json_response(['ok' => (bool)$stmt->rowCount()]);
        }

        // ===== ادمین: لاگ‌ها =====
        case 'admin_logs': require_admin(); {
            $limit = min(500, max(10, (int)($_GET['limit'] ?? 100)));
            $level = $_GET['level'] ?? '';
            json_response(['ok' => true, 'logs' => log_list($limit, $level)]);
        }

        case 'admin_reset_keys': require_admin(); {
            $pdo = db();
            $pdo->exec("UPDATE api_keys SET status = 1, consecutive_errors = 0, cooldown_until = NULL");
            $count = (int)$pdo->query("SELECT COUNT(*) FROM api_keys")->fetchColumn();
            audit_log('keys.reset_all', "Reset all keys ({$count} keys)", ['reset_count' => $count]);
            json_response(['ok' => true, 'reset_count' => $count]);
        }
        default: json_response(['ok' => true, 'name' => HOST_NAME, 'version' => HOST_VERSION, 'time' => date('Y-m-d H:i:s')]);
    }
} catch (Throwable $e) {
    log_error('API exception: ' . $e->getMessage());
    $errOut = ['error' => t('api.internal')];
    if (HOST_DEBUG) $errOut['detail'] = $e->getMessage();
    json_response($errOut, 500);
}

// ================================================================
// هندلرهای عمومی
// ================================================================

function handle_send(): void {
    $code = $_SERVER['HTTP_X_CLIENT_CODE'] ?? '';
    $secret = $_SERVER['HTTP_X_CLIENT_SECRET'] ?? '';
    if (!$code && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
            $parts = explode(':', $m[1], 2);
            if (count($parts) === 2) { $code = $parts[0]; $secret = $parts[1]; }
        }
    }

    // ===== لایه ۱: احراز هویت (با محدودیت تلاش ناموفق) =====
    $authTh = auth_throttle_check('client_auth', client_ip(), 15);
    if (!$authTh['ok']) {
        json_response([
            'ok' => false,
            'ack' => true,
            'status' => 'rate_limited',
            'message' => t('api.tooManyAuth'),
            'error' => t('api.tooManyAuth'),
            'retry_after' => $authTh['retry_after'],
        ], 429);
    }
    $client = auth_client($code, $secret);
    if (!$client) {
        auth_throttle_fail('client_auth', client_ip());
        log_warn('Authentication failed', ['code' => $code]);
        // ACK نامعتبر
        json_response([
            'ok' => false,
            'ack' => true,
            'status' => 'rejected',
            'message' => t('api.ackRejected') . ': ' . t('api.clientSecretInvalid'),
            'error' => t('api.clientSecretInvalid'),
        ], 401);
    }
    auth_throttle_clear('client_auth', client_ip());

    // ===== لایه ۲: Rate Limiting چندلایه (Minute/Hour/Day/Month) =====
    $rl = rate_limit_check_multi('client', $client['code']);
    if (!$rl['ok']) {
        json_response([
            'ok' => false,
            'ack' => true,
            'status' => 'rate_limited',
            'message' => t('api.rateLimited'),
            'retry_after' => $rl['retry_after'],
            'limits' => $rl['limits'],
        ], 429);
    }

    // ===== لایه ۲.۵: اعتبارسنجی HMAC/Nonce/Timestamp (اگه فعال باشه) =====
    $rawBody = file_get_contents('php://input') ?: '';
    $sec = security_validate_request($_SERVER['REQUEST_METHOD'] ?? 'POST', $_SERVER['REQUEST_URI'] ?? '/api.php', $rawBody);
    if (!$sec['ok']) {
        json_response(['ok' => false, 'ack' => true, 'status' => 'rejected', 'message' => t('api.ackRejected') . ': ' . $sec['error']], 401);
    }

    $body = get_json_body();
    $mode = trim($body['mode'] ?? 'specific');
    if (!in_array($mode, ['general', 'specific'])) $mode = 'specific';
    $async = !empty($body['async']);
    $model = trim($body['model'] ?? '');
    $actionName = trim($body['action'] ?? 'chat');
    $payload = $body['payload'] ?? [];
    $callbackUrl = trim($body['callback_url'] ?? '');
    $idempotencyKey = trim($body['idempotency_key'] ?? '');
    $correlationId = trim($body['correlation_id'] ?? '');
    if (!$correlationId) $correlationId = gen_token(16);

    // ===== لایه ۲.۸: Back Pressure — اگه صف پر است، درخواست رد کن =====
    $queueStats = queue_stats();
    if (($queueStats['queued'] ?? 0) > 1000) {
        log_warn('Back pressure: queue is full (>1000)', ['queued' => $queueStats['queued']]);
        json_response([
            'ok' => false,
            'ack' => true,
            'status' => 'rejected',
            'message' => t('api.capacity'),
            'error' => t('api.capacity'),
            'retry_after' => 30,
        ], 503);
    }

    // ===== لایه ۳: اعتبارسنجی درخواست =====
    if ($mode === 'specific') {
        if (!$model) {
            json_response(['ok' => false, 'ack' => true, 'status' => 'rejected', 'message' => t('api.ackRejected') . ': ' . t('api.modelRequired')], 400);
        }
        $modelRow = model_find($model);
        if (!$modelRow) {
            json_response(['ok' => false, 'ack' => true, 'status' => 'rejected', 'message' => t('api.ackRejected') . ': ' . t('api.modelNotActive', ['{model}' => $model])], 400);
        }
    } else {
        if (!$model) $model = '__general__';
    }
    if ($callbackUrl && !is_safe_callback_url($callbackUrl)) {
        json_response(['ok' => false, 'ack' => true, 'status' => 'rejected', 'message' => t('api.ackRejected') . ': ' . t('api.callbackInvalid')], 400);
    }

    // ===== لایه ۴: Idempotency Check =====
    if ($idempotencyKey) {
        $existing = idempotency_check($idempotencyKey);
        if ($existing) {
            // جواب قبلی رو برگردون
            $response = null;
            if (!empty($existing['response'])) {
                $decoded = json_decode($existing['response'], true);
                $response = $decoded !== null ? $decoded : $existing['response'];
            }
            log_info("Idempotency: duplicate key '{$idempotencyKey}' - returning previous result", ['request_id' => $existing['id']]);
            json_response([
                'ok' => true,
                'ack' => true,
                'request_id' => (int)$existing['id'],
                'status' => $existing['status'],
                'sync' => true,
                'idempotent' => true,
                'provider' => $existing['provider_slug'],
                'response' => $response,
                'message' => t('api.duplicateKey'),
            ], 200);
        }
    }

    // امنیت: کلاینت نمی‌تواند اولویت بالاتر از درجه خودش بفرستد
    $priority = (int)$client['degree'];
    if (isset($body['priority'])) {
        $reqP = (int)$body['priority'];
        if ($reqP >= 1 && $reqP <= $priority) $priority = $reqP;
    }

    // ===== لایه ۵: ثبت در صف + ACK فوری (via Dispatcher) =====
    rate_limit_increment('client', $client['code']);

    $dispatcher = new Dispatcher();
    $streamMode = !empty($body['stream']) ? 1 : 0;
    $recv = $dispatcher->receive($client, $mode, $model, $actionName, $payload,
                                 $priority, $callbackUrl ?: null, $idempotencyKey ?: null,
                                 $correlationId, $streamMode);
    $id = $recv['id'];
    log_info("Request #{$id} queued", ['client' => $client['code'], 'mode' => $mode, 'model' => $model, 'action' => $actionName, 'async' => $async, 'position' => $recv['position']]);

    // حالت async: فقط ACK برمی‌گرده
    if ($async) {
        json_response(array_merge(['ok' => true, 'mode' => $mode, 'priority' => $priority,
            'sync' => false, 'correlation_id' => $correlationId], $recv['ack']), 201);
    }

    // ===== لایه ۶: پردازش همزمان (Sync) — Dispatcher → Worker =====
    if (QUEUE_SYNC_PROCESS) {
        $r = $dispatcher->dispatchSync($id);
        if ($r['processed']) {
            $req = queue_status($id);
            $response = null;
            if (!empty($req['response'])) {
                $decoded = json_decode($req['response'], true);
                $response = $decoded !== null ? $decoded : $req['response'];
            }

            if ($r['status'] === 'completed') {
                json_response([
                    'ok' => true, 'ack' => true, 'request_id' => $id, 'mode' => $mode,
                    'priority' => $priority, 'status' => 'completed', 'sync' => true,
                    'provider' => $r['provider'] ?? null, 'fallback_used' => $r['fallback'] ?? null,
                    'latency_ms' => $r['latency_ms'] ?? null,
                    'total_time_ms' => $r['total_time_ms'] ?? null,
                    'queue_time_ms' => $req['queue_time_ms'] ?? null,
                    'correlation_id' => $correlationId,
                    'worker_id' => $r['worker_id'] ?? null,
                    'response' => $response,
                    'message' => t('api.completedMsg'),
                ], 200);
            } else {
                json_response([
                    'ok' => false, 'ack' => true, 'request_id' => $id, 'mode' => $mode,
                    'status' => $r['status'], 'sync' => true,
                    'correlation_id' => $correlationId,
                    'error' => $r['error'] ?? t('api.processingFailed'),
                    'error_type' => $r['error_type'] ?? null,
                    'message' => t('api.failMsg'),
                ], 502);
            }
        }
    }

    // fallback
    json_response([
        'ok' => true,
        'ack' => true,
        'request_id' => $id,
        'mode' => $mode,
        'priority' => $priority,
        'status' => 'queued',
        'sync' => false,
        'correlation_id' => $correlationId,
        'message' => t('api.queuedMsg'),
    ], 201);
}

function handle_cb_reset(): void {
    $body = get_json_body();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_response(['error' => t('api.idRequired')], 400);
    $ok = cb_reset($id);
    audit_log('circuit_breaker.reset', "Circuit breaker reset for provider #{$id}", ['provider_id' => $id]);
    json_response(['ok' => $ok]);
}

function handle_health_check(): void {
    $body = get_json_body();
    $id = (int)($body['id'] ?? 0);
    if ($id) {
        $r = health_check_provider($id);
        json_response(['ok' => $r['ok'], 'provider_id' => $id, 'latency_ms' => $r['latency'] ?? null, 'error' => $r['error'] ?? null]);
    }
    // تست همه
    $providers = provider_list();
    $results = [];
    foreach ($providers as $p) {
        if ($p['status'] == 1) {
            $r = health_check_provider((int)$p['id']);
            $results[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'slug' => $p['slug'], 'ok' => $r['ok'], 'latency_ms' => $r['latency'] ?? null, 'error' => $r['error'] ?? null];
        }
    }
    json_response(['ok' => true, 'results' => $results]);
}

function handle_status(): void {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_response(['error' => t('api.idRequired')], 400);
    $is_admin = admin_is_logged_in();
    $client = null;
    if (!$is_admin) {
        $code = $_SERVER['HTTP_X_CLIENT_CODE'] ?? '';
        $secret = $_SERVER['HTTP_X_CLIENT_SECRET'] ?? '';
        if ($code && $secret) $client = auth_client($code, $secret);
        if (!$client && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
                $parts = explode(':', $m[1], 2);
                if (count($parts) === 2) $client = auth_client($parts[0], $parts[1]);
            }
        }
        if (!$client) json_response(['error' => t('api.authRequired')], 401);
    }
    $r = queue_status($id);
    if (!$r) json_response(['error' => t('api.notFound')], 404);
    if ($client && !$is_admin && (int)$r['client_id'] !== (int)$client['id']) {
        json_response(['error' => t('api.forbidden')], 403);
    }
    json_response(['ok' => true, 'id' => (int)$r['id'], 'status' => $r['status'], 'priority' => (int)$r['priority'], 'mode' => $r['mode'], 'model' => $r['model'], 'provider' => $r['provider_slug'], 'fallback_used' => $r['fallback_used'], 'created_at' => $r['created_at'], 'processed_at' => $r['processed_at'], 'attempts' => (int)$r['attempts']]);
}

function handle_result(): void {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_response(['error' => t('api.idRequired')], 400);
    $is_admin = admin_is_logged_in();
    $client = null;
    if (!$is_admin) {
        $code = $_SERVER['HTTP_X_CLIENT_CODE'] ?? '';
        $secret = $_SERVER['HTTP_X_CLIENT_SECRET'] ?? '';
        if ($code && $secret) $client = auth_client($code, $secret);
        if (!$client && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            if (preg_match('/Bearer\s+(.+)/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
                $parts = explode(':', $m[1], 2);
                if (count($parts) === 2) $client = auth_client($parts[0], $parts[1]);
            }
        }
        if (!$client) json_response(['error' => t('api.authRequired')], 401);
    }
    $r = queue_status($id);
    if (!$r) json_response(['error' => t('api.notFound')], 404);
    if ($client && !$is_admin && (int)$r['client_id'] !== (int)$client['id']) {
        json_response(['error' => t('api.forbidden')], 403);
    }
    $response = $r['response'];
    $parsed = json_decode($response, true);
    $result = ['ok' => true, 'id' => (int)$r['id'], 'status' => $r['status'], 'model' => $r['model'], 'action' => $r['action'], 'response' => $parsed !== null ? $parsed : $response, 'error' => $r['error'], 'provider' => $r['provider_slug'], 'fallback_used' => $r['fallback_used'], 'created_at' => $r['created_at'], 'processed_at' => $r['processed_at']];
    if ($is_admin) {
        $result['payload'] = json_decode($r['payload'], true);
        $result['api_key_id'] = $r['api_key_id'];
        $result['client_name'] = $r['client_name'];
    }
    json_response($result);
}

function handle_process(): void {
    $token = $_GET['token'] ?? '';
    if (!admin_is_logged_in() && !cron_token_ok($token)) json_response(['error' => t('api.forbidden')], 403);
    @set_time_limit(120); // حداکثر ۲ دقیقه
    $batch = min((int)($_GET['batch'] ?? 0), 5); // حداکثر ۵ درخواست در هر بار
    if ($batch === 0) $batch = 5; // پیش‌فرض ۵

    $leaseManager = new LeaseManager();
    $dispatcher = new Dispatcher();
    $qss = new QueueStatsService();

    // 1. Recover expired leases — workerهای crash شده
    $recovered = $leaseManager->recoverExpiredLeases();

    // 2. Promote retry/delayed jobs whose scheduled_at has passed
    $promoted = $dispatcher->promotePending();

    // 3. Process batch با WorkerPool
    $results = $dispatcher->dispatchBatch($batch);
    $processed = count(array_filter($results, fn($r) => !empty($r['processed'])));

    json_response([
        'ok' => true,
        'processed' => $processed,
        'promoted' => $promoted,
        'recovered' => $recovered,
        'results' => $results,
        'stats' => queue_stats(),
        'detailed_stats' => $qss->getDetailedStats(),
        'queue_breakdown' => $qss->getBreakdown(),
    ]);
}

/**
 * پاکسازی دوره‌ای (برای cron هر ۵ دقیقه).
 * - حذف درخواست‌های قدیمی تکمیل‌شده
 * - بازنشانی cooldown‌های منقضی‌شده
 */
function handle_cleanup(): void {
    $token = $_GET['token'] ?? '';
    if (!admin_is_logged_in() && !cron_token_ok($token)) json_response(['error' => t('api.forbidden')], 403);
    $deleted = cleanup_old_requests();
    $reset = reset_expired_cooldowns();
    json_response(['ok' => true, 'deleted_old_requests' => $deleted, 'reset_cooldowns' => $reset]);
}

// ================================================================
// ادمین
// ================================================================

function admin_is_logged_in(): bool {
    return !empty($_SESSION['mizban_admin_user']) && ($_SESSION['mizban_admin_exp'] ?? 0) > time();
}
function require_admin(): void {
    if (!admin_is_logged_in()) json_response(['error' => t('api.adminRequired')], 401);
    // دفاع CSRF برای اکشن‌های تغییردهنده (POST/PUT/PATCH/DELETE)
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($m, ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !is_same_origin_request()) {
        json_response(['error' => t('api.forbidden')], 403);
    }
}
function handle_admin_login(): void {
    $body = get_json_body();
    $user = trim($body['username'] ?? '');
    $pass = $body['password'] ?? '';
    $ip = client_ip();

    // محدودیت تلاش ناموفق (Brute-Force Protection)
    $th = auth_throttle_check('admin_login', $ip, 10);
    if (!$th['ok']) {
        json_response(['error' => t('api.tooManyAuth'), 'retry_after' => $th['retry_after']], 429);
    }

    // احراز هویت از دیتابیس (جدول admins)
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? AND status = 1 LIMIT 1");
    $stmt->execute([$user]);
    $admin = $stmt->fetch();
    $authOk = false;
    if ($admin) {
        $stored = (string)$admin['password'];
        if (is_password_hash($stored)) {
            $authOk = password_verify($pass, $stored);
        } else {
            // legacy plaintext — مقایسه امن سپس ارتقا به هش
            $authOk = hash_equals($stored, $pass);
            if ($authOk) {
                try {
                    $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?")
                        ->execute([password_hash($pass, PASSWORD_DEFAULT), (int)$admin['id']]);
                } catch (Throwable $e) {}
            }
        }
    }
    if ($authOk) {
        auth_throttle_clear('admin_login', $ip);
        session_regenerate_id(true);
        $_SESSION['mizban_admin_user'] = $user;
        $_SESSION['mizban_admin_name'] = $admin['name'];
        $_SESSION['mizban_admin_exp'] = time() + ADMIN_SESSION_TTL;
        log_info('Admin login succeeded', ['user' => $user]);
        json_response(['ok' => true, 'ttl' => ADMIN_SESSION_TTL, 'name' => $admin['name']]);
    }
    auth_throttle_fail('admin_login', $ip);
    log_warn('Admin login failed', ['user' => $user]);
    json_response(['error' => t('api.badLogin')], 401);
}

function handle_admin_change_password(): void {
    $body = get_json_body();
    $user = (string)($_SESSION['mizban_admin_user'] ?? '');
    $current = (string)($body['current_password'] ?? '');
    $new = (string)($body['new_password'] ?? '');
    if (strlen($new) < 8) json_response(['error' => t('pwd.tooShort')], 400);
    if (!$user) json_response(['error' => t('api.adminRequired')], 401);

    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? AND status = 1 LIMIT 1");
    $stmt->execute([$user]);
    $admin = $stmt->fetch();
    if (!$admin) json_response(['error' => t('api.badLogin')], 401);

    $stored = (string)$admin['password'];
    $ok = is_password_hash($stored) ? password_verify($current, $stored) : hash_equals($stored, $current);
    if (!$ok) {
        log_warn('Admin password change: current password incorrect', ['user' => $user]);
        json_response(['error' => t('pwd.wrong')], 403);
    }
    $pdo->prepare("UPDATE admins SET password = ? WHERE id = ?")
        ->execute([password_hash($new, PASSWORD_DEFAULT), (int)$admin['id']]);
    audit_log('admin.change_password', "Admin password changed: {$user}", ['user' => $user]);
    json_response(['ok' => true]);
}

function handle_provider_create(): void {
    $body = get_json_body();
    $name = trim($body['name'] ?? '');
    $slug = trim($body['slug'] ?? '');
    $baseurl = trim($body['baseurl'] ?? '');
    $type = trim($body['type'] ?? 'chat');
    $priority = (int)($body['priority'] ?? 100);
    if (!$name || !$baseurl) json_response(['error' => t('admin.providerFields')], 400);
    if (!$slug) $slug = gen_slug($name);
    if (!in_array($type, ['chat', 'gapgpt'])) json_response(['error' => t('admin.typeChatGapgpt')], 400);
    $id = provider_create($name, $slug, $baseurl, $type, $priority);
    audit_log('provider.create', "New provider: {$slug}", ['id' => $id, 'name' => $name, 'slug' => $slug, 'priority' => $priority]);
    json_response(['ok' => true, 'id' => $id], 201);
}
function handle_provider_update(): void {
    $body = get_json_body();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_response(['error' => t('api.idRequired')], 400);
    $ok = provider_update($id, $body);
    audit_log('provider.update', "Provider updated: #{$id}", ['id' => $id, 'fields' => array_keys($body)]);
    json_response(['ok' => $ok]);
}
function handle_provider_delete(): void {
    $body = get_json_body();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_response(['error' => t('api.idRequired')], 400);
    $ok = provider_delete($id);
    audit_log('provider.delete', "Provider deleted: #{$id}", ['id' => $id]);
    json_response(['ok' => $ok]);
}

function handle_key_create(): void {
    $body = get_json_body();
    $pid = (int)($body['provider_id'] ?? 0);
    $key = $body['key_value'] ?? '';
    $label = trim($body['label'] ?? t('keys.defaultLabel'));
    if (!$pid || !$key) json_response(['error' => t('admin.keyProviderFields')], 400);
    $id = key_create($pid, $key, $label);
    audit_log('key.create', "New key for provider #{$pid}", ['id' => $id, 'provider_id' => $pid, 'label' => $label]);
    json_response(['ok' => true, 'id' => $id], 201);
}
function handle_key_update(): void {
    $body = get_json_body();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_response(['error' => t('api.idRequired')], 400);
    $ok = key_update($id, $body);
    audit_log('key.update', "Key updated: #{$id}", ['id' => $id, 'fields' => array_keys($body)]);
    json_response(['ok' => $ok]);
}

function handle_model_create(): void {
    $body = get_json_body();
    $pid = (int)($body['provider_id'] ?? 0);
    $mid = trim($body['model_id'] ?? '');
    $name = trim($body['display_name'] ?? '');
    $fallback = trim($body['fallback_model'] ?? '');
    if (!$fallback) $fallback = null;
    $isDefault = (int)($body['is_default'] ?? 0);
    if (!$pid || !$mid || !$name) json_response(['error' => t('admin.modelFields')], 400);
    $id = model_create($pid, $mid, $name, $fallback, $isDefault);
    json_response(['ok' => true, 'id' => $id], 201);
}
function handle_model_update(): void {
    $body = get_json_body();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_response(['error' => t('api.idRequired')], 400);
    $ok = model_update($id, $body);
    json_response(['ok' => $ok]);
}

function handle_client_create(): void {
    $body = get_json_body();
    $name = trim($body['name'] ?? '');
    $degree = (int)($body['degree'] ?? 5);
    $secret = $body['secret'] ?? '';
    if (!$name || strlen($secret) < 6) json_response(['error' => t('admin.clientFields')], 400);
    if ($degree < 1 || $degree > 10) json_response(['error' => t('admin.degreeRange')], 400);
    $c = client_create($name, $degree, $secret);
    json_response(['ok' => true, 'client' => $c], 201);
}
function handle_client_update(): void {
    $body = get_json_body();
    $id = (int)($body['id'] ?? 0);
    if (!$id) json_response(['error' => t('api.idRequired')], 400);
    $fields = [];
    if (isset($body['name'])) $fields['name'] = trim($body['name']);
    if (isset($body['degree'])) $fields['degree'] = (int)$body['degree'];
    if (isset($body['status'])) $fields['status'] = (int)$body['status'];
    if (isset($body['secret']) && strlen($body['secret']) >= 6) $fields['secret'] = $body['secret'];
    $ok = client_update($id, $fields);
    json_response(['ok' => $ok]);
}

// پایان api.php
