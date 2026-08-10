<?php

namespace App\Services;

use App\Models\User;

/**
 * Builds the "donate" links we put in emails.
 *
 * Every donate button in every email used to point at the PayPal shortlink
 * (https://freegle.in/paypal1510), so a donor arriving from email could only
 * pay by PayPal. On the website the same donor gets the Stripe Express
 * Checkout Element, which shows Apple Pay / Google Pay / Link / PayPal as
 * one-tap buttons — and roughly half of the payments people start there are
 * one of the wallets rather than PayPal. Email donors were being routed away
 * from the lowest-friction methods.
 *
 * These links therefore point at our own /donate page instead, with:
 *
 *  - the amount pre-selected, so the button the donor tapped in the email is
 *    the amount they are asked to confirm; and
 *  - the auto-login key, because the Go API refuses to create a Stripe
 *    PaymentIntent for a logged-out user (donations/stripe.go CreateIntent
 *    returns 401), which would silently strand the donor on the wallet button.
 *
 * Set FREEGLE_DONATE_OVERRIDE_URL to the PayPal shortlink to put the old
 * behaviour back without a deploy.
 */
class DonateLinkService
{
    /**
     * Suggested one-tap amounts (£) for the email buttons.
     *
     * @return list<int>
     */
    public function amounts(): array
    {
        $amounts = config('freegle.donate.amounts', [2, 3, 5]);

        return empty($amounts) ? [2, 3, 5] : array_values($amounts);
    }

    /**
     * The amount to use where there is only room for one button.
     */
    public function defaultAmount(): int
    {
        return $this->amounts()[0];
    }

    /**
     * A donate link, optionally for a specific amount and a specific user.
     *
     * @param  User|null  $user    Recipient — supplies the auto-login key so the
     *                             Stripe intent can be created on arrival.
     * @param  int|null   $amount  Amount in £, or null to let the page choose.
     * @param  string     $src     Source tag, so we can tell in the logs which
     *                             email a donation came from.
     */
    public function url(?User $user = null, ?int $amount = null, string $src = 'email'): string
    {
        $override = config('freegle.donate.override_url');

        if (! empty($override)) {
            // Explicit escape hatch (e.g. back to PayPal). Amount and login key
            // mean nothing to a third-party page, so don't append them.
            return $override;
        }

        $path = config('freegle.donate.path', '/donate');
        $query = $amount !== null ? ('?amount=' . $amount) : '';

        if ($user !== null && $user->exists) {
            // loginLink() appends ?u=&k=&src= (picking & over ? as needed).
            return $user->loginLink($path . $query, $src);
        }

        $userSite = rtrim(config('freegle.sites.user', 'https://www.ilovefreegle.org'), '/');
        $sep = $query === '' ? '?' : '&';

        return $userSite . $path . $query . $sep . 'src=' . $src;
    }

    /**
     * As url(), for callers that only have a user id (some mailables are
     * constructed from a row rather than a model). A missing or unknown id
     * just means no auto-login key — the page still works, the donor may
     * simply have to be signed in already for the Stripe intent to be created.
     */
    public function urlForUserId(?int $userId, ?int $amount = null, string $src = 'email'): string
    {
        $user = $userId ? User::find($userId) : null;

        return $this->url($user, $amount, $src);
    }

    /**
     * The set of amount buttons for an email, ready to loop over in Blade.
     *
     * @return list<array{amount:int,label:string,url:string}>
     */
    public function amountLinks(?User $user = null, string $src = 'email'): array
    {
        $links = [];

        foreach ($this->amounts() as $amount) {
            $links[] = [
                'amount' => $amount,
                'label' => '£' . $amount,
                'url' => $this->url($user, $amount, $src),
            ];
        }

        return $links;
    }

    /**
     * The PayPal shortlink, for places where we deliberately want PayPal.
     */
    public function paypalUrl(): string
    {
        return config('freegle.donate.paypal_url', 'https://freegle.in/paypal1510');
    }
}
