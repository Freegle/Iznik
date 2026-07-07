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
        if (Schema::hasTable('chat_roster')) {
            return;
        }

        Schema::create('chat_roster', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('chatid')->index('chatid');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->timestamp('date')->useCurrent()->index('date');
            $table->enum('status', ['Online', 'Away', 'Offline', 'Closed', 'Blocked'])->default('Online')->index('status');
            $table->unsignedBigInteger('lastmsgseen')->nullable()->index('lastmsg');
            $table->timestamp('lastemailed')->nullable();
            $table->unsignedBigInteger('lastmsgemailed')->nullable();
            $table->unsignedBigInteger('lastmsgnotified')->nullable()->index('lastmsgnotified');
            $table->string('lastip', 80)->nullable()->index('lastip');
            $table->timestamp('lasttype')->nullable();

            $table->unique(['chatid', 'userid'], 'chatid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_roster');
    }
};
