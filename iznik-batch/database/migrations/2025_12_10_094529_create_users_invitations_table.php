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
        if (Schema::hasTable('users_invitations')) {
            return;
        }

        Schema::create('users_invitations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->index('userid');
            $table->string('email')->index('email');
            $table->timestamp('date')->useCurrent();
            $table->enum('outcome', ['Pending', 'Accepted', 'Declined', ''])->default('Pending');
            $table->timestamp('outcometimestamp')->nullable();

            $table->unique(['userid', 'email'], 'userid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_invitations');
    }
};
