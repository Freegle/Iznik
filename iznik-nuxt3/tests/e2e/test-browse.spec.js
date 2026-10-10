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

// What the browser actually paints at the centre of the viewport, and where it lives.
// Asking the browser beats trusting either stylesheet when checking stacking order.
async function whatIsOnTop(page) {
  return await page.evaluate(() => {
    const viewer = document.querySelector('.fullscreen-viewer')
    const modal = document.querySelector('.message-modal')
    const el = document.elementFromPoint(
      Math.round(window.innerWidth / 2),
      Math.round(window.innerHeight / 2)
    )
    return {
      insideViewer: !!(viewer && el && viewer.contains(el)),
      insidePostModal: !!(modal && el && modal.contains(el)),
      viewerZ: viewer ? getComputedStyle(viewer).zIndex : null,
      postModalZ: modal ? getComputedStyle(modal).zIndex : null,
    }
  })
}

// The seeded posts (scripts/test-fixtures.sql) are at this postcode. Browse shows posts
// near the member, so a test that needs them on screen puts the member here.
const SEEDED_POSTCODE = 'LS1 4AP'

// Browse has no community to join: it shows what is near the member. Give the member a
// location from the postcode prompt Browse shows when it does not know where they are.
async function setBrowseLocation(page, postcode = SEEDED_POSTCODE) {
  await page.gotoAndVerify('/browse', {
    timeout: timeouts.navigation.default,
  })
  await dismissLoginModalIfPresent(page)

  const input = page.locator('.pcinp').filter({ visible: true }).first()
  if (!(await input.isVisible({ timeout: 5000 }).catch(() => false))) {
    // Browse already knows where they are.
    return
  }

  await input.fill(postcode)
  await page
    .locator('.validation-tick')
    .first()
    .waitFor({ state: 'visible', timeout: timeouts.api.default })
  await expect(page.locator('.pcinp').filter({ visible: true })).toHaveCount(0, {
    timeout: timeouts.api.default,
  })
}

async function signUpWithLocation(page, testEmail, userName) {
  const signupResult = await signUpViaHomepage(page, testEmail, userName)
  expect(signupResult).toBeTruthy()
  await setBrowseLocation(page)
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
    await signUpWithLocation(page, testEmail, 'Search Test User')

    // Test search with search term in URL
    console.log('Testing browse page with search term in URL')
    await page.gotoAndVerify('/browse/furniture', {
      timeout: timeouts.navigation.default,
    })

    expect(page.url()).toContain('/browse/furniture')

    // Page should load without errors
    await page.locator('body').waitFor({ state: 'visible', timeout: 5000 })
  })

  test('should display microvolunteering component', async ({
    page,
    takeScreenshot,
    testEmail,
    testEnv,
  }) => {
    await signUpWithLocation(page, testEmail, 'Micro Test User')

    // Navigate to browse page
    await page.gotoAndVerify('/browse', {
      timeout: timeouts.navigation.default,
    })

    // Check for page content
    await page.locator('body').waitFor({ state: 'visible', timeout: 5000 })

    expect(page.url()).toContain('/browse')
  })

  test('should handle responsive behavior', async ({
    page,
    takeScreenshot,
    testEmail,
    testEnv,
  }) => {
    await signUpWithLocation(page, testEmail, 'Responsive Test User')

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
    // short screen; it now comes from the viewport height so six fit. The member is put
    // where the seeded posts are, so the feed has cards in it.
    const CARDS_WANTED = 6
    const CARD_GAP = 8 // .singlecolumn margin-bottom in ScrollGrid
    const MAX_PHOTO = 200 // what the size used to be fixed at, and still caps at

    await loginViaHomepage(page, testEnv.user.email, 'freegle')
    await setBrowseLocation(page)

    await page.setViewportSize({ width: 1280, height: 720 })
    await page.gotoAndVerify('/browse', {
      timeout: timeouts.navigation.default,
    })

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
    await page.gotoAndVerify('/browse', {
      timeout: timeouts.navigation.default,
    })

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
    await signUpWithLocation(page, testEmail, 'Browse Test User')

    // Test general browse page
    console.log('Testing general browse page')
    await page.gotoAndVerify('/browse', {
      timeout: timeouts.navigation.default,
    })

    await expect(page).toHaveTitle(/Browse/, {
      timeout: timeouts.navigation.slowPage,
    })

    console.log('Browse page loaded successfully')
  })

  /* A post opens in a modal, so the photo viewer is opened from inside one and has to
     cover it. Lifting modals as a class above the viewer buries it behind its own
     opener, and members then see only the sliver of photo outside the modal's edges.
     The viewer is fixed at z-index 10000, bootstrap's modals sit at about 1050, and
     nothing may reorder those two. */
  test('should open the photo viewer on top of the post modal', async ({
    page,
    testEnv,
  }) => {
    await loginViaHomepage(page, testEnv.mod.email, 'freegle')

    // The member is put where the seeded posts are, so the card is in the feed.
    await setBrowseLocation(page)

    const card = page
      .locator(`#msg-${testEnv.messages.offer} .message-summary-mobile`)
      .first()
    await expect(card).toBeVisible({ timeout: timeouts.ui.appearance })

    // The profile prompt can open over the page and swallow clicks.
    const aboutMe = page.locator('.modal.show:has-text("public profile")')
    if (await aboutMe.isVisible()) {
      await aboutMe.getByRole('button', { name: 'Skip for now' }).click()
      await expect(aboutMe).toBeHidden()
    }

    await card.click()

    // The post opens in a modal, with its photo at the top. Click the whole photo
    // area: the title sits over the bottom of the photo and the click reaches the
    // area underneath it, which is what opens the viewer.
    const postModal = page.locator('.message-modal')
    await expect(postModal).toBeVisible({ timeout: timeouts.ui.appearance })
    const photo = postModal.locator('.photo-area:visible').first()
    await expect(photo).toBeVisible({ timeout: timeouts.ui.appearance })

    await photo.click()

    await expect(page.locator('.fullscreen-viewer')).toBeVisible({
      timeout: timeouts.ui.appearance,
    })

    await expect
      .poll(async () => (await whatIsOnTop(page)).insideViewer, {
        timeout: timeouts.ui.appearance,
      })
      .toBe(true)

    // Spell the ordering out too, so a failure says which way round they ended up.
    const stacking = await whatIsOnTop(page)
    console.log('Photo viewer stacking:', JSON.stringify(stacking))
    expect(stacking.insidePostModal).toBe(false)
    expect(Number(stacking.viewerZ)).toBeGreaterThan(
      Number(stacking.postModalZ)
    )
  })
})
