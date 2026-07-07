<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_isochrones_users_table.php
 * @property int $id
 * @property int $userid
 * @property int $isochroneid
 * @property string|null $nickname
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IsochroneUser newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IsochroneUser newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IsochroneUser query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IsochroneUser whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IsochroneUser whereIsochroneid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IsochroneUser whereNickname($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|IsochroneUser whereUserid($value)
 * @mixin \Eloquent
 */
class IsochroneUser extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'isochrones_users';
    protected $guarded = ['id'];
    public $timestamps = false;
}
