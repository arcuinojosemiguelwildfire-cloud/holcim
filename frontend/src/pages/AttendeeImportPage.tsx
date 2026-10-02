import { useRef, useState, type ChangeEvent, type ReactNode } from 'react'
import { ArrowLeft, CircleCheck, FileSpreadsheet, FileUp, Upload } from 'lucide-react'
import { Alert } from '../components/ui/Alert'
import { Badge } from '../components/ui/Badge'
import { Button, ButtonLink } from '../components/ui/Button'
import { Card, CardHeader } from '../components/ui/Card'
import { PageHeader } from '../components/ui/PageHeader'
import { ApiError, errorMessage } from '../services/apiClient'
import { attendeeService } from '../services/attendeeService'
import {
  IMPORT_FIELDS,
  type ColumnMapping,
  type DuplicateRow,
  type ImportField,
  type ImportPreview,
  type ImportResult,
  type ParsedFile,
} from '../types/attendee'
import { cn } from '../utils/cn'
import { formatNumber } from '../utils/format'

const MAX_FILE_BYTES = 5 * 1024 * 1024
const PREVIEW_ROWS = 8

const FIELD_INFO: Record<ImportField, { label: string; required: boolean; hint: string }> = {
  full_name: { label: 'Full Name', required: true, hint: 'Required' },
  company: { label: 'Company', required: false, hint: 'Optional ("Name 1" in External Attendees)' },
  department: { label: 'Cluster', required: false, hint: 'Optional (location / region)' },
  email: { label: 'Email', required: false, hint: 'Optional' },
  external_identifier: { label: 'Employee ID / External ID', required: false, hint: 'Optional' },
}

const MATCH_LABELS: Record<DuplicateRow['matchedBy'], string> = {
  external_identifier: 'same Employee ID / External ID',
  email: 'same email',
  name_department: 'same name, company and cluster',
}

type Step = 'upload' | 'map' | 'review' | 'done'
const STEPS: Array<{ key: Step; label: string }> = [
  { key: 'upload', label: 'Upload' },
  { key: 'map', label: 'Map columns' },
  { key: 'review', label: 'Review' },
  { key: 'done', label: 'Done' },
]

