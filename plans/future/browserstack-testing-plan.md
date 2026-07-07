# BrowserStack Automated Testing for Freegle

## Your Sponsored Plan (Confirmed)

Per [BrowserStack Open Source Program](https://www.browserstack.com/open-source):
- **All products included**: Live, Automate, App Live, App Automate, **Percy**
- **5 users and 5 parallels**
- **Lifetime access**

---

## Fully Automated Options with Pass/Fail

### Option 1: Percy Visual Testing (Recommended)

**What**: AI-powered visual regression that automatically blocks PRs on visual changes.

**How it works**:
1. Takes DOM snapshots on every commit/PR
2. Compares against baseline (master branch)
3. AI filters out noise (animations, dynamic content, anti-aliasing)
4. **Automatically updates GitHub PR status** - blocks merge until approved
5. Highlights exact pixel differences

**Pass/Fail behavior**:
- PR status = "Pending" when visual changes detected
- PR status = "Approved" when changes reviewed
- PR status = "Changes Requested" blocks merge
- CI build can be configured to fail on unreviewed changes

**Responsive testing**: Specify up to 10 breakpoint widths per snapshot.

**Setup time**: ~30 minutes

---

### Option 2: Low Code Automation (No Coding Required)

**What**: Record tests in browser, AI maintains them automatically.

**Key AI features**:
- **Self-healing**: Automatically updates tests when UI elements change
- **Flaky test detection**: Identifies unstable tests
- **Root cause analysis**: AI explains why tests failed
- **Natural language**: Write tests in plain English

**Pass/Fail**: Tests pass or fail like traditional automation, with AI reducing false failures by 40%.

**Best for**:
- Quick test creation without Playwright knowledge
- Non-technical team members creating tests
- Rapid coverage of critical user flows

**Setup time**: ~15 minutes to record first test

---

### Option 3: Percy + Playwright (Maximum Coverage)

Combine your existing Playwright tests with Percy snapshots for both functional AND visual testing.

**Pass/Fail**:
- Playwright assertions fail the build (buttons missing, elements not clickable)
- Percy changes require review before merge

---

## Recommended Implementation: Percy

Given your goals (button rendering, scroll flickering, automated pass/fail), Percy is the best fit.

### Step 1: Create Percy Project

1. Go to https://percy.io
2. Sign in with BrowserStack credentials
3. Create new Web project: "Freegle"
4. Copy the `PERCY_TOKEN`

### Step 2: Install Percy

```bash
cd iznik-nuxt3
npm install -D @percy/cli @percy/playwright
```

### Step 3: Create Percy Test File

```javascript
// tests/e2e/percy-visual.spec.js
const { test } = require('@playwright/test')
const percySnapshot = require('@percy/playwright')

// Responsive breakpoints to test
const WIDTHS = [375, 768, 1024, 1440]

// Public pages (no login required)
const PUBLIC_PAGES = [
  { name: 'Homepage', path: '/' },
  { name: 'Explore', path: '/explore' },
  { name: 'Give', path: '/give' },
  { name: 'Find', path: '/find' },
  { name: 'Stories', path: '/stories' },
  { name: 'Stats', path: '/stats' },
]

test.describe('Percy Visual Tests - Public Pages', () => {
  for (const page of PUBLIC_PAGES) {
    test(`Visual: ${page.name}`, async ({ page: p }) => {
      await p.goto(page.path, { waitUntil: 'networkidle' })

      // Percy captures at all specified widths automatically
      await percySnapshot(p, page.name, {
        widths: WIDTHS,
        minHeight: 1024,
        enableJavaScript: true,
      })
    })
  }
})

test.describe('Percy Visual Tests - Scroll States', () => {
  test('Browse page - scrolled state', async ({ page }) => {
    await page.goto('/browse', { waitUntil: 'networkidle' })

    // Capture initial state
    await percySnapshot(page, 'Browse - Initial', { widths: WIDTHS })

    // Scroll down and capture
    await page.evaluate(() => window.scrollTo(0, 1000))
    await page.waitForTimeout(500) // Allow scroll to settle

    await percySnapshot(page, 'Browse - Scrolled', { widths: WIDTHS })
  })

  test('Explore page - after interaction', async ({ page }) => {
    await page.goto('/explore', { waitUntil: 'networkidle' })

    await percySnapshot(page, 'Explore - Initial', { widths: WIDTHS })

    // Scroll to load more content
    await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight))
    await page.waitForTimeout(1000)

    await percySnapshot(page, 'Explore - Bottom', { widths: WIDTHS })
  })
})

test.describe('Percy Visual Tests - Logged In (Test User)', () => {
  test.beforeEach(async ({ page }) => {
    // Login with test user for read-only browsing
    await page.goto('/')

    // Click login
    const loginLink = page.locator('a:has-text("Log in"), button:has-text("Log in")').first()
    await loginLink.click()

    // Fill credentials
    await page.fill('input[type="email"]', process.env.TEST_USER_EMAIL)
    await page.fill('input[type="password"]', process.env.TEST_USER_PASSWORD)
    await page.click('button:has-text("Log in")')

    // Wait for login to complete
    await page.waitForURL('**/browse**', { timeout: 30000 })
  })

  test('Browse page - logged in', async ({ page }) => {
    await page.goto('/browse', { waitUntil: 'networkidle' })
    await percySnapshot(page, 'Browse - Logged In', { widths: WIDTHS })
  })

  test('My Posts page', async ({ page }) => {
    await page.goto('/myposts', { waitUntil: 'networkidle' })
    await percySnapshot(page, 'My Posts', { widths: WIDTHS })
  })

  test('Settings page', async ({ page }) => {
    await page.goto('/settings', { waitUntil: 'networkidle' })
    await percySnapshot(page, 'Settings', { widths: WIDTHS })
  })

  test('Chats page', async ({ page }) => {
    await page.goto('/chats', { waitUntil: 'networkidle' })
    await percySnapshot(page, 'Chats', { widths: WIDTHS })
  })
})
```

### Step 4: Run Percy Locally

```bash
# Set token
export PERCY_TOKEN=your_token_here

# Run Percy tests
npx percy exec -- npx playwright test tests/e2e/percy-visual.spec.js
```

### Step 5: CircleCI Integration

Add to `.circleci/config.yml`:

```yaml
  percy-visual-tests:
    docker:
      - image: mcr.microsoft.com/playwright:v1.40.0-focal
    resource_class: medium
    steps:
      - checkout
      - run:
          name: Install dependencies
          command: |
            cd iznik-nuxt3
            npm ci
      - run:
          name: Run Percy Visual Tests
          command: |
            cd iznik-nuxt3
            npx percy exec -- npx playwright test tests/e2e/percy-visual.spec.js
          environment:
            PERCY_TOKEN: ${PERCY_TOKEN}
            TEST_USER_EMAIL: ${TEST_USER_EMAIL}
            TEST_USER_PASSWORD: ${TEST_USER_PASSWORD}
            TEST_BASE_URL: https://www.ilovefreegle.org

workflows:
  # Run on every PR
  pr-visual-tests:
    jobs:
      - percy-visual-tests:
          filters:
            branches:
              ignore: master

  # Run nightly on master to update baselines
  nightly-baseline:
    triggers:
      - schedule:
          cron: "0 3 * * *"
          filters:
            branches:
              only: master
    jobs:
      - percy-visual-tests
```

### Step 6: GitHub Integration

1. In Percy dashboard: Settings > Integrations > GitHub
2. Connect your repo
3. Percy will automatically:
   - Comment on PRs with visual diffs
   - Block merge until changes approved
   - Update commit status

---

## What Percy Catches Automatically

| Issue | How Percy Detects It |
|-------|---------------------|
| **Missing buttons** | Baseline shows button, new snapshot doesn't = visual diff |
| **Wrong button size** | Pixel difference highlighted in diff |
| **Layout shift on scroll** | Scrolled snapshot differs from expected |
| **Responsive breakage** | 375px width shows broken layout vs baseline |
| **CSS regression** | Any style change creates visual diff |
| **Flickering** | Inconsistent renders flagged as unstable |

---

## Low Code Automation (Alternative/Supplement)

If you want to add functional tests without coding:

### Setup

1. Go to https://www.browserstack.com/low-code-automation
2. Install Chrome extension
3. Record test by browsing the site

### Example: Button Click Test

1. Click "Record"
2. Navigate to https://www.ilovefreegle.org
3. Click buttons you want to test
4. Stop recording
5. Test auto-generates with assertions

### AI Features

- **Self-healing**: Button selector changes? AI finds new selector
- **Flaky detection**: Test fails intermittently? Flagged automatically
- **Root cause**: "Button not found because modal overlay blocking"

---

## Test Observability (Included)

Once tests are running on BrowserStack, you get:

- **AI failure categorization**: Product bug vs environment issue vs flaky test
- **Automatic flaky test tagging**: Tests that flip pass/fail >50% of runs
- **Timeline debugging**: Every log in one view
- **Smart retries**: Automatically re-run suspected flaky tests

---

## Implementation Timeline

### Day 1: Percy Setup
1. Create Percy project
2. Install dependencies
3. Create `percy-visual.spec.js`
4. Run locally to establish baseline

### Day 2: CI Integration
1. Add `PERCY_TOKEN` to CircleCI environment variables
2. Add Percy job to CircleCI config
3. Connect GitHub integration
4. Submit test PR to verify pass/fail

### Day 3: Expand Coverage
1. Add more pages to visual tests
2. Add scroll state captures
3. Add logged-in user tests
4. Review first visual diffs

### Ongoing
- Review Percy diffs on PRs
- Approve intentional changes
- Flag regressions for fix

---

## Estimated Percy Usage

With 5,000 free screenshots/month:

| Test Suite | Snapshots | Widths | Total/Run |
|------------|-----------|--------|-----------|
| Public pages (6) | 6 | 4 | 24 |
| Scroll states (2) | 4 | 4 | 16 |
| Logged in (4) | 4 | 4 | 16 |
| **Total per run** | | | **56** |

At 56 snapshots/run:
- **Daily runs**: 56 × 30 = 1,680/month
- **PR runs** (est. 20/month): 56 × 20 = 1,120/month
- **Total**: ~2,800/month (well within 5,000 limit)

---

## Pass/Fail Summary

| Tool | Pass Condition | Fail Condition | Auto-block PR |
|------|---------------|----------------|---------------|
| **Percy** | No visual changes OR all changes approved | Unreviewed visual changes | Yes |
| **Low Code** | All recorded steps succeed | Step fails (element not found, assertion fails) | Yes |
| **Test Observability** | N/A (analytics only) | N/A | No |

---

## Quick Start Commands

```bash
# Install Percy
cd iznik-nuxt3
npm install -D @percy/cli @percy/playwright

# Create test file
# (copy percy-visual.spec.js from above)

# Run locally
export PERCY_TOKEN=your_token
npx percy exec -- npx playwright test tests/e2e/percy-visual.spec.js

# View results
# Open link shown in terminal output
```

---

## References

- [Percy Visual Testing](https://www.browserstack.com/percy) - AI-powered visual regression
- [Percy Playwright Integration](https://www.browserstack.com/docs/percy/integrate/playwright)
- [Low Code Automation](https://www.browserstack.com/low-code-automation) - No-code test creation
- [Test Observability](https://www.browserstack.com/test-observability) - AI failure analysis
- [BrowserStack Open Source Program](https://www.browserstack.com/open-source)
