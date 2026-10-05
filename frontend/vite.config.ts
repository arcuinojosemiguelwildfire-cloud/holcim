import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  assertNoSecretsInBrowserEnv(env)

  return {
    plugins: [react(), tailwindcss()],

    // Public path the built app is served from ("/" in production at a domain
    // root, "/Holcim/frontend/dist/" when previewing a build inside XAMPP).
    base: env.VITE_BASE_PATH || '/',

    server: {
      port: 5173,
      // In development the browser calls /api on the Vite server, which
      // forwards to the PHP API in XAMPP. Same origin => cookies and CSRF
      // work without any CORS configuration.
      proxy: {
        '/api': {
          target: env.DEV_API_PROXY_TARGET || 'http://localhost/Holcim/backend',
          changeOrigin: true,
        },
      },
    },

    build: {
      outDir: 'dist',
      sourcemap: false,
    },
  }
})

/**
 * Everything prefixed VITE_ is compiled into the public bundle. Refuse to build or
 * serve if a Supabase service-role / secret key ended up in one of those variables.
 */
function assertNoSecretsInBrowserEnv(env: Record<string, string>): void {
  for (const [name, value] of Object.entries(env)) {
    if (!name.startsWith('VITE_') || !value) continue
    const v = value.trim()
    let reason = ''
    if (/SERVICE_ROLE|SECRET/i.test(name)) reason = 'its name marks it as a secret'
    else if (v.startsWith('sb_secret_')) reason = 'it holds a Supabase secret key'
    else if (/^eyJ[\w-]*\.eyJ[\w-]*\.[\w-]*$/.test(v)) {
      try {
        const role = JSON.parse(Buffer.from(v.split('.')[1] ?? '', 'base64url').toString('utf8')).role
        if (role === 'service_role') reason = 'it holds a service_role key'
      } catch {
        // not a JWT payload we can read; leave it
      }
    }
    if (reason) {
      throw new Error(`Refusing to build: ${name} would be published in the browser bundle but ${reason}. Remove it (service-role keys belong only in Supabase Edge Function secrets).`)
    }
  }
}
