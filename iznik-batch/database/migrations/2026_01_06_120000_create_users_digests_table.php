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
        if (Schema::hasTable('users_digests')) {
            return;
        }

        Schema::create('users_digests', function (Blueprint $table) {
            $table->comment('Tracks unified digest progress per user');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid');
            $table->enum('mode', ['immediate', 'daily'])->default('daily')->comment('Digest mode');
            $table->unsignedBigInteger('lastmsgid')->nullable()->comment('ID of last message included in digest');
            $table->timestamp('lastmsgdate')->nullable()->comment('Arrival time of last message included');
            $table->timestamp('lastsent')->nullable()->comment('When the last digest was sent');

            $table->unique(['userid', 'mode'], 'userid_mode');
            $table->index(['mode', 'lastsent'], 'mode_lastsent');

            $table->foreign('userid')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_digests');
    }
};
