-- Production idempotent SQL: reach-engine labels storage.
--
--  * rippling_reach.reach_labels: the FRL2 label bytes (routing server's
--    compact per-region reach record), computed ONCE at the post's maximum
--    budget. Nullable; readers fall back to the stored cells until the
--    backfill (ripple:backfill-reach-labels) has run.
--  * rippling_reach_leaves: one row per (post, reached region), read in the
--    (leaf, msgid) direction for the road-aware browse prefilter.
--
-- Cheap: the column add is ALGORITHM=INSTANT (metadata only, same class as
-- the 2026-08-24 cell columns); the table starts empty.
--
-- Safe to re-run: both statements are guarded.

SET @have_col := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rippling_reach'
    AND COLUMN_NAME = 'reach_labels'
);
SET @sql := IF(@have_col = 0,
  'ALTER TABLE rippling_reach ADD COLUMN reach_labels MEDIUMBLOB NULL, ALGORITHM=INSTANT',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- FK matches the sibling tables (rippling_reach, rippling_reach_notified):
-- messages are hard-deleted by the purge 2 days after a terminal outcome, and
-- without the CASCADE the leaves rows would orphan permanently.
CREATE TABLE IF NOT EXISTS rippling_reach_leaves (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  msgid BIGINT UNSIGNED NOT NULL,
  leaf INT NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY msgid_leaf (msgid, leaf),
  KEY leaf_msgid (leaf, msgid),
  CONSTRAINT rippling_reach_leaves_msgid_foreign
    FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
