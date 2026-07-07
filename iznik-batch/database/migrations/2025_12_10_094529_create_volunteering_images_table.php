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
        if (Schema::hasTable('volunteering_images')) {
            return;
        }

        Schema::create('volunteering_images', function (Blueprint $table) {
            $table->comment('Attachments parsed out from messages and resized');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('opportunityid')->nullable()->index('incomingid')->comment('id in the volunteering table');
            $table->string('contenttype', 80);
            $table->tinyInteger('archived')->nullable()->default(0);
            $table->binary('data')->nullable();
            $table->mediumText('identification')->nullable();
            $table->string('hash', 16)->nullable()->index('hash');
            $table->string('externaluid', 64)->nullable();
            $table->text('externalmods')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('volunteering_images');
    }
};
