<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHICH community signed each onboarding tip off, and how we decided.
 *
 * The sign-off is meant to come from the member's home community - the one whose
 * catchment actually contains where they live. That used to be picked by centre
 * distance, which gets big catchments wrong (a member inside Bristol's area can
 * be nearer a smaller neighbour's centre). Now it is a containment test, and
 * these columns let the sysadmin Sign-off tab show whether that is holding up in
 * practice: how often we resolve a real home group, how often we fall back to
 * nearest-centre, and how often we find nobody and use the plain Freegle voice.
 *
 * Both nullable - existing rows keep NULL and nothing changes behaviourally.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reengage')) {
            return;
        }

        Schema::table('reengage', function (Blueprint $table) {
            if (! Schema::hasColumn('reengage', 'volunteer_groupid')) {
                // The community whose volunteer signed. No FK: groups get deleted
                // and we would rather keep the analytics row than cascade it away.
                $table->unsignedBigInteger('volunteer_groupid')->nullable()->after('segment');
            }

            if (! Schema::hasColumn('reengage', 'volunteer_source')) {
                // 'home'    - catchment contains the member's location (what we want)
                // 'nearest' - no catchment matched; nearest centre used instead
                // 'unknown' - no usable location for the member, so untested
                // 'none'    - no eligible volunteer at all; plain Freegle sign-off
                $table->string('volunteer_source', 16)->nullable()->after('volunteer_groupid');
            }
        });

        // The dashboard groups by source over a date range.
        Schema::table('reengage', function (Blueprint $table) {
            $indexes = Schema::getConnection()
                ->getDoctrineSchemaManager()
                ->listTableIndexes('reengage');

            if (! array_key_exists('volunteer_source_sentat', $indexes)) {
                $table->index(['volunteer_source', 'sentat'], 'volunteer_source_sentat');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('reengage')) {
            return;
        }

        Schema::table('reengage', function (Blueprint $table) {
            if (Schema::hasColumn('reengage', 'volunteer_source')) {
                $table->dropIndex('volunteer_source_sentat');
                $table->dropColumn('volunteer_source');
            }
            if (Schema::hasColumn('reengage', 'volunteer_groupid')) {
                $table->dropColumn('volunteer_groupid');
            }
        });
    }
};
