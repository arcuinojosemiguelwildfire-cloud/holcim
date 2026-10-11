import type { CSSProperties } from 'react'
import { API_BASE_URL, apiClient } from './apiClient'

/** Active Randomizer background: the built-in default or an uploaded image. */
export interface RandomizerBackground {
  custom: boolean
  /** Changes whenever the image changes (cache busting). */
  version: string | null
  width: number | null
  height: number | null
  originalName: string | null
  updatedAt: string | null
  /** API path of the image, e.g. /randomizers/background/image?v=… (null = default). */
  imagePath: string | null
}

export const BACKGROUND_MAX_BYTES = 10 * 1024 * 1024
export const BACKGROUND_TYPES = ['image/jpeg', 'image/png', 'image/webp']
export const BACKGROUND_ACCEPT = '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp'

/** Full URL of the custom image (served by the API with the session cookie), or null for the default. */
export function backgroundImageUrl(background: RandomizerBackground | null | undefined): string | null {
  return background?.custom && background.imagePath ? `${API_BASE_URL}${background.imagePath}` : null
}

/**
 * Inline style for the Randomizer stage: cover + center fill the stage (and
 * fullscreen) without distorting the image. With `overlay` (the admin view
 * and the Settings preview) a dark layer keeps the controls readable; the
 * fullscreen presentation screen shows the image exactly as uploaded.
 */
export function stageBackgroundStyle(url: string | null, overlay = true): CSSProperties | undefined {
  if (!url) return undefined
  const image = `url("${url.replace(/"/g, '%22')}")`
  return {
    backgroundImage: overlay ? `linear-gradient(rgba(2, 6, 23, 0.55), rgba(2, 6, 23, 0.55)), ${image}` : image,
    backgroundSize: 'cover',
    backgroundPosition: 'center',
    backgroundRepeat: 'no-repeat',
  }
}

export const randomizerBackgroundService = {
  async current(): Promise<RandomizerBackground> {
    return (await apiClient.get<{ background: RandomizerBackground }>('/randomizers/background')).background
  },
  async upload(file: File): Promise<RandomizerBackground> {
    const form = new FormData()
    form.append('image', file)
    return (await apiClient.upload<{ background: RandomizerBackground }>('/settings/randomizer-background', form)).background
  },
  async restoreDefault(): Promise<RandomizerBackground> {
    return (await apiClient.delete<{ background: RandomizerBackground }>('/settings/randomizer-background')).background
  },
}
