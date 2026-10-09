<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * email_tracking_journal: an append-only buffer for email image loads and pixel opens.
 *
 * The Go delivery handlers used to write every image load straight into email_tracking_images and
 * UPDATE the parent email_tracking row (opened_at, scroll_depth_percent) on each one. The images of
 * one email load together, so those writes queued on the parent row's lock: 40.7% of db3's
 * statement time, almost all of it lock wait.
 *
 * Now the handlers append one row here, in batches, and mail:tracking:fold (nightly) applies them
 * to email_tracking and email_tracking_images and deletes them. So this table is deliberately bare:
 * no foreign key (an FK insert takes a shared lock on the parent row, which is the contention being
 * removed), no secondary index (the only reads are by id range, and every index is more
 * certification work under Galera), and no parent lookup. ref is the tracking id as the request
 * carried it (the full 32 characters, or the 12-character compact ref); the fold resolves it.
 *
 * kind: 1 = image load, 2 = pixel open.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('email_tracking_journal')) {
            Schema::create('email_tracking_journal', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->string('ref', 32)->charset('ascii')->collation('ascii_bin');
                $t->unsignedTinyInteger('kind');
                $t->string('position', 50)->nullable();
                $t->unsignedTinyInteger('scroll')->nullable();
                $t->timestamp('loaded_at')->useCurrent();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_tracking_journal');
    }
};
