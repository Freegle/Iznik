-- Production: the member-side queue for reach mail. Idempotent.
-- Written by the API when a member joins a group, moves, returns after a long
-- absence or switches to immediate mail; drained by mail:digest:unified --mode=reach.
CREATE TABLE IF NOT EXISTS rippling_reach_member_pending (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    userid BIGINT UNSIGNED NOT NULL,
    reason ENUM('joined','moved','returned','frequency') NOT NULL,
    added TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY userid (userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
