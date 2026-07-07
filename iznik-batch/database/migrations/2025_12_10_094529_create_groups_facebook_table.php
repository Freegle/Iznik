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
        if (Schema::hasTable('groups_facebook')) {
            return;
        }

        Schema::create('groups_facebook', function (Blueprint $table) {
            $table->bigIncrements('uid');
            $table->unsignedBigInteger('groupid')->index('groupid');
            $table->string('name', 80)->nullable();
            $table->enum('type', ['Page', 'Group'])->default('Page');
            $table->string('id', 60)->nullable();
            $table->text('token')->nullable();
            $table->timestamp('authdate')->nullable();
            $table->unsignedBigInteger('msgid')->nullable()->index('msgid')->comment('Last message posted');
            $table->timestamp('msgarrival')->nullable()->comment('Time of last message posted');
            $table->unsignedBigInteger('eventid')->nullable()->index('eventid')->comment('Last event tweeted');
            $table->tinyInteger('valid')->default(1);
            $table->text('lasterror')->nullable();
            $table->timestamp('lasterrortime')->nullable();
            $table->string('sharefrom', 40)->nullable()->default('134117207097')->comment('Facebook page to republish from');
            $table->timestamp('lastupdated')->nullable()->comment('From Graph API');
            $table->integer('postablecount')->default(0);

            $table->unique(['groupid', 'id'], 'groupid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('groups_facebook');
    }
};
