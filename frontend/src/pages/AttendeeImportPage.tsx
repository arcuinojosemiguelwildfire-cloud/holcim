import { useRef, useState, type ChangeEvent, type ReactNode } from 'react'
import { ArrowLeft, CircleCheck, Download, FileSpreadsheet, FileUp, Upload } from 'lucide-react'
import { Alert } from '../components/ui/Alert'
import { Badge } from '../components/ui/Badge'
import { Button, ButtonLink } from '../components/ui/Button'
import { Card, CardHeader } from '../components/ui/Card'
import { PageHeader } from '../components/ui/PageHeader'
import { API_BASE_URL, ApiError, errorMessage } from '../services/apiClient'
import { attendeeService } from '../services/attendeeService'
import {
  GUEST_LIST_FIELDS,
  IMPORT_FIELDS,
  NAME_PART_FIELDS,
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
  full_name: { label: 'Full Name', required: false, hint: 'Required, or First Name + Surname' },
  first_name: { label: 'First Name', required: false, hint: 'Used when there is no Full Name column' },
  middle_name: { label: 'Middle Name', required: false, hint: 'Optional name part' },
  surname: { label: 'Surname', required: false, hint: 'Used when there is no Full Name column' },
  company: { label: 'Company', required: false, hint: 'Optional ("Name 1" / "Designation / Company")' },
  attendee_type: { label: 'Internal/External', required: false, hint: 'Optional: INTERNAL or EXTERNAL' },
  department: { label: 'Cluster', required: false, hint: 'Optional (location / region)' },
  email: { label: 'Email', required: false, hint: 'Optional' },
  external_identifier: { label: 'Guest No. / Employee ID', required: false, hint: 'Optional, unique in the event' },
}

/** Hints on the six-column guest sheet, where the name parts are the name. */
const GUEST_HINTS: Partial<Record<ImportField, string>> = {
  first_name: 'Required',
  surname: 'Required',
  middle_name: 'Optional (may be blank)',
}

const EMPTY_MAPPING = Object.fromEntries(IMPORT_FIELDS.map((field) => [field, null])) as ColumnMapping

const isNamePart = (field: ImportField): boolean => (NAME_PART_FIELDS as readonly string[]).includes(field)

/** Same rule as the server: FIRST + MIDDLE + SURNAME, trimmed, empty parts skipped. */
function combineName(cells: string[], mapping: ColumnMapping): string {
  return NAME_PART_FIELDS.map((field) => {
    const index = mapping[field]
    return index === null ? '' : (cells[index] ?? '').replace(/\s+/g, ' ').trim()
  }).filter((part) => part !== '').join(' ')
}

