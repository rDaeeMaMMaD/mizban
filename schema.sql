-- ================================================================
-- Mizban (میزبان) — MySQL Schema (Enterprise Architecture)
-- Version: 10.6.1 (open-source seed)
-- ================================================================
-- IMPORTANT (Security):
--   • NO real API keys or credentials are stored in this file.
--   • Providers and models below are public metadata only.
--   • Add API keys from the admin panel after installing — they are
--     stored encrypted (AES-256-CBC) in the `api_keys` table.
--   • The default admin and demo clients are created automatically
--     by core.php (db_init) with hashed secrets from .env settings.

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- ================================================================
-- ۱) Providers — ارائه‌دهنده‌های API
-- ================================================================
CREATE TABLE IF NOT EXISTS `providers` (
    `id`                INT PRIMARY KEY AUTO_INCREMENT,
    `name`              VARCHAR(255) NOT NULL,
    `slug`              VARCHAR(100) NOT NULL UNIQUE,
    `baseurl`           VARCHAR(500) NOT NULL,
    `type`              VARCHAR(20) NOT NULL DEFAULT 'chat',
    `status`            TINYINT NOT NULL DEFAULT 1,
    `sort_order`        INT NOT NULL DEFAULT 0,
    `priority`          INT NOT NULL DEFAULT 100,
    `circuit_state`     VARCHAR(20) NOT NULL DEFAULT 'closed',
    `circuit_opened_at` DATETIME NULL,
    `failure_count`     INT NOT NULL DEFAULT 0,
    `health_score`      INT NOT NULL DEFAULT 100,
    `active_requests`   INT NOT NULL DEFAULT 0,
    `total_requests`    INT NOT NULL DEFAULT 0,
    `total_errors`      INT NOT NULL DEFAULT 0,
    `avg_latency_ms`    INT NOT NULL DEFAULT 0,
    `last_health_check` DATETIME NULL,
    `created_at`        DATETIME NOT NULL DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- ۲) API Keys — کلیدهای API (چند کلید برای هر ارائه‌دهنده)
