-- Phase 0 measurements for plans/2026-11-12-tn-api-chat-ingestion.md, run on the yesterday
-- server against a restored production snapshot. Every query is read-only and prints counts only:
-- the rows are members' private messages, so do not select or copy message text off the VM.
--
-- How the data is laid out
--   * Every chat message the email path creates has its raw mail in `messages`, linked through
--     `chat_messages_byemail` (IncomingMailService::storeChatEmailMessage). `messages.fromaddr` is
--     the From header, so a TN member's mail has a *trashnothing.com address there.
--   * A snapshot holds only about TWO DAYS of chat mail. purge:messages (daily, 02:30) runs
--     PurgeService::purgeStrandedMessages, which deletes any `messages` row older than two days
--     that has no messages_groups row, is not a chat_messages.refmsgid and is not a draft. It does
--     not count chat_messages_byemail as a reference, so a chat email's raw row goes, and the
--     chat_messages_byemail row with it (cascade). For a longer sample, run the same queries on
--     several of the LVM snapshots (switch-backup.sh --list) and add the results up. For 30-day
--     volumes, use Loki (31 days) instead; see the plan.
--   * Mail the email path dropped made no rows at all. Those are only in Loki.
--   * NOW() on yesterday is today, not the snapshot. The window is anchored on the snapshot's own
--     latest arrival instead.
--   * `messages` has an index on (arrival, fromaddr), so the window filters below are cheap.

SET @until = (SELECT MAX(arrival) FROM messages);
SET @since = @until - INTERVAL 2 DAY;   -- older chat mail rows have been purged; Q7 shows the real span
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
WHERE m.arrival >= @since AND m.arrival <= @until
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
  SUM(m.message REGEXP '(?im)^x-trash-nothing-log-id:')                           AS has_tn_log_id,
  SUM(m.message REGEXP '(?im)^x-trash-nothing-meta:')                             AS has_tn_meta,
  SUM(m.message LIKE '%automated response from trashnothing.com%')                AS automated,
  SUM(m.message REGEXP '(?im)^content-type:[ \\t]*image/')                        AS has_image_part,
  SUM(m.message REGEXP '(?im)^content-type:[ \\t]*text/html')                     AS has_html_part
FROM messages m
JOIN chat_messages_byemail cme ON cme.msgid = m.id
WHERE m.arrival >= @since AND m.arrival <= @until
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
WHERE m.arrival >= @since AND m.arrival <= @until
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
  WHERE m.arrival >= @since AND m.arrival <= @until
    AND m.fromaddr LIKE '%trashnothing.com'
    AND m.message IS NOT NULL AND LENGTH(m.message) > 0
) x
JOIN users u      ON u.id = x.userid
LEFT JOIN users h ON h.tnuserid = x.tn_id AND h.id <> x.userid
GROUP BY identity, x.route
ORDER BY identity, x.route;


-- Q4b. Q4's case e: the account the email path used holds a different TN id from the header's.
--      What would the API path do? It files by the header's TN id (plan 3.1). For a reply in a
--      chat (notify), it needs the holder of that id to be in the chat, or it skips. For a first
--      reply (direct), it needs a holder at all, or it skips.
SELECT
  x.route,
  CASE WHEN h.id IS NULL                     THEN 'no account holds the header''s TN id'
       WHEN h.id IN (cr.user1, cr.user2)      THEN 'the holder is in the chat'
       ELSE                                        'the holder exists, not in the chat'
  END                                                AS holder,
  COUNT(*)                                           AS messages,
  COUNT(DISTINCT x.userid)                           AS accounts_used,
  COUNT(DISTINCT x.chatid)                           AS chats
