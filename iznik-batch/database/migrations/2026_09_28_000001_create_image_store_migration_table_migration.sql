-- Production idempotent SQL: progress of the legacy-upload copy to object storage
-- (images:migrate-legacy). One row per source table/column holding tusd upload ids,
-- carrying the id cursor and running counts. Nothing per file.
--
-- CREATE TABLE IF NOT EXISTS, so this is safe to re-run.
-- See 2026_09_28_000001_create_image_store_migration_table.php.

CREATE TABLE IF NOT EXISTS `image_store_migration` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `source` varchar(96) NOT NULL,
  `last_id` bigint unsigned NOT NULL DEFAULT 0,
  `verify_last_id` bigint unsigned NOT NULL DEFAULT 0,
  `copied` bigint unsigned NOT NULL DEFAULT 0,
  `present` bigint unsigned NOT NULL DEFAULT 0,
  `missing_source` bigint unsigned NOT NULL DEFAULT 0,
  `failed` bigint unsigned NOT NULL DEFAULT 0,
  `bytes` bigint unsigned NOT NULL DEFAULT 0,
  `verify_missing` bigint unsigned NOT NULL DEFAULT 0,
  `completed_at` timestamp NULL DEFAULT NULL,
  `verify_completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `image_store_migration_source_unique` (`source`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
