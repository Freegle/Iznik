-- Production idempotent SQL: append 'ConvertedToPost' to newsfeed.type.
--
-- The ChitChat convert-to-post flow (apiv2 newsfeed action 'ConvertedToPost',
-- PR #1216) leaves a notice reply on the thread with this type. The enum value
-- was missing, so MySQL truncated the type to '' and the notice rendered as an
-- empty reply from the moderator.
--
-- End-append keeps existing values' indexes stable. ENUM widening is
-- metadata-only with ALGORITHM=INPLACE, LOCK=NONE (INSTANT is not supported
-- for ENUM modification on Percona/Galera), so this is safe to run cluster-wide
-- under the default TOI. Guarded so a re-run is a no-op.
SET @has_value := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'newsfeed'
      AND column_name = 'type' AND column_type LIKE '%ConvertedToPost%');
SET @ddl := IF(@has_value = 0,
    "ALTER TABLE newsfeed MODIFY COLUMN `type` ENUM('Message','CommunityEvent','VolunteerOpportunity','CentralPublicity','Alert','Story','ReferToWanted','ReferToOffer','ReferToTaken','ReferToReceived','AboutMe','Noticeboard','ConvertedToPost') NOT NULL DEFAULT 'Message', ALGORITHM=INPLACE, LOCK=NONE",
    'SELECT 1');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Repair any notice rows written before the enum value existed: they were
-- truncated to '' on insert. Only convert-to-post notices can have ended up as
-- '' (no other code path inserts an out-of-enum type), so the blanket match is
-- safe. msgid cannot be recovered here; the notice renders generic wording
-- without it.
UPDATE newsfeed SET type = 'ConvertedToPost' WHERE type = '';
