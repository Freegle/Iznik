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

-- A child car seat offered by the PW_replytochat moderator, so a reply to it is held while
-- the replier is asked to check the seat and whether they still want it
-- (test-reply-to-chat.spec.js). A copy of fixture offer 150 with its own id, so the other reply
-- tests never meet the question.
INSERT IGNORE INTO messages (id, arrival, date, source, fromip, message, fromuser, fromname, fromaddr,
                             subject, type, textbody, lat, lng, locationid, availableinitially, availablenow,
                             deliverypossible, collection, autoreposts)
SELECT 990101, arrival, date, source, fromip, 'Child car seat, group 1. Some wear on the straps.', fromuser,
       fromname, fromaddr, 'OFFER: Child car seat (EX1 1SR)', 'Offer', 'Child car seat, group 1. Some wear on the straps.',
       lat, lng, locationid, 1, 1, 0, 'Approved', 0
FROM messages WHERE id = 150;

INSERT IGNORE INTO messages_spatial (msgid, point, successful, arrival, msgtype)
SELECT 990101, point, 1, arrival, 'Offer' FROM messages_spatial WHERE msgid = 150;

-- A database loaded before this offer was a car seat still has the armchair.
UPDATE messages SET subject = 'OFFER: Child car seat (EX1 1SR)',
                    message = 'Child car seat, group 1. Some wear on the straps.',
                    textbody = 'Child car seat, group 1. Some wear on the straps.'
WHERE id = 990101;

-- The experiment interested-button for the Playwright case: the arm button for everyone. ux_server_arms is the switch that
-- lets any server arm count. The config row ux_experiments makes the arm live at once; its entry is adopted so that it needs
-- no end time (a running entry more than 15 days ahead is refused). The ux_experiments TABLE row below is what keeps it
-- live: the hourly ux:experiments-tick (17 past) rebuilds the config row from the table, writes {} when nothing is running,
-- and would take the arm away within the hour. ux_experiments is a public key (every browser reads it at boot; the client
-- ignores a name it has no useExperiment call for) and ux_server_arms is not. Playwright and the dev site read these;
-- tests in other suites write their own rows.
INSERT INTO config (`key`, value) VALUES ('ux_server_arms', '1') ON DUPLICATE KEY UPDATE value = VALUES(value);
INSERT INTO config (`key`, value) VALUES ('ux_experiments', '{"interested-button":{"arms":{"button":[0,99]},"adopted":true}}')
ON DUPLICATE KEY UPDATE value = VALUES(value);
-- confirm-reply was reverted and its code removed; a database loaded before then still has its fixture row.
DELETE FROM ux_experiments WHERE name = 'confirm-reply' AND title = 'Fixture: ask every replier';
-- The control range [1,0] is empty and rebuildConfig leaves it out, so everyone is in button. fixed_split stops the tick
-- moving the split. intake_hours 336 puts the published until about 14 days ahead, inside the Go copy's 15 day cap. The
-- slot keeps the reply funnel free for a web experiment started in this database.
INSERT INTO ux_experiments (flow, name, title, hypothesis, change_description, expected, arms, status, started_at, ends_at,
                            decided_at, decision, result, design, created_at, updated_at)
VALUES ('reply', 'interested-button', 'Fixture: offer the button to everyone', 'Fixture for the Playwright case.',
        'Everyone is offered the button.', 'Everyone is offered the button.', '{"control":[1,0],"button":[0,99]}', 'running',
        NOW(), NOW() + INTERVAL 14 DAY, NULL, NULL, NULL,
        '{"channel":"server","source":"interested_reply","window_hours":168,"intake_hours":336,"max_hours":336,"fixed_split":true,"slot":"server:interested-button"}',
        NOW(), NOW())
ON DUPLICATE KEY UPDATE arms = VALUES(arms), status = VALUES(status), started_at = VALUES(started_at),
        ends_at = VALUES(ends_at), decided_at = NULL, decision = NULL, result = NULL, design = VALUES(design), updated_at = NOW();
