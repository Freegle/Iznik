-- Production SQL: messages_eee. NOT YET APPLIED.
--
-- Per-message electricals classification, written by eee:classify-new.
--
-- WHY A NEW TABLE. The classifications from the model-comparison research live only in a
-- dev-side SQLite file (EeeSqliteService). That was right for the research, where the point
-- was to hold several models' answers side by side and score them against human labels, but
-- it leaves nothing the public API can read. This is the production home.
--
-- CREATE TABLE has nothing to build: no scan, no lock on anything hot, returns immediately.
-- The foreign key is declared in the CREATE, so there is no ALTER against `messages` and
-- none of the INPLACE-vs-COPY problem that an FK added later would bring (MySQL 8 only does
-- an FK add INPLACE with foreign_key_checks=0; with checks on it silently copies the whole
-- table).
--
-- WHY is_eee IS NULLABLE, AND WHY NULL IS NOT FALSE. null means the model observed nothing
-- at all, which is a different fact from observing nothing electrical. Roughly 1% of OFFERs
-- have no photo, and some extractions come back empty. Any query that treats null as false
-- will understate the electrical share. Denominators must exclude nulls.
--
-- WHY THERE IS NO weight_kg COLUMN. Measured against volunteer quorum the model's weight is
-- 65% accurate and its size 72%, so neither can carry a published figure. The buckets are
-- stored because they are useful for coarse splits and for continuing to score the model,
-- but published tonnage comes from the `weights` reference table through the same cascade
-- iznik-server-go/item/impact.go already uses (exact items.weight match, then fuzzy weights
-- match, then popularity-weighted population average). On live that is 164 usable `weights`
-- rows and 2,084,416 `items` rows with a usable weight, which is a far better basis than a
-- 65% per-item guess.
--
-- WHY item_condition AND NOT condition. `condition` is reserved in MySQL 8.
--
-- WHY model AND prompt_version ARE IN THE UNIQUE KEY. So the same message can be held under
-- several models when comparing them, exactly as the SQLite table allowed. The page filters
-- to the production pair rather than assuming one row per message.
--
-- SIZING. ~1,560 distinct OFFERs a day on live (46,768 in 30 days, counted over `messages`;
-- counting `messages_groups` gives 5-6.5k/day but that is the rippling fan-out, one row per
-- group reached). So ~570k rows a year under one model.
CREATE TABLE IF NOT EXISTS messages_eee (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    msgid                   BIGINT UNSIGNED NOT NULL,
    attid                   BIGINT UNSIGNED NULL COMMENT 'messages_attachments.id actually classified - the primary photo',
    is_eee                  TINYINT(1) NULL COMMENT 'Material Focus line; null means unknown, never treat as false',
    is_eee_reason           VARCHAR(32) NULL COMMENT 'named_eee | named_not_eee | primary | distinct_function | supplementary | no_electrical_components',
    contains_eee_components TINYINT(1) NULL COMMENT 'Physically contains electrical parts, independent of is_eee',
    weee_category           TINYINT UNSIGNED NULL COMMENT 'EA reporting category 1-15',
    item_condition          ENUM('reusable','damaged','unsure') NULL COMMENT '93% accurate vs volunteer quorum - publishable',
    size_bucket             ENUM('tiny','small','medium','large','unsure') NULL COMMENT '72% accurate - coarse use only, never a precise figure',
    weight_bucket           ENUM('under_1kg','1_5kg','5_20kg','20_100kg','over_100kg','unsure') NULL COMMENT '65% accurate - not publishable as a figure; tonnage uses the weights table',
    electrical_components   TEXT NULL COMMENT 'Semicolon-separated raw component strings the model observed',
    model                   VARCHAR(64) NOT NULL COMMENT 'e.g. gemini-2.0-flash-lite',
    prompt_version          VARCHAR(16) NOT NULL,
    classified_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY messages_eee_msgid_model_prompt (msgid, model, prompt_version),
    KEY messages_eee_iseee_classified (is_eee, classified_at),
    KEY messages_eee_classified_at (classified_at),
    CONSTRAINT messages_eee_ibfk_1 FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Electricals classification per message, from eee:classify-new';

-- Verify.
SELECT
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages_eee')          AS table_present,
    (SELECT COUNT(*) FROM information_schema.STATISTICS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'messages_eee')          AS index_rows,
    (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'messages_eee')     AS fk_rows;
