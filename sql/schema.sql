-- Form Dashboard schema
-- MySQL 5.7+ / MariaDB 10.3+

CREATE TABLE IF NOT EXISTS sites (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(190) NOT NULL,
    url             VARCHAR(255) NOT NULL,
    api_key         CHAR(64) NOT NULL,           -- shown once, used by WP plugin
    secret_hash     CHAR(64) NOT NULL,           -- HMAC secret (sha256 hex)
    status          ENUM('active','paused') NOT NULL DEFAULT 'active',
    email_status    ENUM('unknown','ok','fail') NOT NULL DEFAULT 'unknown',
    email_checked_at DATETIME NULL,
    email_error     VARCHAR(500) NULL,
    last_seen_at    DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_api_key (api_key),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS forms (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id         INT UNSIGNED NOT NULL,
    plugin          VARCHAR(40) NOT NULL,        -- forminator, cf7, gravity, wpforms, fluent, elementor
    remote_form_id  VARCHAR(120) NOT NULL,       -- the form's ID on the WP site
    title           VARCHAR(255) NOT NULL,
    submission_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_submission_at DATETIME NULL,
    first_seen_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_site_plugin_form (site_id, plugin, remote_form_id),
    KEY idx_site (site_id),
    CONSTRAINT fk_forms_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS submissions (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id         INT UNSIGNED NOT NULL,
    form_id         INT UNSIGNED NOT NULL,
    remote_entry_id VARCHAR(120) NULL,           -- entry ID on the source site if available
    payload_json    LONGTEXT NOT NULL,           -- field => value pairs as JSON
    summary         VARCHAR(500) NULL,           -- name/email extract for list view
    email           VARCHAR(255) NULL,
    name            VARCHAR(255) NULL,
    ip_address      VARCHAR(45) NULL,
    user_agent      VARCHAR(500) NULL,
    submitted_at    DATETIME NOT NULL,
    received_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_site_submitted (site_id, submitted_at),
    KEY idx_form_submitted (form_id, submitted_at),
    KEY idx_email (email),
    KEY idx_received (received_at),
    CONSTRAINT fk_sub_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_sub_form FOREIGN KEY (form_id) REFERENCES forms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(190) NOT NULL,
    password_hash   VARCHAR(255) NOT NULL,
    name            VARCHAR(120) NOT NULL,
    role            ENUM('admin','viewer') NOT NULL DEFAULT 'admin',
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alert_rules (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(190) NOT NULL,
    type            ENUM('submission','email_health') NOT NULL DEFAULT 'submission',
    site_id         INT UNSIGNED NULL,           -- NULL = any site
    form_id         INT UNSIGNED NULL,           -- NULL = any form on the site
    notify_emails   TEXT NOT NULL,               -- comma-separated
    enabled         TINYINT(1) NOT NULL DEFAULT 1,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_site_form (site_id, form_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id         INT UNSIGNED NULL,
    status          ENUM('ok','rejected','error') NOT NULL,
    message         VARCHAR(500) NULL,
    ip              VARCHAR(45) NULL,
    received_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_received (received_at),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_health_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id         INT UNSIGNED NOT NULL,
    status          ENUM('ok','fail') NOT NULL,
    error_msg       VARCHAR(500) NULL,
    mailer          VARCHAR(100) NULL,
    checked_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_site_checked (site_id, checked_at),
    KEY idx_status (status),
    CONSTRAINT fk_ehl_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
