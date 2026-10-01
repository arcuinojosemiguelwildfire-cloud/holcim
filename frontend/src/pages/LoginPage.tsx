import { useState, type FormEvent } from 'react'
import { LogIn, ScanLine, ShieldCheck, Trophy } from 'lucide-react'
import { BrandMark } from '../components/layout/BrandMark'
import { Alert } from '../components/ui/Alert'
import { Button } from '../components/ui/Button'
import { TextField } from '../components/ui/FormField'
import { useAuth } from '../hooks/useAuth'
import { ApiError, errorMessage } from '../services/apiClient'

const HIGHLIGHTS = [
  { icon: ScanLine, text: 'Fast QR registration at the venue entrance' },
  { icon: Trophy, text: 'Fair, auditable minor and major prize draws' },
  { icon: ShieldCheck, text: 'Role-based access for every staff member' },
]

export function LoginPage() {
  const { login, bootError, retry } = useAuth()

  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<ApiError | string | null>(null)

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault()
    setSubmitting(true)
    setError(null)
    try {
      // On success GuestOnly redirects to the originally requested page.
      await login({ login: email.trim(), password })
    } catch (caught) {
      setError(caught instanceof ApiError ? caught : errorMessage(caught))
      setPassword('')
      setSubmitting(false)
    }
  }

  const apiError = error instanceof ApiError ? error : null
  const hasFieldErrors = apiError !== null && Object.keys(apiError.fieldErrors).length > 0
  const generalMessage = typeof error === 'string' ? error : apiError && !hasFieldErrors ? apiError.message : null

  return (
    <div className="flex min-h-screen">
      <aside className="relative hidden w-[44%] max-w-xl flex-col justify-between overflow-hidden bg-slate-900 p-12 text-white lg:flex">
        <div
          className="pointer-events-none absolute inset-0 opacity-[0.07]"
          style={{
            backgroundImage: 'linear-gradient(#fff 1px, transparent 1px), linear-gradient(90deg, #fff 1px, transparent 1px)',
            backgroundSize: '40px 40px',
          }}
          aria-hidden
        />
        <div className="relative">
          <BrandMark inverted />
        </div>
        <div className="relative">
          <h1 className="text-3xl font-semibold leading-tight tracking-tight">
            Run registration and prize draws from one place.
          </h1>
          <ul className="mt-8 space-y-4">
            {HIGHLIGHTS.map(({ icon: Icon, text }) => (
              <li key={text} className="flex items-center gap-3 text-slate-300">
                <span className="flex size-9 items-center justify-center rounded-lg bg-white/10">
                  <Icon className="size-5 text-brand-200" aria-hidden />
                </span>
                {text}
              </li>
            ))}
          </ul>
        </div>
        <p className="relative text-sm text-slate-500">Authorized personnel only.</p>
      </aside>

      <main className="flex flex-1 items-center justify-center bg-white px-6 py-12">
        <div className="w-full max-w-sm">
          <div className="mb-8 lg:hidden">
            <BrandMark />
          </div>
          <h2 className="text-2xl font-semibold tracking-tight text-slate-900">Sign in</h2>
          <p className="mt-1 text-sm text-slate-500">Use the account provided by your event administrator.</p>

          {bootError && (
            <Alert
              tone="error"
              title="Cannot connect to the server"
              className="mt-6"
              action={
                <Button variant="secondary" size="sm" onClick={retry}>
                  Try again
                </Button>
              }
            >
              {bootError}
            </Alert>
          )}

          {generalMessage && (
            <Alert tone="error" className="mt-6">
              {generalMessage}
            </Alert>
          )}

          <form onSubmit={handleSubmit} className="mt-6 space-y-5" noValidate>
            <TextField
              label="Email or username"
              type="text"
              name="login"
              autoComplete="username"
              autoCapitalize="none"
              spellCheck={false}
              required
              autoFocus
              value={email}
              onChange={(event) => setEmail(event.target.value)}
              error={apiError?.fieldError('login')}
            />
            <TextField
              label="Password"
              type="password"
              name="password"
              autoComplete="current-password"
              required
              value={password}
              onChange={(event) => setPassword(event.target.value)}
              error={apiError?.fieldError('password')}
            />
            <Button
              type="submit"
              size="lg"
              className="w-full"
              loading={submitting}
              icon={<LogIn className="size-4" aria-hidden />}
            >
              {submitting ? 'Signing in…' : 'Sign in'}
            </Button>
          </form>
        </div>
      </main>
    </div>
  )
}
