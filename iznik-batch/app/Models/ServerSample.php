<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One reading of load, processor time, memory and disk for one host, written by servers:sample.
 *
 * @see ../../database/migrations/2026_11_26_000001_create_server_samples.php
 *
 * @property int $id
 * @property string $host
 * @property \Illuminate\Support\Carbon $sampled_at
 * @property int $cpus
 * @property float $load1
 * @property float $load5
 * @property float $load15
 * @property int $cpu_total
 * @property int $cpu_idle
 * @property int $mem_total_mb
 * @property int $mem_available_mb
 * @property int $swap_total_mb
 * @property int $swap_used_mb
 * @property array<int, array{mount: string, size_gb: float, used_gb: float}> $disks
 */
class ServerSample extends Model
{
    protected $table = 'server_samples';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'sampled_at' => 'datetime',
        'cpus' => 'integer',
        'load1' => 'float',
        'load5' => 'float',
        'load15' => 'float',
        'cpu_total' => 'integer',
        'cpu_idle' => 'integer',
        'mem_total_mb' => 'integer',
        'mem_available_mb' => 'integer',
        'swap_total_mb' => 'integer',
        'swap_used_mb' => 'integer',
        'disks' => 'array',
    ];
}
