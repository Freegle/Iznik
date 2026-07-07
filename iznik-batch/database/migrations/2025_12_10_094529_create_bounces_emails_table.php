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
        if (Schema::hasTable('bounces_emails')) {
            return;
        }

        Schema::create('bounces_emails', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('emailid');
            $table->timestamp('date')->useCurrent();
            $table->text('reason')->nullable();
            $table->boolean('permanent')->default(false);
            $table->boolean('reset')->default(false)->comment('If we have reset bounces for this email');

            $table->index(['emailid', 'date'], 'emailid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bounces_emails');
    }
};
