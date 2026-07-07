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
        if (Schema::hasTable('spam_users')) {
            return;
        }

        Schema::create('spam_users', function (Blueprint $table) {
            $table->comment('Users who are spammers or trusted');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->unique('userid');
            $table->unsignedBigInteger('byuserid')->nullable()->index('byuserid');
            $table->timestamp('added')->useCurrent()->index('added');
            $table->unsignedBigInteger('addedby')->nullable()->index('addedby');
            $table->enum('collection', ['Spammer', 'Whitelisted', 'PendingAdd', 'PendingRemove'])->default('Spammer')->index('collection');
            $table->string('reason')->nullable();
            $table->unsignedBigInteger('heldby')->nullable()->index('spam_users_ibfk_4');
            $table->timestamp('heldat')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spam_users');
    }
};
