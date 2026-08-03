// HEIC/HEIF handling for the Uppy uploaders.
//
// iPhones (and some Android cameras) hand us .heic/.heif files. Most browsers
// cannot decode HEIC at all, and @uppy/compressor re-encodes every image/*
// file through a <canvas> (compressorjs) - so a HEIC either fails to compress
// or, worse, silently produces a black JPEG that we then upload.
//
// The FilePond uploader had a guard for exactly this (commit 353c894a,
// "Not resizing HEIC files, potentially causing photo upload issues"):
// filepond-plugin-image-transform skipped HEIC, and we converted it to JPEG
// ourselves before upload. That guard was lost in the move to Uppy, so HEIC
// uploads regressed. This restores it in two layers:
//
//   1. createHeicPreProcessor() runs as an Uppy pre-processor registered
//      BEFORE Compressor (pre-processors run in the order they are added), so
//      Compressor only ever sees something a canvas can actually draw.
//   2. createHeicSafeCompressor() skips any file that is still HEIC by the
//      time Compressor runs, so a failed conversion uploads the original
//      bytes - which the delivery proxy can cope with - instead of a black
//      JPEG.
//
// Conversion uses heic-to, the maintained successor to heic2any (same
// libheif-in-the-browser approach, still tracking libheif releases). It is
// ~3MB, so it is dynamically imported and only ever fetched by someone who
// actually picks a HEIC file.
import { action } from '~/composables/useClientLog'

const HEIC_MIME_TYPES = [
  'image/heic',
  'image/heif',
  'image/heic-sequence',
  'image/heif-sequence',
]

// libheif is slow on large images and runs on the main thread. If it takes
// longer than this we give up and upload the original rather than leaving the
// user staring at an upload that never starts.
const CONVERT_TIMEOUT_MS = 45000

// Cheap synchronous check - Uppy maps the .heic/.heif extensions to their MIME
// types itself, but files dragged from some sources arrive with an empty or
// wrong type, so check the name too.
export function isHeicFile(file) {
  if (!file) return false

  const type = (file.type || file.data?.type || '').toLowerCase()
  if (HEIC_MIME_TYPES.includes(type)) return true

  const name = (file.name || file.meta?.name || '').toLowerCase()
  return name.endsWith('.heic') || name.endsWith('.heif')
}

function toJpegName(name) {
  if (!name) return 'photo.jpg'
  return name.replace(/\.(heic|heif)$/i, '') + '.jpg'
}

function withTimeout(promise, ms) {
  let timer = null
  const timeout = new Promise((resolve, reject) => {
    timer = setTimeout(() => reject(new Error('HEIC conversion timed out')), ms)
  })

  return Promise.race([promise, timeout]).finally(() => {
    if (timer) clearTimeout(timer)
  })
}

// Returns an Uppy pre-processor which converts any HEIC files in the batch to
// JPEG in place. `uploader` just tags the telemetry so we can tell the two
// uploaders apart in Loki.
export function createHeicPreProcessor({ getUppy, uploader }) {
  return async function convertHeicFiles(fileIDs) {
    const uppy = getUppy()
    if (!uppy) return

    const heicIDs = (fileIDs || []).filter((id) => isHeicFile(uppy.getFile(id)))
    if (!heicIDs.length) return

    console.log('HEIC files to convert', heicIDs.length)
    action('upload_heic_selected', { uploader, file_count: heicIDs.length })

    // libheif can take several seconds per photo, during which the dashboard
    // would otherwise just sit there looking stuck.
    uppy.info(
      heicIDs.length > 1
        ? 'Converting your photos - this can take a few seconds because of the format your phone used.'
        : 'Converting your photo - this can take a few seconds because of the format your phone used.',
      'info',
      10000
    )

    let heicTo = null

    try {
      ;({ heicTo } = await import('heic-to'))
    } catch (e) {
      console.error('Failed to load HEIC converter', e)
      action('upload_heic_convert_failed', {
        uploader,
        file_count: heicIDs.length,
        reason: 'load_failed',
      })
    }

    let failed = 0

    // One at a time: libheif is CPU and memory hungry, and someone adding ten
    // photos at once could otherwise take the tab down.
    for (const id of heicIDs) {
      const file = uppy.getFile(id)
      if (!file) continue

      const startedAt = Date.now()

      try {
        if (!heicTo) throw new Error('HEIC converter unavailable')

        const blob = await withTimeout(
          heicTo({ blob: file.data, type: 'image/jpeg', quality: 0.92 }),
          CONVERT_TIMEOUT_MS
        )

        if (!blob?.size) throw new Error('HEIC conversion produced no data')

        const name = toJpegName(file.meta?.name || file.name)

        // Hand Compressor a named File, not a bare Blob. @uppy/compressor
        // rebuilds the upload filename from its INPUT's name after
        // compressing ("<meta name>.<compressed extension>"), and compressorjs
        // gives a nameless output for a nameless input - so a bare Blob here
        // ends up stored as e.g. "item1.undefined" instead of "item1.jpg".
        const jpeg = new File([blob], name, { type: 'image/jpeg' })

        uppy.setFileState(file.id, {
          data: jpeg,
          type: 'image/jpeg',
          extension: 'jpg',
          size: blob.size,
          name,
          meta: {
            ...file.meta,
            type: 'image/jpeg',
            name,
          },
        })

        action('upload_heic_converted', {
          uploader,
          original_size: file.size ?? file.data?.size ?? null,
          converted_size: blob.size,
          elapsed_ms: Date.now() - startedAt,
        })
      } catch (e) {
        // Leave the file alone. createHeicSafeCompressor keeps Compressor's
        // canvas away from it, so it uploads as the original HEIC.
        failed++
        console.error('HEIC conversion failed', file.id, e)
        action('upload_heic_convert_failed', {
          uploader,
          // heic-to rejects with a bare string in one of its paths, so don't
          // assume an Error.
          reason: e?.message || String(e),
          file_size: file.size ?? file.data?.size ?? null,
          elapsed_ms: Date.now() - startedAt,
        })
      }
    }

    if (failed) {
      uppy.info(
        failed > 1
          ? "We couldn't convert some of your photos, so we'll upload them as they are. If they don't look right, please try again with JPEG photos."
          : "We couldn't convert your photo, so we'll upload it as it is. If it doesn't look right, please try again with a JPEG photo.",
        'warning',
        15000
      )
    }
  }
}

// Wraps @uppy/compressor so that HEIC files never reach compressorjs. Takes the
// base class as a parameter so it can be unit-tested without a real Uppy.
export function createHeicSafeCompressor(BaseCompressor) {
  return class HeicSafeCompressor extends BaseCompressor {
    prepareUpload(fileIDs) {
      const safe = (fileIDs || []).filter(
        (id) => !isHeicFile(this.uppy?.getFile(id))
      )

      if (safe.length !== (fileIDs || []).length) {
        // Only happens if conversion failed - see createHeicPreProcessor.
        console.log('Skipping compression of unconverted HEIC files')
      }

      return super.prepareUpload(safe)
    }
  }
}
