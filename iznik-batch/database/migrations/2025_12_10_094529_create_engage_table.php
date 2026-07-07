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
        if (Schema::hasTable('engage')) {
            return;
        }

        Schema::create('engage', function (Blueprint $table) {
            $table->comment('User re-engagement attempts');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->enum('engagement', ['UT', 'New', 'Occasional', 'Frequent', 'Obsessed', 'Inactive', 'Dormant'])->nullable();
            $table->unsignedBigInteger('mailid')->nullable();
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent()->index('timestamp');
            $table->timestamp('succeeded')->nullable();

            $table->index(['userid', 'mailid'], 'userid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('engage');
    }
};