FROM (
  SELECT
    cm.userid, cm.chatid,
    CASE WHEN m.envelopeto LIKE 'notify-%' THEN '1 notify'
         WHEN m.envelopeto LIKE '%-volunteers@%' THEN '4-5 volunteers'
         ELSE '2-3 direct/other' END                 AS route,
    CAST(REGEXP_SUBSTR(
           REGEXP_SUBSTR(m.message, '(?im)^x-trash-nothing-user-id:[ \\t]*[0-9]+'),
           '[0-9]+$') AS UNSIGNED)                   AS tn_id
  FROM messages m
  JOIN chat_messages_byemail cme ON cme.msgid = m.id
  JOIN chat_messages cm          ON cm.id = cme.chatmsgid
  WHERE m.arrival >= @since AND m.arrival <= @until
    AND m.fromaddr LIKE '%trashnothing.com'
    AND m.message IS NOT NULL AND LENGTH(m.message) > 0
) x
JOIN users u       ON u.id = x.userid
JOIN chat_rooms cr ON cr.id = x.chatid
LEFT JOIN users h  ON h.tnuserid = x.tn_id AND h.id <> x.userid
WHERE x.tn_id IS NOT NULL AND u.tnuserid IS NOT NULL AND u.tnuserid <> x.tn_id
GROUP BY x.route, holder
ORDER BY x.route, holder;


-- Q4c. The accounts behind Q4b, for a person to split before cutover with
--      `tn:split-shared-account <freegle-user-id> <tn-username> <tn-user-id>` (on production, not
--      here; the snapshot only says which accounts to look at). One row per account and the TN id
--      in its mail. Ids only; the username comes from the member's address when splitting.
--      Run it on several snapshots: each holds about two days.
SELECT
  x.userid                                           AS account_used,
  u.tnuserid                                         AS account_tn_id,
  x.tn_id                                            AS header_tn_id,
  h.id                                               AS holder_of_header_tn_id,
  GROUP_CONCAT(DISTINCT x.route ORDER BY x.route)    AS routes,
  COUNT(*)                                           AS messages,
  MAX(x.arrival)                                     AS last_seen
FROM (
  SELECT
    cm.userid, m.arrival,
    CASE WHEN m.envelopeto LIKE 'notify-%' THEN '1 notify'
         WHEN m.envelopeto LIKE '%-volunteers@%' THEN '4-5 volunteers'
         ELSE '2-3 direct/other' END                 AS route,
    CAST(REGEXP_SUBSTR(
           REGEXP_SUBSTR(m.message, '(?im)^x-trash-nothing-user-id:[ \\t]*[0-9]+'),
           '[0-9]+$') AS UNSIGNED)                   AS tn_id
  FROM messages m
  JOIN chat_messages_byemail cme ON cme.msgid = m.id
  JOIN chat_messages cm          ON cm.id = cme.chatmsgid
  WHERE m.arrival >= @since AND m.arrival <= @until
    AND m.fromaddr LIKE '%trashnothing.com'
    AND m.message IS NOT NULL AND LENGTH(m.message) > 0
) x
JOIN users u      ON u.id = x.userid
LEFT JOIN users h ON h.tnuserid = x.tn_id AND h.id <> x.userid
WHERE x.tn_id IS NOT NULL AND u.tnuserid IS NOT NULL AND u.tnuserid <> x.tn_id
GROUP BY x.userid, u.tnuserid, x.tn_id, h.id
ORDER BY messages DESC;


-- Q11. Mail with no TN user id header: who sends it? Q4 found 11 such mails to the volunteers, all
--      from one account, and Q2 found 11 volunteers mails carrying TN's secret. If these are TN
--      itself writing to volunteers, the feed needs to say so (plan 7.1). Shows the From domain,
--      and the local part only when it is not a member's address at user.trashnothing.com.
SELECT
  CASE WHEN m.envelopeto LIKE 'notify-%' THEN '1 notify'
       WHEN m.envelopeto LIKE '%-volunteers@%' THEN '4-5 volunteers'
       ELSE '2-3 direct/other' END                                                      AS route,
  SUBSTRING_INDEX(m.fromaddr, '@', -1)                                                  AS from_domain,
  IF(m.fromaddr LIKE '%@user.trashnothing.com', '(member address)',
     SUBSTRING_INDEX(m.fromaddr, '@', 1))                                               AS from_local,
  (u.tnuserid IS NOT NULL)                                                              AS account_has_tn_id,
  (m.message REGEXP '(?im)^x-trash-nothing-secret:')                                    AS has_secret,
  TRIM(SUBSTRING(REGEXP_SUBSTR(m.message, '(?im)^x-trash-nothing-meta:[^\\r\\n]*'), 22)) AS meta,
  COUNT(*)                                                                              AS mails,
  COUNT(DISTINCT cm.userid)                                                             AS accounts,
  COUNT(DISTINCT cm.chatid)                                                             AS chats,
  COUNT(DISTINCT cr.groupid)                                                            AS communities
