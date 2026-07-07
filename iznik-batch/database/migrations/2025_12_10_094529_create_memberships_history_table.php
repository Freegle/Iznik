<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('memberships_history')) {
            return;
        }

        Schema::create('memberships_history', function (Blueprint $table) {
            $table->comment('Used to spot multijoiners');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid');
            $table->unsignedBigInteger('groupid')->index('groupid');
            $table->enum('collection', ['Approved', 'Pending', 'Banned']);
            $table->timestamp('added')->useCurrent()->index('date');
            $table->boolean('processingrequired')->default(false)->index('processingrequired');

            $table->index(['userid', 'groupid'], 'userid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memberships_history');
    }
};
