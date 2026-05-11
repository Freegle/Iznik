# Freegle Coding Standards

## Testing

- NEVER skip coverage. Run local tests BEFORE committing. All failures must be fixed.
- Run tests via Status Container only: `curl -X POST http://localhost:8081/api/tests/{go,php,laravel,playwright}`
- 90%+ coverage on touched modules. NEVER accept flaky tests — fix root cause.
- Playwright: Use assertions not `waitFor()`/timeouts. Use `type()` not `fill()`. Use config.js constants. Simulate user clicks, never direct navigation or native JS click.
- Parallel test isolation (PHPUnit 4 workers): Use `$this->uniqueEmail()`, `$this->uniqueGroupName()`, `createTestUser/Group/Message()`. Don't hardcode names or rely on shared state.

## Commits & Deployment

- NEVER commit unless tests pass. NEVER push unless told to. NEVER add "Claude Code" to commits. Full stops at end of sentences.
- **Backend first**: Deploy backend before dependent frontend. Split cross-component changes into separate PRs. Link related PRs. Verify production deployment before merging frontend.

## Code Style

- `eslint --fix` on JS/TS. `php artisan pint` on PHP. SCSS: `/* */` not `//`. No `border-radius`. ExternalLink not `<a>`. Full stops. New files → git add. Removed code → no comments about what was there. Temp scripts → `/tmp`.

## Bug Fixes (TDD)

1. Write failing test. 2. Verify it fails. 3. Minimal fix. 4. Verify it passes. 5. Add further tests.

## Validation

- Front-end: Chrome DevTools MCP. Emails: MailPit. Backend: test coverage.

## V2 Go API Handlers

Follow existing patterns in iznik-server-go. Structure: AUTH → PARSE → DB → PRIVACY → RESPOND.
- Auth: `myid := user.WhoAmI(c)` (0 if anonymous). Role: `c.Locals("userRole")`.
- Parallel: `sync.WaitGroup` + `sync.Mutex` for 2+ independent queries. See `message/message.go`.
- Privacy: Regex-replace emails/phones for non-owners. `utils.Blur()` for locations.
- Errors: `fiber.NewError(statusCode, message)`. Empty arrays: `make([]T, 0)` not `nil`.
- Swagger: Add annotations, run `./generate-swagger.sh`. Register both `/api/` and `/apiv2/` routes.
- Prefer `db.Raw()` over GORM for performance. Struct tags: `json:"field"`, `json:"-"`, `gorm:"-"`.
- Tests: ARRANGE/ACT/ASSERT with `CreateTestUser`, `CreateTestGroup`. See existing `*_test.go` files.
- **NEVER enrich responses with nested objects.** V2 returns IDs for related entities (e.g. `msgid`, `groupid`). The frontend fetches details separately via its stores. If you're writing `db.Raw("SELECT...")` inside a loop to inline related data, STOP — return the ID instead.

## Database Migrations

- Laravel migrations in `iznik-batch/database/migrations/` are source of truth.
- Production: Manual idempotent SQL using `INFORMATION_SCHEMA.COLUMNS` checks. Put in `*_migration.sql` files.

## Code Quality

- No copy-paste — extract shared logic. Address linting/static analysis warnings.
- Tools: `jscpd`, `golangci-lint`, PHPStan (in apiv1 container), ESLint.
- **Use model constants, never naked string literals**: `Group::TYPE_FREEGLE` not `'Freegle'`; `Membership::ROLE_OWNER/MODERATOR/MEMBER`, `Membership::COLLECTION_APPROVED/PENDING`; `Message::TYPE_OFFER/TAKEN/WANTED`, `Message::SOURCE_PLATFORM`; `ChatRoom::TYPE_USER2MOD/USER2USER/MOD2MOD`. This applies in services AND tests.

## Containers & CI

- Dev containers: auto-synced. Prod + Go API: rebuild required. Status: `docker restart status`.
- Orb: `~/.local/bin/circleci orb publish .circleci/orb/freegle-tests.yml freegle/tests@X.X.X`
- Plans in `FreegleDocker/plans/`, never in subdirectory repos. Rebuild dev containers on branch switch.
- No hardcoded IPs in docker-compose.yml. Container changes lost on restart.
