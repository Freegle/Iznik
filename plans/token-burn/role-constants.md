# Candidate: Role values only through constants

Status: ready
Last reviewed: 2026-11-03

## Goal

No system role (`User`, `Moderator`, `Support`, `Admin`) or membership role (`Member`,
`Moderator`, `Owner`) is written as a string anywhere in the Go API, the batch code or the
frontend, in code or tests. Each is read from one constant per language, and a role in a SQL
string is a bound parameter. A check in CI and in the editor hook fails any change that adds a
literal back. This is for whoever next writes a permission check: a misspelt role then fails
to build instead of quietly matching nobody.

## Why it qualifies

- Token-heavy because: about 5,500 literals across three code bases, most in test fixtures
  (about 3,100 `CreateTestUser` role arguments and about 1,800 membership-role arguments in
  the Go tests alone), each needing the right constant for its column.
- Unattended because: the change is mechanical, the decisions are settled below, and the full
  suites are the check; nothing needs production, a partner or Edward mid-build.
- Worth it because: a role literal that is wrong gives no error, just the wrong people seeing
  or not seeing member data. The PHP rule already exists in prose
  (`coding-standards.md:51`) and has not held, with 32 membership-role literals left in
  `iznik-batch/app`.

## What exists today

- `iznik-server-go/utils/utils.go:240-242` - `ROLE_MEMBER`, `ROLE_MODERATOR`, `ROLE_OWNER`.
- `iznik-server-go/utils/utils.go:252-255` - `SYSTEMROLE_USER`, `SYSTEMROLE_MODERATOR`,
  `SYSTEMROLE_SUPPORT`, `SYSTEMROLE_ADMIN`.
- `iznik-batch/app/Models/Membership.php:72-74` - `ROLE_MEMBER`, `ROLE_MODERATOR`,
  `ROLE_OWNER`.
- `iznik-batch/app/Models/User.php:132-139` - the same three membership roles again, plus
  `SYSTEMROLE_*`. Two names for one value.
- `iznik-nuxt3/constants.js` - shared frontend constants (regexes, store URLs); no roles yet.
  `iznik-nuxt3/composables/useMe.js:194-202` compares roles as literals.
- `iznik-batch/database/migrations/2025_12_10_094529_create_users_table.php:24` - `systemrole`
  is a `SET` of the four values, default `User`.
- `docs/developers/reference/coding-standards.md:51` - "Use model constants, never naked
  string literals", naming `Membership::ROLE_*`. PHP only; nothing says the same for Go or JS.
- System-role literals, counted on master 2026-11-03:
  - Go code: 26 in SQL strings. Examples: `membership/membership.go:985,992`
    (`u1.systemrole = 'User'` inside a UNION), `group/groupWork.go:408-417`,
    `user/auth.go:58` (`ljuser.Systemrole = "User"`), `user/user.go:3472` (the
    `sysRoleOrder` map keys). `address.go:254`, `user.go:721` and `newsfeed.go:834` already
    bind the role as a parameter, which is the shape to copy.
  - Go tests: 21 in SQL strings, and the `CreateTestUser(t, prefix, role)` argument
    (`test/testUtils.go:130`) written as a literal about 3,100 times (User 2,686,
    Moderator 147, Admin 141, Support 143).
  - PHP: 7 in `app` (`UserManagementService.php:790,808,827,863,879`,
    `MembershipsProcessingService.php:145`, `User.php:350`), 22 in `tests`.
  - Frontend: 62 in 17 code files, 77 in tests.
- Membership-role literals, same day:
  - Go: 5 in SQL strings and 3 elsewhere in code; about 1,800 in tests, most as the
    `CreateTestMembership(t, user, group, role)` argument (`test/testUtils.go:208`).
  - PHP: 32 in `app`, 148 in `tests`.
  - Frontend: 35 in 22 code files, 203 in tests.
  - These counts are by grep and include some UI copy; the build sorts copy from roles.
- `.claude/check-raw-sql.sh` - the model for the guard. It runs as a Claude Code editor hook
  (judging the text being written) and as `--diff origin/master` in the `check-raw-sql` CI
  job (`.circleci/continue-config.yml:111-124`), with its own test script
  `.claude/check-raw-sql.test.sh`.
- `iznik-routing-go` and `iznik-spatial-go` - no role values; not touched.

## Decisions settled

- **No role literal in SQL; the role is a bound parameter fed from the constant.** Edward,
  2026-11-03: "no literals in sql, use constants". A literal inside SQL is the one place a
  compiler cannot check it.
