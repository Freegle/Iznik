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
        if (Schema::hasTable('volunteering')) {
            return;
        }

        Schema::create('volunteering', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
            $table->boolean('pending')->default(false);
            $table->string('title', 80)->index('title');
            $table->boolean('online')->default(false);
            $table->text('location');
            $table->string('contactname', 80)->nullable();
            $table->string('contactphone', 80)->nullable();
            $table->string('contactemail', 80)->nullable();
            $table->string('contacturl')->nullable();
            $table->text('description')->nullable();
            $table->timestamp('added')->useCurrent();
            $table->tinyInteger('deleted')->default(0);
            $table->unsignedBigInteger('deletedby')->nullable()->index('deletedby');
            $table->timestamp('askedtorenew')->nullable();
            $table->timestamp('renewed')->nullable();
            $table->boolean('expired')->default(false);
            $table->text('timecommitment')->nullable();
            $table->unsignedBigInteger('heldby')->nullable()->index('heldby');
            $table->boolean('deletedcovid')->default(false)->comment('Deleted as part of reopening');
            $table->string('externalid')->nullable()->unique('externalid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('volunteering');
    }
};
