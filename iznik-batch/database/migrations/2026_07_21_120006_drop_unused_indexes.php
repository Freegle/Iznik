<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop 23 secondary indexes that no query uses.
     *
     * Identified by combining two independent signals, because neither alone is
     * trustworthy:
     *
     *  1. performance_schema.table_io_waits_summary_by_index_usage read counters
     *     collected from ALL THREE production database nodes and summed. A single
     *     node is not enough - the node reachable through the live tunnel serves
     *     almost no read traffic, so its zeros are meaningless.
     *  2. A read of the Go API and Laravel batch code for every candidate, to rule
     *     out low-frequency paths that a two-week sample would miss.
     *
     * Every index below has zero reads across all three nodes AND no query in the
     * code that could use it, is not UNIQUE, and does not back a foreign key.
     *
     * Indexes deliberately NOT dropped despite reading zero: email_tracking_images
     * .email_tracking_id (CASCADE FK), paf_addresses.udprn (UNIQUE plus manual PAF
     * import dedup), messages.envelopefrom / subject / message-id (incoming-mail
     * paths that are simply quiet), microactions.searchterm1 / searchterm2 (FK).
     *
     * See the matching production SQL in the accompanying _migration.sql, which is
     * what should actually be run against live - these are large, hot tables and
     * the drops want to be done node-by-node (RSU), not as a cluster-wide TOI ALTER.
     */
    private const INDEXES = [
        // chat_roster: 2.80GB of index against 1.24GB of data, and the table is
        // written on essentially every chat interaction, so this is mostly a write
        // saving rather than a disk one.
        ['chat_roster', 'lastip'],
        ['chat_roster', 'lastmsg'],
        ['chat_roster', 'lastmsgnotified'],
        ['chat_roster', 'date'],
        ['chat_roster', 'status'],

        // messages: leftover email plumbing. Geo search goes via messages_spatial's
        // SPATIAL index, so the plain lat/lng b-trees cannot serve it.
        ['messages', 'fromaddr'],
        ['messages', 'envelopeto'],
        ['messages', 'fromup'],
        ['messages', 'sourceheader'],
        ['messages', 'lat'],
        ['messages', 'lng'],
        ['messages', 'retrylastfailure'],

        ['users_searches', 'locationid'],
        ['users_emails', 'md5hash'],
        ['users_emails', 'viewed'],
        ['users', 'firstname_2'],
        ['users', 'gotrealemail'],
        ['users', 'suspectcount'],
        ['locations', 'osm_id'],
        ['locations', 'newareaid'],
        ['audits', 'audits_auditable_type_auditable_id_index'],
        ['engage', 'timestamp'],
        ['memberships_history', 'date'],
    ];

    private function indexExists(string $table, string $index): bool
    {
        if (!Schema::hasTable($table)) {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    public function up(): void
    {
        foreach (self::INDEXES as [$table, $index]) {
            if ($this->indexExists($table, $index)) {
                DB::statement(sprintf('ALTER TABLE `%s` DROP INDEX `%s`', $table, $index));
            }
        }
    }

    /**
     * Recreate the indexes as they were, so the change is reversible.
     */
    public function down(): void
    {
        $definitions = [
            'chat_roster.lastip' => '`lastip`',
            'chat_roster.lastmsg' => '`lastmsgseen`',
            'chat_roster.lastmsgnotified' => '`lastmsgnotified`',
            'chat_roster.date' => '`date`',
            'chat_roster.status' => '`status`',
            'messages.fromaddr' => '`fromaddr`, `subject`',
            'messages.envelopeto' => '`envelopeto`',
            'messages.fromup' => '`fromip`',
            'messages.sourceheader' => '`sourceheader`',
            'messages.lat' => '`lat`',
            'messages.lng' => '`lng`',
            'messages.retrylastfailure' => '`retrylastfailure`',
            'users_searches.locationid' => '`locationid`',
            'users_emails.md5hash' => '`md5hash`',
            'users_emails.viewed' => '`viewed`',
            'users.firstname_2' => '`firstname`, `lastname`',
            'users.gotrealemail' => '`gotrealemail`',
            'users.suspectcount' => '`suspectcount`',
            'locations.osm_id' => '`osm_id`',
            'locations.newareaid' => '`newareaid`',
            'audits.audits_auditable_type_auditable_id_index' => '`auditable_type`, `auditable_id`',
            'engage.timestamp' => '`timestamp`',
            'memberships_history.date' => '`added`',
        ];

        foreach (self::INDEXES as [$table, $index]) {
            if (!Schema::hasTable($table) || $this->indexExists($table, $index)) {
                continue;
            }
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD KEY `%s` (%s)',
                $table,
                $index,
                $definitions["$table.$index"]
            ));
        }
    }
};