FROM messages m
JOIN chat_messages_byemail cme ON cme.msgid = m.id
JOIN chat_messages cm          ON cm.id = cme.chatmsgid
JOIN chat_rooms cr             ON cr.id = cm.chatid
JOIN users u                   ON u.id = cm.userid
WHERE m.arrival >= @since AND m.arrival <= @until
  AND m.fromaddr LIKE '%trashnothing.com'
  AND m.message IS NOT NULL AND LENGTH(m.message) > 0
  AND m.message NOT REGEXP '(?im)^x-trash-nothing-user-id:'
GROUP BY route, from_domain, from_local, account_has_tn_id, has_secret, meta
ORDER BY mails DESC;


-- Q12. Which accounts hold a TN address that is not a member's (noreply@trashnothing.com and the
--      like)? Q11 found TN's notices to volunteers, From noreply@trashnothing.com, filed as coming
--      from an account holding a TN user id, so an account with a TN id holds that address. Not
--      windowed: users_emails is not purged like chat mail. Prints ids, and the address only when
--      its local part is a known system name: the first version of this query also matched
--      members' own addresses mistyped with a TN domain, and printed them.
SELECT
  IF(SUBSTRING_INDEX(ue.email, '@', 1) IN ('noreply', 'no-reply', 'support', 'info', 'help', 'admin',
                                           'bounces', 'postmaster', 'mailer-daemon'),
     ue.email, '(other address)')                                        AS tn_system_address,
  ue.userid                                                              AS account,
  ue.added,
  ue.preferred,
  (u.tnuserid IS NOT NULL)                                               AS account_has_tn_id,
  (u.deleted IS NOT NULL)                                                AS account_deleted,
  (SELECT COUNT(*) FROM users_emails e2
    WHERE e2.userid = ue.userid AND e2.email LIKE '%@user.trashnothing.com') AS member_tn_addresses
FROM users_emails ue
JOIN users u ON u.id = ue.userid
WHERE ue.email LIKE '%trashnothing.com'
  AND ue.email NOT LIKE '%@user.trashnothing.com'
ORDER BY ue.added;


-- Q12b. What the account holding noreply@trashnothing.com looks like. Q12 found the address is its
--       preferred one, added on 2020-12-28, and that it holds a TN user id but no member TN
--       address. addEmailToUser adds addresses as not preferred, so the account was probably
--       created from this address. Counts, dates and yes/no only.
SET @acct = (SELECT userid FROM users_emails WHERE email = 'noreply@trashnothing.com');
SELECT
  u.added                                                                       AS account_created,
  u.lastaccess,
  u.systemrole,
  (u.tnuserid IS NOT NULL)                                                      AS has_tn_id,
  (u.fullname LIKE '%trash%nothing%')                                           AS name_says_trash_nothing,
  (SELECT COUNT(*) FROM users_emails WHERE userid = u.id)                       AS addresses,
  (SELECT COUNT(*) FROM memberships WHERE userid = u.id)                        AS memberships,
  (SELECT COUNT(*) FROM messages WHERE fromuser = u.id)                         AS messages_rows,
  (SELECT COUNT(*) FROM chat_rooms WHERE user1 = u.id AND chattype = 'User2Mod') AS user2mod_chats,
  (SELECT COUNT(*) FROM chat_rooms WHERE user1 = u.id AND chattype = 'User2User')
    + (SELECT COUNT(*) FROM chat_rooms WHERE user2 = u.id AND chattype = 'User2User') AS user2user_chats,
  (SELECT COUNT(*) FROM chat_messages WHERE userid = u.id)                      AS chat_messages_sent,
  (SELECT MIN(date) FROM chat_messages WHERE userid = u.id)                     AS first_chat_message,
  (SELECT MAX(date) FROM chat_messages WHERE userid = u.id)                     AS last_chat_message
FROM users u
WHERE u.id = @acct;


