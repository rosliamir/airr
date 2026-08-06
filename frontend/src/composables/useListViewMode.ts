import { ref, watch } from 'vue'

export type ListViewMode = 'card' | 'table'

/** Persists card/table listing preference per page (e.g. "reports", "projects") across visits. */
export function useListViewMode(key: string, defaultMode: ListViewMode = 'card') {
  const storageKey = `airr:list-view-mode:${key}`
  const stored = localStorage.getItem(storageKey)
  const viewMode = ref<ListViewMode>(stored === 'card' || stored === 'table' ? stored : defaultMode)

  watch(viewMode, (mode) => {
    localStorage.setItem(storageKey, mode)
  })

  return { viewMode }
}
