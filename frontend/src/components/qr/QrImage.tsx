import { useEffect, useState } from 'react'
import { qrSvg } from '../../utils/qr'
import { cn } from '../../utils/cn'

interface QrImageProps {
  payload: string
  label: string
  className?: string
}

/** Renders a QR code as inline SVG (sharp on screen and in print). */
export function QrImage({ payload, label, className }: QrImageProps) {
  const [rendered, setRendered] = useState<{ payload: string; svg: string } | null>(null)

  useEffect(() => {
    let cancelled = false
    qrSvg(payload).then((svg) => {
      if (!cancelled) setRendered({ payload, svg })
    })
    return () => {
      cancelled = true
    }
  }, [payload])

  const svg = rendered?.payload === payload ? rendered.svg : null

  return (
    <div
      role="img"
      aria-label={label}
      className={cn('aspect-square bg-white [&>svg]:block [&>svg]:h-full [&>svg]:w-full', className)}
      // SVG markup comes from the qrcode library, not from user input.
      dangerouslySetInnerHTML={svg ? { __html: svg } : undefined}
    />
  )
}
