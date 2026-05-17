<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupDigest extends Model
{
    protected $table = 'groups_digests';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'msgdate' => 'datetime',
        'started' => 'datetime',
        'ended'   => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupid');
    }
}
