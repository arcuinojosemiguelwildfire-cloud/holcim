import { ChevronLeft, ChevronRight } from 'lucide-react'
import { formatNumber } from '../../utils/format'
import type { Pagination } from '../../types/attendee'

interface PaginationBarProps {
  pagination: Pagination
  /** Omit (with onPageSizeChange) for a fixed page size. */
  pageSizes?: number[]
  onPageChange: (page: number) => void
  onPageSizeChange?: (size: number) => void
}

export function PaginationBar({ pagination, pageSizes, onPageChange, onPageSizeChange }: PaginationBarProps) {
  const { page, perPage, total, totalPages } = pagination
  const from = total === 0 ? 0 : (page - 1) * perPage + 1
  const to = Math.min(page * perPage, total)

  const buttonClass =
    'inline-flex h-8 items-center gap-1 rounded-md px-2.5 text-sm text-slate-700 ring-1 ring-inset ring-slate-300 bg-white hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50'

  return (
    <div className="flex flex-wrap items-center justify-between gap-3 border-t border-slate-200 px-5 py-3 text-sm text-slate-600">
      <div className="flex items-center gap-3">
        <span className="tabular-nums">
          {formatNumber(from)}–{formatNumber(to)} of {formatNumber(total)}
        </span>
        {pageSizes && onPageSizeChange && (
        <label className="flex items-center gap-1.5">
          <span className="text-slate-500">Rows</span>
          <select
            value={perPage}
            onChange={(e) => onPageSizeChange(Number(e.target.value))}
            className="h-8 rounded-md border-0 bg-white py-0 pl-2 pr-7 text-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600"
          >
            {pageSizes.map((size) => (
              <option key={size} value={size}>
                {size}
              </option>
            ))}
          </select>
        </label>
        )}
      </div>
      <div className="flex items-center gap-2">
        <button type="button" className={buttonClass} disabled={page <= 1} onClick={() => onPageChange(page - 1)}>
          <ChevronLeft className="size-4" aria-hidden /> Previous
        </button>
        <label className="flex items-center gap-1.5">
          <span className="sr-only">Page</span>
          <select
            value={page}
            onChange={(e) => onPageChange(Number(e.target.value))}
            className="h-8 rounded-md border-0 bg-white py-0 pl-2 pr-7 text-sm tabular-nums ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-brand-600"
            aria-label="Page number"
          >
            {Array.from({ length: totalPages }, (_, i) => i + 1).map((n) => (
              <option key={n} value={n}>
                Page {n} of {totalPages}
              </option>
            ))}
          </select>
        </label>
        <button type="button" className={buttonClass} disabled={page >= totalPages} onClick={() => onPageChange(page + 1)}>
          Next <ChevronRight className="size-4" aria-hidden />
        </button>
      </div>
    </div>
  )
}
