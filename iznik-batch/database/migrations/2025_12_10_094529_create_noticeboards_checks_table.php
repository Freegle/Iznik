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
        if (Schema::hasTable('noticeboards_checks')) {
            return;
        }

        Schema::create('noticeboards_checks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('noticeboardid')->index('noticeboardid');
            $table->unsignedBigInteger('userid')->nullable()->index('checkedby');
            $table->timestamp('askedat')->nullable();
            $table->timestamp('checkedat')->nullable();
            $table->boolean('inactive');
            $table->boolean('refreshed')->default(false);
            $table->boolean('declined')->default(false);
            $table->text('comments')->nullable();
            $table->timestamp('updated')->useCurrentOnUpdate()->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('noticeboards_checks');
    }
};
