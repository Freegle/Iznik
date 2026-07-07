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
        if (Schema::hasTable('memberships_yahoo')) {
            return;
        }

        Schema::create('memberships_yahoo', function (Blueprint $table) {
            $table->comment('Which groups users are members of');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('membershipid');
            $table->enum('role', ['Member', 'Moderator', 'Owner'])->default('Member')->index('role');
            $table->enum('collection', ['Approved', 'Pending', 'Banned'])->default('Approved')->index('groupid');
            $table->timestamp('added')->useCurrent();
            $table->unsignedBigInteger('emailid')->index('emailid')->comment('Which of their emails they use on this group');
            $table->string('yahooAlias', 80)->nullable()->index('yahooalias');
            $table->enum('yahooPostingStatus', ['MODERATED', 'DEFAULT', 'PROHIBITED', 'UNMODERATED'])->nullable()->index('yahoopostingstatus')->comment('Yahoo mod status if applicable');
            $table->enum('yahooDeliveryType', ['DIGEST', 'NONE', 'SINGLE', 'ANNOUNCEMENT'])->nullable()->index('yahoodeliverytype')->comment('Yahoo delivery settings if applicable');
            $table->tinyInteger('syncdelete')->default(0)->comment('Used during member sync');
            $table->string('yahooapprove')->nullable()->comment('For Yahoo groups, email to approve member if known and relevant');
            $table->string('yahooreject')->nullable()->comment('For Yahoo groups, email to reject member if known and relevant');
            $table->string('joincomment')->nullable()->comment('Any joining comment for this member');

            $table->unique(['membershipid', 'emailid'], 'membershipid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memberships_yahoo');
    }
};
