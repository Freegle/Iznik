<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('charities')) {
            return;
        }

        Schema::create('charities', function (Blueprint $table) {
            $table->increments('id');
            $table->string('orgname', 255);
            $table->enum('orgtype', ['registered', 'other'])->default('registered');
            $table->string('charitynumber', 50)->nullable();
            $table->text('orgdetails')->nullable();
            $table->string('website', 255)->nullable();
            $table->string('social', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('contactemail', 255);
            $table->string('contactname', 255)->nullable();
            $table->unsignedInteger('userid')->nullable();
            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('status');
            $table->index('userid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charities');
    }
};
