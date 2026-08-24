// Freegle-branded QR codes via qr-code-styling.
//
// qr-code-styling manipulates the DOM, so it is imported dynamically and these
// helpers must only be called on the client (e.g. inside onMounted or an event
// handler), never during SSR.

// Brand colours (see assets/css/_color-vars.scss).
const FREEGLE_GREEN = '#61AE24'
const FREEGLE_TEAL = '#3B8070'

// Styling options for a Freegle-branded QR code with the logo embedded in the
// centre. Error-correction level H keeps the code scannable despite the logo.
export function freegleQrOptions(data) {
  return {
    width: 320,
    height: 320,
    type: 'canvas',
    // qr-code-styling errors on empty data; a single space is a safe stand-in.
    data: data || ' ',
    margin: 8,
    image: '/icon.png',
    qrOptions: { errorCorrectionLevel: 'H' },
    imageOptions: {
      crossOrigin: 'anonymous',
      margin: 6,
      imageSize: 0.32,
      hideBackgroundDots: true,
    },
    dotsOptions: { color: FREEGLE_GREEN, type: 'rounded' },
    cornersSquareOptions: { color: FREEGLE_TEAL, type: 'extra-rounded' },
    cornersDotOptions: { color: FREEGLE_GREEN, type: 'dot' },
    backgroundOptions: { color: '#ffffff' },
  }
}

// Create a configured QRCodeStyling instance for the given data.
export async function createFreegleQr(data) {
  const { default: QRCodeStyling } = await import('qr-code-styling')
  return new QRCodeStyling(freegleQrOptions(data))
}

// Build and download a Freegle-branded QR code as a PNG.
export async function downloadFreegleQr(data, name = 'freegle-qr') {
  const qr = await createFreegleQr(data)
  await qr.download({ name, extension: 'png' })
}