const MATCH_LABELS: Record<DuplicateRow['matchedBy'], string> = {
  external_identifier: 'same Guest No. / Employee ID',
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
  const [showAllFields, setShowAllFields] = useState(false)
  const [error, setError] = useState<ApiError | string | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  const reset = () => {
    setStep('upload')
    setParsed(null)
    setMapping(null)
    setPreview(null)
    setResult(null)
    setError(null)
    setShowAllFields(false)
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
      setMapping({ ...EMPTY_MAPPING, ...data.suggestedMapping })
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

  const guestList = parsed?.layout === 'guest_list'
  // The six-column guest sheet shows only its own six fields (no Email / Cluster / Full Name).
  const visibleFields: readonly ImportField[] = guestList && !showAllFields ? GUEST_LIST_FIELDS : IMPORT_FIELDS
  const fullNameMapped = mapping?.full_name != null
  const nameFromParts = !!mapping && !fullNameMapped && mapping.first_name !== null && mapping.surname !== null
  const nameSample = nameFromParts && parsed && mapping
    ? parsed.rows.map((row) => combineName(row.cells, mapping)).find((name) => name !== '')
    : undefined

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
            <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
              <label
                htmlFor="attendee-file"
                className={cn(
                  'inline-flex h-11 cursor-pointer items-center gap-2 rounded-lg bg-brand-700 px-5 text-sm font-medium text-white shadow-sm hover:bg-brand-800',
                  busy && 'pointer-events-none opacity-70',
                )}
              >
                <Upload className="size-4" aria-hidden /> {busy ? 'Reading file…' : 'Choose Excel / CSV'}
              </label>
              <a
                href={`${API_BASE_URL}/attendees/import/template.xlsx`}
                className="inline-flex h-11 items-center gap-2 rounded-lg bg-white px-5 text-sm font-medium text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50"
              >
                <Download className="size-4" aria-hidden /> Download guest template
              </a>
            </div>
            <p className="mt-3 max-w-lg text-xs text-slate-500">
              Template columns: GUEST NO. · SURNAME · FIRST NAME · MIDDLE NAME · INTERNAL/EXTERNAL · DESIGNATION / COMPANY (in red).
              Hidden sheets are never read.
            </p>
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
                  : `${parsed.fileType.toUpperCase()}${parsed.sheet ? ` · sheet "${parsed.sheet}"` : ''} · ${formatNumber(parsed.totalRows)} data rows${rowRange(parsed)} · ${parsed.headers.length} columns${guestList ? ' · standard guest sheet' : ''}`
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
            {(parsed.otherSheets?.length ?? 0) > 0 && (
              <div className="px-5 pb-4">
                <Alert tone="info" title={`Only sheet "${parsed.sheet ?? ''}" was read`}>
                  {formatNumber(parsed.totalRows)} non-empty rows were found below its header row. This workbook also has{' '}
                  {sheetList(parsed.otherSheets ?? [])}, which {parsed.otherSheets?.length === 1 ? 'was' : 'were'} not read. If you expected
                  more rows, move them to the first visible sheet (or make that sheet the only visible one) and upload again.
                </Alert>
              </div>
            )}
            <PreviewTable parsed={parsed} />
          </Card>

          <Card>
            <CardHeader title="Map columns" description="Choose which column in the file holds each attendee field. Suggestions were made from the column names. Change any that are wrong." />
            <div className="grid gap-5 p-5 sm:grid-cols-2">
              {visibleFields.map((field) => {
                const info = FIELD_INFO[field]
                const ignored = fullNameMapped && isNamePart(field)
                const index = ignored ? null : mapping[field]
                const sample = index === null ? null : parsed.rows.find((row) => (row.cells[index] ?? '') !== '')?.cells[index]
                return (
                  <div key={field}>
                    <label htmlFor={`map-${field}`} className="mb-1.5 flex items-baseline justify-between text-sm font-medium text-slate-700">
                      <span>
                        {info.label}
                        {info.required && <span className="ml-0.5 text-red-500" aria-hidden>*</span>}
                      </span>
                      <span className="text-xs font-normal text-slate-400">{guestList && GUEST_HINTS[field] ? GUEST_HINTS[field] : info.hint}</span>
                    </label>
                    <select
                      id={`map-${field}`}
                      value={index ?? ''}
                      disabled={ignored}
                      onChange={(e) => setMapping({ ...mapping, [field]: e.target.value === '' ? null : Number(e.target.value) })}
                      className={cn(
                        'h-10 w-full rounded-lg border-0 bg-white py-0 pl-3 pr-8 text-sm shadow-sm ring-1 ring-inset focus:ring-2 focus:ring-brand-600 disabled:bg-slate-50 disabled:text-slate-400',
                        fieldErrors[field] ? 'ring-red-400' : 'ring-slate-300',
                      )}
                    >
                      <option value="">{ignored ? '— Not used (Full Name is mapped) —' : field === 'full_name' ? '— None: build from name parts —' : '— Not in this file —'}</option>
                      {parsed.headers.map((header, i) => (
                        <option key={header} value={i}>
                          {header}
                        </option>
                      ))}
                    </select>
                    {fieldErrors[field] ? (
                      <p className="mt-1.5 text-sm text-red-600">{fieldErrors[field][0]}</p>
                    ) : field === 'full_name' && nameFromParts ? (
                      <p className="mt-1.5 truncate text-sm text-slate-500">Built from First + Middle + Surname{nameSample ? `, e.g. ${nameSample}` : ''}</p>
                    ) : sample ? (
                      <p className="mt-1.5 truncate text-sm text-slate-500">e.g. {sample}</p>
                    ) : null}
                  </div>
                )
              })}
            </div>
            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4">
              <p className="text-sm text-slate-500">
                {guestList
                  ? 'Standard guest sheet: Full Name = First + Middle + Surname (Middle Name may be blank). No Email column is needed.'
                  : 'A mapped Full Name column is used as is; otherwise Full Name is built from First + Middle + Surname.'}{' '}
                Columns that are not mapped are kept with each attendee as extra information.
                {guestList && (
                  <button type="button" className="ml-1 font-medium text-brand-700 hover:underline" onClick={() => setShowAllFields(!showAllFields)}>
                    {showAllFields ? 'Show only the guest-sheet fields' : 'Show all fields'}
                  </button>
                )}
              </p>
              <Button onClick={validate} loading={busy} disabled={!fullNameMapped && !nameFromParts}>
                Validate and check duplicates
              </Button>
            </div>
          </Card>
        </div>
      )}

      {step === 'review' && preview && parsed && (
        <ReviewStep
          preview={preview}
          guestList={guestList}
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

function rowRange(parsed: ParsedFile): string {
  return parsed.firstRowNumber != null && parsed.lastRowNumber != null
    ? ` (spreadsheet rows ${parsed.firstRowNumber}–${parsed.lastRowNumber})`
    : ''
}

function sheetList(sheets: Array<{ name: string; hidden: boolean }>): string {
  const names = sheets.slice(0, 6).map((s) => `"${s.name}"${s.hidden ? ' (hidden)' : ''}`)
  return `${sheets.length} other sheet${sheets.length === 1 ? '' : 's'}: ${names.join(', ')}${sheets.length > 6 ? ', …' : ''}`
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
  /** Six-column guest sheet: no Cluster / Email columns. */
  guestList: boolean
  filename: string
  busy: boolean
  onBack: () => void
  onCancel: () => void
  onImport: () => void
}

function ReviewStep({ preview, guestList, filename, busy, onBack, onCancel, onImport }: ReviewStepProps) {
  const { summary, invalid, duplicates, newPreview } = preview
  const reconciles = summary.valid + summary.invalid + summary.duplicates === summary.total

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader title="Import preview" description={filename} />
        <div className="grid grid-cols-2 gap-px bg-slate-200 sm:grid-cols-4">
          <SummaryTile label="Total rows read" value={summary.total} />
          <SummaryTile label="Valid (new)" value={summary.valid} tone="good" />
          <SummaryTile label="Invalid" value={summary.invalid} tone={summary.invalid > 0 ? 'bad' : undefined} />
          <SummaryTile label="Duplicates" value={summary.duplicates} tone={summary.duplicates > 0 ? 'warn' : undefined} />
        </div>
        <div className="space-y-2 px-5 py-4 text-sm text-slate-600">
          <p className={cn(!reconciles && 'font-medium text-red-700')}>
            {formatNumber(summary.total)} rows read = {formatNumber(summary.valid)} valid new + {formatNumber(summary.invalid)} invalid + {formatNumber(summary.duplicates)} duplicate
            {!reconciles && ' — these do not add up; do not import, and report this file.'}
          </p>
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
          <thead><tr><Th>Row</Th><Th>Guest No.</Th><Th>Full name</Th><Th>Internal/External</Th><Th>Company</Th>{!guestList && <><Th>Cluster</Th><Th>Email</Th></>}<Th>Reason</Th></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {invalid.map((row, index) => (
              <tr key={`${row.rowNumber}-${index}`}>
                <Td mono>{row.rowNumber}</Td>
                <Td>{row.externalIdentifier || '—'}</Td>
                <Td>{row.fullName || <Empty />}</Td>
                <Td>{row.attendeeType || '—'}</Td>
                <Td>{row.company || (guestList ? '—' : <Empty />)}</Td>
                {!guestList && <><Td>{row.department || <Empty />}</Td><Td>{row.email || '—'}</Td></>}
                <td className="px-5 py-2 text-red-700">{row.reasons.join(' ')}</td>
              </tr>
            ))}
          </tbody>
        </RowSection>
      )}

      {duplicates.length > 0 && (
        <RowSection title={`Duplicate candidates (${formatNumber(duplicates.length)})`} description="These rows will be skipped.">
          <thead><tr><Th>Row</Th><Th>Guest No.</Th><Th>Full name</Th><Th>Company</Th>{!guestList && <Th>Cluster</Th>}<Th>Matches</Th><Th>Because of</Th></tr></thead>
          <tbody className="divide-y divide-slate-100">
            {duplicates.map((row, index) => (
              <tr key={`${row.rowNumber}-${index}`}>
                <Td mono>{row.rowNumber}</Td>
                <Td>
                  {row.externalIdentifier
                    ? <span className={cn(row.matchedBy === 'external_identifier' && 'font-semibold text-amber-800')}>{row.externalIdentifier}</span>
                    : '—'}
                </Td>
                <Td>{row.fullName}</Td>
                <Td>{row.company || (guestList ? '—' : <Empty />)}</Td>
                {!guestList && <Td>{row.department || <Empty />}</Td>}
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
          <thead><tr><Th>Row</Th><Th>Guest No.</Th><Th>Full name</Th><Th>Internal/External</Th><Th>Company</Th>{!guestList && <><Th>Cluster</Th><Th>Email</Th></>}</tr></thead>
          <tbody className="divide-y divide-slate-100">
            {newPreview.map((row, index) => (
              <tr key={`${row.rowNumber}-${index}`}>
                <Td mono>{row.rowNumber}</Td>
                <Td>{row.externalIdentifier ?? '—'}</Td>
                <Td>{row.fullName}</Td>
                <Td>{row.attendeeType ?? '—'}</Td>
                <Td>{row.company || (guestList ? '—' : <Empty />)}</Td>
                {!guestList && <><Td>{row.department || <Empty />}</Td><Td>{row.email ?? '—'}</Td></>}
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
