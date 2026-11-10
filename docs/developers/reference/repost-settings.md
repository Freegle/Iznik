---
last_reviewed: 2026-11-10
owner: Freegle dev team
covers:
  - iznik-batch/app/Services/RepostSettings.php
  - iznik-batch/app/Services/AutoRepostService.php
  - iznik-batch/app/Services/ChaseUpService.php
  - iznik-batch/app/Services/MessageExpiryService.php
  - iznik-batch/database/migrations/2026_11_10_000001_add_reposts_config_row.php
  - iznik-server-go/group/repostsettings.go
  - iznik-server-go/config/config.go
  - iznik-nuxt3/composables/useRepostSettings.js
  - iznik-nuxt3/modtools/components/ModSettingsGroup.vue
  - iznik-nuxt3/modtools/components/ModStdMessageModal.vue
  - iznik-batch/tests/Unit/Services/RepostSettingsTest.php
  - iznik-server-go/test/repost_settings_test.go
---

# Repost settings

How long a post waits before it is reposted, and how many times it is reposted, are **one
system-wide setting**. They are not set per community. This page says where the value lives,
who reads it, and how to change it.

## The row

One row in the `config` table, key `reposts`, value JSON:

```json
{"offer":3,"wanted":7,"max":3,"chaseups":5}
```

| Field | Meaning |
|-------|---------|
| `offer` | Days an OFFER waits before it is reposted, and again between reposts. |
| `wanted` | The same for a WANTED. |
| `max` | How many times a post is reposted. After that the poster is chased up instead. |
| `chaseups` | Days between chase-up emails ("What happened to: ..."). |

A value of `0` is kept and means off. A value that is not a whole, non-negative number is
ignored for that field and the built-in value is used.

The migration `2026_11_10_000001_add_reposts_config_row.php` inserts the row only if it is
absent, so a value set by hand is kept. Its production SQL is beside it
(`..._migration.sql`).

## Who reads it

Every reader uses this row and nothing else. A community's own `groups.settings.reposts` is
**ignored everywhere** and is left in the database untouched.

| Code | How it reads it |
|------|-----------------|
| Batch: `AutoRepostService`, `ChaseUpService`, `MessageExpiryService` | `RepostSettings::get()`, once at the start of a run. |
| Go API: expiry, "can this post be reposted", `repostat` | `group.SystemRepostSettings()`, cached in memory for five minutes. |
| ModTools: the community settings page and standard messages (`$repostoffer`, `$repostwanted`, `$repeatoffertime`, `$repeatwantedtime`) | `useRepostSettings()` through `GET /config/reposts`. |

`reposts` is one of the keys `isPublicConfigKey` lets `GET /config/:key` serve without signing
in. It holds four small numbers, nothing per member.

If the row is missing or is not JSON, each code base falls back to the same four values, held
as one constant in each: `RepostSettings::FALLBACK` (batch), `group.DefaultRepostSettings()`
(Go) and `GROUP_REPOSTS` in `iznik-nuxt3/constants.js`. Each logs a warning (the frontend uses
the fallback without one). Change all four places together.

`maxagetoshow` ("Expire posts") is still per community. A post expires after the greater of
`maxagetoshow` and `offer or wanted x (max + 1)` days, with the repost values from this row.

## Changing it

An admin config update does it, with no deploy:

```
PATCH /api/config/admin   {"reposts": {"offer": 3, "wanted": 7, "max": 3, "chaseups": 5}}
```

or one SQL statement:

```sql
UPDATE config SET value = '{"offer":3,"wanted":7,"max":3,"chaseups":5}' WHERE `key` = 'reposts';
```

The batch sees the change on its next run. The Go API sees it within five minutes (restart
`apiv2` to see it at once). ModTools shows it the next time the settings page is opened (the
config store keeps it for ten minutes).

There is no screen for this. It is changed deliberately, centrally.

## Deploying it

The migration (or its SQL) first, then the batch, then the Go API, then the frontend. The
frontend reads a config key the older API does not serve, so it must come after the API
(`.claude/rules/frontend-traps.md`); until then ModTools shows the built-in values.
