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

### 1. Shared TN name and address helpers on `App\Models\User` — **DONE**
Added next to `removeTNGroup()` in `app/Models/User.php`:
- `tnDisplayName(string $username): string`. A PHP port of the Go `CreatePartnerUser` logic (`iznik-server-go/user/partner.go:152-164`): replace `.` and `_` with spaces, then title case. A comment cross-references the Go side.
  - **Not `ucwords`.** Go's `strings.Title` capitalises after any non-letter/digit/underscore, not just whitespace. Verified by running Go in `freegle-apiv2`: `mary-jane` → `Mary-Jane`, `o'brien` → `O'Brien`, `x2y.z` → `X2y Z`, `élise.dupont` → `Élise Dupont`, `ALREADY.up` → `ALREADY Up`. The PHP version uses `preg_replace_callback('/(?<![\p{L}\p{N}_])\p{Ll}/u', mb_strtoupper)` to match.
  - Known residual difference: Go treats non-ASCII non-letter symbols (e.g. `€`) as non-separators; the PHP regex treats them as separators. Accepted, since TN usernames are not expected to contain them.
- `tnEmailForUsername(string $username): string`. Returns `"{$username}@user.trashnothing.com"`.
- `tnUsernameFromEmail(string $email): ?string`. Already follows step 5's replacement rule: `/^(.+?)(?:-g\d+)?@user\.trashnothing\.com$/i`, stripping an optional `-g<digits>` only immediately before the domain. Returns null for non-TN addresses. Trims and **lowercases** the result, matching Go's `TNAliasIdentity`. (Step 4 compares usernames through this, so compare against a lowercased new username there.)
- Tests: five new cases in `tests/Unit/Models/UserModelTest.php` (Go-parity display names, bare/alias/mixed-case addresses, hyphenated usernames incl. `bibiana` vs `bibiana-gomes-g4840` and `ann-g12-g34`, non-TN rejections). `UserModelTest` passes (72 tests) via the status API.
- **Running tests:** a hook blocks `php artisan test` directly. Use the status API instead: `curl -s -X POST http://localhost:8081/api/tests/laravel -H 'Content-Type: application/json' -d '{"filter":"…","testsuite":"Unit"}'`, then poll `/api/tests/laravel/status`. The full Unit/Feature suites have not been run yet for this step; run them before pushing.

