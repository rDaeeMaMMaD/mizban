<?php
/**
 * Mizban - Smoke tests (no database required)
 * ------------------------------------------------------------------
 * Usage:  php tests/smoke.php
 *
 * Covers the parts of the system that do not need MySQL:
 *   - config + environment loading
 *   - fa/en translation dictionaries stay in sync
 *   - CostEngine price estimates
 *   - error classification (classify_error)
 *   - token generation
 * Exit code 0 = all passed, 1 = failures.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core.php';
require_once __DIR__ . '/../lang.php';
require_once __DIR__ . '/../cost_engine.php';

$failures = 0;
$checks   = 0;

function check(string $name, bool $ok, string $detail = ''): void {
    global $checks, $failures;
    $checks++;
    echo ($ok ? "  PASS  " : "  FAIL  ") . $name . ($detail !== '' ? "  ($detail)" : '') . PHP_EOL;
    if (!$ok) $failures++;
}

echo "== Config / env ==\n";
check('HOST_NAME defined', defined('HOST_NAME') && HOST_NAME !== '');
check('HOST_DEBUG is boolean', is_bool(HOST_DEBUG));
check('MASTER_SECRET is a string', is_string(MASTER_SECRET));

echo "== i18n dictionaries ==\n";
$fa = json_decode((string)file_get_contents(__DIR__ . '/../lang/fa.json'), true);
$en = json_decode((string)file_get_contents(__DIR__ . '/../lang/en.json'), true);
check('fa.json is valid JSON + array', is_array($fa) && count($fa) > 100);
check('en.json is valid JSON + array', is_array($en) && count($en) > 100);
check('identical key sets', array_keys($fa) === array_keys($en));
$noEmpty = true;
foreach ($en as $k => $v) { if (trim((string)$v) === '' || trim((string)$fa[$k]) === '') { $noEmpty = false; break; } }
check('no empty translations', $noEmpty);
check('t() returns Persian by default', t('nav.dashboard') === ($fa['nav.dashboard'] ?? null));

echo "== CostEngine ==\n";
$ce = new CostEngine();
check('gpt-4o-mini cost known', $ce->estimateCost('gpt-4o-mini') === 0.002);
check('unknown model falls back to default', $ce->estimateCost('nope-123') === 0.005);
check('request cost math', abs($ce->estimateRequestCost('gpt-4o-mini', 1000) - 0.002) < 1e-9);

echo "== classify_error ==\n";
check('timeout => retryable', classify_error('Operation timed out', 0)['type'] === 'timeout');
check('429 quota => not retryable', classify_error('monthly quota exceeded', 429)['type'] === 'quota_exhausted');
check('429 rate limit => retryable', classify_error('rate limit', 429)['type'] === 'rate_limit');
check('401 => auth', classify_error('invalid key', 401)['type'] === 'auth');
check('500 => retryable', classify_error('boom', 500)['retryable'] === true);
check('400 => client_error', classify_error('bad request', 400)['type'] === 'client_error');

echo "== helpers ==\n";
$tok = gen_token(32);
check('gen_token(32) length 32', strlen($tok) === 32);
check('gen_token is hex', (bool)preg_match('/^[0-9a-f]+$/', $tok));
check('client_ip defaults safely', is_string(client_ip()) && client_ip() !== '');
check('is_valid_url https ok', is_valid_url('https://example.com/x') === true);
check('is_valid_url ftp rejected', is_valid_url('ftp://x') === false);
check('is_valid_url allows localhost syntax', is_valid_url('http://localhost/hook') === true);
check('is_safe_callback_url rejects localhost', is_safe_callback_url('http://localhost/hook') === false);
check('is_safe_callback_url rejects 127.0.0.1', is_safe_callback_url('http://127.0.0.1/hook') === false);
check('is_safe_callback_url rejects private IP', is_safe_callback_url('http://192.168.1.1/hook') === false);
check('is_safe_callback_url allows public https', is_safe_callback_url('https://example.com/hook') === true);
check('cron_token_ok rejects empty', cron_token_ok('') === false && cron_token_ok(null) === false);

echo "\n{$checks} checks, {$failures} failures\n";
exit($failures === 0 ? 0 : 1);
