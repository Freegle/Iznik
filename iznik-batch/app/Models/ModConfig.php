<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
     * Resolve the config ID for a moderator.
     *
     * Fallback chain:
     * 1. The mod's own modconfigid
     * 2. Any other moderator's configid
     * 3. The first config created by this mod
     * 4. A default config
     *
     * When a fallback is used, the user's modconfigid is updated so the lookup
     * is cached for next time.
     */
    public static function getForMod(int $modId): ?int
    {
        $mod = User::find($modId);
        $configId = $mod?->modconfigid;

        $save = FALSE;

        if (is_null($configId)) {
            # This mod has no config.  If there is another mod with one, then we use that.  This handles the case
            # of a new floundering mod who doesn't quite understand what's going on.  Well, partially.
            $configId = User::whereIn('systemrole', [User::SYSTEMROLE_MODERATOR, User::SYSTEMROLE_SUPPORT, User::SYSTEMROLE_ADMIN])
                ->whereNotNull('modconfigid')
                ->value('modconfigid');

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

        if ($save && $mod) {
            # Record that for next time.
            $mod->update(['modconfigid' => $configId]);
        }

        return $configId;
    }
}
