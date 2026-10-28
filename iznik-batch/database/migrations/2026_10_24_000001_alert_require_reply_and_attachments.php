<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contact a community's volunteers (alerts) gains three things.
 *
 * - alerts.quoted: the member's original email, kept in full and shown quoted in the mail.
 * - alerts.requirereply / alerts.cancelled: a single-community alert can require each of that
 *   community's moderators to reply before ModTools lets them carry on; cancelled releases them.
 * - alerts_attachments: up to five files (screenshots, PDFs) sent with the mail.
 * - alerts_acknowledgements: who has replied, and whether they used their one 30 minute snooze.
 *
 * Two new, empty tables; the ALTER on the small alerts table is INSTANT.
 * docs/developers/reference/contact-group-alerts.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('alerts', 'requirereply')) {
            DB::statement('ALTER TABLE alerts '
                .'ADD COLUMN quoted MEDIUMTEXT NULL, '
                .'ADD COLUMN requirereply TINYINT(1) NOT NULL DEFAULT 0, '
                .'ADD COLUMN cancelled TIMESTAMP NULL DEFAULT NULL, '
                .'ALGORITHM=INSTANT');
        }

        if (! Schema::hasTable('alerts_attachments')) {
            Schema::create('alerts_attachments', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('alertid');
                $t->string('name', 100);
                $t->string('mime', 100);
                $t->unsignedInteger('size');
                $t->binary('data')->nullable();
                $t->index('alertid', 'alerts_attachments_alertid');
                $t->foreign('alertid')->references('id')->on('alerts')->onDelete('cascade');
            });
            DB::statement('ALTER TABLE alerts_attachments MODIFY data MEDIUMBLOB NULL');
        }

        if (! Schema::hasTable('alerts_acknowledgements')) {
            Schema::create('alerts_acknowledgements', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('alertid');
                $t->unsignedBigInteger('userid');
                $t->timestamp('replied')->nullable();
                $t->text('reply')->nullable();
                $t->timestamp('snoozed_until')->nullable();
                $t->boolean('snooze_used')->default(false);
                $t->unique(['alertid', 'userid'], 'alerts_acknowledgements_alert_user');
                $t->index('userid', 'alerts_acknowledgements_userid');
                $t->foreign('alertid')->references('id')->on('alerts')->onDelete('cascade');
                $t->foreign('userid')->references('id')->on('users')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts_acknowledgements');
        Schema::dropIfExists('alerts_attachments');
        if (Schema::hasColumn('alerts', 'requirereply')) {
            DB::statement('ALTER TABLE alerts DROP COLUMN quoted, DROP COLUMN requirereply, DROP COLUMN cancelled');
        }
    }
};
