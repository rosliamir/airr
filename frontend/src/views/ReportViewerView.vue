<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, uploadFile, ApiException } from '../api/client'

type DatasetParam = { name: string; label?: string; type?: string; default?: string }
type Report = {
  id: number; name: string; description?: string | null
  dataset?: { id: number; name: string; parameters?: DatasetParam[] } | null
  definition?: Record<string, unknown> | null
}
type SavedView = {
  id: number; report_id: number; name: string
  definition: Record<string, unknown>
  prompt: string | null
  prompt_history: { text: string; at: string }[]
  report: { id: number; name: string } | null
}

const route = useRoute()
const router = useRouter()
// Either coming from the source report (/reports/:id/view) or reopening a
// previously saved personal view (/views/:savedViewId) — same page either way.
const reportIdParam = route.params.id ? Number(route.params.id) : null
const savedViewIdParam = route.params.savedViewId ? Number(route.params.savedViewId) : null

const report = ref<Report | null>(null)
const savedView = ref<SavedView | null>(null)
const definition = ref<Record<string, unknown>>({})
const html = ref('')
const rowCount = ref<number | null>(null)
const busy = ref(true)
const error = ref('')

const runParams = ref<Record<string, string>>({})
const boundDatasetParams = computed(() => report.value?.dataset?.parameters ?? [])

// ── Prompt panel — same idea as the report editor's: describe a change,
// AI applies it. Here it always edits a personal SavedView, never the
// source report, regardless of which URL you arrived from.
const promptText = ref('')
const promptFile = ref<File | null>(null)
const promptBusy = ref(false)
const promptError = ref('')
const promptHistory = ref<{ text: string; at: string }[]>([])

const saveName = ref('')
const saveBusy = ref(false)
const shareUrl = computed(() => savedView.value ? `${window.location.origin}/views/${savedView.value.id}` : '')
// Report-level "Show header/menu" setting — off means this view renders bare
// (no sidebar/topbar), suitable for a clean public-style share link.
const showChrome = computed(() => (definition.value.show_header_menu as boolean | undefined) ?? true)
const chromeComponent = computed(() => (showChrome.value ? AdminLayout : 'div'))

async function loadReportMeta(id: number) {
  report.value = (await apiRequest<{ data: Report }>(`/reports/${id}`)).data
}

async function runReport() {
  busy.value = true
  error.value = ''
  try {
    if (savedView.value) {
      const res = await apiRequest<{ data: { html: string; row_count: number } }>(`/saved-views/${savedView.value.id}/run`, {
        method: 'POST', body: JSON.stringify({ params: runParams.value }),
      })
      html.value = res.data.html
      rowCount.value = res.data.row_count
    } else if (report.value) {
      const res = await apiRequest<{ data: { html: string; row_count: number } }>(`/reports/${report.value.id}/run`, {
        method: 'POST', body: JSON.stringify({ params: runParams.value, definition: definition.value }),
      })
      html.value = res.data.html
      rowCount.value = res.data.row_count
    }
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load report'
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  try {
    if (savedViewIdParam) {
      savedView.value = (await apiRequest<{ data: SavedView }>(`/saved-views/${savedViewIdParam}`)).data
      definition.value = savedView.value.definition
      promptHistory.value = savedView.value.prompt_history ?? []
      saveName.value = savedView.value.name
      if (savedView.value.report_id) await loadReportMeta(savedView.value.report_id)
    } else if (reportIdParam) {
      await loadReportMeta(reportIdParam)
      definition.value = report.value?.definition ?? {}
      saveName.value = `${report.value?.name ?? 'Report'} — my view`
    }
    for (const p of boundDatasetParams.value) runParams.value[p.name] = p.default ?? ''
    await runReport()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load'
    busy.value = false
  }
})