### 2. New `App\Services\TrashNothing\Ingestion\TnUserProvisioner` — **DONE**
- **Constructor:** `bool $dryRun`, `bool $localTesting`, `string $publicApiKey`, `LokiService $loki`, `?TrashNothingRateLimiter $rateLimiter`.
- **`resolveOrCreate(int $tnUserId): ?User`**
  1. Return the existing user from `User::where('tnuserid', …)`. This moves the lookup out of `GroupPostIngestionService::resolveUser`.
  2. Fetch the user. Call `rateLimiter->await()` first, then `Http::get("{host}/users/{id}", ['api_key' => …])` with Laravel's default timeout, as the other TN syncers use. The host is the generated client's, `Configuration::getDefaultConfiguration()->getHost()` (`https://trashnothing.com/api/v1.4`), so no new config key.
     - Use the plain `Http` client, as `UserChangesSyncer` does. The generated `UsersApi` has no `getUser` method.
     - Pass every logged error through `PostSyncer::redactApiKey()`.
     - With `localTesting`, read `tests/fixtures/tn_sync/users/{id}.json` instead.
  3. If the response is 404, `username` is empty, or the built address fails `filter_var(FILTER_VALIDATE_EMAIL)`, return null.
  4. **Look for an existing account without `tnuserid`.** Use the same order as `IncomingMailService::findUserByEmail` (`:3753`): match the exact address first, then a `users_emails` row whose `canon` equals `User::canonMail(tnEmailForUsername($username))`. That canon also matches the per-group `-gNNN` aliases the email path created. As `FindTNCandidates` does (`partner.go:58`), only consider users with `deleted IS NULL`.
     - If its user has `tnuserid IS NULL`, stamp the id on that user and return it. This mirrors `EnsurePartnerIdentifiers` (`partner.go:226`) and avoids minting a twin. Also attach the bare `username@user.trashnothing.com` address with `$user->addEmail($addr, primary: 0)`, so later exact-match lookups hit, as `handleSubscribe` does with `addEmailToUser` (`:1232`). This keeps the member's current preferred address.
     - If its user holds a *different* `tnuserid`, return null with a distinct reason, `tn-username-clash`, and log it. TN says usernames are not guaranteed unique.
  5. **Create the user** inside `DB::transaction`, writing every field in the "Field parity" table below. Use Eloquent (`User::create`, then `$user->addEmail(...)`) so model events and auditing fire. Every write emits a `TN-SYNC-TRACE [WRITE]` line and honours `dryRun`. In dry run, return an unsaved `User` so `ingest()`'s trace still runs through (`createMessage` already handles the dry-run id).
  6. **Race:** if the `tnuserid` unique index throws a duplicate-key error, re-query by `tnuserid` and return that user. `tn:sync` and the hourly `tn:verify-email-coverage` backfill can provision the same user at the same time.
  7. Emit the Loki event `tn-sync` / `user-create-from-tn` with `tn_user_id`, `user_id` and `linked_existing`.
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
- **Failure handling:** a 5xx, 429 or timeout returns null with reason `tn-user-lookup-failed`, and nothing is remembered. Before cutover, the post is dropped for this run, as it is today. After cutover, `tn:verify-email-coverage` finds it and backfills, and that backfill retries the lookup.
- **As implemented** (`app/Services/TrashNothing/Ingestion/TnUserProvisioner.php`):
  - **Failure reasons.** `resolveOrCreate()` returns `?User`; on null, `lastFailureReason()` gives one of `TnUserProvisioner::REASON_NOT_FOUND` (`tn-user-not-found`: 404, empty/null username, or an invalid built address), `REASON_LOOKUP_FAILED` (`tn-user-lookup-failed`) or `REASON_USERNAME_CLASH` (`tn-username-clash`). Step 3 maps these onto `GroupPostIngestionService`'s routing reasons (`REASON_NOT_FOUND` → `REASON_UNKNOWN_USER`).
  - **Lookup failures** are: a `ConnectionException` (timeout), any non-404 non-2xx status (5xx, 429, 401), a response carrying `cf-mitigated`, or a 2xx whose body is not a JSON object. Each is logged with the status, `Retry-After` and the first 200 chars of the body, all through `redactApiKey()`.
  - **No cache and no memo** (decided in review). Every call that misses on `tnuserid` asks TN again. A cross-run negative cache was dropped: the 10s sync overlap re-sees almost nothing, and the verifier's hourly retries are bounded. Consequence in **dry run only**: an unsaved user cannot be found again by `tnuserid`, so several posts by the same new poster in one dry run each re-fetch and each trace a `users op=insert`. A real run finds the user after the first post.
  - **Canon-match guard (addition to step 4).** A canon match is linked only if `User::tnUsernameFromEmail()` of the stored address equals the lowercased username. Today's `canonMail` strips everything after the last hyphen of a TN local part, so `mary-jane@` and another member's `mary-g12@` share a canon; without the guard they would be linked. Matches are ordered by `users_emails.userid`, then `id`.
  - **Linking is kept** (decided in review), rather than resolving by `tnuserid` only. Without it, an email-path account would get a twin, and if it already holds the bare address, creation would hit the `users_emails.email` UNIQUE index on every attempt. Linking does not overwrite `fullname`; in dry run it writes nothing and returns the existing user unchanged.
  - **Dry-run create** builds an unsaved `User` and traces the email insert itself rather than calling `addEmail(dryRun: true)`: `addEmail` passes `$this->id` to `assignUserToToDonation(int)`, which throws for an unsaved user.
  - **Race.** A 1062 from either the create or the link path re-queries by `tnuserid`. If no winner is found (for example the duplicate was on the email), it returns `REASON_LOOKUP_FAILED`, so the next attempt retries. Any other `QueryException` is rethrown.
  - **Trace lines.** `TN-SYNC-TRACE [TN-USER] tn_user_id=… result=…` (`not-found`, `no-usable-username`, `username-clash`, `created`, `linked`, `lost-race`) alongside the `[WRITE]` lines.
  - **`about_me`/`reply_time`** rows use `timestamp = now()` (the user-changes path uses the change date, which the users endpoint does not give). `reply_time = 0` is stored.
  - **Fixture:** `tests/fixtures/tn_sync/users/99010901.json` (`fixture.poster`) for the `localTesting` path. A missing fixture file is treated as a 404.
- **Tests:** `tests/Unit/Services/TrashNothing/TnUserProvisionerTest.php`, 21 tests, all passing via the status API. They cover the step 7 list, plus field parity, null first/last names, bare-address linking, the canon guard, deleted accounts not linked, an invalid built address, 429 and `cf-mitigated`, dry-run linking, a miss asked again later, and both `localTesting` paths. The race test creates the competing user from inside the `Http::fake` closure, so it really hits the `tnuserid` unique index. The full Unit/Feature suites have **not** been run for this step yet; run them before pushing.

