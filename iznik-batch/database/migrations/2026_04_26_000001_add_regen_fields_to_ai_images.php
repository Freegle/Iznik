<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_images', function (Blueprint $table) {
            if (!Schema::hasColumn('ai_images', 'status')) {
                $table->enum('status', ['active', 'rejected', 'regenerating'])->default('active')->after('imagehash');
            }
            if (!Schema::hasColumn('ai_images', 'regeneration_notes')) {
                $table->text('regeneration_notes')->nullable()->after('status');
            }
            if (!Schema::hasColumn('ai_images', 'pending_externaluid')) {
                $table->string('pending_externaluid')->nullable()->after('regeneration_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('ai_images', function (Blueprint $table) {
            $table->dropColumn(['status', 'regeneration_notes', 'pending_externaluid']);
        });
    }
};
