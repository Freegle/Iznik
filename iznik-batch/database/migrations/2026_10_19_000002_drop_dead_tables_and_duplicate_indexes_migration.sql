-- Production SQL for 2026_10_19_000002_drop_dead_tables_and_duplicate_indexes.php
--
-- Two blocks, each run on the write node. Every statement is metadata only.
--
-- Every DROP INDEX here is refused with LOCK=NONE (error 1845) on production, so
-- each uses LOCK=SHARED: reads carry on and writes to that one table wait for the
-- change, which takes well under a second.


-- Block 1: quiet hour.
-- These tables have no foreign key out to a busy table and nothing points at
-- them. Deleting the 2.7 GB and 0.9 GB simulation files can stall disk I/O for a
-- few seconds. Nothing writes authorities.
SET SESSION lock_wait_timeout = 5;

DROP TABLE IF EXISTS simulation_message_isochrones_users;
DROP TABLE IF EXISTS simulation_message_isochrones_expansions;
DROP TABLE IF EXISTS simulation_message_isochrones_messages;
DROP TABLE IF EXISTS simulation_message_isochrones_runs;
DROP TABLE IF EXISTS transport_duration_model;
DROP TABLE IF EXISTS transport_mode_probabilities;
DROP TABLE IF EXISTS items_non;
DROP TABLE IF EXISTS ebay_favourites;
DROP TABLE IF EXISTS spatial_ref_sys;
DROP TABLE IF EXISTS logs_api;

-- authorities has a spatial index, which also rules out LOCK=NONE.
ALTER TABLE authorities DROP INDEX name_2, ALGORITHM=INPLACE, LOCK=SHARED;


-- Block 2: the backup drain, just before backup:database starts.
-- These touch tables that are written all day (chat_messages, messages_likes,
-- shortlink_clicks) or have a foreign key to users, messages, locations or
-- groups, so they wait until those are quiet. returnpath_seedlist is here for
-- its foreign key to users.
SET SESSION lock_wait_timeout = 5;

-- chat_messages.scheduleid is the only thing pointing at users_schedules (last
-- written 2021). 9 rows still carry a schedule id; clear them one at a time.
UPDATE chat_messages SET scheduleid = NULL WHERE scheduleid IS NOT NULL;
ALTER TABLE chat_messages DROP FOREIGN KEY chat_messages_ibfk_2, DROP INDEX scheduleid, ALGORITHM=INPLACE, LOCK=SHARED;

DROP TABLE IF EXISTS users_schedules;
DROP TABLE IF EXISTS users_dashboard;
DROP TABLE IF EXISTS groups_twitter;
DROP TABLE IF EXISTS mod_bulkops_run;
DROP TABLE IF EXISTS returnpath_seedlist;

-- Each index below is an exact copy, or a leading part, of another index on the same table.
ALTER TABLE isochrones DROP INDEX locationid_2, ALGORITHM=INPLACE, LOCK=SHARED;
ALTER TABLE messages_outcomes_intended DROP INDEX msgid, ALGORITHM=INPLACE, LOCK=SHARED;
ALTER TABLE messages_outcomes_intended DROP INDEX msgid_3, ALGORITHM=INPLACE, LOCK=SHARED;
ALTER TABLE shortlink_clicks DROP INDEX shortlinkid, ALGORITHM=INPLACE, LOCK=SHARED;
ALTER TABLE trysts DROP INDEX arrangedfor, ALGORITHM=INPLACE, LOCK=SHARED;
ALTER TABLE stats_outcomes DROP INDEX groupid, ALGORITHM=INPLACE, LOCK=SHARED;
ALTER TABLE ratings DROP INDEX rater, ALGORITHM=INPLACE, LOCK=SHARED;

-- About 7 GB. Served only the sysadmin Recommendations stats, removed on 2026-10-13.
-- messages_likes is written on every page view, so the short lock wait matters most here.
ALTER TABLE messages_likes DROP INDEX messages_likes_source_ts_user, ALGORITHM=INPLACE, LOCK=SHARED;


-- Rolling back: down() recreates the tables empty and re-adds the indexes. Re-adding
-- the chat_messages_ibfk_2 foreign key is a full copy of chat_messages (about
-- 11 GB), so do not roll back on production without planning for that.
