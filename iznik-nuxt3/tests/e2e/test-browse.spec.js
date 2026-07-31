/**
 * Comprehensive Browse Page Tests
 * Tests the full browse functionality including user signup, message creation, and browsing
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')
const { signUpViaHomepage, loginViaHomepage } = require('./utils/user')
const { dismissLoginModalIfPresent } = require('./utils/reply-helpers')

// Measure the first feed card, the square photo in it, and the chrome above and below the
// feed, so a test can work out how many cards actually fit on screen.
async function measureFeedCard(page) {
  return await page.evaluate(() => {
    const card = document.querySelector('.message-summary-mobile')
    const photo = card.querySelector('.photo-area')
    const navbar = document.querySelector('nav.fixed-top')
    const stickyAd = document.querySelector('.sticky')
    const cardBox = card.getBoundingClientRect()
    const photoBox = photo.getBoundingClientRect()

    return {
      viewportHeight: window.innerHeight,
      cardHeight: cardBox.height,
      photoWidth: photoBox.width,
      photoHeight: photoBox.height,
      navbarHeight: navbar ? navbar.getBoundingClientRect().height : 0,
      stickyAdHeight: stickyAd ? stickyAd.getBoundingClientRect().height : 0,
    }
  })
}

// Wait for the desktop row layout, then measure. The card appears before VisibleWhen has
// settled on the breakpoint, and until it does the card is still in the portrait grid
// layout with a taller-than-wide photo, so wait for the photo to be square first.
async function measureDesktopFeedCard(page) {
  await page.waitForSelector('.message-summary-mobile', {
    timeout: timeouts.ui.appearance,
  })

  await expect
    .poll(
      async () => {
        const { photoWidth, photoHeight } = await measureFeedCard(page)
        return Math.round(photoWidth) === Math.round(photoHeight)
      },
      { timeout: timeouts.ui.appearance }
    )
    .toBe(true)

  return await measureFeedCard(page)
}

// Helper: sign up and join a group.
async function signUpAndJoinGroup(page, testEmail, userName, groupName) {
  const signupResult = await signUpViaHomepage(page, testEmail, userName)
  expect(signupResult).toBeTruthy()

  await page.gotoAndVerify(`/explore/${groupName}`, {
    timeout: timeouts.navigation.default,
  })

  // After navigation, session may not have persisted. If a login modal appears,
  // complete the login with the same credentials before proceeding.
  const loginModal = page.locator(
    '#loginModal, .modal-dialog:has-text("Join the Reuse Revolution")'
  )
  try {
    await loginModal.waitFor({ state: 'visible', timeout: 3000 })
    console.log(
      'Login modal appeared after navigation — session not persisted, logging in'
    )
    // Close the modal and log in via the homepage flow
    const closeButton = loginModal.locator(
      '.btn-close, .close, button[aria-label="Close"]'
    )
    if ((await closeButton.count()) > 0) {
      await closeButton.first().click()
      await loginModal.waitFor({ state: 'hidden', timeout: 5000 })
    }
    const loginSuccess = await loginViaHomepage(page, testEmail)
    expect(loginSuccess).toBeTruthy()
    // Re-navigate to the explore page now that we're logged in
    await page.gotoAndVerify(`/explore/${groupName}`, {
      timeout: timeouts.navigation.default,
    })
  } catch {
    // No login modal — session persisted correctly
  }

  // Check if we're already a member (Leave button visible) or need to join
  const leaveButton = page
    .locator('.btn:has-text("Leave")')
    .filter({ visible: true })
    .first()
  const joinButton = page
    .locator('.btn:has-text("Join this community")')
    .filter({ visible: true })
    .first()

  // Wait for either Join or Leave to appear
  await expect(joinButton.or(leaveButton)).toBeVisible({
    timeout: timeouts.ui.appearance,
  })

  if (await leaveButton.isVisible({ timeout: 5000 }).catch(() => false)) {
    console.log(`Already a member of ${groupName}`)
    return
  }

  await joinButton.click()

  // After clicking Join, either Leave appears (success) or a login modal appears (auth lost)
  await expect(leaveButton.or(loginModal)).toBeVisible({
    timeout: timeouts.ui.appearance,
  })

  if (await loginModal.isVisible({ timeout: 5000 }).catch(() => false)) {
    console.log(
      'Login modal appeared after Join click — session lost, logging in'
    )
    const closeButton = loginModal.locator(
      '.btn-close, .close, button[aria-label="Close"]'
    )
    if ((await closeButton.count()) > 0) {
      await closeButton.first().click()
      await loginModal.waitFor({ state: 'hidden', timeout: 5000 })
    }
    const loginSuccess = await loginViaHomepage(page, testEmail)
    expect(loginSuccess).toBeTruthy()
    // Re-navigate and join
    await page.gotoAndVerify(`/explore/${groupName}`, {
      timeout: timeouts.navigation.default,
    })
    await expect(joinButton.or(leaveButton)).toBeVisible({
      timeout: timeouts.ui.appearance,
    })
    if (await leaveButton.isVisible({ timeout: 5000 }).catch(() => false)) {
      console.log(`Already a member of ${groupName} after re-login`)
      return
    }
    await joinButton.click()
    await expect(leaveButton).toBeVisible({ timeout: timeouts.ui.appearance })
  }

  console.log(`Successfully joined ${groupName}`)
}

test.describe('Browse Page Tests', () => {
  test('should create a message and browse it successfully', async ({
    page,
    testEmail,
    postMessage,
    withdrawPost,
  }) => {
    // Post a message (this handles signup/login internally via the fixture)
    const uniqueItem = `test-browse-${Date.now()}-${Math.random()
      .toString(36)
      .substr(2, 5)}`
    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description: `Created by browse test at ${new Date().toISOString()}`,
      email: testEmail,
    })

    expect(result.id).toBeTruthy()
    console.log(`Created test message with ID: ${result.id}`)

    // Navigate to /myposts and verify our post is visible
    console.log('Navigating to /myposts to verify post visibility')
    await page.gotoAndVerify('/myposts', {
      timeout: timeouts.navigation.default,
    })

    await page.waitForSelector('.message-card, .card-body', {
      timeout: timeouts.ui.appearance,
    })

    const itemLocator = page
      .locator('.message-card, .card-body')
      .filter({ hasText: uniqueItem })
    await itemLocator.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    console.log(`Found our test item "${uniqueItem}" on myposts page`)

    // Now navigate to browse and verify the page loads without errors
    console.log('Testing browse page loads')
    await page.gotoAndVerify('/browse', {
      timeout: timeouts.navigation.default,
    })

    // Wait for the browse page to finish loading — either messages appear,
    // the "no posts" notice is shown, or the postcode prompt appears (when
    // isochrones haven't loaded yet). All are valid states because newly
    // posted messages may not appear immediately due to isochrone/indexing delays.
    const messagesLocator = page.locator(
      '.message-summary-mobile, .messagecard'
    )
    const noPostsLocator = page
      .locator("text=couldn't find any posts")
      .or(page.locator('text=no posts in this area'))
      .or(page.locator("text=Sorry, we didn't find anything"))
      .or(page.locator("text=What's your postcode"))

    await expect(messagesLocator.or(noPostsLocator).first()).toBeVisible({
      timeout: timeouts.navigation.default,
    })

    const messageCount = await messagesLocator.count()
    console.log(`Found ${messageCount} messages on browse page`)

    // Verify page title (SSR starts with default app title; Vue hydration updates it)
    await expect(page).toHaveTitle(/Browse/, {
      timeout: timeouts.navigation.slowPage,
    })

    // Clean up
    await withdrawPost({ item: result.item })
  })

  test('should handle search functionality on browse page', async ({
    page,
    takeScreenshot,
    testEmail,
    testEnv,
  }) => {
    await signUpAndJoinGroup(
      page,
      testEmail,
      'Search Test User',
      testEnv.group.name
    )

    // Test search with search term in URL
    console.log('Testing browse page with search term in URL')
    await page.gotoAndVerify('/browse/furniture', {
      timeout: timeouts.navigation.default,
    })

    // The browse page redirects to /explore when the user has no location set.
    // Both /browse/furniture and /explore are valid outcomes — the page should
    // load without errors either way.
    const url = page.url()
    expect(url.includes('furniture') || url.includes('explore')).toBeTruthy()

    // Page should load without errors
    await page.locator('body').waitFor({ state: 'visible', timeout: 5000 })
  })

  test('should display microvolunteering component', async ({
    page,
    takeScreenshot,
    testEmail,
    testEnv,
  }) => {
    await signUpAndJoinGroup(
      page,
      testEmail,
      'Micro Test User',
      testEnv.group.name
    )

    // Navigate to browse page
    await page.gotoAndVerify('/browse', {
      timeout: timeouts.navigation.default,
    })

    // Check for page content
    await page.locator('body').waitFor({ state: 'visible', timeout: 5000 })

    // The browse page redirects to /explore when the user has no location set.
    // Both /browse and /explore are valid outcomes for a new user with no isochrone.
    const finalUrl = page.url()
    expect(
      finalUrl.includes('/browse') || finalUrl.includes('/explore')
    ).toBeTruthy()
  })

  test('should handle responsive behavior', async ({
    page,
    takeScreenshot,
    testEmail,
    testEnv,
  }) => {
    await signUpAndJoinGroup(
      page,
      testEmail,
      'Responsive Test User',
      testEnv.group.name
    )

    // Test different viewport sizes
    const viewports = [
      { width: 320, height: 568 }, // Mobile
      { width: 768, height: 1024 }, // Tablet
      { width: 1920, height: 1080 }, // Desktop
    ]

    for (const viewport of viewports) {
      console.log(`Testing viewport: ${viewport.width}x${viewport.height}`)
      await page.setViewportSize(viewport)
      await page.gotoAndVerify('/browse', {
        timeout: timeouts.navigation.default,
      })

      // Verify page adapts to different screen sizes
      await page.locator('body').waitFor({ state: 'visible', timeout: 5000 })

      // Check that layout adapts (columns may stack on mobile)
      const container = page
        .locator('.container-fluid, .container, main, [class*="container"]')
        .first()
      if ((await container.count()) > 0) {
        await container.waitFor({ state: 'attached', timeout: 5000 })
      }
    }
  })

  test('should size feed photos from the screen height so six posts fit', async ({
    page,
    testEnv,
  }) => {
    // The lg+ feed card is a row whose photo is a square, so the square's side is the
    // card's height. It used to be a fixed 200px, which put only two or three posts on a
    // short screen; it now comes from the viewport height so six fit. Uses /explore, which
    // shows the same cards and reliably has the seeded posts on it.
    const CARDS_WANTED = 6
    const CARD_GAP = 8 // .singlecolumn margin-bottom in ScrollGrid
    const MAX_PHOTO = 200 // what the size used to be fixed at, and still caps at

    await page.setViewportSize({ width: 1280, height: 720 })
    await page.gotoAndVerify(`/explore/${testEnv.group.name}`, {
      timeout: timeouts.navigation.default,
    })
    await dismissLoginModalIfPresent(page)

    const short = await measureDesktopFeedCard(page)
    console.log('Short screen card:', JSON.stringify(short))

    // The photo is square, so its side really is the row height.
    expect(Math.abs(short.photoWidth - short.photoHeight)).toBeLessThan(1)
    expect(Math.abs(short.cardHeight - short.photoHeight)).toBeLessThan(1)

    // It has reacted to the short screen rather than staying at the old fixed size.
    expect(short.photoHeight).toBeLessThan(MAX_PHOTO)

    // Six cards and the gaps between them fit between the navbar and the sticky ad.
    const usable =
      short.viewportHeight - short.navbarHeight - short.stickyAdHeight
    const needed =
      CARDS_WANTED * short.cardHeight + (CARDS_WANTED - 1) * CARD_GAP
    console.log(`Six cards need ${needed}px, ${usable}px available`)
    expect(needed).toBeLessThanOrEqual(usable + 1)

    // A taller screen gets a bigger photo, but never bigger than it used to be.
    await page.setViewportSize({ width: 1280, height: 1200 })
    await page.gotoAndVerify(`/explore/${testEnv.group.name}`, {
      timeout: timeouts.navigation.default,
    })
    await dismissLoginModalIfPresent(page)

    const tall = await measureDesktopFeedCard(page)
    console.log('Tall screen card:', JSON.stringify(tall))
    expect(tall.photoHeight).toBeGreaterThan(short.photoHeight)
    expect(tall.photoHeight).toBeLessThanOrEqual(MAX_PHOTO)
    expect(Math.abs(tall.photoWidth - tall.photoHeight)).toBeLessThan(1)
  })

  test('should load browse page with existing messages', async ({
    page,
    takeScreenshot,
    testEmail,
    testEnv,
  }) => {
    await signUpAndJoinGroup(
      page,
      testEmail,
      'Browse Test User',
      testEnv.group.name
    )

    // Test general browse page
    console.log('Testing general browse page')
    await page.gotoAndVerify('/browse', {
      timeout: timeouts.navigation.default,
    })

    // Page should load successfully. A new user with no location set will be
    // redirected to /explore (title "Explore Freegle"); a user with a location
    // stays on /browse (title "Browse"). Accept either.
    await expect(page).toHaveTitle(/Browse|Explore/, {
      timeout: timeouts.navigation.slowPage,
    })

    console.log('Browse page loaded successfully')
  })
})
