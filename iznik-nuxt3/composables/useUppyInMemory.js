// Copies each photo into memory before the Uppy uploaders send it.
//
// On Android, a photo chosen in Chrome is a handle onto the phone's photo
// picker, not the bytes themselves. Chrome reads it again only when the upload
// request goes out, and for some phones and photos that second read fails: the
// request dies before it leaves the phone, and tus reports a bare network
// error. The server sees the CORS preflight and then nothing (Discourse 10240).
// The app does not have this problem because its picker hands us a copy.
//
// Reading the file once, straight after it is chosen, and uploading that copy
// takes the picker out of the upload.
//
// Sometimes even that first read fails (about 1 in 20 photos picked in Chrome
// on Android). We try once more, then give up on that photo: remove it from the
// upload, so it does not fail five times over as a misleading network error,
// and call onUnreadable so the uploader can tell the member.
//
// Register it with addPreProcessor AFTER Compressor, so it copies whatever
// Compressor produced and never holds a photo larger than the one we upload.
import { action } from '~/composables/useClientLog'

export const READ_RETRY_DELAY_MS = 500

export const UNREADABLE_PHOTO_MESSAGE =
  "Sorry, we couldn't open that photo. Please try again, or choose a different photo."

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

export function createInMemoryPreProcessor({
  getUppy,
  uploader,
  onUnreadable = null,
}) {
  return async function copyFilesIntoMemory(fileIDs) {
    const uppy = getUppy()
    if (!uppy) return

    let unreadable = 0

    for (const id of fileIDs || []) {
      const file = uppy.getFile(id)
      const data = file?.data
      if (!data || typeof data.arrayBuffer !== 'function') continue

      const startedAt = Date.now()
      let bytes
      let firstError = null

      try {
        bytes = await data.arrayBuffer()
      } catch (e) {
        firstError = e
        await wait(READ_RETRY_DELAY_MS)
        try {
          bytes = await data.arrayBuffer()
        } catch {
          // Still unreadable: dropped below.
        }
      }

      if (bytes) {
        const name = data.name || file.meta?.name || file.name
        const copy = new File([bytes], name, {
          type: data.type || file.type,
          lastModified: data.lastModified,
        })

        uppy.setFileState(id, { data: copy, size: copy.size })
      }

      if (firstError) {
        console.error('Could not read photo before upload', id, firstError)
        action('upload_file_unreadable', {
          uploader,
          error_name: firstError?.name || null,
          reason: firstError?.message || String(firstError),
          file_size: file.size ?? data.size ?? null,
          file_type: file.type || data.type || null,
          elapsed_ms: Date.now() - startedAt,
          // Whether the second read worked, so we can tell if retrying helps.
          recovered: !!bytes,
        })
      }

      if (!bytes) {
        unreadable++
        uppy.removeFile(id)
      }
    }

    if (unreadable && onUnreadable) {
      onUnreadable(unreadable)
    }
  }
}
