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
        if (Schema::hasTable('groups')) {
            return;
        }

        Schema::create('groups', function (Blueprint $table) {
            $table->comment('The different groups that we host');
            $table->bigIncrements('id')->unique('id')->comment('Unique ID of group');
            $table->unsignedBigInteger('legacyid')->nullable()->index('legacyid')->comment('(Freegle) Groupid on old system');
            $table->string('nameshort', 80)->nullable()->unique('nameshort')->comment('A short name for the group');
            $table->string('namefull')->nullable()->unique('namefull')->comment('A longer name for the group');
            $table->string('nameabbr', 5)->nullable()->comment('An abbreviated name for the group');
            $table->string('namealt')->nullable()->index('namealt')->comment('Alternative name, e.g. as used by GAT');
            $table->longText('settings')->nullable()->comment('JSON-encoded settings for group');
            $table->set('type', ['Reuse', 'Freegle', 'Other', 'UnitTest'])->default('Other')->index('type')->comment('High-level characteristics of the group');
            $table->enum('region', ['East', 'East Midlands', 'West Midlands', 'North East', 'North West', 'Northern Ireland', 'South East', 'South West', 'London', 'Wales', 'Yorkshire and the Humber', 'Scotland'])->nullable()->comment('Freegle only');
            $table->boolean('onyahoo')->default(false)->comment('Whether this group is also on Yahoo Groups');
            $table->tinyInteger('onhere')->default(0)->comment('Whether this group is available on this platform');
            $table->boolean('ontn')->default(false);
            $table->boolean('showonyahoo')->default(true)->comment('(Freegle) Whether to show Yahoo links');
            $table->timestamp('lastyahoomembersync')->nullable()->comment('When we last synced approved members');
            $table->timestamp('lastyahoomessagesync')->nullable()->comment('When we last synced approved messages');
            $table->decimal('lat', 10, 6)->nullable();
            $table->decimal('lng', 10, 6)->nullable()->index('lng');
            $table->longText('poly')->nullable()->comment('Any polygon defining core area');
            $table->longText('polyofficial')->nullable()->comment('If present, GAT area and poly is catchment');
            $table->geography('polyindex', null, 3857);
            $table->string('confirmkey', 32)->nullable()->comment('Key used to verify some operations by email');
            $table->tinyInteger('publish')->default(1)->comment('(Freegle) Whether this group is visible to members');
            $table->boolean('listable')->default(true)->comment('Whether shows up in groups API call');
            $table->tinyInteger('onmap')->default(1)->comment('(Freegle) Whether to show on the map of groups');
            $table->tinyInteger('licenserequired')->nullable()->default(1)->comment('Whether a license is required for this group');
            $table->timestamp('trial')->nullable()->useCurrent()->comment('For ModTools, when a trial was started');
            $table->date('licensed')->nullable()->comment('For ModTools, when a group was licensed');
            $table->date('licenseduntil')->nullable()->comment('For ModTools, when a group is licensed until');
            $table->integer('membercount')->default(0)->comment('Automatically refreshed');
            $table->integer('modcount')->default(0);
            $table->unsignedBigInteger('profile')->nullable()->index('profile');
            $table->unsignedBigInteger('cover')->nullable()->index('cover');
            $table->string('tagline')->nullable()->comment('(Freegle) One liner slogan for this group');
            $table->text('description')->nullable();
            $table->date('founded')->nullable();
            $table->timestamp('lasteventsroundup')->nullable()->comment('(Freegle) Last event roundup sent');
            $table->timestamp('lastvolunteeringroundup')->nullable();
            $table->string('external')->nullable()->comment('Link to some other system e.g. Norfolk');
            $table->string('contactmail', 80)->nullable()->comment('For external sites');
            $table->text('welcomemail')->nullable()->comment('(Freegle) Text for welcome mail');
            $table->decimal('activitypercent', 10)->nullable()->comment('Within a group type, the proportion of overall activity that this group accounts for.');
            $table->integer('fundingtarget')->default(0);
            $table->timestamp('lastmoderated')->nullable()->comment('Last moderated inc Yahoo');
            $table->timestamp('lastmodactive')->nullable()->comment('Last mod active on here');
            $table->integer('activeownercount')->nullable()->comment('How many currently active owners');
            $table->integer('activemodcount')->nullable()->comment('How many currently active mods');
            $table->integer('backupownersactive')->default(0);
            $table->integer('backupmodsactive')->default(0);
            $table->timestamp('lastautoapprove')->nullable();
            $table->timestamp('affiliationconfirmed')->nullable();
            $table->unsignedBigInteger('affiliationconfirmedby')->nullable()->index('affiliationconfirmedby');
            $table->boolean('mentored')->default(false)->index('mentored');
            $table->boolean('seekingmods')->default(false);
            $table->boolean('privategroup')->default(false);
            $table->unsignedBigInteger('defaultlocation')->nullable()->index('defaultlocation');
            $table->enum('overridemoderation', ['None', 'ModerateAll'])->default('None');
            $table->decimal('altlat', 10, 6)->nullable();
            $table->decimal('altlng', 10, 6)->nullable();
            $table->date('welcomereview')->nullable();
            $table->boolean('microvolunteering')->default(false);
            $table->json('microvolunteeringoptions')->nullable();
            $table->boolean('autofunctionoverride')->default(false);
            $table->geometry('postvisibility', 'polygon')->nullable();
            $table->boolean('onlovejunk')->default(true);
            $table->json('rules')->nullable();

            $table->index(['altlat', 'altlng'], 'altlat');
            $table->index(['lat', 'lng'], 'lat');
            $table->spatialIndex(['polyindex'], 'polyindex');
            $table->primary(['id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
