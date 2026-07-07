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
        if (Schema::hasTable('users_emails')) {
            return;
        }

        Schema::create('users_emails', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->nullable()->index('userid')->comment('Unique ID in users table');
            $table->string('email')->unique('email')->comment('The email');
            $table->tinyInteger('preferred')->default(1)->comment('Preferred email for this user');
            $table->timestamp('added')->useCurrent();
            $table->string('validatekey', 32)->nullable()->unique('validatekey');
            $table->timestamp('validated')->nullable()->index('validated');
            $table->string('canon')->nullable()->index('canon')->comment('For spotting duplicates');
            $table->string('backwards')->nullable()->index('backwards')->comment('Allows domain search');
            $table->timestamp('bounced')->nullable()->index('bounced');
            $table->timestamp('viewed')->nullable()->index('viewed');
            $table->string('md5hash', 32)->nullable()->virtualAs('md5(lower(`email`))')->index('md5hash');
            $table->timestamp('validatetime')->nullable()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_emails');
    }
};
