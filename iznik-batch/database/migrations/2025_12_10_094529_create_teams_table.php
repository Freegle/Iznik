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
        if (Schema::hasTable('teams')) {
            return;
        }

        Schema::create('teams', function (Blueprint $table) {
            $table->comment('Users who have particular roles in the organisation');
            $table->bigIncrements('id');
            $table->string('name', 80)->unique('name');
            $table->text('description')->nullable();
            $table->enum('type', ['Team', 'Role'])->default('Team');
            $table->string('email')->nullable();
            $table->boolean('active')->default(true);
            $table->string('wikiurl')->nullable();
            $table->boolean('supporttools')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
