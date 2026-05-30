-- Migration 002: Add resync_requested_at to sites table
-- Allows the dashboard to signal a WP site to re-run bulk sync.
-- The WP plugin polls sync-status.php on each heartbeat and clears this
-- by sending type=resync_complete to ingest.php after syncing.

ALTER TABLE sites
    ADD COLUMN resync_requested_at DATETIME NULL DEFAULT NULL
    AFTER last_heartbeat_at;