-- Q13. The envelope-from on TN chat mail, by route. addEmailToUser attaches a mail's envelope-from
--      to the account a notify address names, if no account has it yet. If TN's replies ever came
--      with envelope-from noreply@trashnothing.com, that would explain Q12. Member addresses are
--      masked, and long digit or hex runs are replaced.
SELECT
  CASE WHEN m.envelopeto LIKE 'notify-%' THEN '1 notify'
       WHEN m.envelopeto LIKE '%-volunteers@%' THEN '4-5 volunteers'
       ELSE '2-3 direct/other' END                                       AS route,
  CASE WHEN m.envelopefrom LIKE '%@user.trashnothing.com' THEN '(member address)'
       ELSE REGEXP_REPLACE(REGEXP_REPLACE(m.envelopefrom, '[0-9a-f]{8,}', '<hex>'), '[0-9]{4,}', '<n>')
  END                                                                    AS envelope_from,
  COUNT(*)                                                               AS mails
FROM messages m
JOIN chat_messages_byemail cme ON cme.msgid = m.id
WHERE m.arrival >= @since AND m.arrival <= @until
  AND m.fromaddr LIKE '%trashnothing.com'
GROUP BY route, envelope_from
ORDER BY mails DESC
LIMIT 30;


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
  WHERE m.arrival >= @since AND m.arrival <= @until
    AND m.fromaddr LIKE '%trashnothing.com'
) t
JOIN chat_messages img
  ON img.chatid = t.chatid AND img.userid = t.userid
 AND img.type = 'Image' AND img.platform = 0
 AND img.id > t.id AND img.date BETWEEN t.date AND t.date + INTERVAL 5 MINUTE;


-- Q6. Does chats:process-incoming overwrite the review flags the email path sets on arrival?
--     It did until c5f270ece5 (12 November 2026), which made processing keep an arrival hold; on
--     the 19 November snapshot this query found 3 rows still carrying an arrival-only reason, so
--     the fix holds (plan, end of section 2). These reportreason values are written only by the
--     email path at ingestion; ChatProcessService never writes them. If processed email-path rows
--     still carry them, the flags survive. All email-path chat, not only TN.
--     Only successfully processed rows: processFailed (spammer, banned in common) leaves the
--     arrival values in place, so a failed row says nothing either way.
SELECT cm.reportreason, cm.reviewrequired, COUNT(*) AS messages
FROM chat_messages cm
JOIN chat_messages_byemail cme ON cme.chatmsgid = cm.id
WHERE cm.date >= @since AND cm.date <= @until
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
WHERE cm.date >= @since AND cm.date <= @until
  AND cm.processingrequired = 0
GROUP BY cm.processingsuccessful, cm.reportreason, cm.reviewrequired
ORDER BY messages DESC;


-- Q7. TN chat volume per day and per hour of day, for the parity job's expected counts and the
--     feed's page sizes. Run this one WITHOUT the window (all surviving rows), to see how far back
--     the snapshot's chat mail really goes: expect two days and a bit, then nothing.
SELECT day, SUM(n) AS tn_chat_mails, MAX(n) AS busiest_hour, COUNT(*) AS hours_with_mail
FROM (
  SELECT DATE(m.arrival) AS day, HOUR(m.arrival) AS hr, COUNT(*) AS n
  FROM messages m
  JOIN chat_messages_byemail cme ON cme.msgid = m.id
  WHERE m.arrival >= @until - INTERVAL 30 DAY
    AND m.fromaddr LIKE '%trashnothing.com'
  GROUP BY day, hr
) hourly
GROUP BY day
ORDER BY day;


-- Q9. What TN puts in the text. Q2 searched the raw mail, whose body is usually quoted-printable
--     or base64, so a phrase there can be split or encoded and missed. This reads the decoded text
--     part the email path stores in messages.textbody, and compares it with what it kept in
--     chat_messages.message after stripping quoted text and signatures.
SELECT
  CASE
    WHEN m.envelopeto LIKE 'notify-%'                 THEN '1 notify'
    WHEN m.envelopeto LIKE '%-volunteers@%'           THEN '4-5 volunteers'
    WHEN m.envelopeto LIKE '%@users.ilovefreegle.org' THEN '2-3 direct'
    ELSE 'other'
  END                                                                            AS route,
  COUNT(*)                                                                       AS mails,
  SUM(m.textbody IS NULL OR m.textbody = '')                                     AS no_text_part,
  SUM(m.textbody LIKE '%automated response from trashnothing.com%')              AS automated,
  SUM(m.textbody LIKE '%trashnothing.com/pics%')                                 AS photo_links,
  SUM(m.textbody REGEXP 'https?://(www\\.)?trashnothing\\.com/')                  AS any_tn_link,
  SUM(m.textbody REGEXP '(?i)sent from (the trash nothing|my account on trashnothing)') AS tn_signature,
  SUM(m.textbody LIKE '%collection time%')                                       AS collection_times,
  SUM(m.textbody REGEXP '(?m)^>')                                                AS quoted_lines,
  SUM(LENGTH(cm.message) < 0.8 * LENGTH(m.textbody))                             AS stripped_over_20pc,
  ROUND(AVG(LENGTH(cm.message)))                                                 AS avg_kept_chars,
  ROUND(AVG(LENGTH(m.textbody)))                                                 AS avg_text_chars
