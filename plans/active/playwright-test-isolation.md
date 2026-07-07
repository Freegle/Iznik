# Playwright Test Isolation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make each Playwright test file use its own unique group and moderator so tests can run in parallel without interference.

**Architecture:** A PHP test data factory script creates isolated environments (group + users + messages) on demand. A Playwright fixture calls it via `docker exec` before each test file, injecting unique group/user data into tests. Tests reference fixture data instead of hardcoded `FreeglePlayground` / `testmod@test.com`.

**Tech Stack:** PHP (test data factory), Playwright fixtures, `child_process.execSync` for docker exec calls.

---

## Current State

- **28 test files**, all sharing `FreeglePlayground` group and `testmod@test.com` moderator
- `playwright.config.js` has `workers: 1` and `fullyParallel: false` because parallel execution causes interference
- **13 tests modify shared state** (hold/release messages, approve/reject, move messages, post/withdraw)
- `config.js` already supports `TEST_GROUP` env var but it's global, not per-test
- New user emails are already unique per test (via `testEmail` fixture)
- `testenv.php` creates the shared environment once via `docker exec freegle-apiv1`

## Interference Sources

| Test File | State Changes | Risk |
|-----------|--------------|------|
| modtools-hold-release | 11 hold/release ops | HIGH - changes message state other tests check |
| modtools-move-message | 3 cross-group moves | HIGH - moves messages between groups |
| modtools-member-logs | 4 actions | MEDIUM - creates log entries |
| modtools-support | 2 approve/reject | MEDIUM |
| post-flow | 2 withdrawals | MEDIUM - removes messages from browse |
| post-validation | 3 ops | LOW |
| browse | posts + navigates | LOW - but relies on messages existing |
| reply-flow-* (5 files) | reply to messages | LOW - but needs messages to exist |

## Design

### Test Data Factory: `install/create-test-env.php`

A PHP script that takes a prefix and creates an isolated test environment:

```
docker exec freegle-apiv1 php /var/www/iznik/install/create-test-env.php <prefix>
```

Creates:
- Group: `PW_{prefix}` (e.g., `PW_holdrelease`)
- Second group: `PW_{prefix}_2` (for move-message tests)
- Moderator: `pw_{prefix}_mod@test.com` with password `freegle`
- Regular user: `pw_{prefix}_user@test.com` with password `freegle`
- Second regular user: `pw_{prefix}_user2@test.com` (for chats)
- 2 approved messages (OFFER + WANTED) with spatial data
- 2 pending messages (for modtools pending tests)
- Chat rooms (User2User, User2Mod)

Returns JSON on stdout:
```json
{
  "group": { "id": 123, "name": "PW_holdrelease" },
  "group2": { "id": 124, "name": "PW_holdrelease_2" },
  "mod": { "id": 456, "email": "pw_holdrelease_mod@test.com" },
  "user": { "id": 789, "email": "pw_holdrelease_user@test.com" },
  "user2": { "id": 790, "email": "pw_holdrelease_user2@test.com" },
  "messages": { "offer": 101, "wanted": 102 },
  "pending": { "offer": 103, "wanted": 104 },
  "chats": { "user2user": 201, "user2mod": 202 }
}
```

**Idempotent**: If group `PW_{prefix}` already exists, looks up existing data and returns it. No duplicate creation.

### Playwright Fixture: `testEnv`

Added to `fixtures.js`:

```javascript
testEnv: [async ({}, use, testInfo) => {
  // Derive prefix from test file name: test-modtools-hold-release.spec.js -> holdrelease
  const prefix = path.basename(testInfo.file, '.spec.js')
    .replace('test-', '').replace('test-modtools-', 'mt').replace(/-/g, '')

  const { execSync } = require('child_process')
  const result = execSync(
    `docker exec freegle-apiv1 php /var/www/iznik/install/create-test-env.php ${prefix}`,
    { encoding: 'utf-8', timeout: 30000 }
  )
  const env = JSON.parse(result.trim())
  await use(env)
}, { scope: 'worker' }]
```

