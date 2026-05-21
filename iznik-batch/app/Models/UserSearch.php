<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_searches_table.php
 * @property int $id
 * @property int|null $userid
 * @property \Illuminate\Support\Carbon $date
 * @property string $term
 * @property int|null $maxmsg
 * @property bool $deleted
 * @property int|null $locationid
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearch newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearch newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearch query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearch whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearch whereDeleted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearch whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearch whereLocationid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearch whereMaxmsg($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearch whereTerm($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserSearch whereUserid($value)
 * @mixin \Eloquent
 */
class UserSearch extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_searches';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
        'deleted' => 'boolean',
    ];
}
