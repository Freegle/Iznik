-- Idempotent production SQL for 2026_06_16_000001_create_messages_reach_table.php
--
-- Creates messages_reach: the current "rippled out" reach polygon for each active
-- post (a subset of messages_spatial), maintained by the ripple:expand engine.
-- `polygon` is SRID 3857 to match messages_spatial.point so ST_Contains works
-- without reprojection.
--
-- Safe to run multiple times: CREATE TABLE IF NOT EXISTS (and the table is
-- self-contained, so re-running is a no-op once it exists).
--
-- Run once on production BEFORE deploying the iznik-batch code that runs
-- ripple:expand. Requires CREATE / REFERENCES privilege on the iznik database.

CREATE TABLE IF NOT EXISTS messages_reach (
    msgid             BIGINT UNSIGNED  NOT NULL,
    lat               DOUBLE           NOT NULL,
    lng               DOUBLE           NOT NULL,
    polygon           GEOMETRY         NOT NULL SRID 3857,
    arrival           TIMESTAMP        NULL DEFAULT NULL,
    mode              VARCHAR(8)       NOT NULL DEFAULT 'drive',
    tick              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_ticks       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_freeglers   INT UNSIGNED     NOT NULL DEFAULT 0,
    max_drive_min     FLOAT            NULL DEFAULT NULL,
    schedule          LONGTEXT         NULL DEFAULT NULL,
    next_expansion_at TIMESTAMP        NULL DEFAULT NULL,
    status            ENUM('expanding','stopped','done') NOT NULL DEFAULT 'expanding',
    created_at        TIMESTAMP        NULL DEFAULT NULL,
    updated_at        TIMESTAMP        NULL DEFAULT NULL,
    PRIMARY KEY (msgid),
    SPATIAL INDEX messages_reach_polygon (polygon),
    INDEX messages_reach_next_expansion_at_index (next_expansion_at),
    INDEX messages_reach_status_index (status),
    CONSTRAINT messages_reach_msgid_foreign FOREIGN KEY (msgid)
        REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
