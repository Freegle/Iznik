<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->unsignedBigInteger('heldby')->nullable()->after('approvedat');
            $table->string('spamtype', 50)->nullable()->after('heldby');
            $table->string('spamreason', 255)->nullable()->after('spamtype');

            $table->foreign('heldby')->references('id')->on('users')->onDelete('set null');
            $table->index('heldby', 'messages_groups_heldby_idx');
        });
    }

    public function down(): void
    {
        Schema::table('messages_groups', function (Blueprint $table) {
            $table->dropForeign(['heldby']);
            $table->dropIndex('messages_groups_heldby_idx');
            $table->dropColumn(['heldby', 'spamtype', 'spamreason']);
        });
    }
};
