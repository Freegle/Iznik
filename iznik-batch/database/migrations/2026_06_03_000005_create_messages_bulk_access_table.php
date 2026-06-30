<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The offerer's private collection / access instructions for a bulk offer
 * (address, gate code, etc.), revealed to a recipient only once an item is
 * promised (Reserved).
 *
 * Replaces the former `messages.accessinstructions` column so the bulk-offer
 * feature adds NO column to the core `messages` table - keeping the feature
 * additive. One row per offer (unique msgid). No FK to `messages` (core table
 * untouched); the row is created on demand and is harmless if the post is later
 * removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages_bulk_access', function (Blueprint $table) {
            $table->comment('Per-offer private access/collection instructions for bulk offers.');
            $table->bigIncrements('id');
            $table->unsignedBigInteger('msgid');
            $table->text('accessinstructions')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('msgid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_bulk_access');
    }
};
