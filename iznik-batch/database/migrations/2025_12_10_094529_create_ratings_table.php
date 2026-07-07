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
        if (Schema::hasTable('ratings')) {
            return;
        }

        Schema::create('ratings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('rater')->nullable()->index('rater');
            $table->unsignedBigInteger('ratee')->index('ratee');
            $table->enum('rating', ['Up', 'Down'])->nullable();
            $table->timestamp('timestamp')->useCurrent()->index('timestamp');
            $table->boolean('visible')->default(false);
            $table->unsignedBigInteger('tn_rating_id')->nullable()->unique('tn_rating_id');
            $table->enum('reason', ['NoShow', 'Punctuality', 'Ghosted', 'Rude', 'Other'])->nullable();
            $table->text('text')->nullable();
            $table->tinyInteger('reviewrequired')->default(0);

            $table->unique(['rater', 'ratee'], 'rater_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