export function AttendeeImportPage() {
  const [step, setStep] = useState<Step>('upload')
  const [parsed, setParsed] = useState<ParsedFile | null>(null)
  const [mapping, setMapping] = useState<ColumnMapping | null>(null)
  const [preview, setPreview] = useState<ImportPreview | null>(null)
  const [result, setResult] = useState<ImportResult | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | string | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  const reset = () => {
    setStep('upload')
    setParsed(null)
    setMapping(null)
    setPreview(null)
    setResult(null)
    setError(null)
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
      setError(extension === 'xls'
        ? 'Old Excel (.xls) files are not supported. In Excel choose File > Save As > Excel Workbook (.xlsx) or CSV.'
        : 'Choose a .csv or .xlsx file.')
      event.target.value = ''
      return
    }
    if (file.size > MAX_FILE_BYTES) {
      setError('The file must be 5 MB or smaller.')
      event.target.value = ''
      return
    }
    void run(async () => {
      const data = await attendeeService.parseFile(file)
      setParsed(data)
      setMapping(data.suggestedMapping)
      setStep('map')
    })
  }

  const payload = () => {
    if (!parsed || !mapping) throw new Error('Upload a file first.')
    return { filename: parsed.filename, headers: parsed.headers, rows: parsed.rows, mapping }
  }

  const validate = () =>
    run(async () => {
      setPreview(await attendeeService.preview(payload()))
      setStep('review')
    })

  const confirmImport = () =>
    run(async () => {
      setResult(await attendeeService.commit(payload()))
      setStep('done')
    })

  const apiError = error instanceof ApiError ? error : null
  const fieldErrors = apiError?.fieldErrors ?? {}
  const message = typeof error === 'string' ? error : apiError ? (apiError.fieldError('file') ?? apiError.message) : null

  return (
    <>
      <PageHeader
        title="Import attendees"
        description="Upload the client's attendee list, match its columns, review problems, then import."
        actions={
          <ButtonLink to="/attendees" variant="secondary" icon={<ArrowLeft className="size-4" aria-hidden />}>
            Back to attendees
          </ButtonLink>
        }
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

      {message && (
        <Alert tone="error" className="mb-4" onDismiss={() => setError(null)}>
          {message}
        </Alert>
      )}

      {step === 'upload' && (
        <Card className="p-8">
          <div className="flex flex-col items-center text-center">
            <span className="flex size-12 items-center justify-center rounded-full bg-brand-50 text-brand-700">
              <FileSpreadsheet className="size-6" aria-hidden />
            </span>
            <h2 className="mt-4 text-base font-semibold text-slate-900">Choose an Excel or CSV file</h2>
            <p className="mt-1 max-w-md text-sm text-slate-500">
              .xlsx or .csv, up to 5 MB. The first row must contain column names. Any column names work: you will match
              them to attendee fields in the next step.
            </p>
            <input ref={fileInput} id="attendee-file" type="file" accept=".csv,.xlsx" className="sr-only" onChange={handleFile} disabled={busy} />
            <label
              htmlFor="attendee-file"
              className={cn(
                'mt-6 inline-flex h-11 cursor-pointer items-center gap-2 rounded-lg bg-brand-700 px-5 text-sm font-medium text-white shadow-sm hover:bg-brand-800',
                busy && 'pointer-events-none opacity-70',
              )}
            >
              <Upload className="size-4" aria-hidden /> {busy ? 'Reading file…' : 'Choose Excel / CSV'}
            </label>
          </div>
        </Card>
      )}

      {step === 'map' && parsed && mapping && (
        <div className="space-y-6">
          <Card>
            <CardHeader
              title={parsed.filename}
              description={
                parsed.layout === 'external_attendees'
                  ? `Sheet "${parsed.sheet ?? 'External Attendees'}" · ${formatNumber(parsed.sourceRows ?? 0)} company rows · ${parsed.attendeeColumns ?? 0} Attendee columns → ${formatNumber(parsed.totalRows)} attendees (Name 1 = Company, Cluster = location; Name 1 is not an attendee)`
                  : `${parsed.fileType.toUpperCase()}${parsed.sheet ? ` · sheet "${parsed.sheet}"` : ''} · ${formatNumber(parsed.totalRows)} data rows · ${parsed.headers.length} columns`
              }
              actions={<Button variant="secondary" size="sm" onClick={reset}>Choose a different file</Button>}
            />
            <div className="px-5 py-4">
              <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Detected columns</p>
              <div className="mt-2 flex flex-wrap gap-1.5">
                {parsed.headers.map((header) => (
                  <Badge key={header}>{header}</Badge>
                ))}
              </div>
            </div>
            <PreviewTable parsed={parsed} />
          </Card>

          <Card>
            <CardHeader title="Map columns" description="Choose which column in the file holds each attendee field. Suggestions were made from the column names. Change any that are wrong." />
            <div className="grid gap-5 p-5 sm:grid-cols-2">
              {IMPORT_FIELDS.map((field) => {
                const info = FIELD_INFO[field]
                const index = mapping[field]
                const sample = index === null ? null : parsed.rows.find((row) => (row.cells[index] ?? '') !== '')?.cells[index]
                return (
                  <div key={field}>
                    <label htmlFor={`map-${field}`} className="mb-1.5 flex items-baseline justify-between text-sm font-medium text-slate-700">
                      <span>
                        {info.label}
                        {info.required && <span className="ml-0.5 text-red-500" aria-hidden>*</span>}
                      </span>
                      <span className="text-xs font-normal text-slate-400">{info.hint}</span>
                    </label>
                    <select
                      id={`map-${field}`}
                      value={index ?? ''}
                      onChange={(e) => setMapping({ ...mapping, [field]: e.target.value === '' ? null : Number(e.target.value) })}
                      className={cn(
                        'h-10 w-full rounded-lg border-0 bg-white py-0 pl-3 pr-8 text-sm shadow-sm ring-1 ring-inset focus:ring-2 focus:ring-brand-600',
                        fieldErrors[field] ? 'ring-red-400' : 'ring-slate-300',
                      )}
                    >
                      <option value="">{info.required ? 'Select a column…' : '— Not in this file —'}</option>
                      {parsed.headers.map((header, i) => (
                        <option key={header} value={i}>
                          {header}
                        </option>
                      ))}
                    </select>
                    {fieldErrors[field] ? (
                      <p className="mt-1.5 text-sm text-red-600">{fieldErrors[field][0]}</p>
                    ) : sample ? (
                      <p className="mt-1.5 truncate text-sm text-slate-500">e.g. {sample}</p>
                    ) : null}
                  </div>
                )
              })}
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4">
              <p className="text-sm text-slate-500">Columns that are not mapped are kept with each attendee as extra information.</p>
              <Button onClick={validate} loading={busy} disabled={mapping.full_name === null}>
                Validate and check duplicates
              </Button>
            </div>
          </Card>
        </div>
      )}

      {step === 'review' && preview && parsed && (
        <ReviewStep
          preview={preview}
          filename={parsed.filename}
          busy={busy}
          onBack={() => setStep('map')}
          onCancel={reset}
          onImport={confirmImport}
        />
      )}

      {step === 'done' && result && (
        <Card className="p-8">
          <div className="flex flex-col items-center text-center">
            <span className="flex size-12 items-center justify-center rounded-full bg-emerald-50 text-emerald-600">
              <CircleCheck className="size-6" aria-hidden />
            </span>
            <h2 className="mt-4 text-lg font-semibold text-slate-900">
              Imported {formatNumber(result.imported)} new attendee{result.imported === 1 ? '' : 's'}
            </h2>
            <p className="mt-1 max-w-md text-sm text-slate-500">
              {formatNumber(result.summary.total)} rows read. {formatNumber(result.summary.duplicates)} duplicate
              {result.summary.duplicates === 1 ? ' was' : 's were'} skipped and {formatNumber(result.summary.invalid)} invalid
              row{result.summary.invalid === 1 ? ' was' : 's were'} not imported. Existing attendees were not changed.
            </p>
            {result.created.length > 0 && (
              <p className="mt-3 text-sm text-slate-600">
                Codes assigned: <span className="font-mono">{result.created[0]?.attendeeCode}</span>
                {result.created.length > 1 && <> to <span className="font-mono">{result.created[result.created.length - 1]?.attendeeCode}</span></>}
                {result.imported > result.created.length && ' …'}
              </p>
            )}
            <div className="mt-6 flex flex-wrap justify-center gap-2">
              <Button variant="secondary" onClick={reset} icon={<FileUp className="size-4" aria-hidden />}>
                Import another file
              </Button>
              <ButtonLink to="/attendees">View attendees</ButtonLink>
            </div>
          </div>
        </Card>
      )}
    </>
  )
}

