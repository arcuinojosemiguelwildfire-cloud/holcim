import { useCallback, useState } from 'react'
import { useSearchParams } from 'react-router'
import { Printer } from 'lucide-react'
import { QrImage } from '../components/qr/QrImage'
import { Alert } from '../components/ui/Alert'
import { Button } from '../components/ui/Button'
import { Spinner } from '../components/ui/Spinner'
import { useApiQuery } from '../hooks/useApiQuery'
import { qrService } from '../services/qrService'
import type { QrPrintItem } from '../types/qr'
import { cn } from '../utils/cn'

/**
 * Print sheet for attendee QR labels (opened in its own tab, no app chrome).
 *
 * Items are split into explicit A4 pages so a label is never cut across a page
 * break. Sizes favour scan reliability over labels per page:
 *   Standard: 3 x 4 = 12 per page, QR 44 mm
 *   Large:    2 x 3 =  6 per page, QR 64 mm
 */
const LAYOUTS = {
  standard: { perPage: 12, columns: 3, card: 'h-[68mm] w-[63mm]', qr: 'w-[44mm]', label: 'Standard (12 per page)' },
  large: { perPage: 6, columns: 2, card: 'h-[90mm] w-[95mm]', qr: 'w-[64mm]', label: 'Large (6 per page)' },
} as const
type LayoutKey = keyof typeof LAYOUTS

function chunk<T>(items: T[], size: number): T[][] {
  const pages: T[][] = []
  for (let i = 0; i < items.length; i += size) pages.push(items.slice(i, i + size))
  return pages
}

export function QrPrintPage() {
  const [params] = useSearchParams()
  const idsParam = params.get('ids') ?? ''
  const fetcher = useCallback(() => {
    const ids = idsParam.split(',').map(Number).filter((n) => Number.isInteger(n) && n > 0)
    return qrService.printData(ids.length > 0 ? ids : null)
  }, [idsParam])
  const { data, error, loading } = useApiQuery(fetcher)
  const [layoutKey, setLayoutKey] = useState<LayoutKey>('standard')
  const layout = LAYOUTS[layoutKey]

  return (
    <div className="min-h-screen bg-slate-200 print:bg-white">
      <style>{'@page { size: A4 portrait; margin: 10mm; } @media print { body { background: #fff !important; } }'}</style>

      <div className="sticky top-0 z-10 flex flex-wrap items-center gap-3 border-b border-slate-300 bg-white px-6 py-3 shadow-sm print:hidden">
        <div className="min-w-0 flex-1">
          <p className="font-semibold text-slate-900">Attendee QR labels</p>
          <p className="text-sm text-slate-500">
            {data ? `${data.event.name} · ${data.items.length} label${data.items.length === 1 ? '' : 's'} · ${Math.ceil(data.items.length / layout.perPage)} A4 page(s)` : 'Loading…'}
          </p>
        </div>
        <label className="flex items-center gap-2 text-sm text-slate-600">
          Size
          <select
            value={layoutKey}
            onChange={(e) => setLayoutKey(e.target.value as LayoutKey)}
            className="h-9 rounded-lg border-0 py-0 pl-3 pr-8 text-sm ring-1 ring-inset ring-slate-300"
          >
            {(Object.keys(LAYOUTS) as LayoutKey[]).map((key) => (
              <option key={key} value={key}>{LAYOUTS[key].label}</option>
            ))}
          </select>
        </label>
        <Button onClick={() => window.print()} disabled={!data || data.items.length === 0} icon={<Printer className="size-4" aria-hidden />}>
          Print
        </Button>
      </div>

      <div className="px-4 py-6 print:p-0">
        {error && <Alert tone="error" className="mx-auto max-w-xl">{error}</Alert>}
        {loading && !data && <Spinner label="Preparing labels…" />}
        {data && data.items.length === 0 && (
          <Alert tone="info" className="mx-auto max-w-xl" title="Nothing to print">
            No active attendees with a generated QR code match this selection. Generate QR codes on the QR / ID Generator page first.
          </Alert>
        )}
        {data && data.items.length > 0 && !/^https:\/\//i.test(data.items[0]?.qrPayload ?? '') && (
          <Alert tone="error" className="mx-auto mb-4 max-w-[210mm] print:hidden" title="Test labels only">
            These QR codes do not use a public HTTPS address (APP_URL). Set APP_URL before printing labels for the event.
          </Alert>
        )}
        {data && data.items.length > 0 && (
          <p className="mx-auto mb-4 max-w-[210mm] text-sm text-slate-600 print:hidden">
            Print at <b>100% / Actual size</b> (not “Fit to page”) on A4 paper. Dashed lines are cutting guides.
          </p>
        )}
        {data &&
          chunk(data.items, layout.perPage).map((page, index) => (
            <section
              key={`${layoutKey}-${index}`}
              className="mx-auto mb-6 w-[210mm] bg-white p-[10mm] shadow print:mb-0 print:w-auto print:break-after-page print:p-0 print:shadow-none print:last:break-after-auto"
            >
              <div className={cn('grid justify-center', layout.columns === 3 ? 'grid-cols-[repeat(3,63mm)]' : 'grid-cols-[repeat(2,95mm)]')}>
                {page.map((item) => (
                  <QrLabel key={item.id} item={item} cardClass={layout.card} qrClass={layout.qr} />
                ))}
              </div>
            </section>
          ))}
      </div>
    </div>
  )
}

function QrLabel({ item, cardClass, qrClass }: { item: QrPrintItem; cardClass: string; qrClass: string }) {
  return (
    <div className={cn('flex break-inside-avoid flex-col items-center justify-center border border-dashed border-slate-400 px-[3mm] text-center text-black', cardClass)}>
      <QrImage payload={item.qrPayload} label={`QR code for ${item.attendeeCode}`} className={qrClass} />
      <p className="mt-[1mm] font-mono text-[11pt] font-bold leading-tight">{item.attendeeCode}</p>
      <p className="line-clamp-2 w-full text-[9pt] font-semibold leading-tight">{item.fullName}</p>
      {item.department && <p className="w-full truncate text-[8pt] leading-tight text-neutral-700">{item.department}</p>}
    </div>
  )
}
