-- Production SQL for 2026_10_19_000001_add_edit_and_message_to_mod_stdmsgs.php
--
-- Adds the 'Edit and Message' standard message action and the replybody column that
-- holds its message to the poster. mod_stdmsgs is about 25k rows. The enum value is
-- appended, so the MODIFY is metadata-only; the column add is INSTANT. The MODIFY is
-- safe to re-run; the ADD fails with "Duplicate column" if it has already run.
-- Run before deploying apiv2 and ModTools.
SET SESSION lock_wait_timeout = 5;

ALTER TABLE mod_stdmsgs
  MODIFY COLUMN `action`
    ENUM('Approve','Reject','Leave','Approve Member','Reject Member','Leave Member','Leave Approved Message','Delete Approved Message','Leave Approved Member','Delete Approved Member','Edit','Hold Message','Edit and Message')
    NOT NULL DEFAULT 'Reject' COMMENT 'What action to take',
  ALGORITHM=INPLACE, LOCK=NONE;

ALTER TABLE mod_stdmsgs
  ADD COLUMN `replybody` MEDIUMTEXT NULL COMMENT 'For Edit and Message: the message to the poster',
  ALGORITHM=INSTANT;
