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
        if (Schema::hasTable('shortlink_clicks')) {
            return;
        }

        Schema::create('shortlink_clicks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('shortlinkid')->index('shortlinkid');
            $table->timestamp('timestamp')->useCurrent();

            $table->index(['shortlinkid', 'timestamp'], 'shortlinkid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shortlink_clicks');
    }
};
