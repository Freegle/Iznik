# ModTools Partnerships page

Worktree: `/home/edward/FreegleDocker-partnerships` (branch `feature/modtools-partnerships`, status port 12339, traefik 12342)

## Goal

A Partnerships page in ModTools, gated on a new **Partnerships** team, which manages council
sponsorship deals end to end: which councils have a live sponsorship, the groups each deal
covers, the money (agreed / invoiced / paid, split by UK financial year), reminder mails three
months before expiry, and on-demand generation + download of the quarterly authority stats
spreadsheets.

## Design decisions

- **Tables** are prefixed `partnerships_` (`partnerships` itself carries the prefix).
- **A partnership is with an authority (council)**, not a group. The groups it affects are
  derived from the authority ∩ group polygon overlap (the same query `/authority/{id}` already
  uses) and can be trimmed by hand. Saving a partnership syncs `groups_sponsorship` rows for
  those groups so the member site shows the sponsor.
- **Money**: `partnerships.amount` is the headline deal value. Multi-year deals get explicit
  `partnerships_years` rows (financial year + amount); when there are none, the amount is
  pro-rated across the UK financial years (1 Apr - 31 Mar) the deal spans. Payments are tracked
  in `partnerships_payments` so "invoiced vs paid" is visible.
- **Stats generation is async**: the page POSTs a job row, the Laravel scheduler picks it up,
  renders the spreadsheets with the existing `AuthorityStatsCommand` renderer and stores the
  bytes in `partnerships_statsfiles`; the page polls and downloads from apiv2. Blob-in-DB
  avoids needing a shared volume between the Go and Laravel containers.
- **Reminders** go to the Partnerships team email (partnerships@ilovefreegle.org) when a
  sponsorship is within 3 months of expiry, recorded in `partnerships_reminders` so each
  partnership is only chased once per window.

## Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Worktree + codebase exploration | ✅ | |
| 2 | Laravel migrations for `partnerships*` tables | ✅ | 7 tables + team seed, with production SQL twins |
| 3 | Go: `partnerships` package - CRUD, group sync, FY summary + tests | ✅ | 40 tests |
| 4 | Go: session exposes team memberships | ✅ | `teams` on `me`, not gated on systemrole (see below) |
| 5 | Laravel: stats job runner command + tests | ✅ | `partnerships:stats:run` |
| 6 | Laravel: expiry reminder command + mail + tests | ✅ | `partnerships:reminders`, 18 Laravel tests green |
| 7 | Frontend: API + store + page + components + menu | ✅ | |
| 8 | Vitest tests | ✅ | 83 green |
| 9 | Run Go / Laravel / vitest suites | 🔄 | Laravel + vitest green; final Go run in flight |
| 10 | Live DB | ✅ | Team + members + logins already existed - see below |
| 11 | Docs + PR | 🔄 | |

## Bugs this turned up along the way

- **`authority.GroupsForAuthority` aborted on a non-polygon `polyindex`.** `ST_Area` errors
  on a point, and one such group killed the whole query rather than being skipped - so
  `/authority/{id}` returned no groups at all. The geometry-type check now guards the area
  calls themselves.
- **`YearAmount.FinancialYear` needed an explicit GORM column tag.** The default namer looks
  for `financial_year`, so every explicitly-agreed year split read back as year zero.
- **The session `teams` list must not be gated on systemrole.** A team's own shared account
  is an ordinary member by role, and on live one of the Partnerships team members is exactly
  that - gating on systemrole would have hidden the page from them.

## Live database

Checked over the tunnel before changing anything, and the intended state was already there:

- The **Partnerships** team exists (id 139) with `partnerships@ilovefreegle.org` as its
  address, and already has the three intended people on it.
- All three already have a Native (username/password) login, so there was nothing to create.
  Their existing passwords were left alone.
- The two `@ilovefreegle.org` addresses named in the request are not on those accounts: one
  member's account uses a personal address, and the shared account uses a different
  `@ilovefreegle.org` one. Left as-is rather than guessing at mail routing.

The only outstanding live change is creating the `partnerships*` tables, which the production
SQL twins alongside the migrations do.
