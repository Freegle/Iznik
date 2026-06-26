-- Production idempotent SQL: rename ripple_algorithm_metrics -> rippling_algorithm_metrics.
SET @r := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ripple_algorithm_metrics');
SET @n := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'rippling_algorithm_metrics');
SET @sql := IF(@r > 0 AND @n = 0, 'RENAME TABLE ripple_algorithm_metrics TO rippling_algorithm_metrics', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
