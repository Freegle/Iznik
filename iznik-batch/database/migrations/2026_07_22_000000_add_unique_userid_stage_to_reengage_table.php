<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reengage', function (Blueprint $table) {
            // One row per (member, tip). This is the backstop that lets the send
            // path claim a tip with insertOrIgnore before spooling it, so an
            // overlapping run (a manual invocation racing the cron) or a process
            // that dies mid-send can never record or deliver the same onboarding
            // tip to a member twice.
            $table->unique(['userid', 'stage'], 'reengage_userid_stage_unique');
        });
    }

    public function down(): void
    {
        Schema::table('reengage', function (Blueprint $table) {
            $table->dropUnique('reengage_userid_stage_unique');
        });
    }
};
