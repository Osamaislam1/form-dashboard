-- Migration 001: Reliability improvements
-- Apply once: mysql -u USER -p DBNAME < 001_reliability.sql
-- MySQL 5.7+ / MariaDB 10.3+

-- 1. Deduplication guard on submissions
--    NULL remote_entry_id values are excluded from unique checks in MySQL,
--    so submissions without an entry ID (e.g. CF7) can still insert freely.
ALTER TABLE submissions
  ADD UNIQUE KEY uk_dedup (site_id, form_id, remote_entry_id(60));

-- 2. Alert rate limiting columns on alert_rules
ALTER TABLE alert_rules
  ADD COLUMN last_alerted_at  DATETIME NULL,
  ADD COLUMN cooldown_seconds INT UNSIGNED NOT NULL DEFAULT 300;
-- 300s = 5 min default for submission alerts
-- Update email_health rules to 6 hours to match previous behaviour:
UPDATE alert_rules SET cooldown_seconds = 21600 WHERE type = 'email_health';

-- 3. Alert log — tracks every alert send attempt (ok or fail)
CREATE TABLE IF NOT EXISTS alert_log (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rule_id       INT UNSIGNED NULL,
    site_id       INT UNSIGNED NULL,
    submission_id BIGINT UNSIGNED NULL,
    sent_to       VARCHAR(500) NOT NULL,
    subject       VARCHAR(500) NOT NULL,
    status        ENUM('ok','fail') NOT NULL,
    error_msg     VARCHAR(500) NULL,
    sent_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_sent_at (sent_at),
    KEY idx_rule    (rule_id),
    KEY idx_site    (site_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Dead letter log — submissions the plugin gave up on after 5 retries
CREATE TABLE IF NOT EXISTS dead_letter_log (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_id         INT UNSIGNED NULL,
    payload_json    LONGTEXT NOT NULL,
    attempts        INT NOT NULL DEFAULT 5,
    first_queued_at DATETIME NOT NULL,
    received_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_site     (site_id),
    KEY idx_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Heartbeat timestamp on sites
ALTER TABLE sites
  ADD COLUMN last_heartbeat_at DATETIME NULL;

-- 6. Extend alert_rules type enum to include new system alert types
ALTER TABLE alert_rules
  MODIFY COLUMN type ENUM('submission','email_health','dead_letter','queue_overflow','site_offline') NOT NULL DEFAULT 'submission';
