<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailTrackingImage extends Model
{
    protected $table = 'email_tracking_images';

    public $timestamps = false;

    protected $fillable = [
        'email_tracking_id',
        'image_position',
        'estimated_scroll_percent',
        'loaded_at',
    ];

    protected $casts = [
        'loaded_at' => 'datetime',
    ];

    /**
     * Get the parent tracking record
     */
    public function tracking(): BelongsTo
    {
        return $this->belongsTo(EmailTracking::class, 'email_tracking_id');
    }
}
