<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * memberships.rippled - marks a membership that was auto-created because the user's post
 * rippled into that group (Rippling Out, PR #855 mitigations). Lets consuming queries that
 * have no other suppressible flag treat ripple-added memberships differently from real ones:
 * BirthdayService excludes them (no birthday email "from" a group the user never knowingly
 * joined), and the backfill command identifies them.
 *
 * NOT NULL DEFAULT 0 so every existing/real membership reads as 0 (not rippled). Trailing/
 * positioned bool -> INSTANT add in MySQL 8.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('memberships', 'rippled')) {
            Schema::table('memberships', function (Blueprint $t) {
                $t->boolean('rippled')->default(false)->after('volunteeringallowed');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('memberships', 'rippled')) {
            Schema::table('memberships', function (Blueprint $t) {
                $t->dropColumn('rippled');
            });
        }
    }
};
