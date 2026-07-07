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
        if (Schema::hasTable('trysts')) {
            return;
        }

        Schema::create('trysts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('arrangedat')->useCurrent();
            $table->timestamp('arrangedfor')->nullable()->index('arrangedfor');
            $table->unsignedBigInteger('user1')->index('user1');
            $table->unsignedBigInteger('user2')->index('user2');
            $table->boolean('icssent')->default(false);
            $table->text('ics1uid')->nullable();
            $table->text('ics2uid')->nullable();
            $table->enum('user1response', ['Accepted', 'Declined', 'Other'])->nullable();
            $table->enum('user2response', ['Accepted', 'Declined', 'Other'])->nullable();
            $table->timestamp('remindersent')->nullable();
            $table->timestamp('user1confirmed')->nullable();
            $table->timestamp('user2confirmed')->nullable();
            $table->timestamp('user1declined')->nullable();
            $table->timestamp('user2declined')->nullable();

            $table->unique(['arrangedfor', 'user1', 'user2'], 'arrangedfor_2');
            $table->index(['remindersent', 'arrangedfor'], 'arrangedfor_3');
            $table->index(['icssent', 'arrangedfor'], 'icssent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trysts');
    }
};
