<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property int|null $userid Unique ID in users table
 * @property string $email The email
 * @property bool $preferred Preferred email for this user
 * @property \Illuminate\Support\Carbon $added
 * @property string|null $validatekey
 * @property \Illuminate\Support\Carbon|null $validated
 * @property string|null $canon For spotting duplicates
 * @property string|null $backwards Allows domain search
 * @property \Illuminate\Support\Carbon|null $bounced
 * @property string|null $viewed
 * @property string|null $md5hash
 * @property string|null $validatetime
 * @property-read \App\Models\User|null $user
 * @method static Builder<static>|UserEmail newModelQuery()
 * @method static Builder<static>|UserEmail newQuery()
 * @method static Builder<static>|UserEmail notBounced()
 * @method static Builder<static>|UserEmail preferred()
 * @method static Builder<static>|UserEmail query()
 * @method static Builder<static>|UserEmail unvalidated()
 * @method static Builder<static>|UserEmail validated()
 * @method static Builder<static>|UserEmail whereAdded($value)
 * @method static Builder<static>|UserEmail whereBackwards($value)
 * @method static Builder<static>|UserEmail whereBounced($value)
 * @method static Builder<static>|UserEmail whereCanon($value)
 * @method static Builder<static>|UserEmail whereEmail($value)
 * @method static Builder<static>|UserEmail whereId($value)
 * @method static Builder<static>|UserEmail whereMd5hash($value)
 * @method static Builder<static>|UserEmail wherePreferred($value)
 * @method static Builder<static>|UserEmail whereUserid($value)
 * @method static Builder<static>|UserEmail whereValidated($value)
 * @method static Builder<static>|UserEmail whereValidatekey($value)
 * @method static Builder<static>|UserEmail whereValidatetime($value)
 * @method static Builder<static>|UserEmail whereViewed($value)
 * @mixin \Eloquent
 */
class UserEmail extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_emails';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected static function booted(): void
    {
        static::saving(function (UserEmail $record) {
            if ($record->isDirty('email') || is_null($record->backwards)) {
                $record->backwards = strrev(strtolower((string) $record->email));
            }
        });
    }

    protected $casts = [
        'added' => 'datetime',
        'validated' => 'datetime',
        'preferred' => 'boolean',
        'bounced' => 'datetime',
    ];

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid');
    }

    /**
     * Scope to validated emails.
     */
    public function scopeValidated(Builder $query): Builder
    {
        return $query->whereNotNull('validated');
    }

    /**
     * Scope to unvalidated emails.
     */
    public function scopeUnvalidated(Builder $query): Builder
    {
        return $query->whereNull('validated');
    }

    /**
     * Scope to preferred emails.
     */
    public function scopePreferred(Builder $query): Builder
    {
        return $query->where('preferred', 1);
    }

    /**
     * Scope to non-bouncing emails.
     */
    public function scopeNotBounced(Builder $query): Builder
    {
        return $query->whereNull('bounced');
    }

    /**
     * Check if this email is validated.
     */
    public function isValidated(): bool
    {
        return $this->validated !== null;
    }

    /**
     * Get the domain part of the email.
     */
    public function getDomain(): string
    {
        $parts = explode('@', $this->email);
        return $parts[1] ?? '';
    }
}
