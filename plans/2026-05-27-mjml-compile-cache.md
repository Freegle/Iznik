# MJML Compile-Once / Substitute-Many for Bulk Mailables

**Date:** 2026-05-27
**Branch:** `feature/mjml-compile-cache`
**Goal:** Reduce MJML sidecar load at scale so batch sends (immediate digest, newsletters, donation asks, mod notifications) can ramp without overloading `freegle-mjml`.

---

## Problem

`MjmlMailable::build()` rebuilds the MJML pipeline on every `Mail::send`:

1. Render Blade → MJML string (per-recipient values inlined)
2. POST MJML to `freegle-mjml` sidecar → HTML
3. Set HTML on the email

For batch sends, this means **N sidecar compiles for N recipients** even when the
template body is structurally identical. Confirmed in
`UnifiedDigestService::processGroupImmediate()` (lines 249–285): one message → all
group members in immediate mode runs a full `Mail::send(new UnifiedDigest(...))`
per recipient. `MjmlCompilerService` is wrapped in a `BoundedPool` of 20 with a 30s
timeout — the team already classifies MJML compile as heavy.

This is the same problem AWS SES, Postmark, and Mailchimp/Mandrill solved via
their "send bulk templated email" APIs: one compiled body + N small substitution
maps. SMTP transport (`config/mail.php` → smtp) means we can't outsource it to
SES; the primitive has to live in our application layer.

## Approach

Adopt the industry-standard placeholder pattern using `{{varname}}` syntax —
**verified against the live sidecar** (2026-05-27): braces survive MJML
compilation intact in text content and in `href`/`src`/`alt` attributes. The CSS
inliner only touches `<style>` and `style=`, so as long as placeholders stay out
of CSS, they round-trip cleanly.

### Flow

```
                                 Per cron tick (process-local cache)
┌──────────────────────────────────────────────────────────────────┐
│                                                                  │
│  Mailable::sharedData()    Mailable::recipientVars(User $u)     │
│        │                          │                              │
│        ▼                          ▼                              │
│  Blade render  ───►  MJML w/ {{vars}}  ───sidecar──►  HTML w/ {{vars}}
│                                                          │       │
│                            (cached by shape key)         │       │
│                                                          ▼       │
│   For each recipient:                            strtr({{var}}→value)
│   ─ build per-recipient map                              │       │
│   ─ substitute                                           ▼       │
│   ─ validate (no unbound {{x}})                  Final HTML      │
│   ─ hand to Mailable for spool                                   │
└──────────────────────────────────────────────────────────────────┘
```

### New components

**`App\Services\BulkMjmlCompiler`** — process-local cache + substitution engine.

```php
class BulkMjmlCompiler
{
    /** @var array<string,string> sha1(mjml) → HTML with {{vars}} */
    private array $htmlCache = [];

    /** @return string HTML with {{vars}} placeholders intact */
    public function compileTemplate(string $mjml): string { /* hash + lookup + sidecar */ }

    /** @return string final HTML with placeholders substituted */
    public function substitute(string $htmlWithVars, array $vars): string
    {
        // Pre-encode HTML special chars in values; strtr; then validate no {{x}} left.
    }

    public function clearCache(): void { /* called between cron runs */ }
}
```

**Trait `App\Mail\Concerns\BulkRenderable`** — opt-in contract for mailables.

```php
trait BulkRenderable
{
    /** Stable bucket for recipients that share the same compiled HTML. */
    abstract public function shapeKey(User $u): string;

    /** Per-recipient placeholder substitutions, keyed by var name. */
    abstract public function recipientVars(User $u): array;

    /** Render Blade with placeholders intact, compile MJML, cache by shape. */
    public function renderForRecipient(User $u, BulkMjmlCompiler $compiler): string;
}
```

**`App\Mail\Concerns\HasMergeVars`** — Blade helper.

