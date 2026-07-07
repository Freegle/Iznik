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
        if (Schema::hasTable('simulation_message_isochrones_messages')) {
            return;
        }

        Schema::create('simulation_message_isochrones_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('runid');
            $table->unsignedBigInteger('msgid')->index('msgid');
            $table->integer('sequence')->comment('Order in run (0-based)');
            $table->timestamp('arrival');
            $table->string('subject')->nullable();
            $table->unsignedBigInteger('locationid')->nullable();
            $table->decimal('lat', 10, 6)->nullable();
            $table->decimal('lng', 10, 6)->nullable();
            $table->unsignedBigInteger('groupid');
            $table->string('groupname')->nullable();
            $table->json('group_cga_polygon')->nullable()->comment('Group coverage area GeoJSON');
            $table->integer('total_group_users')->nullable()->default(0);
            $table->integer('total_replies_actual')->nullable()->default(0);
            $table->json('metrics')->nullable()->comment('Summary metrics for this message');

            $table->unique(['runid', 'msgid'], 'runid_msgid');
            $table->index(['runid', 'sequence'], 'runid_sequence');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('simulation_message_isochrones_messages');
    }
};
