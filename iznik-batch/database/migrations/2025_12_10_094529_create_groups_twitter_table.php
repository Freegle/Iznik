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
        if (Schema::hasTable('groups_twitter')) {
            return;
        }

        Schema::create('groups_twitter', function (Blueprint $table) {
            $table->unsignedBigInteger('groupid')->unique('groupid');
            $table->string('name', 80)->nullable();
            $table->text('token');
            $table->text('secret');
            $table->timestamp('authdate')->nullable();
            $table->unsignedBigInteger('msgid')->nullable()->index('msgid')->comment('Last message tweeted');
            $table->timestamp('msgarrival')->nullable();
            $table->unsignedBigInteger('eventid')->nullable()->index('eventid')->comment('Last event tweeted');
            $table->tinyInteger('valid')->default(1);
            $table->boolean('locked')->default(false);
            $table->text('lasterror')->nullable();
            $table->timestamp('lasterrortime')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('groups_twitter');
    }
};
