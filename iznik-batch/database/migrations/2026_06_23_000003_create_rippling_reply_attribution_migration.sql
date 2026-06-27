-- Production idempotent SQL: rippling_reply_attribution (sysadmin reply-source KPI).
-- One row per (post, replier) first Interested reply, snapshotting AT REPLY TIME whether the
-- replier was an established home-group member. Captured by the Go reply handler because the
-- Nuxt reply flow joins the group in order to reply, so the membership state must be frozen at
-- the reply instant (and a join made to reply is excluded by the join-grace in the capture).
CREATE TABLE IF NOT EXISTS rippling_reply_attribution (
    msgid           BIGINT UNSIGNED NOT NULL,
    userid          BIGINT UNSIGNED NOT NULL,
    replied_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    was_home_member TINYINT(1) NOT NULL,
    PRIMARY KEY (msgid, userid),
    KEY rra_replied_at (replied_at)
);
