<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * With no communities, an event or volunteering opportunity needs its own answer to
 * "who should see this?". Each gets a centre point and a reach:
 *
 * - lat/lng: where it is shown around. The poster's location unless they gave a
 *   postcode for it.
 * - reachminutes: the drive time the poster chose on the slider. NULL means the
 *   default, which is the density cap at the centre (the same budget a post gets).
 * - reachmiles: the crow-flies radius that drive time reaches, derived by routing
 *   when it is saved. The lists and digests filter on this.
 *
 * Existing rows take the poster's last location, and the default reach.
 */
return new class extends Migration
{
    private const TABLES = ['communityevents', 'volunteering'];

    public function up(): void
    {
        foreach (self::TABLES as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (! Schema::hasColumn($t, 'lat')) {
                    $table->decimal('lat', 10, 6)->nullable();
                    $table->decimal('lng', 10, 6)->nullable();
                    $table->unsignedSmallInteger('reachminutes')->nullable();
                    $table->decimal('reachmiles', 6, 2)->nullable();
                }
            });

            DB::statement("UPDATE {$t}
                INNER JOIN users ON users.id = {$t}.userid
                INNER JOIN locations ON locations.id = users.lastlocation
                SET {$t}.lat = locations.lat, {$t}.lng = locations.lng
                WHERE {$t}.lat IS NULL");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $t) {
            Schema::table($t, function (Blueprint $table) use ($t) {
                if (Schema::hasColumn($t, 'lat')) {
                    $table->dropColumn(['lat', 'lng', 'reachminutes', 'reachmiles']);
                }
            });
        }
    }
};
