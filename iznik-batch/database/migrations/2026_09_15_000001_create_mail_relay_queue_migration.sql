-- Idempotent production SQL for 2026_09_15_000001_create_mail_relay_queue.php
--
-- mail_relay_queue: what is in the outbound relay's queue right now, per recipient
-- domain - how much is waiting, how old the oldest is, and how fast it is draining.
-- mail_suppressions says which provider is refusing us; this says what we are pacing
-- ourselves, which is the other half of why a member's email arrives late.
--
-- One row per domain, rewritten by every scan. A snapshot, not a history.
--
-- Safe to run more than once: CREATE TABLE IF NOT EXISTS.
-- Run once on production BEFORE deploying iznik-batch code that writes it and the
-- apiv2 build that reads it.

CREATE TABLE IF NOT EXISTS `mail_relay_queue` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain` VARCHAR(255) NOT NULL,
    `waiting` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Queued messages no provider has refused: waiting on our pacing',
    `deferred` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Queued messages a provider has refused with a 4xx',
    `oldest` TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Arrival time of the oldest waiting message',
    `deliveredperhour` INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Deliveries to this domain in the probe log window',
    `instance` VARCHAR(255) NULL DEFAULT NULL,
    `scanned` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        COMMENT 'When the probe that wrote this row ran',
    PRIMARY KEY (`id`),
    UNIQUE KEY `mail_relay_queue_domain_unique` (`domain`),
    KEY `mail_relay_queue_waiting_index` (`waiting`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Current outbound relay queue depth and drain rate, per recipient domain';
