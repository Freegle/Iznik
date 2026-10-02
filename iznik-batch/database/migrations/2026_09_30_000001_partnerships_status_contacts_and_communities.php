<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What the Partnerships page needs to replace the team's spreadsheet.
 *
 *   - status: where the deal is in the pipeline. Quoted, then agreed in principle, then
 *     confirmed, then paid - or overdue. Replaces the agreed flag and its date.
 *   - renewal: how likely the council is to renew, as a traffic light.
 *   - fullprice: the price before any bulk discount, so we keep what we told the council.
 *   - partnerships_contacts: a deal can have several contacts, each with a role, and all of
 *     them get the statistics.
 *   - partnerships_groups.source: whether a community is covered because it is inside the
 *     council boundary, was added by hand, or was left out by hand. A left-out row stays so
 *     that re-checking the boundary does not put it back.
 *   - partnerships_groups.overlap: how much of the community lies inside the boundary. The
 *     statistics weight each community by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('partnerships', 'status')) {
            Schema::table('partnerships', function (Blueprint $table) {
                $table->enum('status', ['Quoted', 'InPrinciple', 'Confirmed', 'Paid', 'Overdue'])
                    ->default('Quoted')->after('amount');
                $table->enum('renewal', ['Likely', 'Unsure', 'Unlikely'])->nullable()->after('status');
                $table->decimal('fullprice', 10, 2)->nullable()->after('renewal')
                    ->comment('Price before any bulk discount, as quoted to the council');
            });

            if (Schema::hasColumn('partnerships', 'agreed')) {
                DB::table('partnerships')->where('agreed', 1)->update(['status' => 'Confirmed']);
            }
        }

        if (!Schema::hasTable('partnerships_contacts')) {
            Schema::create('partnerships_contacts', function (Blueprint $table) {
                $table->comment('People at the council we deal with; all of them get the statistics');
                $table->bigIncrements('id');
                $table->unsignedBigInteger('partnershipid');
                $table->string('name')->nullable();
                $table->string('email')->nullable();
                $table->enum('role', ['Waste', 'Finance', 'Other'])->default('Waste');

                $table->index(['partnershipid'], 'partnershipid');
                $table->foreign('partnershipid')->references('id')->on('partnerships')->onDelete('cascade');
            });

            if (Schema::hasColumn('partnerships', 'contactemail')) {
                DB::statement(
                    "INSERT INTO partnerships_contacts (partnershipid, name, email, role)
                     SELECT id, contactname, contactemail, 'Waste' FROM partnerships
                     WHERE COALESCE(contactname, '') != '' OR COALESCE(contactemail, '') != ''"
                );
            }
        }

        foreach (['agreed', 'agreeddate', 'contactname', 'contactemail'] as $column) {
            if (Schema::hasColumn('partnerships', $column)) {
                Schema::table('partnerships', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }

        if (!Schema::hasColumn('partnerships_groups', 'source')) {
            Schema::table('partnerships_groups', function (Blueprint $table) {
                $table->enum('source', ['Boundary', 'Added', 'Removed'])->default('Boundary')->after('groupid');
                $table->decimal('overlap', 5, 4)->nullable()->after('source')
                    ->comment('Fraction of the community inside the council boundary');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('partnerships_groups', 'source')) {
            Schema::table('partnerships_groups', function (Blueprint $table) {
                $table->dropColumn(['source', 'overlap']);
            });
        }

        if (!Schema::hasColumn('partnerships', 'agreed')) {
            Schema::table('partnerships', function (Blueprint $table) {
                $table->boolean('agreed')->default(false);
                $table->date('agreeddate')->nullable();
                $table->string('contactname')->nullable();
                $table->string('contactemail')->nullable();
            });

            DB::table('partnerships')->whereIn('status', ['Confirmed', 'Paid', 'Overdue'])->update(['agreed' => 1]);
        }

        Schema::dropIfExists('partnerships_contacts');

        if (Schema::hasColumn('partnerships', 'status')) {
            Schema::table('partnerships', function (Blueprint $table) {
                $table->dropColumn(['status', 'renewal', 'fullprice']);
            });
        }
    }
};
