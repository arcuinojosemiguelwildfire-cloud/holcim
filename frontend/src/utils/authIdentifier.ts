// Supabase Auth sign-in identifier (Phase 2; not used by the PHP-backed app yet).
//
// Supabase Auth signs in with email + password. Staff accounts use their real email.
// Scanner operators keep signing in with their USERNAME: each one has an internal Auth
// email "<username>@<domain>" that is derived here and never shown to anyone. The
// domain is not a secret (it is in the public bundle); it only has to be a domain that
// never receives mail. Self-contained (no imports) so the migration tools reuse it.

/** Default internal domain for username logins (".invalid" is reserved by RFC 2606). */
export const DEFAULT_USERNAME_EMAIL_DOMAIN = 'scanner.holcim-event.invalid'

/** Same rule as the PHP scanner-operator usernames (User::USERNAME_PATTERN). */
export const USERNAME_PATTERN = /^[a-z0-9._-]{3,60}$/

export function usernameToAuthEmail(username: string, domain: string = DEFAULT_USERNAME_EMAIL_DOMAIN): string {
  const name = username.trim().toLowerCase()
  if (!USERNAME_PATTERN.test(name)) throw new Error('Invalid username.')
  return `${name}@${domain}`
}

/**
 * What the login form sends to Supabase Auth: an email as typed (lower-cased), or the
 * internal email of a username. Returns null for input that can be neither.
 */
export function loginIdentifierToEmail(identifier: string, domain: string = DEFAULT_USERNAME_EMAIL_DOMAIN): string | null {
  const value = identifier.trim().toLowerCase()
  if (value.includes('@')) return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value) ? value : null
  return USERNAME_PATTERN.test(value) ? `${value}@${domain}` : null
}

/** True for internal username emails, so the UI can show the username instead. */
export function isInternalUsernameEmail(email: string, domain: string = DEFAULT_USERNAME_EMAIL_DOMAIN): boolean {
  return email.toLowerCase().endsWith(`@${domain}`)
}
