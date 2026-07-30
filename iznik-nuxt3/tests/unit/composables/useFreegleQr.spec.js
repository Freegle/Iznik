import { describe, it, expect, vi, beforeEach } from 'vitest'
import {
  freegleQrOptions,
  createFreegleQr,
  downloadFreegleQr,
} from '~/composables/useFreegleQr'

const mockDownload = vi.fn()
// vitest 4 requires constructor mocks to be constructible (no arrows).
const MockQRCodeStyling = vi.fn(function () {
  return { download: mockDownload }
})

vi.mock('qr-code-styling', () => ({
  default: MockQRCodeStyling,
}))

describe('freegleQrOptions', () => {
  it('uses the given data verbatim when non-empty', () => {
    const options = freegleQrOptions('https://ilovefreegle.org/some/path')
    expect(options.data).toBe('https://ilovefreegle.org/some/path')
  })

  it.each([[undefined], [null], ['']])(
    'falls back to a single space when data is %p (qr-code-styling errors on empty data)',
    (data) => {
      expect(freegleQrOptions(data).data).toBe(' ')
    }
  )

  it('sets Freegle brand styling and high error correction for the embedded logo', () => {
    const options = freegleQrOptions('data')
    expect(options.qrOptions.errorCorrectionLevel).toBe('H')
    expect(options.image).toBe('/icon.png')
    expect(options.width).toBe(320)
    expect(options.height).toBe(320)
    expect(options.dotsOptions.color).toBe('#61AE24')
    expect(options.cornersSquareOptions.color).toBe('#3B8070')
  })
})

describe('createFreegleQr', () => {
  beforeEach(() => {
    MockQRCodeStyling.mockClear()
    mockDownload.mockClear()
  })

  it('constructs a QRCodeStyling instance using freegleQrOptions for the data', async () => {
    await createFreegleQr('hello')

    expect(MockQRCodeStyling).toHaveBeenCalledTimes(1)
    const passedOptions = MockQRCodeStyling.mock.calls[0][0]
    expect(passedOptions.data).toBe('hello')
  })
})

describe('downloadFreegleQr', () => {
  beforeEach(() => {
    MockQRCodeStyling.mockClear()
    mockDownload.mockClear()
  })

  it('downloads a PNG with the default name when none is given', async () => {
    await downloadFreegleQr('some-data')

    expect(mockDownload).toHaveBeenCalledWith({
      name: 'freegle-qr',
      extension: 'png',
    })
  })

  it('downloads a PNG using the given name', async () => {
    await downloadFreegleQr('some-data', 'my-custom-name')

    expect(mockDownload).toHaveBeenCalledWith({
      name: 'my-custom-name',
      extension: 'png',
    })
  })
})
