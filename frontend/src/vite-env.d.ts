/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** Base URL of the PHP API as seen by the browser, e.g. "/api". */
  readonly VITE_API_BASE_URL?: string
  /** Public path the app is served from, e.g. "/". */
  readonly VITE_BASE_PATH?: string
}

interface ImportMeta {
  readonly env: ImportMetaEnv
}
