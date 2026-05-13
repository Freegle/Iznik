<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Membership extends Model
{
    protected $table = 'memberships';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    public const ROLE_MEMBER = 'Member';
    public const ROLE_MODERATOR = 'Moderator';
    public const ROLE_OWNER = 'Owner';

    public const COLLECTION_APPROVED = 'Approved';
    public const COLLECTION_PENDING = 'Pending';
    public const COLLECTION_BANNED = 'Banned';

    public const EMAIL_FREQUENCY_NEVER = 0;
    public const EMAIL_FREQUENCY_IMMEDIATE = -1;
    public const EMAIL_FREQUENCY_HOURLY = 1;
    public const EMAIL_FREQUENCY_DAILY = 24;

    // Aliases for digest context.
    public const EMAIL_DIGEST_IMMEDIATE = self::EMAIL_FREQUENCY_IMMEDIATE;
    public const EMAIL_DIGEST_HOURLY = self::EMAIL_FREQUENCY_HOURLY;
    public const EMAIL_DIGEST_DAILY = self::EMAIL_FREQUENCY_DAILY;

    protected $casts = [
        'added' => 'datetime',
        'settings' => 'array',
        'eventsallowed' => 'boolean',
        'emailfrequency' => 'integer',
        'reviewrequestedat' => 'datetime',
        'reviewedat' => 'datetime',
    ];

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid');
    }

    /**
     * Get the group.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupid');
    }

    /**
     * Get the mod config.
     */
    public function config(): BelongsTo
    {
        return $this->belongsTo(ModConfig::class, 'configid');
    }

    /**
     * Scope to approved members.
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('collection', self::COLLECTION_APPROVED);
    }

    /**
     * Scope to pending members.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('collection', self::COLLECTION_PENDING);
    }

    /**
     * Scope to moderators.
     */
    public function scopeModerators(Builder $query): Builder
    {
        return $query->whereIn('role', [self::ROLE_MODERATOR, self::ROLE_OWNER]);
    }

    /**
     * Scope to owners.
     */
    public function scopeOwners(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_OWNER);
    }

    /**
     * Scope by email frequency.
     */
    public function scopeWithEmailFrequency(Builder $query, int $frequency): Builder
    {
        return $query->where('emailfrequency', $frequency);
    }

    /**
     * Scope to members who want digests at a specific frequency.
     */
    public function scopeDigestSubscribers(Builder $query, int $frequency): Builder
    {
        return $query->approved()
            ->withEmailFrequency($frequency)
            ->where('emailfrequency', '>', 0);
    }

    /**
     * Check if this is a moderator membership.
     */
    public function isModerator(): bool
    {
        return in_array($this->role, [self::ROLE_MODERATOR, self::ROLE_OWNER]);
    }

    /**
     * Check if this is an owner membership.
     */
    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }

    /**
     * Get a setting value.
     */
    public function getSetting(string $key, mixed $default = NULL): mixed
    {
        $settings = $this->settings ?? [];
        return $settings[$key] ?? $default;
    }

    /**
     * Check if this moderator is "active" (not a backup mod).
     *
     * Mirrors V1 User::activeModForGroup: use settings.active if present;
     * otherwise fall back to legacy settings.showmessages; otherwise default
     * to active. Members are always considered active.
     */
    public function isActiveMod(): bool
    {
        if (!$this->isModerator()) {
            return TRUE;
        }

        if ($this->settings === NULL) {
            return TRUE;
        }

        if (array_key_exists('active', $this->settings)) {
            return (bool) $this->settings['active'];
        }

        if (array_key_exists('showmessages', $this->settings)) {
            return (bool) $this->settings['showmessages'];
        }

        return TRUE;
    }

    /**
     * Scope to active moderators (not backup mods).
     *
     * Active when: settings is null, or settings.active is truthy, or
     * settings.active is missing and settings.showmessages is missing or
     * truthy. Matches V1 User::activeModForGroup.
     */
    public function scopeActiveModerators(Builder $query): Builder
    {
        return $query->whereIn('role', [self::ROLE_MODERATOR, self::ROLE_OWNER])
            ->where(function ($q) {
                $q->whereNull('settings')
                    ->orWhereRaw("JSON_EXTRACT(settings, '$.active') = true")
                    ->orWhereRaw("JSON_EXTRACT(settings, '$.active') = 1")
                    ->orWhere(function ($q2) {
                        $q2->whereRaw("JSON_EXTRACT(settings, '$.active') IS NULL")
                            ->where(function ($q3) {
                                $q3->whereRaw("JSON_EXTRACT(settings, '$.showmessages') IS NULL")
                                    ->orWhereRaw("JSON_EXTRACT(settings, '$.showmessages') = true")
                                    ->orWhereRaw("JSON_EXTRACT(settings, '$.showmessages') = 1");
                            });
                    });
            });
    }
}
