# "I'm interested" button (experiment `interested-button`)

Edward, 2026-10-23: an "I'm interested" button, run through the UX experiment architecture, as a quick
sign of interest before the first reply. Monitor how many go on to reply, let members find the posts
they marked, chase up by email while the post is still open. No human intervention; screenshots in PR.

Worktree `/home/edward/FreegleDocker-interested-button`, branch `feat/interested-button`,
project `freegle-interested-button`, PORT_STATUS 12045, dev-live 12031, dev-local 12032,
modtools-dev-local 12038.

## Shape

A server experiment decided in Go, like `confirm-reply`: the switch lives on the server, so the app
build carries no switch, and the server says per member whether to offer the button.

- Name `interested-button`. Arms `control` and `button`. Channel `server`, slot `server:interested-button`.
- Exposure: `view`, subject `messages.id`, written by Go when a signed-in web member opens a post they
  could reply to (both arms). One row per member and post (unique key). Not for app requests
  (`utils.RequestFromApp`), TrashNothing accounts, own posts, posts with an outcome, deleted posts, or
  types other than Offer/Wanted.
- Counted by a new batch source `interested_reply`: each member once, on their first `view` exposure,
  once it is `window_hours` (168) old. Converted: the member sent a reply (`chat_messages.type =
  'Interested'`, `userid` = member, `refmsgid` not null) within the window. Guardrail `fewer_handovers`:
  share of counted members NOT promised an item (`messages_promises`) or named in `messages_by` within the
  window must not rise by more than 2 points.
- `extra()` for the tab: per arm, members who marked, marks, marks later followed by a reply from the same
  member to the same post (before the chase-up, after it), chase-ups sent, marks on posts that went before
  any reply.

## Data

Table `messages_interested` (new; every table gets an `id`):

| column | type |
|---|---|
| id | bigint unsigned auto increment PK |
| msgid | bigint unsigned, FK messages(id) ON DELETE CASCADE |
| userid | bigint unsigned, FK users(id) ON DELETE CASCADE |
| added | timestamp default CURRENT_TIMESTAMP |
| chasedat | timestamp NULL |

Unique `(userid, msgid)`, index `(msgid)`, index `(chasedat, added)`. Laravel migration plus idempotent
`_migration.sql`. Merge moves rows (Go `MergeUsersTx`); member export/forget handled where the other
per-member tables are.

## Go API

- `GET /message/:id/interested` (signed in, else 401). Returns `{"arm": "button"|"" , "interested": bool,
  "eligible": bool}`. Looks the arm up with `ux.ExperimentArm` only when eligible, and records the `view`
  exposure for both arms with `ux.RecordExposure`. `interested` is true when a row exists, whatever the arm.
- `POST /message/:id/interested` body `{"interested": true|false}`. True: insert (IGNORE) when eligible
  (arm not required: a page opened before a stop still works). False: delete. Returns `{"interested": bool}`.
- `GET /message/interested`: the member's marks from the last 30 days, newest first, up to 100:
  `[{"msgid", "added", "chasedat", "replied": bool, "open": bool}]`. `open` = no outcome, not deleted.
- `ux.ServerSwitches` entry for `interested-button`.
- Tests in `iznik-server-go/test/`, Swagger annotations as house style.

## Batch

- Migration, model `MessageInterested`.
- `App\Services\Ux\Sources\InterestedReplySource`, key `interested_reply` in `config('freegle.ux.sources')`.
- Command `messages:interested-chaseup`, hourly: per member, marks with `chasedat` NULL, `added` older than
  `chaseup_after_hours` (24) and younger than `max_age_days` (7), no reply from the member to that post, post
  still open (no outcome, not deleted, still approved on a community, not promised). One email per member
  listing those posts (mailable `Message/InterestedChaseUp`, MJML, tracked), then `chasedat` set on each.
  Skip members who are deleted, bouncing, or have mail turned off, as `MatchedPosts` does.
- Tests: source counts, command selection, mail render.

## Frontend

- `MessageExpanded` reply area: signed in, not own post, post open -> `GET /message/:id/interested`. Show
  the outlined **I'm interested** button beside Reply when `arm === 'button'`, or the marked state whenever
  `interested`. Marked: "Interested. We'll remind you tomorrow." with Undo. Client `action()` logs
  `interested_mark` / `interested_undo`.
- My Posts: section **Posts you're interested in**, shown when there are open marks, each with Reply.
- ModTools: hosted detail `ModSysAdminUxInterestedDetail` in `HOSTED.experiment`, for `interested-button`
  only: marks, members, replied before/after chase-up, chase-ups sent.
- Vitest specs; Playwright spec for mark and find; screenshots for the PR.

## Docs

`docs/developers/reference/ux-experiments.md` section "An I'm interested button" (create and start commands,
cleanup), member doc line, `covers:` updated.

## Status

| # | Task | Status |
|---|---|---|
| 1 | Migration + model | ⬜ |
| 2 | Go endpoints, switch, exposure, merge, tests | ⬜ |
| 3 | Batch source, chase-up command and mail, tests | ⬜ |
| 4 | Frontend button, My Posts, ModTools detail, vitest | ⬜ |
| 5 | Playwright + screenshots | ⬜ |
| 6 | Docs | ⬜ |
| 7 | Full suites, PR | ⬜ |
