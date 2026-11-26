<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * server_samples - one reading of load, processor time, memory and disk for one host.
 *
 * servers:sample writes a row per host every fifteen minutes, and servers:rightsize-report reads
 * them once a week to say whether each server is the right size. Nothing else kept this history:
 * the hosts hold no record of their own load.
 *
 * cpu_total and cpu_idle are the kernel's running counters since boot, so the share of processor
 * time in use between two readings is worked out from the difference. disks is a JSON list of
 * {mount, size_gb, used_gb}. Rows older than the retention setting are removed by servers:sample.
 *
 * Production SQL: 2026_11_26_000001_create_server_samples_migration.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('server_samples')) {
            return;
        }

        Schema::create('server_samples', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('host', 64);
            $table->timestamp('sampled_at')->useCurrent();
            $table->unsignedSmallInteger('cpus');
            $table->decimal('load1', 8, 2);
            $table->decimal('load5', 8, 2);
            $table->decimal('load15', 8, 2);
            $table->unsignedBigInteger('cpu_total');
            $table->unsignedBigInteger('cpu_idle');
            $table->unsignedInteger('mem_total_mb');
            $table->unsignedInteger('mem_available_mb');
            $table->unsignedInteger('swap_total_mb');
            $table->unsignedInteger('swap_used_mb');
            $table->json('disks');
            $table->index(['host', 'sampled_at'], 'server_samples_host_sampled_at');
            $table->index('sampled_at', 'server_samples_sampled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_samples');
    }
};
