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
        if (Schema::hasTable('predictions')) {
            return;
        }

        Schema::create('predictions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->unique('rater_2');
            $table->enum('prediction', ['Up', 'Down', 'Disabled', 'Unknown']);
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
            $table->text('probs')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('predictions');
    }
};
