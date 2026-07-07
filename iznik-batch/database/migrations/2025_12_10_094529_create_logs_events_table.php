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
        if (Schema::hasTable('logs_events')) {
            return;
        }

        Schema::create('logs_events', function (Blueprint $table) {
            $table->bigInteger('id', true);
            $table->unsignedBigInteger('userid')->nullable();
            $table->string('sessionid', 32)->nullable()->index('sessionid');
            $table->string('ip', 20)->nullable()->index('ip');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent()->index('timestamp');
            $table->timestamp('clienttimestamp')->nullable();
            $table->integer('posx')->nullable();
            $table->integer('posy')->nullable();
            $table->integer('viewx')->nullable();
            $table->integer('viewy')->nullable();
            $table->mediumText('data')->nullable();
            $table->unsignedBigInteger('datasameas')->nullable()->index('datasameas')->comment('Allows use to reuse data stored in table once for other rows');
            $table->string('datahash', 32)->nullable();
            $table->string('route')->nullable();
            $table->string('target')->nullable();
            $table->string('event', 80)->nullable();

            $table->index(['datahash', 'datasameas'], 'datahash');
            $table->index(['sessionid', 'userid'], 'sessionid_2');
            $table->index(['userid', 'timestamp'], 'userid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('logs_events');
    }
};
