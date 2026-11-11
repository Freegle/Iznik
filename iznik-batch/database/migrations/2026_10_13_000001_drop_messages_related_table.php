<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * messages_related linked a TAKEN or RECEIVED post back to the OFFER or WANTED
 * it closed, by subject distance, so the chase-up could skip posts that were
 * already done. Only V1 wrote it, and V1 went on 2026-07-09; the chase-up now
 * only considers platform posts, which record their outcomes through the app,
 * and those are excluded by messages_outcomes already. The reader is gone, so
 * the table goes too (#1210).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('messages_related');
    }

    public function down(): void
    {
        if (Schema::hasTable('messages_related')) {
            return;
        }

        Schema::create('messages_related', function (Blueprint $table) {
            $table->comment('Messages which are related to each other');
            $table->unsignedBigInteger('id1')->index('id1');
            $table->unsignedBigInteger('id2')->index('id2');

            $table->unique(['id1', 'id2'], 'id1_2');
            $table->foreign(['id1'])->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
            $table->foreign(['id2'])->references(['id'])->on('messages')->onUpdate('no action')->onDelete('cascade');
        });
    }
};
