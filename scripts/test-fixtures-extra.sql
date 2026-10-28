-- Hand-written test fixtures, loaded straight after scripts/test-fixtures.sql (by
-- scripts/setup-test-database.sh and the status container's Playwright DB reset).
--
-- test-fixtures.sql is a dump from the retired V1 seeders and is replaced wholesale when it
-- is regenerated, so anything added by hand belongs here instead. Every statement must be
-- idempotent (explicit ids, INSERT IGNORE): this runs on fresh and populated databases alike.

-- The Freegle account, which asks members questions in chat (firstreply.SystemUserID).
INSERT IGNORE INTO users (id, firstname, lastname, fullname, added, systemrole)
VALUES (990001, 'Freegle', NULL, 'Freegle', NOW(), 'User');
INSERT IGNORE INTO users_emails (userid, email, preferred, added)
VALUES (990001, 'freegle@ilovefreegle.org', 1, NOW());

-- A damaged armchair offered by the PW_replytochat moderator, so a reply to it is held while
-- the replier is asked whether they still want it (test-reply-to-chat.spec.js). A copy of
-- fixture offer 150 with its own id, so the other reply tests never meet the question.
INSERT IGNORE INTO messages (id, arrival, date, source, fromip, message, fromuser, fromname, fromaddr,
                             subject, type, textbody, lat, lng, locationid, availableinitially, availablenow,
                             deliverypossible)
SELECT 990101, arrival, date, source, fromip, 'Comfy old armchair. One arm is a bit wobbly.', fromuser,
       fromname, fromaddr, 'OFFER: Armchair (EX1 1SR)', 'Offer', 'Comfy old armchair. One arm is a bit wobbly.',
       lat, lng, locationid, 1, 1, 0
FROM messages WHERE id = 150;

INSERT IGNORE INTO messages_groups (msgid, groupid, arrival, collection, autoreposts)
SELECT 990101, groupid, arrival, 'Approved', 0 FROM messages_groups WHERE msgid = 150;

INSERT IGNORE INTO messages_spatial (msgid, point, successful, groupid, arrival, msgtype)
SELECT 990101, point, 1, groupid, arrival, 'Offer' FROM messages_spatial WHERE msgid = 150;

INSERT IGNORE INTO messages_eee (msgid, is_eee, item_condition, size_bucket, weight_bucket, model,
                                 prompt_version, classified_at)
VALUES (990101, 0, 'damaged', 'medium', '5_20kg', 'gemini-3.5-flash-lite', 'fixture', NOW());

-- The experiment confirm-reply for the Playwright case: the arm ask for everyone. ux_server_arms is the switch that lets
-- any server arm count. The config row ux_experiments makes the arm live at once; its entry is adopted so that it needs no
-- end time (a running entry more than 15 days ahead is refused). The ux_experiments TABLE row below is what keeps it live:
-- the hourly ux:experiments-tick (17 past) rebuilds the config row from the table, writes {} when nothing is running, and
-- would take the arm away within the hour. ux_experiments is a public key (every browser reads it at boot; the client
-- ignores a name it has no useExperiment call for) and ux_server_arms is not. Playwright and the dev site read these;
-- tests in other suites write their own rows.
INSERT INTO config (`key`, value) VALUES ('ux_server_arms', '1') ON DUPLICATE KEY UPDATE value = VALUES(value);
INSERT INTO config (`key`, value) VALUES ('ux_experiments', '{"confirm-reply":{"arms":{"ask":[0,99]},"adopted":true},"interested-button":{"arms":{"button":[0,99]},"adopted":true}}')
ON DUPLICATE KEY UPDATE value = VALUES(value);
-- The control range [1,0] is empty and rebuildConfig leaves it out, so everyone is in ask. fixed_split stops the tick
-- moving the split. intake_hours 336 puts the published until about 14 days ahead, inside the Go copy's 15 day cap. The
-- slot keeps the reply funnel free for a web experiment started in this database.
INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, started_at, ends_at,
                            decided_at, decision, result, design, created_at, updated_at)
VALUES ('reply', 'confirm-reply', 'Fixture: ask every replier', 'Fixture for the Playwright case.', 'Everyone is asked.',
        'Everyone is asked.', '{"control":[1,0],"ask":[0,99]}', 'running', NOW(), NOW() + INTERVAL 14 DAY,
        NULL, NULL, NULL,
        '{"channel":"server","source":"confirm_reply","window_hours":168,"intake_hours":336,"max_hours":336,"fixed_split":true,"slot":"server:confirm-reply"}',
        NOW(), NOW())
ON DUPLICATE KEY UPDATE arms = VALUES(arms), status = VALUES(status), started_at = VALUES(started_at),
        ends_at = VALUES(ends_at), decided_at = NULL, decision = NULL, result = NULL, design = VALUES(design), updated_at = NOW();

-- The experiment interested-button for the Playwright case: the arm button for everyone, in the same ux_experiments config
-- row and with the same upkeep as confirm-reply above. The control range [1,0] is empty, so everyone is in button.
INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, started_at, ends_at,
                            decided_at, decision, result, design, created_at, updated_at)
VALUES ('reply', 'interested-button', 'Fixture: offer the button to everyone', 'Fixture for the Playwright case.',
        'Everyone is offered the button.', 'Everyone is offered the button.', '{"control":[1,0],"button":[0,99]}', 'running',
        NOW(), NOW() + INTERVAL 14 DAY, NULL, NULL, NULL,
        '{"channel":"server","source":"interested_reply","window_hours":168,"intake_hours":336,"max_hours":336,"fixed_split":true,"slot":"server:interested-button"}',
        NOW(), NOW())
ON DUPLICATE KEY UPDATE arms = VALUES(arms), status = VALUES(status), started_at = VALUES(started_at),
        ends_at = VALUES(ends_at), decided_at = NULL, decision = NULL, result = NULL, design = VALUES(design), updated_at = NOW();
