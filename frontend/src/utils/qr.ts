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

/** Attendee details printed under a QR (never the token or internal IDs). */
export interface QrCardDetails {
  attendeeCode: string
  fullName: string | null | undefined
  department: string | null | undefined
}

/** Trimmed text, or '' for null/undefined/blank (so "null" is never shown). */
export function cleanText(value: string | null | undefined): string {
  return typeof value === 'string' ? value.replace(/\s+/g, ' ').trim() : ''
}

/** Filesystem-safe part: ASCII letters, digits and hyphens only. */
function fileSafe(value: string): string {
  return value
    .normalize('NFKD')
    .replace(/[̀-ͯ]/g, '')
    .replace(/[^A-Za-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 60)
}

/** "ATT-0001-Juan-Delacruz.png" (falls back to "ATT-0001-QR.png"). Never contains the token. */
export function qrFilename(attendeeCode: string, fullName: string | null | undefined): string {
  const code = fileSafe(attendeeCode) || 'attendee'
  const name = fileSafe(cleanText(fullName))
  return `${code}-${name || 'QR'}.png`
}

/** Splits text into at most `maxLines` lines that fit `maxWidth` (last line gets "…"). */
function wrapText(context: CanvasRenderingContext2D, text: string, maxWidth: number, maxLines: number): string[] {
  const words = text.split(' ')
  const lines: string[] = []
  let line = ''
  for (const word of words) {
    const candidate = line ? `${line} ${word}` : word
    if (context.measureText(candidate).width <= maxWidth || line === '') {
      line = candidate
    } else {
      lines.push(line)
      line = word
    }
  }
  if (line) lines.push(line)
  if (lines.length <= maxLines) return lines.map((l) => fitWidth(context, l, maxWidth))
  const kept = lines.slice(0, maxLines)
  kept[maxLines - 1] = fitWidth(context, `${kept[maxLines - 1]} ${lines.slice(maxLines).join(' ')}`, maxWidth)
  return kept.map((l) => fitWidth(context, l, maxWidth))
}

function fitWidth(context: CanvasRenderingContext2D, text: string, maxWidth: number): string {
  if (context.measureText(text).width <= maxWidth) return text
  let cut = text
  while (cut.length > 1 && context.measureText(`${cut}…`).width > maxWidth) cut = cut.slice(0, -1)
  return `${cut.trimEnd()}…`
}

/**
 * Downloads a print-quality QR card PNG: the 1200px QR (about 10 cm at
 * 300 dpi, same rendering as before) with the attendee's full name (bold)
 * and, if present, department centred underneath. Nothing else is drawn
 * (no attendee code, token or internal ID); the code is only used in the
 * file name.
 * Drawn on its own canvas, not a screenshot of the page.
 */
export async function downloadQrPng(payload: string, details: QrCardDetails): Promise<void> {
  const size = 1200
  const padding = 80
  const textWidth = size - padding * 2
  const qrCanvas = document.createElement('canvas')
  await QRCode.toCanvas(qrCanvas, payload, { ...QR_OPTIONS, width: size, color: { dark: '#000000', light: '#ffffff' } })

  const sans = 'system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif'
  const measure = document.createElement('canvas').getContext('2d')
  if (!measure) throw new Error('Your browser cannot create images.')
  measure.font = `bold 80px ${sans}`
  const nameLines = cleanText(details.fullName) ? wrapText(measure, cleanText(details.fullName), textWidth, 2) : []
  measure.font = `56px ${sans}`
  const departmentLines = cleanText(details.department) ? wrapText(measure, cleanText(details.department), textWidth, 2) : []

  // Layout (y = baseline): QR, then name lines, department lines, code.
  const rows: Array<{ text: string; font: string; color: string; advance: number }> = [
    ...nameLines.map((text) => ({ text, font: `bold 80px ${sans}`, color: '#000000', advance: 96 })),
    ...departmentLines.map((text, index) => ({ text, font: `56px ${sans}`, color: '#1f2937', advance: index === 0 ? 84 : 68 })),
  ]
  const textHeight = rows.reduce((sum, row) => sum + row.advance, 0)

  const canvas = document.createElement('canvas')
  canvas.width = size
  canvas.height = size + textHeight + (rows.length > 0 ? 60 : 0)
  const context = canvas.getContext('2d')
  if (!context) throw new Error('Your browser cannot create images.')

  context.fillStyle = '#ffffff'
  context.fillRect(0, 0, canvas.width, canvas.height)
  context.drawImage(qrCanvas, 0, 0, size, size)
  context.textAlign = 'center'
  context.textBaseline = 'alphabetic'
  let y = size - 10 // the QR's white quiet zone already separates it from the text
  for (const row of rows) {
    y += row.advance
    context.font = row.font
    context.fillStyle = row.color
    context.fillText(row.text, size / 2, y)
  }

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/png'))
  if (!blob) throw new Error('Could not create the image.')
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = qrFilename(details.attendeeCode, details.fullName)
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
