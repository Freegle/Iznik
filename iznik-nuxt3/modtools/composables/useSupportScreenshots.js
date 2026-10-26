import { ref } from 'vue'

// Screenshots a support volunteer attaches to a question for the AI Support Helper. Each is scaled
// down in the browser so several fit in one request, then sent as {name, mime, data} with `data`
// base64. The helper checks them again; nothing here is trusted.
export const MAX_SCREENSHOTS = 5
const MAX_EDGE = 1600
const ACCEPTED = ['image/png', 'image/jpeg', 'image/gif', 'image/webp']

function readAsDataUrl(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(reader.result)
    reader.onerror = () => reject(reader.error)
    reader.readAsDataURL(file)
  })
}

function loadImage(src) {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => resolve(img)
    img.onerror = () => reject(new Error('not a picture'))
    img.src = src
  })
}

// Scale a picture so its longest edge is at most MAX_EDGE, as a JPEG. A GIF, or a picture that is
// already small enough, is kept as it is so animation and exact pixels are not lost for nothing.
export async function prepareScreenshot(file) {
  const original = await readAsDataUrl(file)
  const mime = (file.type || '').toLowerCase()
  const keep = () => ({
    name: file.name || 'screenshot',
    mime,
    data: original.split(',')[1],
    preview: original,
  })

  if (mime === 'image/gif') return keep()

  const img = await loadImage(original)
  const longest = Math.max(img.naturalWidth, img.naturalHeight)
  if (longest <= MAX_EDGE && file.size <= 1.5 * 1024 * 1024) return keep()

  const scale = Math.min(1, MAX_EDGE / longest)
  const canvas = document.createElement('canvas')
  canvas.width = Math.round(img.naturalWidth * scale)
  canvas.height = Math.round(img.naturalHeight * scale)
  const ctx = canvas.getContext('2d')
  // A transparent PNG would turn black as a JPEG.
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, canvas.width, canvas.height)
  ctx.drawImage(img, 0, 0, canvas.width, canvas.height)
  const url = canvas.toDataURL('image/jpeg', 0.85)
  return {
    name: (file.name || 'screenshot').replace(/\.[A-Za-z0-9]+$/, '') + '.jpg',
    mime: 'image/jpeg',
    data: url.split(',')[1],
    preview: url,
  }
}

export function useSupportScreenshots() {
  const screenshots = ref([])
  const screenshotError = ref('')

  async function addFiles(fileList) {
    screenshotError.value = ''
    const files = Array.from(fileList || [])
    for (const file of files) {
      if (!ACCEPTED.includes((file.type || '').toLowerCase())) {
        screenshotError.value =
          'Only PNG, JPEG, GIF or WebP pictures can be attached.'
        continue
      }
      if (screenshots.value.length >= MAX_SCREENSHOTS) {
        screenshotError.value = `You can attach up to ${MAX_SCREENSHOTS} screenshots to a question.`
        break
      }
      try {
        screenshots.value.push(await prepareScreenshot(file))
      } catch (e) {
        screenshotError.value =
          'One of the files could not be read as a picture.'
      }
    }
  }

  // Pasting a screenshot straight into the question box is the quickest way to attach one.
  function onPaste(event) {
    const files = Array.from(event.clipboardData?.files || []).filter((f) =>
      (f.type || '').startsWith('image/')
    )
    if (files.length) {
      event.preventDefault()
      addFiles(files)
    }
  }

  function removeScreenshot(index) {
    screenshots.value.splice(index, 1)
    screenshotError.value = ''
  }

  // What goes to the helper: the picture without its preview URL.
  function takeScreenshots() {
    const taken = screenshots.value
    screenshots.value = []
    screenshotError.value = ''
    return taken
  }

  return {
    screenshots,
    screenshotError,
    addFiles,
    onPaste,
    removeScreenshot,
    takeScreenshots,
  }
}

export function forHelper(list) {
  return (list || []).map(({ name, mime, data }) => ({ name, mime, data }))
}
