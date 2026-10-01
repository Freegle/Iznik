# Create Freegle users for unknown TN posters during API post ingestion

## Context

`GroupPostIngestionService::ingest()` resolves the poster via `users.tnuserid`. When no Freegle (FD) user holds that id, the post is dropped as `Post from unknown user` (`GroupPostIngestionService.php:238-251`). The reason given in `resolveUser()`'s docblock (`:630-642`) is that the API supplies no name and no address. That is no longer true. TN (Andrew) confirmed that `GET /api/v1.4/users/{id}?api_key=…` returns `username` (plus `firstname`/`lastname`), and that `username@user.trashnothing.com` is a deliverable address. So instead of dropping the post, we look the user up on TN, create the FD user, and ingest the post.

The work also fixes a latent bug in `UserChangesSyncer`'s username handling. That bug becomes visible once the display name is prettified. See step 4.

## Decisions taken

- **New users stay unmapped.** No `lastlocation` is set at creation. A new user's first post therefore goes Pending with reason `unmapped user`, and mods are notified. `ingest()`'s routing is unchanged.
- **User data only, no membership.** The new user gets every user-level field the existing creation paths write (see "Field parity" under step 2). It does **not** get the group-membership side effects those paths add: no `memberships` row, no `memberships_history` row (so no welcome email or digests), no Group/Joined log entry and no reach-queue entry. This matches `ingest()`'s existing "no membership gate" design. TN's own partner sync adds the membership if and when the member joins.
- **Rate limiting is TBD.** You are testing whether the users endpoint is rate-limited at all. For now the lookup calls the existing `TrashNothingRateLimiter::await()`, so it stays inside the current 2 req/s budget (in-process only) and this costs nothing. The lookup is isolated in one class, so whatever you decide (cross-process `Cache::lock`, per-key buckets, or no throttle) changes one call site. Calls stay sequential: at 2 req/s, `Http::pool`/`Concurrency::run` would only make 429s more likely.
- **Restrictions found:** TN's developer page and the generated client docs publish no rate limit. The only number in the codebase is the "2 requests/second per key" in `TrashNothingRateLimiter`. The generated model doc (`PublicApi/docs/Model/User.md`) says `username` and `profile_image` are *"null for api key requests"*, but your sample from the developer key has them populated. The code must still handle a null `username` (see step 2).
- **Rate-limit probe results (2026-10-01, dev key):** `/users/{id}` is **not in TN's published OpenAPI spec** (`/api/v1.4/trashnothing-openapi.yaml`), so it is undocumented. The spec and terms of use mention no rate limits. 20 sequential calls at 2 req/s and 60 sequential calls with no delay (about 5.6 req/s) all returned 200, with no `X-RateLimit-*`/`RateLimit-*`/`Retry-After` headers and `cache-control: no-store` behind Cloudflare (`cf-cache-status: DYNAMIC`). Parallel load was not tested. The 2 req/s figure in `TrashNothingRateLimiter` is therefore unverified but not contradicted: keep `await()`, keep calls sequential, and treat 429/503/`cf-mitigated`/`Retry-After` as `tn-user-lookup-failed`.
- **Other API behaviour:** the key is only accepted as the `?api_key=` query parameter (a header gives 401). Errors are `text/html`, not JSON: `401 Invalid api_key parameter.`, `404 User N does not exist.`, and `404 not found` for non-numeric ids. Check the status before decoding the body. There is no bulk lookup (`/users/a,b` and `/users/multiple` both 404). The User-Agent is not filtered.

## Implementation

### 1. Shared TN name and address helpers on `App\Models\User`
Add these next to `removeTNGroup()` (`app/Models/User.php:385`):
- `tnDisplayName(string $username): string`. A PHP port of the Go `CreatePartnerUser` logic (`iznik-server-go/user/partner.go:152-164`): replace `.` and `_` with spaces, then `ucwords`. A comment cross-references the Go side.
- `tnEmailForUsername(string $username): string`. Returns `"{$username}@user.trashnothing.com"`.
- `tnUsernameFromEmail(string $email): ?string`. Returns the local part with any `-gNNN` suffix stripped, and only for `@user.trashnothing.com` addresses.