// ── AI prompt — always creates/updates a SavedView (personal, prompt-edited
// copy), never the source report. First prompt on a fresh /reports/:id/view
// implicitly creates the saved view; afterwards it just keeps editing it.
async function generateFromPrompt() {
  if (!promptText.value.trim()) return
  promptBusy.value = true
  promptError.value = ''
  try {
    if (!savedView.value) {
      if (!report.value) return
      savedView.value = (await apiRequest<{ data: SavedView }>(`/reports/${report.value.id}/saved-views`, {
        method: 'POST', body: JSON.stringify({ name: saveName.value, definition: definition.value }),
      })).data
      router.replace(`/views/${savedView.value.id}`)
    }
    const form = new FormData()
    form.append('prompt', promptText.value)
    if (promptFile.value) form.append('file', promptFile.value)
    const res = await uploadFile<{ data: { definition: Record<string, unknown> } }>(`/saved-views/${savedView.value.id}/generate-from-prompt`, form)
    definition.value = res.data.definition
    promptHistory.value = [...promptHistory.value, { text: promptText.value, at: new Date().toISOString() }]
    promptFile.value = null
    await runReport()
  } catch (e) {
    promptError.value = e instanceof ApiException ? e.error.message : 'AI generation failed'
  } finally {
    promptBusy.value = false
  }
}
function usePreviousPrompt(text: string) {
  promptText.value = text
}
function clearPrompt() {
  promptText.value = ''
  promptFile.value = null
}
function onPromptFileChange(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0]
  if (f) promptFile.value = f
}

// ── Save — explicit save button (distinct from the implicit first-prompt
// save above): persists the current definition under this saved view,
// creating one first if none exists yet (e.g. changed parameters only, no prompt).
async function saveView() {
  saveBusy.value = true
  try {
    if (savedView.value) {
      savedView.value = (await apiRequest<{ data: SavedView }>(`/saved-views/${savedView.value.id}`, {
        method: 'PUT', body: JSON.stringify({ name: saveName.value, definition: definition.value }),
      })).data
    } else if (report.value) {
      savedView.value = (await apiRequest<{ data: SavedView }>(`/reports/${report.value.id}/saved-views`, {
        method: 'POST', body: JSON.stringify({ name: saveName.value, definition: definition.value }),
      })).data
      router.replace(`/views/${savedView.value.id}`)
    }
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    saveBusy.value = false
  }
}
function copyShareUrl() {
  if (shareUrl.value) navigator.clipboard?.writeText(shareUrl.value)
}

