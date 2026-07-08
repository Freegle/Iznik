<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add `available` to messages_bulk_items so the offerer - including an external
 * owner using the logged-out secret-link update page - can mark an individual
 * catalogue item taken/gone independently of its quantity. messages.availablenow
 * is recomputed as the sum of quantities across items still marked available.
 * Defaults to 1 so every existing item reads as available.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('messages_bulk_items', 'available')) {
            return;
        }

        Schema::table('messages_bulk_items', function (Blueprint $table) {
            $table->boolean('available')->default(true)->after('quantity')
                ->comment('Owner flag: is this item still on offer (0 = taken/gone elsewhere)');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('messages_bulk_items', 'available')) {
            return;
        }

        Schema::table('messages_bulk_items', function (Blueprint $table) {
            $table->dropColumn('available');
        });
    }
};
