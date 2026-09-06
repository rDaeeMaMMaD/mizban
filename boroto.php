<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

define('BR_LOG_FILE', __DIR__ . '/boroto.log');
define('BR_LOG_TAIL', 60);

// بارگذاری .env تا BOROTO_TOKEN از فایل خوانده شود
require_once __DIR__ . '/config.php';

// توکن اختیاری برای محافظت از endpointهای پروکسی/لاگ (از Environment خوانده می‌شود).
// اگر BOROTO_TOKEN تنظیم نشده باشد، این endpointها غیرفعال‌اند.
define('BR_ACCESS_TOKEN', (string)_mizban_env('BOROTO_TOKEN', ''));

// ---- زبان: از کوکی مشترک پنل (mizban_lang) یا ?lang=fa|en ----
require_once __DIR__ . '/lang.php';
if (isset($_GET['lang'])) {
    $sw = in_array($_GET['lang'], ['fa', 'en'], true) ? $_GET['lang'] : 'fa';
    setcookie('mizban_lang', $sw, time() + 31536000, '/', '', false, true);
    $qs = $_GET; unset($qs['lang']);
    $loc = $_SERVER['SCRIPT_NAME'] ?? '/boroto.php';
    if ($qs) $loc .= '?' . http_build_query($qs);
    header('Location: ' . $loc);
    exit;
}
$BLANG = MIZBAN_LANG; // fa | en
$be = function (string $k, array $v = []) { return htmlspecialchars(t($k, $v), ENT_QUOTES, 'UTF-8'); };

function br_log_access_ok(): bool {
    if (php_sapi_name() === 'cli') return true;
    if (BR_ACCESS_TOKEN === '') return false;
    $given = (string)($_SERVER['HTTP_X_BOROTO_TOKEN'] ?? ($_GET['token'] ?? ''));
    return $given !== '' && hash_equals(BR_ACCESS_TOKEN, $given);
}

