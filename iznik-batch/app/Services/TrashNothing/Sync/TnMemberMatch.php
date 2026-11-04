<?php

namespace App\Services\TrashNothing\Sync;

use App\Models\User;

/**
 * Which Freegle account TN means by the two ids a feed row gives for a member: ours, which TN
 * learns when it adds the member through the partner API, and its own, which it always has.
 *
 * TN's own id is the better one. Ours is missing for a member the posts API provisioned, and out
 * of date for one merged away, or moved out by tn:split-shared-account, since TN last added them.
 * So the account holding TN's id is the one meant. When none does, the account TN names is,
 * unless it holds a different TN id: then it belongs to another member sharing it.
 *
 * This says only what the ids point to. What to do about it is each feed's own decision, because
 * forgetting a member needs more certainty than filing a rating.
 */
final class TnMemberMatch
{
    private function __construct(
        private readonly int $tnId,
        // The account our id names, if it still exists.
        public readonly ?User $named,
        // The account holding TN's id, if one does.
        public readonly ?User $holder,
    ) {}

    public static function find(int $fdId, int $tnId): self
    {
        return new self(
            $tnId,
            $fdId !== 0 ? User::find($fdId) : null,
            $tnId !== 0 ? User::where('tnuserid', $tnId)->first() : null,
        );
    }

    /** The account meant, or null when none is. */
    public function account(): ?User
    {
        if ($this->holder !== null) {
            return $this->holder;
        }

        return $this->namedHoldsAnotherTnId() ? null : $this->named;
    }

    /**
     * The account named has no TN id, and a different account holds this one. They may be twin
     * accounts of one member, which the ids alone cannot say.
     */
    public function heldElsewhere(): bool
    {
        return $this->named !== null && $this->named->tnuserid === null && $this->holder !== null;
    }

    private function namedHoldsAnotherTnId(): bool
    {
        return $this->tnId !== 0
            && $this->named?->tnuserid !== null
            && (int) $this->named->tnuserid !== $this->tnId;
    }
}
