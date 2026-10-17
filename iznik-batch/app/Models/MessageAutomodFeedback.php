<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $automodid The messages_automod row this feedback is about.
 * @property string $node The chart node flagged as wrong.
 * @property int $userid The moderator who gave the feedback.
 * @property \Illuminate\Support\Carbon $created
 * @property-read \App\Models\MessageAutomod $automod
 * @property-read \App\Models\User $user
 * @mixin \Eloquent
 */
class MessageAutomodFeedback extends Model
{
    protected $table = 'messages_automod_feedback';
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = [
        'created' => 'datetime',
    ];

    /**
     * The automod run this feedback is about.
     */
    public function automod(): BelongsTo
    {
        return $this->belongsTo(MessageAutomod::class, 'automodid');
    }

    /**
     * The moderator who gave the feedback.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid');
    }
}
