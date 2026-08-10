<?php

namespace Tests\Unit\Services;

use App\Services\DonateLinkService;
use Tests\TestCase;

class DonateLinkServiceTest extends TestCase
{
    private function svc(): DonateLinkService
    {
        return app(DonateLinkService::class);
    }

    public function test_donate_links_point_at_our_stripe_page_not_paypal(): void
    {
        // The whole point of the service: email donors used to be sent to the
        // PayPal shortlink, so they could not use Apple Pay / Google Pay / Link.
        $url = $this->svc()->url(null, 2, 'digest');

        $this->assertStringContainsString('/donate', $url);
        $this->assertStringNotContainsString('paypal', strtolower($url));
    }

    public function test_amount_is_carried_through_so_the_page_asks_for_what_was_tapped(): void
    {
        $url = $this->svc()->url(null, 5, 'digest');

        $this->assertStringContainsString('amount=5', $url);
    }

    public function test_no_amount_produces_a_link_with_no_amount_param(): void
    {
        $url = $this->svc()->url(null, null, 'digest');

        $this->assertStringNotContainsString('amount=', $url);
        $this->assertStringContainsString('src=digest', $url);
    }

    public function test_link_for_a_user_carries_the_autologin_key(): void
    {
        // Without this the Go API refuses to create the PaymentIntent
        // (donations/stripe.go CreateIntent 401s for a logged-out user) and the
        // donor taps a wallet button that cannot pay.
        $user = $this->createTestUser();

        $url = $this->svc()->url($user, 3, 'donationask');

        $this->assertStringContainsString('u=' . $user->id, $url);
        $this->assertMatchesRegularExpression('/[?&]k=[0-9a-f]{32}/', $url);
        $this->assertStringContainsString('amount=3', $url);
        $this->assertStringContainsString('src=donationask', $url);
    }

    public function test_query_separators_are_well_formed_with_both_amount_and_login(): void
    {
        $user = $this->createTestUser();

        $url = $this->svc()->url($user, 2, 'digest');

        // Exactly one '?', everything else joined with '&'.
        $this->assertSame(1, substr_count($url, '?'), "malformed query string: {$url}");
    }

    public function test_url_for_user_id_resolves_the_user(): void
    {
        $user = $this->createTestUser();

        $url = $this->svc()->urlForUserId((int) $user->id, 2, 'volunteeringdigest');

        $this->assertStringContainsString('u=' . $user->id, $url);
    }

    public function test_url_for_unknown_user_id_still_produces_a_usable_link(): void
    {
        $url = $this->svc()->urlForUserId(null, 2, 'volunteeringdigest');

        $this->assertStringContainsString('/donate', $url);
        $this->assertStringNotContainsString('&k=', $url);
    }

    public function test_amount_links_cover_the_configured_amounts(): void
    {
        config(['freegle.donate.amounts' => [1, 4, 9]]);

        $links = $this->svc()->amountLinks(null, 'digest');

        $this->assertCount(3, $links);
        $this->assertSame(['£1', '£4', '£9'], array_column($links, 'label'));
        $this->assertStringContainsString('amount=9', $links[2]['url']);
    }

    public function test_default_amount_is_the_first_configured_amount(): void
    {
        config(['freegle.donate.amounts' => [7, 11]]);

        $this->assertSame(7, $this->svc()->defaultAmount());
    }

    public function test_override_url_puts_the_old_paypal_behaviour_back(): void
    {
        // The escape hatch: flipping this env var must reroute every donate
        // button without a code change, and must not glue our query params
        // onto a third-party URL.
        config(['freegle.donate.override_url' => 'https://freegle.in/paypal1510']);

        $user = $this->createTestUser();
        $url = $this->svc()->url($user, 5, 'digest');

        $this->assertSame('https://freegle.in/paypal1510', $url);
    }

    public function test_paypal_url_is_still_available_for_deliberate_use(): void
    {
        $this->assertStringContainsString('paypal', $this->svc()->paypalUrl());
    }
}
