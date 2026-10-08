-- Run this migration once in the Fixie DRMS database before deploying the
-- replacement PHP files. Take a database backup first.

-- Add a date for a GM's Retain / Defer decision. It is intentionally separate
-- from the original retention-policy date so the policy calculation remains
-- auditable and a deferral does not rewrite the legal retention period.
SET @has_disposition_review_due_at := (
    SELECT COUNT(*)
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'documents'
       AND COLUMN_NAME = 'disposition_review_due_at'
);
SET @add_disposition_review_due_at_sql := IF(
    @has_disposition_review_due_at = 0,
    'ALTER TABLE documents ADD COLUMN disposition_review_due_at DATETIME NULL DEFAULT NULL AFTER disposition_status',
    'SELECT 1'
);
PREPARE add_disposition_review_due_at_stmt FROM @add_disposition_review_due_at_sql;
EXECUTE add_disposition_review_due_at_stmt;
DEALLOCATE PREPARE add_disposition_review_due_at_stmt;

-- Requesters must be able to open the queue to submit and track their request.
-- Actual rows remain limited by the page's per-folder / per-file access checks.
DELETE up
  FROM user_permissions up
  JOIN users u ON u.user_id = up.user_id
 WHERE up.permission_name = 'can_view_disposition'
   AND u.role NOT IN ('GM', 'President', 'Finance', 'Procurement', 'Supply Chain', 'Sales Staff');

INSERT IGNORE INTO user_permissions (user_id, permission_name)
SELECT u.user_id, 'can_view_disposition'
  FROM users u
 WHERE u.role IN ('GM', 'President', 'Finance', 'Procurement', 'Supply Chain', 'Sales Staff')
   AND u.status = 'Active';

-- Only active General Managers receive review authority. System Admin and
-- President are removed from this capability even if they had an old grant.
DELETE up
  FROM user_permissions up
  JOIN users u ON u.user_id = up.user_id
 WHERE up.permission_name = 'can_approve_disposition'
   AND (u.role <> 'GM' OR u.status <> 'Active');

INSERT IGNORE INTO user_permissions (user_id, permission_name)
SELECT u.user_id, 'can_approve_disposition'
  FROM users u
 WHERE u.role = 'GM'
   AND u.status = 'Active';

-- Request preparation is non-destructive. Grant it to active operational
-- staff who can prepare records; the GM remains the independent reviewer.
DELETE up
  FROM user_permissions up
  JOIN users u ON u.user_id = up.user_id
 WHERE up.permission_name = 'can_manage_disposition'
   AND u.role NOT IN ('Finance', 'Procurement', 'Supply Chain', 'Sales Staff');

INSERT IGNORE INTO user_permissions (user_id, permission_name)
SELECT u.user_id, 'can_manage_disposition'
  FROM users u
 WHERE u.role IN ('Finance', 'Procurement', 'Supply Chain', 'Sales Staff')
   AND u.status = 'Active';

-- Execution remains GM-only; remove any stale grant from other roles and make
-- sure active GMs retain the capability defined by the current policy.
DELETE up
  FROM user_permissions up
  JOIN users u ON u.user_id = up.user_id
 WHERE up.permission_name = 'can_execute_disposition'
   AND (u.role <> 'GM' OR u.status <> 'Active');

INSERT IGNORE INTO user_permissions (user_id, permission_name)
SELECT u.user_id, 'can_execute_disposition'
  FROM users u
 WHERE u.role = 'GM'
   AND u.status = 'Active';
