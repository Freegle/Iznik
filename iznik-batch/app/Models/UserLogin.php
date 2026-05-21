<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_logins_table.php
 * @property int $id
 * @property int $userid Unique ID in users table
 * @property string|null $type
 * @property string|null $uid Unique identifier for login
 * @property string|null $credentials
 * @property \Illuminate\Support\Carbon $added
 * @property \Illuminate\Support\Carbon|null $lastaccess
 * @property string|null $credentials2 For Link logins
 * @property \Illuminate\Support\Carbon|null $credentialsrotated For Link logins
 * @property string|null $salt
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserLogin newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserLogin newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserLogin query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserLogin whereAdded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserLogin whereCredentials($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserLogin whereCredentials2($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserLogin whereCredentialsrotated($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserLogin whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserLogin whereLastaccess($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserLogin whereSalt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserLogin whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserLogin whereUid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserLogin whereUserid($value)
 * @mixin \Eloquent
 */
class UserLogin extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_logins';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'added' => 'datetime',
        'lastaccess' => 'datetime',
        'credentialsrotated' => 'datetime',
    ];
}
