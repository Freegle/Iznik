-- Production idempotent SQL: the tables behind the ModTools Partnerships page.
--
-- A partnership is a sponsorship deal with a local authority. The Freegle groups it covers
-- are derived from the authority/group boundary overlap and cached in partnerships_groups;
-- saving a partnership syncs a groups_sponsorship row per covered group, which is what the
-- member site already reads.
--
-- Money is tracked in three places on purpose: partnerships.amount is the whole deal,
-- partnerships_years is how a multi-year deal splits across UK financial years, and
-- partnerships_payments is what has actually been invoiced and paid.
--
-- All CREATE TABLE IF NOT EXISTS, so this is safe to re-run.
-- See 2026_08_08_000001_create_partnerships_tables.php.

CREATE TABLE IF NOT EXISTS `partnerships` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `authorityid` bigint unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `tagline` varchar(255) DEFAULT NULL,
  `description` text,
  `linkurl` varchar(255) DEFAULT NULL,
  `imageurl` varchar(255) DEFAULT NULL,
  `startdate` date NOT NULL,
  `enddate` date NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `agreed` tinyint(1) NOT NULL DEFAULT '0',
  `agreeddate` date DEFAULT NULL,
  `contactname` varchar(255) DEFAULT NULL,
  `contactemail` varchar(255) DEFAULT NULL,
  `notes` text,
  `visible` tinyint(1) NOT NULL DEFAULT '1',
  `created` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `modified` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `authorityid` (`authorityid`),
  KEY `enddate` (`enddate`),
  CONSTRAINT `partnerships_authorityid_foreign` FOREIGN KEY (`authorityid`) REFERENCES `authorities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Sponsorship deals with local authorities';

CREATE TABLE IF NOT EXISTS `partnerships_years` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partnershipid` bigint unsigned NOT NULL,
  -- The calendar year the financial year starts in, so 2026 means 2026/27.
  `financialyear` int NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `partnershipid_financialyear` (`partnershipid`,`financialyear`),
  CONSTRAINT `partnerships_years_partnershipid_foreign` FOREIGN KEY (`partnershipid`) REFERENCES `partnerships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Explicit per-financial-year split of a multi-year deal';

CREATE TABLE IF NOT EXISTS `partnerships_payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partnershipid` bigint unsigned NOT NULL,
  `date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `paid` date DEFAULT NULL,
  `reference` varchar(255) DEFAULT NULL,
  `notes` text,
  PRIMARY KEY (`id`),
  KEY `partnershipid` (`partnershipid`),
  CONSTRAINT `partnerships_payments_partnershipid_foreign` FOREIGN KEY (`partnershipid`) REFERENCES `partnerships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Invoices raised against a partnership and what has been paid';

CREATE TABLE IF NOT EXISTS `partnerships_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partnershipid` bigint unsigned NOT NULL,
  `groupid` bigint unsigned NOT NULL,
  `sponsorshipid` bigint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partnershipid_groupid` (`partnershipid`,`groupid`),
  KEY `groupid` (`groupid`),
  KEY `partnerships_groups_sponsorshipid_foreign` (`sponsorshipid`),
  CONSTRAINT `partnerships_groups_partnershipid_foreign` FOREIGN KEY (`partnershipid`) REFERENCES `partnerships` (`id`) ON DELETE CASCADE,
  CONSTRAINT `partnerships_groups_groupid_foreign` FOREIGN KEY (`groupid`) REFERENCES `groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `partnerships_groups_sponsorshipid_foreign` FOREIGN KEY (`sponsorshipid`) REFERENCES `groups_sponsorship` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Groups covered by a partnership and their sponsorship rows';

CREATE TABLE IF NOT EXISTS `partnerships_reminders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `partnershipid` bigint unsigned NOT NULL,
  `type` varchar(32) NOT NULL,
  `sent` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `partnershipid_type` (`partnershipid`,`type`),
  CONSTRAINT `partnerships_reminders_partnershipid_foreign` FOREIGN KEY (`partnershipid`) REFERENCES `partnerships` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Expiry reminder mails sent to the Partnerships team';

CREATE TABLE IF NOT EXISTS `partnerships_statsjobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `userid` bigint unsigned DEFAULT NULL,
  `authorityids` text NOT NULL,
  `quarter` varchar(255) NOT NULL DEFAULT '3 months ago',
  `status` enum('Pending','Running','Ready','Failed') NOT NULL DEFAULT 'Pending',
  `error` text,
  `requested` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started` timestamp NULL DEFAULT NULL,
  `completed` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `status_requested` (`status`,`requested`),
  KEY `userid` (`userid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Queued authority stats spreadsheet generation requests';

-- MEDIUMBLOB, not BLOB: a council spreadsheet with a postcode breakdown exceeds 64KB. The
-- bytes live here rather than on disk because the Go API serves the download and shares no
-- filesystem with the batch container that renders them.
CREATE TABLE IF NOT EXISTS `partnerships_statsfiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `jobid` bigint unsigned NOT NULL,
  `authorityid` bigint unsigned DEFAULT NULL,
  `filename` varchar(255) NOT NULL,
  `size` int unsigned NOT NULL DEFAULT '0',
  `content` mediumblob,
  PRIMARY KEY (`id`),
  KEY `jobid` (`jobid`),
  CONSTRAINT `partnerships_statsfiles_jobid_foreign` FOREIGN KEY (`jobid`) REFERENCES `partnerships_statsjobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Spreadsheets produced by a partnerships_statsjobs run';
