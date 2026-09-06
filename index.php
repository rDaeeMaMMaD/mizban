<?php
/**
 * میزبان (Host) - Admin Dashboard v2.1 (Bilingual fa/en)
 * ------------------------------------------------------------------
 * پنل مدیریت: داشبورد، درخواست‌ها، کلاینت‌ها، ارائه‌دهنده‌ها، کلیدها،
 * مدل‌ها، لاگ‌ها، تست زنده و مستندات.
 * زبان: ?lang=fa|en یا کوکی mizban_lang (پیش‌فرض فارسی).
 */

error_reporting(E_ALL);
ini_set('log_errors', '1');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lang.php';

// ===== تغییر زبان: تنظیم کوکی و بازگشت به همان صفحه بدون پارامتر lang =====
if (isset($_GET['lang'])) {
    $switchLang = in_array($_GET['lang'], ['fa', 'en'], true) ? $_GET['lang'] : 'fa';
    setcookie('mizban_lang', $switchLang, time() + 31536000, '/', '', false, true);
    $qs = $_GET;
    unset($qs['lang']);
    $loc = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    if ($qs) $loc .= '?' . http_build_query($qs);
    header('Location: ' . $loc);
    exit;
}

$LANG = MIZBAN_LANG;          // fa | en
$DIR  = $LANG === 'en' ? 'ltr' : 'rtl';
ini_set('display_errors', HOST_DEBUG ? '1' : '0');

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/queue.php';
require_once __DIR__ . '/load_balancer.php';
require_once __DIR__ . '/key_pool.php';
require_once __DIR__ . '/providers.php';
require_once __DIR__ . '/provider_registry.php';
require_once __DIR__ . '/circuit_breaker.php';
require_once __DIR__ . '/response_manager.php';
require_once __DIR__ . '/config_manager.php';
require_once __DIR__ . '/metrics.php';
require_once __DIR__ . '/queue_cleaner.php';
require_once __DIR__ . '/scheduler.php';
require_once __DIR__ . '/worker.php';
require_once __DIR__ . '/dispatcher.php';
require_once __DIR__ . '/policy_engine.php';
require_once __DIR__ . '/cost_engine.php';

secure_session_start(ADMIN_SESSION_NAME);
send_security_headers();

try {
    db_init();
} catch (Throwable $e) {
    http_response_code(500);
    $msg = t('api.dbConnect');
    $detail = HOST_DEBUG ? htmlspecialchars((string)$e->getMessage(), ENT_QUOTES, 'UTF-8') : '';
    echo '<!DOCTYPE html><html lang="' . $LANG . '" dir="' . $DIR . '"><head><meta charset="UTF-8"><title>Error</title></head>'
       . '<body style="font-family:system-ui,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;background:#0f1117;color:#e8eaf2">'
       . '<div style="max-width:520px;padding:24px"><h2>' . htmlspecialchars(t('brand.name'), ENT_QUOTES, 'UTF-8') . '</h2>'
       . '<p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p>'
       . ($detail !== '' ? '<pre style="background:#1c2030;padding:12px;border-radius:8px;overflow:auto;direction:ltr;text-align:left">' . $detail . '</pre>' : '')
       . '</div></body></html>';
    exit;
}

$loggedIn = !empty($_SESSION['mizban_admin_user']) && ($_SESSION['mizban_admin_exp'] ?? 0) > time();
// providers برای پنل — حتی قبل از لاگین لیست خالی بفرست (بعد از لاگین از API پر می‌شود)
$providers = $loggedIn ? provider_list() : [];
$btnLangOther = $LANG === 'en' ? 'fa' : 'en';
$btnLangLabel = $LANG === 'en' ? 'فارسی' : 'English';
$e = function (string $key) { return htmlspecialchars(t($key), ENT_QUOTES, 'UTF-8'); };
$assetsVer = @filemtime(__DIR__ . '/assets.js') ?: HOST_VERSION;
?>
<!DOCTYPE html>
<html lang="<?= $LANG ?>" dir="<?= $DIR ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $e('app.title.full') ?></title>
<link rel="stylesheet" href="assets.css?v=<?= rawurlencode((string)$assetsVer) ?>">
</head>
<body class="lang-<?= $LANG ?>">

