-- ============================================================================
-- RIPPLING EXPERIMENT MONITOR  (canonical, FIXED query - DO NOT regenerate)
-- Run verbatim against prod db3. Output shape is stable across runs.
-- ============================================================================

SELECT '=== 1. REPLY ATTRIBUTION (home vs via-rippling) per day ===' AS section;
-- Source of truth: rippling_reply_attribution, frozen at reply time.
-- was_home_member=0 => replier reached the post via rippling (not an established origin-group member).
SELECT DATE(replied_at)                                   AS day,
       COUNT(*)                                           AS replies,
       SUM(was_home_member = 0)                           AS via_rippling,
       SUM(was_home_member = 1)                           AS home_member,
       ROUND(100 * SUM(was_home_member = 0) / COUNT(*),1) AS pct_via_rippling
FROM rippling_reply_attribution
WHERE replied_at >= CURDATE() - INTERVAL 4 DAY
GROUP BY DATE(replied_at) ORDER BY day;

SELECT '=== 2. VIEWS on rippled posts per day (genuine_pageviews meaningful only from 2026-06-23 = instrumentation start) ===' AS section;
SELECT DATE(ml.timestamp)              AS day,
       COUNT(*)                        AS view_rows,
       SUM(COALESCE(ml.pageview,0)=1)  AS genuine_pageviews,
       COUNT(DISTINCT ml.msgid)        AS posts_viewed,
       COUNT(DISTINCT ml.userid)       AS viewers
FROM messages_likes ml
JOIN rippling_reach rr ON rr.msgid = ml.msgid
WHERE ml.type = 'View' AND ml.timestamp >= CURDATE() - INTERVAL 4 DAY
GROUP BY DATE(ml.timestamp) ORDER BY day;

SELECT '=== 3. ACTIVITY per day (joins / ripples / immediate mails) ===' AS section;
SELECT d AS day,
       MAX(auto_joins)       AS auto_joins,
       MAX(rippled_in)       AS rippled_in,
       MAX(immediate_mailed) AS immediate_mailed
FROM (
    SELECT DATE(timestamp) d, COUNT(*) auto_joins, NULL rippled_in, NULL immediate_mailed
      FROM logs WHERE type='Group' AND subtype='Joined' AND text='Rippled' AND timestamp >= CURDATE()-INTERVAL 4 DAY
      GROUP BY DATE(timestamp)
    UNION ALL
    SELECT day d, NULL, MAX(IF(event='rippled_in',count,NULL)), MAX(IF(event='immediate_mailed',count,NULL))
      FROM rippling_event_metrics WHERE day >= CURDATE()-INTERVAL 4 DAY GROUP BY day
) x GROUP BY d ORDER BY d;

SELECT '=== 4. reach rows total + last-hour activity ===' AS section;
SELECT (SELECT COUNT(*) FROM rippling_reach) AS reach_rows_total,
       (SELECT COUNT(*) FROM rippling_reply_attribution WHERE replied_at >= NOW()-INTERVAL 1 HOUR) AS replies_last_hr,
       (SELECT COUNT(*) FROM messages_notified WHERE notified_at >= NOW()-INTERVAL 1 HOUR) AS immediate_mails_last_hr;
