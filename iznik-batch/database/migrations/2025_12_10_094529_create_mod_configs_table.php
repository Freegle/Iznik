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
        if (Schema::hasTable('mod_configs')) {
            return;
        }

        Schema::create('mod_configs', function (Blueprint $table) {
            $table->comment('Configurations for use by moderators');
            $table->bigIncrements('id')->unique('id')->comment('Unique ID of config');
            $table->string('name')->comment('Name of config set');
            $table->unsignedBigInteger('createdby')->nullable()->index('createdby')->comment('Moderator ID who created it');
            $table->enum('fromname', ['My name', 'Groupname Moderator'])->default('My name');
            $table->enum('ccrejectto', ['Nobody', 'Me', 'Specific'])->default('Nobody');
            $table->string('ccrejectaddr');
            $table->enum('ccfollowupto', ['Nobody', 'Me', 'Specific'])->default('Nobody');
            $table->string('ccfollowupaddr');
            $table->enum('ccrejmembto', ['Nobody', 'Me', 'Specific'])->default('Nobody');
            $table->string('ccrejmembaddr');
            $table->enum('ccfollmembto', ['Nobody', 'Me', 'Specific'])->default('Nobody');
            $table->string('ccfollmembaddr');
            $table->boolean('protected')->default(false)->comment('Protect from edit?');
            $table->mediumText('messageorder')->nullable()->comment('CSL of ids of standard messages in order in which they should appear');
            $table->string('network');
            $table->boolean('coloursubj')->default(true);
            $table->string('subjreg', 1024)->default('^(OFFER|WANTED|TAKEN|RECEIVED) *[\\\\:-].*\\\\(.*\\\\)');
            $table->integer('subjlen')->default(68);
            $table->boolean('default')->default(false)->index('default')->comment('Default configs are always visible');
            $table->boolean('chatread')->default(false);

            $table->primary(['id']);
            $table->index(['id', 'createdby'], 'uniqueid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mod_configs');
    }
};
