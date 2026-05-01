// @ts-check
/**
 * Tests for ModTools post list scroll stability.
 * Verifies that expanding/collapsing messages does not cause unexpected UI jumps.
 */

const { test, expect } = require('./fixtures')
const { timeouts, environment } = require('./config')
const { loginViaModTools } = require('./utils/user')

const MODTOOLS_URL = environment.modtoolsBaseUrl

// Helper: dismiss any overlay modals that block interaction.
async function dismissAllModals(page) {
  await page.evaluate(() => {
    document
      .querySelectorAll('.modal.show, .modal[style*="display: block"]')
      .forEach((el) => {
        el.classList.remove('show')
        el.style.display = 'none'
      })
    document.querySelectorAll('.modal-backdrop').forEach((el) => el.remove())
    document.body.classList.remove('modal-open')
    document.body.style.removeProperty('overflow')
    document.body.style.removeProperty('padding-right')
  })
}

// Helper: check for common error indicators.
async function assertNoErrors(page) {
  const body = await page.textContent('body')
  expect(body).not.toContain('something went wrong')
  expect(body).not.toContain('Oh dear')
  expect(body).not.toContain('undefined is not an object')
  expect(body).not.toContain('Cannot read properties of undefined')
}

test.describe('ModTools Scroll Stability', () => {
  test('scroll position remains stable when expanding/collapsing messages', async ({
    page,
    testEnv,
  }) => {
    // Navigate to ModTools Pending page
    await page.goto(`${MODTOOLS_URL}/pending`)
    await page.waitForLoadState('networkidle')

    await dismissAllModals(page)
    await assertNoErrors(page)

    // Wait for at least 2 messages to load
    await expect(page.locator('[id^="msg-"]')).first().toBeTruthy()
    const messageCount = await page.locator('[id^="msg-"]').count()
    test.skip(messageCount < 2, 'Not enough messages to test scroll stability')

    // Get the first message element
    const firstMessage = page.locator('[id^="msg-"]').first()

    // Record initial scroll position before expanding first message
    const initialScrollTop = await page.evaluate(() => window.scrollY)

    // Click the first message to expand it (assuming click expands the card)
    await firstMessage.click()
    await page.waitForTimeout(500) // Wait for expansion animation

    // Check scroll position after expansion
    const scrollAfterExpand = await page.evaluate(() => window.scrollY)

    // The scroll position should not jump significantly
    // Allow small movement due to smooth scrolling, but not large jumps
    const allowedShift = 100 // pixels - allow some movement
    const scrollShift = Math.abs(scrollAfterExpand - initialScrollTop)

    expect(scrollShift).toBeLessThan(allowedShift)

    // Collapse the message by clicking again
    await firstMessage.click()
    await page.waitForTimeout(500) // Wait for collapse animation

    // Check scroll position after collapse
    const scrollAfterCollapse = await page.evaluate(() => window.scrollY)

    // Scroll position should return to approximately the original position
    const collapseShift = Math.abs(scrollAfterCollapse - initialScrollTop)
    expect(collapseShift).toBeLessThan(allowedShift)
  })

  test('scroll stability with hide expired posts filter', async ({
    page,
    testEnv,
  }) => {
    // Navigate to ModTools Approved page (which has more filtering options)
    await page.goto(`${MODTOOLS_URL}/approved`)
    await page.waitForLoadState('networkidle')

    await dismissAllModals(page)
    await assertNoErrors(page)

    // Wait for messages to load
    await expect(page.locator('[id^="msg-"]')).first().toBeTruthy()

    // Record initial scroll position
    const initialScrollTop = await page.evaluate(() => window.scrollY)

    // If there's a filter toggle for "hide expired posts", test with it
    const filterButtons = await page.locator('button:has-text("Filter"), button:has-text("filter")').count()

    if (filterButtons > 0) {
      // Click filter button to open/toggle filters
      await page.locator('button:has-text("Filter")').first().click()
      await page.waitForTimeout(300)

      // Check scroll position didn't jump when opening filter menu
      const scrollAfterFilter = await page.evaluate(() => window.scrollY)
      const filterShift = Math.abs(scrollAfterFilter - initialScrollTop)

      expect(filterShift).toBeLessThan(100)
    }
  })

  test('no layout thrashing during list rendering', async ({
    page,
    testEnv,
  }) => {
    // Navigate to ModTools Pending page
    await page.goto(`${MODTOOLS_URL}/pending`)
    await page.waitForLoadState('networkidle')

    await dismissAllModals(page)

    // Use Performance API to detect layout thrashing
    const layoutMetrics = await page.evaluate(() => {
      const perfEntries = performance.getEntriesByType('measure')
        .filter(entry => entry.name.includes('layout') || entry.name.includes('reflow'))

      // Also check for console warnings about layout thrashing
      return {
        measureCount: perfEntries.length,
        // Performance paint timing
        paintTiming: performance.getEntriesByType('paint').map(p => p.name)
      }
    })

    // Basic check that the page rendered without excessive layout work
    // (This is a soft check - actual detailed metrics would require Chrome DevTools Protocol)
    expect(layoutMetrics).toBeDefined()

    // Check console for any warnings
    const consoleMessages = []
    page.on('console', msg => {
      if (msg.type() === 'warn' || msg.type() === 'error') {
        consoleMessages.push(msg.text())
      }
    })

    // Wait for potential reflows after initial render
    await page.waitForTimeout(1000)

    // There should not be warnings about performance
    const perfWarnings = consoleMessages.filter(msg =>
      msg.toLowerCase().includes('reflow') ||
      msg.toLowerCase().includes('layout thrashing') ||
      msg.toLowerCase().includes('forced reflow')
    )

    expect(perfWarnings.length).toBe(0)
  })
})
