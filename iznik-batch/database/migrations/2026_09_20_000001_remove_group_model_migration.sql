-- Production SQL for 2026_09_20_000001_remove_group_model.php
--
-- Removes the community (group) model. A post has one moderation state, on messages; a
-- member has one email frequency, one posting status and one ban, on users. The only
-- surviving use of a community is partner_areas, which TrashNothing addresses mail and
-- members by.
--
-- Run in three passes, each one a rolling-schema-upgrade pass on the cluster, in this order,
-- and deploy the code that reads the new columns between pass 2 and pass 3:
--   1. ADD    - new columns and partner_areas. Safe with the old code running.
--   2. MOVE   - copies the data across. Re-runnable. Old code keeps working.
--   3. DROP   - only after the new code is live everywhere. Not reversible: take a backup.
--
-- Every ADD step checks information_schema first, so the file can be run more than once.

-- ============================================================================ 1. ADD
SET @db := DATABASE();

SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE users ADD COLUMN emailfrequency INT NOT NULL DEFAULT 24 COMMENT '-1 immediate, 0 never, 24 daily'", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'users' AND column_name = 'emailfrequency');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE users ADD COLUMN eventsallowed TINYINT(1) NOT NULL DEFAULT 1", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'users' AND column_name = 'eventsallowed');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE users ADD COLUMN volunteeringallowed TINYINT(1) NOT NULL DEFAULT 1", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'users' AND column_name = 'volunteeringallowed');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE users ADD COLUMN postingstatus ENUM('MODERATED','DEFAULT','PROHIBITED','UNMODERATED') NULL", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'users' AND column_name = 'postingstatus');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE users ADD COLUMN banned TIMESTAMP NULL, ADD INDEX banned (banned)", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'users' AND column_name = 'banned');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE users ADD COLUMN bannedby BIGINT UNSIGNED NULL", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'users' AND column_name = 'bannedby');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE users ADD COLUMN welcomed TIMESTAMP NULL", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'users' AND column_name = 'welcomed');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE users ADD COLUMN modconfigid BIGINT UNSIGNED NULL", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'users' AND column_name = 'modconfigid');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE users ADD COLUMN reviewrequestedat BIGINT UNSIGNED NULL", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'users' AND column_name = 'reviewrequestedat');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE users ADD COLUMN reviewreason BIGINT UNSIGNED NULL", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'users' AND column_name = 'reviewreason');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE users ADD COLUMN reviewedat BIGINT UNSIGNED NULL", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'users' AND column_name = 'reviewedat');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE messages ADD COLUMN collection ENUM('Incoming','Pending','Approved','Spam','Rejected') NOT NULL DEFAULT 'Pending', ADD INDEX collection (collection)", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'messages' AND column_name = 'collection');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE messages ADD COLUMN approvedby BIGINT UNSIGNED NULL, ADD COLUMN approvedat TIMESTAMP NULL, ADD COLUMN rejectedat TIMESTAMP NULL", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'messages' AND column_name = 'approvedby');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE messages ADD COLUMN autoreposts INT NOT NULL DEFAULT 0, ADD COLUMN lastautopostwarning TIMESTAMP NULL, ADD COLUMN lastchaseup TIMESTAMP NULL", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'messages' AND column_name = 'autoreposts');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE messages ADD COLUMN contentcheck_checked_at TIMESTAMP NULL, ADD COLUMN contentcheck_reasons JSON NULL", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'messages' AND column_name = 'contentcheck_checked_at');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s := (SELECT IF(COUNT(*) = 0, "ALTER TABLE community_news_areas ADD COLUMN authorityid BIGINT UNSIGNED NULL, ADD INDEX authorityid (authorityid)", 'SELECT 1') FROM information_schema.columns WHERE table_schema = @db AND table_name = 'community_news_areas' AND column_name = 'authorityid');
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS partner_areas (
    id BIGINT UNSIGNED NOT NULL PRIMARY KEY COMMENT 'The community id TrashNothing still sends',
    nameshort VARCHAR(80) NOT NULL,
    namefull VARCHAR(100) NULL,
    lat DECIMAL(10,6) NOT NULL,
    lng DECIMAL(10,6) NOT NULL,
    polyindex GEOMETRY NOT NULL SRID 3857,
    UNIQUE KEY nameshort (nameshort),
    SPATIAL INDEX polyindex (polyindex)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Areas TrashNothing addresses mail and members by. Nothing a member sees reads this.';

INSERT IGNORE INTO partner_areas (id, nameshort, namefull, lat, lng, polyindex)
SELECT id, nameshort, namefull, COALESCE(lat, 0), COALESCE(lng, 0), polyindex
FROM `groups` WHERE ontn = 1 AND polyindex IS NOT NULL;

-- Verify pass 1:
--   SELECT COUNT(*) FROM partner_areas;                       -- the number of TN-syndicated communities
--   SHOW COLUMNS FROM messages LIKE 'collection';

-- ============================================================================ 2. MOVE
UPDATE messages m
INNER JOIN messages_groups mg ON mg.msgid = m.id AND mg.groupid = (
    SELECT x.groupid FROM messages_groups x WHERE x.msgid = m.id
    ORDER BY x.rippled_in ASC, x.arrival ASC, x.groupid ASC LIMIT 1
)
SET m.collection = CASE mg.collection
        WHEN 'Approved' THEN 'Approved' WHEN 'Spam' THEN 'Spam' WHEN 'Rejected' THEN 'Rejected'
        WHEN 'Incoming' THEN 'Incoming' ELSE 'Pending' END,
    m.approvedby = mg.approvedby, m.approvedat = mg.approvedat, m.rejectedat = mg.rejectedat,
    m.autoreposts = COALESCE(mg.autoreposts, 0), m.lastautopostwarning = mg.lastautopostwarning,
    m.lastchaseup = mg.lastchaseup, m.contentcheck_checked_at = mg.contentcheck_checked_at,
    m.contentcheck_reasons = mg.contentcheck_reasons,
    m.heldby = COALESCE(m.heldby, mg.heldby), m.spamtype = COALESCE(m.spamtype, mg.spamtype),
    m.spamreason = COALESCE(m.spamreason, mg.spamreason);

UPDATE users u
INNER JOIN (
    SELECT userid,
        MIN(CASE WHEN emailfrequency = -1 THEN 0 WHEN emailfrequency > 0 THEN 1 ELSE 2 END) AS freqrank,
        MAX(eventsallowed) AS eventsallowed, MAX(volunteeringallowed) AS volunteeringallowed,
        MAX(ourPostingStatus = 'PROHIBITED') AS prohibited, MAX(ourPostingStatus = 'MODERATED') AS moderated,
        MAX(ourPostingStatus = 'UNMODERATED') AS unmoderated, MAX(ourPostingStatus = 'DEFAULT') AS dflt,
        MAX(configid) AS configid
    FROM memberships GROUP BY userid
) m ON m.userid = u.id
SET u.emailfrequency = CASE m.freqrank WHEN 0 THEN -1 WHEN 1 THEN 24 ELSE 0 END,
    u.eventsallowed = COALESCE(m.eventsallowed, 1), u.volunteeringallowed = COALESCE(m.volunteeringallowed, 1),
    u.postingstatus = CASE WHEN m.prohibited = 1 THEN 'PROHIBITED' WHEN m.moderated = 1 THEN 'MODERATED'
        WHEN m.unmoderated = 1 THEN 'UNMODERATED' WHEN m.dflt = 1 THEN 'DEFAULT' ELSE NULL END,
    u.modconfigid = m.configid;

UPDATE users u
INNER JOIN (SELECT userid, MIN(date) AS banned, MIN(byuser) AS bannedby FROM users_banned GROUP BY userid) b ON b.userid = u.id
SET u.banned = b.banned, u.bannedby = b.bannedby
WHERE u.banned IS NULL;

UPDATE users u
INNER JOIN (SELECT userid, MAX(reviewrequestedat) AS requestedat, MAX(reviewedat) AS reviewedat
            FROM memberships WHERE reviewrequestedat IS NOT NULL GROUP BY userid) m ON m.userid = u.id
SET u.reviewrequestedat = m.requestedat, u.reviewedat = m.reviewedat,
    u.reviewreason = (SELECT x.reviewreason FROM memberships x WHERE x.userid = u.id AND x.reviewrequestedat = m.requestedat LIMIT 1);
UPDATE users SET welcomed = COALESCE(welcomed, added, NOW()) WHERE welcomed IS NULL;

-- Verify pass 2:
--   SELECT collection, COUNT(*) FROM messages GROUP BY collection;
--   SELECT emailfrequency, COUNT(*) FROM users GROUP BY emailfrequency;
--   SELECT COUNT(*) FROM users WHERE banned IS NOT NULL;      -- equals COUNT(DISTINCT userid) FROM users_banned

-- ============================================================================ 3. DROP
-- Only once the new code is live everywhere. Per-community statistics are truncated,
-- because they were per community and the batch regenerates national figures.
TRUNCATE TABLE stats;
TRUNCATE TABLE stats_outcomes;
TRUNCATE TABLE stats_summaries;
TRUNCATE TABLE users_dashboard;

-- Foreign keys and indexes on the columns below are named in information_schema; the
-- Laravel migration drops them by looking them up. On production, list them first:
--   SELECT TABLE_NAME, CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
--   WHERE TABLE_SCHEMA = DATABASE() AND (COLUMN_NAME IN ('groupid','group_id','volunteer_groupid')
--      OR REFERENCED_TABLE_NAME IN ('groups','memberships','messages_groups'));
-- and drop each with ALTER TABLE <t> DROP FOREIGN KEY <name>; then the columns:
ALTER TABLE chat_rooms DROP COLUMN groupid;
ALTER TABLE messages_spatial DROP COLUMN groupid;
ALTER TABLE messages_drafts DROP COLUMN groupid;
ALTER TABLE messages_postings DROP COLUMN groupid;
ALTER TABLE messages_history DROP COLUMN groupid;
ALTER TABLE messages_index DROP COLUMN groupid;
ALTER TABLE messages_popular DROP COLUMN groupid;
ALTER TABLE newsfeed DROP COLUMN groupid;
ALTER TABLE logs DROP COLUMN groupid;
ALTER TABLE users_comments DROP COLUMN groupid;
ALTER TABLE users_modmails DROP COLUMN groupid;
ALTER TABLE users_dashboard DROP COLUMN groupid;
ALTER TABLE users_postnotifications_tracking DROP COLUMN groupid;
ALTER TABLE alerts DROP COLUMN groupid;
ALTER TABLE alerts_tracking DROP COLUMN groupid;
ALTER TABLE admins DROP COLUMN groupid;
ALTER TABLE polls DROP COLUMN groupid;
ALTER TABLE shortlinks DROP COLUMN groupid;
ALTER TABLE vouchers DROP COLUMN groupid;
ALTER TABLE locations_excluded DROP COLUMN groupid;
ALTER TABLE newsletters DROP COLUMN groupid;
ALTER TABLE changes DROP COLUMN groupid;
ALTER TABLE email_tracking DROP COLUMN groupid;
ALTER TABLE stats DROP COLUMN groupid;
ALTER TABLE stats_outcomes DROP COLUMN groupid;
ALTER TABLE stats_summaries DROP COLUMN groupid;
ALTER TABLE reengage DROP COLUMN volunteer_groupid;
ALTER TABLE rippling_reach DROP COLUMN rejected_groups, DROP COLUMN reachable_group_ids;
ALTER TABLE community_news_areas DROP COLUMN anchorgroupid, DROP COLUMN groupids;
ALTER TABLE concern_keywords DROP COLUMN scope, DROP COLUMN group_id;
ALTER TABLE simulation_message_isochrones_messages DROP COLUMN groupid;

DROP TABLE IF EXISTS memberships_yahoo_dump, memberships_yahoo, memberships_history, memberships,
    messages_groups, groups_digests, groups_facebook_shares, groups_facebook_toshare, groups_facebook,
    groups_images, groups_mods_welfare, groups_sponsorship, groups_twitter, users_banned,
    communityevents_groups, volunteering_groups, partnerships_groups, rippling_proximity_checked,
    rippling_proximity, mod_bulkops_run, plugin, `groups`;

-- Verify pass 3:
--   SHOW TABLES LIKE 'groups%';                                -- empty
--   SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_name = 'groupid';  -- 0
