-- Production deploy SQL for the Laravel migration of the same name (idempotent).
-- Appends 'Banned' and 'Unbanned' to logs.subtype (a site-wide ban applied or lifted).
--
-- The value list matches the live column's physical order (see the 2026_07_06
-- 'Restored' migration for the history), so this is a pure end-append: 1 byte
-- storage unchanged, metadata-only, no rebuild. ALGORITHM=INSTANT is deliberate:
-- if the live order ever differs from this list the statement is a reorder, and
-- INSTANT makes MySQL refuse it instead of silently rebuilding ~40M rows under
-- Galera TOI. Do not remove it and do not reorder the list.
SET @exists := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'logs'
      AND COLUMN_NAME = 'subtype'
      AND COLUMN_TYPE LIKE "%'Unbanned'%"
);
SET @sql := IF(@exists = 0,
    "ALTER TABLE logs MODIFY COLUMN subtype ENUM('Created','Deleted','Received','Sent','Failure','ClassifiedSpam','Joined','Left','Approved','Rejected','YahooDeliveryType','YahooPostingStatus','NotSpam','Login','Hold','Release','Edit','RoleChange','Merged','Split','Replied','Mailed','Applied','Suspect','Licensed','LicensePurchase','YahooApplied','YahooConfirmed','YahooJoined','MailOff','EventsOff','NewslettersOff','RelevantOff','Logout','Bounce','SuspendMail','Autoreposted','Outcome','OurPostingStatus','VolunteersOff','Autoapproved','Unbounce','WorryWords','NoteAdded','PostcodeChange','Repost','OurEmailFrequency','Restored','Banned','Unbanned') DEFAULT NULL, ALGORITHM=INSTANT",
    'DO 0');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
