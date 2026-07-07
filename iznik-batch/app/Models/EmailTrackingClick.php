<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTrackingClick extends Model
{
    protected $table = 'email_tracking_clicks';

    public $timestamps = false;

    protected $fillable = [
        'email_tracking_id',
        'link_url',
        'link_position',
        'action',
        'ip_address',
        'user_agent',
        'clicked_at',
    ];

    protected $casts = [
        'clicked_at' => 'datetime',
    ];

    /**
     * Get the parent tracking record
     */
    public function tracking(): BelongsTo
    {
        return $this->belongsTo(EmailTracking::class, 'email_tracking_id');
    }
}
