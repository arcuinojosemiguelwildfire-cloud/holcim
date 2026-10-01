import { useRef, useState, type ChangeEvent } from 'react'
import { ArrowLeft, CircleCheck, FileSpreadsheet, FileUp, Upload } from 'lucide-react'
import { Alert } from '../components/ui/Alert'
import { Badge, type BadgeTone } from '../components/ui/Badge'
import { Button, ButtonLink } from '../components/ui/Button'
import { Card, CardHeader } from '../components/ui/Card'
import { PageHeader } from '../components/ui/PageHeader'
import { ApiError, errorMessage } from '../services/apiClient'
import {
  MAJOR_FIELDS,
  majorService,
  type MajorField,
  type MajorImportResult,
  type MajorMapping,
  type MajorParsedFile,
  type MajorPreview,
  type MajorRowResult,
} from '../services/majorService'
import { cn } from '../utils/cn'
import { formatNumber } from '../utils/format'

const MAX_FILE_BYTES = 5 * 1024 * 1024

const FIELD_INFO: Record<MajorField, { label: string; hint: string }> = {
  attendee_code: { label: 'Attendee Code', hint: 'Strongest, e.g. ATT-0001 (if the form asked for it)' },
  external_identifier: { label: 'External Identifier', hint: 'e.g. Employee ID / Employee No.' },
  email: { label: 'Email', hint: 'e.g. Email Address' },
  full_name: { label: 'Full Name', hint: 'Used together with Department' },
  department: { label: 'Department', hint: 'Used together with Full Name' },
}

const RESULT_STYLE: Record<MajorRowResult, { label: string; tone: BadgeTone }> = {
  matched: { label: 'Will become eligible', tone: 'success' },
  already_eligible: { label: 'Already eligible', tone: 'brand' },
  unmatched: { label: 'Unmatched', tone: 'warning' },
  ambiguous: { label: 'Ambiguous', tone: 'warning' },
  inactive: { label: 'Archived attendee', tone: 'muted' },
  invalid: { label: 'Invalid', tone: 'neutral' },
}

type Step = 'upload' | 'map' | 'review' | 'done'
const STEPS: Array<{ key: Step; label: string }> = [
  { key: 'upload', label: 'Upload' },
  { key: 'map', label: 'Map' },
  { key: 'review', label: 'Review' },
  { key: 'done', label: 'Done' },
]

