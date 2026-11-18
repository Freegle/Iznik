-- Phase 0 measurements for plans/2026-11-12-tn-api-chat-ingestion.md, run on the yesterday
-- server against a restored production snapshot. Every query is read-only and prints counts only:
-- the rows are members' private messages, so do not select or copy message text off the VM.
--
-- How the data is laid out
--   * Every chat message the email path creates has its raw mail in `messages`, linked through
--     `chat_messages_byemail` (IncomingMailService::storeChatEmailMessage). `messages.fromaddr` is
--     the From header, so a TN member's mail has a *trashnothing.com address there.
--   * `messages.message` (the raw mail, headers included) is emptied 30-60 days after arrival
--     (PurgeService::purgeMessageSource). The other columns stay. Keep windows within 30 days.
--   * Mail the email path dropped made no rows at all. Those are only in Loki, which yesterday
--     restores too (31 days); see the Loki section of the plan.
--   * NOW() on yesterday is today, not the snapshot. The window is anchored on the snapshot's own
--     latest arrival instead.
--   * `messages` has an index on (arrival, fromaddr), so the window filters below are cheap.

SET @until = (SELECT MAX(arrival) FROM messages);
SET @since = @until - INTERVAL 30 DAY;
SELECT @since AS window_start, @until AS window_end;


-- Q1. TN chat mail by route (the rows of section 1 of the plan), chat type and message type.
--     "direct" splits into row 2 (has a post) and row 3 (no post) on has_post.
SELECT
  CASE
    WHEN m.envelopeto LIKE 'notify-%'                                   THEN '1 notify'
    WHEN m.envelopeto LIKE 'replyto-%'                                  THEN '7 replyto'
    WHEN m.envelopeto LIKE '%-volunteers@%' AND m.subject LIKE 'Reporting %' THEN '5 report'
    WHEN m.envelopeto LIKE '%-volunteers@%'                             THEN '4 volunteers'
    WHEN m.envelopeto LIKE '%-auto@%'                                   THEN 'auto'
    WHEN m.envelopeto LIKE '%@users.ilovefreegle.org'                   THEN '2-3 direct'
    ELSE 'other'
  END                               AS route,
  cr.chattype,
  cm.type,
  (cm.refmsgid IS NOT NULL)         AS has_post,
  COUNT(*)                          AS messages,
  COUNT(DISTINCT cm.userid)         AS senders,
  COUNT(DISTINCT cm.chatid)         AS chats
FROM messages m
JOIN chat_messages_byemail cme ON cme.msgid = m.id
JOIN chat_messages cm          ON cm.id = cme.chatmsgid
JOIN chat_rooms cr             ON cr.id = cm.chatid
WHERE m.arrival >= @since AND m.arrival < @until
  AND m.fromaddr LIKE '%trashnothing.com'
GROUP BY route, cr.chattype, cm.type, has_post
ORDER BY route, messages DESC;


-- Q2. Which headers TN puts on its chat mail, by route. Answers part of section 7.2 without asking
--     TN: is the secret there, is the TN user id there, and does any per-message TN id exist already.
--     For a full list of X- header names, use the tinker scan in the plan instead.
SELECT
  CASE
    WHEN m.envelopeto LIKE 'notify-%'                 THEN '1 notify'
    WHEN m.envelopeto LIKE 'replyto-%'                THEN '7 replyto'
    WHEN m.envelopeto LIKE '%-volunteers@%'           THEN '4-5 volunteers'
    WHEN m.envelopeto LIKE '%@users.ilovefreegle.org' THEN '2-3 direct'
    ELSE 'other'
  END                                                                              AS route,
  COUNT(*)                                                                         AS mails,
  SUM(m.message REGEXP '(?im)^x-trash-nothing-secret:')                           AS has_secret,
  SUM(m.message REGEXP '(?im)^x-trash-nothing-user-id:')                          AS has_tn_user_id,
  SUM(m.message REGEXP '(?im)^x-fd-msgid:')                                       AS has_fd_msgid,
  SUM(m.message REGEXP '(?im)^x-trash-nothing-[a-z-]*(message|chat|reply)[a-z-]*:') AS has_tn_msg_header,
  SUM(m.message LIKE '%automated response from trashnothing.com%')                AS automated,
  SUM(m.message REGEXP '(?im)^content-type:[ \\t]*image/')                        AS has_image_part,
  SUM(m.message REGEXP '(?im)^content-type:[ \\t]*text/html')                     AS has_html_part
FROM messages m
JOIN chat_messages_byemail cme ON cme.msgid = m.id
WHERE m.arrival >= @since AND m.arrival < @until
  AND m.fromaddr LIKE '%trashnothing.com'
  AND m.message IS NOT NULL AND LENGTH(m.message) > 0
GROUP BY route
ORDER BY mails DESC;


-- Q3. Message-ID shapes on TN chat mail: the join key the parity check would use (plan 5.2).
--     If TN's Message-IDs already carry a TN message id, say so to Andrew.
SELECT
  SUBSTRING_INDEX(m.messageid, '@', -1)                  AS messageid_domain,
  COUNT(*)                                               AS mails,
  MIN(m.messageid)                                       AS example
FROM messages m
JOIN chat_messages_byemail cme ON cme.msgid = m.id
WHERE m.arrival >= @since AND m.arrival < @until
  AND m.fromaddr LIKE '%trashnothing.com'