- **Fixtures too, all of them, including every `CreateTestUser` and `CreateTestMembership`
  argument.** Edward: "if you're worried about safety it means you don't trust your tests." The
  suites are the safety net for the sweep; the sweep is not held back for fear of it.
- **Membership roles are in scope, other enum values are not.** Edward chose system plus
  membership roles: the constants already exist and the fix has the same shape. Collections,
  message types and statuses appear in nearly every query and are their own candidate.
- **A guard, in CI and in the editor hook, fails a change that adds a role literal.** Edward
  chose it: without one, new code brings literals back within weeks and the sweep has to be
  done again. It copies the two modes of `check-raw-sql.sh`.
- **The guard looks at role contexts, not at the words.** "Moderator" and "Owner" are also
  ordinary words in UI copy, headings and mail text. The guard fires on a role or systemrole
  comparison or assignment, a role in a SQL string, a role argument to the test helpers, and
  an option value; copy is left alone. A guard that fires on copy gets switched off.
- **`Moderator` is two constants.** The same value is both a system role and a membership
  role. Each literal takes the constant for the column it is compared with, never whichever
  comes first.
- **In PHP, membership roles are `Membership::ROLE_*`.** `coding-standards.md:51` already
  names them. `User::ROLE_*` repeats the values under a second name; callers move to
  `Membership::ROLE_*` and the duplicates go, so the two cannot drift.
- **The frontend gets `SYSTEMROLE_*` and `ROLE_*` in `iznik-nuxt3/constants.js`,** with the
  same names as Go and PHP, so a role reads the same in all three.
- **No behaviour change.** Every edited token is a literal becoming a constant or a parameter.
  Anything else a builder finds wrong on the way (a wrong role in a check, say) is reported as
  an issue, not fixed inside the sweep, so the sweep stays reviewable as mechanical.

## Open questions for Edward

None.

## External prerequisites

None.

## Tasks

One PR each. The guard goes first in diff mode, so no new literals arrive while the sweeps
run; it switches to a whole-tree check last.

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Guard in diff mode: script, its test script, editor hook, CI job | - | A | `.claude`, `.circleci` |
| 2 | Go code: role literals to constants, SQL roles to bound parameters | - | B | iznik-server-go (non-test files) |
| 3 | Go tests: every fixture argument and SQL role literal | - | C | iznik-server-go (`_test.go`, `test/`) |
| 4 | Batch code and tests, and remove `User::ROLE_*` | - | D | iznik-batch |
| 5 | Frontend: constants in `constants.js`, then code and tests | - | E | iznik-nuxt3 |
| 6 | Guard checks the whole tree, held at zero; docs updated | 1-5 | A | `.claude`, `.circleci`, `docs` |

Tasks 2 and 3 are in the same module but never the same file, so they can run side by side.
Task 3 is the largest; it can be split by package if one PR is too big to read.

## Acceptance

- Searching Go, PHP, JS and Vue for the seven role values as quoted strings finds only the
  constant definitions, UI copy, and migrations.
- No SQL string contains a role value. Each role is a bound parameter.
- The guard's own test shows it failing a change that adds `systemrole = 'Support'` to a query,
  a literal `CreateTestUser` role, or `role === 'Owner'` in a component, and passing a change
  that adds the word "Moderator" to some copy.
- After task 6, the CI job fails on master if a literal is planted anywhere, not just in a diff.
- Each sweep PR's word diff shows only literals becoming constants or parameters, with no
  logic touched.
- The Go, Laravel, Vitest and Playwright suites pass, and run the same number of tests as
  master did before the sweep.

## Review burden

- PRs: 6. Five are mechanical; the reviewer reads the guard (1 and 6) and spot-checks the
  word diff of the others.
- Each UI PR carries: none. No screen changes, so `PR_NO_SCREENSHOT=1` with that reason.
- Each migration PR carries: none. No schema change.
- Docs page(s) to add or update, with `covers:`: `docs/developers/reference/coding-standards.md`
  line 51 extended to Go and the frontend and to name the guard (task 6).

## Out of scope

- Other enum values written as literals in SQL: collections, message and chat types,
  statuses. A separate candidate.
- Migrations. They are history and define the columns.
- Renaming the role values or the columns.
- `iznik-routing-go` and `iznik-spatial-go`, which hold no roles.

## Related plans

- None. `.claude/check-raw-sql.sh` is the pattern the guard copies.
