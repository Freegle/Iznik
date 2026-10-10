-- Production SQL: messages_outcomes.feedback + index. Run BEFORE deploying the Go that reads it.
--
-- The Feedback badge (ModTools /session and /group/work) scanned ~148k unreviewed outcomes to
-- find ~400 with a real comment, 0.9s each time. With this index it reads the ~400 directly.
--
-- Statement 1 is INSTANT (virtual column, no row rewrite) and safe under TOI.
-- Statement 2 builds an index on a large table: run it NODE BY NODE under RSU rather than letting
-- TOI serialise cluster-wide writes for the build. Avoid db1's 04:00-04:17 backup window.

SET SESSION lock_wait_timeout = 5;

-- 1.
ALTER TABLE messages_outcomes ADD COLUMN feedback TINYINT(1) GENERATED ALWAYS AS (
  comments IS NOT NULL AND comments <> '' AND comments NOT IN (
    'Sorry, this is no longer available.',
    'Thanks, this has now been taken.',
    'Thanks, I''m no longer looking for this.',
    'Sorry, this has now been taken.',
    'Thanks for the interest, but this has now been taken.',
    'Thanks, these have now been taken.',
    'Thanks, this has now been received.',
    'Withdrawn on user unsubscribe',
    'Auto-Expired')) VIRTUAL, ALGORITHM=INSTANT;

-- 2. On each node in turn:
SET SESSION wsrep_OSU_method = 'RSU';
ALTER TABLE messages_outcomes ADD INDEX feedback_reviewed_timestamp (feedback, reviewed, timestamp, happiness), ALGORITHM=INPLACE, LOCK=NONE;
SET SESSION wsrep_OSU_method = 'TOI';

-- Verify.
ANALYZE TABLE messages_outcomes;
SELECT feedback, COUNT(*) FROM messages_outcomes WHERE timestamp >= CURDATE() - INTERVAL 31 DAY GROUP BY feedback;
EXPLAIN SELECT COUNT(*) FROM messages_outcomes mo
 WHERE mo.feedback = 1 AND mo.reviewed = 0 AND mo.timestamp >= CURDATE() - INTERVAL 31 DAY
   AND (mo.happiness = 'Happy' OR mo.happiness IS NULL);
-- Wanted: key=feedback_reviewed_timestamp, rows in the hundreds (not ~150,000).
