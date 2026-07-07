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
        if (Schema::hasTable('users_stories')) {
            return;
        }

        Schema::create('users_stories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
            $table->timestamp('date')->useCurrentOnUpdate()->useCurrent();
            $table->boolean('public')->default(true);
            $table->boolean('reviewed')->default(false);
            $table->string('headline');
            $table->text('story');
            $table->tinyInteger('tweeted')->default(0);
            $table->boolean('mailedtocentral')->default(false)->comment('Mailed to groups mailing list');
            $table->boolean('mailedtomembers')->nullable()->default(false);
            $table->boolean('newsletterreviewed')->default(false);
            $table->boolean('newsletter')->default(false);
            $table->unsignedBigInteger('reviewedby')->nullable()->index('reviewedby');
            $table->unsignedBigInteger('newsletterreviewedby')->nullable()->index('newsletterreviewedby');
            $table->timestamp('updated')->useCurrentOnUpdate()->nullable();
            $table->boolean('fromnewsfeed')->default(false);

            $table->index(['date', 'reviewed'], 'date');
            $table->index(['reviewed', 'public', 'newsletterreviewed'], 'reviewed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_stories');
    }
};
