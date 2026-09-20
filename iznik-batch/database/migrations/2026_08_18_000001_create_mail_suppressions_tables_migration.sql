-- Production idempotent SQL: the tables behind deferral-aware mail suppression.
--
-- Our bulk relay accepts a message with a 250 and only then discovers that the receiving
-- provider will not take it. When a provider starts deferring us - 2026-08-15, Yahoo began
-- 421-ing everything from the relay's IP with "[TSS04] ... temporarily deferred due to
-- unexpected volume or user complaints" - the sending code sees nothing wrong and keeps
-- rendering mail into a queue that cannot drain.
--
-- mail_suppressions is the gate; mail_suppressed_counts is what release needs in order to
-- send one catch-up rather than replaying the backlog.
--
-- All CREATE TABLE IF NOT EXISTS, so this is safe to re-run.
-- See 2026_08_18_000001_create_mail_suppressions_tables.php.

CREATE TABLE IF NOT EXISTS `mail_suppressions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  -- mxgroup = the relay host family that deferred us (the row carrying the evidence);
  -- domain = a recipient domain seen deferring via that relay, derived as a child row;
  -- address = one mailbox, for the per-address quota-exceeded signal.
  `scope` enum('mxgroup','domain','address') NOT NULL,
  `value` varchar(255) NOT NULL,
  `parentid` bigint unsigned DEFAULT NULL,
  -- The provider's own words, kept verbatim.
  `reason` text,
  `provider` varchar(64) DEFAULT NULL,
  -- Arrival time of the oldest still-deferred message: the "delayed since" date.
  `deferred_since` timestamp NULL DEFAULT NULL,
  `first_seen` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- NULL while active.
  `released_at` timestamp NULL DEFAULT NULL,
  `message_count` int unsigned NOT NULL DEFAULT '0',
  -- Release needs two consecutive clear scans.
  `clear_scans` tinyint unsigned NOT NULL DEFAULT '0',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- Holds the value only while the row is active, so the unique key below
  -- enforces one live suppression per target. MySQL has no partial indexes,
  -- and repeated NULLs do not collide, so history keeps as many released
  -- rows for the same target as it likes.
  `active_value` varchar(255) GENERATED ALWAYS AS (if((`released_at` is null),`value`,NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `scope_active_value` (`scope`,`active_value`),
  KEY `scope_value_released` (`scope`,`value`,`released_at`),
  KEY `released_at` (`released_at`),
  KEY `parentid` (`parentid`),
  CONSTRAINT `mail_suppressions_parentid_foreign` FOREIGN KEY (`parentid`) REFERENCES `mail_suppressions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Targets we must not generate mail for while a provider is deferring us';

CREATE TABLE IF NOT EXISTS `mail_suppressed_counts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned NOT NULL,
  -- The emailType already passed to EmailSpoolerService::spool().
  `emailtype` varchar(32) NOT NULL,
  -- Which suppression was in force when we declined, recorded at the time.
  `suppressionid` bigint unsigned DEFAULT NULL,
  `count` int unsigned NOT NULL DEFAULT '0',
  `firstat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastat` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Claimed by the catch-up pass before it sends.
  `caughtup_at` timestamp NULL DEFAULT NULL,
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `userid_emailtype` (`userid`,`emailtype`),
  KEY `caughtup_at` (`caughtup_at`),
  KEY `suppressionid` (`suppressionid`),
  CONSTRAINT `mail_suppressed_counts_suppressionid_foreign` FOREIGN KEY (`suppressionid`) REFERENCES `mail_suppressions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mail_suppressed_counts_userid_foreign` FOREIGN KEY (`userid`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='What we declined to generate per member, so release can send one catch-up';
