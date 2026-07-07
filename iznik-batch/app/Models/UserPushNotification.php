<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_push_notifications_table.php
 * @property int $id
 * @property int $userid
 * @property \Illuminate\Support\Carbon $added
 * @property string $type
 * @property \Illuminate\Support\Carbon|null $lastsent
 * @property string $subscription
 * @property string $apptype
 * @property \Illuminate\Support\Carbon|null $engageconsidered
 * @property \Illuminate\Support\Carbon|null $engagesent
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPushNotification newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPushNotification newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPushNotification query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPushNotification whereAdded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPushNotification whereApptype($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPushNotification whereEngageconsidered($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPushNotification whereEngagesent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPushNotification whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPushNotification whereLastsent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPushNotification whereSubscription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPushNotification whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPushNotification whereUserid($value)
 * @mixin \Eloquent
 */
class UserPushNotification extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_push_notifications';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'added' => 'datetime',
        'lastsent' => 'datetime',
        'engageconsidered' => 'datetime',
        'engagesent' => 'datetime',
    ];
}
