import { useCallback, useEffect, useState, type RefObject } from 'react'

type FullscreenDocument = Document & { webkitFullscreenElement?: Element | null; webkitExitFullscreen?: () => Promise<void> | void }
type FullscreenElement = HTMLElement & { webkitRequestFullscreen?: () => Promise<void> | void }

function currentFullscreenElement(): Element | null {
  const doc = document as FullscreenDocument
  return doc.fullscreenElement ?? doc.webkitFullscreenElement ?? null
}

/**
 * Standard browser Fullscreen API for one element (with the WebKit prefix
 * for older Safari). Must be started from a user action (click / key press);
 * the user can always leave with Esc.
 */
export function useFullscreen(ref: RefObject<HTMLElement | null>) {
  const [isFullscreen, setIsFullscreen] = useState(false)
  const element = ref.current as FullscreenElement | null
  const supported =
    typeof document !== 'undefined' &&
    (document.fullscreenEnabled || Boolean((document.documentElement as FullscreenElement).webkitRequestFullscreen))

  useEffect(() => {
    const update = () => setIsFullscreen(currentFullscreenElement() !== null && currentFullscreenElement() === ref.current)
    document.addEventListener('fullscreenchange', update)
    document.addEventListener('webkitfullscreenchange', update)
    return () => {
      document.removeEventListener('fullscreenchange', update)
      document.removeEventListener('webkitfullscreenchange', update)
    }
  }, [ref])

  const enter = useCallback(async () => {
    const target = (ref.current ?? element) as FullscreenElement | null
    if (!target) return
    if (target.requestFullscreen) await target.requestFullscreen()
    else await target.webkitRequestFullscreen?.()
  }, [ref, element])

  const exit = useCallback(async () => {
    if (!currentFullscreenElement()) return
    const doc = document as FullscreenDocument
    if (doc.exitFullscreen) await doc.exitFullscreen()
    else await doc.webkitExitFullscreen?.()
  }, [])

  return { isFullscreen, supported, enter, exit }
}