The Blade template uses literal `@{{varname}}` for per-recipient values (Blade's
documented escape so `{{ }}` is emitted verbatim instead of being interpolated).
The trait provides helpers for shared mailable code to declare a variable as
"merge-only", returning the literal `{{varname}}` string at render time.

### Mailable changes (opt-in)

A mailable that opts in declares:
- `shapeKey(User): string` — typically a hash of (template_path, structural
  inputs that change template output). For immediate digest:
  `sha1(message_id|has_jobs|has_amp|sponsor_set_hash)`.
- `recipientVars(User): array` — map of var name → value. Values are HTML-encoded
  by the compiler before substitution.
- The Blade template uses `@{{trackUrl}}`, `@{{distanceText}}` etc. for per-recipient
  values, and normal `{{ $php }}` for shared values.

Mailables that don't opt in continue to use the existing `MjmlMailable::build()`
path — no caching, no `{{vars}}` requirement, no behaviour change.

### Sending loop (caller side)

`App\Services\BulkMailDispatcher` wraps the iteration:

```php
$dispatcher = new BulkMailDispatcher(UnifiedDigest::class, $sharedCtor);
foreach ($users as $user) {
    if (...skip rules...) continue;
    $dispatcher->add($user);
}
$dispatcher->dispatch();   // buckets by shape, compiles per shape, substitutes per recipient, spools each
```

Inside `dispatch()`:
1. Group recipients by `Mailable::shapeKey($user)`.
2. For each shape: instantiate the Mailable with the first recipient, call
   `renderForRecipient()` (which caches the compiled HTML keyed by shape).
3. For each subsequent recipient in the shape: substitute `recipientVars($user)`
   into the cached HTML, build the per-recipient envelope/headers, spool.
4. Validation step rejects unbound `{{[a-zA-Z_][a-zA-Z0-9_]*}}` after substitution.

### Cache lifecycle

- Process-local (PHP array). The digest cron is single-process; cross-process
  sharing isn't needed.
- Cleared on dispatcher destruction (one per send batch) so we don't leak.
- No Redis. 500KB HTML × a few hundred shapes per batch is well within memory.

### Failure modes and safeguards

- **Unbound placeholder leak**: post-substitute regex sweep raises
  `UnboundPlaceholderException` listing the missing keys. Fail-loud — no email
  goes out with `{{varname}}` visible to a user.
- **Placeholder inside CSS**: forbidden by convention. Reviewer checks; lint
  rule in mailable tests.
- **Placeholder values containing HTML specials** (`&`, `<`, `>`, `"`):
  pre-encoded via `htmlspecialchars` before strtr. URL values already use
  `&amp;` per MJML/XML convention.
- **Template mismatch between shapes**: shape key includes a hash of structural
  inputs, so different `@if` branches produce different cache entries.
- **AMP variant**: AMP rendering uses a separate template path (`emails.amp.*`)
  and is per-user anyway (AMP enable/disable is a user attribute). For Phase 1,
  AMP rendering stays on the per-recipient path; the bulk primitive only caches
  the HTML body. (Future work: parallel cache for AMP.)

## Migration scope

This PR migrates batch-send mailables in iznik-batch where the same content
fans out to multiple recipients AND the per-recipient body variation is
small enough to fit the merge-var model.

Migrated:

| Mailable | Service / call site | Multiplier source | Notes |
|---|---|---|---|
| `UnifiedDigest` (immediate) | `UnifiedDigestService::processGroupImmediate` | 1 message → N group members | Users with nearby jobs fall to unique-shape (jobs differ); users without share |
| `StoriesNewsletterMail` | `StoriesNewsletterService` | 1 newsletter → N subscribers | Single cached body per run — only footer email differs |
| `AskMail` | `StoriesAskService` | 1 ask → N targets | Two merge vars (name, email) |
| `ChaseAdminMail` | `ChaseAdminCommand` | 1 chase → N mods of a group | userName + trackingPixelUrl per mod |
| `EventsDigestMail` | `EventsDigestService` | 1 group's events → N members | Two merge vars (email, unsubscribeUrl) |
| `VolunteeringDigestMail` | `VolunteeringDigestService` | 1 group's vols → N members | Members without jobs share; with-jobs go solo |

