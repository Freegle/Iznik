<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * @property int $id
 * @property string $type
 * @property int|null $userid
 * @property string $Payer
 * @property string $PayerDisplayName
 * @property \Illuminate\Support\Carbon $timestamp
 * @property string|null $TransactionID
 * @property numeric $GrossAmount
 * @property string $source
 * @property bool $giftaidconsent
 * @property string|null $giftaidclaimed
 * @property \Illuminate\Support\Carbon|null $giftaidchaseup
 * @property string|null $TransactionType
 * @property string|null $thanked
 * @property-read \App\Models\User|null $user
 * @method static Builder<static>|UserDonation fromPaymentProviders()
 * @method static Builder<static>|UserDonation inDateRange(int $minDays, int $maxDays)
 * @method static Builder<static>|UserDonation newModelQuery()
 * @method static Builder<static>|UserDonation newQuery()
 * @method static Builder<static>|UserDonation notChasedForGiftAid()
 * @method static Builder<static>|UserDonation query()
 * @method static Builder<static>|UserDonation recent(int $days = 30)
 * @method static Builder<static>|UserDonation whereGiftaidchaseup($value)
 * @method static Builder<static>|UserDonation whereGiftaidclaimed($value)
 * @method static Builder<static>|UserDonation whereGiftaidconsent($value)
 * @method static Builder<static>|UserDonation whereGrossAmount($value)
 * @method static Builder<static>|UserDonation whereId($value)
 * @method static Builder<static>|UserDonation wherePayer($value)
 * @method static Builder<static>|UserDonation wherePayerDisplayName($value)
 * @method static Builder<static>|UserDonation whereSource($value)
 * @method static Builder<static>|UserDonation whereThanked($value)
 * @method static Builder<static>|UserDonation whereTimestamp($value)
 * @method static Builder<static>|UserDonation whereTransactionID($value)
 * @method static Builder<static>|UserDonation whereTransactionType($value)
 * @method static Builder<static>|UserDonation whereType($value)
 * @method static Builder<static>|UserDonation whereUserid($value)
 * @method static Builder<static>|UserDonation withoutGiftAidConsent()
 * @mixin \Eloquent
 */
class UserDonation extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'users_donations';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    public const SOURCE_DONATE_WITH_PAYPAL = 'DonateWithPayPal';
    public const SOURCE_PAYPAL_GIVING_FUND = 'PayPalGivingFund';
    public const SOURCE_FACEBOOK = 'Facebook';
    public const SOURCE_EBAY = 'eBay';
    public const SOURCE_BANK_TRANSFER = 'BankTransfer';
    public const SOURCE_STRIPE = 'Stripe';

    protected $casts = [
        'timestamp' => 'datetime',
        'amount' => 'decimal:2',
        'giftaidconsent' => 'boolean',
        'giftaidchaseup' => 'datetime',
    ];

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'userid');
    }

    /**
     * Scope to donations from PayPal or Stripe.
     */
    public function scopeFromPaymentProviders(Builder $query): Builder
    {
        return $query->whereIn('source', [self::SOURCE_DONATE_WITH_PAYPAL, self::SOURCE_STRIPE]);
    }

    /**
     * Scope to donations without gift aid consent.
     */
    public function scopeWithoutGiftAidConsent(Builder $query): Builder
    {
        return $query->where('giftaidconsent', 0);
    }

    /**
     * Scope to donations not yet chased for gift aid.
     */
    public function scopeNotChasedForGiftAid(Builder $query): Builder
    {
        return $query->whereNull('giftaidchaseup');
    }

    /**
     * Scope to recent donations.
     */
    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('timestamp', '>=', now()->subDays($days));
    }

    /**
     * Scope to donations in a date range.
     */
    public function scopeInDateRange(Builder $query, int $minDays, int $maxDays): Builder
    {
        return $query->where('timestamp', '>=', now()->subDays($maxDays))
            ->where('timestamp', '<=', now()->subDays($minDays));
    }

    /**
     * Check if this donation can be chased for gift aid.
     */
    public function canChaseGiftAid(): bool
    {
        return !$this->giftaidconsent
            && $this->giftaidchaseup === null
            && in_array($this->source, [self::SOURCE_DONATE_WITH_PAYPAL, self::SOURCE_STRIPE]);
    }
}