FROM messages m
JOIN chat_messages_byemail cme ON cme.msgid = m.id
JOIN chat_messages cm          ON cm.id = cme.chatmsgid
WHERE m.arrival >= @since AND m.arrival <= @until
  AND m.fromaddr LIKE '%trashnothing.com'
GROUP BY route
ORDER BY mails DESC;


-- Q10. TN's per-mail id and its meta header (found by the header scan, not by Q2's first version).
--      First: is X-Trash-Nothing-Log-Id unique to one mail? If it is, distinct_log_ids plus
--      without_log_id equals mails. Anything short of that is mails sharing a log id.
SELECT
  COUNT(*)                                                                                   AS mails,
  COUNT(DISTINCT REGEXP_SUBSTR(m.message, '(?im)^x-trash-nothing-log-id:[ \\t]*[0-9a-f-]{36}')) AS distinct_log_ids,
  SUM(m.message NOT REGEXP '(?im)^x-trash-nothing-log-id:')                                  AS without_log_id
FROM messages m
JOIN chat_messages_byemail cme ON cme.msgid = m.id
WHERE m.arrival >= @since AND m.arrival <= @until
  AND m.fromaddr LIKE '%trashnothing.com'
  AND m.message IS NOT NULL AND LENGTH(m.message) > 0;

--      Then: what X-Trash-Nothing-Meta holds, by route. The scan found 21 mails with it, in two
--      lengths, so these look like short flags. Check before pasting anything that looks personal.
SELECT
  CASE
    WHEN m.envelopeto LIKE 'notify-%'                                        THEN '1 notify'
    WHEN m.envelopeto LIKE '%-volunteers@%' AND m.subject LIKE 'Reporting %' THEN '5 report'
    WHEN m.envelopeto LIKE '%-volunteers@%'                                  THEN '4 volunteers'
    WHEN m.envelopeto LIKE '%@users.ilovefreegle.org'                        THEN '2-3 direct'
    ELSE 'other'
  END                                                                                   AS route,
  TRIM(SUBSTRING(REGEXP_SUBSTR(m.message, '(?im)^x-trash-nothing-meta:[^\\r\\n]*'), 22)) AS meta,
  COUNT(*)                                                                              AS mails
FROM messages m
JOIN chat_messages_byemail cme ON cme.msgid = m.id
WHERE m.arrival >= @since AND m.arrival <= @until
  AND m.fromaddr LIKE '%trashnothing.com'
  AND m.message REGEXP '(?im)^x-trash-nothing-meta:'
GROUP BY route, meta
ORDER BY mails DESC;


-- Q8. Mail to the volunteers (rows 4-5): does TN send one message to several communities'
--     volunteers? Q1 found 17 such messages from 7 senders in 14 chats. If distinct_texts is
--     well below messages, the same text went to several communities, and the feed needs to say
--     whether it is one message with several recipients or several messages.
SELECT
  CASE WHEN m.subject LIKE 'Reporting %' THEN '5 report' ELSE '4 volunteers' END AS route,
  COUNT(*)                                        AS messages,
  COUNT(DISTINCT cm.userid)                       AS senders,
  COUNT(DISTINCT cr.groupid)                      AS communities,
  COUNT(DISTINCT cm.userid, MD5(cm.message))      AS distinct_texts
FROM messages m
JOIN chat_messages_byemail cme ON cme.msgid = m.id
JOIN chat_messages cm          ON cm.id = cme.chatmsgid
JOIN chat_rooms cr             ON cr.id = cm.chatid
WHERE m.arrival >= @since AND m.arrival <= @until
  AND m.fromaddr LIKE '%trashnothing.com'
  AND m.envelopeto LIKE '%-volunteers@%'
GROUP BY route;
