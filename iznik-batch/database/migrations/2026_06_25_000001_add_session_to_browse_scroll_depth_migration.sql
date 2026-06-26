-- Production SQL: add a unique per-session id to browse_scroll_depth so the Go
-- apiv2 handler upserts one row per browse session (keeping GREATEST(max_position))
-- as the debounced client reports scroll depth, instead of one INSERT per beacon.
-- Run once (MySQL has no ADD COLUMN IF NOT EXISTS); safe on the near-empty table.
ALTER TABLE browse_scroll_depth
    ADD COLUMN session VARCHAR(64) NULL AFTER id,
    ADD UNIQUE KEY bsd_session (session);