`scope: 'worker'` means each worker creates its test env once, shared across all tests in that worker. Since each file runs in its own worker when parallel, each file gets its own isolated environment.

### Test File Changes

Each test file destructures `testEnv` alongside existing fixtures:

```javascript
// Before:
test('can hold a message', async ({ page }) => {
  await loginViaModTools(page, 'testmod@test.com')
  // ... uses FreeglePlayground
})

// After:
test('can hold a message', async ({ page, testEnv }) => {
  await loginViaModTools(page, testEnv.mod.email)
  // ... uses testEnv.group.name
})
```

### Parallel Execution

After all tests use isolated data:

```javascript
// playwright.config.js
workers: process.env.CI ? 2 : 4,  // Multiple workers
fullyParallel: false,  // Keep tests within a file sequential
```

---

## Chunk 1: Test Data Factory

### Task 1: Create `install/create-test-env.php`

**Files:**
- Create: `iznik-server/install/create-test-env.php`

This script reuses the same PHP classes as `testenv.php` (Group, User, ChatRoom, MailRouter, etc.) but is parameterized by prefix.

- [ ] **Step 1: Write the factory script**

```php
<?php
namespace Freegle\Iznik;

require_once dirname(__FILE__) . '/../include/config.php';
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

$prefix = $argv[1] ?? null;
if (!$prefix) {
    fwrite(STDERR, "Usage: create-test-env.php <prefix>\n");
    exit(1);
}

$groupName = "PW_$prefix";
$groupName2 = "PW_{$prefix}_2";
$modEmail = "pw_{$prefix}_mod@test.com";
$userEmail = "pw_{$prefix}_user@test.com";
$user2Email = "pw_{$prefix}_user2@test.com";

$result = [];

// Create or find group
$g = new Group($dbhr, $dbhm);
$gid = $g->findByShortName($groupName);

if (!$gid) {
    $gid = $g->create($groupName, Group::GROUP_FREEGLE);
    $g->setPrivate('onhere', 1);
    $g->setPrivate('polyofficial', '<edinburgh polygon>');
    $g->setPrivate('lat', 55.9533);
    $g->setPrivate('lng', -3.1883);
}

// Create second group
$g2 = new Group($dbhr, $dbhm);
$gid2 = $g2->findByShortName($groupName2);
if (!$gid2) {
    $gid2 = $g2->create($groupName2, Group::GROUP_FREEGLE);
    $g2->setPrivate('onhere', 1);
}

// Create location
$l = new Location($dbhr, $dbhm);
$pcRows = $dbhr->preQuery("SELECT id FROM locations WHERE name = 'EH3 6SS' LIMIT 1");
$pcid = $pcRows ? $pcRows[0]['id'] : null;

// Create moderator
$u = new User($dbhr, $dbhm);
$modUid = $u->findByEmail($modEmail);
if (!$modUid) {
    $modUid = $u->create('PW', 'Mod', "PW Mod $prefix");
    $u->addEmail($modEmail);
    $u->addLogin(User::LOGIN_NATIVE, NULL, 'freegle');
    $u->setPrivate('systemrole', 'Admin');
}
$u->addMembership($gid, User::ROLE_MODERATOR);
$u->addMembership($gid2, User::ROLE_MODERATOR);

// Create regular users (similar pattern)
// ... (user1, user2)

// Create messages (2 approved, 2 pending)
// ... (reuse testenv.php message creation pattern)

// Create chat rooms
// ... (User2User, User2Mod)

// Output JSON
echo json_encode($result, JSON_PRETTY_PRINT);
```

The full implementation follows `testenv.php` patterns exactly.

- [ ] **Step 2: Test the factory locally**

```bash
docker exec freegle-apiv1 php /var/www/iznik/install/create-test-env.php testrun1
```

Expected: JSON output with all created IDs. Run twice to verify idempotency.

- [ ] **Step 3: Commit**

```bash
cd iznik-server && git add install/create-test-env.php
git commit -m "feat: Add parameterized test environment factory for Playwright isolation"
```

---

## Chunk 2: Playwright Fixture

### Task 2: Add `testEnv` fixture to `fixtures.js`

