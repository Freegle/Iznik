<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('concern_keywords')) {
            return;
        }

        Schema::create('concern_keywords', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('keyword', 255);
            $table->string('substance', 255)->nullable();
            $table->enum('category', [
                'substance_regulated',
                'substance_reportable',
                'substance_medicine',
                'scam',
                'review',
                'allowed',
            ]);
            $table->enum('match_mode', ['fuzzy', 'literal', 'regex'])->default('literal');
            $table->text('exclude')->nullable();
            $table->enum('scope', ['global', 'group'])->default('global');
            $table->unsignedInteger('group_id')->nullable();
            $table->enum('action', ['block', 'flag'])->default('flag');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['keyword', 'scope', 'group_id']);
            $table->index(['scope', 'group_id']);
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concern_keywords');
    }
};
