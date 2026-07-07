<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatImage extends Model
{
    protected $table = 'chat_images';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'contenttype' => 'string',
        'archived' => 'boolean',
    ];

    /**
     * Get the chat message.
     */
    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'chatmsgid');
    }
}
