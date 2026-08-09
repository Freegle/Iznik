<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Partnerships team gates the ModTools Partnerships page: members of this team (plus
 * Support/Admin) may see the left-hand menu entry and use the partnership API.
 *
 * Members are added per environment - on live they are set up by hand - so this only
 * creates the team itself, which the permission check looks up by name.
 */
return new class extends Migration
{
    private const NAME = 'Partnerships';

    public function up(): void
    {
        $exists = DB::table('teams')->where('name', self::NAME)->exists();

        if (!$exists) {
            DB::table('teams')->insert([
                'name' => self::NAME,
                'description' => 'Looks after our partnerships with local authorities: '
                    . 'sponsorship deals, the income they bring in, and the quarterly statistics councils receive.',
                'type' => 'Team',
                'email' => 'partnerships@ilovefreegle.org',
                'active' => 1,
                'wikiurl' => null,
                // Members work inside ModTools only; they don't need Support Tools.
                'supporttools' => 0,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('teams')->where('name', self::NAME)->delete();
    }
};
