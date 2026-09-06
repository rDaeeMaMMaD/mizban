<?php
/**
 * میزبان - Database Migration
 * ------------------------------------------------------------------
 * این فایل فقط یک بار باید اجرا بشه (یا وقتی نسخه جدید آپلود می‌شه).
 * db_init() فقط جداول رو می‌سازه. migrate.php تغییرات schema رو اعمال می‌کنه.
 *
 * استفاده:
 *   php migrate.php              — CLI
 *   مرورگر: migrate.php          — HTTP (بعد حذف کنید)
 *
 * یا: index.php?action=migrate   — از پنل ادمین
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/core.php';

/**
 * اجرای همه migration ها.
 * هر migration فقط یک بار اجرا می‌شه (با جدول migrations tracking).
 */
function run_migrations(): array {
    $pdo = db();

    // جدول tracking migrations
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS migrations (
            id          INT PRIMARY KEY AUTO_INCREMENT,
            name        VARCHAR(200) NOT NULL UNIQUE,
            executed_at DATETIME NOT NULL DEFAULT NOW()
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $results = [];

    // ===== Migration 001: اضافه کردن ستون‌های providers =====
    $results[] = run_migration('001_provider_columns', function() use ($pdo) {
        safe_alter("ALTER TABLE providers ADD COLUMN priority INT NOT NULL DEFAULT 100");
        safe_alter("ALTER TABLE providers ADD COLUMN circuit_state VARCHAR(20) NOT NULL DEFAULT 'closed'");
        safe_alter("ALTER TABLE providers ADD COLUMN circuit_opened_at DATETIME NULL");
        safe_alter("ALTER TABLE providers ADD COLUMN failure_count INT NOT NULL DEFAULT 0");
        safe_alter("ALTER TABLE providers ADD COLUMN health_score INT NOT NULL DEFAULT 100");
        safe_alter("ALTER TABLE providers ADD COLUMN active_requests INT NOT NULL DEFAULT 0");
        safe_alter("ALTER TABLE providers ADD COLUMN total_requests INT NOT NULL DEFAULT 0");
        safe_alter("ALTER TABLE providers ADD COLUMN total_errors INT NOT NULL DEFAULT 0");
        safe_alter("ALTER TABLE providers ADD COLUMN avg_latency_ms INT NOT NULL DEFAULT 0");
        safe_alter("ALTER TABLE providers ADD COLUMN last_health_check DATETIME NULL");
    });

    // ===== Migration 002: اضافه کردن ستون‌های api_keys =====
    $results[] = run_migration('002_apikey_columns', function() use ($pdo) {
        safe_alter("ALTER TABLE api_keys ADD COLUMN weight INT NOT NULL DEFAULT 1");
        safe_alter("ALTER TABLE api_keys ADD COLUMN lb_strategy VARCHAR(20) NOT NULL DEFAULT 'least_busy'");
        safe_alter("ALTER TABLE api_keys ADD COLUMN active_requests INT NOT NULL DEFAULT 0");
        safe_alter("ALTER TABLE api_keys ADD COLUMN avg_latency_ms INT NOT NULL DEFAULT 0");
    });

    // ===== Migration 003: اضافه کردن ستون‌های models =====
    $results[] = run_migration('003_model_columns', function() use ($pdo) {
        safe_alter("ALTER TABLE models ADD COLUMN modality VARCHAR(50) NOT NULL DEFAULT 'text'");
        safe_alter("ALTER TABLE models ADD COLUMN supports_stream TINYINT NOT NULL DEFAULT 1");
    });

    // ===== Migration 004: اضافه کردن ستون‌های requests =====
    $results[] = run_migration('004_request_columns', function() use ($pdo) {
        safe_alter("ALTER TABLE requests ADD COLUMN mode VARCHAR(20) NOT NULL DEFAULT 'specific'");
        safe_alter("ALTER TABLE requests ADD COLUMN error_type VARCHAR(50) NULL");
        safe_alter("ALTER TABLE requests ADD COLUMN max_attempts INT NOT NULL DEFAULT 5");
        safe_alter("ALTER TABLE requests ADD COLUMN worker_id VARCHAR(100) NULL");
        safe_alter("ALTER TABLE requests ADD COLUMN queue_position INT NULL");
        safe_alter("ALTER TABLE requests ADD COLUMN queue_time_ms INT NULL");
        safe_alter("ALTER TABLE requests ADD COLUMN total_time_ms INT NULL");
        safe_alter("ALTER TABLE requests ADD COLUMN stream_mode TINYINT NOT NULL DEFAULT 0");
        safe_alter("ALTER TABLE requests ADD COLUMN scheduled_at DATETIME NULL");
        safe_alter("ALTER TABLE requests ADD COLUMN locked_at DATETIME NULL");
        safe_alter("ALTER TABLE requests ADD COLUMN running_at DATETIME NULL");
        safe_alter("ALTER TABLE requests ADD COLUMN idempotency_key VARCHAR(200) NULL");
        safe_alter("ALTER TABLE requests ADD COLUMN correlation_id VARCHAR(100) NULL");
        safe_alter("ALTER TABLE requests ADD COLUMN latency_ms INT NULL");
        try {
            $pdo->exec("CREATE INDEX idx_idempotency ON requests(idempotency_key)");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("CREATE INDEX idx_correlation ON requests(correlation_id)");
        } catch (PDOException $e) {}
    });

    // ===== Migration 005: جدول admins =====
    $results[] = run_migration('005_admins_table', function() use ($pdo) {
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
        // seed ادمین پیش‌فرض (admin / admin123 — قابل‌تغییر از .env)
        $count = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
        if ($count == 0) {
            $adminUser = (defined('MIZBAN_ADMIN_USER') && MIZBAN_ADMIN_USER !== '') ? MIZBAN_ADMIN_USER : 'admin';
            $adminPass = (defined('MIZBAN_ADMIN_PASSWORD') && MIZBAN_ADMIN_PASSWORD !== '') ? MIZBAN_ADMIN_PASSWORD : 'admin123';
            $pdo->prepare("INSERT INTO admins (username, password, name, status) VALUES (?, ?, ?, 1)")
                ->execute([$adminUser, password_hash($adminPass, PASSWORD_DEFAULT), 'Administrator']);
        }
    });

    // ===== Migration 006: جداول سیستم =====
    $results[] = run_migration('006_system_tables', function() use ($pdo) {
        $tables = [
            "CREATE TABLE IF NOT EXISTS health_checks (
                id INT PRIMARY KEY AUTO_INCREMENT, provider_id INT NOT NULL,
                status VARCHAR(20) NOT NULL, latency_ms INT NULL, error TEXT NULL,
                checked_at DATETIME NOT NULL DEFAULT NOW(),
                FOREIGN KEY (provider_id) REFERENCES providers(id) ON DELETE CASCADE,
                INDEX idx_health_provider (provider_id, checked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            "CREATE TABLE IF NOT EXISTS worker_status (
                id VARCHAR(100) PRIMARY KEY, hostname VARCHAR(255) NOT NULL, pid INT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'idle', current_job INT NULL,
                started_at DATETIME NOT NULL DEFAULT NOW(),
                last_heartbeat DATETIME NOT NULL DEFAULT NOW(),
                jobs_completed INT NOT NULL DEFAULT 0, jobs_failed INT NOT NULL DEFAULT 0,
                INDEX idx_worker_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            "CREATE TABLE IF NOT EXISTS rate_limits (
                id INT PRIMARY KEY AUTO_INCREMENT,
                entity_type VARCHAR(20) NOT NULL, entity_id VARCHAR(100) NOT NULL,
                period VARCHAR(10) NOT NULL, count INT NOT NULL DEFAULT 0,
                window_start DATETIME NOT NULL,
                UNIQUE KEY uniq_rate (entity_type, entity_id, period, window_start),
                INDEX idx_rate_entity (entity_type, entity_id, period)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            "CREATE TABLE IF NOT EXISTS statistics (
                id INT PRIMARY KEY AUTO_INCREMENT,
                stat_key VARCHAR(100) NOT NULL, stat_value BIGINT NOT NULL DEFAULT 0,
                period VARCHAR(20) NOT NULL, recorded_at DATETIME NOT NULL DEFAULT NOW(),
                UNIQUE KEY uniq_stat (stat_key, period, recorded_at),
                INDEX idx_stat_key (stat_key, period)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            "CREATE TABLE IF NOT EXISTS settings (
                skey VARCHAR(100) PRIMARY KEY, svalue TEXT NULL,
                updated_at DATETIME NOT NULL DEFAULT NOW()
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            "CREATE TABLE IF NOT EXISTS nonces (
                nonce VARCHAR(100) PRIMARY KEY, client_code VARCHAR(100) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT NOW(),
                INDEX idx_nonce_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            "CREATE TABLE IF NOT EXISTS cron_status (
                cron_name VARCHAR(50) PRIMARY KEY, last_run DATETIME NULL,
                last_duration_ms INT NULL, last_result VARCHAR(20) NULL,
                run_count INT NOT NULL DEFAULT 0, error_count INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        ];
        foreach ($tables as $sql) {
            try { $pdo->exec($sql); } catch (PDOException $e) {}
        }
    });

    // ===== Migration 007: جدول logs و migrations (اگه نبودن) =====
    $results[] = run_migration('007_logs_migrations_tables', function() use ($pdo) {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS logs (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    level VARCHAR(20) NOT NULL DEFAULT 'info',
                    message TEXT NOT NULL,
                    context TEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT NOW(),
                    INDEX idx_logs_level (level, created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        } catch (PDOException $e) {}
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS migrations (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    name VARCHAR(200) NOT NULL UNIQUE,
                    executed_at DATETIME NOT NULL DEFAULT NOW()
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
        } catch (PDOException $e) {}
    });

    // ===== Migration 008: Lease System (Enterprise) =====
    // ستون‌های lease برای ownership قابل تمدید job — جلوگیری از دو worker
    // همزمان روی یک job + بازیابی خودکار از workerهای crash شده.
    $results[] = run_migration('008_lease_system', function() use ($pdo) {
        safe_alter("ALTER TABLE requests ADD COLUMN lease_token VARCHAR(64) NULL");
        safe_alter("ALTER TABLE requests ADD COLUMN lease_until DATETIME NULL");

        // بهبود worker_status با metricهای بیشتر
        safe_alter("ALTER TABLE worker_status ADD COLUMN active_jobs INT NOT NULL DEFAULT 0");
        safe_alter("ALTER TABLE worker_status ADD COLUMN memory_usage INT NOT NULL DEFAULT 0");
        safe_alter("ALTER TABLE worker_status ADD COLUMN cpu_usage INT NOT NULL DEFAULT 0");
        safe_alter("ALTER TABLE worker_status ADD COLUMN heartbeat_interval INT NOT NULL DEFAULT 5");

        // ایندکس برای recovery سریع leaseهای منقضی
        try {
            $pdo->exec("CREATE INDEX idx_requests_lease ON requests(status, lease_until)");
        } catch (PDOException $e) {}
    });

    return $results;
}

/**
 * اجرای یک migration (اگه قبلاً اجرا نشده).
 */
function run_migration(string $name, callable $fn): array {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM migrations WHERE name = ?");
    $stmt->execute([$name]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['name' => $name, 'status' => 'skipped'];
    }
    try {
        $fn();
        $pdo->prepare("INSERT INTO migrations (name) VALUES (?)")->execute([$name]);
        return ['name' => $name, 'status' => 'ok'];
    } catch (Exception $e) {
        return ['name' => $name, 'status' => 'error', 'error' => $e->getMessage()];
    }
}

// ===== اجرای migrations =====
db_init(); // فقط CREATE TABLE IF NOT EXISTS (بدون ALTER)

if (php_sapi_name() === 'cli') {
    echo "=== Mizban Migration ===\n";
    $results = run_migrations();
    foreach ($results as $r) {
        echo "  {$r['name']}: {$r['status']}" . (isset($r['error']) ? " ({$r['error']})" : "") . "\n";
    }
    echo "=== Done ===\n";
} else {
    // اجرا از HTTP فقط برای ادمین لاگین‌شده یا با توکن cron مجاز است
    header('Content-Type: application/json; charset=utf-8');
    $migrateAuthorized = false;
    if (function_exists('secure_session_start')) {
        if (session_status() === PHP_SESSION_NONE) {
            secure_session_start(defined('ADMIN_SESSION_NAME') ? ADMIN_SESSION_NAME : null);
        }
        $migrateAuthorized = !empty($_SESSION['mizban_admin_user']) && (($_SESSION['mizban_admin_exp'] ?? 0) > time());
    }
    if (!$migrateAuthorized) {
        $token = (string)($_GET['token'] ?? '');
        $migrateAuthorized = $token !== '' && function_exists('cron_token_ok')
            && cron_token_ok($token);
    }
    if (!$migrateAuthorized) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Forbidden']);
        exit;
    }
    $results = run_migrations();
    echo json_encode(['ok' => true, 'version' => HOST_VERSION, 'migrations' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

// پایان migrate.php
