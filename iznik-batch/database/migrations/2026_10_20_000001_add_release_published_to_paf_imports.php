<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which release a PAF import took: the date AFD published it, read from their site when the
 * import starts. The PAF update on SysAdmin Housekeeping shows it, and compares it with the latest on the site.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('paf_imports', 'release_published')) {
            Schema::table('paf_imports', function (Blueprint $t) {
                $t->date('release_published')->nullable()->after('edition');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('paf_imports', 'release_published')) {
            Schema::table('paf_imports', function (Blueprint $t) {
                $t->dropColumn('release_published');
            });
        }
    }
};
