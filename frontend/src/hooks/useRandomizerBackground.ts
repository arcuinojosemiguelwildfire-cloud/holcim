import { useEffect, useState } from 'react'
import { backgroundImageUrl, randomizerBackgroundService } from '../services/randomizerBackgroundService'

/**
 * URL of the custom Randomizer background, or null for the built-in default.
 * The image is preloaded first: if it cannot be loaded the default stays,
 * so the stage is never left blank.
 */
export function useRandomizerBackground(): string | null {
  const [url, setUrl] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    randomizerBackgroundService.current().then(
      (background) => {
        const next = backgroundImageUrl(background)
        if (!next) {
          if (!cancelled) setUrl(null)
          return
        }
        const image = new Image()
        image.onload = () => {
          if (!cancelled) setUrl(next)
        }
        image.onerror = () => {
          if (!cancelled) setUrl(null)
        }
        image.src = next
      },
      () => {
        if (!cancelled) setUrl(null) // keep the default background
      },
    )
    return () => {
      cancelled = true
    }
  }, [])

  return url
}
