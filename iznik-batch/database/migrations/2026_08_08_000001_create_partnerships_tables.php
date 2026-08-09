<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tables behind the ModTools Partnerships page.
 *
 * A partnership is a sponsorship deal with a local authority (council). The Freegle
 * groups it covers are derived from the authority/group polygon overlap and cached in
 * partnerships_groups; saving a partnership syncs a groups_sponsorship row per covered
 * group so the member site shows the sponsor.
 *
 * Money is tracked in three places, deliberately:
 *   - partnerships.amount     the headline value of the whole deal
 *   - partnerships_years      how a multi-year deal splits across UK financial years
 *   - partnerships_payments   what has actually been invoiced and paid
 *
 * Everything here is prefixed `partnerships` so the feature's tables are obvious.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('partnerships')) {
            Schema::create('partnerships', function (Blueprint $table) {
                $table->comment('Sponsorship deals with local authorities');
                $table->bigIncrements('id');
                $table->unsignedBigInteger('authorityid');
                $table->string('name');
                $table->string('tagline')->nullable();
                $table->text('description')->nullable();
                $table->string('linkurl')->nullable();
                $table->string('imageurl')->nullable();
                $table->date('startdate');
                $table->date('enddate');
                // The deal value across its whole term, in pounds.
                $table->decimal('amount', 10, 2)->default(0);
                $table->boolean('agreed')->default(false);
                $table->date('agreeddate')->nullable();
                $table->string('contactname')->nullable();
                $table->string('contactemail')->nullable();
                $table->text('notes')->nullable();
                // Whether the sponsor should be shown on the member site.
                $table->boolean('visible')->default(true);
                $table->timestamp('created')->useCurrent();
                $table->timestamp('modified')->useCurrent()->useCurrentOnUpdate();

                $table->index(['authorityid'], 'authorityid');
                $table->index(['enddate'], 'enddate');
            });
        }

        // How a multi-year deal splits across UK financial years (1 Apr - 31 Mar).
        // A financial year is identified by the calendar year it starts in, so 2026
        // means 2026/27. Absent rows mean "pro-rate the amount across the term".
        if (!Schema::hasTable('partnerships_years')) {
            Schema::create('partnerships_years', function (Blueprint $table) {
                $table->comment('Explicit per-financial-year split of a multi-year deal');
                $table->bigIncrements('id');
                $table->unsignedBigInteger('partnershipid');
                $table->integer('financialyear');
                $table->decimal('amount', 10, 2)->default(0);

                $table->unique(['partnershipid', 'financialyear'], 'partnershipid_financialyear');
            });
        }

        if (!Schema::hasTable('partnerships_payments')) {
            Schema::create('partnerships_payments', function (Blueprint $table) {
                $table->comment('Invoices raised against a partnership and what has been paid');
                $table->bigIncrements('id');
                $table->unsignedBigInteger('partnershipid');
                $table->date('date');
                $table->decimal('amount', 10, 2)->default(0);
                $table->date('paid')->nullable();
                $table->string('reference')->nullable();
                $table->text('notes')->nullable();

                $table->index(['partnershipid'], 'partnershipid');
            });
        }

        // Which groups a partnership covers, and the groups_sponsorship row we created
        // for each. Kept as a mapping table so groups_sponsorship stays untouched.
        if (!Schema::hasTable('partnerships_groups')) {
            Schema::create('partnerships_groups', function (Blueprint $table) {
                $table->comment('Groups covered by a partnership and their sponsorship rows');
                $table->bigIncrements('id');
                $table->unsignedBigInteger('partnershipid');
                $table->unsignedBigInteger('groupid');
                $table->unsignedBigInteger('sponsorshipid')->nullable();

                $table->unique(['partnershipid', 'groupid'], 'partnershipid_groupid');
                $table->index(['groupid'], 'groupid');
            });
        }

        // Reminder mails already sent, so a partnership is chased once per window.
        if (!Schema::hasTable('partnerships_reminders')) {
            Schema::create('partnerships_reminders', function (Blueprint $table) {
                $table->comment('Expiry reminder mails sent to the Partnerships team');
                $table->bigIncrements('id');
                $table->unsignedBigInteger('partnershipid');
                $table->string('type', 32);
                $table->timestamp('sent')->useCurrent();

                $table->unique(['partnershipid', 'type'], 'partnershipid_type');
            });
        }

        // Authority stats spreadsheet generation is too slow for a web request, so the
        // page queues a job here and the scheduler renders it in the background.
        if (!Schema::hasTable('partnerships_statsjobs')) {
            Schema::create('partnerships_statsjobs', function (Blueprint $table) {
                $table->comment('Queued authority stats spreadsheet generation requests');
                $table->bigIncrements('id');
                $table->unsignedBigInteger('userid')->nullable();
                // Comma-separated authority ids, matching authority:stats --i.
                $table->text('authorityids');
                $table->string('quarter')->default('3 months ago');
                $table->enum('status', ['Pending', 'Running', 'Ready', 'Failed'])->default('Pending');
                $table->text('error')->nullable();
                $table->timestamp('requested')->useCurrent();
                $table->timestamp('started')->nullable();
                $table->timestamp('completed')->nullable();

                $table->index(['status', 'requested'], 'status_requested');
                $table->index(['userid'], 'userid');
            });
        }

        // The rendered spreadsheets. Held as blobs rather than files because the Go API
        // serves the download while Laravel does the rendering, and they have no shared
        // filesystem.
        if (!Schema::hasTable('partnerships_statsfiles')) {
            Schema::create('partnerships_statsfiles', function (Blueprint $table) {
                $table->comment('Spreadsheets produced by a partnerships_statsjobs run');
                $table->bigIncrements('id');
                $table->unsignedBigInteger('jobid');
                $table->unsignedBigInteger('authorityid')->nullable();
                $table->string('filename');
                $table->unsignedInteger('size')->default(0);
                $table->binary('content')->nullable();

                $table->index(['jobid'], 'jobid');
            });

            // Blueprint's binary() gives a plain BLOB (64KB); a council spreadsheet with a
            // postcode breakdown comfortably exceeds that, so widen it to MEDIUMBLOB (16MB).
            DB::statement('ALTER TABLE partnerships_statsfiles MODIFY content MEDIUMBLOB NULL');
        }

        $this->addForeignKeys();
    }

    /**
     * Cascade deletes so removing a partnership takes its years, payments, group links and
     * reminder history with it, and a job takes its rendered files.
     */
    private function addForeignKeys(): void
    {
        $keys = [
            ['partnerships', 'authorityid', 'authorities', 'cascade'],
            ['partnerships_years', 'partnershipid', 'partnerships', 'cascade'],
            ['partnerships_payments', 'partnershipid', 'partnerships', 'cascade'],
            ['partnerships_groups', 'partnershipid', 'partnerships', 'cascade'],
            ['partnerships_groups', 'groupid', 'groups', 'cascade'],
            // A sponsorship row deleted by hand leaves the link in place, unpointed.
            ['partnerships_groups', 'sponsorshipid', 'groups_sponsorship', 'set null'],
            ['partnerships_reminders', 'partnershipid', 'partnerships', 'cascade'],
            ['partnerships_statsfiles', 'jobid', 'partnerships_statsjobs', 'cascade'],
        ];

        foreach ($keys as [$table, $column, $references, $onDelete]) {
            $name = $table . '_' . $column . '_foreign';

            $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('CONSTRAINT_NAME', $name)
                ->exists();

            if (!$exists) {
                Schema::table($table, function (Blueprint $blueprint) use ($column, $references, $onDelete) {
                    $blueprint->foreign($column)->references('id')->on($references)->onDelete($onDelete);
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partnerships_statsfiles');
        Schema::dropIfExists('partnerships_statsjobs');
        Schema::dropIfExists('partnerships_reminders');
        Schema::dropIfExists('partnerships_groups');
        Schema::dropIfExists('partnerships_payments');
        Schema::dropIfExists('partnerships_years');
        Schema::dropIfExists('partnerships');
    }
};
