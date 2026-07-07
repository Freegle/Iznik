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
        if (Schema::hasTable('stats_summaries')) {
            return;
        }

        Schema::create('stats_summaries', function (Blueprint $table) {
            $table->comment('Stats information used for dashboard');
            $table->bigIncrements('id');
            $table->date('start');
            $table->bigInteger('minusstart')->nullable()->comment('Negative timestamp for indexing');
            $table->enum('period', ['P7D', 'P1M', 'P1Y']);
            $table->unsignedBigInteger('groupid');
            $table->enum('type', ['ApprovedMessageCount', 'SpamMessageCount', 'MessageBreakdown', 'SpamMemberCount', 'PostMethodBreakdown', 'YahooDeliveryBreakdown', 'YahooPostingBreakdown', 'ApprovedMemberCount', 'SupportQueries', 'Happy', 'Fine', 'Unhappy', 'Searches', 'Activity', 'Weight', 'Outcomes', 'Replies', 'ActiveUsers']);
            $table->unsignedBigInteger('count')->nullable();
            $table->mediumText('breakdown')->nullable();

            $table->index(['minusstart', 'period', 'type'], 'minusstart');
            $table->unique(['start', 'period', 'type', 'groupid'], 'start');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stats_summaries');
    }
};
