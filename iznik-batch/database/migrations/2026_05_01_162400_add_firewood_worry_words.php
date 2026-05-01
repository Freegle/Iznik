<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add "fire wood" (two words) as a worry word - catches unsafe painted/treated wood for burning
        DB::table('worrywords')->updateOrInsert(
            ['keyword' => 'fire wood'],
            [
                'type' => 'Review',
                'substance' => 'Painted/treated wood hazard'
            ]
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('worrywords')->where('keyword', 'fire wood')->delete();
    }
};