<!-- ===== صفحه لاگین (همیشه در DOM — نمایش/مخفی با JS) ===== -->
<div id="loginGate" class="login-gate" style="<?= $loggedIn ? 'display:none;' : 'display:flex;' ?>">
    <div class="lang-float">
        <a class="lang-btn <?= $LANG==='en' ? 'active' : '' ?>" href="?lang=en" title="English">EN</a>
        <a class="lang-btn <?= $LANG==='fa' ? 'active' : '' ?>" href="?lang=fa" title="فارسی">فا</a>
    </div>
    <section class="view view-login active" id="view-login" style="margin:0;max-width:400px;width:100%;">
        <div class="login-card">
            <div class="login-icon">🔐</div>
            <h2><?= $e('view.login') ?></h2>
            <p class="muted" style="margin:6px 0 16px;font-size:12.5px;text-align:center"><?= $e('brand.name') ?> — <?= $e('brand.tagline') ?></p>
            <form id="loginForm">
                <div class="field"><label><?= $e('login.username') ?></label><input type="text" name="username" required placeholder="<?= $e('login.username') ?>" autocomplete="username"></div>
                <div class="field"><label><?= $e('login.password') ?></label><input type="password" name="password" required autocomplete="current-password"></div>
                <button type="submit" class="btn btn-primary btn-block"><?= $e('login.submit') ?></button>
            </form>
        </div>
    </section>
    <footer style="margin-top:20px;text-align:center;font-size:12px;color:#6b7283;"><?= $e('brand.name') ?> v<?= HOST_VERSION ?> · <?= date('Y') ?></footer>
</div>

