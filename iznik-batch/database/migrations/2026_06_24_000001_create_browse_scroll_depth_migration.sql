-- Production idempotent SQL: browse_scroll_depth (sysadmin "Scrolling" tab - browse-feed scroll depth).
-- One row per browse-feed session recording the furthest 0-based feed position reached. Powers the
-- scroll-depth curve (fraction of sessions reaching position N), the browse analogue of digest
-- click-by-position. Written by the Go apiv2 scroll-depth handler; userid is NULL for logged-out.
CREATE TABLE IF NOT EXISTS browse_scroll_depth (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    userid          BIGINT UNSIGNED NULL,
    max_position    INT UNSIGNED NOT NULL,
    items_available INT UNSIGNED NULL,
    context         VARCHAR(16) NULL,
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY bsd_created_at (created_at),
    KEY bsd_userid (userid)
);
