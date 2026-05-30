-- Migration 003: track plugin version per site (populated from heartbeat payloads)
ALTER TABLE sites
    ADD COLUMN plugin_version          VARCHAR(20) NULL DEFAULT NULL AFTER last_heartbeat_at,
    ADD COLUMN plugin_version_seen_at  DATETIME    NULL DEFAULT NULL AFTER plugin_version;