function PreviewTable({ parsed }: { parsed: ParsedFile }) {
  return (
    <div className="border-t border-slate-200">
      <p className="px-5 pt-4 text-xs font-semibold uppercase tracking-wide text-slate-500">
        Preview (first {Math.min(PREVIEW_ROWS, parsed.rows.length)} rows)
      </p>
      <div className="mt-2 overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50 text-left text-xs text-slate-500">
            <tr>
              <th scope="col" className="px-5 py-2 font-medium">Row</th>
              {parsed.headers.map((header) => (
                <th key={header} scope="col" className="whitespace-nowrap px-3 py-2 font-medium">{header}</th>
              ))}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {parsed.rows.slice(0, PREVIEW_ROWS).map((row, index) => (
              <tr key={`${row.rowNumber}-${index}`}>
                <td className="px-5 py-2 tabular-nums text-slate-400">{row.rowNumber}</td>
                {row.cells.map((cell, i) => (
                  <td key={i} className="max-w-56 truncate whitespace-nowrap px-3 py-2 text-slate-700">{cell}</td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

interface ReviewStepProps {
  preview: ImportPreview
  filename: string
  busy: boolean
  onBack: () => void
  onCancel: () => void
  onImport: () => void
}

function ReviewStep({ preview, filename, busy, onBack, onCancel, onImport }: ReviewStepProps) {
  const { summary, invalid, duplicates, newPreview } = preview

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader title="Import preview" description={filename} />
        <div className="grid grid-cols-2 gap-px bg-slate-200 sm:grid-cols-4">
          <SummaryTile label="Total rows" value={summary.total} />
          <SummaryTile label="Valid (new)" value={summary.valid} tone="good" />
          <SummaryTile label="Invalid" value={summary.invalid} tone={summary.invalid > 0 ? 'bad' : undefined} />
          <SummaryTile label="Duplicates" value={summary.duplicates} tone={summary.duplicates > 0 ? 'warn' : undefined} />
        </div>
        <div className="space-y-2 px-5 py-4 text-sm text-slate-600">
          <p><b className="text-slate-900">What will happen:</b> {formatNumber(summary.valid)} new attendee{summary.valid === 1 ? '' : 's'} will be created, each with a new attendee code.</p>
          {summary.duplicates > 0 && (
            <p>
              {formatNumber(summary.duplicates)} duplicate row{summary.duplicates === 1 ? '' : 's'} will be skipped
              {summary.duplicatesExisting > 0 && ` (${formatNumber(summary.duplicatesExisting)} already in this event`}
              {summary.duplicatesExisting > 0 && summary.duplicatesInFile > 0 && ', '}
              {summary.duplicatesInFile > 0 && `${summary.duplicatesExisting > 0 ? '' : ' ('}${formatNumber(summary.duplicatesInFile)} repeated within the file`}
              ). Existing attendees and their codes are not changed.
            </p>
          )}
          {summary.invalid > 0 && <p>{formatNumber(summary.invalid)} invalid row{summary.invalid === 1 ? '' : 's'} will not be imported. Fix them in the file and import it again later; already-imported people will be skipped as duplicates.</p>}
        </div>
        <div className="flex flex-wrap justify-end gap-2 border-t border-slate-200 bg-slate-50 px-5 py-4">
          <Button variant="ghost" onClick={onBack} disabled={busy}>Back to mapping</Button>
          <Button variant="secondary" onClick={onCancel} disabled={busy}>Cancel</Button>
          <Button onClick={onImport} loading={busy} disabled={summary.valid === 0}>
            Import {formatNumber(summary.valid)} valid record{summary.valid === 1 ? '' : 's'}
          </Button>
        </div>
      </Card>

      {invalid.length > 0 && (
        <RowSection title={`Invalid rows (${formatNumber(invalid.length)})`} description="These rows will not be imported.">
          <thead><tr><Th>Row</Th><Th>Full name</Th><Th>Company</Th><Th>Cluster</Th><Th>Email</Th><Th>Reason</Th></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {invalid.map((row, index) => (
              <tr key={`${row.rowNumber}-${index}`}>
                <Td mono>{row.rowNumber}</Td>
                <Td>{row.fullName || <Empty />}</Td>
                <Td>{row.company || <Empty />}</Td>
                <Td>{row.department || <Empty />}</Td>
                <Td>{row.email || '—'}</Td>
                <td className="px-5 py-2 text-red-700">{row.reasons.join(' ')}</td>
              </tr>
            ))}
          </tbody>
        </RowSection>
      )}

      {duplicates.length > 0 && (
        <RowSection title={`Duplicate candidates (${formatNumber(duplicates.length)})`} description="These rows will be skipped.">
          <thead><tr><Th>Row</Th><Th>Full name</Th><Th>Company</Th><Th>Cluster</Th><Th>Matches</Th><Th>Because of</Th></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {duplicates.map((row, index) => (
              <tr key={`${row.rowNumber}-${index}`}>
                <Td mono>{row.rowNumber}</Td>
                <Td>{row.fullName}</Td>
                <Td>{row.company || <Empty />}</Td>
                <Td>{row.department || <Empty />}</Td>
                <Td>
                  {row.matches.type === 'existing' ? (
                    <>
                      Existing <span className="font-mono">{row.matches.attendeeCode}</span> {row.matches.fullName}
                      {row.matches.status === 'archived' && ' (archived)'}
                    </>
                  ) : (
                    <>Row {row.matches.rowNumber} in this file ({row.matches.fullName})</>
                  )}
                </Td>
                <Td>{MATCH_LABELS[row.matchedBy]}</Td>
              </tr>
            ))}
          </tbody>
        </RowSection>
      )}

      {newPreview.length > 0 && (
        <RowSection
          title={`New attendees (${formatNumber(summary.valid)})`}
          description={summary.valid > newPreview.length ? `Showing the first ${newPreview.length}.` : undefined}
        >
          <thead><tr><Th>Row</Th><Th>Full name</Th><Th>Company</Th><Th>Cluster</Th><Th>Email</Th><Th>External ID</Th></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {newPreview.map((row, index) => (
              <tr key={`${row.rowNumber}-${index}`}>
                <Td mono>{row.rowNumber}</Td>
                <Td>{row.fullName}</Td>
                <Td>{row.company || <Empty />}</Td>
                <Td>{row.department || <Empty />}</Td>
                <Td>{row.email ?? '—'}</Td>
                <Td>{row.externalIdentifier ?? '—'}</Td>
              </tr>
            ))}
          </tbody>
        </RowSection>
      )}
    </div>
  )
}

function SummaryTile({ label, value, tone }: { label: string; value: number; tone?: 'good' | 'bad' | 'warn' }) {
  return (
    <div className="bg-white px-5 py-4">
      <p className="text-xs font-medium uppercase tracking-wide text-slate-500">{label}</p>
      <p
        className={cn(
          'mt-1 text-2xl font-semibold tabular-nums',
          tone === 'good' ? 'text-emerald-700' : tone === 'bad' ? 'text-red-600' : tone === 'warn' ? 'text-amber-700' : 'text-slate-900',
        )}
      >
        {formatNumber(value)}
      </p>
    </div>
  )
}

function RowSection({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
  return (
    <Card className="overflow-hidden">
      <CardHeader title={title} description={description} />
      <div className="max-h-96 overflow-auto">
        <table className="min-w-full text-sm">{children}</table>
      </div>
    </Card>
  )
}

function Th({ children }: { children: ReactNode }) {
  return <th scope="col" className="sticky top-0 bg-slate-50 px-5 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">{children}</th>
}

function Td({ children, mono }: { children: ReactNode; mono?: boolean }) {
  return <td className={cn('px-5 py-2 text-slate-700', mono && 'font-mono text-xs tabular-nums text-slate-500')}>{children}</td>
}

function Empty() {
  return <span className="italic text-red-600">empty</span>
}
