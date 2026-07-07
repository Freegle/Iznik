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
        if (Schema::hasTable('groups_digests')) {
            return;
        }

        Schema::create('groups_digests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('groupid')->index('groupid');
            $table->integer('frequency');
            $table->unsignedBigInteger('msgid')->nullable()->index('msggrpid')->comment('Which message we got upto when sending');
            $table->timestamp('msgdate')->nullable()->comment('Arrival of message we have sent upto');
            $table->timestamp('started')->nullable();
            $table->timestamp('ended')->nullable();

            $table->unique(['groupid', 'frequency'], 'groupid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('groups_digests');
    }
};
