<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\UserDonation;
use Tests\TestCase;

class UserDonationModelTest extends TestCase
{
    public function test_donation_can_be_created(): void
    {
        $user = $this->createTestUser();
        $payerEmail = $this->uniqueEmail('payer');

        $donation = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => $payerEmail,
            'PayerDisplayName' => 'Test User',
        ]);

        $this->assertDatabaseHas('users_donations', ['id' => $donation->id]);
    }

    public function test_user_relationship(): void
    {
        $user = $this->createTestUser();
        $payerEmail = $this->uniqueEmail('payer');

        $donation = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => $payerEmail,
            'PayerDisplayName' => 'Test User',
        ]);

        $this->assertEquals($user->id, $donation->user->id);
    }

    public function test_without_gift_aid_consent_scope(): void
    {
        $user = $this->createTestUser();

        $noConsent = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test User',
            'giftaidconsent' => false,
        ]);

        $withConsent = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 20.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test User',
            'giftaidconsent' => true,
        ]);

        $results = UserDonation::withoutGiftAidConsent()->get();

        $this->assertTrue($results->contains('id', $noConsent->id));
        $this->assertFalse($results->contains('id', $withConsent->id));
    }

    public function test_not_chased_for_gift_aid_scope(): void
    {
        $user = $this->createTestUser();

        $notChased = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test User',
            'giftaidchaseup' => null,
        ]);

        $chased = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 20.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test User',
            'giftaidchaseup' => now(),
        ]);

        $results = UserDonation::notChasedForGiftAid()->get();

        $this->assertTrue($results->contains('id', $notChased->id));
        $this->assertFalse($results->contains('id', $chased->id));
    }

    public function test_recent_scope(): void
    {
        $user = $this->createTestUser();

        $recent = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now()->subDays(5),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test User',
        ]);

        $old = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now()->subDays(60),
            'GrossAmount' => 20.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test User',
        ]);

        $results = UserDonation::recent(30)->get();

        $this->assertTrue($results->contains('id', $recent->id));
        $this->assertFalse($results->contains('id', $old->id));
    }

    public function test_in_date_range_scope(): void
    {
        $user = $this->createTestUser();

        $inRange = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now()->subDays(10),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test User',
        ]);

        $tooRecent = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now()->subDays(2),
            'GrossAmount' => 20.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test User',
        ]);

        $tooOld = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now()->subDays(40),
            'GrossAmount' => 30.00,
            'type' => 'PayPal',
            'source' => 'DonateWithPayPal',
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test User',
        ]);

        $results = UserDonation::inDateRange(5, 30)->get();

        $this->assertTrue($results->contains('id', $inRange->id));
        $this->assertFalse($results->contains('id', $tooRecent->id));
        $this->assertFalse($results->contains('id', $tooOld->id));
    }

    public function test_source_constants(): void
    {
        $this->assertEquals('DonateWithPayPal', UserDonation::SOURCE_DONATE_WITH_PAYPAL);
        $this->assertEquals('PayPalGivingFund', UserDonation::SOURCE_PAYPAL_GIVING_FUND);
        $this->assertEquals('Facebook', UserDonation::SOURCE_FACEBOOK);
        $this->assertEquals('eBay', UserDonation::SOURCE_EBAY);
        $this->assertEquals('BankTransfer', UserDonation::SOURCE_BANK_TRANSFER);
        $this->assertEquals('Stripe', UserDonation::SOURCE_STRIPE);
    }

    public function test_from_payment_providers_scope(): void
    {
        $user = $this->createTestUser();

        $paypalDonation = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => UserDonation::SOURCE_DONATE_WITH_PAYPAL,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test User',
        ]);

        $stripeDonation = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 20.00,
            'type' => 'Stripe',
            'source' => UserDonation::SOURCE_STRIPE,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test User',
        ]);

        $otherDonation = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 30.00,
            'type' => 'Other',
            'source' => UserDonation::SOURCE_FACEBOOK,
            'Payer' => $this->uniqueEmail('payer'),
            'PayerDisplayName' => 'Test User',
        ]);

        $results = UserDonation::fromPaymentProviders()->get();

        $this->assertTrue($results->contains('id', $paypalDonation->id));
        $this->assertTrue($results->contains('id', $stripeDonation->id));
        $this->assertFalse($results->contains('id', $otherDonation->id));
    }

    public function test_can_chase_gift_aid_returns_true(): void
    {
        $user = $this->createTestUser();
        $payerEmail = $this->uniqueEmail('payer');

        $donation = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => UserDonation::SOURCE_DONATE_WITH_PAYPAL,
            'Payer' => $payerEmail,
            'PayerDisplayName' => 'Test User',
            'giftaidconsent' => false,
            'giftaidchaseup' => null,
        ]);

        $this->assertTrue($donation->canChaseGiftAid());
    }

    public function test_can_chase_gift_aid_returns_false_with_consent(): void
    {
        $user = $this->createTestUser();
        $payerEmail = $this->uniqueEmail('payer');

        $donation = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => UserDonation::SOURCE_DONATE_WITH_PAYPAL,
            'Payer' => $payerEmail,
            'PayerDisplayName' => 'Test User',
            'giftaidconsent' => true,
            'giftaidchaseup' => null,
        ]);

        $this->assertFalse($donation->canChaseGiftAid());
    }

    public function test_can_chase_gift_aid_returns_false_if_already_chased(): void
    {
        $user = $this->createTestUser();
        $payerEmail = $this->uniqueEmail('payer');

        $donation = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 10.00,
            'type' => 'PayPal',
            'source' => UserDonation::SOURCE_DONATE_WITH_PAYPAL,
            'Payer' => $payerEmail,
            'PayerDisplayName' => 'Test User',
            'giftaidconsent' => false,
            'giftaidchaseup' => now(),
        ]);

        $this->assertFalse($donation->canChaseGiftAid());
    }

    public function test_can_chase_gift_aid_returns_false_for_non_payment_provider(): void
    {
        $user = $this->createTestUser();
        $payerEmail = $this->uniqueEmail('payer');

        $donation = UserDonation::create([
            'userid' => $user->id,
            'timestamp' => now(),
            'GrossAmount' => 10.00,
            'type' => 'Other',
            'source' => UserDonation::SOURCE_FACEBOOK,
            'Payer' => $payerEmail,
            'PayerDisplayName' => 'Test User',
            'giftaidconsent' => false,
            'giftaidchaseup' => null,
        ]);

        $this->assertFalse($donation->canChaseGiftAid());
    }
}
