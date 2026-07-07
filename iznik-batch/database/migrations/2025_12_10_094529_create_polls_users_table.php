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
        if (Schema::hasTable('polls_users')) {
            return;
        }

        Schema::create('polls_users', function (Blueprint $table) {
            $table->unsignedBigInteger('pollid')->index('pollid_2');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->timestamp('date')->useCurrent();
            $table->tinyInteger('shown')->nullable()->default(1);
            $table->text('response')->nullable();

            $table->unique(['pollid', 'userid'], 'pollid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('polls_users');
    }
};
