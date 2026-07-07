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
        if (Schema::hasTable('teams_members')) {
            return;
        }

        Schema::create('teams_members', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->unsignedBigInteger('teamid')->index('teamid');
            $table->timestamp('added')->useCurrent();
            $table->text('description')->nullable();
            $table->string('nameoverride', 80)->nullable();
            $table->longText('imageoverride')->nullable();

            $table->unique(['userid', 'teamid'], 'userid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams_members');
    }
};
