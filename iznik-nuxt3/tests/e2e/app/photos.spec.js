/**
 * Photos from the device itself, which the browser suite cannot reach: the system
 * camera, and an image shared into the app from another app. Each hands the WebView a
 * native file that the give flow previews and uploads to tusd; a photo counts as
 * added when the give flow's draft holds it with its tusd id.
 *
 * Shown afterwards, an uploaded photo is fetched through the image delivery service,
 * which on a stack with offset ports (worktrees, CI) cannot reach tusd and answers
 * 404, so these tests stop at the upload rather than at the rendered thumbnail.
 */

const path = require('path')
const { test, expect } = require('../fixtures')
const { timeouts } = require('../config')
const device = require('./device')

// A flat image the app's quality check calls very blurry, so the give flow always
// asks before uploading it; the test answers the way a member keeping it would.
const SHARED_IMAGE = path.join(__dirname, '../assets/item1.png')

// The photos in the give flow's drafts (the compose store), as plain data.
function draftPhotos(page) {
  return page.evaluate(() => {
    const pinia =
      document.getElementById('__nuxt').__vue_app__.config.globalProperties
        .$pinia
    const messages = pinia.state.value.compose?.messages || []
    return messages
      .filter(Boolean)
      .flatMap((m) => m.attachments || [])
      .map((a) => ({
        preview: a.preview || '',
        uid: a.externaluid || '',
        uploading: !!a.uploading,
        error: !!a.error,
      }))
  })
}

// Waits until the draft holds an uploaded photo whose native file matches
// previewPattern, answering the quality prompt ("Use This") if the app asks.
async function waitForUploadedPhoto(page, previewPattern) {
  const useThis = page.locator('button', { hasText: 'Use This' })
  let photo = null
  await expect
    .poll(
      async () => {
        if (await useThis.isVisible().catch(() => false)) {
          await useThis.click().catch(() => {})
        }
        const photos = await draftPhotos(page)
        photo =
          photos.find(
            (p) =>
              previewPattern.test(p.preview) &&
              p.uid.startsWith('freegletusd-') &&
              !p.uploading &&
              !p.error
          ) || null
        return !!photo
      },
      {
        timeout: timeouts.background,
        message: `an uploaded photo from ${previewPattern} in the draft`,
      }
    )
    .toBe(true)
  return photo
}

test.describe('Photos from the device', () => {
  test('a photo taken with the camera is added to the give flow and uploaded', async ({
    page,
  }) => {
    await page.gotoAndVerify('/give/mobile/photos', { maxRetries: 1 })
    await page.locator('.add-photos-button').first().click()
    await page
      .locator('button.source-option', { hasText: 'Take Photo' })
      .click()

    // The camera plugin hands over to the system camera app.
    await expect
      .poll(() => device.foregroundPackage(), {
        timeout: timeouts.ui.appearance,
      })
      .toBe('com.android.camera2')
    await device.takePictureWithSystemCamera()
    await device.waitForForeground()

    // The picture comes back as a file in the app's own storage.
    const photo = await waitForUploadedPhoto(
      page,
      /_capacitor_file_\/.*\/Pictures\/JPEG_.*\.jpg$/
    )
    expect(page.url()).toContain('/give/mobile/photos')
    console.log(`[Test] camera photo uploaded as ${photo.uid}`)
  })

  test('a photo chosen from the gallery is added to the give flow and uploaded', async ({
    page,
  }) => {
    const uri = await device.addImageToGallery(SHARED_IMAGE)
    const mediaId = uri.split('/').pop()

    await page.gotoAndVerify('/give/mobile/photos', { maxRetries: 1 })
    await page.locator('.add-photos-button').first().click()
    await page
      .locator('button.source-option', { hasText: 'Choose from Gallery' })
      .click()

    // The camera plugin opens the system photo picker.
    await expect
      .poll(() => device.foregroundPackage(), {
        timeout: timeouts.ui.appearance,
      })
      .toMatch(/providers\.media/)
    await device.pickNewestFromPhotoPicker()
    await device.waitForForeground()

    // The plugin copies the picked image into the app's cache under its MediaStore
    // id, so the uploaded photo is provably the one picked.
    const photo = await waitForUploadedPhoto(
      page,
      new RegExp(`_capacitor_file_/.*/cache/${mediaId}\\.\\d+\\.jpe?g$`)
    )
    console.log(`[Test] gallery photo ${mediaId} uploaded as ${photo.uid}`)
  })

  test('the app is offered when another app shares an image', async () => {
    expect(await device.isShareTarget('image/jpeg')).toBe(true)
    expect(await device.isShareTarget('image/png')).toBe(true)
  })

  test('an image shared while the app is in the background opens the give flow with it', async ({
    page,
  }) => {
    await page.gotoAndVerify('/', { maxRetries: 1 })
    const uri = await device.addImageToGallery(SHARED_IMAGE)

    await device.pressHome()
    await device.shareImage(uri, 'image/png')
    await device.waitForForeground()

    await expect
      .poll(() => page.url(), { timeout: timeouts.ui.appearance })
      .toMatch(/\/give\/mobile\/photos(\?|$)/)
    // MainActivity's copy of the shared image, read through Capacitor's file URL.
    const photo = await waitForUploadedPhoto(
      page,
      /_capacitor_file_\/.*\/cache\/shared\/share-[0-9-]+\.jpg$/
    )
    console.log(`[Test] shared image uploaded as ${photo.uid}`)
  })

  test('an image shared while the app is not running starts it in the give flow with it', async ({
    page,
    appWebView,
  }) => {
    // The page fixture has already cleared the app's storage, so a photo in the
    // draft afterwards can only have come from this share.
    expect(await draftPhotos(page)).toEqual([])
    const uri = await device.addImageToGallery(SHARED_IMAGE)

    await device.forceStop()
    await device.shareImage(uri, 'image/png')
    const started = await appWebView.reattach()

    await expect
      .poll(() => started.url(), { timeout: timeouts.navigation.initial })
      .toMatch(/\/give\/mobile\/photos(\?|$)/)
    const photo = await waitForUploadedPhoto(
      started,
      /_capacitor_file_\/.*\/cache\/shared\/share-[0-9-]+\.jpg$/
    )
    console.log(`[Test] cold-start shared image uploaded as ${photo.uid}`)
  })
})
