let audioContext: AudioContext | null = null

/**
 * Short feedback tone via Web Audio (no sound files). Best effort only:
 * browsers may block audio until the user has interacted with the page.
 */
export function beep(kind: 'success' | 'warning' | 'error'): void {
  try {
    audioContext ??= new AudioContext()
    const context = audioContext
    if (context.state === 'suspended') void context.resume()

    const tones = { success: [880, 1320], warning: [660, 660], error: [220, 180] }[kind]
    tones.forEach((frequency, index) => {
      const oscillator = context.createOscillator()
      const gain = context.createGain()
      const start = context.currentTime + index * 0.14
      oscillator.type = kind === 'error' ? 'square' : 'sine'
      oscillator.frequency.value = frequency
      gain.gain.setValueAtTime(0.0001, start)
      gain.gain.exponentialRampToValueAtTime(0.25, start + 0.01)
      gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.12)
      oscillator.connect(gain).connect(context.destination)
      oscillator.start(start)
      oscillator.stop(start + 0.13)
    })
  } catch {
    // Sound is optional.
  }
}