**Files:**
- Modify: `iznik-nuxt3/tests/e2e/fixtures.js`

- [ ] **Step 1: Add the testEnv fixture**

Add to the `test.extend({})` block:

```javascript
testEnv: [async ({}, use, testInfo) => {
  const { execSync } = require('child_process')
  // Derive prefix from filename: test-modtools-hold-release.spec.js -> mtholdrelease
  const filename = path.basename(testInfo.file, '.spec.js')
  const prefix = filename
    .replace('test-modtools-', 'mt')
    .replace('test-', '')
    .replace(/-/g, '')

  console.log(`Creating isolated test environment: PW_${prefix}`)

  const stdout = execSync(
    `docker exec freegle-apiv1 php /var/www/iznik/install/create-test-env.php ${prefix}`,
    { encoding: 'utf-8', timeout: 60000 }
  )

  const env = JSON.parse(stdout.trim())
  console.log(`Test environment ready: group=${env.group.name}, mod=${env.mod.email}`)

  await use(env)
}, { scope: 'worker' }]
```

- [ ] **Step 2: Verify fixture loads without breaking existing tests**

Run a single test to verify the fixture is available:

```bash
npx playwright test tests/e2e/test-homepage.spec.js
```

- [ ] **Step 3: Commit**

```bash
cd iznik-nuxt3 && git add tests/e2e/fixtures.js
git commit -m "feat: Add testEnv fixture for isolated Playwright test environments"
```

---

## Chunk 3: Update Login Utilities

### Task 3: Make login helpers accept dynamic credentials

**Files:**
- Modify: `iznik-nuxt3/tests/e2e/utils/user.js`

The `loginViaModTools` function already takes email and password parameters, so no changes needed there.

Add a helper to get moderator credentials from testEnv:

- [ ] **Step 1: Add `getModCredentials` helper**

```javascript
// In config.js or utils/user.js
const getModCredentials = (testEnv) => ({
  email: testEnv?.mod?.email || 'testmod@test.com',
  password: 'freegle',
})
```

This provides backward compatibility: if `testEnv` is not provided, falls back to the shared moderator.

- [ ] **Step 2: Commit**

---

## Chunk 4: Update ModTools Tests (10 files)

These are the highest priority because they modify shared state (hold/release, move messages, etc.).

### Task 4: Update modtools tests to use testEnv

**Files to modify** (in priority order based on interference risk):

1. `test-modtools-hold-release.spec.js` - HIGH (11 hold/release ops)
2. `test-modtools-move-message.spec.js` - HIGH (3 cross-group moves)
3. `test-modtools-pending-messages.spec.js` - MEDIUM
4. `test-modtools-member-logs.spec.js` - MEDIUM (4 actions)
5. `test-modtools-support.spec.js` - MEDIUM (2 approve/reject)
6. `test-modtools-dashboard.spec.js` - LOW (read-only)
7. `test-modtools-chat-list.spec.js` - LOW (read-only)
8. `test-modtools-edits.spec.js` - LOW (read-only)
9. `test-modtools-page-loads.spec.js` - LOW (read-only)
10. `test-modtools-login.spec.js` - LOW (login only)

**Pattern for each file:**

- [ ] **Step 1: Add `testEnv` to test destructuring**

```javascript
// Before:
test('test name', async ({ page }) => {
  await loginViaModTools(page, 'testmod@test.com')

// After:
test('test name', async ({ page, testEnv }) => {
  await loginViaModTools(page, testEnv.mod.email)
```

- [ ] **Step 2: Replace hardcoded group references**

```javascript
// Before:
await expect(page.getByText('FreeglePlayground')).toBeVisible()

// After:
await expect(page.getByText(testEnv.group.name)).toBeVisible()
```

- [ ] **Step 3: Run each file after changes**

```bash
npx playwright test tests/e2e/test-modtools-hold-release.spec.js
```

- [ ] **Step 4: Commit after each file passes**

---

## Chunk 5: Update Frontend Tests (browse, reply-flow, post)

### Task 5: Update browse and reply-flow tests

