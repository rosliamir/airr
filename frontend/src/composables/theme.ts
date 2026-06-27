import { ref } from 'vue'

// M14 — UI themes. Persisted client-side; applied via html[data-theme].
export type Theme = 'classic' | 'dark' | 'vibration'

export const THEMES: { value: Theme; label: string; hint: string }[] = [
  { value: 'classic', label: 'AIRR Classic', hint: 'Light, crimson accent' },
  { value: 'dark', label: 'Dark Mode', hint: 'Easy on the eyes' },
  { value: 'vibration', label: 'Vibration', hint: 'Colorful & lively' },
]

const STORAGE_KEY = 'airr-theme'
export const theme = ref<Theme>('classic')

export function applyTheme(t: Theme) {
  theme.value = t
  document.documentElement.setAttribute('data-theme', t)
  try { localStorage.setItem(STORAGE_KEY, t) } catch { /* ignore */ }
}

export function initTheme() {
  let saved: string | null = null
  try { saved = localStorage.getItem(STORAGE_KEY) } catch { /* ignore */ }
  applyTheme((saved as Theme) || 'classic')
}
