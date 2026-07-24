/**
 * Tests for the item-name warning banners shown while composing a post
 * (components/PostItem.vue).
 *
 * Covers the "Sealed and in date" warning added for contact lens solution
 * and similar regulated items, plus the neighbouring validation branches in
 * the same component that had no e2e coverage at all: the other
 * keyword-triggered safety warnings, the hard-blocked unpostable-item
 * messages, the soft "too vague" nudge, and the duplicate-open-post check.
 */

const { test, expect } = require('./fixtures')
const { timeouts } = require('./config')

const DESKTOP_VIEWPORT = { width: 1280, height: 800 }

// Locates the item-name input within the compose form, regardless of
// whether we're on /give (Offer) or /find (Wanted).
function itemInputLocator(page) {
  return page
    .locator('.post-item-container')
    .locator('input[placeholder*="single word"], input[id^="what"]')
    .first()
}

// The keyword-triggered warning renders as an <h1> with the warning's
// "type" as its text (see PostItem.vue). Scoped to the compose form so it
// can't collide with unrelated h1.header--size3 elements elsewhere on the
// page (e.g. ExploreNearby, MicroVolunteering).
function warningHeadingLocator(page) {
  return page.locator('.post-item-container h1.header--size3')
}

test.describe('PostItem compose warnings (desktop)', () => {
  test.use({ viewport: DESKTOP_VIEWPORT })

  test('Shows the correct regulated-item warning (or none) across a range of items - OFFER', async ({
    page,
  }) => {
    await page.gotoAndVerify('/give', {
      timeout: timeouts.navigation.default,
    })

    const itemInput = itemInputLocator(page)
    await itemInput.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })

    // [item text, expected warning heading (or null for no warning)]
    const cases = [
      ['contact lens solution', 'Sealed and in date'],
      ['sunscreen', 'Sealed and in date'],
      ['wooden bookshelf', null],
      ['knife', 'Knives'],
      ['car seat', 'Car seats'],
      ['motorcycle helmet', 'Motorcycle and cycle helmets'],
      ['cot mattress', 'Cot Mattress'],
      ['leather sofa', 'Upholstered household items and furniture'],
      ['lamp - free to a good home', 'Free'],
      ['giant hogweed', 'Banned Plants'],
      ['bicycle', null],
    ]

    for (const [itemText, expectedType] of cases) {
      await itemInput.fill(itemText)

      if (expectedType) {
        const heading = warningHeadingLocator(page)
        await expect(heading).toBeVisible({
          timeout: timeouts.assertion.slow,
        })
        await expect(heading).toContainText(expectedType)
        console.log(`"${itemText}" correctly showed "${expectedType}" warning`)
      } else {
        await expect(warningHeadingLocator(page)).toHaveCount(0)
        console.log(`"${itemText}" correctly showed no keyword warning`)
      }
    }

    // The PR's actual new copy - verify the message text, not just the heading.
    await itemInput.fill('contact lens fluid')
    await expect(
      page
        .locator('.post-item-container')
        .getByText('Please make sure this is sealed and in date.')
    ).toBeVisible({ timeout: timeouts.assertion.slow })
  })

  test('Shows the sealed-and-in-date warning on the WANTED flow too, and not for unrelated items', async ({
    page,
  }) => {
    await page.gotoAndVerify('/find', {
      timeout: timeouts.navigation.default,
    })

    const itemInput = itemInputLocator(page)
    await itemInput.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })

    await itemInput.fill('contact lens cleaner')
    const heading = warningHeadingLocator(page)
    await expect(heading).toBeVisible({ timeout: timeouts.assertion.slow })
    await expect(heading).toContainText('Sealed and in date')
    console.log('WANTED flow correctly showed the sealed-and-in-date warning')

    // Unrelated item on the WANTED flow shows no warning.
    await itemInput.fill('office chair')
    await expect(warningHeadingLocator(page)).toHaveCount(0)
    console.log('WANTED flow correctly showed no warning for an unrelated item')
  })

  test('Blocks unpostable items and nudges on overly-vague ones - OFFER', async ({
    page,
  }) => {
    await page.gotoAndVerify('/give', {
      timeout: timeouts.navigation.default,
    })

    const itemInput = itemInputLocator(page)
    await itemInput.waitFor({
      state: 'visible',
      timeout: timeouts.ui.appearance,
    })
    const form = page.locator('.post-item-container')

    // Hard block: numeric-only item.
    await itemInput.fill('123')
    await expect(form.getByText(/not a valid item/i)).toBeVisible({
      timeout: timeouts.assertion.slow,
    })
    console.log('Numeric-only item correctly hard-blocked')

    // Hard block: content-free catch-all item.
    await itemInput.fill('anything')
    await expect(
      form.getByText(/isn.t enough for people to know what you mean/i)
    ).toBeVisible({ timeout: timeouts.assertion.slow })
    console.log('Catch-all "anything" item correctly hard-blocked')

    // Soft nudge: overly general but postable term - not a hard block.
    await itemInput.fill('furniture')
    await expect(form.getByText(/avoid very general terms/i)).toBeVisible({
      timeout: timeouts.assertion.slow,
    })
    await expect(form.getByText(/not a valid item/i)).toHaveCount(0)
    await expect(
      form.getByText(/isn.t enough for people to know what you mean/i)
    ).toHaveCount(0)
    console.log('Overly-general "furniture" item correctly soft-nudged only')

    // A specific, valid item shows none of the above.
    await itemInput.fill('quality garden tools')
    await expect(form.getByText(/not a valid item/i)).toHaveCount(0)
    await expect(
      form.getByText(/isn.t enough for people to know what you mean/i)
    ).toHaveCount(0)
    await expect(form.getByText(/avoid very general terms/i)).toHaveCount(0)
    console.log('Specific valid item correctly showed no validation warnings')
  })

  test('Contact-lens-solution item can still be posted end to end (OFFER)', async ({
    page,
    testEmail,
    postMessage,
    withdrawPost,
  }) => {
    const uniqueItem = `contact lens solution ${Date.now()}${Math.floor(
      Math.random() * 100
    )}`

    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description:
        'Unopened contact lens solution, still sealed and in date. Created by automated test.',
      email: testEmail,
    })

    expect(result.id).toBeTruthy()
    console.log(
      `Contact-lens-solution OFFER posted successfully with ID: ${result.id}`
    )

    await withdrawPost({ item: result.item })
  })

  test('Contact-lens-solution item can still be posted end to end (WANTED)', async ({
    page,
    testEmail,
    postMessage,
    withdrawPost,
  }) => {
    const uniqueItem = `contact lens solution wanted ${Date.now()}${Math.floor(
      Math.random() * 100
    )}`

    const result = await postMessage({
      type: 'WANTED',
      item: uniqueItem,
      description:
        'Looking for contact lens solution. Created by automated test.',
      email: testEmail,
    })

    expect(result.id).toBeTruthy()
    console.log(
      `Contact-lens-solution WANTED posted successfully with ID: ${result.id}`
    )

    await withdrawPost({ item: result.item })
  })

  test('Detects a duplicate open post with the same item name', async ({
    page,
    testEmail,
    postMessage,
    withdrawPost,
  }) => {
    const uniqueItem = `test duplicate item ${Date.now()}${Math.floor(
      Math.random() * 100
    )}`

    const first = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description:
        'First post, left open on purpose to test duplicate detection.',
      email: testEmail,
    })
    expect(first.id).toBeTruthy()

    try {
      // postMessage() lands on /myposts, whose mount awaits
      // messageStore.fetch(id) for every active post before it stops showing
      // its loading state (see the `myid` watcher in pages/myposts.vue) -
      // populating messageStore.list, which is what PostItem's duplicate
      // check actually reads (via messageStore.all). That population lives
      // only in this tab's Pinia store: a hard reload (page.goto/
      // page.reload) wipes it, and /give's own mount never repopulates
      // messageStore.list.
      //
      // TODO: latent bug - duplicate-open-post detection in PostItem.vue
      // only works if messageStore.list already happens to hold the user's
      // other posts from earlier in the same session (e.g. a /myposts
      // visit); loading /give or /find directly never shows the warning.
      // useCompose.js:133 fires a messageStore.fetchByUser() call that looks
      // like it should cover this gap, but it's fire-and-forget (not
      // awaited by setup()) AND it writes into messageStore.byUserList, a
      // different key that the duplicate check does not read - so it can
      // never populate messageStore.list no matter how long you wait.
      // This test therefore navigates the way a real user does right after
      // posting - via the in-app nav link (a client-side transition that
      // preserves the Pinia store) - rather than a reload.
      await expect(page).toHaveURL(/\/myposts/, {
        timeout: timeouts.navigation.default,
      })

      const messageCard = page
        .locator(`.message-card:has-text("${uniqueItem}")`)
        .first()
      await expect(messageCard).toBeVisible({
        timeout: timeouts.ui.appearance,
      })

      await page.locator('#menu-option-give').click()
      await expect(page).toHaveURL(/\/give/, {
        timeout: timeouts.navigation.default,
      })

      const itemInput = itemInputLocator(page)
      await itemInput.waitFor({
        state: 'visible',
        timeout: timeouts.ui.appearance,
      })
      await itemInput.fill(uniqueItem)

      const duplicateWarning = page
        .locator('.post-item-container')
        .getByText(/already have an open post/i)
      await expect(duplicateWarning).toBeVisible({
        timeout: timeouts.assertion.slow,
      })
      console.log('Duplicate-open-post warning correctly shown')
    } finally {
      await withdrawPost({ item: first.item })
    }
  })

  test('Shows a keyword warning inside the Edit-post modal (edititem prop branch)', async ({
    page,
    testEmail,
    postMessage,
    withdrawPost,
  }) => {
    // MessageEditModal renders PostItem with edit/edititem props instead of
    // the compose store the /give and /find flows use above - a separate
    // code path through the same component (see the `item` computed's
    // props.edit branch in PostItem.vue) that had no e2e coverage at all.
    const uniqueItem = `plain bookshelf ${Date.now()}${Math.floor(
      Math.random() * 100
    )}`

    const result = await postMessage({
      type: 'OFFER',
      item: uniqueItem,
      description:
        'A plain item with no warnings, to be edited. Created by automated test.',
      email: testEmail,
    })
    expect(result.id).toBeTruthy()

    try {
      await expect(page).toHaveURL(/\/myposts/, {
        timeout: timeouts.navigation.default,
      })

      const messageCard = page
        .locator(`.message-card:has-text("${uniqueItem}")`)
        .first()
      await expect(messageCard).toBeVisible({
        timeout: timeouts.ui.appearance,
      })
      await messageCard.locator('button:has-text("Edit")').first().click()

      const editModal = page.locator('.modal.show')
      await expect(editModal).toBeVisible({ timeout: timeouts.ui.appearance })

      const editItemInput = editModal
        .locator('input[placeholder*="single word"], input[id^="what"]')
        .first()
      await expect(editItemInput).toHaveValue(uniqueItem, {
        timeout: timeouts.ui.appearance,
      })

      // No warning yet for the plain item name.
      await expect(editModal.locator('h1.header--size3')).toHaveCount(0)

      await editItemInput.fill('contact lens solution')

      const editWarningHeading = editModal.locator('h1.header--size3')
      await expect(editWarningHeading).toBeVisible({
        timeout: timeouts.assertion.slow,
      })
      await expect(editWarningHeading).toContainText('Sealed and in date')
      console.log(
        'Edit modal correctly showed the sealed-and-in-date warning via the edititem branch'
      )

      // Cancel without saving - the original item name stands, so cleanup
      // below can still find it by its original text.
      await editModal.locator('button:has-text("Cancel")').first().click()
      await expect(editModal).not.toBeVisible({
        timeout: timeouts.ui.appearance,
      })
    } finally {
      await withdrawPost({ item: result.item })
    }
  })
})