<!-- ===== پنل ادمین (همیشه در DOM) ===== -->
<div class="app" id="appShell" style="<?= $loggedIn ? '' : 'display:none;' ?>">
    <aside class="sidebar" id="sidebar">
        <div class="logo"><div class="logo-icon"><?= $LANG === 'en' ? 'M' : 'م' ?></div><div><div class="logo-title"><?= $e('brand.name') ?></div><div class="logo-sub"><?= $e('brand.tagline') ?> v<?= HOST_VERSION ?></div></div></div>
        <nav class="nav">
            <a href="#" class="nav-item active" data-view="dashboard"><span>📊</span> <?= $e('nav.dashboard') ?></a>
            <a href="#" class="nav-item" data-view="requests"><span>📥</span> <?= $e('nav.requests') ?></a>
            <a href="#" class="nav-item" data-view="clients"><span>👥</span> <?= $e('nav.clients') ?></a>
            <a href="#" class="nav-item" data-view="providers"><span>🔌</span> <?= $e('nav.providers') ?></a>
            <a href="#" class="nav-item" data-view="keys"><span>🔑</span> <?= $e('nav.keys') ?></a>
            <a href="#" class="nav-item" data-view="models"><span>🧠</span> <?= $e('nav.models') ?></a>
            <a href="#" class="nav-item" data-view="logs"><span>📜</span> <?= $e('nav.logs') ?></a>
            <a href="#" class="nav-item" data-view="test"><span>🧪</span> <?= $e('nav.test') ?></a>
            <a href="#" class="nav-item" data-view="docs"><span>📖</span> <?= $e('nav.docs') ?></a>
        </nav>
        <div class="sidebar-footer">
            <button class="btn btn-ghost btn-block" id="btnPwd">🔑 <?= $e('pwd.menu') ?></button>
            <button class="btn btn-ghost btn-block" id="btnLogout">🚪 <?= $e('action.logout') ?></button>
            <div class="status-badge"><span class="dot dot-ok"></span> <?= $e('sb.status') ?></div>
        </div>
    </aside>
    <main class="main">
        <header class="topbar">
            <button class="menu-toggle" id="menuToggle">☰</button>
            <h1 id="viewTitle"><?= $e('nav.dashboard') ?></h1>
            <span class="topbar-spacer"></span>
            <a class="lang-btn" href="?lang=<?= $btnLangOther ?>" title="<?= $btnLangLabel ?>"><?= $btnLangOther === 'fa' ? 'فا' : 'EN' ?></a>
            <span class="time" id="liveTime"><?= date('H:i:s') ?></span>
        </header>
        <div class="content">
            <section class="view active" id="view-dashboard">
                <div class="stats-grid" id="statsGrid"></div>
                <div id="advStatsGrid"></div>
                <div class="grid-2">
                    <div class="card"><div class="card-head"><h3>📋 <?= $e('dash.latest') ?></h3><button class="btn btn-sm btn-ghost" id="btnRefreshDash">🔄</button></div><div class="card-body" id="dashRequests"></div></div>
                    <div class="card"><div class="card-head"><h3>⚖️ <?= $e('dash.providerLoad') ?></h3></div><div class="card-body" id="dashProviders"></div></div>
                </div>
                <div class="card"><div class="card-head"><h3>⚙️ <?= $e('dash.queueProcess') ?></h3></div><div class="card-body"><button class="btn btn-primary" id="btnProcessQueue">▶️ <?= $e('btn.processQueue') ?></button><button class="btn btn-ghost" id="btnCleanup">🧹 <?= $e('btn.cleanup') ?></button><span id="processResult"></span></div></div>
            </section>
            <section class="view" id="view-requests">
                <div class="card"><div class="card-head"><h3>📥 <?= $e('req.title') ?></h3><select id="reqFilter" class="select" style="width:auto"><option value=""><?= $e('generic.all') ?></option><option value="queued"><?= $e('stat.queued') ?></option><option value="running"><?= $e('stat.processing') ?></option><option value="completed"><?= $e('stat.completed') ?></option><option value="failed"><?= $e('stat.failed') ?></option></select></div><div class="card-body"><div class="table-wrap"><table class="table"><thead><tr><th><?= $e('req.col.id') ?></th><th><?= $e('req.col.client') ?></th><th><?= $e('req.col.mode') ?></th><th><?= $e('req.col.model') ?></th><th><?= $e('req.col.provider') ?></th><th><?= $e('req.col.priority') ?></th><th><?= $e('req.col.status') ?></th><th><?= $e('req.col.fallback') ?></th><th><?= $e('req.col.time') ?></th><th><?= $e('req.col.actions') ?></th></tr></thead><tbody id="reqTableBody"></tbody></table></div></div></div>
            </section>
            <section class="view" id="view-clients">
                <div class="card"><div class="card-head"><h3>👥 <?= $e('clients.title') ?></h3><button class="btn btn-primary btn-sm" id="btnNewClient">➕ <?= $e('clients.new') ?></button></div><div class="card-body"><div class="table-wrap"><table class="table"><thead><tr><th><?= $e('col.name') ?></th><th><?= $e('col.code') ?></th><th><?= $e('col.degree') ?></th><th><?= $e('field.status') ?></th><th><?= $e('req.col.actions') ?></th></tr></thead><tbody id="clientTableBody"></tbody></table></div></div></div>
            </section>
            <section class="view" id="view-providers">
                <div class="card"><div class="card-head"><h3>🔌 <?= $e('providers.title') ?></h3><button class="btn btn-primary btn-sm" id="btnNewProvider">➕ <?= $e('providers.new') ?></button></div><div class="card-body" id="providersBody"></div></div>
            </section>
            <section class="view" id="view-keys">
                <div class="card"><div class="card-head"><h3>🔑 <?= $e('keys.title') ?></h3><select id="keyProviderFilter" class="select" style="width:auto"></select></div><div class="card-body" id="keysBody"></div></div>
            </section>
            <section class="view" id="view-models">
                <div class="card"><div class="card-head"><h3>🧠 <?= $e('models.title') ?></h3><button class="btn btn-primary btn-sm" id="btnNewModel">➕ <?= $e('models.new') ?></button></div><div class="card-body" id="modelsBody"></div></div>
            </section>
            <section class="view" id="view-logs">
                <div class="card"><div class="card-head"><h3>📜 <?= $e('logs.title') ?></h3><select id="logFilter" class="select" style="width:auto"><option value=""><?= $e('generic.all') ?></option><option value="info"><?= $e('logs.info') ?></option><option value="warn"><?= $e('logs.warn') ?></option><option value="error"><?= $e('logs.error') ?></option></select></div><div class="card-body"><div class="log-list" id="logList"></div></div></div>
            </section>
            <section class="view" id="view-test">
                <div class="grid-2">
                    <div class="card"><div class="card-head"><h3>🧪 <?= $e('test.title') ?></h3></div><div class="card-body"><form id="testForm">
                        <div class="field"><label><?= $e('test.mode') ?></label><select name="mode" class="select" id="testMode"><option value="specific">🎯 specific</option><option value="general">🤖 general</option></select></div>
                        <div class="field" id="modelField"><label><?= $e('test.model') ?></label><input type="text" name="model" id="testModel" class="input" placeholder="gpt-4o-mini"></div>
                        <div class="field"><label><?= $e('test.action') ?></label><select name="action" class="select" id="testAction"><option value="chat">chat</option><option value="models">models</option></select></div>
                        <div class="field"><label><?= $e('test.input') ?></label><textarea name="input" class="input" rows="4"><?= $LANG === 'en' ? 'Hello!' : 'سلام!' ?></textarea></div>
                        <div class="field"><label><?= $e('test.clientCode') ?></label><input type="text" name="code" class="input" value="miz_basic_001"></div>
                        <div class="field"><label><?= $e('test.secret') ?></label><input type="password" name="secret" class="input" placeholder="••••••••"></div>
                        <button type="submit" class="btn btn-primary btn-block">🚀 <?= $e('test.send') ?></button>
                    </form></div></div>
                    <div class="card"><div class="card-head"><h3><?= $e('test.response') ?></h3><button class="btn btn-sm btn-ghost" id="btnCopyResult"><?= $e('test.copy') ?></button></div><div class="card-body"><div class="result-box" id="testResult"><div class="muted"><?= $e('test.resultEmpty') ?></div></div></div></div>
                </div>
            </section>
            <section class="view" id="view-docs">
                <div class="card"><div class="card-head"><h3>📖 <?= $e('docs.title') ?></h3></div><div class="card-body docs">
                    <p class="muted"><?= $e('docs.intro') ?></p>
                    <h4><?= $e('docs.example.send') ?></h4>
                    <pre class="code">curl -X POST "https://YOUR_DOMAIN/api.php?action=send" -H "Content-Type: application/json" -H "X-Client-Code: miz_basic_001" -H "X-Client-Secret: basic_secret_CHANGE_ME" -d '{"mode":"specific","model":"gpt-4o-mini","action":"chat","payload":{"messages":[{"role":"user","content":"<?= $LANG === 'en' ? 'Hello' : 'سلام' ?>"}]}}'</pre>
                    <h4><?= $e('docs.example.general') ?></h4>
                    <pre class="code">curl -X POST "https://YOUR_DOMAIN/api.php?action=send" -H "Content-Type: application/json" -H "X-Client-Code: miz_basic_001" -H "X-Client-Secret: basic_secret_CHANGE_ME" -d '{"mode":"general","action":"chat","payload":{"messages":[{"role":"user","content":"<?= $LANG === 'en' ? 'Hello' : 'سلام' ?>"}]}}'</pre>
                    <h4><?= $e('docs.getResult') ?></h4>
                    <pre class="code">curl "https://YOUR_DOMAIN/api.php?action=result&id=REQUEST_ID"</pre>
                    <h4><?= $e('docs.section.statuses') ?></h4>
                    <p class="muted"><?= $e('docs.note.async') ?></p>
                    <p class="muted"><?= $e('docs.note.security') ?></p>
                </div></div>
            </section>
        </div>
        <footer class="footer"><?= $e('brand.name') ?> v<?= HOST_VERSION ?> · <?= date('Y') ?></footer>
    </main>