function br_log(array $entry): void {
    $entry = array_merge(['ts' => date('Y-m-d H:i:s')], $entry);
    $line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    @file_put_contents(BR_LOG_FILE, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function br_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

$br_action = $_GET['action'] ?? ($_POST['action'] ?? null);

/* ---------------------------------------------------------------------
 * action=send  → پروکسی درخواست به میزبان هدف + لاگ کامل
 * ------------------------------------------------------------------- */
if ($br_action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // بدون BOROTO_TOKEN، پروکسی باز یک بردار SSRF است — الزامی.
    if (!br_log_access_ok()) {
        br_json(['ok' => false, 'error' => t('api.forbidden')], 403);
    }

    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!is_array($body)) {
        br_json(['ok' => false, 'error' => t('boroto.jsonInvalid')], 400);
    }

    $targetUrl    = trim((string)($body['target_url']    ?? ''));
    $clientCode   = trim((string)($body['client_code']   ?? ''));
    $clientSecret = trim((string)($body['client_secret'] ?? ''));
    $payload      = $body['payload'] ?? null;

    // دفاع SSRF پایه: فقط http(s) — توکن BOROTO_TOKEN الزامی است
    require_once __DIR__ . '/core.php';
    if ($targetUrl === '' || !is_valid_url($targetUrl)) {
        br_json(['ok' => false, 'error' => t('boroto.invalidUrl')], 422);
    }
    if (!is_array($payload)) {
        br_json(['ok' => false, 'error' => t('boroto.invalidPayload')], 422);
    }

    $headers = ['Content-Type: application/json'];
    if ($clientCode   !== '') $headers[] = 'X-Client-Code: '   . $clientCode;
    if ($clientSecret !== '') $headers[] = 'X-Client-Secret: ' . $clientSecret;

    $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $start = microtime(true);
    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $jsonPayload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $rawResponse = curl_exec($ch);
    $curlErrNo   = curl_errno($ch);
    $curlErr     = curl_error($ch);
    $httpStatus  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $durationMs = round((microtime(true) - $start) * 1000, 1);

    $decoded = null;
    if ($rawResponse !== false && $rawResponse !== '') {
        $decoded = json_decode($rawResponse, true);
    }

    $result = [
        'ok'          => $curlErrNo === 0,
        'target_url'  => $targetUrl,
        'http_status' => $httpStatus,
        'duration_ms' => $durationMs,
        'curl_errno'  => $curlErrNo,
        'curl_error'  => $curlErr ?: null,
        'response'    => $decoded !== null ? $decoded : $rawResponse,
        'queue_id'    => $decoded['request_id'] ?? $decoded['queue_id'] ?? null,
        'provider'    => $decoded['provider']   ?? null,
        'model'       => $decoded['model']      ?? ($payload['model'] ?? null),
    ];

    br_log([
        'type'        => 'request',
        'target_url'  => $targetUrl,
        'client_code' => $clientCode,
        // client_secret عمداً لاگ نمی‌شود
        'payload'     => $payload,
        'http_status' => $httpStatus,
        'duration_ms' => $durationMs,
        'curl_errno'  => $curlErrNo,
        'curl_error'  => $curlErr ?: null,
        'response'    => $decoded !== null ? $decoded : $rawResponse,
    ]);

    br_json($result, 200);
}

/* ---------------------------------------------------------------------
 * action=log  → خواندن آخرین رکوردهای boroto.log
 * ------------------------------------------------------------------- */
if ($br_action === 'log' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!br_log_access_ok()) br_json(['ok' => false, 'error' => 'Forbidden'], 403);
    if (!file_exists(BR_LOG_FILE)) br_json(['ok' => true, 'entries' => []]);
    $lines   = file(BR_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines   = array_slice($lines, -BR_LOG_TAIL);
    $entries = array_values(array_filter(array_map(
        fn($l) => json_decode($l, true),
        $lines
    )));
    br_json(['ok' => true, 'entries' => array_reverse($entries)]);
}

/* ---------------------------------------------------------------------
 * action=clear_log → پاک‌کردن فایل لاگ سمت سرور
 * ------------------------------------------------------------------- */
if ($br_action === 'clear_log' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!br_log_access_ok()) br_json(['ok' => false, 'error' => 'Forbidden'], 403);
    @file_put_contents(BR_LOG_FILE, '');
    br_json(['ok' => true]);
}
?>
<!DOCTYPE html>
<html lang="<?= $BLANG ?>" dir="<?= $BLANG === 'en' ? 'ltr' : 'rtl' ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Boroto // Console</title>
<style>
.lang-switch{display:inline-flex;gap:4px;margin-inline-end:10px;}
.lang-switch a{font-family:var(--sans);font-size:11px;color:var(--text-faint);text-decoration:none;padding:3px 8px;border:1px solid var(--border-soft);border-radius:6px;}
.lang-switch a.active{color:#1a1206;background:var(--amber);border-color:var(--amber);}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{
  --bg-void:#0A0D10;
  --bg-panel:#12161C;
  --bg-panel-alt:#171C24;
  --bg-raised:#1B212B;
  --border:#232A34;
  --border-soft:#1A2029;
  --text:#DCE3EA;
  --text-dim:#7C8896;
  --text-faint:#4C5560;
  --amber:#E8A33D;
  --amber-dim:#8A6425;
  --red:#D8564B;
  --blue:#5B9BD5;
  --green:#5FBF7A;
  --radius:10px;
  --mono:'JetBrains Mono',ui-monospace,monospace;
  --sans:'Vazirmatn',system-ui,sans-serif;
}
*{box-sizing:border-box;}
html,body{margin:0;padding:0;height:100%;}
body{
  background:var(--bg-void);
  color:var(--text);
  font-family:var(--sans);
  font-size:14.5px;
  display:flex;
  flex-direction:column;
  overflow:hidden;
}
::selection{background:var(--amber-dim);color:#fff;}
::-webkit-scrollbar{width:8px;height:8px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:4px;}
::-webkit-scrollbar-thumb:hover{background:var(--text-faint);}

/* ---------- Status strip (signature element) ---------- */
.statusbar{
  display:flex;
  align-items:center;
  gap:18px;
  padding:9px 18px;
  background:linear-gradient(180deg,var(--bg-panel-alt),var(--bg-panel));
  border-bottom:1px solid var(--border);
  font-family:var(--mono);
  font-size:12px;
  color:var(--text-dim);
  flex-shrink:0;
  overflow-x:auto;
  white-space:nowrap;
}
.statusbar .brand{
  font-family:var(--sans);
  font-weight:800;
  font-size:14px;
  color:var(--amber);
  letter-spacing:.3px;
  display:flex;
  align-items:center;
  gap:8px;
}
.pulse{
  width:8px;height:8px;border-radius:50%;
  background:var(--text-faint);
  box-shadow:0 0 0 rgba(232,163,61,.5);
  flex-shrink:0;
}
.pulse.live{
  background:var(--green);
  animation:pulseglow 1.8s infinite;
}
.pulse.error{ background:var(--red); }
@keyframes pulseglow{
  0%{box-shadow:0 0 0 0 rgba(95,191,122,.55);}
  70%{box-shadow:0 0 0 8px rgba(95,191,122,0);}
  100%{box-shadow:0 0 0 0 rgba(95,191,122,0);}
}
.statusbar .stat{display:flex;gap:6px;align-items:center;}
.statusbar .stat b{color:var(--text);font-weight:600;}
.statusbar .spacer{flex:1;}
.iconbtn{
  background:transparent;border:1px solid var(--border);color:var(--text-dim);
  border-radius:7px;padding:5px 10px;font-family:var(--sans);font-size:12px;
  cursor:pointer;transition:.15s;display:flex;align-items:center;gap:6px;
}
.iconbtn:hover{border-color:var(--amber-dim);color:var(--amber);}

/* ---------- Layout ---------- */
.wrap{flex:1;display:flex;min-height:0;}
.main{flex:1;display:flex;flex-direction:column;min-width:0;}
.side{
  width:340px;flex-shrink:0;border-left:1px solid var(--border);
  background:var(--bg-panel);display:flex;flex-direction:column;
  transition:margin-right .2s ease;
}
@media (max-width:860px){
  .side{position:fixed;top:0;bottom:0;left:0;width:86vw;max-width:340px;z-index:40;
    border-left:none;border-right:1px solid var(--border);
    box-shadow:20px 0 40px rgba(0,0,0,.4);}
  .side.hidden{transform:translateX(-100%);}
  .side:not(.hidden){transform:translateX(0);}
  .overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:35;display:none;}
  .overlay.show{display:block;}
}

