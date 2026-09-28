-- Production idempotent SQL: drop the progress table of the finished legacy-upload
-- copy to object storage. Safe to re-run.
-- See 2026_09_29_000001_drop_image_store_migration_table.php.

DROP TABLE IF EXISTS `image_store_migration`;
