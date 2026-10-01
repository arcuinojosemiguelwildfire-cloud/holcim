import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')

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
