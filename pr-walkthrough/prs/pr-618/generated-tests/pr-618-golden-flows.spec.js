// GOLDEN-FLOW regression tests generated from pr-618/capture-plan.json by
// pr-walkthrough/src/plan-to-playwright.mjs. These are the same flows the walkthrough
// video shows. Drop into iznik-nuxt3/tests/e2e/. Each test asserts the golden state
// reached (the controls the video highlights). Seeded ids/emails come from env
// (see env-from-testenvs.mjs / test-envs.json); TEST_BASE_URL sets the target.
const { test, expect } = require('@playwright/test')
const { loginViaHomepage, loginViaModTools } = require('./utils/user')

test.describe('pr-618 golden flows (from walkthrough)', () => {
  test('golden flow: clearance-composer', async ({ page }) => {
    await page.goto(`/give/clearance`, { waitUntil: 'networkidle' })
    await page.locator('[data-testid="clearance-title"]').first().fill(`Office Clearance`)
    await page.locator("textarea[placeholder^=\"e.g. Charity office clearance\"]").first().fill(`Charity office clearance — everything must go by Friday. Collection from central Brighton.`)
    await page.locator('[data-testid="mode-manual"]').first().click()
    await expect(page.locator('[data-testid="item-name-0"]').first()).toBeVisible()
    await page.locator('[data-testid="item-name-0"]').first().fill(`Filing cabinet`)
    await page.locator('[data-testid="item-qty-0"]').first().fill(`3`)
    await page.locator('[data-testid="item-condition-0"]').first().selectOption(`LikeNew`)
    await page.locator('[data-testid="add-item"]').first().click()
    await page.waitForTimeout(250)
    await page.locator('[data-testid="item-name-0"]').first().fill(`Swivel chair`)
    await page.locator('[data-testid="item-qty-0"]').first().fill(`14`)
    await page.locator('[data-testid="item-condition-0"]').first().selectOption(`Used`)
    await page.locator('[data-testid="add-item"]').first().click()
    await page.waitForTimeout(250)
    await page.locator('[data-testid="item-name-0"]').first().fill(`Office desk`)
    await page.locator('[data-testid="item-qty-0"]').first().fill(`4`)
    await page.locator('[data-testid="item-condition-0"]').first().selectOption(`Good`)
    await page.locator('[data-testid="slot-0"]').first().fill(`Wed 8 Apr, 10am–4pm`)
    await page.locator('[data-testid="add-slot"]').first().click()
    await page.waitForTimeout(300)
    await page.locator('[data-testid="slot-0"]').first().fill(`Tue 7 Apr, 10am–4pm`)
    await page.locator('[data-testid="clearance-access"]').first().fill(`Side gate by the loading bay; ask for reception.`)
    await page.waitForTimeout(400)
    await expect(page.locator("input[placeholder*=\"postcode\" i]").first()).toBeVisible() // Pick your area first
    await expect(page.locator('[data-testid="clearance-title"]').first()).toBeVisible() // Name the whole clearance
    await expect(page.locator('[data-testid="mode-manual"]').first()).toBeVisible() // Type them in — or paste a spreadsheet
    await expect(page.locator('[data-testid="item-qty-0"]').first()).toBeVisible() // How many
    await expect(page.locator('[data-testid="item-condition-0"]').first()).toBeVisible() // Condition
    await expect(page.getByText("things in total").first()).toBeVisible() // 3 items, 21 things
    await expect(page.locator('[data-testid="slot-0"]').first()).toBeVisible() // Offer set collection times
    await expect(page.locator('[data-testid="clearance-access"]').first()).toBeVisible() // Private access instructions
  })

  test('golden flow: recipient-interest', async ({ page }) => {
    await page.goto(`/message/${process.env.BULK_MSG_ID}`, { waitUntil: 'networkidle' })
    await page.locator("[data-testid^=\"pick-\"]").first().click()
    await expect(page.locator("[data-testid^=\"qty-\"]").first()).toBeVisible()
    await page.waitForTimeout(400)
    await expect(page.getByText("items in this offer").first()).toBeVisible() // A browsable catalogue in one offer
    await expect(page.locator("[data-testid^=\"pick-\"]").first()).toBeVisible() // A clear toggle: “I'd like this”
    await expect(page.locator("[data-testid^=\"qty-\"]").first()).toBeVisible() // Choose how many
    await expect(page.locator('[data-testid="slot-picker"]').first()).toBeVisible() // Pick a collection time
    await expect(page.locator('[data-testid="register-interest"]').first()).toBeVisible() // Send the giver your picks
  })

  test('golden flow: giver-chat', async ({ page }) => {
    await loginViaHomepage(page, process.env.GIVER_EMAIL, process.env.TEST_PASSWORD || 'freegle')
    await page.goto(`/chats/${process.env.GIVER_CHAT_ID}`, { waitUntil: 'networkidle' })
    await expect(page.getByText("can collect").first()).toBeVisible()
    await page.waitForTimeout(500)
    await expect(page.getByText("can collect: Tue 7 Apr").first()).toBeVisible() // One consolidated message — everything they chose
  })

  test('golden flow: mod-bulk-preview', async ({ page }) => {
    await loginViaModTools(page, process.env.MOD_EMAIL, process.env.TEST_PASSWORD || 'freegle')
    await page.goto(`/modtools/messages/approved`, { waitUntil: 'networkidle' })
    await page.waitForTimeout(4000)
    await page.waitForTimeout(1000)
    await page.waitForTimeout(1000)
    await page.waitForTimeout(1000)
    await page.waitForTimeout(1000)
    await page.waitForTimeout(1200)
    await expect(page.locator('[data-testid="bulk-preview-btn"]').first()).toBeVisible()
    await page.locator('[data-testid="bulk-preview-btn"]').first().click()
    await expect(page.locator("#modBulkPreviewModal .modal-content").first()).toBeVisible()
    await page.waitForTimeout(800)
  })
})