**Files to modify:**

1. `test-browse.spec.js` - Uses `signUpAndJoinGroup` with FreeglePlayground
2. `test-reply-flow-new-user.spec.js` - Replies to messages on FreeglePlayground
3. `test-reply-flow-existing-user.spec.js` - Same
4. `test-reply-flow-logged-in.spec.js` - Same
5. `test-reply-flow-edge-cases.spec.js` - Same
6. `test-reply-flow-social.spec.js` - Same
7. `test-reply-flow-logging.spec.js` - Same
8. `test-post-flow.spec.js` - Posts to FreeglePlayground
9. `test-post-validation.spec.js` - Validation on post form
10. `test-v2-api-pages.spec.js` - API page checks

**Pattern:**

```javascript
// Before: navigate to browse page for the shared group
await page.goto(`/explore/FreeglePlayground`)

// After: use the isolated group
await page.goto(`/explore/${testEnv.group.name}`)
```

For reply-flow tests that need existing messages to reply to, the `testEnv` fixture provides pre-created messages with known IDs.

- [ ] **Steps: Same pattern as Chunk 4** — add `testEnv`, replace hardcoded refs, test, commit.

---

## Chunk 6: Update Remaining Tests

### Task 6: Update low-risk tests

**Files:**
1. `test-homepage.spec.js` - No group refs, no changes needed
2. `test-pages.spec.js` - No group refs, no changes needed
3. `test-explore.spec.js` - May reference testgroup
4. `test-settings.spec.js` - User settings, may need isolated user
5. `test-user-ratings.spec.js` - Needs messages to rate
6. `test-ai-illustration.spec.js` - Signup tests, likely independent
7. `test-marketing-consent.spec.js` - Signup tests, likely independent
8. `test-register-unsubscribe.spec.js` - Account management

Many of these may not need changes if they don't reference shared groups/users.

- [ ] **Steps:** Review each, update if needed, test, commit.

---

## Chunk 7: Enable Parallel Execution

### Task 7: Update playwright.config.js for parallel workers

**Files:**
- Modify: `iznik-nuxt3/playwright.config.js`

- [ ] **Step 1: Increase worker count**

```javascript
// Before:
workers: 1,
fullyParallel: false,

// After:
workers: process.env.CI ? 2 : 4,
fullyParallel: false,  // Keep tests within a file sequential
```

- [ ] **Step 2: Run full test suite with multiple workers**

```bash
npx playwright test --workers=2
```

Verify no test interference. If any failures, debug and fix.

- [ ] **Step 3: Run full suite with workers=4 locally**

- [ ] **Step 4: Commit**

```bash
git commit -m "feat: Enable parallel Playwright execution with isolated test environments"
```

---

## Cleanup Considerations

**Option A: No cleanup** (recommended initially)
- Test groups/users accumulate but don't cause problems
- Idempotent factory means re-runs reuse existing data
- Simpler implementation

**Option B: Cleanup via afterAll**
- Add a cleanup script that removes test data for a given prefix
- Call in `afterAll` or `globalTeardown`
- More complex, risk of deleting data other tests need

**Recommendation:** Start with Option A. Add cleanup later if the test database grows too large.

## Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|------------|
| Factory script fails | Tests can't create env | Fall back to shared FreeglePlayground |
| Docker exec slow | Adds ~2s per test file | `scope: 'worker'` means once per file, not per test |
| Database fills up | Test data accumulates | Periodic manual cleanup; testenv.php already has this pattern |
| Some tests still interfere | Flaky failures | Identify and fix; the fixture makes isolation explicit |
| Reply-flow tests need browseable messages | Can't find messages | Factory creates approved messages with spatial data + search index |

## Execution Order

1. Factory script (Chunk 1) - foundation
2. Playwright fixture (Chunk 2) - integration layer
3. Login utilities (Chunk 3) - convenience
4. ModTools tests (Chunk 4) - highest interference risk
5. Frontend tests (Chunk 5) - medium risk
6. Remaining tests (Chunk 6) - low risk
7. Enable parallelism (Chunk 7) - final step
