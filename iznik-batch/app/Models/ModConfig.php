<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use OwenIt\Auditing\Contracts\Auditable;

class ModConfig extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'mod_configs';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    protected $casts = [
        'protected' => 'boolean',
        'coloursubj' => 'boolean',
        'default' => 'boolean',
        'chatread' => 'boolean',
        'subjlen' => 'integer',
    ];

    /**
     * Get the user who created this config.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'createdby');
    }

    /**
     * Get memberships using this config.
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'configid');
    }

    /**
     * Resolve the config ID for a moderator on a group.
     *
     * Fallback chain:
     * 1. The mod's own configid on that membership
     * 2. Any other mod/owner's configid on the same group
     * 3. The first config created by this mod
     * 4. A default config
     *
     * When a fallback is used, the membership is updated so the lookup is
     * cached for next time.
     */
    public static function getForGroup(int $modId, int $groupId): ?int
    {
        $membership = Membership::where('userid', $modId)
            ->where('groupid', $groupId)
            ->first();

        $configId = $membership?->configid;

        $save = FALSE;

        if (is_null($configId)) {
            # This user has no config.  If there is another mod with one, then we use that.  This handles the case
            # of a new floundering mod who doesn't quite understand what's going on.  Well, partially.
            $configId = Membership::where('groupid', $groupId)
                ->whereIn('role', [Membership::ROLE_MODERATOR, Membership::ROLE_OWNER])
                ->whereNotNull('configid')
                ->value('configid');

            if (!is_null($configId)) {
                $save = TRUE;
            }
        }

        if (is_null($configId)) {
            # Still nothing.  Choose the first one created by us - at least that's something.
            $configId = static::where('createdby', $modId)->value('id');

            if (!is_null($configId)) {
                $save = TRUE;
            }
        }

        if (is_null($configId)) {
            # Still nothing.  Choose a default
            $configId = static::where('default', TRUE)->value('id');

            if (!is_null($configId)) {
                $save = TRUE;
            }
        }

        if ($save && $membership) {
            # Record that for next time.
            $membership->update(['configid' => $configId]);
        }

        return $configId;
    }
}
