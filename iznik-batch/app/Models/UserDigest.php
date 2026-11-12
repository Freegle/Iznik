<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tracks unified digest progress per user.
 *
 * Replaces the per-group tracking in groups_digests.
 */
class UserDigest extends Model
{
    protected $table = 'users_digests';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'lastmsgdate' => 'datetime',
        'lastsent' => 'datetime',
        // msgids the last daily digest left out at the post cap - see getPostsForUser().
        'carryover' => 'array',
        // msgids the reach gate held back from the daily digest that the cursor then passed -
        // see UnifiedDigestService::loadPostsForUser().
        'held_back' => 'array',
    ];

    /**
     * Get the user this digest tracker belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid');
    }
}
