<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `accessinstructions` to messages: an offer-level private note (exact
 * address, gate code, intercom, etc.) that is NOT shown publicly. For a bulk
 * offer ("clearance") it is sent to a replier only once the offerer promises
 * them an item (their interest is moved to Reserved). Nullable; V1 ignores it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('messages', 'accessinstructions')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->text('accessinstructions')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('messages', 'accessinstructions')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('accessinstructions');
        });
    }
};
