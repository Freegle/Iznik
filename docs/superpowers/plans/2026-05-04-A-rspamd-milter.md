# Rspamd Milter Integration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire rspamd into the Postfix mail pipeline as a milter so incoming spam is rejected at SMTP level, and resolve the two-class naming confusion in iznik-batch.

**Architecture:** Postfix calls rspamd on every incoming SMTP connection via the milter protocol (port 11332). Rspamd scores the message using its own ML rules plus SpamAssassin scores, adds `X-Rspamd-*` headers to clean mail, and rejects definite spam with a 5xx code before it enters the Postfix queue. The `milter_default_action = accept` ensures mail flows normally if rspamd is down. The dormant `App\Services\SpamCheck\SpamCheckService` is renamed to `RspamdService` to eliminate ambiguity with the incoming-mail class of the same name.

**Tech Stack:** Postfix (Alpine), rspamd UCL config, PHP 8.3 / Laravel 12, Docker Compose

**Spec:** `docs/superpowers/specs/2026-05-04-post-review-service-design.md` — Section 11

---

## File Map

| Action | Path |
|---|---|
| Modify | `conf/postfix/main.cf` |
| Create | `conf/rspamd/local.d/worker-proxy.inc` |
| Create | `conf/rspamd/local.d/spamassassin.conf` |
| Create | `conf/rspamd/local.d/actions.conf` |
| Modify | `docker-compose.yml` — rspamd and batch-prod env vars |
| Rename + modify | `iznik-batch/app/Services/SpamCheck/SpamCheckService.php` → `RspamdService.php` |
| Modify | `iznik-batch/app/Listeners/SpamCheckListener.php` |
| Create | `iznik-batch/tests/Unit/Services/SpamCheck/RspamdServiceTest.php` |

---

### Task 1: Add rspamd milter and SpamAssassin plugin config files

**Files:**
- Create: `conf/rspamd/local.d/worker-proxy.inc`
- Create: `conf/rspamd/local.d/spamassassin.conf`
- Create: `conf/rspamd/local.d/actions.conf`

- [ ] **Step 1: Create the milter worker config**

```ucl
# conf/rspamd/local.d/worker-proxy.inc
# Milter worker — receives connections from Postfix on port 11332.
# rspamd scores each message and returns a milter response.
bind_socket = "rspamd:11332";
```

- [ ] **Step 2: Create the SpamAssassin plugin config**

```ucl
# conf/rspamd/local.d/spamassassin.conf
# Calls the spamassassin-app sidecar and incorporates its score into rspamd's total.
servers = "spamassassin-app:783";
timeout = 15s;
```

- [ ] **Step 3: Create the actions (score threshold) config**

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

- [ ] **Step 4: Verify rspamd container can find the new files**

```bash
docker restart freegle-rspamd
docker logs freegle-rspamd 2>&1 | tail -20
```

Expected: no config parse errors. rspamd starts and binds port 11334 (HTTP) as before. Port 11332 (milter) will be bound once docker-compose exposes it (Task 2).

- [ ] **Step 5: Commit**

```bash
git add conf/rspamd/local.d/worker-proxy.inc conf/rspamd/local.d/spamassassin.conf conf/rspamd/local.d/actions.conf
git commit -m "feat(rspamd): add milter worker and spamassassin plugin config"
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

- [ ] **Step 2: Expose milter port and set env vars in docker-compose.yml**

In the `rspamd` service section, add port 11332 to the internal network (no external exposure needed):

```yaml
  rspamd:
    # ... existing config ...
    expose:
      - "11334"   # HTTP API (already accessible)
      - "11332"   # milter (Postfix connects internally)
```

In the `batch-prod` service environment block, add:

```yaml
      - RSPAMD_HOST=rspamd
      - RSPAMD_PORT=11334
      - SPAM_CHECK_ENABLED=false
```

`SPAM_CHECK_ENABLED=false` explicitly disables the dormant outgoing-mail SpamCheckListener so it doesn't accidentally run.

- [ ] **Step 3: Rebuild and restart Postfix and rspamd**

```bash
docker-compose build postfix
docker-compose up -d postfix rspamd
docker logs freegle-postfix 2>&1 | tail -20
docker logs freegle-rspamd 2>&1 | tail -20
```

Expected: Postfix starts with no milter errors. rspamd binds port 11332 in addition to 11334.

- [ ] **Step 4: Smoke-test the milter connection**

```bash
# Send a test message through Postfix and verify rspamd headers appear
echo "Subject: Test\n\nHello world" | docker exec -i freegle-postfix sendmail -f test@example.com test@groups.ilovefreegle.org
# Check mailpit for the test message and look for X-Rspamd-* headers
```

Expected: the received message in mailpit has `X-Rspamd-Score`, `X-Rspamd-Action`, and `X-Rspamd-Symbols` headers.

- [ ] **Step 5: Commit**

```bash
git add conf/postfix/main.cf docker-compose.yml
git commit -m "feat(rspamd): wire milter into Postfix and expose port in docker-compose"
```

---

### Task 3: Rename SpamCheckService → RspamdService and update listener

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
docker exec freegle-batch php artisan test --filter=RspamdServiceTest
```

