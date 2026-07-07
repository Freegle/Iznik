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
        if (Schema::hasTable('messages_edits')) {
            return;
        }

        Schema::create('messages_edits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid')->index('msgid');
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
            $table->unsignedBigInteger('byuser')->nullable()->index('byuser');
            $table->boolean('reviewrequired')->default(false);
            $table->timestamp('revertedat')->nullable();
            $table->timestamp('approvedat')->nullable();
            $table->longText('oldtext')->nullable();
            $table->longText('newtext')->nullable();
            $table->string('oldsubject')->nullable();
            $table->string('newsubject')->nullable();
            $table->enum('oldtype', ['Offer', 'Taken', 'Wanted', 'Received', 'Admin', 'Other'])->nullable();
            $table->enum('newtype', ['Offer', 'Taken', 'Wanted', 'Received', 'Admin', 'Other'])->nullable();
            $table->text('olditems')->nullable();
            $table->text('newitems')->nullable();
            $table->text('oldimages')->nullable();
            $table->text('newimages')->nullable();
            $table->unsignedBigInteger('oldlocation')->nullable();
            $table->unsignedBigInteger('newlocation')->nullable();

            $table->index(['timestamp', 'reviewrequired'], 'timestamp');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages_edits');
    }
};