### 2. New `App\Services\TrashNothing\Ingestion\TnUserProvisioner`
- **Constructor:** `bool $dryRun`, `bool $localTesting`, `string $publicApiKey`, `LokiService $loki`, `?TrashNothingRateLimiter $rateLimiter`.
- **`resolveOrCreate(int $tnUserId): ?User`**
  1. Return the existing user from `User::where('tnuserid', …)`. This moves the lookup out of `GroupPostIngestionService::resolveUser`.
  2. Check a per-run memo, plus a short negative cache (`Cache`, about 6h, key `tn-user-lookup-miss:{id}`). Without it, the 10s sync overlap and the verifier's backfills would re-hit the API for the same 404 or null-username user.
  3. Fetch the user. Call `rateLimiter->await()` first, then `Http::timeout(…)->get("https://trashnothing.com/api/v1.4/users/{id}", ['api_key' => …])`. The host comes from a new config key `freegle.trashnothing.public_api_base_url`.
     - Use the plain `Http` client, as `UserChangesSyncer` does. The generated `UsersApi` has no `getUser` method.
     - Pass every logged error through `PostSyncer::redactApiKey()`.
     - With `localTesting`, read `tests/fixtures/tn_sync/users/{id}.json` instead.
  4. If the response is 404, `username` is empty, or the built address fails `filter_var(FILTER_VALIDATE_EMAIL)`, write the negative cache entry and return null.
  5. **Look for an existing account without `tnuserid`.** Use the same order as `IncomingMailService::findUserByEmail` (`:3753`): match the exact address first, then a `users_emails` row whose `canon` equals `User::canonMail(tnEmailForUsername($username))`. That canon also matches the per-group `-gNNN` aliases the email path created. As `FindTNCandidates` does (`partner.go:58`), only consider users with `deleted IS NULL`.
     - If its user has `tnuserid IS NULL`, stamp the id on that user and return it. This mirrors `EnsurePartnerIdentifiers` (`partner.go:226`) and avoids minting a twin. Also attach the bare `username@user.trashnothing.com` address with `$user->addEmail($addr, primary: 0)`, so later exact-match lookups hit, as `handleSubscribe` does with `addEmailToUser` (`:1232`). This keeps the member's current preferred address.
     - If its user holds a *different* `tnuserid`, return null with a distinct reason, `tn-username-clash`, and log it. TN says usernames are not guaranteed unique.
  6. **Create the user** inside `DB::transaction`, writing every field in the "Field parity" table below. Use Eloquent (`User::create`, then `$user->addEmail(...)`) so model events and auditing fire. Every write emits a `TN-SYNC-TRACE [WRITE]` line and honours `dryRun`. In dry run, return an unsaved `User` so `ingest()`'s trace still runs through (`createMessage` already handles the dry-run id).
  7. **Race:** if the `tnuserid` unique index throws a duplicate-key error, re-query by `tnuserid` and return that user. `tn:sync` and the hourly `tn:verify-email-coverage` backfill can provision the same user at the same time.
  8. Emit the Loki event `tn-sync` / `user-create-from-tn` with `tn_user_id`, `user_id` and `linked_existing`.
