---
last_reviewed: 2026-08-19
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/Mail/Deferrals/*.php
  - iznik-batch/app/Services/Mail/MailSuppressionService.php
  - iznik-batch/app/Services/Mail/DeliveryHealthService.php
  - iznik-batch/tests/Unit/Services/Mail/DeliveryHealthServiceTest.php
  - iznik-batch/app/Console/Commands/Mail/ScanDeferralsCommand.php
  - iznik-batch/app/Mail/Deferrals/UnreadChatCatchUpMail.php
  - iznik-batch/database/migrations/2026_08_18_000001_create_mail_suppressions_tables.php
  - iznik-batch/tests/Unit/Services/Mail/Deferrals/*.php
  - iznik-batch/tests/Feature/Mail/DeferralScanServiceTest.php
  - iznik-batch/tests/Feature/Mail/MailSuppressionServiceTest.php
  - iznik-batch/tests/Feature/Mail/DeferralCatchUpServiceTest.php
  - iznik-batch/tests/Feature/Mail/ScanDeferralsCommandTest.php
  - iznik-server-go/emailtracking/deferrals.go
  - iznik-nuxt3/modtools/components/ModMailDelayed.vue
  - iznik-nuxt3/modtools/components/ModSupportMailDeferrals.vue
  - iznik-nuxt3/tests/unit/components/modtools/ModMailDelayed.spec.js
---

# Mail deferrals and suppression

## The problem this solves

We hand our outbound mail to a relay over SMTP. The relay accepts each message
with a `250` and takes responsibility for delivering it onward. Everything
after that - the actual conversation with Gmail, Yahoo, Microsoft - happens on
the relay, out of sight of any code in this repo.

That is fine until a provider stops accepting us. Then the relay keeps taking
our mail, keeps answering `250`, and quietly stacks it up in a queue that
cannot drain. Nothing in `EmailSpoolerService`, nothing in
`SmtpFailureClassifier`, nothing in `monitor:email-health` can see it: they all
measure whether we successfully handed mail over, which we did.

On 2026-08-15 at 16:38 UTC Yahoo began returning
`421 4.7.0 [TSS04] ... temporarily deferred due to unexpected volume or user
complaints` to every message from the relay's IP, triggered by a Community
News send that tripled the relay's normal volume for two days running. Three
days later nobody had noticed. The deferred queue held 85,537 messages and was
growing by around 1,300 an hour. About 9,400 people had received nothing.

Two things were broken, and this feature fixes both.

**We could not see it.** Nothing in our code was capable of observing a
deferral, so no alert could ever have fired.

**We kept generating into it.** Roughly 67,000 digests a day plus per-post
immediate mail continued to render - the expensive MJML and AMP compile - for
addresses that provably could not receive any of it. The 85k backlog was 61%
`[Group] OFFER:` immediate mail, 25% digests, and the rest chat notifications,
WeMissYou and Community News.

## How detection works

`mail:deferrals:scan` runs every fifteen minutes and reads the relay's own
Postfix queue.

It reaches the relay with `App\Monitoring\HostCommandRunner`, the same
ssh-and-parse-stdout mechanism the host health probes already use. The probe is
read-only and runs in one round trip. It uses `postqueue -j` rather than
`mailq`, because `mailq` takes minutes over a queue this size and `postqueue -j`
emits one JSON object per line, which we can cap and stream.

The relay's topology is not in this repo and must not be: the ssh target lives
only in the environment. See "Configuration" below.

### Two tiers, and the first is the one that matters

**MX group (primary).** Deferrals are grouped by the *relay host* Postfix
blamed, not by the recipient's domain. This matters more than it looks.
Providers block per sending-IP-to-receiving-infrastructure pair. When Yahoo
blocked us, that single block took out `yahoo.co.uk`, `yahoo.com`, `ymail.com`,
`rocketmail.com`, `aol.com`, `aol.co.uk`, `aim.com` **and `sky.com`** - Sky's
mail is Yahoo-hosted, which nothing about the domain name would tell you. All
of them relay through `*.am0.yahoodns.net`. Group by relay and you catch all
eight; group by domain and you are playing whack-a-mole with a list you will
never finish.

A relay family is suppressed when its deferred backlog is over threshold *and*
deliveries to it have all but stopped. Both halves are needed: a big backlog
alone can be a slow evening, and a quiet hour alone can be a quiet hour. During
the incident the ratio was roughly one delivery an hour against tens of
thousands of deferral events, so in practice this is not a close call.

**Per address (secondary).** An individual mailbox with enough deferrals over
enough hours. This catches `452 4.2.2 Recipient mailbox quota exceeded`, which
is present in the queue independently of any incident. It is deliberately
slower and more forgiving than the MX-group tier, because a mailbox that is
full today is often fine tomorrow.

### Where the domain list comes from

When a relay family is suppressed we also write a child `domain` row for every
recipient domain we actually saw deferring behind it. That is what keeps the
sending-loop check to a plain indexed lookup with no DNS in the hot path - and
it is better evidence than an MX lookup would be, because it is a record of
what actually happened to our mail rather than what DNS says should have.

### Alerting

Any new MX-group suppression raises a Sentry alert, as does a relay we cannot
reach at all. The entire reason this ran for three days is that nothing
shouted.

### The other half: mail that is accepted and then binned

Everything above catches a provider that *refuses* us. The refusal leaves the message in the
relay's queue, where it can be counted.

A provider can fail us the opposite way: accept every message and then quietly do nothing with
it. That leaves no queue entry at all, so the scan cannot see it, and from our side everything
looks perfect. On 2026-08-16 every Yahoo-run domain - `yahoo.co.uk`, `yahoo.com`, `aol.com`,
`sky.com`, `ymail.com`, `rocketmail.com` - went from a steady 16-36% open rate to zero and
stayed there, following a send five times the normal daily volume two days earlier. That is
about 35,000 emails a day going nowhere, and nothing alerted.

`DeliveryHealthService` watches for that shape by comparing each domain's open rate against its
own recent history, once a day. It compares a domain against itself rather than against a fixed
figure or against other domains, because open rates differ hugely and legitimately between
providers - `icloud.com` opens at 56% where `gmail.com` opens at 23% on the same mail - so any
absolute threshold would either miss real outages at the top or cry wolf at the bottom.
Domains that never report opens drop out by themselves, because their baseline is already zero.

The two are reported as separate alerts on purpose. "A provider has stopped taking our mail"
and "we are short of capacity" are different problems for different people. When the scan
suppresses a provider, expect the open-rate check to name the same domains a day or so later:
that is the two agreeing, not a duplicate.

## How suppression works

`App\Services\Mail\MailSuppressionService::isSuppressed()` is the gate. It is
called from inside each sending job's per-recipient loop, **before the render**
rather than at spool or send time, because the render is where the cost is.

Gated paths, all of which skip before a `Mailable` is constructed:

| Mail | Where the gate sits |
|------|---------------------|
| Immediate `[Group] OFFER:` / `WANTED:` | `UnifiedDigestService::processGroupImmediate()`, after the `email_preferred` guard |
| Reach and match mail | `UnifiedDigestService::spoolPostToRecipients()`, same place |
| Daily digest | `UnifiedDigestService::sendDigestToUser()`, before the digest tracker is read |
| Chat notifications | `ChatNotificationService::processMessage()`, after the `email_preferred` guard |
| WeMissYou / engagement | `EngageEmailService::sendToUsers()` |
| Community News | `CommunityNewsEmailService::sendWeekly()` |

There is a final backstop inside `EmailSpoolerService::spool()` itself, above
the render call. It catches anything that does not gate earlier, including any
new send path somebody adds later without knowing this exists. It returns `''`
rather than throwing, matching the existing permanent-failure contract, because
callers key off the truthiness of the returned id.

### Two deliberate asymmetries

The `EmailSpoolerService::spool()` backstop **logs but does not count**. The
per-recipient gates do the counting, because they are the only ones that know
whose mail it is; a `Mailable` at spool time may not carry a member id at all.
Anything the backstop catches is therefore something no gate covered, which is
a gap to close in the gate rather than a number to record - and it shows up in
the log so that it can be.

Freegle's own addresses are **never** suppressed, at any scope. The volume is
trivial - alerts, reports, mod notifications to team addresses - so nothing is
saved by holding them, and the failure mode is absurd: the alert saying a
provider has stopped accepting our mail is exactly the message that would be
dropped.

And the `BounceService` ignore patterns are unconditional rather than scoped
to a live suppression, on purpose. A queue-expiry DSN turns up as much as five
days after the deferral that caused it, by which time the suppression has
usually been released. Scoping the patterns to active suppressions would miss
exactly the DSNs they exist to catch.
### What the gate does not catch

Mail already sitting in the file spool when a suppression starts still goes
out, and will be deferred like the rest. That is deliberate. Holding it at
send time instead would need a per-message backoff that the spool format does
not have today, and `ProcessSpoolCommand --daemon` retries transient
failures on its next tick about a second later - so a send-time hold would hot
loop against a provider that is already unhappy with our volume. The spool
drains in minutes, so the tail is minutes of mail against days of it.

### Why not `users.bouncing`

Because it means something else. `bouncing` means *this address is bad*. It is
shown to moderators as such, they act on it, and historically it was never
reset. A deferral is our sending reputation with a provider; the member's
address is fine and there is nothing they can do. Conflating the two would get
members chased for our problem.

## Release, and what happens to the backlog

A suppression is released when deliveries to the relay have resumed *and* its
deferred count has stayed below threshold for two consecutive scans. Release is
deliberately harder than suppression, so a single quiet moment inside a
provider's own backoff window cannot reopen the floodgates.

There is also a fail-open: if the probe has not been able to confirm a
suppression for `stale_after_hours`, it is released and alerted on. Quietly not
mailing an entire provider for ever, because our own probe broke, would be
worse than the problem this feature was written to solve.

We never store the mail we declined to render - storing it would defeat the
point - so the backlog policy is per type:

| Type | On release |
|------|------------|
| `[Group] OFFER:` / `WANTED:` | Dropped. A three-day-old post is taken or gone. The immediate-mail cursor is per group rather than per member, so it has moved on regardless. |
| Community News, WeMissYou, volunteering | Dropped. All periodic; the next one along is a better email than a stale one. |
| Daily digest | One catch-up covering the whole window. This needs no code: the gate returns *before* the digest tracker is advanced, so the next daily run naturally spans the gap and sends exactly one. |
| Chat notifications | One "you have unread messages" summary. Never replayed individually - a stack of days-old notifications arriving at once is its own harm, and is the behaviour that gets a sender deferred in the first place. |

`mail_suppressed_counts` records, per member and per type, what we declined to
generate. That is what lets the catch-up say something true about the size of
the gap, and it is what ModTools shows moderators.

Release is evaluated per tier, because the two tiers are different scales. A
relay family always has a tail of stragglers, so it clears when the backlog is
back to a normal level and the provider is visibly taking our mail again. One
mailbox holds single figures, so applying the relay-sized threshold to it would
call a still-full mailbox clear on the very next scan; an address clears only
when the queue holds nothing for it at all.

Any scan that is not clear resets the counter, so the two clear scans have to
be consecutive. Targets the same scan has just re-confirmed as deferring are
kept out of the release pass entirely, since it reads a snapshot taken before
those confirmations were written.

One known gap: a domain served by two suppressed relay families gets a single
child row, under whichever family claimed it first. Releasing that family
releases the row even though the other is still blocking. The next scan
re-creates it under the remaining family, so the exposure is one scan interval.

## Queue hygiene

`mail:deferrals:scan --purge` deletes the queued backlog for a suppressed
relay. It is dry-run unless `--force` is also given.

This is not tidying. Postfix retries a deferred message until
`maximal_queue_lifetime` and then emits **one bounce per message**, each
carrying the provider's original 4xx text, each landing back in our own bounce
processing. With around nine queued messages per affected member, that would
push essentially every one of them past the five-soft-bounces-in-fourteen-days
threshold in `BounceService::checkAndSuspendUser()` and suspend them for a
problem that was ours.

`postsuper -d` removes messages silently with no DSN at all, which is the only
thing that stops that cascade.

As a second line of defence, `BounceService::IGNORE_PATTERNS` now matches
`temporarily deferred`, `[TSS04]` and `delivery time expired`, so a queue-expiry
DSN that does reach us never counts toward suspension. Note that VERP does not
save us here: bulk mail carries a plain `noreply@` envelope sender, but
`processBounce()` falls back to the DSN's `Original-Recipient` header, so
attribution still lands on the member.

Only ever purge after a dry run against a verified id list. Ids are filtered
against a queue-id shape before they reach the relay's shell.

## What moderators see

`ModMailDelayed.vue` renders next to the existing bouncing treatment in
`ModMember.vue`:

> **Email delayed since 15 August** - Yahoo is not currently accepting our mail.

It is deliberately `info` rather than `danger`, and deliberately worded so it
cannot be mistaken for bouncing. The only correct action is to wait.

The apiv2 `/memberships` payload carries `maildelayedsince`,
`maildelayedprovider` and `maildelayedcount`.

All three read from `mail_suppressed_counts`, which the batch side writes -
including which suppression was in force - at the moment it declines to
generate each email. That is deliberate. Working out later which provider is
refusing a given member would mean resolving their send address the way the
mailer does (a ranking over `users_emails`, not a flag) and then matching it
by domain: not something a reporting query should be reimplementing, not
indexable, and wrong by the time the suppression has been released anyway.

The consequence worth knowing is that a member shows as delayed once we have
actually held something back for them, rather than from the instant their
provider is suppressed. In practice that is the next digest or post
notification they were due. "Delayed since" therefore means "since we started
holding your mail", which is also the date the count belongs to.

They are correlated subqueries rather than joins, because a member has one row
per type of mail held and a join would multiply member rows; each is keyed on
`msc.userid`, the leading column of that table's unique index. They are
pointers in Go so a query branch that does not select them reads as *unknown*
rather than as a confident "not delayed".

**Support view**: sysadmin > Mail > Delayed
(`GET /modtools/email/deferrals`) lists every active suppression and every
member whose mail is being held, capped at 1,000 rows with the cap stated
rather than silently applied.

## Configuration

Everything lives under `freegle.mail.deferrals` in `iznik-batch/config/freegle.php`.

The feature is **off by default** (`FREEGLE_MAIL_DEFERRALS_ENABLED`) and the
schedule entry is wrapped in that config check, so it is not even registered in
dev or CI.

The relay's ssh target (`FREEGLE_MAIL_DEFERRALS_HOST`) and its key
(`MAIL_DEFERRALS_SSH_KEY_HOST_PATH`, bind-mounted read-only into the container)
live only in the uncommitted environment on the batch host, following the same
rule as `FREEGLE_MONITORING_HOSTS`. The topology never enters committed code.

The key is deliberately separate from the monitoring key. That one is a root
shell across the whole estate; this one only ever needs to read a mail queue,
and should be restricted with a forced command in `authorized_keys` on the
relay. That restriction is an ops action outside this repo.

Thresholds (backlog size, delivery rate, release window, staleness) are all
env-overridable - see the comments in `config/freegle.php`, which explain what
each was set against.

## Schema

Two tables, created by
`2026_08_18_000001_create_mail_suppressions_tables.php` with the paired
idempotent production SQL alongside it.

`mail_suppressions` - one row per suppression, scoped `mxgroup`, `domain` or
`address`. Domain rows hang off their mxgroup row via `parentid`, so releasing
a provider cascades. Rows are kept after release; the active set is
`released_at IS NULL`.

`mail_suppressed_counts` - per member and per type, what we declined to
generate, plus the `suppressionid` that was in force at the time. Claimed by
`caughtup_at` before the catch-up sends, so a crash cannot send it twice.

## Tests

- `tests/Unit/Services/Mail/Deferrals/MxGrouperTest.php` - relay grouping,
  including the public-suffix and shared-platform cases that would otherwise
  suppress far too much.
- `tests/Unit/Services/Mail/Deferrals/DeferralProbeTest.php` - parsing real
  `postqueue -j` shapes, truncation, unattributable deferrals, and the purge
  input filter.
- `tests/Feature/Mail/DeferralScanServiceTest.php` - the suppress/release
  decisions, including "busy is not blocked" and the two-clear-scan rule.
- `tests/Feature/Mail/MailSuppressionServiceTest.php` - the gate itself.
- `tests/Feature/Mail/DeferralCatchUpServiceTest.php` - one catch-up, not a
  replay.
- `tests/Feature/Mail/ScanDeferralsCommandTest.php` - the acceptance test:
  replaying the incident queue, one scan suppresses Yahoo and every domain
  behind it while Gmail keeps getting mail, and purge refuses to run without
  `--force`.
- `tests/Unit/Services/Mail/Incoming/BounceServiceTest.php` - the deferral
  ignore patterns.
- `iznik-server-go/test/membership_test.go` - the delayed fields on the
  memberships payload, including that they clear after catch-up.
- `iznik-nuxt3/tests/unit/components/modtools/ModMailDelayed.spec.js` - the
  moderator wording, and that it reads as information rather than as a fault.
