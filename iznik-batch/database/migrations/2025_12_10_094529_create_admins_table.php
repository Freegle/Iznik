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
        if (Schema::hasTable('admins')) {
            return;
        }

        Schema::create('admins', function (Blueprint $table) {
            $table->comment('Try all means to reach people with these');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('createdby')->nullable()->index('createdby');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');
            $table->timestamp('created')->useCurrent();
            $table->unsignedBigInteger('editedby')->nullable()->index('editedby');
            $table->timestamp('editedat')->nullable();
            $table->timestamp('complete')->nullable();
            $table->text('subject');
            $table->text('text');
            $table->string('ctalink')->nullable();
            $table->string('ctatext')->nullable();
            $table->boolean('pending')->default(true);
            $table->unsignedBigInteger('parentid')->nullable()->index('parentid');
            $table->unsignedBigInteger('heldby')->nullable()->index('heldby');
            $table->timestamp('heldat')->nullable();
            $table->boolean('activeonly')->default(false);
            $table->timestamp('sendafter')->nullable();
            $table->boolean('essential')->default(true);

            $table->index(['complete', 'pending'], 'complete');
            $table->index(['groupid', 'complete', 'pending'], 'groupid_2');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