### 3. Wire it into `GroupPostIngestionService` — **DONE**
- **As implemented:** constructor parameter `TnUserProvisioner $userProvisioner`; `resolveUser()` removed and replaced by `unresolvedUserReason()`, which maps `lastFailureReason()` onto the routing reason. `REASON_TN_USER_LOOKUP_FAILED`/`REASON_TN_USERNAME_CLASH` take their values from the provisioner's constants, so the strings cannot drift. The `[POST-SKIP]` trace keeps `reason=unknown-user` for a not-found (it matches the email path's trace) and uses the reason string for the two new failures; the `post-skip-unknown-user` Loki event gains a `reason` key. `PostSyncer` builds the provisioner with `$this->apiKey`, its shared rate limiter, `dryRun` and `localTesting` (not `fixtureDir`: the users fixture always comes from `tests/fixtures/tn_sync/users/`).
- **Tests:** `GroupPostIngestionServiceTest` now fakes `GET /users/{id}` with one closure keyed on the id (404 by default, so existing unknown-user cases still drop). New cases: a new poster is created and the post goes Pending with `reason=unmapped user` and no membership; dry run traces the user insert and writes nothing; a 503 gives `tn-user-lookup-failed`; an address held under another `tnuserid` gives `tn-username-clash`. No other test posts from an unknown user outside `localTesting`, so `EmailApiParityTest`/`TnApiLokiParityTest` needed no change. `PostSyncerTest`'s ingestion spy now passes a provisioner too.
- **Bug found in step 2 while wiring:** `TnUserProvisioner::create()` now sets `lastlocation => null` explicitly. Without it, the model from `User::create`/`new User` has no `lastlocation` key, so `ingest()`'s `$user->lastlocation` resolved the `lastLocation()` relation and threw.
- **Verified:** the filtered run (286 tests) and the full Unit/Feature suites (7076 tests) pass via the status API. `check-docs-freshness` still flags `trashnothing.md` because of step 2's `TnUserProvisioner.php`; that is left for step 7.
- Inject `TnUserProvisioner` through the constructor. `PostSyncer` builds it from `$this->apiKey` (the public key), its shared rate limiter, `dryRun` and `localTesting` (`PostSyncer.php:70`).
- Replace `resolveUser()` with the provisioner. Keep the `user === null` branch for the remaining failures, using new API-only reason constants `REASON_TN_USER_LOOKUP_FAILED` and `REASON_TN_USERNAME_CLASH` (map from `TnUserProvisioner::lastFailureReason()`). Keep `REASON_UNKNOWN_USER` for `TnUserProvisioner::REASON_NOT_FOUND` (a 404 or null username).
- Rewrite the `resolveUser` docblock and the comment at `:232-237`. Add a note by the reason constants that creating the user is a **deliberate divergence** from email-path case 2, which still drops. Parity comparisons will then show API-side Pending against email-side Dropped/unknown-user, and that is expected.

### 4. Fix `UserChangesSyncer`'s username handling (`UserChangesSyncer.php:110-131`) — **DONE**
- **As implemented:** the username logic moved to `UserChangesSyncer::applyUsername()`.
  - The old username is `tnUsernameFromEmail($user->email_preferred)`, compared with `strtolower($new)`, so a case-only change is not a rename. There is no `fullname` fallback: `sync()` already skips users whose preferred address is not TN.
  - On a rename, `fullname = tnDisplayName($new)`. The TN addresses whose `tnUsernameFromEmail()` equals the old username are collected. Other usernames' TN addresses (e.g. `bibiana-gomes-g4840@` when the old username is `bibiana`) and non-TN addresses are left alone.
  - The bare `new@` is added **before** the old addresses are removed, so the user always keeps a preferred TN address, even if a removal fails partway. It is `primary: 1` if any removed address was preferred.
  - **Email clash:** `fullname` is still updated (the TN rename is real), but the addresses are left untouched. A `[NAME-CHANGE] … email-clash=… held_by=…` trace line is logged and `\Sentry\captureMessage` is called.
- **Tests:**
  - New `tests/Unit/Services/TrashNothing/UserChangesSyncerTest.php`, 7 tests: the unchanged prettified name, a case-only change, collapsing two aliases with one Loki event each, a rename from a bare address, other usernames' and non-TN addresses left alone, the clash, and dry run. All 7 failed before the fix.
  - `TNSyncCommandTest`: `createTNUser()` takes an optional TN username. Four name-change tests (`updates_fullname`, `updates_tn_emails`, `skips_name_change_when_unchanged`, `test_loki_logs_user_email_rename`) were rewritten for the new behaviour, because they asserted the old raw-username `fullname` and the suffix-preserving rewrite.
