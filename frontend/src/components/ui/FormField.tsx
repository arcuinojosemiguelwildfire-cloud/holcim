import { useId, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import { cn } from '../../utils/cn'

const CONTROL_CLASSES =
  'block w-full rounded-lg border-0 bg-white px-3 text-sm text-slate-900 shadow-sm ring-1 ring-inset ' +
  'placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:outline-none disabled:bg-slate-50 disabled:text-slate-500'

function controlClasses(hasError: boolean, extra?: string): string {
  return cn(
    CONTROL_CLASSES,
    hasError ? 'ring-red-400 focus:ring-red-500' : 'ring-slate-300 focus:ring-brand-600',
    extra,
  )
}

interface FieldWrapperProps {
  id: string
  label: string
  error?: string
  hint?: string
  required?: boolean
  children: ReactNode
}

function FieldWrapper({ id, label, error, hint, required, children }: FieldWrapperProps) {
  return (
    <div>
      <label htmlFor={id} className="mb-1.5 block text-sm font-medium text-slate-700">
        {label}
        {required && <span className="ml-0.5 text-red-500" aria-hidden>*</span>}
      </label>
      {children}
      {error ? (
        <p id={`${id}-error`} className="mt-1.5 text-sm text-red-600">{error}</p>
      ) : hint ? (
        <p id={`${id}-hint`} className="mt-1.5 text-sm text-slate-500">{hint}</p>
      ) : null}
    </div>
  )
}

interface CommonProps {
  label: string
  error?: string
  hint?: string
}

function describedBy(id: string, error?: string, hint?: string): string | undefined {
  if (error) return `${id}-error`
  if (hint) return `${id}-hint`
  return undefined
}

export function TextField({ label, error, hint, className, ...props }: CommonProps & InputHTMLAttributes<HTMLInputElement>) {
  const id = useId()
  return (
    <FieldWrapper id={id} label={label} error={error} hint={hint} required={props.required}>
      <input
        id={id}
        aria-invalid={Boolean(error)}
        aria-describedby={describedBy(id, error, hint)}
        className={controlClasses(Boolean(error), cn('h-10', className))}
        {...props}
      />
    </FieldWrapper>
  )
}

export function TextAreaField({ label, error, hint, className, ...props }: CommonProps & TextareaHTMLAttributes<HTMLTextAreaElement>) {
  const id = useId()
  return (
    <FieldWrapper id={id} label={label} error={error} hint={hint} required={props.required}>
      <textarea
        id={id}
        aria-invalid={Boolean(error)}
        aria-describedby={describedBy(id, error, hint)}
        className={controlClasses(Boolean(error), cn('py-2', className))}
        {...props}
      />
    </FieldWrapper>
  )
}

interface SelectFieldProps extends CommonProps, SelectHTMLAttributes<HTMLSelectElement> {
  options: ReadonlyArray<{ value: string; label: string }>
}

export function SelectField({ label, error, hint, options, className, ...props }: SelectFieldProps) {
  const id = useId()
  return (
    <FieldWrapper id={id} label={label} error={error} hint={hint} required={props.required}>
      <select
        id={id}
        aria-invalid={Boolean(error)}
        aria-describedby={describedBy(id, error, hint)}
        className={controlClasses(Boolean(error), cn('h-10 pr-8', className))}
        {...props}
      >
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
    </FieldWrapper>
  )
}