- **Field parity with the existing creation paths.** These are the two places a TN member's FD account is created today: the email path's `IncomingMailService::handleSubscribe` (`:1205-1221`), and the Go partner join `putMembershipsPartner` → `CreatePartnerUser` (`membership.go:1393`, `partner.go:147-212`).

  | Table.column | Email subscribe | Go partner | New API provisioning |
  |---|---|---|---|
  | `users.fullname` | From-header display name | username prettified (`.`/`_` → space, title case) | `tnDisplayName(username)`, matching Go |
  | `users.firstname` / `lastname` | not set | not set | the API's `firstname`/`lastname` when non-null (new data neither path had) |
  | `users.systemrole` | `'User'` (explicit) | column default `'User'` | `'User'` (explicit, as the email path) |
  | `users.added` | `now()` | `NOW()` | `now()` |
  | `users.lastaccess` | `now()` | column default (current) | `now()`; `ingest()` also sets it again straight after |
  | `users.tnuserid` | not set (later stamped by the partner sync) | set | set |
  | `users_emails.email` | the `-gNNN` alias the mail came from | the alias TN sent | bare `username@user.trashnothing.com` (TN's advice) |
  | `users_emails.preferred` | 1 | 1 | 1 |
  | `users_emails.added` | `now()` | `NOW()` | column default (current), via `addEmail` |
  | `users_emails.canon` | PHP canon | Go canon | PHP `User::canonMail` (filled in by `UserEmail::booted` too). Use the PHP form deliberately: `findUserByEmail`'s canon fallback can only find PHP-shaped canon (see `.claude/rules/mail-and-data.md`, "Go's CanonicalizeEmail is not PHP's") |
  | `users_emails.backwards` | (hook) | REVERSE(canon) | set by `UserEmail::booted` from canon; do not pass it |
  | `users_aboutme` | — | — | from `about_me` when non-null, same shape as `UserChangesSyncer:89-108` |
  | `users_replytime` | — | — | from `reply_time` when non-null, same shape as `UserChangesSyncer:73-86` |
  | membership, `memberships_history`, Group/Joined log, reach queue | yes | yes | **no**, by decision (see "Decisions taken") |

  Deliberately left unset, as neither existing path writes them: `lastlocation` (by decision), `settings`, `source`, and the per-user mail flags (column defaults apply). Go's `emailhygiene.Report` has no PHP counterpart and is skipped. Add an `ensureFieldParity`-style test: create a user through the provisioner and assert every row above, including the absence of membership rows.
- **Failure handling:** a 5xx, 429 or timeout returns null with reason `tn-user-lookup-failed`, and the result is **not** negatively cached. Before cutover, the post is dropped for this run, as it is today. After cutover, `tn:verify-email-coverage` finds it and backfills, and that backfill retries the lookup.

### 3. Wire it into `GroupPostIngestionService`
- Inject `TnUserProvisioner` through the constructor. `PostSyncer` builds it from `$this->apiKey` (the public key), its shared rate limiter, `dryRun` and `localTesting` (`PostSyncer.php:70`).
- Replace `resolveUser()` with the provisioner. Keep the `user === null` branch for the remaining failures, using new API-only reason constants `REASON_TN_USER_LOOKUP_FAILED` and `REASON_TN_USERNAME_CLASH`. Keep `REASON_UNKNOWN_USER` for a 404 or null username.
- Rewrite the `resolveUser` docblock and the comment at `:232-237`. Add a note by the reason constants that creating the user is a **deliberate divergence** from email-path case 2, which still drops. Parity comparisons will then show API-side Pending against email-side Dropped/unknown-user, and that is expected.

### 4. Fix `UserChangesSyncer`'s username handling (`UserChangesSyncer.php:110-131`)
- Today it compares `removeTNGroup($user->fullname)` with `$change['username']`, then sets `fullname` to the raw username. For any user whose `fullname` was prettified (every user `CreatePartnerUser` made, and now these too), every change event looks like a rename. `fullname` gets overwritten with `tricia.hayes`, and the `"{$oldname}-"` email replace never matches.
- Instead, derive the old username from the user's TN email: `tnUsernameFromEmail()` on the preferred address.
- On a real rename:
  - set `fullname = User::tnDisplayName($new)`;
  - **collapse the TN addresses to one bare `new@user.trashnothing.com`, dropping the `-gXXX` suffix.** This replaces the existing `"{$oldname}-"` → `"{$new}-"` rewrite, which kept the suffix:
    - Remove every TN address for the old username, both `old-gNNN@user.trashnothing.com` and bare `old@user.trashnothing.com`, with `removeEmail()`, emitting the `user-email-rename` Loki event once per removed address (`old_email` → `new_email`).
    - Add `User::tnEmailForUsername($new)` once with `addEmail()`. Make it `primary: 1` if any removed address was preferred, which is normally the case. It has to stay preferred, because `User::isTN()` reads the preferred address and `UserChangesSyncer` skips users for whom it returns false.
    - Dropping the suffixed aliases is safe for inbound mail. TN still sends from `new-gNNN@` aliases, and `findUserByEmail`'s canon fallback reduces those to `new@usertrashnothingcom`, the same canon as the bare address. The Go partner sync's `EnsurePartnerIdentifiers` may re-attach a `new-gNNN` alias later, and that is harmless.
    - If the bare `new@` address already belongs to a *different* user (`users_emails.email` is UNIQUE), leave this user's addresses untouched. Log `TN-SYNC-TRACE [NAME-CHANGE] … email-clash` and send the error to Sentry rather than throwing mid-change.
    - Emit `TN-SYNC-TRACE [WRITE]` lines and honour `dryRun` throughout, as the existing code does.
- If no TN email can be found, fall back to the current comparison.

### 5. Config, docs, tests
- **Config:** in `config/freegle.php`, under `trashnothing`, add `public_api_base_url` (default `https://trashnothing.com/api/v1.4`) and `user_lookup_miss_ttl`.
- **Docs:** update `docs/developers/reference/trashnothing.md`.
  - The section near line 184 ("poster is resolved through `users.tnuserid`… nothing is created") becomes the provisioning behaviour.
  - Update the User Changes API section to cover the rename fix.
  - Respect its `covers:` front matter so `check-docs-freshness` passes.
- **`plans/tn-api-post-ingestion.md` §F:** close the "missing user" open item.
- **Tests:**
  - `tests/Unit/Services/TrashNothing/TnUserProvisionerTest.php` (new), using `Http::fake` with **one** closure keyed on the request URL (see laravel-batch-traps: fakes merge, and the first stub wins). Cases:
    - existing `tnuserid` makes no HTTP call;
    - create from a full response checks `fullname` "Tricia Hayes", the email, `tnuserid` and `firstname` handling;
    - an existing account with a matching `-gNNN` alias and no `tnuserid` is linked, not duplicated;
    - a clash with a different `tnuserid` returns null;
    - a 404 or null username is cached negatively, so a second call makes no HTTP request;
    - a 500 is not cached;
    - dry run writes nothing;
    - a duplicate-key race returns the existing user;
    - the API key does not appear in logs.
  - `GroupPostIngestionServiceTest`: update the unknown-user case near `:981` so an unknown `tn_user_id` with a faked TN response now creates the user and the post goes **Pending, reason `unmapped user`**. Add cases for the lookup-failure and clash reasons.
  - `UserChangesSyncer` test: a prettified-`fullname` user with an unchanged username must not be renamed; a rename on a user holding `old-g123@` (preferred) and `old-g456@` ends with exactly one TN address, bare `new@user.trashnothing.com`, preferred, so `isTN()` stays true; a rename from a bare `old@` does the same; a non-TN address on the user is left alone; an email clash with another user leaves the addresses unchanged; dry run writes nothing.
  - `EmailApiParityTest` / `TnApiLokiParityTest`: if any fixture relies on the unknown-user drop, mark the divergence explicitly. Do not loosen the assertions.
  - A `User` helper unit test, with one Go/PHP parity example per transformation.

### 6. Remove the "every TN address has a `-gXXX` suffix" assumption across the codebase
After steps 2 and 4, TN addresses **without** a `-gXXX` suffix become normal: the provisioner creates bare `username@user.trashnothing.com`, and a rename collapses everything to that form. (Some legacy bare rows already exist; `FixTNNamesCommand.php:40` says "older rows sit directly on the bare domain".) This step needs extensive checks, rewrites and tests, because every piece of code that recognises or parses a TN address by its suffix will silently mishandle the bare form. None of it throws; it produces a plausible wrong answer.

**Replacement rule.**
- *"Is this a TN address?"* becomes a domain check: the address ends with `@user.trashnothing.com`, case-insensitive. Nothing else.
- *"Which TN username is this?"* uses one shared helper per stack: strip an **optional** `-g\d+` that sits immediately before the domain, and only there (`/^(.+?)(?:-g\d+)?@user\.trashnothing\.com$/i`). In PHP this is step 1's `User::tnUsernameFromEmail`; in Go, `TNAliasIdentity` with the suffix made optional. Never split on the first `-` or `-g`, because usernames can contain hyphens (`mary-jane`, `bibiana-gomes`).
- *"Find this member's other addresses"* must match both forms: `email = 'username@…' OR email LIKE 'username-g%@…'`, then exact-match the username through the helper. That exact-match guard already exists to stop the `bibiana-g%` → `bibiana-gomes-g4840` mis-merge.

**A. Email-format assumptions that break with bare addresses. Fix these and add bare-address tests for each.**

| Site | What it assumes | What goes wrong with a bare address |
|---|---|---|
| `iznik-batch/app/Models/User.php:472` `canonMail()` and `IncomingMailService.php:3800` `canonicalizeEmail()` | `/(.*)\-(.*)(@user.trashnothing.com)/` strips **anything** after the last hyphen | A bare `mary-jane@` canonicalises to `mary@…`, so the canon fallback in `findUserByEmail` can resolve one member's mail to a different member's account. Tighten both to strip only `-g\d+`. Check how many existing `canon` rows would change (`BackfillEmailCanonCommand` covers the rewrite), and keep the two PHP functions and Go's `CanonicalizePartnerEmail` byte-identical. |
| `iznik-server-go/user/partner.go:275` `tnAliasRegexp` / `TNAliasIdentity` | `^(.+)-g\d+@(.+)$` is mandatory | A bare address returns `ok=false`, so everything below falls back or does nothing. |
| `partner.go:384` `CanonicalizePartnerEmail` | built on `TNAliasIdentity` | A bare address falls through to the general Go `CanonicalizeEmail`: domain dots are kept and local dots stripped (`triciahayes@user.trashnothing.com`), so the PHP canon lookup can't find it. This reopens the duplicate-account hole described in `.claude/rules/mail-and-data.md`. |
| `partner.go:297-345` `FindTNSiblings` (LIKE `username-g%@` at `:316`) | siblings always carry `-g` | A bare address finds no siblings, and a bare sibling is never found from a suffixed one. The partner Promise 403 fix (TN post 47243586) regresses for these members. |
| `partner.go:157` `CreatePartnerUser` name extraction | `strings.Index(prefix, "-g")`, the **first** `-g` | Already wrong for `bibiana-gomes-g4840` (it gives "Bibiana"); a bare `mary-grace@` gives "Mary". Switch to the shared username helper, then apply `tnDisplayName`. |
| `iznik-batch/app/Console/Commands/TrashNothing/TNSyncCommand.php:406` `tnUsernameFromAddress` and the per-tick LIKE at `:512` | `-g\d+@` suffix; LIKE `username-g%@` | For a bare address the regex doesn't match, so the **whole address** becomes the "username", and a member's bare and suffixed twin accounts are never grouped or merged. The per-tick LIKE also misses bare rows. Rewrite both using the replacement rule. Keep the exact-username guard and the full-pass memory design (one integer per username). |
| `iznik-batch/app/Console/Commands/User/FixTNNamesCommand.php:61` | `/^(.*)-[^-]+@/`, a hyphen before `@` | A bare `tricia.hayes@` is skipped; a bare `mary-jane@` sets `fullname` to "mary". Use the shared helper, then `tnDisplayName`. |
| `iznik-batch/app/Services/TrashNothing/Sync/UserChangesSyncer.php:118-120` | `"{$oldname}-"` substring | Already covered by step 4. |
| `iznik-batch/app/Support/NameSanitiser.php:76` and `iznik-server-go/user/namevalidation.go:73` (`TN_EMAIL_SUFFIX` / `tnEmailSuffix`) | an email-shaped `fullname` from TN ends `-g\d+@user.trashnothing.com` | A `fullname` holding a bare TN address is no longer recognised as an import side effect and may be treated as suspicious. Change both to the domain check. They are deliberately twinned, so keep the PHP and Go versions in step. |

**B. Already domain-only. Verify these, with a bare-address test case where a test exists; no change expected.**
- `User::isTN()` (`User.php:434`)
- `ModMember.vue:454` (`isTN`)
- `DiscourseNotSignedUpService.php:115`
- `TNSyncCommand::whereTNAddress` (`:637-644`)
- `FixTNNamesCommand`'s row filter (`:43`)
- `IncomingMailService::findUserByEmail`'s exact-match arm (`:3771`)

**C. Display-name `-gXXX` stripping, not email parsing. Keep these.** They strip the suffix TN used to put in **names** (`Alice-g298`). A bare-address user's name has no suffix, so they simply do nothing. Re-read each during the audit to confirm it never receives an email address:
- `User::removeTNGroup` (`User.php:385`) and `getDisplayNameAttribute` (`:326`)
- `ListModsService.php:155`
- `PushNotificationService.php:1325`
- Go `utils.TN_REGEXP` / `tnOnlyRegexp` / `TidyName` (`utils.go:117`, `:379-441`)
- `chat/chatroom.go:1106`
- `message/message.go:49`

**Audit procedure.** The grep above was run on 2026-10-01. Re-run it on the implementation branch, because new sites may land meanwhile:
`grep -rnE -- '-g\[0-9\]|-g\\d|-g\(|"-g"|-g%|\\-\(\.\*\)\(@user|removeTNGroup|TNAliasIdentity|tnUsernameFromAddress|user\.trashnothing'` across `iznik-batch/app`, `iznik-server-go`, `iznik-nuxt3`, `status-nuxt`, `monitor-fsm` and `scripts`. Also check SQL in raw queries and migrations for `REGEXP_REPLACE`/`LIKE '%-g%'`. Classify every hit as A, B or C above. Anything in A gets a fix and a test.

**Tests.**
- About 10 test files currently build TN fixtures only in the `-gNNN` form (`grep -rlE -- '-g[0-9]+@user\.trashnothing\.com'` across `iznik-batch/tests`, `iznik-server-go`, `iznik-nuxt3/tests`). Every test covering an A-row site gets bare-address cases, including:
  - a hyphenated bare username (`mary-jane@`);
  - a dotted bare username (`tricia.hayes@`);
  - a bare and a suffixed address of the same member being treated as one;
  - two different members whose usernames share a prefix (`bibiana@` vs `bibiana-gomes-g4840@`) being kept apart.
- Add one cross-stack canon table (the same input → expected canon pairs, asserted in both a PHP and a Go test), so `canonMail`, `canonicalizeEmail` and `CanonicalizePartnerEmail` cannot drift apart again.
- Run the Go suite as well as the batch suites, since this step touches `iznik-server-go`. Per CLAUDE.md, update the CircleCI orb if test wiring changes.

**Docs and rules.**
- Update the "Email Canonicalization" and "Identifying the member behind an address" sections of `docs/developers/reference/trashnothing.md`.
- Add a trap to `.claude/rules/mail-and-data.md`: TN addresses are no longer always `-gNNN` aliases, so recognise them by domain and parse the username with the shared helper.

## Critical files
- `iznik-batch/app/Services/TrashNothing/Ingestion/GroupPostIngestionService.php`
- `iznik-batch/app/Services/TrashNothing/Ingestion/TnUserProvisioner.php` (new)
- `iznik-batch/app/Services/TrashNothing/Sync/PostSyncer.php` (construction only)
- `iznik-batch/app/Services/TrashNothing/Sync/UserChangesSyncer.php`
- `iznik-batch/app/Models/User.php`
- `iznik-batch/config/freegle.php`, `docs/developers/reference/trashnothing.md`
- Step 6 (suffix audit): `IncomingMailService.php` (`canonicalizeEmail`), `TNSyncCommand.php`, `FixTNNamesCommand.php`, `NameSanitiser.php`, `iznik-server-go/user/partner.go`, `iznik-server-go/user/namevalidation.go`, `.claude/rules/mail-and-data.md`

Reused as-is: `TrashNothingRateLimiter`, `PostSyncer::redactApiKey`, `User::addEmail`/`canonMail`/`removeTNGroup`, the `UserAboutMe`/`UserReplyTime` models, `LokiService::logEvent`.

## Verification
1. `docker exec freegle-batch php artisan test --filter="TnUserProvisioner|GroupPostIngestionService|UserChangesSyncer|EmailApiParity|TnApiLokiParity"`, then the full `--testsuite=Unit,Feature`.
2. `docker exec freegle-batch php artisan tn:sync --local-testing` with a fixture post whose `user_id` is unknown, plus `tests/fixtures/tn_sync/users/{id}.json`. Check the `TN-SYNC-TRACE` lines for the user and email inserts, and the message landing Pending/`unmapped user`.
3. Against the real API with the dev key: `tn:parity-check` (or `tn:sync --dry-run --local-testing` off) on a window containing an unknown poster. Confirm the lookup succeeds with `username` populated for the developer key.
4. Step 6: the Go suite for `iznik-server-go/user`, plus the full batch suites. Then re-run the audit grep and confirm that every remaining `-g` hit is a C-row (display-name) site.
5. ~~Rate-limit probe~~ Done 2026-10-01; see "Rate-limit probe results" above. Outcome: `await()` stays as is.
