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
        if (Schema::hasTable('users_dashboard')) {
            return;
        }

        Schema::create('users_dashboard', function (Blueprint $table) {
            $table->comment('Cached copy of mod dashboard, gen in cron');
            $table->bigIncrements('id');
            $table->enum('type', ['Reuse', 'Freegle', 'Other'])->nullable();
            $table->unsignedBigInteger('userid')->nullable()->index('userid');
            $table->boolean('systemwide')->nullable()->index('systemwide');
            $table->unsignedBigInteger('groupid')->nullable()->index('groupid');
            $table->string('start', 50)->nullable();
            $table->timestamp('timestamp')->useCurrentOnUpdate()->useCurrent();
            $table->string('key', 128)->unique('key');
            $table->longText('data')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users_dashboard');
    }
};
