import { cleanText } from '../../utils/qr'
import { cn } from '../../utils/cn'
import { QrImage } from './QrImage'

interface QrCardProps {
  payload: string
  attendeeCode: string
  fullName: string | null | undefined
  department: string | null | undefined
  className?: string
}

/** On-screen attendee QR card: QR, then full name and department centred underneath. */
export function QrCard({ payload, attendeeCode, fullName, department, className }: QrCardProps) {
  const name = cleanText(fullName)
  const dept = cleanText(department)

  return (
    <figure className={cn('flex flex-col items-center rounded border border-slate-200 bg-white px-2 pb-3 text-center', className)} data-testid="qr-card">
      <QrImage payload={payload} label={`QR code for ${attendeeCode}`} className="w-full" />
      <figcaption className="-mt-1 w-full">
        {name && <p className="break-words text-base font-bold leading-tight text-slate-900">{name}</p>}
        {dept && <p className="mt-0.5 break-words text-sm leading-tight text-slate-700">{dept}</p>}
        <p className="mt-1 font-mono text-xs text-slate-500">{attendeeCode}</p>
      </figcaption>
    </figure>
  )
}
