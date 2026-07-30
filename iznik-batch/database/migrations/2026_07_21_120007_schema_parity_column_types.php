<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bring column types into line with production.
     *
     * These are the cases where the migrations produce a WEAKER column than live -
     * smaller storage, less precision, or a looser type. The reverse cases, where
     * the migrations are ahead of production because a feature has not been
     * deployed yet, are deliberately left alone and listed at the bottom.
     *
     * The most consequential group is blob -> longblob on the image tables. A
     * MySQL `blob` truncates at 64 KB; production uses `longblob`. Any test or
     * local database built from the migrations would silently truncate real image
     * data, so image-handling code could pass locally and corrupt on live.
     */
    private function modify(string $table, string $column, string $definition): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, $column)) {
            return;
        }
        DB::statement(sprintf('ALTER TABLE `%s` MODIFY `%s` %s', $table, $column, $definition));
    }

    public function up(): void
    {
        // --- blob (64 KB) -> longblob. Silent truncation risk. ---
        foreach ([
            'chat_images', 'communityevents_images', 'groups_images', 'newsfeed_images',
            'newsletters_images', 'noticeboards_images', 'users_exports', 'users_images',
            'users_stories_images', 'volunteering_images',
        ] as $table) {
            $this->modify($table, 'data', 'LONGBLOB NULL');
        }
        $this->modify('messages_attachments', 'data', 'LONGBLOB NULL');

        // --- text (64 KB) -> longtext ---
        $this->modify('alerts', 'html', 'LONGTEXT NOT NULL');

        // --- undersized varchars ---
        $this->modify('email_tracking', 'subject', 'VARCHAR(500) NULL');
        $this->modify('email_tracking', 'tracking_id', 'VARCHAR(64) NOT NULL');
        $this->modify('messages_attachments', 'contenttype', 'VARCHAR(80) NOT NULL');

        // --- sub-second precision. Ordering and dedup depend on it. ---
        $this->modify('messages_groups', 'arrival', 'TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)');
        $this->modify('groups_digests', 'msgdate', 'TIMESTAMP(6) NULL');
        $this->modify('logs_events', 'clienttimestamp', 'TIMESTAMP(3) NULL');
        $this->modify(
            'logs_events',
            'timestamp',
            'TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3)'
        );

        // --- timestamps that are NOT NULL with a default on live ---
        $this->modify('email_tracking', 'created_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        $this->modify(
            'email_tracking',
            'updated_at',
            'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
        $this->modify('email_tracking_clicks', 'clicked_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        $this->modify('email_tracking_images', 'loaded_at', 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        $this->modify(
            'rippling_params',
            'updated_at',
            'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
        $this->modify('email_tracking', 'replied_at', 'DATETIME NULL');

        // --- loose types that are constrained enums on live ---
        $this->modify(
            'email_tracking',
            'replied_via',
            "ENUM('amp','website','email') NULL"
        );
        $this->modify(
            'messages',
            'spamtype',
            "ENUM('CountryBlocked','IPUsedForDifferentUsers','IPUsedForDifferentGroups',"
            . "'SubjectUsedForDifferentGroups','SpamAssassin','NotSpam','WorryWord','Greetings spam',"
            . "'Referenced known spammer','Known spam keyword','URL on DBL','BulkVolunteerMail',"
            . "'UsedOurDomain') NULL"
        );

        // --- enum value present on live but missing from the migrations ---
        // NOTE: MySQL drops the DEFAULT when a column is MODIFYed without one, so
        // every MODIFY below has to restate the production default explicitly.
        // Losing DEFAULT 0 on background_tasks.attempts silently broke task
        // processing (attempts became NULL, so `attempts + 1` was NULL too).
        $this->modify(
            'users_push_notifications',
            'type',
            "ENUM('Google','Firefox','Test','Android','IOS','FCMAndroid','FCMIOS','BrowserPush') "
            . "NOT NULL DEFAULT 'Google'"
        );

        // --- integer/float signedness and width ---
        $this->modify('users', 'tnuserid', 'BIGINT NULL');
        $this->modify('users', 'publishconsent', 'TINYINT NOT NULL DEFAULT 0');
        $this->modify('abtest', 'suggest', 'TINYINT(1) NOT NULL DEFAULT 0');
        $this->modify('search_history', 'groups', 'INT NULL');
        foreach ([
            ['rippling_algorithm_metrics', 'lifetime_days', 'FLOAT NOT NULL'],
            ['rippling_algorithm_metrics', 'max_minutes', 'FLOAT NOT NULL'],
            ['rippling_algorithm_metrics', 'replier_drive_min_p75', 'FLOAT NULL'],
            ['rippling_algorithm_metrics', 'replier_rank_p50', 'FLOAT NULL'],
            ['rippling_algorithm_metrics', 'reply_p50_hours', 'FLOAT NULL'],
            ['rippling_algorithm_metrics', 'reply_p75_hours', 'FLOAT NULL'],
            ['rippling_reach', 'max_drive_min', 'FLOAT NULL'],
        ] as [$table, $column, $definition]) {
            $this->modify($table, $column, $definition);
        }

        // --- nullability where live is looser ---
        $this->modify('background_tasks', 'attempts', 'INT UNSIGNED NULL DEFAULT 0');
        $this->modify('background_tasks', 'created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
        $this->modify('charities', 'created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');
        $this->modify(
            'charities',
            'updated_at',
            'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
        );
        $this->modify('chat_room_redirects', 'created_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP');

        /*
         * DELIBERATELY NOT CHANGED - the migrations are ahead of production here,
         * because the feature has not shipped yet. Reverting these to match live
         * would break pending work:
         *
         *   messages_outcomes.outcome          migrations add 'Partial'
         *   microactions.result                migrations add 'Suppress'
         *   messages_bulk_outreach.status      migrations add 'AutoAck', 'Bounced'
         *   email_tracking.clicked_link        migrations varchar(2048) vs live 500
         *   email_tracking_clicks.link_url     migrations varchar(2048) vs live 500
         *   email_tracking_clicks.user_agent   migrations varchar(512)  vs live 500
         *   jobs.body                          migrations text vs live varchar(256)
         *
         * These should reach production the normal way, by running the migration
         * that introduced them.
         */
    }

    public function down(): void
    {
        // Deliberately not reversed. Every change here widens a type or tightens a
        // constraint to match production; narrowing them again would risk
        // truncating data written in the meantime.
    }
};
