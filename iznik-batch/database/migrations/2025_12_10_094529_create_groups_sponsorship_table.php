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
        if (Schema::hasTable('groups_sponsorship')) {
            return;
        }

        Schema::create('groups_sponsorship', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('groupid');
            $table->string('name');
            $table->string('linkurl')->nullable();
            $table->date('startdate');
            $table->date('enddate');
            $table->string('contactname');
            $table->string('contactemail');
            $table->integer('amount');
            $table->text('notes')->nullable();
            $table->string('imageurl')->nullable();
            $table->boolean('visible')->default(true);
            $table->string('tagline')->nullable();
            $table->text('description')->nullable();

            $table->index(['groupid', 'startdate', 'enddate', 'visible'], 'groupid');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('groups_sponsorship');
    }
};
