import { cleanText } from '../../utils/qr'
import { cn } from '../../utils/cn'
import { QrImage } from './QrImage'

interface QrCardProps {
  payload: string
  attendeeCode: string
  fullName: string | null | undefined
  company: string | null | undefined
  /** Cluster (location / region). */
  department: string | null | undefined
  className?: string
}

/**
 * On-screen attendee QR card: QR, then full name, company and cluster
 * (location; stored as department), each only if present
 * centred underneath. The attendee code is used only for the accessible label.
 */
export function QrCard({ payload, attendeeCode, fullName, company, department, className }: QrCardProps) {
  const name = cleanText(fullName)
  const companyName = cleanText(company)
  const dept = cleanText(department)

  return (
    <figure className={cn('flex flex-col items-center rounded border border-slate-200 bg-white px-2 pb-3 text-center', className)} data-testid="qr-card">
      <QrImage payload={payload} label={`QR code for ${name || attendeeCode}`} className="w-full" />
      <figcaption className="-mt-1 w-full">
        {name && <p className="break-words text-base font-bold leading-tight text-slate-900">{name}</p>}
        {companyName && <p className="mt-0.5 break-words text-sm font-medium leading-tight text-slate-800">{companyName}</p>}
        {dept && <p className="mt-0.5 break-words text-xs leading-tight text-slate-600">{dept}</p>}
      </figcaption>
    </figure>
  )
}
