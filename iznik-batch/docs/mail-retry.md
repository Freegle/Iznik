# Durable mail retry (render-failure recovery)

## The problem this solves

Emails are rendered **inline** on the cron hot path and then handed to
`EmailSpoolerService::spool()`, which writes the fully-built message to the
file spool for the `mail-spooler` daemon to send. The render happens *before*
anything durable is written.

So if a render/build throws — a template referencing a key the PHP side isn't
supplying yet (e.g. `heroImageUrl` during a deploy window), an MJML-server
blip, a DB hiccup — the message never reaches the spool. The spooler's
send-time retry can't help something that was never spooled, and historically
the caller caught the exception, advanced its cursor, and **silently dropped**
the recipient. That is exactly how ~1,100 immediate digests were lost over one
~8-minute deploy window.

The send step was already resilient (file spool + `mail:spool:process`). The
**render step was not.** This mechanism closes that gap, generically, for any
email type.

## How it works

1. A mailable opts in by implementing `App\Mail\Contracts\RetryableMailable`
   (two methods — see below). No call-site changes are needed; the hook lives
   in `spool()`.
2. When `spool()` catches a **non-permanent** build/render failure for a
   `RetryableMailable`, instead of rethrowing (→ drop) it captures the
   mailable's scalar **descriptor** (IDs only) and dispatches an
   `App\Jobs\SpoolMail` queue job, then returns `''`. The cron carries on and
   its cursor advances safely — the job now owns delivery.
3. The 2 `queue:work` workers (`docker/supervisor.conf`) run `SpoolMail`, which
   rebuilds a **fresh** mailable from current DB state via
   `rebuildFromDescriptor()` and re-renders + spools it
   (`autoRetry: false`, so a still-broken render throws back into the queue's
   own retry machinery rather than dispatching another job — no infinite loop).
4. Retry policy is Laravel-native: `retryUntil(now+24h)` with capped backoff
   (1m, 5m, then 10m). A fix deployed within 24h drains the backlog
   automatically on the next backoff tick. Jobs still failing after 24h land in
   `failed_jobs`.

Why the failure path (not write-before-render for every email)? Volume. The
live immediate-digest path renders ~300 emails/min across 8 shards; routing the
whole firehose through 2 queue workers would bottleneck it. Only **failures**
touch the queue. And the cron's cursor only advances *after* a message is
processed, so a hard mid-render crash already reprocesses next tick — the only
uncovered drop vector was the caught-and-swallowed exception, which this
converts into a durable retry.

Why store IDs, not the built mailable? So the retry renders against **current**
data, and so the queue payload stays small and stable. (Laravel's
`SerializesModels` only reduces *top-level* models to IDs; our mailables hold
collections of `['message' => Message, ...]` arrays, whose models would
otherwise be serialised whole.)

Delivery guarantee is **at-least-once**: a duplicate is possible (e.g. the
original send actually succeeded but was recorded as a failure). That is a
deliberate trade — drops are the harm we are protecting against, not
duplicates.

## Onboarding a mailable

Implement the interface:

```php
use App\Mail\Contracts\RetryableMailable;

class MyMail extends MjmlMailable implements RetryableMailable
{
    public function mailDescriptor(): array
    {
        // IDs only — never models or built objects.
        return ['userid' => $this->user->id, 'thingid' => $this->thing->id];
    }

    public static function rebuildFromDescriptor(array $descriptor): ?self
    {
        $user = User::find($descriptor['userid'] ?? null);
        $thing = Thing::find($descriptor['thingid'] ?? null);

        // Return null when the email is no longer applicable (recipient/data
        // deleted). That cancels the retry — it's "nothing to send", NOT a
        // failure, so it never counts toward dead-lettering.
        if (! $user || ! $user->email_preferred || ! $thing) {
            return null;
        }

        return new self($user, $thing);
    }
}
```

Currently onboarded: `App\Mail\Digest\UnifiedDigest` (the incident) and
`App\Mail\Chat\ChatNotification`.

## Monitoring & recovery

- `monitor:email-health` runs every 15 min and now also alerts (24/7, escalated
  to Sentry) when any `SpoolMail` jobs are sitting in `failed_jobs` — a
  non-zero count almost always means a render bug is dropping a whole class of
  email. Threshold: `FREEGLE_EMAIL_HEALTH_FAILED_MAIL_RETRY_THRESHOLD`
  (`config('freegle.email_health.failed_mail_retry_threshold')`, default 1).
- `mail:retry-failed` re-queues parked `SpoolMail` jobs after the fix is
  deployed (`--limit=N` to cap). Scoped to `SpoolMail`, so it won't disturb
  other failed jobs. Nothing is ever lost — `failed_jobs` just means "held,
  needs a human to ship the fix".
