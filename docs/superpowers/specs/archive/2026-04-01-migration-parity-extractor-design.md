# V1→V2 Migration Parity Extractor — Design Spec

**Date:** 2026-04-01  
**Status:** Approved

## Problem

V1 PHP API endpoints and background jobs have deep call chains. When migrating to V2 (Go API / Laravel), behaviours buried several layers down in model methods are routinely missed — both during initial discovery and during implementation. Previous mitigations (equivalent tests, line-by-line review, adversarial review, ralph TDD, deferral-catching hooks) have all failed to prevent gaps reliably because they rely on Claude's reading comprehension to discover behaviours, which is inherently lossy for deep call chains.

## Solution

Mechanically extract a complete behaviour ledger from V1 PHP source using a proper AST (not regex), then check V2 Go source for evidence of each behaviour. Discovery is automated and deterministic — Claude's judgment is removed from the most failure-prone step.

---

## Component 1: PHP Behaviour Extractor

**File:** `scripts/parsers/v1-behavior-extractor.php`  
**Dependency:** `nikic/php-parser` (added as Composer `require-dev` in `iznik-server/`)

### How it works

1. Accepts a V1 PHP endpoint file as input (e.g. `http/api/comment.php`)
2. Parses it into a full AST using PHP-Parser
3. Uses a recursive `NodeVisitor` to walk `MethodCall`, `StaticCall`, and `FuncCall` nodes
4. For each call, resolves the definition in `include/` and recursively visits that AST (depth cap: 15 levels, cycle detection via seen-set)
5. At each node, records every instance of these behaviour categories with source file + line number:

| Category | Detection pattern |
|----------|------------------|
| SQL query | `preQuery`, `prePrepared`, `beginTransaction`, raw SQL string args |
| Email send | `sendOne`, `Mailer::`, `mail(` |
| Push notification | `Notifications::create`, `sendPush` |
| Audit/log entry | `->log(`, `LoggedPDO`, activity log patterns |
| External HTTP | `curl_`, `file_get_contents` with URL arg |
| Return variant | Structurally distinct `return [...]` shapes |
| Auth branch | Conditionals gated on `$myid`, `isAdmin`, `isMod`, `hasPermission` |

### Output

A markdown ledger per endpoint:

```
## http/api/comment.php

| # | Category | Description | V1 source |
|---|----------|-------------|-----------|
| 1 | SQL | SELECT from comments | include/User.php:412 |
| 2 | Audit/log | log comment flag action | include/User.php:438 |
...
```

---

## Component 2: V2 Coverage Checker

**File:** `scripts/parsers/v2-coverage-checker.py`

Takes the V1 ledger JSON produced by Component 1 and the path to the corresponding V2 Go package. For each ledger item, uses ripgrep (via subprocess) to search for evidence in the Go source:

- **SQL** — table name + operation type in `db.Raw`/`db.Exec` calls
- **Push notifications** — Go notification-send function name
- **Email** — Go email-queue call pattern
- **Audit/log** — Go log-creation pattern
- **Auth branches** — equivalent permission check functions

Python + ripgrep is used rather than Go AST — pattern matching in known files is sufficient; full AST is unnecessary overhead.

Each item is assigned one of three statuses:
- `FOUND` — evidence present in V2
- `NOT_FOUND` — no evidence found (genuine gap)
- `INTENTIONAL` — documented in `plans/active/v1-v2-parity-audit.md` as deliberate architectural difference

Only `NOT_FOUND` items appear in the gap report.

The PHP filename → Go package mapping is derived from `plans/active/api-migration-review-log.md`.

---

## Component 3: Driver and Output

**File:** `scripts/parsers/run-parity-check.sh`

- Iterates all 58 V1 API files in `iznik-server/http/api/`
- Skips endpoints already fully documented as N/A in `v1-v2-parity-audit.md`
- Calls extractor (produces V1-only ledger as JSON) → coverage checker (annotates ledger with `FOUND`/`NOT_FOUND`/`INTENTIONAL`) per endpoint
- Writes two outputs:
  - `docs/parity/YYYY-MM-DD-parity-report.md` — full gap report, one section per endpoint
  - Summary table at top: endpoint | behaviours extracted | gaps found | gap %

---

## Future Use (ongoing migrations)

Once verified against the completed migration, this toolchain becomes a mandatory pre-implementation gate:

1. Run extractor on V1 endpoint → produces locked behaviour ledger
2. User reviews and approves ledger before any V2 code is written
3. Implementation ticks off ledger items with Go file + line references
4. Coverage checker confirms all items `FOUND` before marking endpoint done
5. No deferral possible — a ledger item with no Go pointer is a build blocker

---

## Constraints and Limitations

- Dynamic PHP dispatch (`$obj->$method()` where `$method` is a variable) cannot be statically resolved; these are flagged in the ledger as `DYNAMIC — manual check required`
- Virtual/abstract methods in PHP base classes are followed via the most-derived class instantiated in the endpoint file
- The extractor will not catch every behaviour (e.g. implicit framework hooks), but covers the systematic categories that account for the majority of historical gaps
- Background job migration (Laravel artisan commands vs V1 cron scripts) uses the same toolchain — entry point is the cron PHP file rather than an API endpoint

---

## Success Criteria

- Running against the completed V1→V2 API migration produces a gap report
- Gap report matches or exceeds the findings in `plans/active/v1-v2-parity-audit.md` (22 known bugs)
- False negative rate (gaps the tool misses) is lower than the false negative rate of manual review