// ── Print / Export — same as the editor's Preview modal.
function printReport() {
  const w = window.open('', '_blank')
  if (!w) return
  w.document.write(`<html><head><title>${report.value?.name ?? 'Report'}</title></html><body>${html.value}</body></html>`)
  w.document.close()
  w.focus()
  w.print()
}
async function exportReport(format: 'csv' | 'excel') {
  if (!report.value) return
  try {
    // The source report's export-file endpoint accepts a definition override —
    // works the same whether the definition came from the report itself or a
    // saved view, without needing a separate export endpoint for saved views.
    const res = await fetch(`${(import.meta as { env: { VITE_API_BASE_URL?: string } }).env.VITE_API_BASE_URL ?? ''}/api/reports/${report.value.id}/export-file`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('airr_token') ?? ''}` },
      body: JSON.stringify({ format, params: runParams.value, definition: definition.value }),
    })
    if (!res.ok) return
    const blob = await res.blob()
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `${report.value?.name ?? 'report'}.${format === 'csv' ? 'csv' : 'xlsx'}`
    document.body.appendChild(a)
    a.click()
    a.remove()
  } catch { /* non-fatal */ }
}
</script>

<template>
  <component :is="chromeComponent" :class="showChrome ? '' : 'min-h-screen bg-slate-50 p-6'">
    <div class="max-w-6xl mx-auto flex gap-5">
      <div class="flex-1 min-w-0 space-y-4">
        <div class="flex items-center justify-between flex-wrap gap-2">
          <div>
            <h1 class="text-lg font-bold text-slate-800">{{ savedView?.name ?? report?.name ?? 'Report' }}</h1>
            <p v-if="savedView" class="text-xs text-slate-400">Personal view of "{{ savedView.report?.name ?? report?.name }}"</p>
          </div>
          <div class="flex items-center gap-2">
            <span v-if="rowCount !== null" class="text-xs text-slate-400 mr-1">{{ rowCount }} rows</span>
            <button @click="printReport" :disabled="!html" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5 disabled:opacity-40">Print / PDF</button>
            <button @click="exportReport('csv')" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5">Export CSV</button>
            <button @click="exportReport('excel')" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5">Export Excel</button>
            <button @click="saveView" :disabled="saveBusy" class="text-xs font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5 disabled:opacity-50">{{ saveBusy ? 'Saving…' : 'Save' }}</button>
          </div>
        </div>

        <div v-if="shareUrl" class="flex items-center gap-2 text-xs bg-slate-50 rounded-lg px-3 py-2">
          <span class="text-slate-400">Shareable link:</span>
          <code class="text-slate-600 truncate flex-1">{{ shareUrl }}</code>
          <button @click="copyShareUrl" class="text-airr-600 hover:underline shrink-0">Copy</button>
        </div>

        <div v-if="boundDatasetParams.length" class="flex flex-wrap items-end gap-3 bg-slate-50 rounded-lg px-3 py-2">
          <div v-for="p in boundDatasetParams" :key="p.name">
            <label class="block text-[10px] text-slate-500 mb-0.5">{{ p.label || p.name }}</label>
            <input v-model="runParams[p.name]" :type="p.type === 'date' ? 'date' : 'text'"
              class="text-xs rounded border border-slate-200 px-2 py-1 outline-none focus:ring-2 focus:ring-airr-300" />
          </div>
          <button @click="runReport" class="text-xs font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5">Run</button>
        </div>

        <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>
        <div v-if="busy" class="text-slate-400 text-sm">Loading…</div>
        <div v-else-if="html" class="bg-white shadow-sm rounded-xl p-5 overflow-x-auto">
          <div v-html="html"></div>
        </div>
      </div>

      <!-- Prompt panel — same feature as the report editor's: describe a
           change in plain language, AI applies it (to this personal saved
           view, never the source report). -->
      <aside class="w-72 shrink-0 space-y-3">
        <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">Prompt</p>
        <p class="text-[10px] text-slate-400">Tell the AI how this report should look — changes save to your own view, the original report is never touched.</p>
        <textarea v-model="promptText" rows="4" placeholder='e.g. "remove the email column", "add a running total"'
          class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 resize-none"></textarea>
        <label class="block text-xs text-slate-500 border border-dashed border-slate-200 rounded-lg px-2 py-1.5 text-center cursor-pointer hover:bg-slate-50">
          {{ promptFile ? promptFile.name : 'Attach file' }}
          <input type="file" class="hidden" @change="onPromptFileChange" />
        </label>
        <div class="flex gap-2">
          <button @click="clearPrompt" class="text-xs text-slate-500 px-2 py-1.5">Clear</button>
          <button @click="generateFromPrompt" :disabled="promptBusy || !promptText.trim()"
            class="flex-1 text-xs font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5 disabled:opacity-50">
            {{ promptBusy ? 'Generating…' : 'Generate' }}
          </button>
        </div>
        <p v-if="promptError" class="text-xs text-airr-700 bg-airr-50 rounded-lg px-2 py-1.5">{{ promptError }}</p>

        <div v-if="promptHistory.length" class="pt-2 border-t border-slate-100 space-y-1">
          <p class="text-[10px] font-medium text-slate-400 uppercase tracking-wide">Previous prompts</p>
          <button v-for="(p, i) in [...promptHistory].reverse()" :key="i" @click="usePreviousPrompt(p.text)"
            class="block w-full text-left text-[11px] text-slate-500 hover:bg-slate-50 rounded px-2 py-1 truncate" :title="p.text">
            {{ p.text }}
          </button>
        </div>
      </aside>
    </div>
  </component>
</template>
