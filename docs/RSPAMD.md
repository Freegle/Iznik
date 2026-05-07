# Rspamd Integration

## What it does

Rspamd sits as a Postfix milter on port 11332. Every incoming SMTP connection is scored.
Definite spam (score >= 15) is rejected with SMTP 5xx before entering the Postfix queue.
Borderline mail (score >= 5) passes with X-Rspamd-* headers added.

## Web UI

http://rspamd.localhost — password set in `conf/rspamd/local.d/worker-controller.inc`
(default `q1` for dev; replace with a `rspamadm pw --encrypt` hash for production).

## Threshold tuning

After a few weeks of production traffic, review score distributions in the web UI History
tab and adjust `conf/rspamd/local.d/actions.conf`. Start conservative (`reject=15`) and
lower gradually.

## Where milter-modified mail goes

Mail to `groups.ilovefreegle.org`, `users.ilovefreegle.org`, etc. is routed via
`transport_maps` to the `freegle-mail-handler` pipe, which POSTs the (now
milter-decorated) message to `batch-prod:8080/api/mail/incoming`. It does NOT go to
mailpit. Inspect batch-prod logs or the rspamd History tab to verify headers/scores.

## Relationship with SpamAssassin

Rspamd and SpamAssassin run **in parallel at the application layer**, not inside rspamd.
Each filter sees every non-rejected incoming message:

1. Postfix milter calls **rspamd only** (port 11332). rspamd uses its own rules, Bayes,
   RBLs, etc. Hard rejects (score >= 15) drop here at SMTP time.
2. Surviving messages flow through the freegle pipe to batch-prod, where
   `IncomingMailService::checkForSpam()` calls **SpamAssassin only** via
   `SPAMC/1.2` against `spamassassin-app:783`. Chat-message paths also call SA via
   `getSpamAssassinScore()`.

The rspamd `spamassassin` plugin is **not** used and must not be configured here — it
loads SpamAssassin `.cf` rule files, it does not talk to a remote `spamd` daemon. There
is no `local.d/spamassassin.conf` for that reason.

The dormant `App\Services\SpamCheck\RspamdService::checkAll()` (formerly
`SpamCheckService`) is the outgoing-mail equivalent that would consult both filters in
parallel and add both header sets. It is currently inert (`SPAM_CHECK_ENABLED=false`).

## Fallback

`milter_default_action = accept` — if rspamd is unreachable, Postfix accepts mail
normally and the SpamAssassin stage in batch-prod still runs.
