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
        if (Schema::hasTable('stats')) {
            return;
        }

        Schema::create('stats', function (Blueprint $table) {
            $table->comment('Stats information used for dashboard');
            $table->bigIncrements('id');
            $table->date('date');
            $table->date('end');
            $table->unsignedBigInteger('groupid')->index('groupid');
            $table->enum('type', ['ApprovedMessageCount', 'SpamMessageCount', 'MessageBreakdown', 'SpamMemberCount', 'PostMethodBreakdown', 'YahooDeliveryBreakdown', 'YahooPostingBreakdown', 'ApprovedMemberCount', 'SupportQueries', 'Happy', 'Fine', 'Unhappy', 'Searches', 'Activity', 'Weight', 'Outcomes', 'Replies', 'ActiveUsers']);
            $table->unsignedBigInteger('count')->nullable();
            $table->mediumText('breakdown')->nullable();

            $table->unique(['date', 'type', 'groupid'], 'date');
            $table->index(['type', 'date', 'groupid'], 'type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stats');
    }
};
