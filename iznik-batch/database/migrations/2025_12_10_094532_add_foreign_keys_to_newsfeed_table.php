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
        Schema::table('newsfeed', function (Blueprint $table) {
            $table->foreign(['userid'])->references(['id'])->on('users')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['msgid'])->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['groupid'])->references(['id'])->on('groups')->onUpdate('no action')->onDelete('cascade');
            if (Schema::hasColumn('newsfeed', 'eventid')) {
                $table->foreign(['eventid'])->references(['id'])->on('communityevents')->onUpdate('no action')->onDelete('cascade');
            }
            if (Schema::hasColumn('newsfeed', 'volunteeringid')) {
                $table->foreign(['volunteeringid'])->references(['id'])->on('volunteering')->onUpdate('no action')->onDelete('cascade');
            }
            if (Schema::hasColumn('newsfeed', 'publicityid')) {
                $table->foreign(['publicityid'])->references(['id'])->on('groups_facebook_toshare')->onUpdate('no action')->onDelete('cascade');
            }
            if (Schema::hasColumn('newsfeed', 'storyid')) {
                $table->foreign(['storyid'])->references(['id'])->on('users_stories')->onUpdate('no action')->onDelete('cascade');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('newsfeed', function (Blueprint $table) {
        });
    }
};
