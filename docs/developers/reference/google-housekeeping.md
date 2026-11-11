---
last_reviewed: 2026-10-24
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/Google/**
  - iznik-batch/app/Console/Commands/Google/**
  - iznik-nuxt3/server/utils/sitemap.js
  - iznik-nuxt3/server/routes/sitemap.xml.ts
---

# Google housekeeping (Search Console and Ads)

Two weekly jobs in `iznik-batch` look at Google on our behalf and say when something needs
a person. Both record a row in `housekeeper_tasks` (the housekeeping badge on the ModTools
Sysadmin page, overdue after 8 days) and email Geeks on failure. They only read. Neither edits an
ad, appeals anything or changes a Google setting. The one exception is
`google:search-console-check --submit-missing`, which submits the sitemap to a property that has
none, and is never run by the schedule.

| Command | Runs | Task key |
|---|---|---|
| `google:search-console-check` | Mondays 10:40 | `google-search-console` |
| `google:ads-check` | Mondays 10:50 | `google-ads` |

Switches: `GOOGLE_SEARCH_CONSOLE_CHECK_ENABLED` and `GOOGLE_ADS_CHECK_ENABLED` (default on).
Until the credentials below exist, each run fails and its summary names the missing variables.

## Search Console

Auth is a Google **service account** added as a user on each Search Console property. No
person logs in. Uses the Search Console API (`webmasters/v3` for properties and sitemaps,
`searchconsole/v1` for URL inspection).

For every property the account can see, it fails when:

- a sitemap reports errors or warnings;
- a sitemap has never been read, or was last read more than 14 days ago;
- an `ilovefreegle.org` property does not list `https://www.ilovefreegle.org/sitemap.xml`;
- Google's URL inspection of the home page and 3 live posts (taken from our own sitemap)
  says it could not fetch the page (server error, blocked, redirect error and so on). A page
  Google has simply not met yet is not a failure.

The per-reason "Page indexing" report in the Search Console website (for example "Server error
(5xx)") has **no API**. That is the report behind the emails of 11 October 2026. The sampled URL
inspection is the nearest automatic signal, and the Page indexing report itself still needs a
person to look at the website when the check names a fetch problem.

## Google Ads (Ad Grants)

Auth is a normal OAuth client plus a refresh token, because the Ads API does not accept a bare
service account. API access belongs to the Cloud project: Google Ads API, Access levels in the Cloud
console, at **Explorer** or above (Test only reaches test accounts). With that, no developer token is
needed. It fails when:

- the account status is not `ENABLED`;
- any live ad is disapproved or limited, naming the policy (for example "Dating and companionship",
  which needs a certificate);
- any asset in use (image, sitelink, callout and so on, on a live campaign, ad group or the account)
  is disapproved. Google approves an asset separately for each way it is used, so an ad can read
  approved while an image it shows is not: on 2026-10-24 the Freegle logo was approved as the business
  logo but disapproved as an ad image (text or graphic overlay) in Brand and Generic;
- last month's click-through rate is under 5% (the Ad Grants floor, `GOOGLE_ADS_MIN_CTR`), or there
  were no impressions;
- any live keyword has a Quality Score of 1 or 2 (not allowed in Ad Grants).

Ad Grants accounts have no billing, so there is no billing check.

## Credentials

Set in the environment of the batch containers (`.env.background` on the batch host). Names only
here; never commit values.

| Variable | For |
|---|---|
| `GOOGLE_SERVICE_ACCOUNT_B64` | Search Console. The service account JSON key, base64 encoded. Or `GOOGLE_SERVICE_ACCOUNT_FILE` for a path inside the container. |
| `GOOGLE_SEARCH_CONSOLE_SITES` | Optional comma separated property URLs. Empty means every property the account can see. |
| `GOOGLE_SEARCH_CONSOLE_SITEMAP` | Optional. Default `https://www.ilovefreegle.org/sitemap.xml`. |
| `GOOGLE_ADS_DEVELOPER_TOKEN` | Optional. Sent only when set. A project with Ads API access in the Cloud console needs none; the API Center form now only issues tokens for the App Conversion Tracking API. |
| `GOOGLE_ADS_CUSTOMER_ID` | Ads. The 10 digit account ID, digits only. |
| `GOOGLE_ADS_LOGIN_CUSTOMER_ID` | Ads. Only if the account is reached through a manager account. |
| `GOOGLE_ADS_CLIENT_ID`, `GOOGLE_ADS_CLIENT_SECRET` | Ads. An OAuth client of type Desktop app. |
| `GOOGLE_ADS_REFRESH_TOKEN` | Ads. Obtained once by the account owner, see below. |
| `GOOGLE_ADS_API_VERSION` | Optional. Default `v26`. Google retires versions about a year after release; bump it (v21 was gone and v22 refused by October 2026). |

### Search Console setup (once)

1. In Google Cloud Console, create (or reuse) a project. Enable **Google Search Console API**.
2. IAM and Admin, Service Accounts, create one (for example `freegle-housekeeping`). No roles
   needed. Keys tab, Add key, JSON. Base64 encode the file (`base64 -w0 key.json`) into
   `GOOGLE_SERVICE_ACCOUNT_B64` and delete the downloaded file.
3. In Search Console, for each property (`ilovefreegle.org`, `modtools.org`, and the
   `https://www.ilovefreegle.org/` property): Settings, Users and permissions, Add user, the service
   account's email address, permission **Restricted** is enough to read.
4. Run `php artisan google:search-console-check` and check the result on the Sysadmin page.

### Google Ads setup (once)

Done for Freegle on 2026-10-24 in the `freegle-1139` project, as geeks@.

1. In Google Cloud Console enable the **Google Ads API**. Under Google Ads API, Access levels, apply
   for **Explorer** (it allows calls to real accounts; approval took minutes). No developer token
   is needed after that.
2. In Google Auth Platform, create an OAuth client of type Desktop app. That gives the client ID and
   secret. The consent screen must be **In production**: in Testing, refresh tokens expire after 7 days.
3. Get the refresh token once, signed in as a Google user with access to the Ads account: an OAuth
   loopback flow with that client, scope `https://www.googleapis.com/auth/adwords`,
   `access_type=offline`, `prompt=consent`. Google asks the account to confirm it is really them,
   which may need its passkey, so do it in a browser that has one. Put the refresh token in
   `GOOGLE_ADS_REFRESH_TOKEN`.
4. Put the account ID in `GOOGLE_ADS_CUSTOMER_ID` (FreegleAds is 495-138-8253). The signed-in user
   reaches it directly, so `GOOGLE_ADS_LOGIN_CUSTOMER_ID` stays empty. Run `php artisan google:ads-check`.

## Tests

`tests/Feature/Google/` runs both commands with every Google call faked.
