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
        if (Schema::hasTable('memberships_yahoo_dump')) {
            return;
        }

        Schema::create('memberships_yahoo_dump', function (Blueprint $table) {
            $table->comment('Copy of last member sync from Yahoo');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('groupid')->unique('groupid');
            $table->longText('members');
            $table->timestamp('lastupdated')->useCurrentOnUpdate()->useCurrent();
            $table->timestamp('lastprocessed')->nullable()->index('lastprocessed')->comment('When this was last processed into the main tables');
            $table->timestamp('synctime')->nullable()->comment('Time on client when sync started');
            $table->tinyInteger('backgroundok')->default(1);
            $table->boolean('needsprocessing')->nullable()->storedAs('(`lastupdated` > `lastprocessed`)')->index('needsprocessing');

            $table->index(['lastupdated', 'lastprocessed'], 'lastupdated');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('memberships_yahoo_dump');
    }
};