Expected: FAIL — `class App\Services\SpamCheck\RspamdService not found`

- [ ] **Step 3: Rename the file and update the class declaration**

```bash
mv iznik-batch/app/Services/SpamCheck/SpamCheckService.php \
   iznik-batch/app/Services/SpamCheck/RspamdService.php
```

In `iznik-batch/app/Services/SpamCheck/RspamdService.php`, change line 11:

```php
// Before:
class SpamCheckService

// After:
class RspamdService
```

- [ ] **Step 4: Update the listener to use the new class name**

In `iznik-batch/app/Listeners/SpamCheckListener.php`, update the import and type hint:

```php
// Before:
use App\Services\SpamCheck\SpamCheckService;
// ...
public function __construct(private readonly SpamCheckService $spamChecker) {}

// After:
use App\Services\SpamCheck\RspamdService;
// ...
public function __construct(private readonly RspamdService $spamChecker) {}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
docker exec freegle-batch php artisan test --filter=RspamdServiceTest
```

Expected: PASS — 3 tests

- [ ] **Step 6: Run the full batch test suite to check for regressions**

```bash
docker exec freegle-batch php artisan test --testsuite=Unit,Feature
```

Expected: all tests pass

- [ ] **Step 7: Commit**

```bash
git add iznik-batch/app/Services/SpamCheck/RspamdService.php \
        iznik-batch/app/Listeners/SpamCheckListener.php \
        iznik-batch/tests/Unit/Services/SpamCheck/RspamdServiceTest.php
git rm iznik-batch/app/Services/SpamCheck/SpamCheckService.php
git commit -m "refactor(batch): rename SpamCheckService to RspamdService to avoid naming conflict with incoming-mail class"
```

---

### Task 4: Verify end-to-end in dev and document rspamd tuning

**Files:**
- No new files — observation and documentation task

- [ ] **Step 1: Open the rspamd web UI**

Navigate to `http://rspamd.localhost` in a browser. Log in with password `q1` (set in `worker-controller.inc`).

Check that:
- The "Throughput" graph shows activity (messages scored)
- The "Symbols" tab shows rules firing on test messages

- [ ] **Step 2: Send a GTUBE spam test**

The GTUBE string is the standard spam test payload (like EICAR for AV). Send a message containing it through Postfix and verify rspamd rejects it:

```bash
GTUBE="XJS*C4JDBQADN1.NSBN3*2IDNEN*GTUBE-STANDARD-ANTI-UBE-TEST-EMAIL*C.34X"
echo "Subject: Test spam\n\n${GTUBE}" | \
  docker exec -i freegle-postfix sendmail -f test@example.com test@groups.ilovefreegle.org
```

Expected: Postfix log shows `milter-reject` with a 5xx code. Message does not appear in mailpit.

- [ ] **Step 3: Verify clean mail still passes**

```bash
echo "Subject: Free sofa\n\nOak sofa, good condition, collection from Didsbury." | \
  docker exec -i freegle-postfix sendmail -f user@example.com group@groups.ilovefreegle.org
```

Expected: Message appears in mailpit with `X-Rspamd-Score` header showing a low score (< 5).

- [ ] **Step 4: Commit final integration notes to SENTRY-INTEGRATION.md or a new RSPAMD.md**

```bash
cat > docs/RSPAMD.md << 'EOF'
# Rspamd Integration

## What it does
Rspamd sits as a Postfix milter on port 11332. Every incoming SMTP connection is scored.
Definite spam (score ≥ 15) is rejected with SMTP 5xx before entering the Postfix queue.
Borderline mail (score ≥ 5) passes with X-Rspamd-* headers added.

## Web UI
http://rspamd.localhost — password: q1 (set in conf/rspamd/local.d/worker-controller.inc)

## Threshold tuning
After a few weeks of production traffic, review score distributions in the web UI and adjust
conf/rspamd/local.d/actions.conf. Start conservative (reject=15) and lower gradually.

## SpamAssassin
rspamd calls spamassassin-app:783 and incorporates its score.
The direct SPAMD_HOST call in the incoming-mail Laravel service can be removed once
rspamd milter scores prove reliable.

## Fallback
milter_default_action = accept — if rspamd is unreachable, Postfix accepts mail normally.
EOF
git add docs/RSPAMD.md
git commit -m "docs: add rspamd integration reference"
```
