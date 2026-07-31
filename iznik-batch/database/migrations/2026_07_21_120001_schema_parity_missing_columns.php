<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add columns that exist in production but were missing from the migrations.
     *
     * The migrations are meant to be the single source of truth for the schema
     * (see CLAUDE.md), but a comparison of information_schema between production
     * and a freshly-migrated database found columns that only exist on live. That
     * drift means test databases are built to a different shape than production,
     * so a query can pass in CI and fail on live (or vice versa).
     *
     * None of these columns are written by current code. They are added here to
     * make the schemas match, NOT because they are wanted - see the PR for a
     * suggested follow-up to retire the legacy ones (covidconfirmed,
     * precovidmoderated, norfolkmsgid, suspect*) from production instead.
     */
    public function up(): void
    {
        if (!Schema::hasColumn('chat_messages', 'norfolkmsgid')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                // Legacy Norfolk-council pilot linkage. No current reader or writer.
                $table->unsignedBigInteger('norfolkmsgid')->nullable();
            });
        }

        if (!Schema::hasColumn('groups', 'precovidmoderated')) {
            Schema::table('groups', function (Blueprint $table) {
                // Snapshot of the moderation setting taken before the 2020 COVID change.
                $table->boolean('precovidmoderated')->default(false);
            });
        }

        if (!Schema::hasColumn('messages_attachments', 'identification')) {
            Schema::table('messages_attachments', function (Blueprint $table) {
                // AI image identification text.
                $table->mediumText('identification')->nullable();
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'covidconfirmed')) {
                $table->timestamp('covidconfirmed')->nullable();
            }
            // suspectcount/date/reason are legacy spam-scoring fields with no
            // current reader. Production also carries an index on suspectcount,
            // which is dropped as unused in 2026_07_21_120006 - so the column is
            // added here without it, matching post-cleanup production.
            if (!Schema::hasColumn('users', 'suspectcount')) {
                $table->unsignedInteger('suspectcount')->default(0);
            }
            if (!Schema::hasColumn('users', 'suspectdate')) {
                $table->timestamp('suspectdate')->nullable();
            }
            if (!Schema::hasColumn('users', 'suspectreason')) {
                $table->string('suspectreason', 80)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['covidconfirmed', 'suspectcount', 'suspectdate', 'suspectreason'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        if (Schema::hasColumn('messages_attachments', 'identification')) {
            Schema::table('messages_attachments', function (Blueprint $table) {
                $table->dropColumn('identification');
            });
        }

        if (Schema::hasColumn('groups', 'precovidmoderated')) {
            Schema::table('groups', function (Blueprint $table) {
                $table->dropColumn('precovidmoderated');
            });
        }

        if (Schema::hasColumn('chat_messages', 'norfolkmsgid')) {
            Schema::table('chat_messages', function (Blueprint $table) {
                $table->dropColumn('norfolkmsgid');
            });
        }
    }
};
