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
        if (Schema::hasTable('messages_reneged')) {
            return;
        }

        Schema::create('messages_reneged', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent()->index('timestamp');
            $table->unsignedBigInteger('msgid')->index('msgid');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_reneged');
    }
};
