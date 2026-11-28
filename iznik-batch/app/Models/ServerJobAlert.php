<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One late-job alert that was showing when servers:sample ran.
 *
 * @see ../../database/migrations/2026_11_26_000002_create_server_job_alerts.php
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $sampled_at
 * @property string $slug
 */
class ServerJobAlert extends Model
{
    protected $table = 'server_job_alerts';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'sampled_at' => 'datetime',
    ];
}
