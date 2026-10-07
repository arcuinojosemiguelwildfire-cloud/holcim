/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Base URL of the PHP API as seen by the browser, e.g. "/api". */
  readonly VITE_API_BASE_URL?: string
  /** Public path the app is served from, e.g. "/". */
  readonly VITE_BASE_PATH?: string
  /** Supabase project URL, e.g. "https://abcd.supabase.co" (public). */
  readonly VITE_SUPABASE_URL?: string
  /** Supabase anon / publishable key (public). NEVER the service-role key. */
  readonly VITE_SUPABASE_ANON_KEY?: string
  /** Internal email domain for username (scanner) logins in Supabase Auth (public). */
  readonly VITE_SUPABASE_USERNAME_EMAIL_DOMAIN?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
