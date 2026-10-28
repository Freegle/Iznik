<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A member pressed "I'm interested" on a post: a quick sign of interest before replying. One row per
 * member and post. chasedat is when the chase-up email named it, so it is named once.
 *
 * @see ../../database/migrations/2026_10_23_000001_create_messages_interested_table.php
 *
 * A plain model, deliberately not Auditable: the Go API writes the rows and a mark is not worth an audit row.
 * The foreign keys delete a member's rows with the member or the post.
 *
 * @property int $id
 * @property int $msgid
 * @property int $userid
 * @property \Illuminate\Support\Carbon $added
 * @property \Illuminate\Support\Carbon|null $chasedat
 */
class MessageInterested extends Model
{
    protected $table = 'messages_interested';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected $casts = [
        'added' => 'datetime',
        'chasedat' => 'datetime',
    ];
}
