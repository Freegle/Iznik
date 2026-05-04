# Rspamd Milter Integration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire rspamd into the Postfix mail pipeline as a milter so incoming spam is rejected at SMTP level, and resolve the two-class naming confusion in iznik-batch.

**Architecture:** Postfix calls rspamd on every incoming SMTP connection via the milter protocol (port 11332). Rspamd scores the message using its own ML rules plus SpamAssassin scores, adds `X-Rspamd-*` headers to clean mail, and rejects definite spam with a 5xx code before it enters the Postfix queue. The `milter_default_action = accept` ensures mail flows normally if rspamd is down. The dormant `App\Services\SpamCheck\SpamCheckService` is renamed to `RspamdService` to eliminate ambiguity with the incoming-mail class of the same name (`App\Services\Mail\Incoming\SpamCheckService`).

**Tech Stack:** Postfix (Alpine), rspamd UCL config, PHP 8.3 / Laravel 12, Docker Compose

**Spec:** `docs/superpowers/specs/2026-05-04-post-review-service-design.md` — Section 11

---

## Environment notes (read before executing)

- **Container names depend on `COMPOSE_PROJECT_NAME`.** Default is `freegle`, giving `freegle-rspamd`, `freegle-postfix`, `freegle-batch-prod`, etc. Some hosts (e.g. the FreegleDocker dev box) use `COMPOSE_PROJECT_NAME=freegledocker`, giving `freegledocker-rspamd`, `freegledocker-postfix`, `freegledocker-batch-prod`. The commands below use the default `freegle-` prefix and the `batch-prod` service name (not `batch`); adapt the prefix to match your host, or run via `docker-compose exec <service>` which is project-name-agnostic.
- **Postfix lives in the `mail` compose profile**, not `backend`/`dev`. Local devs running the standard `frontend,database,backend,dev,monitoring` profile set will not have postfix running. Add `mail` to `COMPOSE_PROFILES` (or run `docker-compose --profile mail up -d postfix`) before the smoke tests.
- **rspamd already exposes 11332-11333 on the docker network** by default (the upstream image's `EXPOSE`). Postfix can therefore reach the milter port today without any compose change; the `expose:` block in Task 2 is documentation, not a functional requirement.
- **`config/freegle.php` already defines sensible defaults** for `rspamd_host=rspamd`, `rspamd_port=11334`, and `spam_check.enabled=false`. The batch-prod env vars added in Task 2 are explicit overrides that match the existing defaults — useful for clarity/audit, but they do not change runtime behaviour.

---

## File Map

| Action | Path |
|---|---|
| Modify | `conf/postfix/main.cf` |
| Create | `conf/rspamd/local.d/worker-proxy.inc` |
| Create | `conf/rspamd/local.d/worker-controller.inc` |
| Create | `conf/rspamd/local.d/spamassassin.conf` |
| Create | `conf/rspamd/local.d/actions.conf` |
| Modify | `docker-compose.yml` — rspamd and batch-prod env vars |
| Rename + modify | `iznik-batch/app/Services/SpamCheck/SpamCheckService.php` → `RspamdService.php` |
| Modify | `iznik-batch/app/Listeners/SpamCheckListener.php` |
| Create | `iznik-batch/tests/Unit/Services/SpamCheck/RspamdServiceTest.php` |

---

### Task 1: Add rspamd milter, controller, and SpamAssassin plugin config files

**Files:**
- Create: `conf/rspamd/local.d/worker-proxy.inc`
- Create: `conf/rspamd/local.d/worker-controller.inc`
- Create: `conf/rspamd/local.d/spamassassin.conf`
- Create: `conf/rspamd/local.d/actions.conf`

- [ ] **Step 1: Create the milter worker config**

```ucl
# conf/rspamd/local.d/worker-proxy.inc
# Milter worker — receives connections from Postfix on port 11332.
# rspamd scores each message and returns a milter response.
bind_socket = "rspamd:11332";
```

- [ ] **Step 2: Create the controller worker config (web UI password)**

The web UI is fronted by Traefik at `rspamd.localhost`, which means requests arrive from a non-`secure_ip` origin and rspamd will refuse them without a configured password. Without this file the UI cannot be used.

```ucl
# conf/rspamd/local.d/worker-controller.inc
# Controller worker — serves the rspamd web UI on port 11334.
# Password is required because Traefik proxies from a non-secure_ip origin.
# Generate a real hash for production: `rspamadm pw --encrypt`
password = "q1";
```

- [ ] **Step 3: Create the SpamAssassin plugin config**

```ucl
# conf/rspamd/local.d/spamassassin.conf
# Calls the spamassassin-app sidecar and incorporates its score into rspamd's total.
servers = "spamassassin-app:783";
timeout = 15s;
```

- [ ] **Step 4: Create the actions (score threshold) config**

```ucl
# conf/rspamd/local.d/actions.conf
# Conservative thresholds for Freegle's mail mix.
# add_header: borderline mail passes with X-Rspamd-* headers for Laravel to read.
# rewrite_subject: prepend [SPAM] on high-score mail that still passes.
# reject: definite spam — return SMTP 5xx before entering Postfix queue.
# Tune these after reviewing score distribution in the rspamd web UI (rspamd.localhost).
actions {
  add_header = 5;
  rewrite_subject = 8;
  reject = 15;
}
```

- [ ] **Step 5: Verify rspamd container can find the new files**

```bash
# Replace 'freegle-' with your COMPOSE_PROJECT_NAME prefix if it isn't the default,
# or use `docker-compose restart rspamd` which is project-name-agnostic.
docker-compose restart rspamd
docker-compose logs --tail=20 rspamd
```

Expected: no config parse errors. rspamd starts and binds port 11334 (HTTP) and 11332 (milter).

- [ ] **Step 6: Commit**

```bash
git add conf/rspamd/local.d/worker-proxy.inc \
        conf/rspamd/local.d/worker-controller.inc \
        conf/rspamd/local.d/spamassassin.conf \
        conf/rspamd/local.d/actions.conf
git commit -m "feat(rspamd): add milter worker, controller password, and spamassassin plugin config"
```

---

### Task 2: Wire Postfix milter and update docker-compose

**Files:**
- Modify: `conf/postfix/main.cf`
- Modify: `docker-compose.yml`

- [ ] **Step 1: Add milter directives to Postfix main.cf**

Add these lines at the end of `conf/postfix/main.cf`:

```
# Rspamd milter — scores every incoming SMTP connection.
# milter_default_action = accept means mail flows normally if rspamd is unreachable.
smtpd_milters = inet:rspamd:11332
non_smtpd_milters = inet:rspamd:11332
milter_default_action = accept
milter_protocol = 6
```

- [ ] **Step 2: (Optional) Document the milter port in docker-compose.yml**

The rspamd image already exposes 11332 on the docker network, so this is documentation only — it has no runtime effect. Skip if you'd rather not touch compose for a no-op.

```yaml
  rspamd:
    # ... existing config ...
    expose:
      - "11334"   # HTTP API (already accessible)
      - "11332"   # milter (Postfix connects internally; image already exposes this)
```

In the `batch-prod` service environment block, add (these duplicate `config/freegle.php` defaults but make the wiring explicit):

```yaml
      - RSPAMD_HOST=rspamd
      - RSPAMD_PORT=11334
      - SPAM_CHECK_ENABLED=false
```

`SPAM_CHECK_ENABLED=false` explicitly disables the dormant outgoing-mail SpamCheckListener so it doesn't accidentally run.

- [ ] **Step 3: Rebuild and restart Postfix and rspamd**

`main.cf` is `COPY`'d into the postfix image at build time, so postfix needs a rebuild. Ensure the `mail` profile is active (`COMPOSE_PROFILES` includes `mail`) before running these.

```bash
docker-compose build postfix
docker-compose up -d postfix rspamd
docker-compose logs --tail=20 postfix
docker-compose logs --tail=20 rspamd
```

Expected: Postfix starts with no milter errors. rspamd binds port 11332 in addition to 11334.

- [ ] **Step 4: Smoke-test the milter connection**

`virtual_mailbox_domains` (groups.ilovefreegle.org, users.ilovefreegle.org, etc.) are routed via `transport_maps` to the `freegle-mail-handler` pipe, which `POST`s the message to `batch-prod:8080/api/mail/incoming` — **not to mailpit.** To see the milter-added headers, either inspect what batch-prod receives, or send to a non-virtual address that mailpit captures, or read the rspamd web UI history.

```bash
# Send a test message through Postfix
docker-compose exec -T postfix sh -c \
  'printf "Subject: Test\n\nHello world\n" | sendmail -f test@example.com test@groups.ilovefreegle.org'

# Verify rspamd scored it
docker-compose logs --tail=50 rspamd | grep -E 'msg_id|action'
# Or open http://rspamd.localhost and check the History tab.
```

Expected: rspamd logs show the message scored with an action (`no action`, `add header`, or `reject`).

- [ ] **Step 5: Commit**

```bash
git add conf/postfix/main.cf docker-compose.yml
git commit -m "feat(rspamd): wire milter into Postfix and expose port in docker-compose"
```

---

### Task 3: Rename SpamCheckService → RspamdService and update listener

**Context:** Two `SpamCheckService` classes exist today and the rename targets only the dormant one:

- `App\Services\Mail\Incoming\SpamCheckService` — **active**; full REASON_* constants, used by the incoming-mail pipeline, has its own test suite. **Do not touch.**
- `App\Services\SpamCheck\SpamCheckService` — **dormant**; only consumer is `App\Listeners\SpamCheckListener`. This is the one we rename.

**Files:**
- Rename + modify: `iznik-batch/app/Services/SpamCheck/SpamCheckService.php` → `RspamdService.php`
- Modify: `iznik-batch/app/Listeners/SpamCheckListener.php`

- [ ] **Step 1: Write the failing test for the rename**

Create `iznik-batch/tests/Unit/Services/SpamCheck/RspamdServiceTest.php`:

```php
<?php

namespace Tests\Unit\Services\SpamCheck;

use App\Services\SpamCheck\RspamdService;
use Tests\TestCase;

class RspamdServiceTest extends TestCase
{
    public function test_class_exists_under_new_name(): void
    {
        $this->assertTrue(class_exists(RspamdService::class));
    }

    public function test_has_check_rspamd_method(): void
    {
        $service = new RspamdService();
        $this->assertTrue(method_exists($service, 'checkRspamd'));
    }

    public function test_has_check_all_method(): void
    {
        $service = new RspamdService();
        $this->assertTrue(method_exists($service, 'checkAll'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker-compose exec batch-prod php artisan test --filter=RspamdServiceTest
```

Expected: FAIL — `class App\Services\SpamCheck\RspamdService not found`

- [ ] **Step 3: Rename the file and update the class declaration**

```bash
git mv iznik-batch/app/Services/SpamCheck/SpamCheckService.php \
       iznik-batch/app/Services/SpamCheck/RspamdService.php
```

In `iznik-batch/app/Services/SpamCheck/RspamdService.php`, change the class declaration:

```php
// Before:
class SpamCheckService

// After:
class RspamdService
```

- [ ] **Step 4: Update the listener to use the new class name**

In `iznik-batch/app/Listeners/SpamCheckListener.php`, update the import and constructor:

```php
// Before:
use App\Services\SpamCheck\SpamCheckService;
// ...
private SpamCheckService $spamChecker;
public function __construct(SpamCheckService $spamChecker) { $this->spamChecker = $spamChecker; }

// After:
use App\Services\SpamCheck\RspamdService;
// ...
private RspamdService $spamChecker;
public function __construct(RspamdService $spamChecker) { $this->spamChecker = $spamChecker; }
```

Also update the `SpamCheckService::isEnabled()` call inside `handle()` to `RspamdService::isEnabled()`.

- [ ] **Step 5: Run test to verify it passes**

```bash
docker-compose exec batch-prod php artisan test --filter=RspamdServiceTest
```

Expected: PASS — 3 tests

- [ ] **Step 6: Run the full batch test suite to check for regressions**

```bash
docker-compose exec batch-prod php artisan test --testsuite=Unit,Feature
```

Expected: all tests pass. In particular, `Tests\Unit\Services\Mail\Incoming\SpamCheckServiceTest` (the **other**, unrelated SpamCheckService) must continue to pass — that is the active class and we have not touched it.

- [ ] **Step 7: Commit**

```bash
git add iznik-batch/app/Services/SpamCheck/RspamdService.php \
        iznik-batch/app/Listeners/SpamCheckListener.php \
        iznik-batch/tests/Unit/Services/SpamCheck/RspamdServiceTest.php
git commit -m "refactor(batch): rename SpamCheckService to RspamdService to avoid naming conflict with incoming-mail class"
```

---

### Task 4: Verify end-to-end in dev and document rspamd tuning

**Files:**
- No new files — observation and documentation task

- [ ] **Step 1: Open the rspamd web UI**

Navigate to `http://rspamd.localhost` in a browser. Log in with the password set in `worker-controller.inc` (Task 1 Step 2 — default `q1` for dev only).

Check that:
- The "Throughput" graph shows activity (messages scored)
- The "Symbols" tab shows rules firing on test messages
- The "History" tab lists smoke-test messages from Task 2

- [ ] **Step 2: Send a GTUBE spam test**

The GTUBE string is the standard spam test payload (like EICAR for AV). rspamd scores GTUBE around 1000, well above any reject threshold. Send a message containing it and verify rspamd rejects it:

```bash
GTUBE='XJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X'
docker-compose exec -T postfix sh -c \
  "printf 'Subject: Test spam\n\n${GTUBE}\n' | sendmail -f test@example.com test@groups.ilovefreegle.org"
```

Expected: Postfix log shows `milter-reject` with a 5xx code. Message does not reach the freegle pipe / batch handler.

- [ ] **Step 3: Verify clean mail still passes**

```bash
docker-compose exec -T postfix sh -c \
  "printf 'Subject: Free sofa\n\nOak sofa, good condition, collection from Didsbury.\n' | \
   sendmail -f user@example.com group@groups.ilovefreegle.org"
```

Expected: Message reaches the freegle pipe (visible in batch-prod logs), with `X-Rspamd-Score` header showing a low score (< 5). Also visible in rspamd's History tab.

- [ ] **Step 4: Commit final integration notes to a new RSPAMD.md**

```bash
cat > docs/RSPAMD.md << 'EOF'
# Rspamd Integration

## What it does
Rspamd sits as a Postfix milter on port 11332. Every incoming SMTP connection is scored.
Definite spam (score ≥ 15) is rejected with SMTP 5xx before entering the Postfix queue.
Borderline mail (score ≥ 5) passes with X-Rspamd-* headers added.

## Web UI
http://rspamd.localhost — password set in conf/rspamd/local.d/worker-controller.inc
(default `q1` for dev; replace with a `rspamadm pw --encrypt` hash for production).

## Threshold tuning
After a few weeks of production traffic, review score distributions in the web UI and adjust
conf/rspamd/local.d/actions.conf. Start conservative (reject=15) and lower gradually.

## SpamAssassin
rspamd calls spamassassin-app:783 and incorporates its score.
The direct SPAMD_HOST call in the incoming-mail Laravel service can be removed once
rspamd milter scores prove reliable.

## Fallback
milter_default_action = accept — if rspamd is unreachable, Postfix accepts mail normally.

## Where milter-modified mail goes
Mail to `groups.ilovefreegle.org`, `users.ilovefreegle.org`, etc. is routed via
transport_maps to the `freegle-mail-handler` pipe, which POSTs the (now milter-decorated)
message to `batch-prod:8080/api/mail/incoming`. It does NOT go to mailpit. Inspect
batch-prod logs or the rspamd History tab to verify headers/scores.
EOF
git add docs/RSPAMD.md
git commit -m "docs: add rspamd integration reference"
```
