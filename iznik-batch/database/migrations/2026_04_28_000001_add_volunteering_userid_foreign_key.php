<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('UPDATE volunteering v LEFT JOIN users u ON u.id = v.userid SET v.userid = NULL WHERE v.userid IS NOT NULL AND u.id IS NULL');

        Schema::table('volunteering', function (Blueprint $table) {
            $table->foreign('userid', 'volunteering_ibfk_3')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('volunteering', function (Blueprint $table) {
            $table->dropForeign('volunteering_ibfk_3');
        });
    }
};
