<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('admins', 'editprotected')) {
            Schema::table('admins', function (Blueprint $table) {
                $table->boolean('editprotected')->default(false)->after('essential');
            });
        }

        if (!Schema::hasColumn('admins', 'template')) {
            Schema::table('admins', function (Blueprint $table) {
                $table->string('template', 50)->nullable()->after('editprotected');
            });
        } else {
            // Fix column type if it was incorrectly created as boolean
            Schema::table('admins', function (Blueprint $table) {
                $table->string('template', 50)->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['editprotected', 'template']);
        });
    }
};
