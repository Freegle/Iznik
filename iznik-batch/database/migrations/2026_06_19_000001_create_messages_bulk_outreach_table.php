<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * messages_bulk_outreach: records the outreach to a community organisation (a Freegle
 * user) for a specific bulk offer (a messages row), with the org's details, its
 * preferences for that offer, and the outreach lifecycle. One row per (msgid, userid).
 *
 * This is the operational, simplified form of the OutreachRound entity in
 * plans/community-reuse-outreach-methodology.md (org = user, clearance = the bulk offer).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('messages_bulk_outreach')) {
            return;
        }

        Schema::create('messages_bulk_outreach', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('msgid');          // the bulk offer (messages.id)
            $t->unsignedBigInteger('userid');         // the org's Freegle account

            // org snapshot
            $t->string('orgname');
            $t->string('orgtype', 64)->nullable();
            $t->string('website')->nullable();
            $t->string('area')->nullable();
            $t->string('contactname')->nullable();
            $t->enum('tier', ['1', '2'])->nullable();

            // preferences for THIS bulk offer
            $t->json('clusters')->nullable();         // ["office setup","tables"]
            $t->text('preferences')->nullable();      // free-text wants / why fit

            // evidence / audit
            $t->string('activity_url', 1024)->nullable();
            $t->date('activity_date')->nullable();
            $t->enum('confidence', ['high', 'med', 'low'])->nullable();
            $t->string('source', 64)->nullable();     // which search bucket

            // outreach lifecycle
            $t->enum('status', [
                'Imported', 'Queued', 'Sent', 'Replied', 'Took', 'Declined', 'NoResponse', 'Skipped',
            ])->default('Imported');
            $t->unsignedBigInteger('chatid')->nullable();   // chat_rooms.id once the intro is sent
            $t->timestamp('sent_at')->nullable();
            $t->enum('sent_via', ['chat', 'email'])->nullable();
            $t->timestamp('replied_at')->nullable();
            $t->string('outcome')->nullable();
            $t->date('suppressed_until')->nullable();       // PECR rate-limit
            $t->text('notes')->nullable();

            $t->timestamps();

            $t->unique(['msgid', 'userid'], 'messages_bulk_outreach_msgid_userid_unique');
            $t->index('msgid');
            $t->index('userid');
            $t->index('status');
            $t->index('chatid');
        });

        // Foreign keys via raw SQL (kept out of the Blueprint, matching the project convention).
        DB::statement('ALTER TABLE messages_bulk_outreach
            ADD CONSTRAINT messages_bulk_outreach_msgid_foreign
            FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE messages_bulk_outreach
            ADD CONSTRAINT messages_bulk_outreach_userid_foreign
            FOREIGN KEY (userid) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_bulk_outreach');
    }
};