/* ---------- Chat area ---------- */
.chat{
  flex:1;overflow-y:auto;padding:22px 22px 8px;
  display:flex;flex-direction:column;gap:16px;
}
.empty-state{
  margin:auto;text-align:center;color:var(--text-faint);
  font-family:var(--mono);font-size:13px;max-width:420px;line-height:2;
}
.empty-state .glyph{font-size:34px;margin-bottom:10px;color:var(--amber-dim);}

.msg{max-width:78%;display:flex;flex-direction:column;gap:6px;}
.msg.user{align-self:flex-start;}
.msg.bot{align-self:flex-end;}
.bubble{
  padding:12px 15px;border-radius:var(--radius);
  line-height:1.85;white-space:pre-wrap;word-break:break-word;
  font-size:14.5px;
}
.msg.user .bubble{
  background:var(--bg-raised);border:1px solid var(--border);
  border-top-right-radius:3px;
}
.msg.bot .bubble{
  background:var(--bg-panel-alt);border:1px solid var(--amber-dim);
  border-top-left-radius:3px;
}
.msg.bot .bubble.is-error{border-color:var(--red);}

.meta-strip{
  font-family:var(--mono);font-size:11px;color:var(--text-dim);
  display:flex;flex-wrap:wrap;gap:10px;padding:0 4px;
}
.meta-strip .chip{
  background:var(--bg-raised);border:1px solid var(--border-soft);
  border-radius:5px;padding:2px 8px;display:flex;gap:5px;align-items:center;
}
.meta-strip .chip.status-ok{color:var(--green);border-color:rgba(95,191,122,.3);}
.meta-strip .chip.status-bad{color:var(--red);border-color:rgba(216,86,75,.3);}
.msg-actions{display:flex;gap:8px;padding:0 4px;}
.linkbtn{
  background:none;border:none;color:var(--text-faint);cursor:pointer;
  font-family:var(--mono);font-size:11px;padding:0;transition:.15s;
}
.linkbtn:hover{color:var(--amber);}

.raw-json{
  margin-top:2px;background:var(--bg-void);border:1px solid var(--border-soft);
  border-radius:7px;padding:10px 12px;font-family:var(--mono);font-size:11.5px;
  color:var(--text-dim);max-height:260px;overflow:auto;display:none;
}
.raw-json.open{display:block;}

.typing{
  align-self:flex-end;display:flex;gap:4px;padding:14px 15px;
  background:var(--bg-panel-alt);border:1px solid var(--border);border-radius:var(--radius);
}
.typing span{
  width:6px;height:6px;border-radius:50%;background:var(--amber);
  animation:blink 1.2s infinite;
}
.typing span:nth-child(2){animation-delay:.2s;}
.typing span:nth-child(3){animation-delay:.4s;}
@keyframes blink{0%,80%,100%{opacity:.25;}40%{opacity:1;}}

