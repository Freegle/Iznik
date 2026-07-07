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
        if (Schema::hasTable('partners_messages')) {
            return;
        }

        Schema::create('partners_messages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('partnerid')->index('partnerid');
            $table->unsignedBigInteger('msgid')->index('msgid');
            $table->timestamp('time')->useCurrent();

            $table->unique(['partnerid', 'msgid'], 'partnerid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('partners_messages');
    }
};
