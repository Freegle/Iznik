<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An ADMIN used to go to one community's members. With no communities it says who it goes to:
 *
 * - national: every member. Only Support or Admin may set it, as only they could send an ADMIN
 *   with no community before.
 * - otherwise lat/lng/reachminutes/reachmiles, the same centre and reach an event has: members
 *   within reachmiles of the centre.
 *
 * An ADMIN with neither goes to nobody. Existing unsent ADMINs need their audience set before
 * they are approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            if (! Schema::hasColumn('admins', 'national')) {
                $table->boolean('national')->default(false);
                $table->decimal('lat', 10, 6)->nullable();
                $table->decimal('lng', 10, 6)->nullable();
                $table->unsignedSmallInteger('reachminutes')->nullable();
                $table->decimal('reachmiles', 6, 2)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            if (Schema::hasColumn('admins', 'national')) {
                $table->dropColumn(['national', 'lat', 'lng', 'reachminutes', 'reachmiles']);
            }
        });
    }
};
