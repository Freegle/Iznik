-- Idempotent production SQL for 2026_09_26_000001_fix_users_review_column_types.php
--
-- Fixes a type bug in 2026_09_20_000001_remove_group_model: users.reviewrequestedat,
-- reviewreason and reviewedat were added as BIGINT UNSIGNED NULL, but the legacy
-- memberships columns they replace are timestamp/string/timestamp, and application
-- code (user/user.go CheckLocationChangeVelocity, session/session.go spammembers)
-- already treats them that way. Safe to run more than once.

ALTER TABLE users
  MODIFY COLUMN reviewrequestedat TIMESTAMP NULL,
  MODIFY COLUMN reviewreason VARCHAR(255) NULL,
  MODIFY COLUMN reviewedat TIMESTAMP NULL,
  ALGORITHM=INPLACE, LOCK=NONE;
