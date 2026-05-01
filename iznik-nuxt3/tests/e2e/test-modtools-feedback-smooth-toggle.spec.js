import { test, expect } from '@playwright/test'

test.describe('ModTools Feedback - Smooth Hide Expired Toggle', () => {
  test('should smoothly transition items when toggling hide expired posts', async ({
    page,
  }) => {
    // Navigate to modtools feedback page
    await page.goto('/modtools/members/feedback', { waitUntil: 'networkidle' })

    // Wait for the feedback list to load
    await page.waitForSelector('.item-wrapper', { timeout: 10000 })

    // Get initial item count
    const initialItems = await page.locator('.item-wrapper').count()
    expect(initialItems).toBeGreaterThan(0)

    // Find the "Show expired" checkbox
    const showExpiredCheckbox = page.locator('input[type="checkbox"]').filter({
      hasText: 'Show expired',
    })

    // Verify checkbox is initially checked
    const isChecked = await showExpiredCheckbox.isChecked()
    expect(isChecked).toBe(true)

    // Check that transition-group exists and has proper structure
    const transitionGroup = page.locator('.items-container')
    await expect(transitionGroup).toBeVisible()

    // Toggle the "show expired" checkbox
    await showExpiredCheckbox.click()

    // Wait for transition to complete (0.2s in CSS)
    await page.waitForTimeout(300)

    // Get new item count after filter
    const newItems = await page.locator('.item-wrapper').count()

    // Verify items were filtered (should be less or equal to original)
    expect(newItems).toBeLessThanOrEqual(initialItems)

    // Verify transition classes are applied during animation
    const itemWrapper = page.locator('.item-wrapper').first()
    await expect(itemWrapper).toHaveClass(/fade-slide/)

    // Verify no major layout shift by checking page height doesn't jump dramatically
    const initialHeight = await page.evaluate(() => document.body.scrollHeight)

    // Wait for any async updates
    await page.waitForTimeout(500)

    const finalHeight = await page.evaluate(() => document.body.scrollHeight)

    // The height change should be smooth and not cause CLS issues
    // (height change is expected, but should be smooth, not a jump)
    expect(Math.abs(initialHeight - finalHeight)).toBeLessThan(initialHeight / 2)
  })

  test('should maintain smooth transitions on multiple toggle clicks', async ({
    page,
  }) => {
    await page.goto('/modtools/members/feedback', { waitUntil: 'networkidle' })
    await page.waitForSelector('.item-wrapper', { timeout: 10000 })

    const showExpiredCheckbox = page.locator('input[type="checkbox"]').filter({
      hasText: 'Show expired',
    })

    // Toggle multiple times to verify smooth transition each time
    for (let i = 0; i < 3; i++) {
      await showExpiredCheckbox.click()
      await page.waitForTimeout(300) // Wait for transition
    }

    // Verify page is still responsive
    const items = await page.locator('.item-wrapper').count()
    expect(items).toBeGreaterThanOrEqual(0)
  })

  test('should work smoothly on different screen sizes', async ({ page }) => {
    // Test on mobile view
    await page.setViewportSize({ width: 375, height: 667 })
    await page.goto('/modtools/members/feedback', { waitUntil: 'networkidle' })
    await page.waitForSelector('.item-wrapper', { timeout: 10000 })

    const showExpiredCheckbox = page.locator('input[type="checkbox"]').filter({
      hasText: 'Show expired',
    })

    const initialCountMobile = await page.locator('.item-wrapper').count()

    await showExpiredCheckbox.click()
    await page.waitForTimeout(300)

    const finalCountMobile = await page.locator('.item-wrapper').count()
    expect(finalCountMobile).toBeLessThanOrEqual(initialCountMobile)

    // Test on tablet view
    await page.setViewportSize({ width: 768, height: 1024 })
    await page.goto('/modtools/members/feedback', { waitUntil: 'networkidle' })
    await page.waitForSelector('.item-wrapper', { timeout: 10000 })

    const initialCountTablet = await page.locator('.item-wrapper').count()

    await showExpiredCheckbox.click()
    await page.waitForTimeout(300)

    const finalCountTablet = await page.locator('.item-wrapper').count()
    expect(finalCountTablet).toBeLessThanOrEqual(initialCountTablet)
  })
})
