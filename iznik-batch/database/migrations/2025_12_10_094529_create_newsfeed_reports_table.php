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
        if (Schema::hasTable('newsfeed_reports')) {
            return;
        }

        Schema::create('newsfeed_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('newsfeedid')->index('newsfeedid');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
            $table->text('reason')->nullable();

            $table->unique(['newsfeedid', 'userid'], 'newsfeedid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('newsfeed_reports');
    }
};
