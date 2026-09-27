-- A moderator flagging one node of a stored automod run (messages_automod) as wrong. See
-- the .php migration in this directory for the full explanation; this is the idempotent
-- raw-SQL twin.

CREATE TABLE IF NOT EXISTS messages_automod_feedback (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    automodid BIGINT UNSIGNED NOT NULL COMMENT 'The messages_automod row this feedback is about.',
    node      VARCHAR(255) NOT NULL COMMENT 'The chart node flagged as wrong.',
    userid    BIGINT UNSIGNED NOT NULL COMMENT 'The moderator who gave the feedback.',
    created   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY messages_automod_feedback_automodid_index (automodid),
    CONSTRAINT messages_automod_feedback_automodid_foreign FOREIGN KEY (automodid)
        REFERENCES messages_automod (id) ON DELETE CASCADE,
    CONSTRAINT messages_automod_feedback_userid_foreign FOREIGN KEY (userid)
        REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='A moderator flagging one node of a stored automod run as wrong.';
