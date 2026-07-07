<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_stories_requested_table.php
 * @property int $id
 * @property int $userid
 * @property \Illuminate\Support\Carbon $date
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStoryRequested newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStoryRequested newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStoryRequested query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStoryRequested whereDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStoryRequested whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserStoryRequested whereUserid($value)
 * @mixin \Eloquent
 */
class UserStoryRequested extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_stories_requested';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'date' => 'datetime',
    ];
}
