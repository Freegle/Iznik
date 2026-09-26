# CookieYes watchdog: replace the housekeeper extension's CookieYes task

## Why

The freegle-housekeeper Chrome extension's CookieYes task logs into app.cookieyes.com with a
password and TOTP, opens Cookie Manager > Uncategorized and leaves the tab open for a person to
categorise the cookies by hand.

Two things changed in September 2026:

- **CookieYes's AI Cookie Classifier** is now enabled on Freegle's account with "publish all
  AI-classified cookies automatically", so new cookies found by a scan are categorised with no
  manual review. The manual step the extension existed for is gone.
- **CookieYes published an MCP server** (`https://app.cookieyes.com/mcp`, OAuth 2.1 + PKCE).
  Its ten tools can read domains, banner status, scan results and compliance settings, and
  trigger a scan. None can change a cookie's category (confirmed against CookieYes's MCP
  documentation, 26 Sep 2026), which is why the classifier matters.

What is left is a watchdog: keep scans fresh and notice if something goes wrong. That is
deterministic, so it is plain PHP in iznik-batch, talking to the MCP server directly. No Claude,
no browser, no human in the loop once authorised.

## Behaviour

`php artisan cookieyes:check`, weekly (Monday 10:30 UTC, clear of the backup drain window).
For every domain in the CookieYes account:

1. Read the latest scan results and the banner status.
2. If the latest scan is older than 30 days, trigger a new one. This stays within the plan's
   per-domain monthly scan allowance, and does nothing when CookieYes's own scheduled scans are
   already keeping it fresh.
3. **Pass** = banner live, no uncategorised cookies, latest scan under 45 days old. Anything
   else is a **fail** with a one-line reason.

Results are written to the `housekeeper_tasks` row `cookieyes` (enabled, not a placeholder,
`interval_hours` 192), so the existing ModTools admin housekeeping badge fires on a failure and
also when the job stops running. On a failure it also emails `freegle.mail.geeks_addr` with the
existing (currently unsent) `HousekeeperResultsMail`. An authorisation failure says to re-run
`cookieyes:authorize`.

The exact checks in step 3 depend on what `get_scan_results` actually returns, which cannot be
seen without a token. Task 4 below records real responses first and finalises the checks
against them.

## OAuth

The authorisation server (`/.well-known/oauth-authorization-server`) supports dynamic client
registration, public clients (`token_endpoint_auth_method: none`), S256 PKCE, the
`refresh_token` grant and the `offline_access` scope. It looks like Ory Hydra, which **rotates
refresh tokens on every use** and by default expires one unused for 30 days. So:

- The new refresh token is saved immediately after each refresh, before any MCP call.
- Refresh runs under a cache lock so two runs can never spend the same refresh token (Hydra
  treats reuse as theft and revokes the chain).
- Weekly runs keep the token well inside its lifetime.

One-time login, non-interactive so it works over `docker exec` without a TTY:

1. `cookieyes:authorize` registers a client (first time only), generates the PKCE verifier and
   state, stores them as a pending login (expires after 15 minutes) and prints the login URL.
   The redirect URI is `http://localhost:8765/callback`; nothing listens there.
2. The account owner opens the URL, logs in and clicks Allow. The browser lands on a page that
   fails to load; its address contains the code.
3. `cookieyes:authorize --callback="<that address>"` checks the state, exchanges the code and
   stores the tokens.

Everything is stored in the existing `config` key/value table, encrypted with
`Crypt::encryptString` (APP_KEY), under `cookieyes.oauth` (client id, refresh token, cached
access token and expiry) and `cookieyes.oauth_pending`.

Prerequisite already done on the CookieYes side: profile menu > MCP access > "Enable
OAuth-based access".

## Components (iznik-batch)

| Unit | Responsibility |
|------|----------------|
| `App\Services\CookieYes\CookieYesTokenStore` | Read/write the encrypted `config` rows. |
| `App\Services\CookieYes\CookieYesOAuth` | Client registration, login URL, code exchange, refresh with rotation under a lock. Throws `CookieYesAuthException` when a new login is needed. |
| `App\Services\CookieYes\CookieYesMcpClient` | Streamable-HTTP JSON-RPC: `initialize`, `notifications/initialized`, `tools/list`, `tools/call`. Handles JSON and SSE responses and `Mcp-Session-Id`. Knows nothing about checks. |
| `App\Services\CookieYes\CookieYesWatchdogService` | Steps 1-3 above; returns a result (status, summary, per-domain log lines). |
| `cookieyes:authorize` | The one-time login above. |
| `cookieyes:call {tool} {--args=}` | Ops/debug: call any tool (or `tools/list`) and print the raw JSON. Used to record fixtures and to diagnose. |
| `cookieyes:check` | Runs the watchdog, records the housekeeper row, emails on failure. |

`HousekeeperService` gains a public `recordRun()` (the `housekeeper_tasks` upsert it already
does inline), used by both the extension path and the watchdog.

## Extension (separate repo, ~/freegle-housekeeper)

Remove the CookieYes task entirely: `TASK_REGISTRY` entry, `tasks/cookieyes-management.js`,
the manifest host permission and content script, the popup entries and the README row. Once the
extension stops sending it in its registry, `cookieyes:check` owns the `cookieyes` row.

## Out of scope

Banner restyling, any use of Claude, categorising cookies (the classifier does it).

## Tasks

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | `CookieYesTokenStore` + `CookieYesOAuth`, test-first with `Http::fake` | ⬜ | register, auth URL, exchange, refresh, rotation, revoked token |
| 2 | `CookieYesMcpClient`, test-first | ⬜ | JSON + SSE bodies, session id, JSON-RPC error, 401 |
| 3 | `cookieyes:authorize` + `cookieyes:call` | ⬜ | |
| 4 | Trial run on dev: authorise, record real responses as fixtures | ⬜ | needs the account owner to log in once |
| 5 | `CookieYesWatchdogService` checks against the fixtures | ⬜ | finalise pass/fail rules |
| 6 | `cookieyes:check`, `HousekeeperService::recordRun`, schedule, failure email | ⬜ | |
| 7 | Extension: remove the CookieYes task | ⬜ | separate repo/PR |
| 8 | Docs: developer reference page + ops note for the one-time authorise | ⬜ | |
| 9 | Full Laravel suite, review, PR | ⬜ | |