-- ================================================================
CREATE TABLE IF NOT EXISTS `api_keys` (
    `id`                 INT PRIMARY KEY AUTO_INCREMENT,
    `provider_id`        INT NOT NULL,
    `key_encrypted`      TEXT NOT NULL,
    `label`              VARCHAR(255) NOT NULL DEFAULT '',
    `status`             TINYINT NOT NULL DEFAULT 1,
    `weight`             INT NOT NULL DEFAULT 1,
    `lb_strategy`        VARCHAR(20) NOT NULL DEFAULT 'least_busy',
    `request_count`      INT NOT NULL DEFAULT 0,
    `error_count`        INT NOT NULL DEFAULT 0,
    `consecutive_errors` INT NOT NULL DEFAULT 0,
    `active_requests`    INT NOT NULL DEFAULT 0,
    `avg_latency_ms`     INT NOT NULL DEFAULT 0,
    `last_used_at`       DATETIME NULL,
    `cooldown_until`     DATETIME NULL,
    `created_at`         DATETIME NOT NULL DEFAULT NOW(),
    FOREIGN KEY (`provider_id`) REFERENCES `providers`(`id`) ON DELETE CASCADE,
    INDEX `idx_keys_provider` (`provider_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- ۳) Models — مدل‌های هر ارائه‌دهنده
-- ================================================================
CREATE TABLE IF NOT EXISTS `models` (
    `id`              INT PRIMARY KEY AUTO_INCREMENT,
    `provider_id`     INT NOT NULL,
    `model_id`        VARCHAR(200) NOT NULL,
    `display_name`    VARCHAR(255) NOT NULL,
    `fallback_model`  VARCHAR(200) NULL,
    `modality`        VARCHAR(50) NOT NULL DEFAULT 'text',
    `supports_stream` TINYINT NOT NULL DEFAULT 1,
    `is_default`      TINYINT NOT NULL DEFAULT 0,
    `status`          TINYINT NOT NULL DEFAULT 1,
    `sort_order`      INT NOT NULL DEFAULT 0,
    `created_at`      DATETIME NOT NULL DEFAULT NOW(),
    FOREIGN KEY (`provider_id`) REFERENCES `providers`(`id`) ON DELETE CASCADE,
    INDEX `idx_models_model` (`model_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- ۴) Clients — کلاینت‌ها (رمز هش‌شده و درجه)
-- ================================================================
CREATE TABLE IF NOT EXISTS `clients` (
    `id`          INT PRIMARY KEY AUTO_INCREMENT,
    `name`        VARCHAR(255) NOT NULL,
    `code`        VARCHAR(100) NOT NULL UNIQUE,
    `degree`      INT NOT NULL DEFAULT 5,
    `secret_hash` VARCHAR(255) NOT NULL,
    `status`      TINYINT NOT NULL DEFAULT 1,
    `created_at`  DATETIME NOT NULL DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- ۵) Admins — ادمین‌های سیستم (هش‌شده؛ seed خودکار توسط core.php)
-- ================================================================
CREATE TABLE IF NOT EXISTS `admins` (
    `id`          INT PRIMARY KEY AUTO_INCREMENT,
    `username`    VARCHAR(100) NOT NULL UNIQUE,
    `password`    VARCHAR(255) NOT NULL,
    `name`        VARCHAR(255) NOT NULL DEFAULT 'Admin',
    `status`      TINYINT NOT NULL DEFAULT 1,
    `created_at`  DATETIME NOT NULL DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- ۵) Requests — درخواست‌ها (Multi-Queue)
--    وضعیت‌ها: queued / running / completed / failed / dead / retry / delayed
-- ================================================================
CREATE TABLE IF NOT EXISTS `requests` (
    `id`              INT PRIMARY KEY AUTO_INCREMENT,
    `client_id`       INT NOT NULL,
    `mode`            VARCHAR(20) NOT NULL DEFAULT 'specific',
    `provider_slug`   VARCHAR(100) NULL,
    `model`           VARCHAR(200) NOT NULL,
    `action`          VARCHAR(50) NOT NULL,
    `payload`         LONGTEXT NULL,
    `priority`        INT NOT NULL DEFAULT 5,
    `status`          VARCHAR(20) NOT NULL DEFAULT 'queued',
    `callback_url`    VARCHAR(500) NULL,
    `response`        LONGTEXT NULL,
    `error`           TEXT NULL,
    `error_type`      VARCHAR(50) NULL,
    `attempts`        INT NOT NULL DEFAULT 0,
    `max_attempts`    INT NOT NULL DEFAULT 6,
    `api_key_id`      INT NULL,
    `worker_id`       VARCHAR(100) NULL,
    `fallback_used`   VARCHAR(500) NULL,
    `idempotency_key` VARCHAR(200) NULL,
    `correlation_id`  VARCHAR(100) NULL,
    `queue_position`  INT NULL,
    `latency_ms`      INT NULL,
    `queue_time_ms`   INT NULL,
    `total_time_ms`   INT NULL,
    `stream_mode`     TINYINT NOT NULL DEFAULT 0,
    `scheduled_at`    DATETIME NULL,
    `lease_token`     VARCHAR(64) NULL,
    `lease_until`     DATETIME NULL,
    `created_at`      DATETIME NOT NULL DEFAULT NOW(),
    `locked_at`       DATETIME NULL,
    `running_at`      DATETIME NULL,
    `processed_at`    DATETIME NULL,
    FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`),
    INDEX `idx_requests_status` (`status`, `priority` DESC, `created_at`),
    INDEX `idx_requests_queue` (`status`, `scheduled_at`),
    INDEX `idx_requests_lease` (`status`, `lease_until`),
    INDEX `idx_idempotency` (`idempotency_key`),
    INDEX `idx_correlation` (`correlation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- ۶) Health Checks — تاریخچه سلامت ارائه‌دهنده‌ها
-- ================================================================
CREATE TABLE IF NOT EXISTS `health_checks` (
    `id`          INT PRIMARY KEY AUTO_INCREMENT,
    `provider_id` INT NOT NULL,
    `status`      VARCHAR(20) NOT NULL,
    `latency_ms`  INT NULL,
    `error`       TEXT NULL,
    `checked_at`  DATETIME NOT NULL DEFAULT NOW(),
    FOREIGN KEY (`provider_id`) REFERENCES `providers`(`id`) ON DELETE CASCADE,
    INDEX `idx_health_provider` (`provider_id`, `checked_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- ۷) Worker Status — وضعیت Workerهای فعال
-- ================================================================
CREATE TABLE IF NOT EXISTS `worker_status` (
    `id`                 VARCHAR(100) PRIMARY KEY,
    `hostname`           VARCHAR(255) NOT NULL,
    `pid`                INT NOT NULL,
    `status`             VARCHAR(20) NOT NULL DEFAULT 'idle',
    `current_job`        INT NULL,
    `started_at`         DATETIME NOT NULL DEFAULT NOW(),
    `last_heartbeat`     DATETIME NOT NULL DEFAULT NOW(),
    `jobs_completed`     INT NOT NULL DEFAULT 0,
    `jobs_failed`        INT NOT NULL DEFAULT 0,
    `active_jobs`        INT NOT NULL DEFAULT 0,
    `memory_usage`       INT NOT NULL DEFAULT 0,
    `cpu_usage`          INT NOT NULL DEFAULT 0,
    `heartbeat_interval` INT NOT NULL DEFAULT 5,
    INDEX `idx_worker_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- ۸) Rate Limits — شمارش درخواست (Minute/Hour/Day/Month + auth)
-- ================================================================
CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id`           INT PRIMARY KEY AUTO_INCREMENT,
    `entity_type`  VARCHAR(20) NOT NULL,
    `entity_id`    VARCHAR(100) NOT NULL,
    `period`       VARCHAR(10) NOT NULL,
    `count`        INT NOT NULL DEFAULT 0,
    `window_start` DATETIME NOT NULL,
    UNIQUE KEY `uniq_rate` (`entity_type`, `entity_id`, `period`, `window_start`),
    INDEX `idx_rate_entity` (`entity_type`, `entity_id`, `period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- ۹) Statistics — آمار تجمیعی
-- ================================================================
CREATE TABLE IF NOT EXISTS `statistics` (
    `id`          INT PRIMARY KEY AUTO_INCREMENT,
    `stat_key`    VARCHAR(100) NOT NULL,
    `stat_value`  BIGINT NOT NULL DEFAULT 0,
    `period`      VARCHAR(20) NOT NULL,
    `recorded_at` DATETIME NOT NULL DEFAULT NOW(),
    UNIQUE KEY `uniq_stat` (`stat_key`, `period`, `recorded_at`),
    INDEX `idx_stat_key` (`stat_key`, `period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- ۱۰) Settings — تنظیمات پویا (ConfigManager)
-- ================================================================
CREATE TABLE IF NOT EXISTS `settings` (
    `skey`       VARCHAR(100) PRIMARY KEY,
    `svalue`     TEXT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- ۱۱) Nonces — جلوگیری از Replay Attack
-- ================================================================
CREATE TABLE IF NOT EXISTS `nonces` (
    `nonce`       VARCHAR(100) PRIMARY KEY,
    `client_code` VARCHAR(100) NOT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT NOW(),
    INDEX `idx_nonce_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- ۱۲) Cron Status — وضعیت اجرای Cron ها
-- ================================================================
CREATE TABLE IF NOT EXISTS `cron_status` (
    `cron_name`         VARCHAR(50) PRIMARY KEY,
    `last_run`          DATETIME NULL,
    `last_duration_ms`  INT NULL,
    `last_result`       VARCHAR(20) NULL,
    `run_count`         INT NOT NULL DEFAULT 0,
    `error_count`       INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ================================================================
-- ۱۳) Logs — لاگ‌های سیستم
-- ================================================================
CREATE TABLE IF NOT EXISTS `logs` (
    `id`         INT PRIMARY KEY AUTO_INCREMENT,
    `level`      VARCHAR(20) NOT NULL DEFAULT 'info',
    `message`    TEXT NOT NULL,
    `context`    TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT NOW(),
    INDEX `idx_logs_level` (`level`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ================================================================
-- داده‌های اولیه (Seed Data) — فقط ابرداده عمومی، بدون کلید
-- ================================================================

-- ===== Providers (public endpoints) =====
INSERT IGNORE INTO `providers` (`name`, `slug`, `baseurl`, `type`, `status`, `sort_order`, `priority`) VALUES
('زدی‌ای (Z.ai GLM)', 'zai', 'https://api.z.ai/api/paas/v4', 'chat', 1, 1, 1),
('رپیدای‌پی (RapidAPI)', 'rapidapi', 'https://rapidapi.com', 'rapidapi', 1, 2, 2),
('دیپ‌سیک (DeepSeek)', 'deepseek', 'https://api.deepseek.com', 'chat', 1, 3, 3),
('ان‌ویدیا (NVIDIA NIM)', 'nvidia', 'https://integrate.api.nvidia.com/v1', 'chat', 1, 4, 4),
('اپن‌روتر (OpenRouter)', 'openrouter', 'https://openrouter.ai/api/v1', 'chat', 1, 5, 5),
('جمینای (Gemini)', 'gemini', 'https://generativelanguage.googleapis.com/v1beta', 'gemini', 1, 6, 6),
('گپ جی‌پی‌تی (GapGPT)', 'gapgpt', 'https://api.gapgpt.app/v1', 'gapgpt', 1, 7, 7);

-- ===== Models (per provider) =====
-- Z.ai (provider_id = 1)
INSERT IGNORE INTO `models` (`provider_id`, `model_id`, `display_name`, `fallback_model`, `modality`, `is_default`, `status`) VALUES
(1, 'glm-5.2', 'GLM-5.2', NULL, 'chat', 1, 1),
(1, 'glm-5.1', 'GLM-5.1', 'glm-5.2', 'chat', 0, 1),
(1, 'glm-5', 'GLM-5', 'glm-5.2', 'chat', 0, 1),
(1, 'glm-5-turbo', 'GLM-5 Turbo', 'glm-5.2', 'chat', 0, 1),
(1, 'glm-4.7', 'GLM-4.7', 'glm-5.2', 'chat', 0, 1),
(1, 'glm-4.6', 'GLM-4.6', 'glm-5.2', 'chat', 0, 1),
(1, 'glm-4.5', 'GLM-4.5', 'glm-5.2', 'chat', 0, 1),
(1, 'glm-4-plus', 'GLM-4 Plus', 'glm-5.2', 'chat', 0, 1),
(1, 'glm-4-32b-0414-128k', 'GLM-4 32B 0414 128K', 'glm-5.2', 'chat', 0, 1),
(1, 'glm-4.5-air', 'GLM-4.5 Air', 'glm-5.2', 'chat', 0, 1),
(1, 'glm-4.5-airx', 'GLM-4.5 AirX', 'glm-5.2', 'chat', 0, 1),
(1, 'glm-4.5-flash', 'GLM-4.5 Flash', 'glm-5.2', 'chat', 0, 1),
(1, 'glm-4.7-flash', 'GLM-4.7 Flash', 'glm-5.2', 'chat', 0, 1),
(1, 'glm-4.7-flashx', 'GLM-4.7 FlashX', 'glm-5.2', 'chat', 0, 1),
(1, 'glm-4.6v', 'GLM-4.6V (Vision)', 'glm-5.2', 'vision', 0, 1),
(1, 'glm-4.6v-flashx', 'GLM-4.6V FlashX (Vision)', 'glm-5.2', 'vision', 0, 1),
(1, 'glm-4.5v', 'GLM-4.5V (Vision)', 'glm-5.2', 'vision', 0, 1),
(1, 'glm-5v-turbo', 'GLM-5V Turbo (Vision)', 'glm-5.2', 'vision', 0, 1),
(1, 'glm-ocr', 'GLM OCR', 'glm-5.2', 'ocr', 0, 1),
(1, 'autoglm-phone-multilingual', 'AutoGLM Phone Multilingual', 'glm-5.2', 'agent', 0, 1),
(1, 'web-reader', 'Web Reader (Tool)', 'glm-5.2', 'tool', 0, 1),
(1, 'search-prime', 'Search Prime (Tool)', 'glm-5.2', 'tool', 0, 1);

-- RapidAPI (provider_id = 2)
INSERT IGNORE INTO `models` (`provider_id`, `model_id`, `display_name`, `fallback_model`, `modality`, `is_default`, `status`) VALUES
(2, 'chatgpt-4-2', 'ChatGPT 4.2', NULL, 'chat', 1, 1),
(2, 'deepseek-reasoner', 'DeepSeek Reasoner (RapidAPI)', 'chatgpt-4-2', 'chat', 0, 1),
(2, 'claude-3-7-sonnet', 'Claude 3.7 Sonnet (RapidAPI)', 'chatgpt-4-2', 'chat', 0, 1);

-- DeepSeek (provider_id = 3)
INSERT IGNORE INTO `models` (`provider_id`, `model_id`, `display_name`, `fallback_model`, `modality`, `is_default`, `status`) VALUES
(3, 'deepseek-v4-flash', 'DeepSeek V4 Flash', NULL, 'chat', 1, 1),
(3, 'deepseek-chat', 'DeepSeek Chat', 'deepseek-v4-flash', 'chat', 0, 1),
(3, 'deepseek-v4-pro', 'DeepSeek V4 Pro', 'deepseek-v4-flash', 'chat', 0, 1);

-- NVIDIA (provider_id = 4)
INSERT IGNORE INTO `models` (`provider_id`, `model_id`, `display_name`, `fallback_model`, `modality`, `is_default`, `status`) VALUES
(4, 'z-ai/glm-5.2', 'Z.ai GLM-5.2 (NVIDIA)', NULL, 'chat', 1, 1),
(4, 'minimaxai/minimax-m3', 'MiniMax M3 (NVIDIA)', 'z-ai/glm-5.2', 'chat', 0, 1),
(4, 'minimaxai/minimax-m2.7', 'MiniMax M2.7 (NVIDIA)', 'z-ai/glm-5.2', 'chat', 0, 1),
(4, 'deepseek-ai/deepseek-v4-flash', 'DeepSeek V4 Flash (NVIDIA)', 'z-ai/glm-5.2', 'chat', 0, 1),
(4, 'deepseek-ai/deepseek-v4-pro', 'DeepSeek V4 Pro (NVIDIA)', 'z-ai/glm-5.2', 'chat', 0, 1);

-- OpenRouter (provider_id = 5)
INSERT IGNORE INTO `models` (`provider_id`, `model_id`, `display_name`, `fallback_model`, `modality`, `is_default`, `status`) VALUES
(5, 'tencent/hy3:free', 'Tencent HY3 (Free)', NULL, 'chat', 1, 1),
(5, 'poolside/laguna-xs-2.1:free', 'Poolside Laguna XS 2.1 (Free)', 'tencent/hy3:free', 'chat', 0, 1);

-- Gemini (provider_id = 6)
INSERT IGNORE INTO `models` (`provider_id`, `model_id`, `display_name`, `fallback_model`, `modality`, `is_default`, `status`) VALUES
(6, 'gemini-2.0-flash', 'Gemini 2.0 Flash', NULL, 'chat', 1, 1),
(6, 'gemini-2.5-flash', 'Gemini 2.5 Flash', 'gemini-2.0-flash', 'chat', 0, 1),
(6, 'gemini-1.5-flash', 'Gemini 1.5 Flash', 'gemini-2.0-flash', 'chat', 0, 1);

-- GapGPT (provider_id = 7)
INSERT IGNORE INTO `models` (`provider_id`, `model_id`, `display_name`, `fallback_model`, `modality`, `is_default`, `status`) VALUES
(7, 'gpt-4o-mini', 'GPT-4o Mini', NULL, 'chat,vision', 1, 1),
(7, 'gapgpt-qwen-3.6', 'GapGPT Qwen 3.6', 'gpt-4o-mini', 'chat', 0, 1),
(7, 'gapgpt-qwen-3.6-thinking', 'GapGPT Qwen 3.6 Thinking', 'gpt-4o-mini', 'chat', 0, 1);

-- NOTE: No clients/admins/api_keys seeds here on purpose.
-- core.php (db_init) creates the default admin (from MIZBAN_ADMIN_USER /
-- MIZBAN_ADMIN_PASSWORD) and demo clients (hashed) on first run.

-- ===== Settings (تنظیمات پیش‌فرض) =====
INSERT IGNORE INTO `settings` (`skey`, `svalue`) VALUES
('queue.sync_process', '1'),
('queue.concurrency', '10'),
('queue.batch_size', '50'),
('queue.retry_max', '2'),
('queue.retention_days', '30'),
('cb.failure_threshold', '10'),
('cb.cooldown_seconds', '300'),
('ack.enabled', '1'),
('security.hmac_enabled', '0'),
('health.check_interval', '60')
ON DUPLICATE KEY UPDATE `svalue` = VALUES(`svalue`);

-- ================================================================
-- جداول الحاقی (attachments / metrics / stream / locks / jobs)
-- ================================================================

-- ===== attachments =====
CREATE TABLE IF NOT EXISTS `attachments` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `file_id` VARCHAR(64) NOT NULL UNIQUE,
    `original_name` VARCHAR(500) NOT NULL DEFAULT '',
    `stored_path` VARCHAR(500) NOT NULL,
    `stored_url` VARCHAR(500) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `file_size` BIGINT NOT NULL DEFAULT 0,
    `file_hash` VARCHAR(64) NOT NULL,
    `storage_driver` VARCHAR(20) NOT NULL DEFAULT 'local',
    `created_at` DATETIME NOT NULL DEFAULT NOW(),
    INDEX `idx_attach_file` (`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== provider_metrics =====
CREATE TABLE IF NOT EXISTS `provider_metrics` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `provider_id` INT NOT NULL,
    `avg_latency_ms` INT NOT NULL DEFAULT 0,
    `error_rate` DECIMAL(5,2) NOT NULL DEFAULT 0,
    `success_rate` DECIMAL(5,2) NOT NULL DEFAULT 100,
    `active_requests` INT NOT NULL DEFAULT 0,
    `total_requests` INT NOT NULL DEFAULT 0,
    `timeout_count` INT NOT NULL DEFAULT 0,
    `rate_limit_count` INT NOT NULL DEFAULT 0,
    `error_500_count` INT NOT NULL DEFAULT 0,
    `health_score` INT NOT NULL DEFAULT 100,
    `recorded_at` DATETIME NOT NULL DEFAULT NOW(),
    FOREIGN KEY (`provider_id`) REFERENCES `providers`(`id`) ON DELETE CASCADE,
    INDEX `idx_pm_provider` (`provider_id`, `recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== provider_key_metrics =====
CREATE TABLE IF NOT EXISTS `provider_key_metrics` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `key_id` INT NOT NULL,
    `avg_latency_ms` INT NOT NULL DEFAULT 0,
    `error_rate` DECIMAL(5,2) NOT NULL DEFAULT 0,
    `success_rate` DECIMAL(5,2) NOT NULL DEFAULT 100,
    `active_requests` INT NOT NULL DEFAULT 0,
    `total_requests` INT NOT NULL DEFAULT 0,
    `state` VARCHAR(20) NOT NULL DEFAULT 'open',
    `recorded_at` DATETIME NOT NULL DEFAULT NOW(),
    INDEX `idx_pkm_key` (`key_id`, `recorded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== stream_sessions =====
CREATE TABLE IF NOT EXISTS `stream_sessions` (
    `session_id` VARCHAR(64) PRIMARY KEY,
    `request_id` INT NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'streaming',
    `created_at` DATETIME NOT NULL DEFAULT NOW(),
    `completed_at` DATETIME NULL,
    INDEX `idx_ss_request` (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== stream_chunks =====
CREATE TABLE IF NOT EXISTS `stream_chunks` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `session_id` VARCHAR(64) NOT NULL,
    `content` LONGTEXT NOT NULL,
    `is_final` TINYINT NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT NOW(3),
    INDEX `idx_sc_session` (`session_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== worker_jobs =====
CREATE TABLE IF NOT EXISTS `worker_jobs` (
    `id` INT PRIMARY KEY AUTO_INCREMENT,
    `worker_id` VARCHAR(100) NOT NULL,
    `request_id` INT NOT NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'assigned',
    `assigned_at` DATETIME NOT NULL DEFAULT NOW(),
    `completed_at` DATETIME NULL,
    INDEX `idx_wj_worker` (`worker_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== locks (Distributed Lock Manager) =====
CREATE TABLE IF NOT EXISTS `locks` (
    `lock_key` VARCHAR(200) PRIMARY KEY,
    `lock_holder` VARCHAR(100) NOT NULL,
    `locked_at` DATETIME NOT NULL DEFAULT NOW(),
    `expires_at` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===== migrations (tracking) =====
CREATE TABLE IF NOT EXISTS `migrations` (
    `id`          INT PRIMARY KEY AUTO_INCREMENT,
    `name`        VARCHAR(200) NOT NULL UNIQUE,
    `executed_at` DATETIME NOT NULL DEFAULT NOW()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- پایان schema.sql (open-source seed — بدون کلید واقعی)
