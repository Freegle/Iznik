import { useRuntimeConfig } from '#app'
import { useImageStore } from '~/stores/image'

// Upload a single image File/Blob straight to the TUS endpoint and register it
// as an attachment, returning the photo object the bulk editor stores on an
// item. This backs the composer's per-row "Add photo" button — the one-photo-
// per-item flow. (The batch flow, where you upload a pile of photos and drag
// each onto its item, goes through PhotoUploader instead.)
//
// tus-js-client is imported lazily inside the upload call so it isn't pulled
// into the main bundle (or loaded by unit tests) just for this affordance.
export function useBulkPhotoUpload() {
  const runtimeConfig = useRuntimeConfig()
  const imageStore = useImageStore()

  async function uploadPhoto(file, { type = 'Message', onProgress } = {}) {
    const tus = await import('tus-js-client')

    // 1. Push the bytes to the TUS uploader, then derive the uid the API wants.
    const externaluid = await new Promise((resolve, reject) => {
      const upload = new tus.Upload(file, {
        endpoint: runtimeConfig.public.TUS_UPLOADER,
        retryDelays: [0, 3000, 5000, 10000, 20000],
        onError: reject,
        onProgress: (uploaded, total) => {
          if (onProgress && total) {
            onProgress(Math.round((uploaded / total) * 100))
          }
        },
        onSuccess: () => {
          const url = upload.url
          resolve('freegletusd-' + url.substring(url.lastIndexOf('/') + 1))
        },
      })
      upload.findPreviousUploads().then((previousUploads) => {
        if (previousUploads.length) {
          upload.resumeFromPreviousUpload(previousUploads[0])
        }
        upload.start()
      })
    })

    // 2. Register the attachment on the server. The server returns a processed
    //    URL (EXIF stripped) which we use rather than the raw uploaded one.
    const ret = await imageStore.post({
      imgtype: type,
      externaluid,
      externalmods: {},
      recognise: false,
    })

    return {
      id: ret.id,
      path: ret.url,
      paththumb: ret.url,
      ouruid: ret.uid,
      info: ret.info,
    }
  }

  return { uploadPhoto }
}