GROUP BY messageid_domain
ORDER BY mails DESC
LIMIT 20;


-- Q4. Identity: would the API path's rule (the account holding the TN user id, plan 3.1) pick the
--     same account the email path wrote the message as? Read off the X-Trash-Nothing-User-Id header.
--     Rows other than "same account" are messages the API path would file elsewhere or skip; they
--     size decision 1 in section 8.
SELECT
  CASE
    WHEN x.tn_id IS NULL                        THEN 'a. no TN user id header'
    WHEN u.tnuserid = x.tn_id                   THEN 'b. same account: it holds the TN id'
    WHEN u.tnuserid IS NULL AND h.id IS NULL    THEN 'c. no account holds the TN id; sender has none (same account only if TN sends our id)'
    WHEN u.tnuserid IS NULL                     THEN 'd. another account holds the TN id; sender has none'
    ELSE                                             'e. sender holds a different TN id'
  END                                                AS identity,
  x.route,
  COUNT(*)                                           AS messages,
  COUNT(DISTINCT x.userid)                           AS senders
FROM (
  SELECT
    cm.userid,
    CASE WHEN m.envelopeto LIKE 'notify-%' THEN '1 notify'
         WHEN m.envelopeto LIKE '%-volunteers@%' THEN '4-5 volunteers'
         ELSE '2-3 direct/other' END                 AS route,
    CAST(REGEXP_SUBSTR(
           REGEXP_SUBSTR(m.message, '(?im)^x-trash-nothing-user-id:[ \\t]*[0-9]+'),
           '[0-9]+$') AS UNSIGNED)                   AS tn_id
  FROM messages m
  JOIN chat_messages_byemail cme ON cme.msgid = m.id
  JOIN chat_messages cm          ON cm.id = cme.chatmsgid
  WHERE m.arrival >= @since AND m.arrival < @until
    AND m.fromaddr LIKE '%trashnothing.com'
    AND m.message IS NOT NULL AND LENGTH(m.message) > 0
) x
JOIN users u      ON u.id = x.userid
LEFT JOIN users h ON h.tnuserid = x.tn_id AND h.id <> x.userid
GROUP BY identity, x.route
ORDER BY identity, x.route;


-- Q5. Images: how many TN chat mails were followed by Image messages from the same sender in the
--     same chat (addPhotosToChat writes them straight after the text message).
SELECT
  COUNT(DISTINCT t.id)   AS tn_text_messages_with_images,
  COUNT(img.id)          AS image_messages
FROM (
  SELECT cm.id, cm.chatid, cm.userid, cm.date
  FROM messages m
  JOIN chat_messages_byemail cme ON cme.msgid = m.id
  JOIN chat_messages cm          ON cm.id = cme.chatmsgid
  WHERE m.arrival >= @since AND m.arrival < @until
    AND m.fromaddr LIKE '%trashnothing.com'
) t
JOIN chat_messages img
  ON img.chatid = t.chatid AND img.userid = t.userid
 AND img.type = 'Image' AND img.platform = 0
 AND img.id > t.id AND img.date BETWEEN t.date AND t.date + INTERVAL 5 MINUTE;


-- Q6. Does chats:process-incoming overwrite the review flags the email path sets on arrival?
--     (Section 2, "Something to check".) These reportreason values are written only by the email
--     path at ingestion; ChatProcessService never writes them. If processed email-path rows still
--     carry them, the flags survive and the plan's suspicion is wrong. If none do, while the mail
--     path flags messages (the second query), it is right. All email-path chat, not only TN.
--     Only successfully processed rows: processFailed (spammer, banned in common) leaves the
--     arrival values in place, so a failed row says nothing either way.
SELECT cm.reportreason, cm.reviewrequired, COUNT(*) AS messages
FROM chat_messages cm
JOIN chat_messages_byemail cme ON cme.chatmsgid = cm.id
WHERE cm.date >= @since AND cm.date < @until
  AND cm.processingrequired = 0 AND cm.processingsuccessful = 1
  AND cm.reportreason IN ('SpamAssassin', 'SubjectUsedForDifferentGroups', 'CountryBlocked',
                          'Known spam keyword', 'BulkVolunteerMail', 'UsedOurDomain', 'Script',
                          'SameImage')
GROUP BY cm.reportreason, cm.reviewrequired
ORDER BY messages DESC;

-- Every reportreason on processed email-path rows, for comparison, split by success.
SELECT cm.processingsuccessful, cm.reportreason, cm.reviewrequired, COUNT(*) AS messages
FROM chat_messages cm
JOIN chat_messages_byemail cme ON cme.chatmsgid = cm.id
WHERE cm.date >= @since AND cm.date < @until
  AND cm.processingrequired = 0
GROUP BY cm.processingsuccessful, cm.reportreason, cm.reviewrequired
ORDER BY messages DESC;


-- Q7. TN chat volume per day, for the parity job's expected counts and the feed's page sizes.
SELECT DATE(m.arrival) AS day, COUNT(*) AS tn_chat_mails
FROM messages m
JOIN chat_messages_byemail cme ON cme.msgid = m.id
WHERE m.arrival >= @since AND m.arrival < @until
  AND m.fromaddr LIKE '%trashnothing.com'
GROUP BY day
ORDER BY day;
