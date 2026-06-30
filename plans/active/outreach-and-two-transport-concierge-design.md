# Email-native community outreach + two-transport concierge - design

Status: DRAFT for review (2026-06-29). Branch: `feat/helper-concierge-agent` (PR #911).
Supersedes nothing; sits alongside `plans/active/freegle-helper-concierge.md` (the existing
Freegle-chat concierge), which becomes transport #2 here.

## 1. What we are building and why

A **standalone outreach capability**: cold-email a curated list of local community
organisations to tell them about items a donor is giving away, and have an AI concierge
("Natalie @ Freegle") manage the resulting conversations to allocation - entirely over email.

Immediate driver: a **trial of ~10 individual Freegle OFFER posts**. The posts are made from
one dedicated account; we email nearby orgs (e.g. the Olave Centre / NW3 outreach list); replies
are handled by the concierge. This is decoupled from the structured bulk-offer feature (#618).

Two distinct things share one concierge brain:
1. **Outreach** - cold emails to orgs that are NOT Freegle users, sent from a real mailbox.
2. **Concierge** - the existing #911 reply-concierge FSM, refactored so it can run over either
   the mailbox (this trial) or directly on a Freegle account (member-to-member clearances).

## 2. Decisions locked

- **Email-native (Option B).** Orgs are never made Freegle users; they are ordinary email
  correspondents. This satisfies "org contacts must never receive any normal Freegle email"
  *by construction* (they do not exist in Freegle's user table), and is fairer to strangers who
  do not know Freegle's website. (Option A - orgs as suppressed Freegle users on the chat
  concierge - was rejected: the no-Freegle-email rule would be a fragile denylist against a
  system designed to email its users.)
- **Dedicated identity:** mailbox `natalie-wagg@ilovefreegle.org`, From display name
  `Natalie @ Freegle`. This is a **dual identity**: a real Google Workspace mailbox AND a Freegle
  user (the offerer of the 10 posts) whose Freegle chat-notifications are delivered to the same
  mailbox. All non-chat Freegle mail to that user is suppressed (`newslettersallowed=0`,
  `relevantallowed=0`, no group membership).
- **Language: PHP** (lives in `iznik-batch` next to MJML rendering and the mail infra). One
  `GmailService` serves both the outreach sender and the FSM mailbox transport.
- **Unsubscribe is mailbox-native** - a `List-Unsubscribe` header + a visible link to a
  `mailto:` unsubscribe address; the concierge watching the inbox records suppression. No web
  endpoint to stand up for the trial.
- **Auth:** a Google **service account with domain-wide delegation** impersonating the mailbox,
  scopes `gmail.send` + `gmail.modify`. SA key referenced by host path, never committed.
- **`@ilovefreegle.org` is usable as a real freegler email** (verified, see §8).

## 3. Why `@ilovefreegle.org` works as a normal freegler (verified)

- `OURDOMAINS` (`iznik-server/include/config.php:78`) = `users.`, `groups.`,
  `direct.ilovefreegle.org`, `republisher.freegle.in`. **Bare `ilovefreegle.org` is not in it**,
  so `Mail::ourDomain('x@ilovefreegle.org')` is FALSE - the code treats it as an ordinary
  external address.
- `User::getEmailPreferred()` (`User.php:473`) therefore accepts it, and the **chat-notification
  send path uses `getEmailPreferred()`** - so chat-notify emails are delivered to the real Google
  mailbox. This is the leg the unified design depends on.
- `User::addEmail()` (`User.php:673`) accepts it.
- The only special-case is `Mail::realEmail()` returning FALSE for `@ilovefreegle.org`
  (`Mail.php:213`), which gates only **group volunteer alerts** (`Alert.php:262`) - which we
  want suppressed anyway.

## 4. Architecture

### 4.1 Identity
`natalie-wagg@ilovefreegle.org`:
- **Gmail mailbox** (Workspace) - the concierge reads/sends here.
- **Freegle user** - offerer of the 10 trial OFFER posts; preferred email is the mailbox;
  all non-chat Freegle mail suppressed.

### 4.2 Two kinds of email in the mailbox
- **(a) Outreach + org replies** - the cold email goes out via the Gmail API; `Reply-To` is the
  mailbox, so org replies are ordinary Gmail threads.
- **(b) Freegle chat-notify emails** - if anyone (an org via the website, or a normal Freegler)
  messages the offerer on Freegle, Freegle emails a chat notification to the mailbox.
  These are recognisable by header `X-Freegle-Mail-Type: ChatNotification` and
  `Reply-To: notify-<chatid>-<userid>@users.ilovefreegle.org`. Replying to that `Reply-To`
  routes the text back into the Freegle chat (`MailRouter::replyToChatNotification`).

The concierge handles both from one inbox - this is the "other Freeglers are just another email
thread" unification.

### 4.3 One FSM brain, two transports
```
                    +------------------------------+
                    |   Concierge FSM (the brain)  |
                    |  states, scoring, proposals  |  <- transport-agnostic
                    +---------------+--------------+
                                    |
                +-------------------+--------------------+
                |                                        |
     +----------v-----------+               +------------v-----------+
     | Mailbox transport     |               | Freegle-account transp |
     | (Gmail API)           |               | (existing /helper API) |
     | - org email threads   |               | - native chat poll     |
     | - Freegle chat-notify |               | - send-as-offerer      |
     |   emails (reply to    |               | - helper_* side effects|
     |   notify- address)    |               |   (Reserved/Promise)   |
     +-----------------------+               +------------------------+
        used by THIS trial                      used when the concierge
                                                is offered to Freeglers
```

Transport interface (from discovery): `hasNewActivity`, `listOpenThreads`, `readThread`,
`getReplierInfo`, `sendReply`, `markThreadRead`, `upsertReplierRecord`, `setItemState`,
`createProposal`, `listPendingProposals`, `resolveProposal`, `recordAllocation`,
`recordCollected`, `recordRejection`. The brain, scoring rubric, tone rules and proposal flow
are unchanged across transports.

## 5. Components (all in `iznik-batch` unless noted)

1. **`app/Services/Gmail/GmailService.php`** - the Gmail client.
   - Auth: `google/auth` `ServiceAccountCredentials` with `setSub('natalie-wagg@…')` (DWD), scopes
     `gmail.send` + `gmail.modify`; SA key path from `GMAIL_OUTREACH_CREDENTIALS_PATH`.
   - `send(Address $from, Address $to, string $subject, string $html, string $text, array $headers)` -
     builds an RFC 2822 MIME message, base64url, `users.messages.send`.
   - `listThreads(string $query)`, `getThread(string $id)`, `getMessage(string $id)` - read.
   - `modifyLabels(string $id, array $add, array $remove)` - state + mark-read.
   - **`DRY_RUN` mode** (default until the SA key is present): instead of calling Google, writes
     the full rendered `.eml` to `storage/app/outreach/dryrun/` and logs. Lets us see real output
     before any image rebuild or admin step.
2. **MJML outreach email** - `resources/views/emails/mjml/outreach/initial.blade.php`
   (+ `emails/text/outreach/initial.blade.php` plain-text). Rendered to HTML via
   `MjmlCompilerService::compile()`. Content: warm intro signed "Natalie @ Freegle", the list of
   the ~10 offer posts (title + link), the provenance footer ("we found your details published
   online, local to these items…"), and the `mailto:` unsubscribe link. One email per org.
3. **`app/Services/Outreach/OutreachRecipientStore.php`** - file-backed for the trial.
   - Input: a recipients CSV (name, org, email, area, the offer subset to mention).
   - Ledger JSON: per-email `status` (pending/sent/replied/suppressed), `sent_at`, `message_id`.
   - Suppression list (emails) consulted before every send; appended to by the concierge when an
     unsubscribe arrives.
4. **`app/Console/Commands/Outreach/SendOutreachCommand.php`** - `outreach:send`.
   - Flags: `--recipients=<csv>`, `--posts=<csv|json of post urls/titles>`, `--limit=N`,
     `--dry-run` (default on), `--live`. Honours suppression + one-email-per-org; rate-limits;
     records the ledger. Renders per-recipient and calls `GmailService::send`.
5. **FSM mailbox transport** -
   - `.claude/skills/freegle-helper-concierge/SKILL.md` refactored: an `## I/O contract` section
     parameterised by `$TRANSPORT` (freegle|gmail); the FSM-logic sections unchanged.
   - `helper-poll-gmail.sh` - cheap poll (Gmail `search_threads`/list, hash) mirroring
     `helper-poll.sh`.
   - Gmail transport ops exposed as Artisan commands the loop calls: `outreach:poll`,
     `outreach:read-thread`, `outreach:reply` (reply-by-email; if the thread is a Freegle
     chat-notify, reply to its `notify-` Reply-To so it threads back into the chat).
   - State (knowledge records, proposals) for the mailbox transport stored in the `helper_*`
     tables with a `transport='gmail'` discriminator, or a flat JSON ledger if we keep the trial
     fully out of the DB (decide in build; default: JSON ledger for the trial to stay standalone).

## 6. Data flow (trial)

1. `outreach:send --live` renders + sends one email per org from `natalie-wagg@`, `Reply-To`
   the mailbox, `List-Unsubscribe` set. Ledger marks `sent`.
2. Org replies → lands as a Gmail thread in the mailbox.
3. Concierge loop: poll → on change, read new threads → FSM decides → `outreach:reply` sends the
   response from the mailbox. Gathers requirements, scores, proposes allocations for human
   approval, confirms collection - same FSM as #911.
4. If anyone instead messages the offerer on the Freegle site, the chat-notify email arrives in
   the same mailbox; the FSM replies to its `notify-` address and the text threads into the
   Freegle chat. Optionally the dedicated account marks the Freegle post outcome via the API.
5. Unsubscribe email → concierge appends the sender to the suppression list; never contacted again.

## 7. Suppression / unsubscribe (mailbox-native)

- Every outreach email carries `List-Unsubscribe: <mailto:natalie-wagg+unsub@ilovefreegle.org?subject=unsubscribe>`
  (+ a visible body link to the same) and `List-Unsubscribe-Post` for one-click.
- The concierge inbox loop treats any message to the `+unsub` sub-address (or subject
  "unsubscribe") as a global, permanent opt-out: append the email to the suppression list, send a
  brief confirmation, stop. Checked before every future send.
- Global + permanent, as agreed.

## 8. Human prerequisites (block go-live, not the build)

1. **Mailbox** `natalie-wagg@ilovefreegle.org` exists as a real licensed Workspace mailbox.
2. **Service account + domain-wide delegation**: SA created in a GCP project with the Gmail API
   enabled; in Admin Console grant its client ID the scopes
   `https://www.googleapis.com/auth/gmail.send,https://www.googleapis.com/auth/gmail.modify`.
3. SA JSON key placed on the host (outside the repo); path given to us; wired as
   `GMAIL_OUTREACH_CREDENTIALS_PATH` (host-path mount, Firebase-key pattern).
4. The 10 OFFER posts made from the dedicated Freegle account; the list of post URLs given to us.

## 9. Auth + deliverability

- DWD service account, impersonation `sub=natalie-wagg@…`, scopes `gmail.send`+`gmail.modify`.
- DNS is already correct: SPF + DKIM pass; DMARC `p=reject`. Sending via the Gmail API as
  `@ilovefreegle.org` passes DMARC automatically (Exclaimer bypassed on the API path).
- Ramp 50-100/day; From a recognisable name; `Reply-To` the mailbox; plain-text part included;
  avoid link-heavy first emails.

## 10. Build order

A. `GmailService` with DRY_RUN (no Google dep needed for dry-run).
B. MJML outreach template + text fallback + render check.
C. `OutreachRecipientStore` + `outreach:send` (dry-run end-to-end producing real `.eml`).
D. Live path: add `google/auth` to composer, batch image rebuild, live auth check (send to self,
   read back).
E. FSM: transport-parameterise `SKILL.md`, add `helper-poll-gmail.sh` + `outreach:poll/read/reply`.
F. Validate end-to-end with a self-test org before any real contact.

## 11. Testing

- Dry-run render: `.eml` + HTML written to disk and eyeballed (and screenshot via headless Chrome).
- Live auth check: send to `geeks@`/a test inbox, read it back via the Gmail API, inspect
  `Authentication-Results` for dkim/spf/dmarc=pass.
- Concierge: drive a fake org thread end-to-end in dry-run before real sends.
- No real org is contacted until the human prerequisites are done and a self-test passes.

## 12. Risks / open questions

- Org-policy "disable SA key creation" could block the key (fallback: workload identity - overkill).
- Mailbox must be a real mailbox, not an alias, or replies won't be readable.
- Whether mailbox-transport FSM state lives in `helper_*` (shared, `transport` column) or a JSON
  ledger - default JSON for the standalone trial; revisit if we productise.
- Gmail per-day send cap (2,000 Workspace); trivial at trial scale.

## 13. Out of scope (for the trial)

- A web unsubscribe page (mailbox-native is enough).
- Productising the concierge-for-Freeglers offer (transport #2 already exists via #911; this
  spec only keeps it a first-class path).
- Any change to the #618 bulk-offer feature.
