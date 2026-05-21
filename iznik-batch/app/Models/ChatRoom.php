<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property string|null $name
 * @property string $chattype
 * @property int|null $groupid Restricted to a group
 * @property \App\Models\User|null $user1 For DMs
 * @property \App\Models\User|null $user2 For DMs
 * @property string|null $description
 * @property \Illuminate\Support\Carbon|null $created
 * @property string $synctofacebook
 * @property int|null $synctofacebookgroupid
 * @property \Illuminate\Support\Carbon|null $latestmessage Really when chat last active
 * @property int $msgvalid
 * @property int $msginvalid
 * @property bool $flaggedspam
 * @property int|null $ljofferid
 * @property-read \App\Models\Group|null $group
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChatImage> $images
 * @property-read int|null $images_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChatMessage> $messages
 * @property-read int|null $messages_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ChatRoster> $roster
 * @property-read int|null $roster_count
 * @method static Builder<static>|ChatRoom mod2Mod()
 * @method static Builder<static>|ChatRoom newModelQuery()
 * @method static Builder<static>|ChatRoom newQuery()
 * @method static Builder<static>|ChatRoom notFlaggedSpam()
 * @method static Builder<static>|ChatRoom query()
 * @method static Builder<static>|ChatRoom recent(int $days = 31)
 * @method static Builder<static>|ChatRoom user2Mod()
 * @method static Builder<static>|ChatRoom user2User()
 * @method static Builder<static>|ChatRoom whereChattype($value)
 * @method static Builder<static>|ChatRoom whereCreated($value)
 * @method static Builder<static>|ChatRoom whereDescription($value)
 * @method static Builder<static>|ChatRoom whereFlaggedspam($value)
 * @method static Builder<static>|ChatRoom whereGroupid($value)
 * @method static Builder<static>|ChatRoom whereId($value)
 * @method static Builder<static>|ChatRoom whereLatestmessage($value)
 * @method static Builder<static>|ChatRoom whereLjofferid($value)
 * @method static Builder<static>|ChatRoom whereMsginvalid($value)
 * @method static Builder<static>|ChatRoom whereMsgvalid($value)
 * @method static Builder<static>|ChatRoom whereName($value)
 * @method static Builder<static>|ChatRoom whereSynctofacebook($value)
 * @method static Builder<static>|ChatRoom whereSynctofacebookgroupid($value)
 * @method static Builder<static>|ChatRoom whereUser1($value)
 * @method static Builder<static>|ChatRoom whereUser2($value)
 * @mixin \Eloquent
 */
class ChatRoom extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'chat_rooms';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    public const TYPE_MOD2MOD = 'Mod2Mod';
    public const TYPE_USER2MOD = 'User2Mod';
    public const TYPE_USER2USER = 'User2User';
    public const TYPE_GROUP = 'Group';

    protected $casts = [
        'created' => 'datetime',
        'latestmessage' => 'datetime',
        'flaggedspam' => 'boolean',
        'msgvalid' => 'integer',
        'msginvalid' => 'integer',
    ];

    /**
     * Get user1.
     */
    public function user1(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user1');
    }

    /**
     * Get user2.
     */
    public function user2(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user2');
    }

    /**
     * Get the group (for mod chats).
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupid');
    }

    /**
     * Get chat messages.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'chatid');
    }

    /**
     * Get chat roster entries.
     */
    public function roster(): HasMany
    {
        return $this->hasMany(ChatRoster::class, 'chatid');
    }

    /**
     * Get chat images.
     */
    public function images(): HasMany
    {
        return $this->hasMany(ChatImage::class, 'chatid');
    }

    /**
     * Scope to user2user chats.
     */
    public function scopeUser2User(Builder $query): Builder
    {
        return $query->where('chattype', self::TYPE_USER2USER);
    }

    /**
     * Scope to user2mod chats.
     */
    public function scopeUser2Mod(Builder $query): Builder
    {
        return $query->where('chattype', self::TYPE_USER2MOD);
    }

    /**
     * Scope to mod2mod chats.
     */
    public function scopeMod2Mod(Builder $query): Builder
    {
        return $query->where('chattype', self::TYPE_MOD2MOD);
    }

    /**
     * Scope to chats not flagged as spam.
     */
    public function scopeNotFlaggedSpam(Builder $query): Builder
    {
        return $query->where('flaggedspam', 0);
    }

    /**
     * Scope to recent chats.
     */
    public function scopeRecent(Builder $query, int $days = 31): Builder
    {
        return $query->where('latestmessage', '>=', now()->subDays($days));
    }

    /**
     * Get the other user in a user2user chat.
     */
    public function getOtherUser(int $userId): ?User
    {
        if ($this->getAttributeValue('user1') === $userId) {
            return $this->user2()->first();
        }
        if ($this->getAttributeValue('user2') === $userId) {
            return $this->user1()->first();
        }
        return NULL;
    }

    /**
     * Check if chat is a DM (user2user).
     */
    public function isDm(): bool
    {
        return $this->chattype === self::TYPE_USER2USER;
    }

    /**
     * Check if chat involves a specific user.
     */
    public function involvesUser(int $userId): bool
    {
        return $this->getAttributeValue('user1') === $userId || $this->getAttributeValue('user2') === $userId;
    }

    /**
     * Find or create a User2Mod chat room, using transaction + SELECT FOR UPDATE
     * to prevent duplicate creation. Matches V1 ChatRoom::createUser2Mod().
     *
     * The unique key (user1, user2, chattype) does NOT prevent User2Mod duplicates
     * because user2 is NULL and MySQL treats NULLs as distinct in unique indexes.
     */
    public static function getOrCreateUser2Mod(int $userId, int $groupId): ?self
    {
        $room = DB::transaction(function () use ($userId, $groupId) {
            // Lock any existing row to close the timing window.
            $chat = DB::selectOne(
                'SELECT id FROM chat_rooms WHERE user1 = ? AND groupid = ? AND chattype = ? FOR UPDATE',
                [$userId, $groupId, self::TYPE_USER2MOD]
            );

            if ($chat) {
                DB::update('UPDATE chat_rooms SET latestmessage = NOW() WHERE id = ?', [$chat->id]);
                return self::find($chat->id);
            }

            // No existing chat — create one inside the same transaction.
            return self::create([
                'chattype' => self::TYPE_USER2MOD,
                'user1' => $userId,
                'groupid' => $groupId,
                'latestmessage' => now(),
            ]);
        });

        if ($room) {
            // Ensure the member and all group mods are in the roster so that
            // chat notifications reach everyone.
            DB::statement('INSERT IGNORE INTO chat_roster (chatid, userid) VALUES (?, ?)', [$room->id, $userId]);

            $modUserIds = DB::table('memberships')
                ->where('groupid', $groupId)
                ->whereIn('role', ['Owner', 'Moderator'])
                ->pluck('userid');

            foreach ($modUserIds as $modUserId) {
                DB::statement('INSERT IGNORE INTO chat_roster (chatid, userid) VALUES (?, ?)', [$room->id, $modUserId]);
            }
        }

        return $room;
    }
}
