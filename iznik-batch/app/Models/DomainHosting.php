<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What hosting:scan-domains found out about one web domain. Keyed by the domain and holding nothing
 * about any member; the Go API joins a member's preferred email domain to it.
 *
 * @see ../../database/migrations/2026_10_30_000001_create_domain_hosting_table.php
 *
 * A plain model, not Auditable: the rows are facts about public websites, rewritten on every check.
 *
 * @property int $id
 * @property string $domain
 * @property bool $wordpress
 * @property bool $krystal
 * @property string|null $nameserver
 * @property string|null $server
 * @property int|null $status
 * @property \Illuminate\Support\Carbon $checked_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class DomainHosting extends Model
{
    protected $table = 'domain_hosting';

    public $timestamps = false;

    protected $fillable = ['domain', 'wordpress', 'krystal', 'nameserver', 'server', 'status', 'checked_at'];

    protected $casts = [
        'wordpress' => 'boolean',
        'krystal' => 'boolean',
        'status' => 'integer',
        'checked_at' => 'datetime',
        'created_at' => 'datetime',
    ];
}
