<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @see ../../database/migrations/2025_12_10_094529_create_microactions_table.php
 * @property int $id
 * @property string|null $actiontype
 * @property int $userid
 * @property int|null $msgid
 * @property string|null $msgcategory
 * @property string $result
 * @property \Illuminate\Support\Carbon $timestamp
 * @property string|null $comments
 * @property int|null $searchterm1
 * @property int|null $searchterm2
 * @property int $version For when we make changes which affect the validity of the data
 * @property int|null $item1
 * @property int|null $item2
 * @property int|null $rotatedimage
 * @property float $score_positive
 * @property float $score_negative
 * @property string|null $modfeedback
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereActiontype($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereComments($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereItem1($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereItem2($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereModfeedback($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereMsgcategory($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereMsgid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereResult($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereRotatedimage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereScoreNegative($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereScorePositive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereSearchterm1($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereSearchterm2($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereTimestamp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereUserid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Microaction whereVersion($value)
 * @mixin \Eloquent
 */
class Microaction extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'microactions';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'timestamp' => 'datetime',
        'score_positive' => 'float',
        'score_negative' => 'float',
    ];
}
