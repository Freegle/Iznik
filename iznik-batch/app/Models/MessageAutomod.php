<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $msgid The post reviewed.
 * @property int $groupid The group whose rules were applied.
 * @property string $mode shadow|approve
 * @property string $chart_version The chart WorkflowDefinition version that produced this row.
 * @property string $verdict approve|hold
 * @property string $end_node The chart end node reached, e.g. APPROVE or HOLD_LOAN.
 * @property string|null $reason Moderator-facing explanation of the end node.
 * @property array $path Every node visited: question, answer, confidence, evidence.
 * @property \Illuminate\Support\Carbon $created
 * @property-read \App\Models\Message $message
 * @property-read \App\Models\Group $group
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MessageAutomodFeedback> $feedback
 * @mixin \Eloquent
 */
class MessageAutomod extends Model
{
    public const MODE_SHADOW = 'shadow';
    public const MODE_APPROVE = 'approve';

    public const VERDICT_APPROVE = 'approve';
    public const VERDICT_HOLD = 'hold';

    protected $table = 'messages_automod';
    protected $guarded = [];
    public $timestamps = false;

    protected $casts = [
        'path' => 'array',
        'created' => 'datetime',
    ];

    /**
     * The post this row is about.
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'msgid');
    }

    /**
     * The group whose rules were applied.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupid');
    }

    /**
     * Moderator feedback ("this step is wrong") against this run.
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(MessageAutomodFeedback::class, 'automodid');
    }

    /**
     * Whether the chart decided to approve.
     */
    public function isApprove(): bool
    {
        return $this->verdict === self::VERDICT_APPROVE;
    }
}
