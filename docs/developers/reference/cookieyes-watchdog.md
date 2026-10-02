---
last_reviewed: 2026-09-27
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/CookieYes/**
  - iznik-batch/app/Console/Commands/CookieYes/**
---

# CookieYes watchdog

CookieYes runs Freegle's cookie consent banner. Once a week, `php artisan cookieyes:check`
checks that it is still doing its job, with nobody involved:

- the banner is live on every website in the CookieYes account (not switched off, and not
  disabled by the plan's pageview limit);
- GDPR is one of the laws the banner applies;
- every cookie the banner publishes to visitors is in a category, and it publishes at
  least one;
- the last scan is under 45 days old. Once it is over 30 days old, the watchdog starts a
  new one at the plan's full page limit.

"Publishes" is the operative word. The check reads the banner script CookieYes serves from
its CDN (the `src` in the site's embed code), which carries the categories and cookies
visitors are shown. It does not judge categorisation on the scan results: those record what
the scanner found and how it categorised it at the time, before the classifier ran, so they
go on listing cookies as uncategorised after the classifier has placed and published them.
The scan results are still written to the log for the record.

The result goes into `housekeeper_tasks` as the task `cookieyes`, which drives the
housekeeping badge on the ModTools Sysadmin page. That badge also shows the task as overdue
if a week and a day pass without a run. A failure is emailed to Geeks as well.

## Why nobody has to categorise cookies

Until September 2026 this was a task in the freegle-housekeeper Chrome extension. The task
logged in to CookieYes and opened the list of uncategorised cookies for someone to sort out
by hand. CookieYes's **AI Cookie Classifier** now does that sorting during each scan. It is
set in Cookie Manager to "publish all AI-classified cookies automatically", which needs the
Basic plan or above. So an uncategorised cookie now means the classifier could not place
it, and a person has to look. That is why it fails the check.

The classifier works on the cookies a scan finds, a few minutes after the scan completes,
and publishes straight to the banner. A cookie it cannot place stays in the banner's
"Uncategorized" group until someone categorises it by hand.

## How it talks to CookieYes

CookieYes offers no API key. The watchdog uses CookieYes's MCP server
(`https://app.cookieyes.com/mcp`), which is meant for AI assistants but is just JSON-RPC
over HTTP. It is called here with no AI involved, so nothing in this code depends on
Claude.

| Class | Job |
|---|---|
| `CookieYesOAuth` | OAuth 2.1 + PKCE login as a public client, and keeping the login alive |
| `CookieYesTokenStore` | Keeps the login in the `config` table, encrypted with `APP_KEY` |
| `CookieYesMcpClient` | Calls a tool and unwraps the answer; knows nothing about the checks |
| `CookieYesPublishedBanner` | Fetches the banner script from the CDN and reads the published categories out of it |
| `CookieYesWatchdogService` | The checks above |

The MCP server's tools can read the account and start a scan, but **none of them can
change a cookie's category**. The only write tools are banner colours, banner layout and
starting a scan.

To see what a tool returns, or to diagnose a failure:

```bash
php artisan cookieyes:call tools/list
php artisan cookieyes:call get_scan_results --args='{"websiteId":"<id from list_domains>"}'
```

The test fixtures in `iznik-batch/tests/fixtures/cookieyes/` are real answers, recorded
this way. `script.js` there is the category part of the real banner script, fetched from
the address in `get_embed_code.json`.

## The login, and keeping it

A person logs in once per environment, as described below. After that, the refresh token
keeps the login alive. CookieYes rotates the refresh token on every use and treats reuse
of an old one as theft, revoking the login. So:

- the new refresh token is saved before anything else happens;
- refreshing happens under a cache lock, so two runs can never spend the same token;
- a login cannot be copied between environments. Each environment logs in for itself.

An unused refresh token eventually expires (30 days is the usual default for CookieYes's
login server). The weekly run keeps it fresh.

### Logging in (once per environment, or when the check says the login is lost)

The CookieYes account owner must first have switched on **profile menu > MCP access >
Enable OAuth-based access**.

1. On the batch container, run `php artisan cookieyes:authorize`. It prints a link.
2. Open the link while logged in to CookieYes as the account owner, and click **Allow**.
3. The browser then fails to load a `localhost:8765/callback?code=...` page. That is
   expected, because nothing listens there.
4. Within 24 hours, run
   `php artisan cookieyes:authorize --callback="<the whole address from the address bar>"`.
   It confirms by listing the account's websites.

If the login is lost later (revoked in CookieYes's Connectors list, left unused for too
long, or `APP_KEY` changed), the check fails and its summary says to run
`cookieyes:authorize` again.

## Settings

`freegle.cookieyes` in `iznik-batch/config/freegle.php`: `enabled` (`COOKIEYES_ENABLED`, on by
default; off stops the schedule), `base_url`, and
`rescan_after_days` / `stale_after_days`, which default to 30 and 45.
