USE `fixie_drms`;

-- Phase 5M5B adds read-performance indexes only. No audit row is changed,
-- archived, or deleted by this migration.
CREATE INDEX IF NOT EXISTS `idx_audit_timestamp_log_id`
    ON `audit_logs` (`timestamp`, `log_id`);

CREATE INDEX IF NOT EXISTS `idx_audit_action_timestamp_log`
    ON `audit_logs` (`action_type`, `timestamp`, `log_id`);

CREATE INDEX IF NOT EXISTS `idx_audit_user_timestamp_log`
    ON `audit_logs` (`user_id`, `timestamp`, `log_id`);

ANALYZE TABLE `audit_logs`;
