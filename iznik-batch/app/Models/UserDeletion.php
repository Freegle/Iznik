<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A tombstone for a user we have destroyed, so partners can destroy their copy.
 *
 * Partners (TrashNothing) poll /api/changes for everything that has moved since a
 * timestamp. Users are reported from users.lastupdated, which only works while the
 * row exists - so without this table a forgotten-then-purged member just stops
 * appearing, and the partner keeps a copy of someone who asked to be gone.
 *
 * Written wherever we forget or hard-delete a user; read by the Go /api/changes
 * handler, which reports each row as a user change of type Deleted carrying only
 * the Freegle user id.
 *
 * Not Auditable, unlike most of our models: the rows are themselves an append-only
 * record of one event each, so auditing them would just duplicate every insert.
 *
 * @see ../../database/migrations/2026_08_08_000001_create_users_deletions_table.php
 * @property int $id
 * @property int $userid id in the users table - no FK, the row outlives the user
 * @property \Illuminate\Support\Carbon $timestamp
 * @property string $type Forgotten|Purged
 * @property string|null $reason
 * @mixin \Eloquent
 */
class UserDeletion extends Model
{
    public const TYPE_FORGOTTEN = 'Forgotten';
    public const TYPE_PURGED = 'Purged';

    protected $table = 'users_deletions';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
    ];

    /**
     * Record that a user has been destroyed.
     *
     * Called from every path that wipes or removes a user. Deliberately tolerant:
     * losing a tombstone must never be the thing that aborts a GDPR erasure, so a
     * failure here is logged and swallowed.
     */
    public static function record(int $userId, string $type, ?string $reason = NULL): void
    {
        try {
            self::create([
                'userid' => $userId,
                'timestamp' => now(),
                'type' => $type,
                'reason' => $reason,
            ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error("Failed to record deletion of user #{$userId}: " . $e->getMessage());
        }
    }
}
