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
        if (Schema::hasTable('users_logins')) {
            return;
        }

        Schema::create('users_logins', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->index('userid')->comment('Unique ID in users table');
            $table->enum('type', ['Yahoo', 'Facebook', 'Google', 'Native', 'Link', 'Apple'])->nullable();
            $table->string('uid')->nullable()->comment('Unique identifier for login');
            $table->text('credentials')->nullable();
            $table->timestamp('added')->useCurrent();
            $table->timestamp('lastaccess')->nullable()->useCurrent()->index('validated');
            $table->text('credentials2')->nullable()->comment('For Link logins');
            $table->timestamp('credentialsrotated')->nullable()->comment('For Link logins');
            $table->string('salt')->nullable();

            $table->unique(['uid', 'type'], 'email');
            $table->unique(['userid', 'type', 'uid'], 'userid_3');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_logins');
    }
};
