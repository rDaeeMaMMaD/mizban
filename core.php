<?php
/**
 * میزبان (Host) - Core Engine v2.0
 * ------------------------------------------------------------------
 * قلب سیستم: پایگاه داده، احراز هویت، صف، HTTP، Load Balancing،
 * Failover، رمزنگاری کلیدها، امنیت و توابع کمکی.
 */

if (!defined('HOST_NAME')) { http_response_code(403); exit('Forbidden'); }

// ================================================================
// بخش ۱: پایگاه داده
// ================================================================

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

/**
 * اجرای ALTER TABLE بدون خطا اگه ستون وجود داشته باشه.
 * در MySQL، وقتی ERRMODE_EXCEPTION روشنه، @ خطاها رو suppress نمی‌کنه.
 */
function safe_alter(string $sql): void {
    try { db()->exec($sql); } catch (PDOException $e) { /* ستون از قبل وجود دارد */ }
}

function db_init(): void {
    $pdo = db();

    // فقط CREATE TABLE IF NOT EXISTS — بدون ALTER TABLE
    // Migration ها در migrate.php هستن

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS providers (
            id                INT PRIMARY KEY AUTO_INCREMENT,
            name              VARCHAR(255) NOT NULL,
            slug              VARCHAR(100) NOT NULL UNIQUE,
            baseurl           VARCHAR(500) NOT NULL,
            type              VARCHAR(20) NOT NULL DEFAULT 'chat',
            status            TINYINT NOT NULL DEFAULT 1,
            sort_order        INT NOT NULL DEFAULT 0,
            priority          INT NOT NULL DEFAULT 100,
            circuit_state     VARCHAR(20) NOT NULL DEFAULT 'closed',
            circuit_opened_at DATETIME NULL,
            failure_count     INT NOT NULL DEFAULT 0,
            health_score      INT NOT NULL DEFAULT 100,
            active_requests   INT NOT NULL DEFAULT 0,
            total_requests    INT NOT NULL DEFAULT 0,
            total_errors      INT NOT NULL DEFAULT 0,
            avg_latency_ms    INT NOT NULL DEFAULT 0,
            last_health_check DATETIME NULL,
            created_at        DATETIME NOT NULL DEFAULT NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS api_keys (
            id               INT PRIMARY KEY AUTO_INCREMENT,
            provider_id      INT NOT NULL,
            key_encrypted    TEXT NOT NULL,
            label            VARCHAR(255) NOT NULL DEFAULT '',
            status           TINYINT NOT NULL DEFAULT 1,
            weight           INT NOT NULL DEFAULT 1,
            lb_strategy      VARCHAR(20) NOT NULL DEFAULT 'least_busy',
            request_count    INT NOT NULL DEFAULT 0,
            error_count      INT NOT NULL DEFAULT 0,
            consecutive_errors INT NOT NULL DEFAULT 0,
            active_requests  INT NOT NULL DEFAULT 0,
            avg_latency_ms   INT NOT NULL DEFAULT 0,
            last_used_at     DATETIME NULL,
            cooldown_until   DATETIME NULL,
            created_at       DATETIME NOT NULL DEFAULT NOW(),
            FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
            INDEX idx_keys_provider (provider_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS models (
            id               INT PRIMARY KEY AUTO_INCREMENT,
            provider_id      INT NOT NULL,
            model_id         VARCHAR(200) NOT NULL,
            display_name     VARCHAR(255) NOT NULL,
            fallback_model   VARCHAR(200) NULL,
            modality         VARCHAR(50) NOT NULL DEFAULT 'text',
            supports_stream  TINYINT NOT NULL DEFAULT 1,
            is_default       TINYINT NOT NULL DEFAULT 0,
            status           TINYINT NOT NULL DEFAULT 1,
            sort_order       INT NOT NULL DEFAULT 0,
            created_at       DATETIME NOT NULL DEFAULT NOW(),
            FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
            INDEX idx_models_model (model_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS clients (
            id          INT PRIMARY KEY AUTO_INCREMENT,
            name        VARCHAR(255) NOT NULL,
            code        VARCHAR(100) NOT NULL UNIQUE,
            degree      INT NOT NULL DEFAULT 5,
            secret_hash VARCHAR(255) NOT NULL,
            status      TINYINT NOT NULL DEFAULT 1,
            created_at  DATETIME NOT NULL DEFAULT NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id          INT PRIMARY KEY AUTO_INCREMENT,
            username    VARCHAR(100) NOT NULL UNIQUE,
            password    VARCHAR(255) NOT NULL,
            name        VARCHAR(255) NOT NULL DEFAULT 'Admin',
            status      TINYINT NOT NULL DEFAULT 1,
            created_at  DATETIME NOT NULL DEFAULT NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS requests (
            id              INT PRIMARY KEY AUTO_INCREMENT,
            client_id       INT NOT NULL,
            mode            VARCHAR(20) NOT NULL DEFAULT 'specific',
            provider_slug   VARCHAR(100) NULL,
            model           VARCHAR(200) NOT NULL,
            action          VARCHAR(50) NOT NULL,
            payload         LONGTEXT NULL,
            priority        INT NOT NULL DEFAULT 5,
            status          VARCHAR(20) NOT NULL DEFAULT 'queued',
            callback_url    VARCHAR(500) NULL,
            response        LONGTEXT NULL,
            error           TEXT NULL,
            error_type      VARCHAR(50) NULL,
            attempts        INT NOT NULL DEFAULT 0,
            max_attempts    INT NOT NULL DEFAULT 6,
            api_key_id      INT NULL,
            worker_id       VARCHAR(100) NULL,
            fallback_used   VARCHAR(500) NULL,
            idempotency_key VARCHAR(200) NULL,
            correlation_id  VARCHAR(100) NULL,
            queue_position  INT NULL,
            latency_ms      INT NULL,
            queue_time_ms   INT NULL,
            total_time_ms   INT NULL,
            stream_mode     TINYINT NOT NULL DEFAULT 0,
            scheduled_at    DATETIME NULL,
            lease_token     VARCHAR(64) NULL,
            lease_until     DATETIME NULL,
            created_at      DATETIME NOT NULL DEFAULT NOW(),
            locked_at       DATETIME NULL,
            running_at      DATETIME NULL,
            processed_at    DATETIME NULL,
            FOREIGN KEY (client_id) REFERENCES clients(id),
            INDEX idx_requests_status (status, priority DESC, created_at),
            INDEX idx_requests_queue (status, scheduled_at),
            INDEX idx_requests_lease (status, lease_until),
            INDEX idx_idempotency (idempotency_key),
            INDEX idx_correlation (correlation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS health_checks (
            id            INT PRIMARY KEY AUTO_INCREMENT,
            provider_id   INT NOT NULL,
            status        VARCHAR(20) NOT NULL,
            latency_ms    INT NULL,
            error         TEXT NULL,
            checked_at    DATETIME NOT NULL DEFAULT NOW(),
            FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
            INDEX idx_health_provider (provider_id, checked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS worker_status (
            id                VARCHAR(100) PRIMARY KEY,
            hostname          VARCHAR(255) NOT NULL,
            pid               INT NOT NULL,
            status            VARCHAR(20) NOT NULL DEFAULT 'idle',
            current_job       INT NULL,
            started_at        DATETIME NOT NULL DEFAULT NOW(),
            last_heartbeat    DATETIME NOT NULL DEFAULT NOW(),
            jobs_completed    INT NOT NULL DEFAULT 0,
            jobs_failed       INT NOT NULL DEFAULT 0,
            active_jobs       INT NOT NULL DEFAULT 0,
            memory_usage      INT NOT NULL DEFAULT 0,
            cpu_usage         INT NOT NULL DEFAULT 0,
            heartbeat_interval INT NOT NULL DEFAULT 5,
            INDEX idx_worker_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS rate_limits (
            id            INT PRIMARY KEY AUTO_INCREMENT,
            entity_type   VARCHAR(20) NOT NULL,
            entity_id     VARCHAR(100) NOT NULL,
            period        VARCHAR(10) NOT NULL,
            count         INT NOT NULL DEFAULT 0,
            window_start  DATETIME NOT NULL,
            UNIQUE KEY uniq_rate (entity_type, entity_id, period, window_start),
            INDEX idx_rate_entity (entity_type, entity_id, period)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS statistics (
            id            INT PRIMARY KEY AUTO_INCREMENT,
            stat_key      VARCHAR(100) NOT NULL,
            stat_value    BIGINT NOT NULL DEFAULT 0,
            period        VARCHAR(20) NOT NULL,
            recorded_at   DATETIME NOT NULL DEFAULT NOW(),
            UNIQUE KEY uniq_stat (stat_key, period, recorded_at),
            INDEX idx_stat_key (stat_key, period)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            skey   VARCHAR(100) PRIMARY KEY,
            svalue TEXT NULL,
            updated_at DATETIME NOT NULL DEFAULT NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS nonces (
            nonce       VARCHAR(100) PRIMARY KEY,
            client_code VARCHAR(100) NOT NULL,
            created_at  DATETIME NOT NULL DEFAULT NOW(),
            INDEX idx_nonce_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cron_status (
            cron_name   VARCHAR(50) PRIMARY KEY,
            last_run    DATETIME NULL,
            last_duration_ms INT NULL,
            last_result VARCHAR(20) NULL,
            run_count   INT NOT NULL DEFAULT 0,
            error_count INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS logs (
            id         INT PRIMARY KEY AUTO_INCREMENT,
            level      VARCHAR(20) NOT NULL DEFAULT 'info',
            message    TEXT NOT NULL,
            context    TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT NOW(),
            INDEX idx_logs_level (level, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS migrations (
            id          INT PRIMARY KEY AUTO_INCREMENT,
            name        VARCHAR(200) NOT NULL UNIQUE,
            executed_at DATETIME NOT NULL DEFAULT NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // seed ادمین پیش‌فرض — پیش‌فرض: admin / admin123 (قابل‌تغییر از .env)
    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
    if ($adminCount == 0) {
        $adminUser = (defined('MIZBAN_ADMIN_USER') && MIZBAN_ADMIN_USER !== '') ? MIZBAN_ADMIN_USER : 'admin';
        $adminPass = (defined('MIZBAN_ADMIN_PASSWORD') && MIZBAN_ADMIN_PASSWORD !== '') ? MIZBAN_ADMIN_PASSWORD : 'admin123';
        $pdo->prepare("INSERT INTO admins (username, password, name, status) VALUES (?, ?, ?, 1)")
            ->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), 'Administrator']);
    }

    // seed کلاینت‌های پیش‌فرض (رمز هش‌شده)
    $count = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    if ($count == 0) {
        $pdo->prepare("INSERT INTO clients (name, code, degree, secret_hash, status) VALUES (?, ?, ?, ?, 1)")
            ->execute(['Basic Client', 'miz_basic_001', 5, password_hash('basic_secret_CHANGE_ME', PASSWORD_DEFAULT)]);
        $pdo->prepare("INSERT INTO clients (name, code, degree, secret_hash, status) VALUES (?, ?, ?, ?, 1)")
            ->execute(['VIP Client', 'miz_vip_001', 10, password_hash('vip_secret_CHANGE_ME', PASSWORD_DEFAULT)]);
    }
    // Auto-reset: re-enable all disabled keys + clear expired cooldowns
    try {
        $pdo->exec("UPDATE api_keys SET status = 1, consecutive_errors = 0, cooldown_until = NULL WHERE status = 0");
        $pdo->exec("UPDATE api_keys SET cooldown_until = NULL WHERE cooldown_until IS NOT NULL AND cooldown_until < NOW()");
    } catch (PDOException $e) {}

    // seed توکن cron: اگر در settings نبود و از Environment هم نیامده بود، یک توکن تصادفی بساز
    try {
        $tokCount = (int)$pdo->query("SELECT COUNT(*) FROM settings WHERE skey = 'cron.token'")->fetchColumn();
        if ($tokCount === 0) {
            $cronTok = (defined('CRON_TOKEN_DEFAULT') && CRON_TOKEN_DEFAULT !== '') ? CRON_TOKEN_DEFAULT : bin2hex(random_bytes(16));
            $pdo->prepare("INSERT INTO settings (skey, svalue) VALUES ('cron.token', ?)")->execute([$cronTok]);
        }
    } catch (PDOException $e) {}
}

function get_cron_token(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $cached = defined('CRON_TOKEN_DEFAULT') ? CRON_TOKEN_DEFAULT : '';
    try {
        $stmt = db()->prepare("SELECT svalue FROM settings WHERE skey = 'cron.token' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if ($row && !empty($row['svalue'])) $cached = $row['svalue'];
    } catch (PDOException $e) {}
    return (string)$cached;
}

/**
 * آیا توکن cron معتبر است؟
 * توکن خالی هرگز پذیرفته نمی‌شود (جلوگیری از باز بودن endpoint وقتی secret تنظیم نشده).
 */
function cron_token_ok(?string $token): bool {
    $expected = get_cron_token();
    if ($expected === '' || $token === null || $token === '') return false;
    return hash_equals($expected, (string)$token);
}

// ================================================================
// بخش ۲: کلیدهای API — رمزنگاری AES-256-CBC (at-rest)
// ================================================================

/**
 * رمزنگاری کلید API با AES-256-CBC و IV تصادفی برای هر مقدار.
 * خروجی با پیشوند "enc:v2:" علامت‌گذاری می‌شود تا از plaintext قدیمی تشخیص داده شود.
 */
function encrypt_key(string $plain): string {
    if ($plain === '') return '';
    if (MASTER_SECRET === '') {
        // fail-closed: بدون MASTER_SECRET نباید کلیدی به‌صورت plain ذخیره شود
        throw new RuntimeException('MASTER_SECRET is not set — API key encryption is disabled. See config.php / .env');
    }
    $ivlen = openssl_cipher_iv_length(ENCRYPT_METHOD) ?: 16;
    $iv = random_bytes($ivlen);
    $cipher = openssl_encrypt($plain, ENCRYPT_METHOD, MASTER_SECRET, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) {
        throw new RuntimeException('API key encryption failed (openssl)');
    }
    return 'enc:v2:' . base64_encode($iv . $cipher);
}

/**
 * رمزگشایی کلید. مقادیر قدیمی (plaintext بدون پیشوند) همان‌طور برگردانده می‌شوند
 * تا مهاجرت بدون توقف سرویس انجام شود.
 */
function decrypt_key(string $stored): string {
    if ($stored === '') return '';
    if (strncmp($stored, 'enc:v2:', 7) === 0) {
        if (MASTER_SECRET === '') {
            @error_log('[mizban] ERROR: cannot decrypt API key — MASTER_SECRET is not configured.');
            return '';
        }
        $raw = base64_decode(substr($stored, 7), true);
        if ($raw === false) return '';
        $ivlen = openssl_cipher_iv_length(ENCRYPT_METHOD) ?: 16;
        $iv = substr($raw, 0, $ivlen);
        $cipher = substr($raw, $ivlen);
        $plain = openssl_decrypt($cipher, ENCRYPT_METHOD, MASTER_SECRET, OPENSSL_RAW_DATA, $iv);
        return $plain === false ? '' : $plain;
    }
    return $stored; // legacy plaintext
}

// ================================================================
// بخش ۳: احراز هویت کلاینت‌ها — هش امن + مهاجرت تدریجی
// ================================================================

/** آیا این رشته یک هش معتبر password_hash است؟ */
function is_password_hash(string $s): bool {
    return (bool)preg_match('/^\$(2y|argon2i|argon2id)\$/', $s);
}

function hash_client_secret(string $secret): string {
    return password_hash($secret, PASSWORD_DEFAULT);
}

function auth_client(string $code, string $secret): ?array {
    if ($code === '' || $secret === '') return null;
    $pdo = db();
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE code = ? AND status = 1 LIMIT 1");
    $stmt->execute([$code]);
    $client = $stmt->fetch();
    if (!$client) return null;
    $stored = (string)$client['secret_hash'];

    if (is_password_hash($stored)) {
        if (!password_verify($secret, $stored)) return null;
    } else {
        // legacy plaintext — مقایسه constant-time، سپس ارتقا به هش
        if (!hash_equals($stored, $secret)) return null;
        try {
            $pdo->prepare("UPDATE clients SET secret_hash = ? WHERE id = ?")
                ->execute([hash_client_secret($secret), (int)$client['id']]);
        } catch (Throwable $e) {}
    }
    return $client;
}

function rate_limit_check(int $clientId): bool {
    $pdo = db();
    $window = date('Y-m-d H:i:s', time() - RATE_LIMIT_WINDOW);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE client_id = ? AND created_at > ?");
    $stmt->execute([$clientId, $window]);
    return (int)$stmt->fetchColumn() < RATE_LIMIT_MAX;
}

// ================================================================
// بخش ۴: مدیریت ارائه‌دهنده‌ها، کلیدها، مدل‌ها
// ================================================================

function provider_list(): array {
    return db()->query("SELECT * FROM providers ORDER BY sort_order, id")->fetchAll();
}

function provider_get(int $id): ?array {
    $stmt = db()->prepare("SELECT * FROM providers WHERE id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function provider_get_by_slug(string $slug): ?array {
    $stmt = db()->prepare("SELECT * FROM providers WHERE slug = ?");
    $stmt->execute([$slug]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function provider_create(string $name, string $slug, string $baseurl, string $type, int $priority = 100): int {
    $stmt = db()->prepare("INSERT INTO providers (name, slug, baseurl, type, status, sort_order, priority) VALUES (?, ?, ?, ?, 1, 0, ?)");
    $stmt->execute([$name, $slug, $baseurl, $type, $priority]);
    return (int)db()->lastInsertId();
}

function provider_update(int $id, array $f): bool {
    $sets = []; $vals = [];
    foreach (['name', 'slug', 'baseurl', 'type', 'status', 'sort_order', 'priority'] as $k) {
        if (isset($f[$k])) { $sets[] = "$k = ?"; $vals[] = $f[$k]; }
    }
    if (!$sets) return false;
    $vals[] = $id;
    return db()->prepare("UPDATE providers SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
}

function provider_delete(int $id): bool {
    return db()->prepare("DELETE FROM providers WHERE id = ?")->execute([$id]);
}

// --- کلیدها ---

function key_list(int $providerId): array {
    $stmt = db()->prepare("SELECT id, provider_id, label, status, request_count, error_count, consecutive_errors, last_used_at, cooldown_until, created_at FROM api_keys WHERE provider_id = ? ORDER BY id");
    $stmt->execute([$providerId]);
    return $stmt->fetchAll();
}

function key_get(int $id): ?array {
    // هرگز key_encrypted برنگردان — فقط متادیتا
    $stmt = db()->prepare("SELECT id, provider_id, label, status, request_count, error_count, consecutive_errors, last_used_at, cooldown_until, created_at FROM api_keys WHERE id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function key_create(int $providerId, string $keyValue, string $label): int {
    $stmt = db()->prepare("INSERT INTO api_keys (provider_id, key_encrypted, label, status) VALUES (?, ?, ?, 1)");
    $stmt->execute([$providerId, encrypt_key($keyValue), $label]);
    return (int)db()->lastInsertId();
}

function key_update(int $id, array $f): bool {
    $sets = []; $vals = [];
    if (isset($f['label'])) { $sets[] = "label = ?"; $vals[] = $f['label']; }
    if (isset($f['status'])) { $sets[] = "status = ?"; $vals[] = (int)$f['status']; }
    if (isset($f['key_value']) && $f['key_value'] !== '') { $sets[] = "key_encrypted = ?"; $vals[] = encrypt_key($f['key_value']); }
    if (isset($f['reset_stats']) && $f['reset_stats']) {
        $sets[] = "request_count = 0"; $sets[] = "error_count = 0"; $sets[] = "consecutive_errors = 0"; $sets[] = "cooldown_until = NULL";
    }
    if (!$sets) return false;
    $vals[] = $id;
    return db()->prepare("UPDATE api_keys SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
}

function key_delete(int $id): bool {
    return db()->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$id]);
}

// --- مدل‌ها ---

function model_list(int $providerId = 0): array {
    if ($providerId > 0) {
        $stmt = db()->prepare("SELECT m.*, p.name AS provider_name, p.slug AS provider_slug FROM models m LEFT JOIN providers p ON m.provider_id = p.id WHERE m.provider_id = ? ORDER BY m.sort_order, m.id");
        $stmt->execute([$providerId]);
    } else {
        $stmt = db()->query("SELECT m.*, p.name AS provider_name, p.slug AS provider_slug FROM models m LEFT JOIN providers p ON m.provider_id = p.id ORDER BY p.sort_order, m.sort_order, m.id");
    }
    return $stmt->fetchAll();
}

function model_find(string $modelId): ?array {
    $stmt = db()->prepare("SELECT m.*, p.name AS provider_name, p.slug AS provider_slug, p.baseurl, p.type, p.status AS provider_status FROM models m JOIN providers p ON m.provider_id = p.id WHERE m.model_id = ? AND m.status = 1 AND p.status = 1 LIMIT 1");
    $stmt->execute([$modelId]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function model_create(int $providerId, string $modelId, string $name, ?string $fallback, int $isDefault = 0): int {
    $stmt = db()->prepare("INSERT INTO models (provider_id, model_id, display_name, fallback_model, is_default, status, sort_order) VALUES (?, ?, ?, ?, ?, 1, 0)");
    $stmt->execute([$providerId, $modelId, $name, $fallback, $isDefault]);
    return (int)db()->lastInsertId();
}

function model_update(int $id, array $f): bool {
    $sets = []; $vals = [];
    foreach (['model_id', 'display_name', 'fallback_model', 'is_default', 'status', 'sort_order'] as $k) {
        if (isset($f[$k])) { $sets[] = "$k = ?"; $vals[] = $f[$k]; }
    }
    if (!$sets) return false;
    $vals[] = $id;
    return db()->prepare("UPDATE models SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
}

function model_delete(int $id): bool {
    return db()->prepare("DELETE FROM models WHERE id = ?")->execute([$id]);
}

// ================================================================
// بخش ۵: Load Balancing — انتخاب بهترین کلید
// ================================================================

/**
 * گرفتن کلیدهای فعال و آماده برای یک ارائه‌دهنده.
 * کلیدهایی که در cooldown هستند یا غیرفعالند حذف می‌شوند.
 */
function get_available_keys(int $providerId): array {
    $now = date('Y-m-d H:i:s');
    $stmt = db()->prepare("
        SELECT * FROM api_keys
        WHERE provider_id = ? AND status = 1
          AND (cooldown_until IS NULL OR cooldown_until < ?)
        ORDER BY consecutive_errors ASC, request_count ASC, last_used_at IS NULL DESC, last_used_at ASC
    ");
    $stmt->execute([$providerId, $now]);
    return $stmt->fetchAll();
}

/**
 * ثبت موفقیت یک کلید.
 */
function key_record_success(int $keyId): void {
    $stmt = db()->prepare("UPDATE api_keys SET request_count = request_count + 1, consecutive_errors = 0, last_used_at = NOW(), cooldown_until = NULL WHERE id = ?");
    $stmt->execute([$keyId]);
}

/**
 * ثبت شکست یک کلید و قرار دادن در cooldown.
 */
function key_record_failure(int $keyId): void {
    $pdo = db();
    $cooldown = date('Y-m-d H:i:s', time() + KEY_COOLDOWN_SECONDS);
    $stmt = $pdo->prepare("UPDATE api_keys SET request_count = request_count + 1, error_count = error_count + 1, consecutive_errors = consecutive_errors + 1, last_used_at = NOW(), cooldown_until = ? WHERE id = ?");
    $stmt->execute([$cooldown, $keyId]);
    // کلیدها فقط cooldown می‌شن، هرگز غیرفعال نمی‌شن (auto-disable حذف شد)
}

// ================================================================
// بخش ۶: Failover — اجرای درخواست با کلیدهای جایگزین و مدل fallback
// ================================================================

/**
 * مسیریابی و اجرای درخواست با Load Balancing و Failover.
 *
 * دو حالت مسیریابی:
 *  - specific: مدل مشخص شده → به ارائه‌دهنده همان مدل می‌رود + کلید خلوت‌تر
 *  - general:  مدل مشخص نشده → ارائه‌دهنده خلوت‌تر انتخاب می‌شود + مدل پیش‌فرض + کلید خلوت‌تر
 *
 * الگوریتم:
 *  ۱. (specific) مدل را پیدا کن → ارائه‌دهنده‌اش را مشخص کن
 *     (general)  ارائه‌دهنده خلووت‌تر را انتخاب کن + مدل پیش‌فرض او
 *  ۲. کلیدهای فعال ارائه‌دهنده را بگیر (مرتب بر اساس خلوت بودن)
 *  ۳. هر کلید را امتحان کن:
 *     - موفق → ثبت موفقیت + بازگشت نتیجه
 *     - شکست → ثبت شکست + cooldown + کلید بعدی
 *  ۴. همه کلیدها شکست خوردند → مدل fallback را امتحان کن (ارائه‌دهنده دیگر)
 *
 * @return array ['ok', 'response', 'error', 'provider', 'key_id', 'fallback_used', 'mode']
 */
function route_and_execute(string $model, string $action, array $payload, string $mode = 'specific', array $visited = []): array {
    if (count($visited) > FAILOVER_MAX_PROVIDERS + 1) {
        return ['ok' => false, 'response' => '', 'error' => mtr('err.maxFailover'), 'provider' => null, 'key_id' => null, 'fallback_used' => null, 'mode' => $mode];
    }

    // حالت general: انتخاب ارائه‌دهنده خلووت‌تر
    if ($mode === 'general' && empty($visited)) {
        $chosen = select_general_provider();
        if (!$chosen) {
            return ['ok' => false, 'response' => '', 'error' => mtr('err.noActiveProvider'), 'provider' => null, 'key_id' => null, 'fallback_used' => null, 'mode' => $mode];
        }
        $modelRow = model_find($chosen['default_model']);
        if (!$modelRow) {
            // اگر مدل پیش‌فرض پیدا نشد، اولین مدل فعال ارائه‌دهنده را بگیر
            $models = model_list((int)$chosen['id']);
            $modelRow = $models[0] ?? null;
        }
        if (!$modelRow) {
            return ['ok' => false, 'response' => '', 'error' => mtr('err.noModelForProvider', ['{provider}' => $chosen['slug']]), 'provider' => null, 'key_id' => null, 'fallback_used' => null, 'mode' => $mode];
        }
        $modelRow['provider_slug'] = $chosen['slug'];
        $modelRow['provider_id'] = (int)$chosen['id'];
        $modelRow['baseurl'] = $chosen['baseurl'];
        $modelRow['type'] = $chosen['type'];
        $visited[] = $modelRow['model_id'];
        log_info("General routing: provider {$chosen['slug']} selected (least loaded)", ['model' => $modelRow['model_id']]);
    } else {
        // حالت specific: مدل مشخص شده
        $modelRow = model_find($model);
        if (!$modelRow) {
            return ['ok' => false, 'response' => '', 'error' => mtr('err.modelNotActiveOrMissing', ['{model}' => $model]), 'provider' => null, 'key_id' => null, 'fallback_used' => null, 'mode' => $mode];
        }
        $visited[] = $model;
    }

    $providerId = (int)$modelRow['provider_id'];
    $providerSlug = $modelRow['provider_slug'];

    // بررسی circuit breaker
    $cb = cb_check($providerId);
    if (!$cb['available']) {
        log_warn("Circuit breaker open for {$providerSlug}. Switching to fallback.", ['model' => $modelRow['model_id']]);
        return try_fallback($modelRow, $action, $payload, $visited, mtr('err.cbOpen'), $mode);
    }

    $keys = get_available_keys($providerId);
    if (empty($keys)) {
        log_warn("No active key for provider {$providerSlug}. Switching to fallback.", ['model' => $modelRow['model_id']]);
        return try_fallback($modelRow, $action, $payload, $visited, '', $mode);
    }

    $lastError = '';
    $startTime = microtime(true);
    foreach ($keys as $key) {
        $apiKey = decrypt_key($key['key_encrypted']);
        if ($apiKey === '') {
            key_record_failure((int)$key['id']);
            $lastError = mtr('err.keyDecrypt');
            continue;
        }

        $result = call_worker($modelRow, $key, $apiKey, $action, $payload);

        if ($result['ok']) {
            $latencyMs = (int)((microtime(true) - $startTime) * 1000);
            key_record_success((int)$key['id']);
            cb_record_success($providerId);
            return [
                'ok' => true,
                'response' => $result['response'],
                'error' => '',
                'provider' => $providerSlug,
                'key_id' => (int)$key['id'],
                'fallback_used' => count($visited) > 1 ? implode(' → ', $visited) : null,
                'mode' => $mode,
                'latency_ms' => $latencyMs,
            ];
        }

        $lastError = $result['error'];
        key_record_failure((int)$key['id']);
        cb_record_failure($providerId);
        log_warn("کلید #{$key['id']} ({$providerSlug}) شکست خورد: {$lastError}. تلاش با کلید بعدی.", ['model' => $modelRow['model_id']]);
    }

    return try_fallback($modelRow, $action, $payload, $visited, $lastError, $mode);
}

/**
 * انتخاب ارائه‌دهنده برای حالت general.
 * الگوریتم:
 *  ۱. اولویت (priority) — عدد کمتر = اول تلاش می‌شود
 *  ۲. بار (load) — کمترین درخواست در صف + در حال پردازش
 *  ۳. وزن (weight) — عدد بالاتر = ارجحیت بیشتر در صورت تساوی
 *
 * ارائه‌دهنده‌ای که اولویت کمتر و بار کمتر دارد انتخاب می‌شود.
 *
 * @return array|null ردیف ارائه‌دهنده یا null
 */
function select_general_provider(): ?array {
    $pdo = db();
    // مرتب بر اساس priority ASC (کمتر=اول)، سپس load، سپس weight DESC
    // فقط ارائه‌دهنده‌هایی که circuit breaker شان بسته یا half-open است
    $providers = $pdo->query("SELECT * FROM providers WHERE status = 1 AND circuit_state != 'open' ORDER BY priority ASC, sort_order ASC, id ASC")->fetchAll();
    if (!$providers) {
        // اگر همه open هستند، بررسی باز شدن half-open
        $providers = $pdo->query("SELECT * FROM providers WHERE status = 1 ORDER BY priority ASC, id ASC")->fetchAll();
        if (!$providers) return null;
    }

    // شمارش بار هر ارائه‌دهنده
    $stmt = $pdo->prepare("
        SELECT p.id, p.slug, COUNT(r.id) AS load_count
        FROM providers p
        LEFT JOIN models m ON m.provider_id = p.id AND m.status = 1
        LEFT JOIN requests r ON r.model = m.model_id AND r.status IN ('queued','processing')
        WHERE p.status = 1
        GROUP BY p.id
    ");
    $stmt->execute();
    $loads = [];
    foreach ($stmt->fetchAll() as $row) $loads[(int)$row['id']] = (int)$row['load_count'];

    // مدل پیش‌فرض هر ارائه‌دهنده
    $defaults = [];
    $stmt2 = $pdo->query("SELECT provider_id, model_id FROM models WHERE is_default = 1 AND status = 1");
    foreach ($stmt2->fetchAll() as $row) $defaults[(int)$row['provider_id']] = $row['model_id'];

    // گروه‌بندی بر اساس priority: در هر گروه اولویت، ارائه‌دهنده با کمترین بار انتخاب می‌شود
    // اما اگر همه ارائه‌دهنده‌های یک اولویت بار بالا دارند، به اولویت بعدی نمی‌رویم
    // (اولویت مطلق است مگر اینکه ارائه‌دهنده‌های اولویت بالاتر همه در cooldown/non-active باشند)
    $byPriority = [];
    foreach ($providers as $p) {
        $pri = (int)$p['priority'];
        if (!isset($byPriority[$pri])) $byPriority[$pri] = [];
        $byPriority[$pri][] = $p;
    }
    ksort($byPriority); // مرتب بر اساس priority

    foreach ($byPriority as $pri => $group) {
        // در این گروه، ارائه‌دهنده با کمترین بار + بالاترین health score + داشتن مدل پیش‌فرض
        usort($group, function($a, $b) use ($loads, $defaults) {
            $la = $loads[(int)$a['id']] ?? 0;
            $lb = $loads[(int)$b['id']] ?? 0;
            // اول health score بالاتر
            $ha = (int)$a['health_score'];
            $hb = (int)$b['health_score'];
            if ($ha !== $hb) return $hb - $ha;
            // اول کسانی که مدل پیش‌فرض دارند
            $aHasDefault = isset($defaults[(int)$a['id']]) ? 0 : 1;
            $bHasDefault = isset($defaults[(int)$b['id']]) ? 0 : 1;
            if ($aHasDefault !== $bHasDefault) return $aHasDefault - $bHasDefault;
            // سپس کمترین بار
            if ($la !== $lb) return $la - $lb;
            return 0;
        });
        $best = $group[0] ?? null;
        if ($best && isset($defaults[(int)$best['id']])) {
            $best['default_model'] = $defaults[(int)$best['id']];
            return $best;
        }
        if ($best) {
            $best['default_model'] = null;
            return $best;
        }
    }
    return null;
}

/**
 * تلاش با مدل fallback.
 */
function try_fallback(array $modelRow, string $action, array $payload, array $visited, string $lastError = '', string $mode = 'specific'): array {
    $fallbackModel = $modelRow['fallback_model'];
    if (!$fallbackModel || in_array($fallbackModel, $visited)) {
        return ['ok' => false, 'response' => '', 'error' => $lastError ?: mtr('err.allKeysFailed'), 'provider' => null, 'key_id' => null, 'fallback_used' => implode(' → ', $visited), 'mode' => $mode];
    }
    log_warn("Failover: switching from '{$modelRow['model_id']}' to '{$fallbackModel}'", ['chain' => implode(' → ', $visited)]);
    // در fallback همیشه specific می‌شویم چون مدل fallback مشخص است
    return route_and_execute($fallbackModel, $action, $payload, 'specific', $visited);
}

/**
 * فراخوانی ورکر مناسب بر اساس نوع ارائه‌دهنده.
 */
function call_worker(array $modelRow, array $keyRow, string $apiKey, string $action, array $payload): array {
    require_once __DIR__ . '/workers.php';
    $type = $modelRow['type'];
    if ($type === 'gapgpt') {
        return run_worker_gapgpt($modelRow, $apiKey, $action, $payload);
    }
    // همه ارائه‌دهنده‌های دیگر (zai, deepseek, nvidia, openrouter) از chat/completions استفاده می‌کنند
    return run_worker_chat($modelRow, $apiKey, $action, $payload);
}

// ================================================================
// بخش ۷: صف
// ================================================================

function queue_add(int $clientId, string $mode, string $model, string $action, string $payload, int $priority, ?string $callbackUrl, ?string $idempotencyKey = null, ?string $correlationId = null, int $streamMode = 0, ?string $scheduledAt = null): int {
    $pdo = db();
    $maxAttempts = DEAD_QUEUE_MAX_ATTEMPTS;
    $stmt = $pdo->prepare("INSERT INTO requests (client_id, mode, model, action, payload, priority, status, callback_url, idempotency_key, correlation_id, max_attempts, stream_mode, scheduled_at, created_at) VALUES (?, ?, ?, ?, ?, ?, 'queued', ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$clientId, $mode, $model, $action, $payload, $priority, $callbackUrl, $idempotencyKey, $correlationId, $maxAttempts, $streamMode, $scheduledAt]);
    $id = (int)$pdo->lastInsertId();
    // محاسبه موقعیت در صف (تعداد درخواست‌های با اولویت بالاتر یا مساوی + خودش)
    $posStmt = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE status = 'queued' AND (priority > ? OR (priority = ? AND created_at <= (SELECT created_at FROM requests WHERE id = ?)))");
    $posStmt->execute([$priority, $priority, $id]);
    $position = (int)$posStmt->fetchColumn();
    $pdo->prepare("UPDATE requests SET queue_position = ? WHERE id = ?")->execute([$position, $id]);
    return $id;
}

/**
 * محاسبه زمان تخمینی پردازش (ETA) بر اساس موقعیت و میانگین latency.
 */
function queue_eta(int $position): int {
    $pdo = db();
    $avgLat = (int)$pdo->query("SELECT AVG(latency_ms) FROM requests WHERE status = 'completed' AND latency_ms IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();
    if (!$avgLat) $avgLat = 2000; // پیش‌فرض ۲ ثانیه
    $concurrency = max(1, WORKER_CONCURRENCY);
    return (int)(($position / $concurrency) * $avgLat);
}

// ================================================================
// بخش ۶.۵: Rate Limiting چندلایه (Minute/Hour/Day/Month)
// ================================================================

/**
 * بررسی rate limit برای کلاینت یا کلید API.
 * @param string $entityType 'client' یا 'apikey'
 * @param string $entityId شناسه
 * @return array ['ok' => bool, 'limits' => [...], 'retry_after' => int]
 */
function rate_limit_check_multi(string $entityType, string $entityId): array {
    $pdo = db();
    $limits = $GLOBALS['RATE_LIMITS'][$entityType] ?? $GLOBALS['RATE_LIMITS']['client'];
    $now = time();
    $periods = [
        'minute' => 60,
        'hour' => 3600,
        'day' => 86400,
        'month' => 2592000,
    ];
    $result = ['ok' => true, 'limits' => [], 'retry_after' => 0];
    foreach ($periods as $period => $seconds) {
        $windowStart = date('Y-m-d H:i:s', $now - ($now % $seconds));
        $stmt = $pdo->prepare("SELECT count FROM rate_limits WHERE entity_type = ? AND entity_id = ? AND period = ? AND window_start = ?");
        $stmt->execute([$entityType, $entityId, $period, $windowStart]);
        $row = $stmt->fetch();
        $count = $row ? (int)$row['count'] : 0;
        $limit = $limits[$period] ?? 999999;
        $result['limits'][$period] = ['used' => $count, 'limit' => $limit, 'remaining' => max(0, $limit - $count)];
        if ($count >= $limit) {
            $result['ok'] = false;
            $result['retry_after'] = max($result['retry_after'], $seconds - ($now % $seconds));
        }
    }
    return $result;
}

/**
 * ثبت یک درخواست در rate limit (افزایش شمارنده).
 */
function rate_limit_increment(string $entityType, string $entityId): void {
    $pdo = db();
    $now = time();
    $periods = ['minute' => 60, 'hour' => 3600, 'day' => 86400, 'month' => 2592000];
    foreach ($periods as $period => $seconds) {
        $windowStart = date('Y-m-d H:i:s', $now - ($now % $seconds));
        // INSERT ... ON DUPLICATE KEY UPDATE (MySQL)
        $stmt = $pdo->prepare("INSERT INTO rate_limits (entity_type, entity_id, period, count, window_start) VALUES (?, ?, ?, 1, ?) ON DUPLICATE KEY UPDATE count = count + 1");
        $stmt->execute([$entityType, $entityId, $period, $windowStart]);
    }
}

// ================================================================
// بخش ۶.۶: امنیت پیشرفته — HMAC + Nonce + Timestamp
// ================================================================

/**
 * اعتبارسنجی HMAC Signature + Timestamp + Nonce.
 * @return array ['ok' => bool, 'error' => string]
 */
function security_validate_request(string $method, string $path, string $body): array {
    if (!SECURITY_HMAC_ENABLED) return ['ok' => true, 'error' => ''];

    $timestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';
    $nonce = $_SERVER['HTTP_X_NONCE'] ?? '';
    $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';

    if (!$timestamp || !$nonce || !$signature) {
        return ['ok' => false, 'error' => mtr('err.secHeaders')];
    }

    // اعتبارسنجی timestamp (درخواست‌های قدیمی رد می‌شن)
    $reqTime = (int)$timestamp;
    if (abs(time() - $reqTime) > SECURITY_TIMESTAMP_WINDOW) {
        return ['ok' => false, 'error' => mtr('err.secExpired')];
    }

    // بررسی nonce (جلوگیری از replay attack)
    $pdo = db();
    $clientCode = $_SERVER['HTTP_X_CLIENT_CODE'] ?? 'unknown';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM nonces WHERE nonce = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)");
    $stmt->execute([$nonce, SECURITY_NONCE_TTL_HOURS]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['ok' => false, 'error' => mtr('err.secReplay')];
    }
    // ثبت nonce
    $pdo->prepare("INSERT IGNORE INTO nonces (nonce, client_code) VALUES (?, ?)")->execute([$nonce, $clientCode]);

    // اعتبارسنجی امضا
    $expectedSig = hash_hmac('sha256', $method . $path . $timestamp . $nonce . $body, MASTER_SECRET);
    if (!hash_equals($expectedSig, $signature)) {
        return ['ok' => false, 'error' => mtr('err.secSignature')];
    }

    return ['ok' => true, 'error' => ''];
}

// ================================================================
// بخش ۶.۷: Health Check — بررسی سلامت ارائه‌دهنده‌ها
// ================================================================

/**
 * تست سلامت یک ارائه‌دهنده.
 */
function health_check_provider(int $providerId): array {
    $pdo = db();
    $p = provider_get($providerId);
    if (!$p) return ['ok' => false, 'error' => 'provider not found'];

    $start = microtime(true);
    $modelRow = null;
    // پیدا کردن مدل تست
    $models = model_list($providerId);
    foreach ($models as $m) {
        if ($m['status'] == 1) { $modelRow = $m; break; }
    }
    if (!$modelRow) {
        // ثبت نتیجه ناسالم
        record_health_check($providerId, 'unhealthy', null, 'No active model available for testing');
        return ['ok' => false, 'error' => 'no model'];
    }

    $keys = get_available_keys($providerId);
    if (empty($keys)) {
        record_health_check($providerId, 'unhealthy', null, 'No active key available');
        return ['ok' => false, 'error' => 'no key'];
    }

    $apiKey = decrypt_key($keys[0]['key_encrypted']);
    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
    $base = rtrim($p['baseurl'], '/');
    // انتخاب endpoint بر اساس نوع ارائه‌دهنده — اکثر آن‌ها OpenAI chat/completions هستند (B5)
    if (($p['type'] ?? 'chat') === 'gapgpt') {
        $url = $base . '/responses';
        $body = ['model' => $modelRow['model_id'], 'input' => 'ping', 'max_output_tokens' => 5];
    } else {
        $url = $base . '/chat/completions';
        $body = ['model' => $modelRow['model_id'], 'messages' => [['role' => 'user', 'content' => 'ping']], 'max_tokens' => 5];
    }
    $r = http_request('POST', $url, $headers, $body, HEALTH_CHECK_TIMEOUT);
    $latency = (int)((microtime(true) - $start) * 1000);

    if ($r['error'] || $r['status'] >= 400) {
        record_health_check($providerId, 'unhealthy', $latency, $r['error'] ?: "HTTP {$r['status']}");
        // کاهش health score
        $pdo->prepare("UPDATE providers SET health_score = GREATEST(0, health_score - 20), last_health_check = NOW() WHERE id = ?")->execute([$providerId]);
        return ['ok' => false, 'error' => $r['error'] ?: "HTTP {$r['status']}", 'latency' => $latency];
    }

    record_health_check($providerId, 'healthy', $latency, null);
    // افزایش health score (بازگشت به 100)
    $pdo->prepare("UPDATE providers SET health_score = LEAST(100, health_score + 10), last_health_check = NOW(), avg_latency_ms = ? WHERE id = ?")->execute([$latency, $providerId]);
    return ['ok' => true, 'latency' => $latency];
}

function record_health_check(int $providerId, string $status, ?int $latency, ?string $error): void {
    $pdo = db();
    $pdo->prepare("INSERT INTO health_checks (provider_id, status, latency_ms, error) VALUES (?, ?, ?, ?)")
        ->execute([$providerId, $status, $latency, $error]);
}

// ================================================================
// بخش ۶.۸: طبقه‌بندی خطاها برای Failover هوشمند
// ================================================================

/**
 * طبقه‌بندی خطا برای تصمیم‌گیری failover.
 * @return array ['type' => timeout|server_error|rate_limit|network|auth|client_error, 'retryable' => bool]
 */
function classify_error(string $error, int $httpCode = 0): array {
    $e = strtolower($error);
    
    // HTML response = driver/endpoint error, NOT provider failure
    if (strpos($e, '<!doctype') !== false || strpos($e, '<html') !== false || strpos($e, 'html response') !== false) {
        return ['type' => 'driver_error', 'retryable' => false, 'disable_key' => false];
    }
    
    // Timeout — retryable
    if (strpos($e, 'timeout') !== false || strpos($e, 'timed out') !== false) {
        return ['type' => 'timeout', 'retryable' => true, 'disable_key' => false];
    }
    // Network — retryable
    if (strpos($e, 'connection') !== false || strpos($e, 'network') !== false || strpos($e, 'resolve') !== false || strpos($e, 'curl error') !== false) {
        return ['type' => 'network', 'retryable' => true, 'disable_key' => false];
    }
    // 429 — rate limit or quota — retryable but DON'T retry immediately
    // Check if it's a permanent quota exhaustion (monthly/yearly)
    if ($httpCode === 429) {
        // Monthly/yearly quota = NOT retryable (won't recover soon)
        if (strpos($e, 'monthly') !== false || strpos($e, 'yearly') !== false || strpos($e, 'quota') !== false || strpos($e, 'plan') !== false) {
            return ['type' => 'quota_exhausted', 'retryable' => false, 'disable_key' => false];
        }
        // Regular rate limit — retryable
        return ['type' => 'rate_limit', 'retryable' => true, 'disable_key' => false];
    }
    // 401 — invalid API key — NOT retryable, but DON'T disable (user might fix it)
    if ($httpCode === 401) return ['type' => 'auth', 'retryable' => false, 'disable_key' => false];
    // 403 — forbidden/permission — NOT retryable
    // Could be: wrong endpoint, wrong API version, region restriction, permission denied
    if ($httpCode === 403) return ['type' => 'forbidden', 'retryable' => false, 'disable_key' => false];
    // 402 — payment required — NOT retryable (won't recover without payment)
    if ($httpCode === 402) return ['type' => 'payment_required', 'retryable' => false, 'disable_key' => false];
    // 404 — model not found or endpoint not found — NOT retryable
    if ($httpCode === 404) return ['type' => 'not_found', 'retryable' => false, 'disable_key' => false];
    // 5xx — server error — retryable
    if ($httpCode >= 500) return ['type' => 'server_error', 'retryable' => true, 'disable_key' => false];
    // Other 4xx — NOT retryable
    if ($httpCode >= 400) return ['type' => 'client_error', 'retryable' => false, 'disable_key' => false];
    // Unknown — retryable (might be transient)
    return ['type' => 'unknown', 'retryable' => true, 'disable_key' => false];
}

// ================================================================
// بخش ۶.۹: Worker Status — مدیریت workerهای فعال
// ================================================================

function worker_register(string $workerId): void {
    $pdo = db();
    $pdo->prepare("INSERT INTO worker_status (id, hostname, pid, status, started_at, last_heartbeat) VALUES (?, ?, ?, 'idle', NOW(), NOW()) ON DUPLICATE KEY UPDATE status = 'idle', last_heartbeat = NOW()")
        ->execute([$workerId, gethostname(), getmypid()]);
}

function worker_heartbeat(string $workerId, string $status, ?int $currentJob = null): void {
    $pdo = db();
    $pdo->prepare("UPDATE worker_status SET status = ?, current_job = ?, last_heartbeat = NOW() WHERE id = ?")
        ->execute([$status, $currentJob, $workerId]);
}

function worker_unregister(string $workerId): void {
    db()->prepare("DELETE FROM worker_status WHERE id = ?")->execute([$workerId]);
}

function worker_list(): array {
    return db()->query("SELECT * FROM worker_status ORDER BY started_at DESC")->fetchAll();
}

// ================================================================
// بخش ۶.۱۰: Cron Status
// ================================================================

function cron_record_run(string $name, int $durationMs, string $result): void {
    $pdo = db();
    $pdo->prepare("INSERT INTO cron_status (cron_name, last_run, last_duration_ms, last_result, run_count, error_count) VALUES (?, NOW(), ?, ?, 1, ?) ON DUPLICATE KEY UPDATE last_run = NOW(), last_duration_ms = ?, last_result = ?, run_count = run_count + 1, error_count = error_count + ?")
        ->execute([$name, $durationMs, $result, $result === 'error' ? 1 : 0, $durationMs, $result, $result === 'error' ? 1 : 0]);
}

function cron_status_list(): array {
    return db()->query("SELECT * FROM cron_status ORDER BY cron_name")->fetchAll();
}

// ================================================================
// بخش ۶.۵: Circuit Breaker — مدیریت قطعی ارائه‌دهنده
// ================================================================

/**
 * بررسی وضعیت circuit breaker یک ارائه‌دهنده.
 * @return array ['state' => closed|open|half-open, 'available' => bool]
 */
function cb_check(int $providerId): array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT circuit_state, circuit_opened_at, failure_count FROM providers WHERE id = ?");
    $stmt->execute([$providerId]);
    $p = $stmt->fetch();
    if (!$p) return ['state' => 'closed', 'available' => true];

    $state = $p['circuit_state'];
    if ($state === 'closed') return ['state' => 'closed', 'available' => true];

    if ($state === 'open') {
        // بررسی اینکه cooldown گذشته یا نه
        if ($p['circuit_opened_at']) {
            $opened = strtotime($p['circuit_opened_at']);
            if (time() - $opened >= CB_COOLDOWN_SECONDS) {
                // انتقال به half-open
                $pdo->prepare("UPDATE providers SET circuit_state = 'half-open' WHERE id = ?")->execute([$providerId]);
                return ['state' => 'half-open', 'available' => true];
            }
        }
        return ['state' => 'open', 'available' => false];
    }

    // half-open: اجازه استفاده با محدودیت
    return ['state' => 'half-open', 'available' => true];
}

/**
 * ثبت موفقیت — بستن circuit breaker.
 */
function cb_record_success(int $providerId): void {
    $pdo = db();
    $pdo->prepare("UPDATE providers SET circuit_state = 'closed', circuit_opened_at = NULL, failure_count = 0 WHERE id = ?")->execute([$providerId]);
}

/**
 * ثبت شکست — باز کردن circuit breaker اگر خطاها از حد گذشته.
 */
function cb_record_failure(int $providerId): void {
    $pdo = db();
    $pdo->prepare("UPDATE providers SET failure_count = failure_count + 1 WHERE id = ?")->execute([$providerId]);
    $stmt = $pdo->prepare("SELECT failure_count FROM providers WHERE id = ?");
    $stmt->execute([$providerId]);
    $fc = (int)$stmt->fetchColumn();
    if ($fc >= CB_FAILURE_THRESHOLD) {
        $pdo->prepare("UPDATE providers SET circuit_state = 'open', circuit_opened_at = NOW() WHERE id = ?")->execute([$providerId]);
        log_warn("Circuit breaker opened for provider #{$providerId} ({$fc} consecutive errors)");
    }
}

/**
 * بازنشانی دستی circuit breaker (از پنل ادمین).
 */
function cb_reset(int $providerId): bool {
    return db()->prepare("UPDATE providers SET circuit_state = 'closed', circuit_opened_at = NULL, failure_count = 0 WHERE id = ?")->execute([$providerId]);
}

// ================================================================
// بخش ۶.۶: Idempotency — جلوگیری از پردازش تکراری
// ================================================================

/**
 * بررسی کلید idempotency.
 * @return array|null درخواست قبلی اگر وجود دارد، در غیر این صورت null
 */
function idempotency_check(string $key): ?array {
    if (!IDEMPOTENCY_ENABLED || !$key) return null;
    $pdo = db();
    $cutoff = date('Y-m-d H:i:s', time() - IDEMPOTENCY_TTL_HOURS * 3600);
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE idempotency_key = ? AND created_at > ? AND status IN ('completed','processing') ORDER BY id DESC LIMIT 1");
    $stmt->execute([$key, $cutoff]);
    $r = $stmt->fetch();
    return $r ?: null;
}

// ================================================================
// بخش ۶.۷: ACK — تاییدیه فوری دریافت درخواست
// ================================================================

/**
 * ارسال ACK (تاییدیه فوری) به callback_url.
 * @param string $callbackUrl آدرس مقصد
 * @param array $ackData داده‌های ACK
 */
function send_ack(string $callbackUrl, array $ackData): void {
    if (!ACK_ENABLED || !ACK_CALLBACK || !is_valid_url($callbackUrl)) return;
    send_callback_robust($callbackUrl, $ackData);
}

/**
 * ساخت داده ACK.
 */
function build_ack(int $requestId, string $status, string $message, array $extra = []): array {
    return array_merge([
        'type' => 'ack',
        'request_id' => $requestId,
        'status' => $status,
        'message' => $message,
        'timestamp' => date('c'),
    ], $extra);
}

function queue_next(): ?array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->query("SELECT * FROM requests WHERE status = 'queued' AND attempts <= " . QUEUE_RETRY_MAX . " ORDER BY priority DESC, created_at ASC LIMIT 1 FOR UPDATE");
        $req = $stmt->fetch();
        if (!$req) { $pdo->commit(); return null; }
        $upd = $pdo->prepare("UPDATE requests SET status = 'processing' WHERE id = ? AND status = 'queued'");
        $upd->execute([$req['id']]);
        if ($upd->rowCount() === 0) { $pdo->commit(); return null; }
        $pdo->commit();
        return $req;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function queue_complete(int $id, string $response, ?string $providerSlug, ?int $keyId, ?string $fallbackUsed, ?int $latencyMs = null): void {
    $stmt = db()->prepare("UPDATE requests SET status = 'completed', response = ?, provider_slug = ?, api_key_id = ?, fallback_used = ?, latency_ms = ?, processed_at = NOW() WHERE id = ?");
    $stmt->execute([$response, $providerSlug, $keyId, $fallbackUsed, $latencyMs, $id]);
}

function queue_fail(int $id, string $error, bool $canRetry): void {
    if ($canRetry) {
        $stmt = db()->prepare("UPDATE requests SET status = 'queued', error = ?, attempts = attempts + 1 WHERE id = ?");
    } else {
        $stmt = db()->prepare("UPDATE requests SET status = 'failed', error = ?, attempts = attempts + 1, processed_at = NOW() WHERE id = ?");
    }
    $stmt->execute([$error, $id]);
}

function queue_status(int $id): ?array {
    $stmt = db()->prepare("SELECT r.*, c.name AS client_name, c.code AS client_code FROM requests r LEFT JOIN clients c ON r.client_id = c.id WHERE r.id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return $r ?: null;
}

function queue_stats(): array {
    $stats = [
        'queued' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0,
        'retry' => 0, 'dead' => 0, 'delayed' => 0, 'total' => 0
    ];
    $rows = db()->query("SELECT status, COUNT(*) as c FROM requests GROUP BY status")->fetchAll();
    foreach ($rows as $r) {
        $status = $r['status'];
        if (isset($stats[$status])) $stats[$status] = (int)$r['c'];
        $stats['total'] += (int)$r['c'];
    }
    return $stats;
}

/**
 * آمار تفصیلی صف — شامل زمان‌های میانگین، workerهای زنده، throughput و نرخ خطا.
 * برای داشبورد و endpoint detailed_stats استفاده می‌شه.
 */
function queue_detailed_stats(): array {
    $pdo = db();
    $breakdown = [];
    $rows = $pdo->query("SELECT status, COUNT(*) as c FROM requests GROUP BY status")->fetchAll();
    foreach ($rows as $r) { $breakdown[$r['status']] = (int)$r['c']; }

    // میانگین زمان صف و پردازش (آخرین ۱ ساعت)
    $avgQueue = (int)$pdo->query("SELECT COALESCE(AVG(queue_time_ms), 0) FROM requests WHERE status = 'completed' AND queue_time_ms IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();
    $avgProcess = (int)$pdo->query("SELECT COALESCE(AVG(total_time_ms), 0) FROM requests WHERE status = 'completed' AND total_time_ms IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)")->fetchColumn();

    // P50, P95, P99 latency (آخرین ۱ ساعت)
    $p50 = queue_percentile($pdo, 'total_time_ms', 50);
    $p95 = queue_percentile($pdo, 'total_time_ms', 95);
    $p99 = queue_percentile($pdo, 'total_time_ms', 99);

    // Workerهای زنده و مشغول
    $workersAlive = (int)$pdo->query("SELECT COUNT(*) FROM worker_status WHERE last_heartbeat > DATE_SUB(NOW(), INTERVAL 30 SECOND)")->fetchColumn();
    $workersBusy = (int)$pdo->query("SELECT COUNT(*) FROM worker_status WHERE status = 'working' AND last_heartbeat > DATE_SUB(NOW(), INTERVAL 30 SECOND)")->fetchColumn();

    // Throughput (درخواست‌های تکمیل‌شده در دقیقه اخیر)
    $throughput = (int)$pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'completed' AND processed_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)")->fetchColumn();

    // نرخ retry و نرخ شکست
    $totalFinished = (int)$pdo->query("SELECT COUNT(*) FROM requests WHERE status IN ('completed', 'failed', 'dead')")->fetchColumn();
    $retryCount = (int)$pdo->query("SELECT COUNT(*) FROM requests WHERE status = 'retry'")->fetchColumn();
    $failedCount = (int)$pdo->query("SELECT COUNT(*) FROM requests WHERE status IN ('failed', 'dead')")->fetchColumn();

    return [
        'waiting' => $breakdown['queued'] ?? 0,
        'running' => $breakdown['running'] ?? 0,
        'retry' => $breakdown['retry'] ?? 0,
        'delayed' => $breakdown['delayed'] ?? 0,
        'completed' => $breakdown['completed'] ?? 0,
        'failed' => $breakdown['failed'] ?? 0,
        'dead' => $breakdown['dead'] ?? 0,
        'total' => array_sum($breakdown),
        'avg_queue_time_ms' => $avgQueue,
        'avg_processing_time_ms' => $avgProcess,
        'p50_latency_ms' => $p50,
        'p95_latency_ms' => $p95,
        'p99_latency_ms' => $p99,
        'workers_alive' => $workersAlive,
        'workers_busy' => $workersBusy,
        'throughput_per_minute' => $throughput,
        'retry_rate' => $totalFinished > 0 ? round(($retryCount / $totalFinished) * 100, 2) : 0,
        'failure_rate' => $totalFinished > 0 ? round(($failedCount / $totalFinished) * 100, 2) : 0,
    ];
}

/**
 * محاسبه percentile یک column از جدول requests.
 * فقط columnهای مجاز — جلوگیری از SQL injection.
 *
 * @param PDO    $pdo    اتصال دیتابیس
 * @param string $column نام ستون (total_time_ms, queue_time_ms, latency_ms)
 * @param int    $percentile percentile مورد نظر (0-100)
 * @return int مقدار percentile یا 0
 */
function queue_percentile(PDO $pdo, string $column, int $percentile): int {
    static $allowed = ['total_time_ms', 'queue_time_ms', 'latency_ms'];
    if (!in_array($column, $allowed, true)) return 0;
    try {
        $sql = "SELECT COALESCE({$column}, 0) FROM requests WHERE status = 'completed' AND {$column} IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) ORDER BY {$column}";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
        if (empty($rows)) return 0;
        $idx = (int)floor(count($rows) * $percentile / 100);
        if ($idx >= count($rows)) $idx = count($rows) - 1;
        if ($idx < 0) $idx = 0;
        return (int)$rows[$idx];
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * پردازش یک درخواست خاص با ID (برای حالت sync).
 * این تابع دقیقاً همون درخواستی که الان ثبت شده رو پردازش می‌کنه.
 */
function queue_process_by_id(int $id): array {
    $pdo = db();
    // اول رکورد رو بخون
    $stmt = $pdo->prepare("SELECT * FROM requests WHERE id = ?");
    $stmt->execute([$id]);
    $req = $stmt->fetch();
    if (!$req) return ['processed' => false, 'reason' => 'not_found'];
    // علامت‌گذاری به running (با زمان‌بندی)
    $upd = $pdo->prepare("UPDATE requests SET status = 'running', running_at = NOW(), queue_time_ms = TIMESTAMPDIFF(MICROSECOND, created_at, NOW()) DIV 1000 WHERE id = ? AND status IN ('queued','locked','retry')");
    $upd->execute([$id]);

    $mode = $req['mode'] ?? 'specific';
    $payload = json_decode($req['payload'], true) ?: [];
    $totalStart = microtime(true);
    $result = route_and_execute($req['model'], $req['action'], $payload, $mode);
    $totalMs = (int)((microtime(true) - $totalStart) * 1000);

    if ($result['ok']) {
        $latency = $result['latency_ms'] ?? $totalMs;
        // ثبت کامل با queue_time و total_time
        $stmt = $pdo->prepare("UPDATE requests SET status = 'completed', response = ?, provider_slug = ?, api_key_id = ?, fallback_used = ?, latency_ms = ?, total_time_ms = ?, processed_at = NOW() WHERE id = ?");
        $stmt->execute([$result['response'], $result['provider'], $result['key_id'], $result['fallback_used'], $latency, $totalMs, $id]);
        // بروزرسانی آمار ارائه‌دهنده
        if ($result['provider']) {
            $pdo->prepare("UPDATE providers SET total_requests = total_requests + 1, active_requests = GREATEST(0, active_requests - 1) WHERE slug = ?")->execute([$result['provider']]);
        }
        if (!empty($req['callback_url'])) {
            send_callback_robust($req['callback_url'], [
                'request_id' => (int)$req['id'], 'status' => 'completed', 'model' => $req['model'], 'mode' => $mode,
                'provider' => $result['provider'], 'fallback_used' => $result['fallback_used'],
                'latency_ms' => $latency, 'total_time_ms' => $totalMs,
                'queue_time_ms' => $req['queue_time_ms'] ?? 0,
                'response' => json_decode($result['response'], true),
            ]);
        }
        log_info("درخواست #{$req['id']} پردازش شد (sync)", ['model' => $req['model'], 'mode' => $mode, 'provider' => $result['provider'], 'fallback' => $result['fallback_used'], 'latency_ms' => $latency, 'total_ms' => $totalMs]);
        return ['processed' => true, 'id' => (int)$req['id'], 'status' => 'completed', 'mode' => $mode, 'provider' => $result['provider'], 'fallback' => $result['fallback_used'], 'latency_ms' => $latency, 'total_time_ms' => $totalMs];
    } else {
        // طبقه‌بندی خطا
        $errType = classify_error($result['error']);
        $attempts = (int)$req['attempts'] + 1;
        $maxAttempts = (int)($req['max_attempts'] ?: DEAD_QUEUE_MAX_ATTEMPTS);

        if ($attempts >= $maxAttempts) {
            // انتقال به dead queue
            $stmt = $pdo->prepare("UPDATE requests SET status = 'dead', error = ?, error_type = ?, attempts = ?, processed_at = NOW() WHERE id = ?");
            $stmt->execute([$result['error'], $errType['type'], $attempts, $id]);
            if (!empty($req['callback_url'])) {
                send_callback_robust($req['callback_url'], ['request_id' => (int)$req['id'], 'status' => 'dead', 'error' => $result['error'], 'error_type' => $errType['type']]);
            }
            log_error("Request #{$req['id']} moved to dead queue: {$result['error']}", ['model' => $req['model'], 'mode' => $mode, 'attempts' => $attempts]);
            return ['processed' => true, 'id' => (int)$req['id'], 'status' => 'dead', 'mode' => $mode, 'error' => $result['error'], 'error_type' => $errType['type']];
        } elseif ($errType['retryable']) {
            // retry با exponential backoff
            $delay = min(RETRY_MAX_DELAY, RETRY_BASE_DELAY * pow(2, $attempts - 1));
            $scheduledAt = date('Y-m-d H:i:s', time() + $delay);
            $stmt = $pdo->prepare("UPDATE requests SET status = 'retry', error = ?, error_type = ?, attempts = ?, scheduled_at = ? WHERE id = ?");
            $stmt->execute([$result['error'], $errType['type'], $attempts, $scheduledAt, $id]);
            log_warn("درخواست #{$req['id']} برای retry زمان‌بندی شد (تلاش {$attempts}/{$maxAttempts})، تاخیر {$delay}s", ['model' => $req['model'], 'error_type' => $errType['type']]);
            return ['processed' => true, 'id' => (int)$req['id'], 'status' => 'retry', 'mode' => $mode, 'error' => $result['error'], 'error_type' => $errType['type'], 'retry_in' => $delay];
        } else {
            // خطای غیرقابل retry → failed
            $stmt = $pdo->prepare("UPDATE requests SET status = 'failed', error = ?, error_type = ?, attempts = ?, processed_at = NOW() WHERE id = ?");
            $stmt->execute([$result['error'], $errType['type'], $attempts, $id]);
            if (!empty($req['callback_url'])) {
                send_callback_robust($req['callback_url'], ['request_id' => (int)$req['id'], 'status' => 'failed', 'error' => $result['error'], 'error_type' => $errType['type']]);
            }
            log_error("Request #{$req['id']} failed (sync): {$result['error']}", ['model' => $req['model'], 'mode' => $mode, 'error_type' => $errType['type']]);
            return ['processed' => true, 'id' => (int)$req['id'], 'status' => 'failed', 'mode' => $mode, 'error' => $result['error'], 'error_type' => $errType['type']];
        }
    }
}

/**
 * پردازش یک درخواست از صف با Load Balancing و Failover.
 * از mode درخواست (general/specific) برای مسیریابی استفاده می‌کند.
 */
function queue_process_one(): array {
    $req = queue_next();
    if (!$req) return ['processed' => false, 'reason' => 'empty'];

    $mode = $req['mode'] ?? 'specific';
    $payload = json_decode($req['payload'], true) ?: [];
    $result = route_and_execute($req['model'], $req['action'], $payload, $mode);

    if ($result['ok']) {
        queue_complete((int)$req['id'], $result['response'], $result['provider'], $result['key_id'], $result['fallback_used']);
        if (!empty($req['callback_url'])) {
            send_callback($req['callback_url'], [
                'request_id' => (int)$req['id'],
                'status' => 'completed',
                'model' => $req['model'],
                'mode' => $mode,
                'provider' => $result['provider'],
                'fallback_used' => $result['fallback_used'],
                'response' => json_decode($result['response'], true),
            ]);
        }
        log_info("درخواست #{$req['id']} پردازش شد", ['model' => $req['model'], 'mode' => $mode, 'provider' => $result['provider'], 'fallback' => $result['fallback_used']]);
        return ['processed' => true, 'id' => (int)$req['id'], 'status' => 'completed', 'mode' => $mode, 'provider' => $result['provider'], 'fallback' => $result['fallback_used']];
    } else {
        $canRetry = $req['attempts'] < QUEUE_RETRY_MAX;
        queue_fail((int)$req['id'], $result['error'], $canRetry);
        if (!$canRetry && !empty($req['callback_url'])) {
            send_callback($req['callback_url'], ['request_id' => (int)$req['id'], 'status' => 'failed', 'error' => $result['error']]);
        }
        log_error("Request #{$req['id']} failed: {$result['error']}", ['model' => $req['model'], 'mode' => $mode]);
        return ['processed' => true, 'id' => (int)$req['id'], 'status' => 'failed', 'mode' => $mode, 'error' => $result['error']];
    }
}

/**
 * پردازش batch از صف با همزمانی واقعی (concurrency).
 * از curl_multi_exec برای اجرای همزمان N درخواست HTTP به API استفاده می‌کند.
 *
 * الگوریتم:
 *  ۱. N درخواست از صف می‌گیریم (به صورت atomic)
 *  ۲. همه را به حالت processing می‌بریم
 *  ۳. به صورت موازی اجرا می‌کنیم (curl_multi)
 *  ۴. نتایج را ذخیره می‌کنیم
 *  ۵. Callback‌ها را ارسال می‌کنیم
 *
 * @param int $n تعداد کل درخواست‌های پردازش‌شده (پیش‌فرض QUEUE_BATCH_SIZE)
 * @return array نتایج
 */
function queue_process_batch(int $n = 0): array {
    $n = $n > 0 ? $n : QUEUE_BATCH_SIZE;
    $concurrency = min(QUEUE_CONCURRENCY, $n);
    $results = [];
    $processed = 0;

    while ($processed < $n) {
        $chunk = min($concurrency, $n - $processed);
        $requests = queue_next_batch($chunk);
        if (empty($requests)) break;

        // اجرای موازی با curl_multi
        $chunkResults = process_requests_parallel($requests);
        foreach ($chunkResults as $r) {
            $results[] = $r;
            $processed++;
        }
    }
    return $results;
}

/**
 * گرفتن چند درخواست از صف به صورت atomic.
 */
function queue_next_batch(int $count): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // گرفتن N درخواست با قفل ردیفی (FOR UPDATE) — جلوگیری از پردازش تکراری در MySQL
        $stmt = $pdo->prepare("SELECT * FROM requests WHERE status = 'queued' AND attempts <= " . QUEUE_RETRY_MAX . " ORDER BY priority DESC, created_at ASC LIMIT ? FOR UPDATE");
        $stmt->bindValue(1, $count, PDO::PARAM_INT);
        $stmt->execute();
        $reqs = $stmt->fetchAll();
        if (empty($reqs)) { $pdo->commit(); return []; }
        // علامت‌گذاری همه به processing
        $ids = array_column($reqs, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $upd = $pdo->prepare("UPDATE requests SET status = 'processing' WHERE id IN ($placeholders) AND status = 'queued'");
        $upd->execute($ids);
        $pdo->commit();
        return $reqs;
    } catch (Exception $e) {
        $pdo->rollBack();
        return [];
    }
}

/**
 * پردازش موازی چند درخواست با curl_multi.
 * هر درخواست به یک ارائه‌دهنده/کلید می‌رود و همزمان اجرا می‌شود.
 */
function process_requests_parallel(array $requests): array {
    if (empty($requests)) return [];

    // برای هر درخواست، مسیریابی را انجام می‌دهیم (انتخاب provider + key)
    // سپس HTTP request را به curl_multi اضافه می‌کنیم
    $handles = [];
    $mh = curl_multi_init();
    $context = []; // نگاشت handle → request info

    foreach ($requests as $req) {
        $mode = $req['mode'] ?? 'specific';
        $payload = json_decode($req['payload'], true) ?: [];

        // مسیریابی: انتخاب provider + key (بدون اجرای HTTP)
        $route = prepare_route($req['model'], $req['action'], $payload, $mode);

        if (!$route['ok']) {
            // مسیریابی ناموفق - بدون اجرای HTTP
            $canRetry = (int)$req['attempts'] < QUEUE_RETRY_MAX;
            queue_fail((int)$req['id'], $route['error'], $canRetry);
            log_error("Request #{$req['id']} routing failed: {$route['error']}", ['model' => $req['model'], 'mode' => $mode]);
            $handles[] = null;
            $context[] = ['req' => $req, 'result' => ['ok' => false, 'error' => $route['error']], 'route' => $route];
            continue;
        }

        // ساخت curl handle
        $ch = build_curl_handle($route, $req);
        if ($ch) {
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        } else {
            $handles[] = null;
        }
        $context[] = ['req' => $req, 'route' => $route, 'result' => null];
    }

    // اجرای موازی
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh);
    } while ($active && $status === CURLM_OK);

    // جمع‌آوری نتایج
    $results = [];
    foreach ($handles as $i => $ch) {
        $ctx = &$context[$i];
        $req = $ctx['req'];

        if ($ch === null) {
            // قبلاً هندل شده (مسیریابی ناموفق)
            $results[] = ['processed' => true, 'id' => (int)$req['id'], 'status' => 'failed', 'mode' => $req['mode'] ?? 'specific', 'error' => $ctx['result']['error'] ?? 'unknown'];
            continue;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $body = curl_multi_getcontent($ch);
        $err = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        $route = $ctx['route'];
        $result = process_http_result($httpCode, $body, $err, $route);

        if ($result['ok']) {
            key_record_success((int)$route['key_id']);
            queue_complete((int)$req['id'], $result['response'], $route['provider'], (int)$route['key_id'], $route['fallback_used']);
            // Callback
            if (!empty($req['callback_url'])) {
                send_callback_robust($req['callback_url'], [
                    'request_id' => (int)$req['id'],
                    'status' => 'completed',
                    'model' => $req['model'],
                    'mode' => $req['mode'] ?? 'specific',
                    'provider' => $route['provider'],
                    'fallback_used' => $route['fallback_used'],
                    'response' => json_decode($result['response'], true),
                ]);
            }
            log_info("درخواست #{$req['id']} پردازش شد (موازی)", ['model' => $req['model'], 'mode' => $req['mode'] ?? 'specific', 'provider' => $route['provider'], 'fallback' => $route['fallback_used']]);
            $results[] = ['processed' => true, 'id' => (int)$req['id'], 'status' => 'completed', 'mode' => $req['mode'] ?? 'specific', 'provider' => $route['provider'], 'fallback' => $route['fallback_used']];
        } else {
            key_record_failure((int)$route['key_id']);
            $canRetry = (int)$req['attempts'] < QUEUE_RETRY_MAX;
            queue_fail((int)$req['id'], $result['error'], $canRetry);
            if (!$canRetry && !empty($req['callback_url'])) {
                send_callback_robust($req['callback_url'], ['request_id' => (int)$req['id'], 'status' => 'failed', 'error' => $result['error']]);
            }
            log_error("Request #{$req['id']} failed (parallel): {$result['error']}", ['model' => $req['model']]);
            $results[] = ['processed' => true, 'id' => (int)$req['id'], 'status' => 'failed', 'mode' => $req['mode'] ?? 'specific', 'error' => $result['error']];
        }
    }
    curl_multi_close($mh);
    return $results;
}

/**
 * مسیریابی بدون اجرای HTTP — فقط انتخاب provider + key + ساخت URL/body.
 */
function prepare_route(string $model, string $action, array $payload, string $mode = 'specific'): array {
    if ($mode === 'general') {
        $chosen = select_general_provider();
        if (!$chosen) return ['ok' => false, 'error' => mtr('err.noActiveProvider')];

        $modelRow = null;
        if (!empty($chosen['default_model'])) {
            $modelRow = model_find($chosen['default_model']);
        }
        if (!$modelRow) {
            $models = model_list((int)$chosen['id']);
            $modelRow = $models[0] ?? null;
        }
        if (!$modelRow) return ['ok' => false, 'error' => mtr('err.noModelForProvider', ['{provider}' => $chosen['slug']])];

        $modelRow['provider_slug'] = $chosen['slug'];
        $modelRow['provider_id'] = (int)$chosen['id'];
        $modelRow['baseurl'] = $chosen['baseurl'];
        $modelRow['type'] = $chosen['type'];
        $modelRow['fallback_used'] = null;
        $modelRow['visited'] = [$modelRow['model_id']];
    } else {
        $modelRow = model_find($model);
        if (!$modelRow) return ['ok' => false, 'error' => mtr('err.modelNotActiveOrMissing', ['{model}' => $model])];
        $modelRow['fallback_used'] = null;
        $modelRow['visited'] = [$model];
    }

    $providerId = (int)$modelRow['provider_id'];
    $keys = get_available_keys($providerId);
    if (empty($keys)) {
        // تلاش fallback
        return try_prepare_fallback($modelRow, $action, $payload, $modelRow['visited'], mtr('err.noActiveKey'), $mode);
    }

    $key = $keys[0]; // خلوت‌ترین کلید
    $apiKey = decrypt_key($key['key_encrypted']);
    if ($apiKey === '') {
        return ['ok' => false, 'error' => mtr('err.keyDecrypt')];
    }

    // ساخت URL + body بر اساس نوع worker
    $httpReq = build_http_request($modelRow, $apiKey, $action, $payload);
    if (!$httpReq) return ['ok' => false, 'error' => mtr('err.invalidAction', ['{action}' => $action])];

    return [
        'ok' => true,
        'url' => $httpReq['url'],
        'method' => $httpReq['method'],
        'headers' => $httpReq['headers'],
        'body' => $httpReq['body'],
        'provider' => $modelRow['provider_slug'],
        'key_id' => (int)$key['id'],
        'fallback_used' => $modelRow['fallback_used'],
    ];
}

/**
 * Fallback برای prepare_route.
 */
function try_prepare_fallback(array $modelRow, string $action, array $payload, array $visited, string $lastError, string $mode): array {
    $fallbackModel = $modelRow['fallback_model'];
    if (!$fallbackModel || in_array($fallbackModel, $visited)) {
        return ['ok' => false, 'error' => $lastError];
    }
    $fallbackRow = model_find($fallbackModel);
    if (!$fallbackRow) return ['ok' => false, 'error' => $lastError];
    $fallbackRow['fallback_used'] = implode(' → ', array_merge($visited, [$fallbackModel]));
    $fallbackRow['visited'] = array_merge($visited, [$fallbackModel]);

    $keys = get_available_keys((int)$fallbackRow['provider_id']);
    if (empty($keys)) return ['ok' => false, 'error' => $lastError];

    $key = $keys[0];
    $apiKey = decrypt_key($key['key_encrypted']);
    if ($apiKey === '') return ['ok' => false, 'error' => mtr('err.keyDecrypt')];

    $httpReq = build_http_request($fallbackRow, $apiKey, $action, $payload);
    if (!$httpReq) return ['ok' => false, 'error' => "اکشن '{$action}' در fallback پشتیبانی نمی‌شود"];

    return [
        'ok' => true,
        'url' => $httpReq['url'],
        'method' => $httpReq['method'],
        'headers' => $httpReq['headers'],
        'body' => $httpReq['body'],
        'provider' => $fallbackRow['provider_slug'],
        'key_id' => (int)$key['id'],
        'fallback_used' => $fallbackRow['fallback_used'],
    ];
}

/**
 * ساخت HTTP request بر اساس نوع worker.
 */
function build_http_request(array $modelRow, string $apiKey, string $action, array $payload): ?array {
    require_once __DIR__ . '/workers.php';
    $base = rtrim($modelRow['baseurl'], '/');
    $type = $modelRow['type'];
    $defaultModel = $modelRow['model_id'] ?? 'gpt-4o-mini';

    $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];

    if ($type === 'gapgpt') {
        return build_gapgpt_request($base, $defaultModel, $action, $payload, $headers);
    }
    return build_chat_request($base, $defaultModel, $action, $payload, $headers);
}

/**
 * ساخت curl handle از route.
 */
function build_curl_handle(array $route, array $req): ?CurlHandle {
    $ch = curl_init();
    $headers = $route['headers'];
    curl_setopt_array($ch, [
        CURLOPT_URL => $route['url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $route['method'],
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => HTTP_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => HTTP_TIMEOUT,
        CURLOPT_USERAGENT => HTTP_USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($route['body'] !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($route['body']) ? json_encode($route['body']) : $route['body']);
    }
    return $ch;
}

/**
 * پردازش نتیجه HTTP از curl.
 */
function process_http_result(int $httpCode, string $body, string $err, array $route): array {
    if ($err) return ['ok' => false, 'error' => mtr('err.conn', ['{err}' => $err])];
    if ($httpCode >= 200 && $httpCode < 300) return ['ok' => true, 'response' => $body];
    $json = json_decode($body, true);
    $msg = "HTTP {$httpCode}";
    if (is_array($json)) {
        if (isset($json['error']['message'])) $msg .= ': ' . $json['error']['message'];
        elseif (isset($json['message'])) $msg .= ': ' . $json['message'];
        elseif (isset($json['detail'])) $msg .= ': ' . (is_string($json['detail']) ? $json['detail'] : json_encode($json['detail']));
    } else {
        $msg .= ': ' . substr($body, 0, 300);
    }
    return ['ok' => false, 'error' => $msg];
}

/**
 * Callback قوی با retry.
 */
function send_callback_robust(string $url, array $data): void {
    if (!is_safe_callback_url($url)) return;
    $body = json_encode($data, JSON_UNESCAPED_UNICODE);
    for ($attempt = 0; $attempt <= CALLBACK_RETRY_MAX; $attempt++) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => CALLBACK_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            log_info("Callback delivered to {$url}", ['request_id' => $data['request_id'] ?? null, 'attempt' => $attempt + 1]);
            return;
        }
        if ($attempt < CALLBACK_RETRY_MAX) {
            usleep(500000 * ($attempt + 1)); // 0.5s, 1s, 1.5s
        }
    }
    log_error("Callback to {$url} failed after " . (CALLBACK_RETRY_MAX + 1) . " attempts: HTTP {$code} {$err}", ['request_id' => $data['request_id'] ?? null]);
}

/**
 * پاکسازی درخواست‌های قدیمی (برای cron).
 * درخواست‌های تکمیل‌شده یا ناموفق که از QUEUE_RETENTION_DAYS گذشته‌اند را حذف می‌کند.
 */
function cleanup_old_requests(): int {
    $pdo = db();
    $cutoff = date('Y-m-d H:i:s', time() - QUEUE_RETENTION_DAYS * 86400);
    $stmt = $pdo->prepare("DELETE FROM requests WHERE status IN ('completed','failed') AND created_at < ?");
    $stmt->execute([$cutoff]);
    return $stmt->rowCount();
}

/**
 * بازنشانی cooldown کلیدهای منقضی‌شده (برای cron).
 */
function reset_expired_cooldowns(): int {
    $now = date('Y-m-d H:i:s');
    $stmt = db()->prepare("UPDATE api_keys SET cooldown_until = NULL WHERE cooldown_until IS NOT NULL AND cooldown_until < ?");
    $stmt->execute([$now]);
    return $stmt->rowCount();
}

// ================================================================
// بخش ۸: HTTP Client
// ================================================================

function http_request(string $method, string $url, array $headers = [], $body = null, int $timeout = 0): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => HTTP_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => $timeout > 0 ? $timeout : HTTP_TIMEOUT,
        CURLOPT_USERAGENT => HTTP_USER_AGENT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($body !== null) {
        if (is_array($body)) {
            $isMultipart = false;
            foreach ($body as $v) {
                if (is_array($v) && isset($v['isFile'])) { $isMultipart = true; break; }
            }
            if ($isMultipart) {
                $pf = [];
                foreach ($body as $k => $v) {
                    if (is_array($v) && isset($v['isFile'])) {
                        $pf[$k] = new CURLFile($v['path'], $v['mime'], $v['name']);
                    } else { $pf[$k] = $v; }
                }
                curl_setopt($ch, CURLOPT_POSTFIELDS, $pf);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['status' => 0, 'body' => '', 'error' => $err];
    return ['status' => (int)$code, 'body' => $resp, 'error' => ''];
}

function send_callback(string $url, array $data): void {
    if (!is_safe_callback_url($url)) return;
    @http_request('POST', $url, ['Content-Type: application/json'], $data, 30);
}

// ================================================================
// بخش ۹: لاگ‌گذاری
// ================================================================

function log_info(string $m, array $c = []): void { _log('info', $m, $c); }
function log_error(string $m, array $c = []): void { _log('error', $m, $c); }
function log_warn(string $m, array $c = []): void { _log('warn', $m, $c); }

function _log(string $level, string $msg, array $ctx = []): void {
    try {
        db()->prepare("INSERT INTO logs (level, message, context) VALUES (?, ?, ?)")->execute([$level, $msg, json_encode($ctx, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {}
    // Log Rotation — نگه‌داری ۵ فایل لاگ
    $line = sprintf("[%s] [%s] %s %s\n", date('Y-m-d H:i:s'), strtoupper($level), $msg, $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : '');
    @file_put_contents(LOG_PATH, $line, FILE_APPEND | LOCK_EX);
    if (file_exists(LOG_PATH) && filesize(LOG_PATH) > LOG_MAX_SIZE) {
        // چرخش: 4→5, 3→4, 2→3, 1→2, current→1
        for ($i = 4; $i >= 1; $i--) {
            $old = LOG_PATH . '.' . $i;
            $new = LOG_PATH . '.' . ($i + 1);
            if (file_exists($old)) @rename($old, $new);
        }
        @rename(LOG_PATH, LOG_PATH . '.1');
        // حذف فایل‌های قدیمی‌تر از ۵
        for ($i = 6; $i <= 10; $i++) {
            $f = LOG_PATH . '.' . $i;
            if (file_exists($f)) @unlink($f);
        }
    }
}

/**
 * لاگ‌گذاری عملیات ادمین (Audit Log).
 * اکشن‌های ادمین (فعال/غیرفعال کردن provider، تغییر کلیدها و ...) رو در جدول logs با level='audit' ذخیره می‌کنه.
 *
 * @param string $action  نام اکشن (مثلاً 'provider.toggle', 'key.create')
 * @param string $message پیام توصیفی
 * @param array  $context داده‌های اضافی (id, user, ...)
 */
function audit_log(string $action, string $message, array $context = []): void {
    $ctx = array_merge(['action' => $action], $context);
    try {
        db()->prepare("INSERT INTO logs (level, message, context) VALUES ('audit', ?, ?)")
            ->execute([$message, json_encode($ctx, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {}
    $line = sprintf("[%s] [AUDIT] %s :: %s %s\n", date('Y-m-d H:i:s'), $action, $message, $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : '');
    @file_put_contents(LOG_PATH, $line, FILE_APPEND | LOCK_EX);
}

function log_list(int $limit = 100, string $level = ''): array {
    $pdo = db();
    if ($level) {
        $stmt = $pdo->prepare("SELECT * FROM logs WHERE level = ? ORDER BY id DESC LIMIT ?");
        $stmt->execute([$level, $limit]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM logs ORDER BY id DESC LIMIT ?");
        $stmt->execute([$limit]);
    }
    return $stmt->fetchAll();
}

// ================================================================
// بخش ۱۰: امنیت و کمکی
// ================================================================

function clean_input($v) {
    if (is_string($v)) return htmlspecialchars(trim($v), ENT_QUOTES, 'UTF-8');
    if (is_array($v)) return array_map('clean_input', $v);
    return $v;
}

/**
 * ترجمه یک کلید با fallback امن برای محیط‌های CLI (بدون lang.php).
 * در web از t() استفاده می‌کند؛ در CLI/worker از فرهنگ لغت فارسی به‌صورت مستقیم.
 */
function mtr(string $key, array $vars = []): string {
    if (function_exists('t')) return t($key, $vars);
    static $dict = null;
    if ($dict === null) {
        $f = __DIR__ . '/lang/fa.json';
        $dict = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    }
    $s = $dict[$key] ?? $key;
    foreach ($vars as $k => $v) $s = str_replace($k, (string)$v, $s);
    return $s;
}

// ================================================================
// بخش ۱۰.۱: سخت‌سازی نشست (Session) + Origin + Brute-Force
// ================================================================

/**
 * شروع امن نشست: کوکی HttpOnly + SameSite=Lax (+ Secure روی HTTPS).
 * در همه ورودی‌های وب (index.php / api.php) استفاده شود.
 */
function secure_session_start(?string $sessionName = null): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1'); // رد کردن session id انتخابی مهاجم
    ini_set('session.use_only_cookies', '1');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    if ($sessionName !== null && $sessionName !== '') session_name($sessionName);
    session_start();
}

/** هدرهای امنیتی پایه برای پاسخ‌های HTTP. */
function send_security_headers(): void {
    if (headers_sent()) return;
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
}

/**
 * آدرس IP واقعی کلاینت.
 * عمداً از X-Forwarded-For استفاده نمی‌شود (قابل جعل است).
 */
function client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return ($ip !== '' && $ip !== null) ? (string)$ip : '0.0.0.0';
}

/**
 * آیا مسیر فایل داخل پوشه مجاز uploads است؟
 * (جلوگیری از خواندن فایل‌های دلخواه سرور از طریق payload در اکشن‌هایی مثل transcriptions)
 */
function is_allowed_upload_path(string $path): bool {
    if ($path === '') return false;
    $root = realpath(__DIR__ . '/uploads');
    if ($root === false) return false;
    $real = realpath($path);
    return $real !== false && strncmp($real, $root . DIRECTORY_SEPARATOR, strlen($root) + 1) === 0;
}

/**
 * بررسی محدودیت تلاش‌های ناموفق احراز هویت (پنجره ۱۵ دقیقه‌ای).
 * @param string $scope  مثل 'admin_login' یا 'client_auth'
 * @param string $entity مثل IP یا کد کلاینت
 * @param int $limit حداکثر تلاش ناموفق مجاز در پنجره
 * @return array ['ok' => bool, 'retry_after' => int, 'count' => int]
 */
function auth_throttle_check(string $scope, string $entity, int $limit = 8): array {
    try {
        $pdo = db();
        $windowStart = date('Y-m-d H:i:s', time() - (time() % 900));
        $stmt = $pdo->prepare("SELECT count FROM rate_limits WHERE entity_type = ? AND entity_id = ? AND period = 'auth' AND window_start = ?");
        $stmt->execute([$scope, $entity, $windowStart]);
        $row = $stmt->fetch();
        $count = $row ? (int)$row['count'] : 0;
        if ($count >= $limit) {
            return ['ok' => false, 'retry_after' => 900 - (time() % 900), 'count' => $count];
        }
        return ['ok' => true, 'retry_after' => 0, 'count' => $count];
    } catch (Throwable $e) {
        return ['ok' => true, 'retry_after' => 0, 'count' => 0]; // fail-open فقط برای خطای دیتابیس
    }
}

/** ثبت یک تلاش ناموفق احراز هویت. */
function auth_throttle_fail(string $scope, string $entity): void {
    try {
        $pdo = db();
        $windowStart = date('Y-m-d H:i:s', time() - (time() % 900));
        $stmt = $pdo->prepare("INSERT INTO rate_limits (entity_type, entity_id, period, count, window_start) VALUES (?, ?, 'auth', 1, ?) ON DUPLICATE KEY UPDATE count = count + 1");
        $stmt->execute([$scope, $entity, $windowStart]);
    } catch (Throwable $e) {}
}

/** پاک کردن شمارنده تلاش‌های ناموفق (بعد از ورود موفق). */
function auth_throttle_clear(string $scope, string $entity): void {
    try {
        db()->prepare("DELETE FROM rate_limits WHERE entity_type = ? AND entity_id = ? AND period = 'auth'")
            ->execute([$scope, $entity]);
    } catch (Throwable $e) {}
}

/**
 * بررسی هم‌مبدأ بودن درخواست (برای دفاع CSRF روی اکشن‌های تغییردهنده ادمین).
 * درخواست‌های بدون Origin (curl / server-to-server) مجازند.
 */
function is_same_origin_request(): bool {
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        // مرورگرهای مدرن Sec-Fetch-Site می‌فرستند؛ cross-site یعنی CSRF است
        $sfs = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($sfs === 'cross-site') return false;
        return true;
    }
    // مقایسه بر اساس host (نه scheme) تا پشت پروکسی/ترمینیشن TLS شکسته نشود؛
    // دفاع اصلی CSRF کوکی SameSite=Lax است.
    $originHost = strtolower((string)parse_url($origin, PHP_URL_HOST));
    $selfHost = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? '')));
    return $originHost !== '' && $originHost === $selfHost;
}

function gen_token(int $len = 32): string {
    return bin2hex(random_bytes(max(8, (int)($len / 2))));
}

function gen_client_code(string $prefix = 'miz'): string {
    return $prefix . '_' . substr(bin2hex(random_bytes(8)), 0, 12);
}

function gen_slug(string $name): string {
    $s = preg_replace('/[^a-zA-Z0-9]+/', '_', $name);
    return strtolower(trim($s, '_')) ?: 'provider';
}

/**
 * اعتبارسنجی پایه URL (فقط http/https با syntax معتبر).
 */
function is_valid_url(?string $url): bool {
    if (empty($url)) return false;
    return (bool)filter_var($url, FILTER_VALIDATE_URL) && (bool)preg_match('#^https?://#i', $url);
}

/**
 * URL امن برای callback / webhook (دفاع SSRF).
 * localhost، IPهای خصوصی/رزروشده و hostnameهای داخلی رد می‌شوند.
 */
function is_safe_callback_url(?string $url): bool {
    if (!is_valid_url($url)) return false;

    $parts = parse_url($url);
    if (!is_array($parts)) return false;
    $host = strtolower(trim((string)($parts['host'] ?? '')));
    if ($host === '') return false;

    if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0', '[::1]'], true)) return false;
    if (preg_match('/\.(local|internal|localhost|lan|home)$/i', $host)) return false;
    if (str_starts_with($host, 'fe80:') || str_starts_with($host, 'fc') || str_starts_with($host, 'fd')) return false;

    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $resolved = @gethostbynamel($host);
        if (is_array($resolved) && $resolved) {
            $ips = $resolved;
        }
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

function json_response($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apply_cors(): void {
    if (!CORS_ENABLED) return;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (in_array('*', CORS_ORIGINS, true) || in_array($origin, CORS_ORIGINS, true)) {
        header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Client-Code, X-Client-Secret');
        header('Access-Control-Max-Age: 86400');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

function get_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// --- CRUD کلاینت ---
function client_create(string $name, int $degree, string $secret): array {
    $code = gen_client_code();
    db()->prepare("INSERT INTO clients (name, code, degree, secret_hash, status) VALUES (?, ?, ?, ?, 1)")->execute([$name, $code, $degree, hash_client_secret($secret)]);
    return ['id' => (int)db()->lastInsertId(), 'code' => $code];
}
function client_list(): array {
    return db()->query("SELECT id, name, code, degree, status, created_at FROM clients ORDER BY id DESC")->fetchAll();
}
function client_update(int $id, array $f): bool {
    $sets = []; $vals = [];
    foreach (['name', 'degree', 'status'] as $k) { if (isset($f[$k])) { $sets[] = "$k = ?"; $vals[] = $f[$k]; } }
    if (isset($f['secret'])) { $sets[] = 'secret_hash = ?'; $vals[] = hash_client_secret($f['secret']); }
    if (!$sets) return false;
    $vals[] = $id;
    return db()->prepare("UPDATE clients SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
}
function client_delete(int $id): bool {
    return db()->prepare("DELETE FROM clients WHERE id = ?")->execute([$id]);
}

// --- آمار پیشرفته (بار هر ارائه‌دهنده + تعداد کلاینت‌ها + metrics) ---
function advanced_stats(): array {
    $pdo = db();
    $stats = queue_stats();
    // بار هر ارائه‌دهنده + circuit state + latency + health score + active requests
    $providerLoads = $pdo->query("
        SELECT p.slug, p.name, p.priority, p.circuit_state, p.failure_count,
               p.health_score, p.active_requests, p.total_requests, p.total_errors, p.avg_latency_ms,
               p.last_health_check,
               COUNT(r.id) AS load_count,
               AVG(r.latency_ms) AS avg_latency,
               COUNT(CASE WHEN r.status = 'completed' THEN 1 END) AS completed_count,
               COUNT(CASE WHEN r.status = 'failed' THEN 1 END) AS failed_count,
               COUNT(CASE WHEN r.status = 'dead' THEN 1 END) AS dead_count
        FROM providers p
        LEFT JOIN models m ON m.provider_id = p.id AND m.status = 1
        LEFT JOIN requests r ON r.model = m.model_id
        WHERE p.status = 1
        GROUP BY p.id ORDER BY p.priority ASC, p.sort_order
    ")->fetchAll();
    foreach ($providerLoads as &$pl) {
        $pl['avg_latency'] = $pl['avg_latency'] ? round((float)$pl['avg_latency']) : (int)$pl['avg_latency_ms'];
        $total = (int)$pl['completed_count'] + (int)$pl['failed_count'];
        $pl['success_rate'] = $total > 0 ? round($pl['completed_count'] / $total * 100, 1) : 100;
        $pl['error_rate'] = $total > 0 ? round($pl['failed_count'] / $total * 100, 1) : 0;
    }
    unset($pl);

    // queue breakdown by status
    $queueBreakdown = [];
    $rows = $pdo->query("SELECT status, COUNT(*) as c FROM requests GROUP BY status")->fetchAll();
    foreach ($rows as $r) $queueBreakdown[$r['status']] = (int)$r['c'];

    // workers
    $workers = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN status = 'working' THEN 1 ELSE 0 END) as active, SUM(CASE WHEN status = 'idle' THEN 1 ELSE 0 END) as idle FROM worker_status")->fetch();
    $workerStats = ['total' => (int)$workers['total'], 'active' => (int)$workers['active'], 'idle' => (int)$workers['idle']];

    // cron status
    $crons = $pdo->query("SELECT * FROM cron_status ORDER BY cron_name")->fetchAll();

    // avg queue time + avg total time
    $avgQueueTime = (float)$pdo->query("SELECT AVG(queue_time_ms) FROM requests WHERE status = 'completed' AND queue_time_ms IS NOT NULL")->fetchColumn();
    $avgTotalTime = (float)$pdo->query("SELECT AVG(total_time_ms) FROM requests WHERE status = 'completed' AND total_time_ms IS NOT NULL")->fetchColumn();

    $clientCount = (int)$pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    $activeKeys = (int)$pdo->query("SELECT COUNT(*) FROM api_keys WHERE status = 1")->fetchColumn();
    $hourAgo = date('Y-m-d H:i:s', time() - 3600);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE created_at > ?");
    $stmt->execute([$hourAgo]);
    $lastHour = (int)$stmt->fetchColumn();
    $openCircuits = (int)$pdo->query("SELECT COUNT(*) FROM providers WHERE circuit_state = 'open'")->fetchColumn();
    $avgLatency = (float)$pdo->query("SELECT AVG(latency_ms) FROM requests WHERE status = 'completed' AND latency_ms IS NOT NULL")->fetchColumn();

    return [
        'queue' => $stats,
        'queue_breakdown' => $queueBreakdown,
        'providers_load' => $providerLoads,
        'clients' => $clientCount,
        'active_keys' => $activeKeys,
        'last_hour_requests' => $lastHour,
        'concurrency' => QUEUE_CONCURRENCY,
        'batch_size' => QUEUE_BATCH_SIZE,
        'retention_days' => QUEUE_RETENTION_DAYS,
        'open_circuits' => $openCircuits,
        'avg_latency_ms' => round($avgLatency),
        'avg_queue_time_ms' => round($avgQueueTime),
        'avg_total_time_ms' => round($avgTotalTime),
        'workers' => $workerStats,
        'crons' => $crons,
        'ack_enabled' => ACK_ENABLED,
        'health_check_enabled' => true,
        'circuit_breaker_enabled' => true,
        'hmac_security_enabled' => SECURITY_HMAC_ENABLED,
    ];
}

// --- لیست درخواست‌ها ---
function request_list(int $page = 1, int $perPage = 50, string $status = ''): array {
    $offset = ($page - 1) * $perPage;
    $sql = "SELECT r.*, c.name AS client_name, c.code AS client_code FROM requests r LEFT JOIN clients c ON r.client_id = c.id";
    $args = [];
    if ($status) { $sql .= " WHERE r.status = ?"; $args[] = $status; }
    $sql .= " ORDER BY r.id DESC LIMIT ? OFFSET ?";
    $args[] = $perPage; $args[] = $offset;
    $stmt = db()->prepare($sql);
    foreach ($args as $i => $a) $stmt->bindValue($i + 1, $a, is_int($a) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->execute();
    return $stmt->fetchAll();
}

function request_count(string $status = ''): int {
    if ($status) { $stmt = db()->prepare("SELECT COUNT(*) FROM requests WHERE status = ?"); $stmt->execute([$status]); }
    else { $stmt = db()->query("SELECT COUNT(*) FROM requests"); }
    return (int)$stmt->fetchColumn();
}

// پایان core.php

// ================================================================
// جدول‌های جدید v5.0.0
// ================================================================

function db_init_v5(): void {
    $pdo = db();

    // attachments — فایل‌های چندرسانه‌ای
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS attachments (
            id              INT PRIMARY KEY AUTO_INCREMENT,
            file_id         VARCHAR(64) NOT NULL UNIQUE,
            original_name   VARCHAR(500) NOT NULL DEFAULT '',
            stored_path     VARCHAR(500) NOT NULL,
            stored_url      VARCHAR(500) NOT NULL,
            mime_type       VARCHAR(100) NOT NULL,
            file_size       BIGINT NOT NULL DEFAULT 0,
            file_hash       VARCHAR(64) NOT NULL,
            storage_driver  VARCHAR(20) NOT NULL DEFAULT 'local',
            created_at      DATETIME NOT NULL DEFAULT NOW(),
            INDEX idx_attach_file (file_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // provider_metrics — تاریخچه metrics هر Provider
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS provider_metrics (
            id              INT PRIMARY KEY AUTO_INCREMENT,
            provider_id     INT NOT NULL,
            avg_latency_ms  INT NOT NULL DEFAULT 0,
            error_rate      DECIMAL(5,2) NOT NULL DEFAULT 0,
            success_rate    DECIMAL(5,2) NOT NULL DEFAULT 100,
            active_requests INT NOT NULL DEFAULT 0,
            total_requests  INT NOT NULL DEFAULT 0,
            timeout_count   INT NOT NULL DEFAULT 0,
            rate_limit_count INT NOT NULL DEFAULT 0,
            error_500_count INT NOT NULL DEFAULT 0,
            health_score    INT NOT NULL DEFAULT 100,
            recorded_at     DATETIME NOT NULL DEFAULT NOW(),
            FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
            INDEX idx_pm_provider (provider_id, recorded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // provider_key_metrics — تاریخچه metrics هر Key
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS provider_key_metrics (
            id              INT PRIMARY KEY AUTO_INCREMENT,
            key_id          INT NOT NULL,
            avg_latency_ms  INT NOT NULL DEFAULT 0,
            error_rate      DECIMAL(5,2) NOT NULL DEFAULT 0,
            success_rate    DECIMAL(5,2) NOT NULL DEFAULT 100,
            active_requests INT NOT NULL DEFAULT 0,
            total_requests  INT NOT NULL DEFAULT 0,
            state           VARCHAR(20) NOT NULL DEFAULT 'open',
            recorded_at     DATETIME NOT NULL DEFAULT NOW(),
            INDEX idx_pkm_key (key_id, recorded_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // stream_sessions — session‌های استریم
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stream_sessions (
            session_id      VARCHAR(64) PRIMARY KEY,
            request_id      INT NOT NULL,
            status          VARCHAR(20) NOT NULL DEFAULT 'streaming',
            created_at      DATETIME NOT NULL DEFAULT NOW(),
            completed_at    DATETIME NULL,
            INDEX idx_ss_request (request_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // stream_chunks — chunk‌های استریم
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS stream_chunks (
            id              INT PRIMARY KEY AUTO_INCREMENT,
            session_id      VARCHAR(64) NOT NULL,
            content         LONGTEXT NOT NULL,
            is_final        TINYINT NOT NULL DEFAULT 0,
            created_at      DATETIME(3) NOT NULL DEFAULT NOW(3),
            INDEX idx_sc_session (session_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // worker_jobs — job‌های اختصاصی هر worker
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS worker_jobs (
            id              INT PRIMARY KEY AUTO_INCREMENT,
            worker_id       VARCHAR(100) NOT NULL,
            request_id      INT NOT NULL,
            status          VARCHAR(20) NOT NULL DEFAULT 'assigned',
            assigned_at     DATETIME NOT NULL DEFAULT NOW(),
            completed_at    DATETIME NULL,
            INDEX idx_wj_worker (worker_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // locks — قفل‌های توزیع‌شده
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS locks (
            lock_key        VARCHAR(200) PRIMARY KEY,
            lock_holder     VARCHAR(100) NOT NULL,
            locked_at       DATETIME NOT NULL DEFAULT NOW(),
            expires_at      DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}


// ================================================================
// Distributed Lock Manager
// ================================================================

class LockManager {
    private PDO $db;

    public function __construct() { $this->db = db(); }

    /**
     * گرفتن یک قفل.
     * @return bool true اگه قفل گرفته شد
     */
    public function acquire(string $key, string $holder, int $ttlSeconds = 30): bool {
        $expires = date('Y-m-d H:i:s', time() + $ttlSeconds);
        try {
            $this->db->prepare("
                INSERT INTO locks (lock_key, lock_holder, locked_at, expires_at)
                VALUES (?, ?, NOW(), ?)
            ")->execute([$key, $holder, $expires]);
            return true;
        } catch (PDOException $e) {
            // قفل از قبل وجود داره — بررسی انقضا
            $this->cleanup();
            try {
                $this->db->prepare("
                    INSERT INTO locks (lock_key, lock_holder, locked_at, expires_at)
                    VALUES (?, ?, NOW(), ?)
                ")->execute([$key, $holder, $expires]);
                return true;
            } catch (PDOException $e2) {
                return false;
            }
        }
    }

    /**
     * آزاد کردن قفل.
     */
    public function release(string $key, string $holder): bool {
        $stmt = $this->db->prepare("DELETE FROM locks WHERE lock_key = ? AND lock_holder = ?");
        $stmt->execute([$key, $holder]);
        return $stmt->rowCount() > 0;
    }

    /**
     * پاکسازی قفل‌های منقضی.
     */
    public function cleanup(): int {
        return (int)$this->db->exec("DELETE FROM locks WHERE expires_at < NOW()");
    }

    /**
     * بررسی آیا قفل وجود داره.
     */
    public function isLocked(string $key): bool {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM locks WHERE lock_key = ? AND expires_at > NOW()");
        $stmt->execute([$key]);
        return (int)$stmt->fetchColumn() > 0;
    }
}


// ================================================================
// Model State Manager — per-Model circuit breaker
// ================================================================

class ModelStateManager {
    private PDO $db;

    public function __construct() { $this->db = db(); }

    /**
     * بررسی وضعیت یک مدل خاص.
     * @return array ['available' => bool, 'state' => string]
     */
    public function checkModel(int $modelId): array {
        // مدل می‌تونه خاص باشه: مثلاً gpt-4 خراب باشه ولی gpt-3 سالم
        $stmt = $this->db->prepare("SELECT status FROM models WHERE id = ?");
        $stmt->execute([$modelId]);
        $m = $stmt->fetch();
        if (!$m) return ['available' => false, 'state' => 'not_found'];
        return ['available' => (int)$m['status'] === 1, 'state' => (int)$m['status'] === 1 ? 'open' : 'disabled'];
    }

    /**
     * ثبت شکست یک مدل خاص.
     */
    public function recordModelFailure(int $modelId, string $errorType): void {
        // اگه مدل مدام fail می‌کنه، می‌تونه auto-disable بشه
        $stmt = $this->db->prepare("
            INSERT INTO provider_metrics (provider_id, error_rate, success_rate, total_requests, recorded_at)
            SELECT m.provider_id, 100, 0, 1, NOW() FROM models m WHERE m.id = ?
        ");
        $stmt->execute([$modelId]);
    }

    /**
     * ثبت موفقیت یک مدل.
     */
    public function recordModelSuccess(int $modelId, int $latencyMs): void {
        $stmt = $this->db->prepare("
            INSERT INTO provider_metrics (provider_id, avg_latency_ms, success_rate, total_requests, recorded_at)
            SELECT m.provider_id, ?, 100, 1, NOW() FROM models m WHERE m.id = ?
        ");
        $stmt->execute([$latencyMs, $modelId]);
    }
}