Deliberately skipped — content is per-recipient or audience size is 1, so
the cache primitive gives no benefit:

| Mailable | Why skipped |
|---|---|
| `ModNotifMail` | Each mod's `htmlSummary` is unique → cache hit = 0 |
| `NewsfeedModNotifMail` | Each mod's `posts` is filtered by their groups → cache hit = 0 |
| `ChitchatReportMail` | One email to a fixed support address list, not a fan-out |
| `AlertNoMessagesMail` | Single recipient (mentors address) |
| `AskForDonation` | Per-user `$itemSubject` + many per-user tracked URLs — viable but complex; deferred |
| `UnifiedDigest` (daily) | Per-user content; shapeKey returns unique-per-user → cache no-op, no regression |

Truly per-recipient mailables (welcome, verify-email, forgot-password,
chat-notification, birthday, donation thank-you, etc.) keep using the
existing per-send compile path. No change.

## Test strategy

This is correctness-critical work. Tests must prove the new path produces
**byte-identical** HTML to the old path for every migrated mailable.

1. **Parity tests** (per migrated mailable): given a Mailable instance and a list
   of users, render via the bulk path; render the same recipients via the
   old per-send path. Assert byte-identical HTML bodies. Run with realistic
   variation: with/without jobs, with/without sponsors, with/without AMP.

2. **Cache hit accounting**: assert that for N recipients of the same shape,
   the sidecar is called exactly once (using a mocked `MjmlCompilerService`
   that counts compiles).

3. **Validation**: a mailable that forgets to provide a `{{var}}` raises
   `UnboundPlaceholderException` with the missing key names listed.

4. **HTML encoding**: a `recipientVars` value containing `&`, `<`, `"` is
   correctly encoded so the resulting HTML validates.

5. **Headers and envelope**: per-recipient headers (`X-Freegle-User-Id`,
   `List-Unsubscribe`, `Feedback-ID`, reply-to) still come out per-recipient
   even though the body is shared.

6. **AMP variant**: where the mailable supports AMP, the AMP part is still
   rendered per-recipient (not cached). Verified via the existing AMP-on
   integration tests.

7. **Full suite**: Unit + Feature + Integration suites all pass. Mailpit
   integration test for each migrated mailable confirms end-to-end delivery
   with correct headers.

## Rollout

- Add a feature flag `freegle.bulk_mail.enabled` defaulting to `true`. If a
  regression slips through, ops can flip the flag and every mailable falls
  back to the existing per-send path.
- For the immediate digest specifically, ops can also keep the existing
  `FREEGLE_DIGEST_IMMEDIATE_ALLOWLIST` pilot gate — the bulk path doesn't
  change the allowlist semantics.

## Open question

`UnifiedDigest` daily mode is per-user (each user sees their own union of
group memberships), but the per-message body content (item name, image,
description, poster avatar) is shared across users that received the same
message. There may be sharing opportunities at the **per-post fragment** level
rather than the whole-email level. This is a separate design question; for
this PR daily digest goes through `BulkMailDispatcher` but `shapeKey` returns
a unique value per recipient (so it's effectively the old path — zero
regression, zero cache hits). A follow-up PR can refine `shapeKey` once we've
measured.

## What this PR does NOT change

- `MjmlCompilerService` itself. It stays as-is for non-batch sends and is used
  internally by `BulkMjmlCompiler` for the actual sidecar call.
- The `freegle-mjml` sidecar. No protocol or container changes.
- Existing MJML templates outside the migrated set.
- The spool format or any X-Freegle-* header semantics.
- The AMP rendering path.
