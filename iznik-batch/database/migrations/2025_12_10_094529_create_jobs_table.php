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
        if (Schema::hasTable('jobs')) {
            return;
        }

        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('location', 256)->nullable();
            $table->string('title')->nullable();
            $table->string('city', 256)->nullable();
            $table->string('state', 256)->nullable();
            $table->string('zip', 32)->nullable();
            $table->string('country', 256)->nullable();
            $table->string('job_type', 32)->nullable();
            $table->dateTime('posted_at')->nullable();
            $table->string('job_reference', 32)->nullable()->unique('job_reference');
            $table->string('company', 256)->nullable();
            $table->string('mobile_friendly_apply', 32)->nullable();
            $table->string('category', 64)->nullable();
            $table->string('html_jobs', 32)->nullable();
            $table->string('url', 1024)->nullable();
            $table->text('body')->nullable();
            $table->decimal('cpc', 4, 4)->nullable();
            $table->geography('geometry', null, 3857);
            $table->timestamp('seenat')->nullable()->index('seenat');
            $table->integer('clickability')->default(0);
            $table->string('bodyhash', 32)->nullable()->index('bodyhash');
            $table->boolean('visible')->default(false);

            $table->spatialIndex(['geometry'], 'geometry');
            $table->unique(['job_reference'], 'job_reference_2');
            $table->unique(['location', 'title'], 'location');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
