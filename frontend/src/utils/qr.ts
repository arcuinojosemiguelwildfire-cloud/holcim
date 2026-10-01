import QRCode from 'qrcode'

/**
 * QR rendering. Error correction "Q" (~25% damage tolerance) survives
 * smudged/laminated prints; a 4-module quiet zone is the QR spec minimum.
 */
const QR_OPTIONS = { errorCorrectionLevel: 'Q', margin: 4 } as const

const svgCache = new Map<string, string>()

/** Crisp, resolution-independent SVG markup (screen + print). */
export async function qrSvg(payload: string): Promise<string> {
  const cached = svgCache.get(payload)
  if (cached) return cached
  const svg = await QRCode.toString(payload, { ...QR_OPTIONS, type: 'svg', color: { dark: '#000000', light: '#ffffff' } })
  svgCache.set(payload, svg)
  return svg
}

/**
 * Downloads a print-quality PNG: 1200px QR (about 10cm at 300 dpi) with the
 * attendee code and name underneath.
 */
export async function downloadQrPng(payload: string, attendeeCode: string, fullName: string): Promise<void> {
  const size = 1200
  const qrCanvas = document.createElement('canvas')
  await QRCode.toCanvas(qrCanvas, payload, { ...QR_OPTIONS, width: size, color: { dark: '#000000', light: '#ffffff' } })

  const canvas = document.createElement('canvas')
  canvas.width = size
  canvas.height = size + 190
  const context = canvas.getContext('2d')
  if (!context) throw new Error('Your browser cannot create images.')

  context.fillStyle = '#ffffff'
  context.fillRect(0, 0, canvas.width, canvas.height)
  context.drawImage(qrCanvas, 0, 0, size, size)
  context.fillStyle = '#000000'
  context.textAlign = 'center'
  context.font = 'bold 72px ui-monospace, Menlo, Consolas, monospace'
  context.fillText(attendeeCode, size / 2, size + 60)
  context.font = '48px system-ui, -apple-system, Segoe UI, Arial, sans-serif'
  context.fillText(fullName.length > 40 ? `${fullName.slice(0, 39)}…` : fullName, size / 2, size + 140)

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'))
  if (!blob) throw new Error('Could not create the image.')
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `${attendeeCode}-QR.png`
  document.body.appendChild(link)
  link.click()
  link.remove()
  setTimeout(() => URL.revokeObjectURL(url), 1000)
}

/** Opens the print sheet in a new tab. No ids = all active attendees with a QR. */
export function openQrPrintSheet(ids?: number[]): void {
  const base = import.meta.env.BASE_URL.replace(/\/+$/, '')
  const query = ids && ids.length > 0 ? `?ids=${ids.join(',')}` : ''
  window.open(`${base}/print/qr${query}`, '_blank', 'noopener')
}
