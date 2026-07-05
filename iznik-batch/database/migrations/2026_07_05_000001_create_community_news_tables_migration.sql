-- Production idempotent SQL: Community News (area-based local-news digest).
-- Two tables backing the weekly area email + the ChitChat engagement trial.
--   community_news_areas: clusters of communitynews-enabled Freegle groups, keyed
--     by anchorgroupid (the lowest enabled groupid in the cluster).
--   community_news_items: researched nuggets, drip-posted to ChitChat and/or emailed.
-- CREATE TABLE IF NOT EXISTS is naturally idempotent. Run once on production
-- BEFORE deploying.
CREATE TABLE IF NOT EXISTS community_news_areas (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    anchorgroupid BIGINT UNSIGNED NOT NULL,
    name VARCHAR(255) NOT NULL,
    intro TEXT NULL DEFAULT NULL,
    lat DOUBLE NOT NULL,
    lng DOUBLE NOT NULL,
    groupids LONGTEXT NOT NULL,
    groupcount INT UNSIGNED NOT NULL DEFAULT 0,
    lastresearched TIMESTAMP NULL DEFAULT NULL,
    lastposted TIMESTAMP NULL DEFAULT NULL,
    lastemailed TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY community_news_areas_anchorgroupid_unique (anchorgroupid),
    KEY community_news_areas_lastresearched_index (lastresearched),
    KEY community_news_areas_lastposted_index (lastposted),
    KEY community_news_areas_lastemailed_index (lastemailed)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS community_news_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    areaid BIGINT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    snippet TEXT NOT NULL,
    url VARCHAR(1024) NULL DEFAULT NULL,
    source VARCHAR(255) NULL DEFAULT NULL,
    researched_at TIMESTAMP NULL DEFAULT NULL,
    newsfeedid BIGINT UNSIGNED NULL DEFAULT NULL,
    posted_at TIMESTAMP NULL DEFAULT NULL,
    emailed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY community_news_items_areaid_posted_at_index (areaid, posted_at),
    KEY community_news_items_areaid_emailed_at_index (areaid, emailed_at),
    KEY community_news_items_newsfeedid_index (newsfeedid),
    CONSTRAINT community_news_items_areaid_foreign FOREIGN KEY (areaid)
        REFERENCES community_news_areas (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
