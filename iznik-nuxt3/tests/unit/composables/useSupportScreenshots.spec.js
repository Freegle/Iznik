import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import {
  useSupportScreenshots,
  prepareScreenshot,
  forHelper,
  MAX_SCREENSHOTS,
} from '~/modtools/composables/useSupportScreenshots'

// jsdom can neither decode a picture nor draw on a canvas, so both are stood in for.
let naturalWidth = 800
let naturalHeight = 600
const drawn = []

beforeEach(() => {
  naturalWidth = 800
  naturalHeight = 600
  drawn.length = 0
  vi.stubGlobal(
    'Image',
    class {
      set src(v) {
        this._src = v
        setTimeout(() => {
          if (v.includes('QlJPS0VO')) this.onerror?.()
          else this.onload?.()
        })
      }

      get naturalWidth() {
        return naturalWidth
      }

      get naturalHeight() {
        return naturalHeight
      }
    }
  )
  HTMLCanvasElement.prototype.getContext = () => ({
    fillRect() {},
    drawImage(...a) {
      drawn.push(a)
    },
  })
  HTMLCanvasElement.prototype.toDataURL = () => 'data:image/jpeg;base64,SCALED'
})

afterEach(() => vi.unstubAllGlobals())

function file(name, type, content = 'x', size) {
  const f = new File([content], name, { type })
  if (size) Object.defineProperty(f, 'size', { value: size })
  return f
}

describe('prepareScreenshot', () => {
  it('keeps a small picture as it is', async () => {
    const shot = await prepareScreenshot(file('a.png', 'image/png'))
    expect(shot.mime).toBe('image/png')
    expect(shot.name).toBe('a.png')
    expect(shot.preview).toContain('data:image/png;base64,')
    expect(shot.data).not.toContain('data:')
  })

  it('scales a big picture down to a JPEG', async () => {
    naturalWidth = 3200
    naturalHeight = 1600
    const shot = await prepareScreenshot(file('big.png', 'image/png'))
    expect(shot.mime).toBe('image/jpeg')
    expect(shot.name).toBe('big.jpg')
    expect(shot.data).toBe('SCALED')
    // 3200 across becomes 1600, keeping the shape.
    expect(drawn[0][3]).toBe(1600)
    expect(drawn[0][4]).toBe(800)
  })

  it('keeps a GIF whatever its size', async () => {
    naturalWidth = 5000
    const shot = await prepareScreenshot(file('a.gif', 'image/gif'))
    expect(shot.mime).toBe('image/gif')
  })
})

describe('useSupportScreenshots', () => {
  it('adds several pictures at once', async () => {
    const { screenshots, addFiles, screenshotError } = useSupportScreenshots()
    await addFiles([file('1.png', 'image/png'), file('2.png', 'image/png')])
    expect(screenshots.value).toHaveLength(2)
    expect(screenshotError.value).toBe('')
  })

  it('refuses a file that is not a picture and keeps the others', async () => {
    const { screenshots, addFiles, screenshotError } = useSupportScreenshots()
    await addFiles([
      file('notes.pdf', 'application/pdf'),
      file('1.png', 'image/png'),
    ])
    expect(screenshots.value).toHaveLength(1)
    expect(screenshotError.value).toMatch(/PNG, JPEG, GIF or WebP/)
  })

  it('stops at the limit and says so', async () => {
    const { screenshots, addFiles, screenshotError } = useSupportScreenshots()
    await addFiles(
      Array.from({ length: MAX_SCREENSHOTS + 2 }, (_, i) =>
        file(`${i}.png`, 'image/png')
      )
    )
    expect(screenshots.value).toHaveLength(MAX_SCREENSHOTS)
    expect(screenshotError.value).toContain(String(MAX_SCREENSHOTS))
  })

  it('reports a file that cannot be read as a picture', async () => {
    const { screenshots, addFiles, screenshotError } = useSupportScreenshots()
    await addFiles([file('BROKEN.png', 'image/png', 'BROKEN', 3 * 1024 * 1024)])
    expect(screenshots.value).toHaveLength(0)
    expect(screenshotError.value).toMatch(/could not be read/)
  })

  it('takes pictures pasted from the clipboard', async () => {
    const { screenshots, onPaste } = useSupportScreenshots()
    const preventDefault = vi.fn()
    onPaste({
      preventDefault,
      clipboardData: { files: [file('p.png', 'image/png')] },
    })
    await new Promise((r) => setTimeout(r, 20))
    expect(preventDefault).toHaveBeenCalled()
    expect(screenshots.value).toHaveLength(1)
  })

  it('leaves ordinary text pastes alone', () => {
    const { screenshots, onPaste } = useSupportScreenshots()
    const preventDefault = vi.fn()
    onPaste({ preventDefault, clipboardData: { files: [] } })
    expect(preventDefault).not.toHaveBeenCalled()
    expect(screenshots.value).toHaveLength(0)
  })

  it('removes one, and taking them empties the list', async () => {
    const { screenshots, addFiles, removeScreenshot, takeScreenshots } =
      useSupportScreenshots()
    await addFiles([file('1.png', 'image/png'), file('2.png', 'image/png')])
    removeScreenshot(0)
    expect(screenshots.value.map((s) => s.name)).toEqual(['2.png'])
    expect(takeScreenshots()).toHaveLength(1)
    expect(screenshots.value).toHaveLength(0)
  })

  it('forHelper drops the preview', () => {
    expect(
      forHelper([{ name: 'a', mime: 'image/png', data: 'D', preview: 'P' }])
    ).toEqual([{ name: 'a', mime: 'image/png', data: 'D' }])
    expect(forHelper(undefined)).toEqual([])
  })
})