/* ---------- Composer ---------- */
.composer{
  border-top:1px solid var(--border);background:var(--bg-panel-alt);
  padding:14px 22px 18px;flex-shrink:0;
}
.composer-row{display:flex;gap:10px;align-items:flex-end;}
.composer textarea{
  flex:1;resize:none;background:var(--bg-void);color:var(--text);
  border:1px solid var(--border);border-radius:var(--radius);
  padding:12px 14px;font-family:var(--sans);font-size:14.5px;line-height:1.6;
  max-height:160px;min-height:46px;outline:none;transition:.15s;
}
.composer textarea:focus{border-color:var(--amber-dim);}
.sendbtn{
  background:var(--amber);color:#1a1206;border:none;border-radius:var(--radius);
  width:46px;height:46px;flex-shrink:0;cursor:pointer;font-size:18px;
  display:flex;align-items:center;justify-content:center;transition:.15s;
}
.sendbtn:hover{background:#f0b458;}
.sendbtn:disabled{background:var(--border);color:var(--text-faint);cursor:not-allowed;}
.composer-hint{
  font-family:var(--mono);font-size:11px;color:var(--text-faint);
  margin-top:7px;display:flex;justify-content:space-between;
}

/* ---------- Sidebar ---------- */
.side-head{
  padding:16px 18px;border-bottom:1px solid var(--border);
  font-weight:700;font-size:13px;color:var(--text);
  display:flex;align-items:center;justify-content:space-between;
}
.side-body{flex:1;overflow-y:auto;padding:16px 18px;display:flex;flex-direction:column;gap:16px;}
.field{display:flex;flex-direction:column;gap:6px;}
.field label{font-size:12px;color:var(--text-dim);font-weight:500;}
.field input,.field select{
  background:var(--bg-void);border:1px solid var(--border);color:var(--text);
  border-radius:7px;padding:9px 11px;font-family:var(--mono);font-size:12.5px;outline:none;
}
.field input:focus,.field select:focus{border-color:var(--amber-dim);}
.field .hint{font-size:10.5px;color:var(--text-faint);}
.divider{height:1px;background:var(--border-soft);margin:2px 0;}
.side-sub{font-size:11px;color:var(--text-faint);text-transform:uppercase;letter-spacing:.6px;font-weight:600;}
.btn-primary{
  background:var(--amber);color:#1a1206;border:none;border-radius:8px;
  padding:10px;font-family:var(--sans);font-weight:700;font-size:13px;cursor:pointer;
}
.btn-primary:hover{background:#f0b458;}
.btn-ghost{
  background:transparent;border:1px solid var(--border);color:var(--text-dim);
  border-radius:8px;padding:9px;font-family:var(--sans);font-size:12.5px;cursor:pointer;
}
.btn-ghost:hover{border-color:var(--red);color:var(--red);}

.log-entry{
  border:1px solid var(--border-soft);border-radius:7px;padding:8px 10px;
  font-family:var(--mono);font-size:11px;color:var(--text-dim);
  display:flex;flex-direction:column;gap:3px;
}
.log-entry .row{display:flex;justify-content:space-between;gap:8px;}
.log-entry .ok{color:var(--green);}
.log-entry .bad{color:var(--red);}

@media (max-width:860px){
  .chat{padding:16px 14px;}
  .composer{padding:10px 14px 14px;}
  .msg{max-width:92%;}
}
</style>
</head>
<body>

<div class="statusbar">
  <div class="brand"><span class="pulse" id="pulseDot"></span> BOROTO // CONSOLE</div>
  <div class="stat"><?= $be('boroto.host') ?> <b id="stHost">—</b></div>
  <div class="stat"><?= $be('boroto.lastStatus') ?> <b id="stStatus">—</b></div>
  <div class="stat"><?= $be('boroto.respTime') ?> <b id="stTime">—</b></div>
  <div class="stat"><?= $be('boroto.reqCount') ?> <b id="stCount">0</b></div>
  <div class="spacer"></div>
  <div class="lang-switch">
    <a href="?lang=en" class="<?= $BLANG === 'en' ? 'active' : '' ?>">EN</a>
    <a href="?lang=fa" class="<?= $BLANG === 'fa' ? 'active' : '' ?>">فا</a>
  </div>
  <button class="iconbtn" id="btnSidebarToggle"><?= $be('boroto.settings') ?></button>
</div>

<div class="wrap">
  <div class="main">
    <div class="chat" id="chat">
      <div class="empty-state" id="emptyState">
        <div class="glyph">◎</div>
        <?= $be('boroto.empty1') ?><br>
        <?= $be('boroto.empty2') ?>
      </div>
    </div>

    <div class="composer">
      <div class="composer-row">
        <textarea id="promptInput" rows="1" placeholder="<?= $be('boroto.placeholder') ?>"></textarea>
        <button class="sendbtn" id="sendBtn" title="<?= $be('boroto.send') ?>">➤</button>
      </div>
      <div class="composer-hint">
        <span id="hintTarget">هدف: —</span>
        <span>Boroto Console v1.0</span>
      </div>
    </div>
  </div>

  <div class="overlay" id="overlay"></div>
  <div class="side hidden" id="sidePanel">
    <div class="side-head">
      <?= $be('boroto.connSettings') ?>
      <button class="iconbtn" id="btnSidebarClose">✕</button>
    </div>
    <div class="side-body">

      <div class="side-sub"><?= $be('boroto.hostSection') ?></div>
      <div class="field">
        <label><?= $be('boroto.endpointLabel') ?></label>
        <input type="text" id="cfgUrl" placeholder="https://YOUR_DOMAIN/api.php?action=send">
        <div class="hint"><?= $be('boroto.hostHint') ?></div>
      </div>

      <div class="field">
        <label>X-Client-Code</label>
        <input type="text" id="cfgCode" placeholder="miz_basic_001">
      </div>
      <div class="field">
        <label>X-Client-Secret</label>
        <input type="text" id="cfgSecret" placeholder="basic_secret_CHANGE_ME">
      </div>
      <div class="field">
        <label><?= $be('boroto.accessToken') ?></label>
        <input type="password" id="cfgBorotoToken" placeholder="BOROTO_TOKEN" autocomplete="off">
        <div class="hint"><?= $be('boroto.accessTokenHint') ?></div>
      </div>

      <div class="divider"></div>
      <div class="side-sub"><?= $be('boroto.reqSection') ?></div>
      <div class="field">
        <label><?= $be('boroto.routeMode') ?></label>
        <select id="cfgMode">
          <option value="general"><?= $be('boroto.modeGeneral') ?></option>
          <option value="specific"><?= $be('boroto.modeSpecific') ?></option>
        </select>
      </div>
      <div class="field">
        <label><?= $be('boroto.modelLabel') ?></label>
        <input type="text" id="cfgModel" placeholder="deepseek-chat">
      </div>
      <div class="field">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
          <input type="checkbox" id="cfgAsync" style="width:auto;">
          <?= $be('boroto.async') ?>
        </label>
      </div>

      <button class="btn-primary" id="btnSaveCfg"><?= $be('boroto.saveCfg') ?></button>

      <div class="divider"></div>
      <div class="side-sub"><?= $be('boroto.history') ?></div>
      <button class="btn-ghost" id="btnClearChat"><?= $be('boroto.clearChat') ?></button>
      <button class="btn-ghost" id="btnClearServerLog"><?= $be('boroto.clearServerLog') ?></button>
      <button class="btn-ghost" id="btnLoadServerLog"><?= $be('boroto.loadServerLog') ?></button>

      <div class="divider"></div>
      <div class="side-sub"><?= $be('boroto.serverRecords') ?></div>
      <div id="serverLogList" style="display:flex;flex-direction:column;gap:8px;"></div>
    </div>
  </div>
</div>

<script>
(function(){
  'use strict';

  window.BOROTO_I18N = <?= json_encode(mizban_dict($BLANG), JSON_UNESCAPED_UNICODE) ?>;
  window.BOROTO_I18N_FA = <?= json_encode(mizban_dict('fa'), JSON_UNESCAPED_UNICODE) ?>;

  /* ---------- i18n ---------- */
  const I18N = window.BOROTO_I18N || {};
  const I18N_FA = window.BOROTO_I18N_FA || {};
  function __(k, vars){
    let s = (I18N[k] !== undefined) ? I18N[k] : ((I18N_FA[k] !== undefined) ? I18N_FA[k] : k);
    if (vars) for (const key in vars) s = s.split('{'+key+'}').join(vars[key]);
    return s;
  }

  const els = {
    chat: document.getElementById('chat'),
    emptyState: document.getElementById('emptyState'),
    input: document.getElementById('promptInput'),
    sendBtn: document.getElementById('sendBtn'),
    pulseDot: document.getElementById('pulseDot'),
    stHost: document.getElementById('stHost'),
    stStatus: document.getElementById('stStatus'),
    stTime: document.getElementById('stTime'),
    stCount: document.getElementById('stCount'),
    hintTarget: document.getElementById('hintTarget'),
    sidePanel: document.getElementById('sidePanel'),
    overlay: document.getElementById('overlay'),
    btnSidebarToggle: document.getElementById('btnSidebarToggle'),
    btnSidebarClose: document.getElementById('btnSidebarClose'),
    cfgUrl: document.getElementById('cfgUrl'),
    cfgCode: document.getElementById('cfgCode'),
    cfgSecret: document.getElementById('cfgSecret'),
    cfgBorotoToken: document.getElementById('cfgBorotoToken'),
    cfgMode: document.getElementById('cfgMode'),
    cfgModel: document.getElementById('cfgModel'),
    cfgAsync: document.getElementById('cfgAsync'),
    btnSaveCfg: document.getElementById('btnSaveCfg'),
    btnClearChat: document.getElementById('btnClearChat'),
    btnClearServerLog: document.getElementById('btnClearServerLog'),
    btnLoadServerLog: document.getElementById('btnLoadServerLog'),
    serverLogList: document.getElementById('serverLogList'),
  };

  const STORAGE_CFG  = 'boroto_cfg_v1';
  const STORAGE_CHAT = 'boroto_chat_v1';
  let requestCount = 0;

  /* ---------------- Config ---------------- */
  function loadCfg(){
    let cfg = {};
    try { cfg = JSON.parse(localStorage.getItem(STORAGE_CFG) || '{}'); } catch(e){}
    els.cfgUrl.value    = cfg.url    || 'https://YOUR_DOMAIN/api.php?action=send';
    els.cfgCode.value   = cfg.code   || 'miz_basic_001';
    els.cfgSecret.value = cfg.secret || 'basic_secret_CHANGE_ME';
    els.cfgBorotoToken.value = cfg.borotoToken || '';
    els.cfgMode.value   = cfg.mode   || 'general';
    els.cfgModel.value  = cfg.model  || '';
    els.cfgAsync.checked= !!cfg.async;
    updateStatusHost();
  }
  function saveCfg(){
    const cfg = {
      url: els.cfgUrl.value.trim(),
      code: els.cfgCode.value.trim(),
      secret: els.cfgSecret.value.trim(),
      borotoToken: els.cfgBorotoToken.value.trim(),
      mode: els.cfgMode.value,
      model: els.cfgModel.value.trim(),
      async: els.cfgAsync.checked,
    };
    localStorage.setItem(STORAGE_CFG, JSON.stringify(cfg));
    updateStatusHost();
    closeSidebar();
  }
  function borotoHeaders(extra){
    const h = Object.assign({'Content-Type': 'application/json'}, extra || {});
    const tok = (els.cfgBorotoToken.value || '').trim();
    if (tok) h['X-Boroto-Token'] = tok;
    return h;
  }
  function borotoUrl(action){
    const tok = (els.cfgBorotoToken.value || '').trim();
    return '?action=' + encodeURIComponent(action) + (tok ? '&token=' + encodeURIComponent(tok) : '');
  }
  function updateStatusHost(){
    const u = els.cfgUrl.value.trim() || '—';
    els.stHost.textContent = u.length > 46 ? u.slice(0,46) + '…' : u;
    els.hintTarget.textContent = __('boroto.target') + (els.cfgUrl.value.trim() || __('boroto.notSet'));
  }

  /* ---------------- Sidebar ---------------- */
  function openSidebar(){ els.sidePanel.classList.remove('hidden'); els.overlay.classList.add('show'); }
  function closeSidebar(){ els.sidePanel.classList.add('hidden'); els.overlay.classList.remove('show'); }
  els.btnSidebarToggle.addEventListener('click', openSidebar);
  els.btnSidebarClose.addEventListener('click', closeSidebar);
  els.overlay.addEventListener('click', closeSidebar);
  els.btnSaveCfg.addEventListener('click', saveCfg);

  /* ---------------- Chat persistence ---------------- */
  function loadChat(){
    let msgs = [];
    try { msgs = JSON.parse(localStorage.getItem(STORAGE_CHAT) || '[]'); } catch(e){}
    if (msgs.length) els.emptyState.style.display = 'none';
    msgs.forEach(m => renderMessage(m, false));
    requestCount = msgs.filter(m => m.role === 'bot').length;
    els.stCount.textContent = requestCount;
    scrollToBottom();
  }
  function persistMessage(m){
    let msgs = [];
    try { msgs = JSON.parse(localStorage.getItem(STORAGE_CHAT) || '[]'); } catch(e){}
    msgs.push(m);
    if (msgs.length > 200) msgs = msgs.slice(-200);
    localStorage.setItem(STORAGE_CHAT, JSON.stringify(msgs));
  }
  els.btnClearChat.addEventListener('click', function(){
    if (!confirm(__('boroto.confirmClearChat'))) return;
    localStorage.removeItem(STORAGE_CHAT);
    els.chat.innerHTML = '';
    els.chat.appendChild(els.emptyState);
    els.emptyState.style.display = 'flex';
    requestCount = 0;
    els.stCount.textContent = 0;
  });

  /* ---------------- Server log panel ---------------- */
  els.btnClearServerLog.addEventListener('click', async function(){
    if (!confirm(__('boroto.confirmClearLog'))) return;
    await fetch(borotoUrl('clear_log'), { method:'POST', headers: borotoHeaders() });
    els.serverLogList.innerHTML = '';
  });
  els.btnLoadServerLog.addEventListener('click', async function(){
    try{
      const res = await fetch(borotoUrl('log'), { headers: borotoHeaders() });
      const data = await res.json();
      els.serverLogList.innerHTML = '';
      (data.entries || []).forEach(e => {
        const div = document.createElement('div');
        div.className = 'log-entry';
        const okClass = (e.http_status >= 200 && e.http_status < 300 && !e.curl_errno) ? 'ok' : 'bad';
        div.innerHTML =
          '<div class="row"><span>' + escapeHtml(e.ts || '') + '</span>' +
          '<span class="' + okClass + '">' + (e.http_status || '—') + '</span></div>' +
          '<div class="row"><span>' + escapeHtml((e.target_url||'').slice(0,34)) + '</span>' +
          '<span>' + (e.duration_ms != null ? e.duration_ms+'ms' : '—') + '</span></div>';
        els.serverLogList.appendChild(div);
      });
    }catch(e){
      els.serverLogList.innerHTML = '<div class="log-entry bad">' + __('boroto.loadLogError') + '</div>';
    }
  });

  /* ---------------- Rendering ---------------- */
  function escapeHtml(str){
    return String(str).replace(/[&<>"']/g, c => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
  }

  function renderMessage(m, doPersist){
    els.emptyState.style.display = 'none';
    const wrap = document.createElement('div');
    wrap.className = 'msg ' + (m.role === 'user' ? 'user' : 'bot');

    const bubble = document.createElement('div');
    bubble.className = 'bubble' + (m.isError ? ' is-error' : '');
    bubble.textContent = m.text;
    wrap.appendChild(bubble);

    if (m.role === 'bot'){
      const meta = document.createElement('div');
      meta.className = 'meta-strip';
      const statusClass = m.httpStatus >= 200 && m.httpStatus < 300 ? 'status-ok' : 'status-bad';
      meta.innerHTML =
        '<span class="chip ' + statusClass + '">HTTP ' + (m.httpStatus ?? '—') + '</span>' +
        '<span class="chip">⏱ ' + (m.durationMs != null ? m.durationMs + 'ms' : '—') + '</span>' +
        '<span class="chip">Queue: ' + (m.queueId ?? '—') + '</span>' +
        '<span class="chip">Provider: ' + escapeHtml(m.provider ?? '—') + '</span>' +
        '<span class="chip">Model: ' + escapeHtml(m.model ?? '—') + '</span>';
      wrap.appendChild(meta);

      const actions = document.createElement('div');
      actions.className = 'msg-actions';
      const copyBtn = document.createElement('button');
      copyBtn.className = 'linkbtn';
      copyBtn.textContent = __('boroto.copyResp');
      copyBtn.onclick = () => {
        navigator.clipboard.writeText(m.text).then(() => {
          copyBtn.textContent = __('boroto.copied');
          setTimeout(() => copyBtn.textContent = __('boroto.copyResp'), 1500);
        });
      };
      const rawBtn = document.createElement('button');
      rawBtn.className = 'linkbtn';
      rawBtn.textContent = __('boroto.showRaw');
      const rawBox = document.createElement('pre');
      rawBox.className = 'raw-json';
      rawBox.textContent = m.rawJson ? JSON.stringify(m.rawJson, null, 2) : __('boroto.emptyRaw');
      rawBtn.onclick = () => {
        rawBox.classList.toggle('open');
        rawBtn.textContent = rawBox.classList.contains('open') ? __('boroto.hideRaw') : __('boroto.showRaw');
      };
      actions.appendChild(copyBtn);
      actions.appendChild(rawBtn);
      wrap.appendChild(actions);
      wrap.appendChild(rawBox);
    }

    els.chat.appendChild(wrap);
    if (doPersist !== false) persistMessage(m);
    scrollToBottom();
  }

  function scrollToBottom(){
    requestAnimationFrame(() => { els.chat.scrollTop = els.chat.scrollHeight; });
  }

  function showTyping(){
    const t = document.createElement('div');
    t.className = 'typing';
    t.id = 'typingIndicator';
    t.innerHTML = '<span></span><span></span><span></span>';
    els.chat.appendChild(t);
    scrollToBottom();
  }
  function hideTyping(){
    const t = document.getElementById('typingIndicator');
    if (t) t.remove();
  }

  function setPulse(state){
    els.pulseDot.className = 'pulse ' + (state === 'ok' ? 'live' : state === 'error' ? 'error' : '');
  }

  /* ---------------- Send flow ---------------- */
  async function sendMessage(){
    const text = els.input.value.trim();
    if (!text) return;

    let cfg = {};
    try { cfg = JSON.parse(localStorage.getItem(STORAGE_CFG) || '{}'); } catch(e){}
    if (!cfg.url){
      alert(__('boroto.setUrlFirst'));
      openSidebar();
      return;
    }

    renderMessage({ role:'user', text: text });
    els.input.value = '';
    autoGrow();
    els.sendBtn.disabled = true;
    showTyping();

    const payload = {
      mode: cfg.mode || 'general',
      action: 'chat',
      payload: { messages: [{ role:'user', content: text }] }
    };
    if (cfg.mode === 'specific' && cfg.model) payload.model = cfg.model;
    if (cfg.async) payload.async = true;

    const reqBody = {
      target_url: cfg.url,
      client_code: cfg.code || '',
      client_secret: cfg.secret || '',
      payload: payload
    };

    try{
      const res = await fetch(borotoUrl('send'), {
        method: 'POST',
        headers: borotoHeaders(),
        body: JSON.stringify(reqBody)
      });
      const data = await res.json();
      hideTyping();
      requestCount++;
      els.stCount.textContent = requestCount;
      els.stStatus.textContent = data.http_status ?? '—';
      els.stTime.textContent = data.duration_ms != null ? data.duration_ms + 'ms' : '—';

      const isOk = data.ok && data.http_status >= 200 && data.http_status < 300;
      setPulse(isOk ? 'ok' : 'error');

      let botText;
      if (isOk){
        const r = data.response;
        botText = extractReadable(r);
      } else {
        botText = data.error
          ? __('boroto.errorPrefix') + data.error
          : __('boroto.recvError', {code: data.http_status}) + (data.curl_error ? ' — ' + data.curl_error : '');
      }

      renderMessage({
        role: 'bot',
        text: botText,
        isError: !isOk,
        httpStatus: data.http_status,
        durationMs: data.duration_ms,
        queueId: data.queue_id,
        provider: data.provider,
        model: data.model,
        rawJson: data
      });
    }catch(err){
      hideTyping();
      setPulse('error');
      renderMessage({
        role:'bot', text:__('boroto.networkError', {msg: err.message}),
        isError:true, httpStatus:0
      });
    }finally{
      els.sendBtn.disabled = false;
      els.input.focus();
    }
  }

  function extractReadable(r){
    if (r == null) return __('boroto.emptyResp');
    if (typeof r === 'string') return r;
    try{
      // میزبان: {ok, ack, status, provider, response: {...API...}}
      var apiResp = r;
      if (r.response && typeof r.response === 'object') {
        if (r.response.choices || r.response.output || r.response.content) {
          apiResp = r.response;
        } else if (r.response.response) {
          apiResp = r.response.response;
        }
      }
      // OpenAI Chat: choices[0].message.content
      if (apiResp.choices && apiResp.choices[0] && apiResp.choices[0].message) {
        return apiResp.choices[0].message.content || JSON.stringify(apiResp, null, 2);
      }
      // GapGPT Responses: output[0].content[0].text
      if (apiResp.output && Array.isArray(apiResp.output)) {
        var texts = [];
        apiResp.output.forEach(function(item) {
          if (item.content && Array.isArray(item.content)) {
            item.content.forEach(function(b) { if (b.text) texts.push(b.text); });
          }
        });
        if (texts.length) return texts.join('\n');
      }
      // Direct content array
      if (apiResp.content && Array.isArray(apiResp.content)) {
        return apiResp.content.map(function(b) { return b.text || ''; }).join('\n') || JSON.stringify(apiResp, null, 2);
      }
      // String
      if (typeof apiResp.response === 'string') return apiResp.response;
      return JSON.stringify(apiResp, null, 2);
    }catch(e){
      return JSON.stringify(r);
    }
  }

  /* ---------------- Composer UX ---------------- */
  function autoGrow(){
    els.input.style.height = 'auto';
    els.input.style.height = Math.min(els.input.scrollHeight, 160) + 'px';
  }
  els.input.addEventListener('input', autoGrow);
  els.input.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && !e.shiftKey){
      e.preventDefault();
      sendMessage();
    }
  });
  els.sendBtn.addEventListener('click', sendMessage);

  /* ---------------- Init ---------------- */
  loadCfg();
  loadChat();
  els.input.focus();
})();
</script>
</body>
</html>