export function MajorImportPage() {
  const [step, setStep] = useState<Step>('upload')
  const [parsed, setParsed] = useState<MajorParsedFile | null>(null)
  const [mapping, setMapping] = useState<MajorMapping | null>(null)
  const [preview, setPreview] = useState<MajorPreview | null>(null)
  const [result, setResult] = useState<MajorImportResult | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | string | null>(null)
  const [filter, setFilter] = useState<MajorRowResult | 'all'>('all')
  const fileInput = useRef<HTMLInputElement>(null)

  const reset = () => {
    setStep('upload')
    setParsed(null)
    setMapping(null)
    setPreview(null)
    setResult(null)
    setError(null)
    setFilter('all')
    if (fileInput.current) fileInput.current.value = ''
  }

  const run = async (action: () => Promise<void>) => {
    setBusy(true)
    setError(null)
    try {
      await action()
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : errorMessage(caught))
    } finally {
      setBusy(false)
    }
  }

  const handleFile = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    if (!file) return
    const extension = file.name.split('.').pop()?.toLowerCase()
    if (extension !== 'csv' && extension !== 'xlsx') {
      setError('Choose a .csv or .xlsx file (in Google Sheets: File › Download › CSV or Microsoft Excel).')
      event.target.value = ''
      return
    }
    if (file.size > MAX_FILE_BYTES) {
      setError('The file must be 5 MB or smaller.')
      event.target.value = ''
      return
    }
    void run(async () => {
      const data = await majorService.parseFile(file)
      setParsed(data)
      setMapping(data.suggestedMapping)
      setStep('map')
    })
  }

  const payload = () => {
    if (!parsed || !mapping) throw new Error('Upload a file first.')
    return { filename: parsed.filename, headers: parsed.headers, rows: parsed.rows, mapping }
  }

  const apiError = error instanceof ApiError ? error : null
  const fieldErrors = apiError?.fieldErrors ?? {}
  const message = typeof error === 'string' ? error : apiError ? (apiError.fieldError('file') ?? apiError.fieldError('mapping') ?? apiError.message) : null
  const hasIdentifier =
    mapping !== null &&
    (mapping.attendee_code !== null || mapping.external_identifier !== null || mapping.email !== null || (mapping.full_name !== null && mapping.department !== null))

  return (
    <>
      <PageHeader
        title="Import Major responses"
        description="Match exported form responses to attendees. Matching attendees become Major Eligible; attendee details are never changed."
        actions={<ButtonLink to="/major-eligibility" variant="secondary" icon={<ArrowLeft className="size-4" aria-hidden />}>Back</ButtonLink>}
      />

      <ol className="mb-6 flex flex-wrap gap-2 text-sm" aria-label="Import steps">
        {STEPS.map(({ key, label }, index) => {
          const current = STEPS.findIndex((s) => s.key === step)
          return (
            <li
              key={key}
              aria-current={key === step ? 'step' : undefined}
              className={cn(
                'flex items-center gap-2 rounded-full px-3 py-1 ring-1 ring-inset',
                index === current ? 'bg-brand-700 text-white ring-brand-700'
                  : index < current ? 'bg-brand-50 text-brand-800 ring-brand-200' : 'bg-white text-slate-500 ring-slate-200',
              )}
            >
              <span className="tabular-nums">{index + 1}</span> {label}
            </li>
          )
        })}
      </ol>

      {message && <Alert tone="error" className="mb-4" onDismiss={() => setError(null)}>{message}</Alert>}

      {step === 'upload' && (
        <Card className="p-8">
          <div className="flex flex-col items-center text-center">
            <span className="flex size-12 items-center justify-center rounded-full bg-brand-50 text-brand-700">
              <FileSpreadsheet className="size-6" aria-hidden />
            </span>
            <h2 className="mt-4 text-base font-semibold text-slate-900">Choose the exported responses file</h2>
            <p className="mt-1 max-w-md text-sm text-slate-500">
              In Google Sheets: File › Download › Comma-separated values (.csv) or Microsoft Excel (.xlsx). Up to 5 MB. Any column names work.
            </p>
            <input ref={fileInput} id="major-file" type="file" accept=".csv,.xlsx" className="sr-only" onChange={handleFile} disabled={busy} />
            <label
              htmlFor="major-file"
              className={cn(
                'mt-6 inline-flex h-11 cursor-pointer items-center gap-2 rounded-lg bg-brand-700 px-5 text-sm font-medium text-white shadow-sm hover:bg-brand-800',
                busy && 'pointer-events-none opacity-70',
              )}
            >
              <Upload className="size-4" aria-hidden /> {busy ? 'Reading file…' : 'Choose CSV / XLSX'}
            </label>
          </div>
        </Card>
      )}

      {step === 'map' && parsed && mapping && (
        <div className="space-y-6">
          <Card>
            <CardHeader
              title={parsed.filename}
              description={`${parsed.fileType.toUpperCase()} · ${formatNumber(parsed.totalRows)} response rows · ${parsed.headers.length} columns`}
              actions={<Button variant="secondary" size="sm" onClick={reset}>Choose a different file</Button>}
            />
            <div className="overflow-x-auto">
              <table className="min-w-full text-sm">
                <thead className="bg-slate-50 text-left text-xs text-slate-500">
                  <tr>
                    <th scope="col" className="px-5 py-2 font-medium">Row</th>
                    {parsed.headers.map((h) => <th key={h} scope="col" className="whitespace-nowrap px-3 py-2 font-medium">{h}</th>)}
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {parsed.rows.slice(0, 5).map((row) => (
                    <tr key={row.rowNumber}>
                      <td className="px-5 py-2 tabular-nums text-slate-400">{row.rowNumber}</td>
                      {row.cells.map((cell, i) => <td key={i} className="max-w-56 truncate whitespace-nowrap px-3 py-2 text-slate-700">{cell}</td>)}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>

          <Card>
            <CardHeader
              title="Map matching columns"
              description="Map every identifier the form collected. Rows are matched exactly (spaces and capitals ignored); a row must point to exactly one attendee."
            />
            <div className="grid gap-5 p-5 sm:grid-cols-2 lg:grid-cols-3">
              {MAJOR_FIELDS.map((field) => {
                const index = mapping[field]
                const sample = index === null ? null : parsed.rows.find((r) => (r.cells[index] ?? '') !== '')?.cells[index]
                return (
                  <div key={field}>
                    <label htmlFor={`map-${field}`} className="mb-1.5 block text-sm font-medium text-slate-700">{FIELD_INFO[field].label}</label>
                    <select
                      id={`map-${field}`}
                      value={index ?? ''}
                      onChange={(e) => setMapping({ ...mapping, [field]: e.target.value === '' ? null : Number(e.target.value) })}
                      className={cn(
                        'h-10 w-full rounded-lg border-0 bg-white py-0 pl-3 pr-8 text-sm shadow-sm ring-1 ring-inset focus:ring-2 focus:ring-brand-600',
                        fieldErrors[field] ? 'ring-red-400' : 'ring-slate-300',
                      )}
                    >
                      <option value="">— Not in this file —</option>
                      {parsed.headers.map((header, i) => <option key={header} value={i}>{header}</option>)}
                    </select>
                    <p className={cn('mt-1.5 truncate text-sm', fieldErrors[field] ? 'text-red-600' : 'text-slate-500')}>
                      {fieldErrors[field]?.[0] ?? (sample ? `e.g. ${sample}` : FIELD_INFO[field].hint)}
                    </p>
                  </div>
                )
              })}
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4">
              <p className="text-sm text-slate-500">Other columns (timestamp, answers…) are ignored and not stored.</p>
              <Button
                onClick={() => run(async () => { setPreview(await majorService.preview(payload())); setStep('review') })}
                loading={busy}
                disabled={!hasIdentifier}
              >
                Check matches
              </Button>
            </div>
          </Card>
        </div>
      )}

      {step === 'review' && preview && parsed && (
        <div className="space-y-6">
          <Card>
            <CardHeader title="Import review" description={parsed.filename} />
            <div className="grid grid-cols-2 gap-px bg-slate-200 sm:grid-cols-4 lg:grid-cols-7">
              <Tile label="Total rows" value={preview.summary.total} />
              <Tile label="Valid rows" value={preview.summary.valid} />
              <Tile label="Matched (new)" value={preview.summary.matched} tone="good" />
              <Tile label="Already eligible" value={preview.summary.alreadyEligible} />
              <Tile label="Unmatched" value={preview.summary.unmatched} tone={preview.summary.unmatched ? 'warn' : undefined} />
              <Tile label="Ambiguous" value={preview.summary.ambiguous} tone={preview.summary.ambiguous ? 'warn' : undefined} />
              <Tile label="Invalid / archived" value={preview.summary.invalid + preview.summary.inactive} />
            </div>
            <div className="space-y-1 px-5 py-4 text-sm text-slate-600">
              <p><b className="text-slate-900">What will happen:</b> {formatNumber(preview.summary.matched)} attendee{preview.summary.matched === 1 ? '' : 's'} will become Major Eligible.</p>
              <p>Unmatched, ambiguous, invalid and archived rows are skipped. Nobody is guessed, and attendee details are not changed. Already-eligible attendees stay as they are.</p>
            </div>
            <div className="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
              <Button variant="ghost" onClick={() => setStep('map')} disabled={busy}>Back to mapping</Button>
              <Button variant="secondary" onClick={reset} disabled={busy}>Cancel</Button>
              <Button
                onClick={() => run(async () => { setResult(await majorService.commit(payload())); setStep('done') })}
                loading={busy}
                disabled={preview.summary.matched === 0}
              >
                Import {formatNumber(preview.summary.matched)} eligible
              </Button>
            </div>
          </Card>

          <Card className="overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-5 py-4">
              <h2 className="text-base font-semibold text-slate-900">Rows</h2>
              <select
                aria-label="Show rows"
                value={filter}
                onChange={(e) => setFilter(e.target.value as MajorRowResult | 'all')}
                className="h-9 rounded-lg border-0 py-0 pl-3 pr-8 text-sm ring-1 ring-inset ring-slate-300"
              >
                <option value="all">All rows</option>
                {(Object.keys(RESULT_STYLE) as MajorRowResult[]).map((key) => <option key={key} value={key}>{RESULT_STYLE[key].label}</option>)}
              </select>
            </div>
            <div className="max-h-[480px] overflow-auto">
              <table className="min-w-full text-sm">
                <thead className="sticky top-0 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <tr>
                    <th scope="col" className="px-5 py-2">Row</th>
                    <th scope="col" className="px-5 py-2">From file</th>
                    <th scope="col" className="px-5 py-2">Result</th>
                    <th scope="col" className="px-5 py-2">Attendee</th>
                    <th scope="col" className="px-5 py-2">Reason</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {preview.rows.filter((r) => filter === 'all' || r.result === filter).map((row) => (
                    <tr key={row.rowNumber}>
                      <td className="px-5 py-2 font-mono text-xs text-slate-500">{row.rowNumber}</td>
                      <td className="max-w-72 px-5 py-2 text-slate-700">
                        <span className="block truncate">{[row.values.full_name, row.values.department].filter(Boolean).join(' · ') || '—'}</span>
                        <span className="block truncate text-xs text-slate-500">
                          {[row.values.attendee_code, row.values.external_identifier, row.values.email].filter(Boolean).join(' · ')}
                        </span>
                      </td>
                      <td className="whitespace-nowrap px-5 py-2"><Badge tone={RESULT_STYLE[row.result].tone}>{RESULT_STYLE[row.result].label}</Badge></td>
                      <td className="px-5 py-2 text-slate-700">
                        {row.attendee ? <><span className="font-mono text-xs">{row.attendee.attendeeCode}</span> {row.attendee.fullName}</> : '—'}
                      </td>
                      <td className="px-5 py-2 text-slate-500">{row.reason}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </div>
      )}

      {step === 'done' && result && (
        <Card className="p-8">
          <div className="flex flex-col items-center text-center">
            <span className="flex size-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
              <CircleCheck className="size-6" aria-hidden />
            </span>
            <h2 className="mt-4 text-lg font-semibold text-slate-900">
              {formatNumber(result.newlyEligible)} attendee{result.newlyEligible === 1 ? ' is' : 's are'} now Major Eligible
            </h2>
            <p className="mt-1 max-w-md text-sm text-slate-500">
              {formatNumber(result.summary.total)} rows read · {formatNumber(result.summary.alreadyEligible)} already eligible ·{' '}
              {formatNumber(result.summary.unmatched)} unmatched · {formatNumber(result.summary.ambiguous)} ambiguous skipped.
            </p>
            <div className="mt-6 flex flex-wrap justify-center gap-2">
              <Button variant="secondary" onClick={reset} icon={<FileUp className="size-4" aria-hidden />}>Import another file</Button>
              <ButtonLink to="/major-eligibility">View Major Eligible</ButtonLink>
            </div>
          </div>
        </Card>
      )}
    </>
  )
}

function Tile({ label, value, tone }: { label: string; value: number; tone?: 'good' | 'warn' }) {
  return (
    <div className="bg-white px-4 py-3">
      <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p className={cn('mt-1 text-2xl font-semibold tabular-nums', tone === 'good' ? 'text-emerald-700' : tone === 'warn' ? 'text-amber-700' : 'text-slate-900')}>
        {formatNumber(value)}
      </p>
    </div>
  )
}
