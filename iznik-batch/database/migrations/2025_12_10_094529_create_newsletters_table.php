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
        if (Schema::hasTable('newsletters')) {
            return;
        }

        Schema::create('newsletters', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');
            $table->string('subject', 80);
            $table->text('textbody')->comment('For people who don\'t read HTML');
            $table->timestamp('created')->useCurrent();
            $table->timestamp('completed')->nullable();
            $table->unsignedBigInteger('uptouser')->nullable()->comment('User id we are upto, roughly');
            $table->enum('type', ['General', 'Stories', '', ''])->default('General');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('newsletters');
    }
};
