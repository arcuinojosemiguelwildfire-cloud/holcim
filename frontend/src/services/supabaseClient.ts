// Supabase browser client (Phase 1 of the Supabase migration: configuration only).
//
// NOT used by the app yet - the PHP API (apiClient.ts) is still the live backend.
// Later phases switch auth and data access to Supabase one feature at a time.
//
// Configuration (frontend/.env.local, or the Vercel project's environment variables):
//   VITE_SUPABASE_URL       https://<project-ref>.supabase.co
//   VITE_SUPABASE_ANON_KEY  the PUBLIC anon / publishable key
//
// SECURITY: everything prefixed VITE_ is compiled into the public JavaScript bundle.
// The service-role / secret key bypasses Row Level Security and must NEVER be here,
// in Vercel, in client code or in Git; it lives only in Supabase Edge Function secrets.
// This module refuses to start with such a key (vite.config.ts also refuses to build).
import { createClient, type SupabaseClient } from '@supabase/supabase-js'

/** Returns why a key must not be used in the browser, or null when it is a public key. */
export function unsafeBrowserKeyReason(key: string): string | null {
  const k = key.trim()
  if (k.startsWith('sb_secret_')) return 'this is a Supabase SECRET key'
  const parts = k.split('.')
  if (parts.length === 3 && parts[1]) {
    // Legacy JWT keys: the payload says which role the key acts as.
    try {
      const json = atob(parts[1].replace(/-/g, '+').replace(/_/g, '/'))
      const role = (JSON.parse(json) as { role?: unknown }).role
      if (role !== 'anon') return `this key acts as role "${String(role)}", not "anon"`
    } catch {
      return 'the key is not a valid Supabase JWT'
    }
    return null
  }
  if (k.startsWith('sb_publishable_')) return null
  return 'unrecognised key format (expected the anon or sb_publishable_ key)'
}

export interface SupabaseConfig {
  url: string
  anonKey: string
}

/** Reads and validates the public Supabase settings; null when not configured. */
export function readSupabaseConfig(env: Record<string, unknown> = import.meta.env): SupabaseConfig | null {
  const text = (v: unknown): string => (typeof v === 'string' ? v.trim() : '')
  const url = text(env.VITE_SUPABASE_URL)
  const anonKey = text(env.VITE_SUPABASE_ANON_KEY)
  if (url === '' && anonKey === '') return null
  if (url === '' || anonKey === '') {
    throw new Error('Supabase is half configured: set both VITE_SUPABASE_URL and VITE_SUPABASE_ANON_KEY.')
  }
  let parsed: URL
  try {
    parsed = new URL(url)
  } catch {
    throw new Error('VITE_SUPABASE_URL is not a valid URL.')
  }
  const local = parsed.hostname === 'localhost' || parsed.hostname === '127.0.0.1'
  if (parsed.protocol !== 'https:' && !local) throw new Error('VITE_SUPABASE_URL must use https://.')
  const reason = unsafeBrowserKeyReason(anonKey)
  if (reason) throw new Error(`Refusing VITE_SUPABASE_ANON_KEY: ${reason}. Use the public anon key.`)
  return { url: parsed.origin, anonKey }
}

export function isSupabaseConfigured(): boolean {
  return readSupabaseConfig() !== null
}

let client: SupabaseClient | null = null

/** Lazily created shared client. Throws if Supabase is not configured. */
export function getSupabase(): SupabaseClient {
  if (client) return client
  const config = readSupabaseConfig()
  if (!config) throw new Error('Supabase is not configured (VITE_SUPABASE_URL / VITE_SUPABASE_ANON_KEY).')
  client = createClient(config.url, config.anonKey, {
    auth: { persistSession: true, autoRefreshToken: true, detectSessionInUrl: true },
  })
  return client
}
