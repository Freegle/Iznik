-- Production SQL: item_desirability + messages_desirability. NOT YET APPLIED.
--
-- Per-item-type demand scores and per-message applications of them, written by
-- desirability:import-artifact (operator, offline-built file) and
-- desirability:score-new (hourly scheduler). Nothing user-facing reads these
-- yet; they are the data layer for ranking, digests and expectation-setting to
-- build on.
--
-- CREATE TABLE has nothing to build: no scan, no lock on anything hot, returns
-- immediately. The messages_desirability foreign key is declared in the CREATE,
-- so there is no ALTER against `messages` and none of the INPLACE-vs-COPY
-- problem an FK added later would bring (MySQL 8 only does an FK add INPLACE
-- with foreign_key_checks=0; with checks on it silently copies the table).
--
-- WHY bucket IS DERIVED FROM A POSTERIOR. low/high are only assigned when the
-- gamma posterior over the item's lift clears the bound with 80% confidence;
-- a thinly-measured or near-boundary item is medium by construction, so a
-- score of 1.01 vs 0.99 can never flip a bucket on noise.
--
-- WHY lift_views IS NOT A RANKING COLUMN. Desirable items are taken quickly,
-- which cuts short the time they collect views - given equal exposure they end
-- with FEWER views. lift_views measures attention-while-open; rank by
-- lift_replies.
--
-- The embedding column holds 256 little-endian float32 (1024 bytes), the same
-- recipe as the embedding sidecar, present only on the ~20k reference rows the
-- cold-start kNN searches.
--
-- After applying, the operator imports the artifact (path from the analysis
-- hand-over):
--   php artisan desirability:import-artifact /path/to/artifact.jsonl

CREATE TABLE IF NOT EXISTS item_desirability (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    canonical VARCHAR(191) NOT NULL,
    cluster_rep VARCHAR(191) NULL,
    lift_replies DECIMAL(8, 4) NOT NULL,
    evidence DECIMAL(10, 2) NOT NULL,
    lift_views DECIMAL(8, 4) NULL,
    taken_rate DECIMAL(5, 4) NULL,
    n_posts INT NOT NULL DEFAULT 0,
    bucket ENUM('low', 'medium', 'high') NOT NULL DEFAULT 'medium',
    embedding BLOB NULL,
    model_version VARCHAR(50) NOT NULL,
    built_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY item_desirability_canonical_model_version_unique (canonical, model_version),
    KEY item_desirability_model_version_bucket_index (model_version, bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages_desirability (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    msgid BIGINT UNSIGNED NOT NULL,
    score DECIMAL(8, 4) NOT NULL,
    bucket ENUM('low', 'medium', 'high') NOT NULL,
    source ENUM('exact', 'knn', 'default') NOT NULL,
    matched_canonical VARCHAR(191) NULL,
    model_version VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY messages_desirability_msgid_model_version_unique (msgid, model_version),
    KEY messages_desirability_created_at_index (created_at),
    CONSTRAINT messages_desirability_msgid_foreign FOREIGN KEY (msgid)
        REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
