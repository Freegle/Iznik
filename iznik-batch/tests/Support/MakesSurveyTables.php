<?php

namespace Tests\Support;

/**
 * Makes the member survey tables for a test that reads or seeds them, when they are not there.
 *
 * The tables belong to the member surveys and are made by that part's migration. The idea
 * generator works without them, and its tests run on a database built from its own migrations only,
 * where they do not exist. The statements are those of the surveys' production SQL file, character
 * for character, so a database where that migration has run is left as it is.
 *
 * MySQL commits on any CREATE TABLE, even for a table that already exists. Run on the test's own
 * connection after DatabaseTransactions has opened its transaction, that would end the transaction:
 * nothing the test wrote would be rolled back, and the rows would reach later test classes. So this
 * never touches the test's connection. It opens a second one, on the same write host, looks in
 * information_schema, and creates what is missing there. It does this once per process, before the
 * deletes in a test's setUp. No test may run DDL (CREATE, ALTER, DROP or TRUNCATE) on the default
 * connection, for any table (.claude/rules/laravel-batch-traps.md).
 */
trait MakesSurveyTables
{
    /** Where the "already checked" flag is kept: a static property in a trait is one per class that uses it. */
    private const SURVEY_TABLES_CHECKED = 'ux_survey_tables_checked';

    /** @return array<string, string> table name => the CREATE TABLE IF NOT EXISTS statement */
    private static function surveyTableStatements(): array
    {
        return [
            'ux_prompts' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `ux_prompts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(16) NOT NULL,
  `flow` varchar(32) DEFAULT NULL,
  `question` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `options` json NOT NULL,
  `images` json DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'open',
  `max_responses` int unsigned NOT NULL DEFAULT '200',
  `settings` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ux_prompts_status_kind` (`status`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'ux_problems' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `ux_problems` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `flow` varchar(32) DEFAULT NULL,
  `kind` varchar(16) NOT NULL DEFAULT 'problem',
  `group_name` varchar(40) DEFAULT NULL,
  `title` varchar(160) NOT NULL,
  `description` text,
  `prompt_id` bigint unsigned DEFAULT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `mentions` int unsigned NOT NULL DEFAULT '0',
  `weight` decimal(6,3) DEFAULT NULL,
  `evidence` json DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ux_problems_flow_status` (`flow`,`status`),
  KEY `ux_problems_prompt` (`prompt_id`),
  KEY `ux_problems_group` (`kind`,`group_name`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
            'ux_prompt_responses' => <<<'SQL'
CREATE TABLE IF NOT EXISTS `ux_prompt_responses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `prompt_id` bigint unsigned NOT NULL,
  `userid` bigint unsigned NOT NULL,
  `answer` json NOT NULL,
  `comment` text,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_prompt_responses_once` (`prompt_id`,`userid`),
  KEY `ux_prompt_responses_user_time` (`userid`,`timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        ];
    }

    /** Make the survey tables that are missing, on a second connection, once per process. */
    protected function makeSurveyTables(): void
    {
        if (isset($GLOBALS[self::SURVEY_TABLES_CHECKED])) {
            return;
        }

        // The same configuration as the test's connection, but a connection of its own, to the first
        // write host: never DB::connection()->getPdo(), and never a read host that could lag.
        $cfg = (array) config('database.connections.mysql');
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['write']['host'][0], $cfg['port'], $cfg['database']);
        $pdo = new \PDO($dsn, (string) $cfg['username'], (string) $cfg['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        // Asked of the write host, not a replica: a table created a moment ago may not have reached one.
        $have = $pdo->prepare('SELECT table_name AS name FROM information_schema.tables WHERE table_schema = ? AND table_name IN (?, ?, ?)');
        $have->execute([$cfg['database'], 'ux_prompts', 'ux_problems', 'ux_prompt_responses']);
        $present = array_column($have->fetchAll(\PDO::FETCH_ASSOC), 'name');

        foreach (self::surveyTableStatements() as $table => $statement) {
            if (! in_array($table, $present, true)) {
                $pdo->exec($statement);
            }
        }

        $GLOBALS[self::SURVEY_TABLES_CHECKED] = true;
    }
}
