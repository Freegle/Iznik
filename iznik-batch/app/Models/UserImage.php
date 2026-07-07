<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_users_images_table.php
 * @property int $id
 * @property int|null $userid id in the users table
 * @property string $contenttype
 * @property bool $default
 * @property string|null $url
 * @property bool|null $archived
 * @property string|null $data
 * @property string|null $identification
 * @property string|null $hash
 * @property string|null $externaluid
 * @property string|null $externalmods
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImage query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImage whereArchived($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImage whereContenttype($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImage whereData($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImage whereDefault($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImage whereExternalmods($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImage whereExternaluid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImage whereHash($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImage whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImage whereIdentification($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImage whereUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserImage whereUserid($value)
 * @mixin \Eloquent
 */
class UserImage extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_images';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'default' => 'boolean',
        'archived' => 'boolean',
    ];
}
