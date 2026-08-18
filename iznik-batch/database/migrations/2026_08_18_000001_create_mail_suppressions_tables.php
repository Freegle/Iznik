<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tables behind deferral-aware mail suppression.
 *
 * Our bulk relay accepts a message with a 250 and only then discovers that the
 * receiving provider will not take it. When a provider starts deferring us -
 * 2026-08-15, Yahoo began 421-ing everything from the relay's IP with
 * "[TSS04] ... temporarily deferred due to unexpected volume or user
 * complaints" - the sending code sees nothing wrong and keeps rendering mail
 * into a queue that cannot drain. Three days of that put 85,537 messages in
 * the queue for about 9,400 people who received none of them.
 *
 * `mail_suppressions` is the gate. A row says "do not generate mail for this
 * target until further notice". Scope is deliberately layered:
 *
 *   mxgroup  the relay host family the deferrals came from, e.g.
 *            am0.yahoodns.net. This is the row that carries the evidence,
 *            because a provider blocks per IP-to-relay pair, not per domain:
 *            one Yahoo block took out yahoo.co.uk, yahoo.com, ymail.com,
 *            rocketmail.com, aol.com, aol.co.uk, aim.com and sky.com, the
 *            last of which is not recognisably Yahoo from its domain at all.
 *   domain   a recipient domain seen deferring via a suppressed mxgroup.
 *            Written as a child of the mxgroup row (see parentid) so the
 *            per-address gate is a plain indexed lookup with no DNS in the
 *            sending loop.
 *   address  one mailbox, for the slower per-address signal that catches
 *            "452 4.2.2 Recipient mailbox quota exceeded" - a real problem
 *            with one person's mailbox rather than with our reputation.
 *
 * Rows are kept after release so the history of an episode survives; the
 * active set is `released_at IS NULL`.
 *
 * Deliberately NOT users.bouncing. That flag means "this address is bad",
 * it is shown to moderators as such, and historically it was never reset.
 * A deferral is our reputation problem, not the member's address problem,
 * and it has to read differently everywhere it surfaces.
 *
 * `mail_suppressed_counts` is what release needs. We do not store rendered
 * mail - that would defeat the point of not rendering it - so instead we
 * count, per member and per type of mail, what we declined to generate.
 * On release that is enough to send one catch-up digest and one "you have
 * unread messages" summary rather than replaying nine stale emails.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mail_suppressions')) {
            Schema::create('mail_suppressions', function (Blueprint $table) {
                $table->comment('Targets we must not generate mail for while a provider is deferring us');
                $table->bigIncrements('id');

                // What kind of thing `value` names. See the class comment.
                $table->enum('scope', ['mxgroup', 'domain', 'address']);
                $table->string('value');

                // Domain rows are derived from the mxgroup row that explains
                // them, so releasing the parent releases the children and the
                // reason only has to be recorded once.
                $table->unsignedBigInteger('parentid')->nullable();

                // The provider's own words, e.g. the 421 line we were given.
                // Kept verbatim: when this fires at 3am it is the only
                // evidence of why.
                $table->text('reason')->nullable();

                // Friendly name for the member-facing and moderator-facing
                // text ("Yahoo is not currently accepting our mail").
                // Nullable because we can always fall back to the value.
                $table->string('provider', 64)->nullable();

                // Arrival time of the oldest still-deferred message for this
                // target. This is the "delayed since" date moderators see,
                // and it is deliberately the start of the member's problem
                // rather than the moment we noticed it.
                $table->timestamp('deferred_since')->nullable();

                $table->timestamp('first_seen')->useCurrent();
                $table->timestamp('last_seen')->useCurrent();

                // NULL while active. Set when deliveries resume and the
                // deferred count has stayed below threshold for two
                // consecutive scans.
                $table->timestamp('released_at')->nullable();

                // Deferred messages seen for this target at the last scan.
                $table->unsignedInteger('message_count')->default(0);

                // Consecutive scans that looked clear. Release needs two, so
                // that a single quiet scan during a backoff window does not
                // reopen the floodgates.
                $table->unsignedTinyInteger('clear_scans')->default(0);

                $table->timestamp('created')->useCurrent();
                $table->timestamp('modified')->useCurrent()->useCurrentOnUpdate();

                // The sending-loop lookup: scope + value + still active.
                $table->index(['scope', 'value', 'released_at'], 'scope_value_released');
                // Listing the active set, and expiring old history.
                $table->index(['released_at'], 'released_at');
                $table->index(['parentid'], 'parentid');
            });
        }

        if (! Schema::hasTable('mail_suppressed_counts')) {
            Schema::create('mail_suppressed_counts', function (Blueprint $table) {
                $table->comment('What we declined to generate per member, so release can send one catch-up');
                $table->bigIncrements('id');
                $table->unsignedBigInteger('userid');

                // The emailType already passed to EmailSpoolerService::spool(),
                // e.g. digest_immediate, digest, chat, engage. Keeping the
                // same vocabulary means the catch-up policy can be expressed
                // in the same terms as the mail it replaces.
                $table->string('emailtype', 32);

                // Which suppression was in force when we declined. Recorded
                // at the time rather than re-derived later, because working
                // out afterwards which provider was refusing a given member
                // means resolving their send address the same way the mailer
                // does - a ranking, not a flag - which is not something a
                // reporting query should be reimplementing.
                $table->unsignedBigInteger('suppressionid')->nullable();

                $table->unsignedInteger('count')->default(0);
                $table->timestamp('firstat')->useCurrent();
                $table->timestamp('lastat')->useCurrent();

                // Claimed by the catch-up pass before it sends, so a crash
                // mid-release cannot send the same catch-up twice. Cleared
                // again if a later episode suppresses the same member.
                $table->timestamp('caughtup_at')->nullable();

                $table->timestamp('created')->useCurrent();
                $table->timestamp('modified')->useCurrent()->useCurrentOnUpdate();

                $table->unique(['userid', 'emailtype'], 'userid_emailtype');
                // The catch-up sweep: everything still owed.
                $table->index(['caughtup_at'], 'caughtup_at');
                $table->index(['suppressionid'], 'suppressionid');
            });
        }

        $this->addActiveUniqueIndex();
        $this->addForeignKeys();
    }

    /**
     * At most one ACTIVE row per (scope, value).
     *
     * MySQL has no partial indexes, so a stored generated column holds the
     * value only while the row is active and NULL once it is released. A
     * unique index over that column enforces "one live suppression per
     * target" while letting history keep as many released rows for the same
     * target as it likes, because repeated NULLs do not collide.
     *
     * Without this, a scan that died half way could leave two active rows
     * for one provider. activeByKey() keys its map on scope+value, so the
     * second would be invisible to the scan and would keep gating mail long
     * after the first was released.
     */
    private function addActiveUniqueIndex(): void
    {
        if (! Schema::hasTable('mail_suppressions') || Schema::hasColumn('mail_suppressions', 'active_value')) {
            return;
        }

        DB::statement('ALTER TABLE mail_suppressions
            ADD COLUMN active_value VARCHAR(255)
            GENERATED ALWAYS AS (IF(released_at IS NULL, value, NULL)) STORED');

        DB::statement('ALTER TABLE mail_suppressions
            ADD UNIQUE KEY scope_active_value (scope, active_value)');
    }
    /**
     * Foreign keys go in afterwards, guarded, so a re-run against a database
     * that already has them is a no-op rather than an error.
     */
    private function addForeignKeys(): void
    {
        $exists = function (string $name): bool {
            return DB::table('information_schema.TABLE_CONSTRAINTS')
                ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
                ->where('CONSTRAINT_NAME', $name)
                ->exists();
        };

        if (Schema::hasTable('mail_suppressions') && ! $exists('mail_suppressions_parentid_foreign')) {
            Schema::table('mail_suppressions', function (Blueprint $table) {
                $table->foreign('parentid', 'mail_suppressions_parentid_foreign')
                    ->references('id')->on('mail_suppressions')
                    ->onDelete('cascade');
            });
        }

        if (Schema::hasTable('mail_suppressed_counts') && ! $exists('mail_suppressed_counts_suppressionid_foreign')) {
            Schema::table('mail_suppressed_counts', function (Blueprint $table) {
                // SET NULL rather than CASCADE: once a suppression's history
                // is gone we still want to know we held mail for this member,
                // just not which provider it was.
                $table->foreign('suppressionid', 'mail_suppressed_counts_suppressionid_foreign')
                    ->references('id')->on('mail_suppressions')
                    ->onDelete('set null');
            });
        }

        if (Schema::hasTable('mail_suppressed_counts') && ! $exists('mail_suppressed_counts_userid_foreign')) {
            Schema::table('mail_suppressed_counts', function (Blueprint $table) {
                $table->foreign('userid', 'mail_suppressed_counts_userid_foreign')
                    ->references('id')->on('users')
                    ->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_suppressed_counts');
        Schema::dropIfExists('mail_suppressions');
    }
};