</div>

<div class="modal" id="clientModal"><div class="modal-card"><div class="modal-head"><h3 id="clientModalTitle"><?= $e('clients.modal.new') ?></h3><button class="modal-close" data-close="clientModal">✕</button></div><div class="modal-body"><form id="clientForm"><input type="hidden" name="id"><div class="field"><label><?= $e('col.name') ?></label><input type="text" name="name" required class="input"></div><div class="field"><label><?= $e('col.degree') ?> (1-10)</label><input type="number" name="degree" min="1" max="10" value="5" required class="input"></div><div class="field"><label><?= $e('field.secret') ?></label><input type="text" name="secret" class="input" placeholder="<?= $e('action.keep') ?>"></div><div class="field"><label><?= $e('field.status') ?></label><select name="status" class="select"><option value="1"><?= $e('status.active') ?></option><option value="0"><?= $e('status.inactive') ?></option></select></div><button type="submit" class="btn btn-primary btn-block"><?= $e('action.save') ?></button></form></div></div></div>
<div class="modal" id="providerModal"><div class="modal-card"><div class="modal-head"><h3 id="providerModalTitle"><?= $e('providers.modal.new') ?></h3><button class="modal-close" data-close="providerModal">✕</button></div><div class="modal-body"><form id="providerForm"><input type="hidden" name="id"><div class="field"><label><?= $e('col.name') ?></label><input type="text" name="name" required class="input"></div><div class="field"><label><?= $e('field.slug') ?></label><input type="text" name="slug" class="input" placeholder="auto"></div><div class="field"><label><?= $e('field.baseurl') ?></label><input type="text" name="baseurl" required class="input" placeholder="https://..."></div><div class="field"><label><?= $e('field.type') ?></label><select name="type" class="select"><option value="chat">chat</option><option value="gapgpt">gapgpt</option></select></div><div class="field"><label><?= $e('field.priority') ?></label><input type="number" name="priority" min="1" max="999" value="100" class="input"></div><div class="field"><label><?= $e('field.status') ?></label><select name="status" class="select"><option value="1"><?= $e('status.active') ?></option><option value="0"><?= $e('status.inactive') ?></option></select></div><button type="submit" class="btn btn-primary btn-block"><?= $e('action.save') ?></button></form></div></div></div>
<div class="modal" id="keyModal"><div class="modal-card"><div class="modal-head"><h3 id="keyModalTitle"><?= $e('keys.modal.new') ?></h3><button class="modal-close" data-close="keyModal">✕</button></div><div class="modal-body"><form id="keyForm"><input type="hidden" name="id"><input type="hidden" name="provider_id"><div class="field"><label><?= $e('field.label') ?></label><input type="text" name="label" class="input"></div><div class="field"><label><?= $e('field.key') ?></label><input type="text" name="key_value" class="input" placeholder="sk-..." autocomplete="off"></div><div class="field"><label><?= $e('field.status') ?></label><select name="status" class="select"><option value="1"><?= $e('status.active') ?></option><option value="0"><?= $e('status.inactive') ?></option></select></div><div class="field"><label><input type="checkbox" name="reset_stats" value="1"> <?= $e('key.resetStats') ?></label></div><button type="submit" class="btn btn-primary btn-block"><?= $e('action.save') ?></button></form></div></div></div>
<div class="modal" id="modelModal"><div class="modal-card"><div class="modal-head"><h3 id="modelModalTitle"><?= $e('models.modal.new') ?></h3><button class="modal-close" data-close="modelModal">✕</button></div><div class="modal-body"><form id="modelForm"><input type="hidden" name="id"><div class="field"><label><?= $e('field.provider') ?></label><select name="provider_id" required class="select" id="modelProviderSelect"></select></div><div class="field"><label><?= $e('field.modelId') ?></label><input type="text" name="model_id" required class="input" placeholder="gpt-4o-mini"></div><div class="field"><label><?= $e('field.displayName') ?></label><input type="text" name="display_name" required class="input"></div><div class="field"><label><?= $e('field.fallbackModel') ?></label><input type="text" name="fallback_model" class="input" placeholder="gpt-4o-mini"></div><div class="field"><label><?= $e('field.status') ?></label><select name="status" class="select"><option value="1"><?= $e('status.active') ?></option><option value="0"><?= $e('status.inactive') ?></option></select></div><button type="submit" class="btn btn-primary btn-block"><?= $e('action.save') ?></button></form></div></div></div>
<div class="modal" id="detailModal"><div class="modal-card modal-lg"><div class="modal-head"><h3><?= $e('detail.title') ?></h3><button class="modal-close" data-close="detailModal">✕</button></div><div class="modal-body" id="detailModalBody"></div></div></div>
<div class="modal" id="pwdModal"><div class="modal-card"><div class="modal-head"><h3><?= $e('pwd.title') ?></h3><button class="modal-close" data-close="pwdModal">✕</button></div><div class="modal-body"><form id="pwdForm"><div class="field"><label><?= $e('pwd.current') ?></label><input type="password" name="current" required class="input" autocomplete="current-password"></div><div class="field"><label><?= $e('pwd.new') ?></label><input type="password" name="new" required class="input" autocomplete="new-password" minlength="8"></div><div class="field"><label><?= $e('pwd.confirm') ?></label><input type="password" name="confirm" required class="input" autocomplete="new-password"></div><button type="submit" class="btn btn-primary btn-block"><?= $e('pwd.submit') ?></button></form></div></div></div>

<div class="toast" id="toast"></div>
<script>
window.MIZBAN = {
    loggedIn: <?= $loggedIn ? 'true' : 'false' ?>,
    lang: <?= json_encode($LANG) ?>,
    i18n: <?= json_encode(mizban_dict($LANG), JSON_UNESCAPED_UNICODE) ?>,
    i18nFa: <?= json_encode(mizban_dict('fa'), JSON_UNESCAPED_UNICODE) ?>,
    providers: <?= json_encode($providers, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="assets.js?v=<?= rawurlencode((string)$assetsVer) ?>"></script>
</body>
</html>
