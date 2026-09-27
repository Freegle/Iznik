-- One row per (msgid, groupid): the current automod flowchart outcome for that post on
-- that group. An edit or a rerun replaces the row. See the .php migration in this
-- directory for the full explanation; this is the idempotent raw-SQL twin.

CREATE TABLE IF NOT EXISTS messages_automod (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    msgid         BIGINT UNSIGNED NOT NULL COMMENT 'The post reviewed.',
    groupid       BIGINT UNSIGNED NOT NULL COMMENT 'The group whose rules were applied.',
    mode          ENUM('shadow', 'approve') NOT NULL COMMENT 'shadow: recorded only. approve: could auto-approve.',
    chart_version VARCHAR(255) NOT NULL COMMENT 'The chart WorkflowDefinition version that produced this row.',
    verdict       ENUM('approve', 'hold') NOT NULL COMMENT 'The chart''s decision. Never reject.',
    end_node      VARCHAR(255) NOT NULL COMMENT 'The chart end node reached, e.g. APPROVE or HOLD_LOAN.',
    reason        VARCHAR(255) NULL COMMENT 'Moderator-facing explanation of the end node.',
    path          JSON NOT NULL COMMENT 'Every node visited: question, answer, confidence, evidence.',
    created       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY messages_automod_msgid_groupid_unique (msgid, groupid),
    KEY messages_automod_created_index (created),
    KEY messages_automod_groupid_created_index (groupid, created),
    CONSTRAINT messages_automod_msgid_foreign FOREIGN KEY (msgid)
        REFERENCES messages (id) ON DELETE CASCADE,
    CONSTRAINT messages_automod_groupid_foreign FOREIGN KEY (groupid)
        REFERENCES `groups` (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='One row per (msgid, groupid): the current automod flowchart outcome for that post on that group.';
