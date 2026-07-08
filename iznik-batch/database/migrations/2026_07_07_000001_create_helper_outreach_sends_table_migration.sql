-- Idempotent production DDL for helper_outreach_sends (dup guard for approved
-- email-outreach replies). Safe to run repeatedly.
CREATE TABLE IF NOT EXISTS `helper_outreach_sends` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `proposalid` BIGINT UNSIGNED NOT NULL,
  `gmail_thread_id` VARCHAR(255) NULL DEFAULT NULL,
  `gmail_message_id` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `helper_outreach_sends_proposalid_unique` (`proposalid`),
  CONSTRAINT `helper_outreach_sends_proposalid_foreign`
    FOREIGN KEY (`proposalid`) REFERENCES `helper_proposals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
