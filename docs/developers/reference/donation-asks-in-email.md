---
last_reviewed: 2026-08-10
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/DonateLinkService.php
  - iznik-batch/app/Mail/Donation/AskForDonation.php
  - iznik-batch/resources/views/emails/mjml/components/donate-ask.blade.php
  - iznik-nuxt3/pages/donate.vue
  - iznik-nuxt3/components/StripeDonate.vue
---

# Donation asks in email

Where the "donate" buttons in emails go, and why they go there.

## The short version

Every donate button in every email used to point at the PayPal shortlink
`https://freegle.in/paypal1510`. They now point at our own `/donate` page, with
the amount pre-selected and an auto-login key attached.

## Why

On the website, `StripeDonate.vue` mounts Stripe's **Express Checkout Element**,
which renders Apple Pay, Google Pay, Link and PayPal as one-tap buttons. In a
week of production `donation_payment_started` client logs, the express methods
people actually chose split roughly:

| Method | Share of express donation starts |
|---|---|
| PayPal | ~48% |
| Apple Pay | ~36% |
| Google Pay | ~10% |
| Link | ~6% |

Email donors could reach none of the non-PayPal half, because the email sent
them straight to PayPal. Around 1,300 donate clicks a month came out of the
digests alone.

Published figures put the effect of adding wallets at a few percent on
conversion (Fundraise Up measured Google Pay +2.6% overall, +4% among Chrome
users, Apple Pay +2%). Wallets also remove card entry entirely, which matters
most for the small gifts that make up most of Freegle's donations (mean Stripe
donation is about £2.34).

## What is *not* possible

Taking the payment **inside** the email is not an option, and no one does it:

- AMP for Email's component allowlist has no payment component, allows no
  JavaScript, and `amp-form` may not redirect after submit. (We do send AMP
  digests — see `app/Mail/Traits/AmpEmail.php` — so this was worth checking.)
- Apple Pay and Google Pay need the Payment Request API in a real browsing
  context with a user gesture, which an email renderer does not provide.

Stripe's own guidance for email is the hosted-page pattern: the email carries a
link, the page takes the payment. So the best available shape is **one tap in
the email (the amount) then one tap on the page (the wallet)**, and making the
wallet options *visible in the email* so the low-friction path is known before
the click.

## How it flows

1. **`DonateLinkService`** (`iznik-batch/app/Services/DonateLinkService.php`)
   builds the links. `url()` produces
   `{USER_SITE}/donate?amount=N&u=<id>&k=<key>&src=<tag>`; `amountLinks()`
   produces one entry per configured amount.
2. **The auto-login key is not optional.** `donations/stripe.go` `CreateIntent`
   returns 401 for a logged-out user, so without `?u=&k=` a donor arriving from
   email taps a wallet button that cannot pay. The key comes from
   `User::loginLink()` / `LoginLinkService`, the same `users_logins` type=`Link`
   key other one-click email links use.
3. **The destination host must be on the redirect allow-list.** Email links are
   wrapped in the tracking redirect, which validates the decoded destination in
   `iznik-server-go/emailtracking/emailtracking.go` `isValidRedirectURL`.
   `USER_SITE` is on that list. An arbitrary third-party URL is not — this is why
   the old code commented that the PayPal shortlink had to stay a shortlink
   (`freegle.in` is explicitly allowed).
4. **The shared block**
   (`resources/views/emails/mjml/components/donate-ask.blade.php`) renders either
   the full amount-button row or a single compact button, plus the payment-mark
   strip. It is used by the donation ask, the unified digest, the chat
   notification and the volunteering digest.
5. **The landing page** (`iznik-nuxt3/pages/donate.vue`) reads `?amount=` (and
   `?monthly=1`), clamps it to what the API accepts (£1–£250), and holds the
   payment buttons back for up to 5s while the auto-login from `app.vue`
   completes, so a fast tap cannot race the login into a 401.

## The payment marks

`iznik-nuxt3/public/emailimages/paymethods.png` is a strip of the brand owners'
own published marks (Apple Pay mark, Google Pay mark, PayPal logo) plus the word
"card", scaled to a common height and not otherwise altered.

Many clients block images by default, so **the alt text carries the same
message** ("Apple Pay, Google Pay, PayPal or card"). Do not drop it.

To regenerate the strip, compose the marks at 4x in a headless browser and
screenshot with a transparent background — it sits on four different near-white
section backgrounds across the templates, so a baked-in white box shows.

## Configuration

`config/freegle.php` under `donate`:

| Key | Env | Purpose |
|---|---|---|
| `path` | `FREEGLE_DONATE_PATH` | Page on the user site (default `/donate`). |
| `amounts` | `FREEGLE_DONATE_AMOUNTS` | Comma-separated £ amounts for the buttons (default `2,3,5`). First is used where there is only room for one button. |
| `override_url` | `FREEGLE_DONATE_OVERRIDE_URL` | Set to the PayPal shortlink to put the old PayPal-only behaviour back with no deploy. |
| `paypal_url` | `FREEGLE_DONATE_PAYPAL_URL` | The shortlink, still available for places that deliberately want PayPal. |

## Trade-off to watch

PayPal donors now take one extra tap: email → `/donate` → PayPal button →
PayPal, where before the email went straight to PayPal. That is the cost of
opening up the wallets to everyone else. `override_url` exists so this can be
reverted quickly if the numbers say it was the wrong call.

Attribution for measuring it: donate links carry `src=` (`digest`,
`donationask`, `chatnotify`, `volunteeringdigest`) and each amount button is
tracked separately (`p=donate_2`, `donate_3`, `donate_5`), so
`email_tracking_clicks.action='donate'` shows which ask and which amount people
pick.
