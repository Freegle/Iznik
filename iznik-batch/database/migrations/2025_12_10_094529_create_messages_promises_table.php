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
        if (Schema::hasTable('messages_promises')) {
            return;
        }

        Schema::create('messages_promises', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->timestamp('promisedat')->useCurrentOnUpdate()->useCurrent()->index('promisedat');

            $table->unique(['msgid', 'userid'], 'msgid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_promises');
    }
};
