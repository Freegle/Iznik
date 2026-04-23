<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Group extends Model
{
    public const TYPE_FREEGLE = 'Freegle';
    public const TYPE_REUSE = 'Reuse';
    public const TYPE_OTHER = 'Other';

    protected $table = 'groups';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    protected static function boot(): void
    {
        parent::boot();

        // Auto-generate polyindex geometry from lat/lng when creating.
        static::creating(function (Group $group) {
            if (!empty($group->lat) && !empty($group->lng) && empty($group->polyindex)) {
                $srid = config('freegle.srid');
                $group->polyindex = \DB::raw("ST_GeomFromText('POINT({$group->lng} {$group->lat})', {$srid})");
            }
        });
    }

    protected $casts = [
        'settings' => 'array',
        'microvolunteeringoptions' => 'array',
        'rules' => 'array',
        'lat' => 'decimal:6',
        'lng' => 'decimal:6',
        'founded' => 'date',
        'onhere' => 'boolean',
        'publish' => 'boolean',
        'listable' => 'boolean',
        'onmap' => 'boolean',
        'mentored' => 'boolean',
        'seekingmods' => 'boolean',
        'privategroup' => 'boolean',
        'microvolunteering' => 'boolean',
        'onlovejunk' => 'boolean',
    ];

    /**
     * Get group's memberships.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'groupid');
    }

    /**
     * Get group's messages via message_groups pivot.
     */
    public function messages(): BelongsToMany
    {
        return $this->belongsToMany(Message::class, 'messages_groups', 'groupid', 'msgid')
            ->withPivot(['collection', 'arrival', 'approved_by', 'deleted']);
    }

    /**
     * Scope to only Freegle groups.
     */
    public function scopeFreegle(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_FREEGLE);
    }

    /**
     * Scope to only groups that are on the platform.
     */
    public function scopeOnHere(Builder $query): Builder
    {
        return $query->where('onhere', 1);
    }

    /**
     * Scope to only published groups.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('publish', 1);
    }

    /**
     * Scope to active Freegle groups (excludes closed groups).
     */
    public function scopeActiveFreegle(Builder $query): Builder
    {
        return $query->freegle()->onHere()->published()->notClosed();
    }

    /**
     * Scope to exclude closed groups.
     *
     * Note: Must check for both boolean false AND integer 0, as some groups
     * have "closed": 0 (integer) rather than "closed": false (boolean).
     * In MySQL JSON comparisons, 0 != false.
     */
    public function scopeNotClosed(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('settings')
                ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') IS NULL")
                ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') = false")
                ->orWhereRaw("JSON_EXTRACT(settings, '$.closed') = 0");
        });
    }

    /**
     * Check if the group is closed.
     */
    public function isClosed(): bool
    {
        $settings = $this->settings ?? [];
        return !empty($settings['closed']);
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
     * Get approved members.
     */
    public function approvedMembers(): HasMany
    {
        return $this->memberships()->where('collection', 'Approved');
    }

    /**
     * Get moderators.
     */
    public function moderators(): HasMany
    {
        return $this->memberships()->whereIn('role', ['Moderator', 'Owner']);
    }
}
