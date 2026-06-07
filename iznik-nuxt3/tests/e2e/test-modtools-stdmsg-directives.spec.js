/**
 * ModTools standard-message directives — screenshot/e2e for PR #659.
 *
 * Opens the "send standard message" modal (ModStdMessageModal) against a pending
 * message, using the seeded standard message "Suggest editing or reposting" which
 * contains the new directive features:
 *   - $editlink / $repeatwantedtime substitution vars
 *   - <editthis>…</editthis> (mod must personalise before sending → warning)
 *   - <optional>…</optional> (one-tap Keep / Remove, forces a decision)
 * Captures screenshots of what a moderator sees, written to /tmp/stdmsg-shots/.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl
const SHOT_DIR = '/tmp/stdmsg-shots'

async function dismissAllModals(page) {
  for (let i = 0; i < 5; i++) {
    const removed = await page.evaluate(() => {
      let found = false
      document
        .querySelectorAll('.modal.show, .modal[style*="display: block"]')
        .forEach((el) => {
          el.classList.remove('show')
          el.style.display = 'none'
          found = true
        })
      document.querySelectorAll('.modal-backdrop').forEach((el) => {
        el.remove()
        found = true
      })
      document.body.classList.remove('modal-open')
      return found
    })
    if (!removed) break
    await page.waitForTimeout(300)
  }
}

async function selectGroupWithPendingMessages(page, groupSelect) {
  let targetGroupValue = null
  await expect
    .poll(
      async () => {
        const options = await groupSelect.locator('option').all()
        for (const option of options) {
          const text = await option.textContent()
          const value = await option.getAttribute('value')
          if (value && value !== '0' && /\(\d+\)/.test(text)) {
            targetGroupValue = value
            return true
          }
        }
        return false
      },
      { message: 'group options with pending counts', timeout: timeouts.navigation.slowPage }
    )
    .toBe(true)
  await groupSelect.selectOption(targetGroupValue)
  return targetGroupValue
}

test.describe('ModTools standard-message directives (#659)', () => {
  test('send-standard-message modal shows directive features', async ({ page, testEnv }) => {
    expect(testEnv.pending.offer).toBeTruthy()

    await loginViaModTools(page, testEnv.mod.email)
    await page.goto(`${MODTOOLS_URL}/messages/pending`, {
      timeout: timeouts.navigation.initial,
    })

    const groupSelect = page.locator('#communitieslist')
    await expect(groupSelect).toBeVisible({ timeout: timeouts.navigation.slowPage })
    await dismissAllModals(page)
    await page
      .waitForFunction(() => !document.querySelector('.modal-backdrop'), { timeout: 10000 })
      .catch(() => {})

    await selectGroupWithPendingMessages(page, groupSelect)

    const messageCards = page.locator('.card')
    await expect(messageCards.first()).toBeVisible({ timeout: timeouts.navigation.slowPage })

    // The seeded standard message renders as a button labelled with its title.
    const stdBtn = page
      .locator('button:has-text("Suggest editing or reposting"), .btn:has-text("Suggest editing or reposting")')
      .first()

    // It may sit behind a "Reject" group / more menu; reveal it if needed.
    if (!(await stdBtn.isVisible().catch(() => false))) {
      const reject = page.locator('button:has-text("Reject"), .btn:has-text("Reject")').first()
      if (await reject.isVisible().catch(() => false)) {
        await reject.click().catch(() => {})
        await page.waitForTimeout(500)
      }
    }
    await page.screenshot({ path: `${SHOT_DIR}/00-pending-with-buttons.png`, fullPage: true })

    await expect(stdBtn).toBeVisible({ timeout: timeouts.ui.appearance })
    await stdBtn.click()

    // Modal opens.
    const modal = page.locator('#stdmsgmodal')
    await expect(modal).toBeVisible({ timeout: timeouts.ui.appearance })
    await page.waitForTimeout(800) // let substitution + directive parsing render

    // 1) As-opened: body with substituted $editlink/$repeatwantedtime, the
    //    <editthis> pending-edit warning, and the <optional> Keep/Remove panel.
    await modal.screenshot({ path: `${SHOT_DIR}/01-modal-as-opened.png` })
    await page.screenshot({ path: `${SHOT_DIR}/01b-modal-fullpage.png`, fullPage: true })

    // 2) Capture the directive warning shown if the mod tries to send without
    //    personalising the <editthis> / deciding the <optional>.
    const sendBtn = modal
      .locator('button:has-text("Send"), .btn-primary:has-text("Send")')
      .first()
    if (await sendBtn.isVisible().catch(() => false)) {
      await sendBtn.click().catch(() => {})
      await page.waitForTimeout(500)
      await modal.screenshot({ path: `${SHOT_DIR}/02-send-blocked-warning.png` })
    }

    // 3) Decide the optional section (Remove) to show the one-tap behaviour.
    const removeBtn = modal
      .locator('button:has-text("Remove"), .btn:has-text("Remove")')
      .first()
    if (await removeBtn.isVisible().catch(() => false)) {
      await removeBtn.click().catch(() => {})
      await page.waitForTimeout(400)
      await modal.screenshot({ path: `${SHOT_DIR}/03-optional-removed.png` })
    }

    console.log('stdmsg-directives screenshots written to', SHOT_DIR)
  })
})
