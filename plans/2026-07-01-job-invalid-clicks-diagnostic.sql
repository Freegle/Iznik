-- Diagnostic: size the WhatJobs "invalid click" buckets from our own logs_jobs.
--
-- Context: WhatJobs decides invalid/billable on THEIR side from the outbound
-- redirect. We can't see their per-click reasons, but their single biggest
-- exclusion is rule #3 in the Publisher Program Terms:
--   "any repeat clicks from the same user, IP, or user agent, on the same
--    advert within the same user session or within 24 hours of the first click"
-- logs_jobs records every click (no unique index) with userid, jobid, timestamp,
-- so same (userid, jobid) recurring within 24h is a direct proxy for that bucket.
--
-- Caveats:
--  * Only identified-user clicks (userid NOT NULL) can be deduped this way.
--    Anonymous clicks are deduped by WhatJobs on IP/user-agent, which we do not
--    log -- query (0) reports that share separately.
--  * LAG measures the gap to the PREVIOUS click; WhatJobs measures from the
--    FIRST click, so long chains are slightly under-counted here (fine as a proxy).
--  * Read-only. Run against prod (db3 primary).

-- (0) How much of our click volume is even attributable to a user? -------------
SELECT
    COUNT(*)                                                   AS total_clicks_14d,
    SUM(userid IS NULL)                                        AS anonymous_clicks,
    ROUND(100 * SUM(userid IS NULL) / COUNT(*), 1)             AS pct_anonymous,
    SUM(jobid IS NULL OR jobid = 0)                            AS missing_jobid
FROM logs_jobs
WHERE timestamp >= NOW() - INTERVAL 14 DAY;

-- (1) Repeat-click rate: share of identified-user clicks that repeat the SAME
--     advert by the SAME user within 24h of the previous click on it. ----------
SELECT
    COUNT(*)                                                          AS user_clicks_14d,
    SUM(prev_ts IS NOT NULL AND ts <= prev_ts + INTERVAL 24 HOUR)     AS repeat_within_24h,
    ROUND(100 * SUM(prev_ts IS NOT NULL AND ts <= prev_ts + INTERVAL 24 HOUR)
              / COUNT(*), 1)                                          AS pct_repeat_24h
FROM (
    SELECT
        timestamp AS ts,
        LAG(timestamp) OVER (PARTITION BY userid, jobid ORDER BY timestamp) AS prev_ts
    FROM logs_jobs
    WHERE userid IS NOT NULL
      AND jobid IS NOT NULL AND jobid <> 0
      AND timestamp >= NOW() - INTERVAL 14 DAY
) t;

-- (2) Tightness of the repeats: distinguishes accidental double-clicks (seconds)
--     from genuine return visits (hours). A big <=5s bucket => debounce fixes it;
--     a big minutes/hours bucket => the /jobs re-exposure funnel is the driver. --
SELECT
    CASE
        WHEN gap_s <= 5      THEN '1: <=5s  (double-click)'
        WHEN gap_s <= 300    THEN '2: <=5m  (rapid re-click)'
        WHEN gap_s <= 3600   THEN '3: <=1h  (same session)'
        WHEN gap_s <= 86400  THEN '4: <=24h (same day)'
        ELSE                      '5: >24h  (valid return)'
    END                                                    AS repeat_bucket,
    COUNT(*)                                               AS clicks
FROM (
    SELECT
        TIMESTAMPDIFF(SECOND,
            LAG(timestamp) OVER (PARTITION BY userid, jobid ORDER BY timestamp),
            timestamp)                                     AS gap_s
    FROM logs_jobs
    WHERE userid IS NOT NULL
      AND jobid IS NOT NULL AND jobid <> 0
      AND timestamp >= NOW() - INTERVAL 14 DAY
) t
WHERE gap_s IS NOT NULL
GROUP BY repeat_bucket
ORDER BY repeat_bucket;

-- (3) Does the /jobs funnel drive repeats? Compare repeat share by placement/page.
--     (Only meaningful for rows tagged since PR #916; legacy rows are NULL/NULL.) -
SELECT
    COALESCE(placement, 'untagged')                                  AS placement,
    COUNT(*)                                                         AS clicks,
    SUM(prev_ts IS NOT NULL AND ts <= prev_ts + INTERVAL 24 HOUR)    AS repeat_24h,
    ROUND(100 * SUM(prev_ts IS NOT NULL AND ts <= prev_ts + INTERVAL 24 HOUR)
              / COUNT(*), 1)                                         AS pct_repeat_24h
FROM (
    SELECT
        placement,
        timestamp AS ts,
        LAG(timestamp) OVER (PARTITION BY userid, jobid ORDER BY timestamp) AS prev_ts
    FROM logs_jobs
    WHERE userid IS NOT NULL
      AND jobid IS NOT NULL AND jobid <> 0
      AND timestamp >= NOW() - INTERVAL 14 DAY
) t
GROUP BY placement
ORDER BY clicks DESC;
