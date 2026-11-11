import { describe, it, expect, vi, afterEach } from 'vitest'
import { analyzeBlur } from '~/composables/useBlurDetector'

// Stand-in canvas: the drawn image is replaced by a pixel generator so the
// real grayscale -> Laplacian -> variance pipeline runs on known data.
function mockCanvas(pixelFn) {
  const created = { canvas: null, drawArgs: null }
  const realCreate = document.createElement.bind(document)
  vi.spyOn(document, 'createElement').mockImplementation((tag) => {
    if (tag !== 'canvas') return realCreate(tag)
    const canvas = {
      width: 0,
      height: 0,
      getContext: () => ({
        drawImage: (...args) => {
          created.drawArgs = args
        },
        getImageData: (_x, _y, w, h) => {
          const data = new Uint8ClampedArray(w * h * 4)
          for (let y = 0; y < h; y++) {
            for (let x = 0; x < w; x++) {
              const [r, g, b] = pixelFn(x, y)
              const i = (y * w + x) * 4
              data[i] = r
              data[i + 1] = g
              data[i + 2] = b
              data[i + 3] = 255
            }
          }
          return { data, width: w, height: h }
        },
      }),
    }
    created.canvas = canvas
    return canvas
  })
  return created
}

const grey = (v) => [v, v, v]

afterEach(() => {
  vi.restoreAllMocks()
})

describe('analyzeBlur pixel pipeline', () => {
  it('scores a flat image as zero variance', async () => {
    mockCanvas(() => grey(128))
    expect(await analyzeBlur({ width: 100, height: 100 })).toBe(0)
  })

  it('scores a smooth gradient below the warning threshold', async () => {
    mockCanvas((x) => grey(x * 4))
    const score = await analyzeBlur({ width: 50, height: 50 }, 50)
    // Constant slope has no second derivative; only grayscale truncation
    // and the unconvolved border contribute.
    expect(score).toBeLessThan(10)
  })

  it('scores a checkerboard far above the acceptable threshold', async () => {
    mockCanvas((x, y) => grey((x + y) % 2 ? 255 : 0))
    expect(await analyzeBlur({ width: 64, height: 64 }, 64)).toBeGreaterThan(
      1000
    )
  })

  it('scores a sharp edge higher than a soft edge', async () => {
    mockCanvas((x) => grey(x < 32 ? 0 : 255))
    const sharp = await analyzeBlur({ width: 64, height: 64 }, 64)
    vi.restoreAllMocks()
    mockCanvas((x) => grey(Math.max(0, Math.min(255, (x - 24) * 32))))
    const soft = await analyzeBlur({ width: 64, height: 64 }, 64)
    expect(sharp).toBeGreaterThan(soft)
    expect(soft).toBeGreaterThan(0)
  })

  it('uses luminosity weights so green contributes more than blue', async () => {
    // Same edge contrast in one channel only: green (0.587) vs blue (0.114).
    mockCanvas((x) => [0, x < 16 ? 0 : 255, 0])
    const green = await analyzeBlur({ width: 32, height: 32 }, 32)
    vi.restoreAllMocks()
    mockCanvas((x) => [0, 0, x < 16 ? 0 : 255])
    const blue = await analyzeBlur({ width: 32, height: 32 }, 32)
    expect(green).toBeGreaterThan(blue)
    expect(blue).toBeGreaterThan(0)
  })

  it('ignores the one pixel border (border is never convolved)', async () => {
    // Only the outermost ring is non-zero; interior Laplacian reads it only
    // from pixels adjacent to the ring.
    mockCanvas((x, y) =>
      x === 0 || y === 0 || x === 9 || y === 9 ? grey(255) : grey(0)
    )
    const score = await analyzeBlur({ width: 10, height: 10 }, 10)
    expect(score).toBeGreaterThan(0)
  })

  it('returns zero for images too small to have interior pixels', async () => {
    mockCanvas((x, y) => grey((x + y) % 2 ? 255 : 0))
    expect(await analyzeBlur({ width: 2, height: 2 }, 2)).toBe(0)
  })
})

describe('analyzeBlur resizing', () => {
  it.each([
    // [imgW, imgH, sampleSize, expectedW, expectedH]
    [1024, 1024, 256, 256, 256],
    [2000, 1000, 256, 256, 128],
    [1000, 2000, 256, 128, 256],
    [100, 100, 256, 256, 256], // upscales small images to the sample size
    [1000, 333, 100, 100, 33], // floors fractional dimensions
    [640, 480, 64, 64, 48],
  ])(
    'resizes %ix%i with sampleSize %i to %ix%i',
    async (w, h, size, ew, eh) => {
      const created = mockCanvas(() => grey(10))
      await analyzeBlur({ width: w, height: h }, size)
      expect(created.canvas.width).toBe(ew)
      expect(created.canvas.height).toBe(eh)
      expect(created.drawArgs.slice(1)).toEqual([0, 0, ew, eh])
    }
  )

  it('defaults the sample size to 256', async () => {
    const created = mockCanvas(() => grey(10))
    await analyzeBlur({ width: 512, height: 512 })
    expect(created.canvas.width).toBe(256)
  })
})

describe('analyzeBlur with a URL string', () => {
  const RealImage = globalThis.Image

  afterEach(() => {
    globalThis.Image = RealImage
  })

  it('loads the URL into an Image and analyses it once onload fires', async () => {
    let srcSeen
    globalThis.Image = class {
      constructor() {
        this.width = 64
        this.height = 64
      }

      set src(v) {
        srcSeen = v
        queueMicrotask(() => this.onload())
      }
    }
    const created = mockCanvas((x, y) => grey((x + y) % 2 ? 255 : 0))
    const score = await analyzeBlur('data:image/png;base64,AAAA', 64)
    expect(srcSeen).toBe('data:image/png;base64,AAAA')
    expect(created.canvas.width).toBe(64)
    expect(score).toBeGreaterThan(1000)
  })

  it('rejects with the error event when the image fails to load', async () => {
    const err = new Error('boom')
    globalThis.Image = class {
      set src(_) {
        queueMicrotask(() => this.onerror(err))
      }
    }
    await expect(analyzeBlur('blob:bad')).rejects.toBe(err)
  })

  it('does not fire the timeout after a successful load', async () => {
    vi.useFakeTimers()
    try {
      globalThis.Image = class {
        constructor() {
          this.width = 8
          this.height = 8
        }

        set src(_) {
          queueMicrotask(() => this.onload())
        }
      }
      mockCanvas(() => grey(5))
      const p = analyzeBlur('data:x', 8)
      await vi.advanceTimersByTimeAsync(1)
      await expect(p).resolves.toBe(0)
      expect(vi.getTimerCount()).toBe(0)
    } finally {
      vi.useRealTimers()
    }
  })

  it('clears the timeout when the load errors', async () => {
    vi.useFakeTimers()
    try {
      globalThis.Image = class {
        set src(_) {
          queueMicrotask(() => this.onerror('x'))
        }
      }
      const p = analyzeBlur('data:x')
      const assertion = expect(p).rejects.toBe('x')
      await vi.advanceTimersByTimeAsync(1)
      await assertion
      expect(vi.getTimerCount()).toBe(0)
    } finally {
      vi.useRealTimers()
    }
  })
})