- **Verified:** the filtered run (250 tests) and the full Unit/Feature/Integration suites (7083 tests) pass via the status API. Docs (`trashnothing.md` User Changes section) are left for step 7.
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

### 5. Remove the "every TN address has a `-gXXX` suffix" assumption across the codebase — **IMPLEMENTED, awaiting review**
- **As implemented:**
  - **Canon.** `User::canonMail` strips only `/^(.+)-g\d+(@user\.trashnothing\.com)$/i`. `IncomingMailService::canonicalizeEmail` now just calls `User::canonMail`, so the two PHP copies cannot drift. Go needed no canon change beyond `TNAliasIdentity`. Only rows whose TN local part has a hyphen but **no** `-g<digits>` suffix get a different canon (plus mixed-case domains, which `users:backfill-email-canon` already lowercases). `users:backfill-email-canon` recomputes `canonMail(strtolower(email))` and covers the rewrite unchanged; run its dry run on production to get the count.
  - **Go.** `tnAliasRegexp` is `^(.+?)(-g\d+)?@(.+)$`; `TNAliasIdentity` accepts a bare address **only on `user.trashnothing.com`** (a bare address on another partner domain is not an identity, which keeps `FindTNSiblings(plain@test.com)` empty). `FindTNSiblings` matches `email = bare OR email LIKE 'username-g%@domain'`, keeping the exact-identity guard. `CreatePartnerUser` uses a new `partnerDisplayName()` (case-preserving, strips only a trailing `-g\d+`).
  - **`TNSyncCommand`.** `tnUsernameFromAddress()` delegates to `User::tnUsernameFromEmail` (returns `?string`; non-TN rows are skipped). The per-tick probe matches the bare address too. Side effect: usernames are now compared **lowercased** in both passes, as Go does, so `Bibiana-g1@` and `bibiana-g2@` group together (the per-tick pass previously skipped that pair, the full pass kept them apart).
  - **`FixTNNamesCommand`.** Name is `tnDisplayName(tnUsernameFromEmail(email))`, so it is now title-cased (`canonform` → `Canonform`; the existing test's assertion was changed accordingly). Addresses not on `user.trashnothing.com` are skipped. A name already equal to the computed one is skipped rather than rewritten, because hyphenated names keep matching the `fullname LIKE '%-%'` filter. The test fixture moved from the non-existent `name-12345@trashnothing.com` shape to `name-g12345@user.trashnothing.com`.
  - **`NameSanitiser::TN_EMAIL_SUFFIX` / Go `tnEmailSuffix`** are now the domain check. Both are **unreachable**: `isSuspicious` returns early on any `@` first. Candidates for removal in both stacks.
  - **Audit (re-run 2026-10-07):** no A-row sites beyond the table. Extra domain-only (B) sites: Go `user/user.go:3841`, `membership/membership.go:94`. C-row `TidyName` and `ListModsService` do receive email local parts, but only strip a trailing `-g\d+`, which matches the replacement rule.
  - **B-row tests** already used bare addresses (`UserModelTest::test_is_tn_returns_true_for_trashnothing_user`, `DiscourseNotSignedUpServiceTest`, `ModMember.spec.js`); none added.
- **Second-pass inventory (2026-10-07).** Wider grep than the audit procedure below: it also catches comments and prose (`-gNNNN`, `-gXXX`, "per-group alias", "group suffix"), escaped forms (`\-g`, `g\d+@`), `strpos`/`indexOf`/`explode` on hyphens, raw SQL, the nuxt client and the housekeeper's job descriptions. Every hit, classified:

  | Site | What it does with the suffix | Class | Status |
  |---|---|---|---|
  | `User.php` `tnUsernameFromEmail` (`/^(.+?)(?:-g\d+)?@user\.trashnothing\.com$/i`) | optional suffix | shared PHP helper | already right (step 1) |
  | `User.php` `canonMail` | strips only `-g\d+` before the TN domain | A | fixed |
  | `IncomingMailService::canonicalizeEmail` | delegates to `canonMail` | A | fixed |
  | `IncomingMailService.php` `handleSubscribe` comments (the "second per-group address" / "another per-group alias" notes) | describe aliases only | comment | updated in this pass to include the bare form |
  | `TnUserProvisioner::findExistingAccount` docblock, and its test `test_canon_match_with_a_different_username_is_not_linked` | say canonMail "strips everything after the LAST hyphen" | comment/test | outdated; updated in this pass. The guard stays: rows written before step 5 keep the old canon until `users:backfill-email-canon` rewrites them. A stale-canon test was added |
  | `TNSyncCommand::tnUsernameFromAddress`, per-tick `LIKE 'username-g%@'` | now via `tnUsernameFromEmail`; probe also matches `email = 'username@…'` | A | fixed |
  | `TNSyncCommand.php` backwards-format comment (`canon strips the -gNNNN suffix`) | describes canon | comment | still accurate |
  | `FixTNNamesCommand` name extraction | via `tnUsernameFromEmail` + `tnDisplayName` | A | fixed |
  | `FixTNNamesCommand` row-filter comment ("older rows sit directly on the bare domain") | refers to `@trashnothing.com` (no `user.`), which the `%@%trashnothing.com` filter matches | comment | **open question**, see below |
  | `NameSanitiser::TN_EMAIL_SUFFIX`, Go `tnEmailSuffix` | domain check | A | fixed; unreachable behind the `@` early return |
  | `UserEmail.php:60`, `BackfillEmailCanonCommand.php:14` | "canonMail drops the -gNNNN suffix" | comment | still accurate |
  | `UserChangesSyncer.php:170` | collapse aliases to bare | step 4 | accurate |
  | Go `partner.go` `tnAliasRegexp`/`TNAliasIdentity`, `FindTNSiblings` LIKE, `CanonicalizePartnerEmail`, `partnerDisplayName` | optional suffix; bare only on TN domain | A | fixed |
  | Go `message/message.go` comment above `WithTNSiblings` in `resolvePartnerAuth` ("a DIFFERENT per-group alias") | describes aliases only | comment | updated in this pass |
  | Go `housekeeper/housekeeper.go:377` `users:fix-tn-names` description (`firstname-groupid@trashnothing.com`) | wrong shape and domain | comment/UI text | updated in this pass |
  | Go `user/user.go:3841`, `membership/membership.go:94` (`LIKE '%@user.trashnothing.com'`), `chat/chatmessage.go:2151` (`freegleDomains` contains `trashnothing`) | domain only | B | no change |
  | nuxt `ModMember.vue:454`, `MessageHistory.vue:249`, `ModMergeMemberModal.vue:131-132`, `ModStdMessageModal.vue:572` | `includes('trashnothing…')` | B | no change |
  | `User::removeTNGroup`/`getDisplayNameAttribute`, `ListModsService.php:130-155`, `PushNotificationService.php:1374`, Go `utils.TN_REGEXP`/`tnRegexp`/`tnOnlyRegexp`/`TidyName`, `chat/chatroom.go:1120`, `message/message.go:50,694` | strip a trailing `-g\d+` from a **name** | C | no change. `TidyName` (via `InventName`, `user.go:491`) and `ListModsService` do receive an email local part, but they strip only a trailing `-g\d+`, which is the replacement rule, so a bare username passes through intact |
  | `NewsfeedDigestService.php:203,260` ("group suffix" of a location name), nuxt `ChatListEntry.vue:66` (` (Group)` in a chat name), `IncomingMailService.php:1648` (`explode('-')` on `replyto-…`) | not TN addresses | n/a | unrelated |
  | Tests: Go `utils*_test.go` TidyName cases, `partner_test.go` alias comments, nuxt "group suffix" specs | C-row or accurate | — | no change |
  | Docs: `docs/developers/reference/trashnothing.md:39-44, 61, 68-77, 87, 402-404, 568, 826`; rules: `.claude/rules/mail-and-data.md:180, 240` | describe `-g{groupid}` as universal, `tnUsernameFromAddress()`'s mandatory suffix, PHP vs Go canon table | docs | **step 7** (deliberately not touched here) |

  **Open question (FixTNNamesCommand).** Its filter is `email LIKE '%@%trashnothing.com'`, and the original comment says some older rows sit on the bare `@trashnothing.com` domain (the old test fixture used `name-12345@trashnothing.com`). `tnUsernameFromEmail` only accepts `@user.trashnothing.com`, so those rows are now skipped instead of getting a name. Every other TN check in the codebase (`isTN`, `TNAliasIdentity`, the partner domain) also ignores `@trashnothing.com`. Count them on production before deciding whether the command should still name them:
  `SELECT COUNT(*) FROM users_emails WHERE email LIKE '%@trashnothing.com'`.

- **Tests:** cross-stack canon table in `UserEmailTest::tnCanonTable` (also driven through `IncomingMailServiceTest` via `DataProviderExternal`) and `iznik-server-go/user/partner_canon_test.go`; `findUserByEmail` bare/alias and prefix-sharing cases; `TNSyncCommandTest` incremental bare↔alias merge, prefix-sharing kept apart, full-pass grouping; `FixTNNamesCommandTest` bare, hyphenated, already-correct and non-member cases; `partner_test.go` bare sibling lookup, hyphenated display names, bare canon; `NameSanitiserTest`/`namevalidation_test.go` bare inputs.

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

**Docs and rules.** See step 7: `trashnothing.md` sections, its `covers:` front matter, and the `mail-and-data.md` trap. Step 7 also maps each A-row site to its test file.

### 6. Move every TN user to a bare address (after step 5)
**Goal:** every TN member ends up with one preferred bare `username@user.trashnothing.com` address and no `-gNNN` aliases. This depends on step 5, so do not start before it has landed. Until then, bare addresses mis-canonicalise (`mary-jane@` → `mary@…`) and are invisible to Go's `TNAliasIdentity`/`FindTNSiblings` and to `TNSyncCommand`'s grouping.

- **Normalise on every change event.** In `UserChangesSyncer::applyUsername()`, the early return for an unchanged username skips the email collapse. That return should skip only the `fullname` update; the collapse then runs whenever the user still holds a `-gNNN` alias or lacks the bare address. Pull the collapse (clash check, add before remove, one `user-email-rename` event per removed address) into a method that both paths call.
  - Reverse the two tests that currently assert the alias survives an unchanged username: `test_prettified_fullname_with_unchanged_username_is_not_renamed` (`UserChangesSyncerTest`) and `test_sync_skips_name_change_when_unchanged` (`TNSyncCommandTest`). They should still assert that `fullname` is left alone.
  - Add a test that a user already on the bare address with no aliases gets no writes and no Loki events.
  - Expect a slow but large migration: change events carry `username` even when only `about_me`, `reply_time` or the location changed.
- **Backfill members with no change events.** Add an artisan command that applies the same collapse, keyed by the shared username helper. It needs `--dry-run` and a batch limit, and must log clashes (see step 4) rather than throw. Members who never generate a change event would otherwise keep their aliases forever.
- **The Go partner sync re-adds aliases.** `EnsurePartnerIdentifiers` (`partner.go:226`) may attach a `-gNNN` alias again. It does not change the preferred address, so this is harmless for `isTN()`. To reach "no aliases at all", that function has to stop adding them, or add the bare form instead.
- **Before the backfill,** count the TN users still holding a `-gNNN` alias and the bare addresses that would clash, so the size of the move is known.

### 7. Config, docs, tests
Docs and tests for every step land here, so `check-docs-freshness` and the suites are satisfied once, against the finished behaviour.

- **Config:**
  - Steps 1–4: none. The host comes from the generated client (step 2) and the timeout is Laravel's default.
  - Steps 5–6: no new env vars or flags expected. If step 6's backfill command is scheduled rather than run by hand, add it to `iznik-batch/routes/console.php` next to `users:fix-tn-names` (`:1338`). `FREEGLE_TN_MERGE_LEGACY_DUPLICATES` keeps its meaning; the step 5 changes to `TNSyncCommand`'s grouping apply under both settings.
  - If any test wiring changes (for example a new Go test package for the cross-stack canon table), update and republish the CircleCI orb, per CLAUDE.md.
- **Docs: `docs/developers/reference/trashnothing.md`.**
  - **Front matter `covers:`.** It already covers `IncomingMailService.php`, `Commands/TrashNothing/**`, `Ingestion/**`, `Sync/**` and `partner.go`, so steps 2–6 will trip the freshness check on it. Add the step 5 files it does not yet cover: `iznik-batch/app/Models/User.php`, `iznik-batch/app/Console/Commands/User/FixTNNamesCommand.php`, `iznik-batch/app/Support/NameSanitiser.php` and `iznik-server-go/user/namevalidation.go`. Add the two halves of the cross-stack canon table test under the "cross-stack behaviour tests" comment.
  - **Post Ingestion via API** (near line 184, "poster is resolved through `users.tnuserid`… nothing is created"): describe provisioning, with the three failure reasons and the Pending/`unmapped user` routing.
  - **User Changes API** (near line 441): cover the rename fix (step 4) and the collapse to one bare address on every change event (step 6).
  - **Email-Based Detection** (near line 32): the `{username}-g{groupid}@` format is no longer universal. Say that addresses are recognised by domain, and that a member may hold a bare `username@` address, the `-gNNN` form, or both while step 6 runs.
  - **Email Canonicalization** (near line 57): only a `-g<digits>` immediately before the domain is stripped. Give `mary-jane@` as the example that used to collapse to `mary@`.
  - **Identifying the member behind an address** (near line 64): the sibling probe matches both `username@` and `username-g%@`, and the username comes from the shared helper rather than `tnUsernameFromAddress()`'s mandatory suffix.
  - **Group Membership (Subscribe Mail)** (near line 400): "every `-gNNNN` alias canonicalises to one value" also holds for the bare address now.
  - **Key functions** (near line 127): add `User::tnUsernameFromEmail`, the other step 1 helpers and `TnUserProvisioner::resolveOrCreate`.
  - **Areas for Possible Improvement → 7. Duplicate User Detection** (near line 798): update the description of `EnsurePartnerIdentifiers` to match whatever step 6 decides (stops adding aliases, or adds the bare form).
  - **Maintenance:** document step 6's backfill command: its `--dry-run`, batch limit and clash logging, and the pre-backfill count query.
- **Rules: `.claude/rules/mail-and-data.md`.**
  - Add the trap: TN addresses are no longer always `-gNNN` aliases. Recognise them by domain and parse the username with the shared helper. Never split on the first `-` or `-g`.
  - **"Go's CanonicalizeEmail is not PHP's canonicalizeEmail"**: update the "TN `-gNNNN` suffix" row. After step 5, PHP strips only `-g\d+` and Go's `CanonicalizePartnerEmail` handles the bare form. Point at the cross-stack canon table test as the guard against drift.
  - **"`users_emails.backwards` is REVERSE(canon)"**: check that the worked example and the `users:fix-tn-names` note still hold after `FixTNNamesCommand` changes.
  - **Front matter `paths:`**: add `iznik-batch/app/Models/User.php`, `iznik-batch/app/Support/NameSanitiser.php`, `iznik-server-go/user/namevalidation.go` and `iznik-batch/app/Console/Commands/User/FixTNNamesCommand.php`, so the trap loads where the parsing lives.
- **`plans/tn-api-post-ingestion.md` §F:** close the "missing user" open item.
- **Tests, steps 1–4:**
  - **Done in step 2.** `tests/Unit/Services/TrashNothing/TnUserProvisionerTest.php` (new), using `Http::fake` with **one** closure keyed on the request URL (see laravel-batch-traps: fakes merge, and the first stub wins). Cases:
    - existing `tnuserid` makes no HTTP call;
    - create from a full response checks `fullname` "Tricia Hayes", the email, `tnuserid` and `firstname` handling;
    - an existing account with a matching `-gNNN` alias and no `tnuserid` is linked, not duplicated;
    - a clash with a different `tnuserid` returns null;
    - a 404 or null username returns null with the not-found reason, and a later call asks TN again;
    - a 500 returns null with `tn-user-lookup-failed`;
    - dry run writes nothing;
    - a duplicate-key race returns the existing user;
    - the API key does not appear in logs.
  - `GroupPostIngestionServiceTest`: update the unknown-user case near `:981` so an unknown `tn_user_id` with a faked TN response now creates the user and the post goes **Pending, reason `unmapped user`**. Add cases for the lookup-failure and clash reasons.
  - `UserChangesSyncer` test: a prettified-`fullname` user with an unchanged username must not be renamed; a rename on a user holding `old-g123@` (preferred) and `old-g456@` ends with exactly one TN address, bare `new@user.trashnothing.com`, preferred, so `isTN()` stays true; a rename from a bare `old@` does the same; a non-TN address on the user is left alone; an email clash with another user leaves the addresses unchanged; dry run writes nothing. (Step 6 changes the unchanged-username case: `fullname` is still left alone, but the aliases now collapse.)
  - `EmailApiParityTest` / `TnApiLokiParityTest`: if any fixture relies on the unknown-user drop, mark the divergence explicitly. Do not loosen the assertions.
  - A `User` helper unit test, with one Go/PHP parity example per transformation.
- **Tests, step 5.** Each A-row site has an existing test file. Add the bare-address cases listed in step 5 to each:

  | Site | Test file |
  |---|---|
  | `User::canonMail`, `tnUsernameFromEmail` | `iznik-batch/tests/Unit/Models/UserModelTest.php`, `tests/Unit/Models/UserEmailTest.php` |
  | `IncomingMailService::canonicalizeEmail`, `findUserByEmail` canon fallback | `iznik-batch/tests/Unit/Services/Mail/Incoming/IncomingMailServiceTest.php` |
  | canon rewrite of existing rows | `iznik-batch/tests/Unit/Commands/User/BackfillEmailCanonCommandTest.php` |
  | `TNAliasIdentity`, `CanonicalizePartnerEmail`, `FindTNSiblings`, `CreatePartnerUser` | `iznik-server-go/test/partner_test.go`; check `test/user_tn_privacy_test.go` still passes with a bare-address member |
  | `TNSyncCommand::tnUsernameFromAddress` and the per-tick LIKE | `iznik-batch/tests/Feature/TrashNothing/TNSyncCommandTest.php` |
  | `FixTNNamesCommand` | `iznik-batch/tests/Feature/User/FixTNNamesCommandTest.php` |
  | `NameSanitiser::TN_EMAIL_SUFFIX` / Go `tnEmailSuffix` | `iznik-batch/tests/Unit/Support/NameSanitiserTest.php`, `iznik-server-go/user/namevalidation_test.go` (the same inputs in both) |

  - B rows (already domain-only): add a bare-address case to `UserModelTest` (`isTN`), `iznik-nuxt3/tests/unit/components/modtools/ModMember.spec.js` and `DiscourseNotSignedUpServiceTest.php`.
  - C rows (display-name stripping): `PushNotificationServiceTest.php` and the Go `TidyName` tests should still pass unchanged. A failure there means a C site was receiving an address after all.
  - The cross-stack canon table: one PHP test and one Go test asserting the same input → canon pairs across `canonMail`, `canonicalizeEmail` and `CanonicalizePartnerEmail`.
- **Tests, step 6:**
  - Reverse `test_prettified_fullname_with_unchanged_username_is_not_renamed` (`UserChangesSyncerTest`) and `test_sync_skips_name_change_when_unchanged` (`TNSyncCommandTest`), as step 6 describes.
  - A test that a user already on the bare address with no aliases gets no writes and no Loki events.
  - A new test file for the backfill command: dry run writes nothing; the batch limit is honoured; a clash is logged and skipped, not thrown; a hyphenated username (`mary-jane-g12@` → `mary-jane@`) and a prefix-sharing pair (`bibiana-g1@` vs `bibiana-gomes-g4840@`) are handled correctly.
  - If `EnsurePartnerIdentifiers` changes, cover it in `iznik-server-go/test/partner_test.go`: a partner action for a member already on the bare address adds no `-gNNN` alias (or adds the bare form, whichever step 6 settles on).
- **Run everything:** the Go suite as well as the batch Unit/Feature/Integration suites and the nuxt unit tests (for `ModMember.spec.js`), then `node scripts/check-docs-freshness.mjs` (diffs against `origin/master` by default).

## Critical files
- `iznik-batch/app/Services/TrashNothing/Ingestion/GroupPostIngestionService.php`
- `iznik-batch/app/Services/TrashNothing/Ingestion/TnUserProvisioner.php` (new)
- `iznik-batch/app/Services/TrashNothing/Sync/PostSyncer.php` (construction only)
- `iznik-batch/app/Services/TrashNothing/Sync/UserChangesSyncer.php`
- `iznik-batch/app/Models/User.php`
- `docs/developers/reference/trashnothing.md`
- Step 5 (suffix audit): `IncomingMailService.php` (`canonicalizeEmail`), `TNSyncCommand.php`, `FixTNNamesCommand.php`, `NameSanitiser.php`, `iznik-server-go/user/partner.go`, `iznik-server-go/user/namevalidation.go`, `.claude/rules/mail-and-data.md`

Reused as-is: `TrashNothingRateLimiter`, `PostSyncer::redactApiKey`, `User::addEmail`/`canonMail`/`removeTNGroup`, the `UserAboutMe`/`UserReplyTime` models, `LokiService::logEvent`.

## Verification
1. Through the status API (direct `php artisan test` is blocked by a hook): POST `/api/tests/laravel` with `{"filter":"UserModelTest|TnUserProvisioner|GroupPostIngestionService|UserChangesSyncer|EmailApiParity|TnApiLokiParity","testsuite":"Unit,Feature"}`, then with an empty body for the full suites.
2. `docker exec freegle-batch php artisan tn:sync --local-testing` with a fixture post whose `user_id` is unknown, plus `tests/fixtures/tn_sync/users/{id}.json`. Check the `TN-SYNC-TRACE` lines for the user and email inserts, and the message landing Pending/`unmapped user`.
3. Against the real API with the dev key: `tn:parity-check` (or `tn:sync --dry-run --local-testing` off) on a window containing an unknown poster. Confirm the lookup succeeds with `username` populated for the developer key.
4. Step 5: the Go suite for `iznik-server-go/user`, plus the full batch suites. Then re-run the audit grep and confirm that every remaining `-g` hit is a C-row (display-name) site.
5. ~~Rate-limit probe~~ Done 2026-10-01; see "Rate-limit probe results" above. Outcome: `await()` stays as is.
