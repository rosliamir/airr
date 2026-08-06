<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, uploadFile, ApiException } from '../api/client'

type DatasetParam = { name: string; label?: string; type?: string; default?: string }
type CustomParam = {
  id: string; title: string; name: string
  type: 'text' | 'dropdown' | 'checkbox' | 'radio' | 'date' | 'datetime' | 'time' | 'amount'
  default_value?: string | boolean | null; options?: string[]
  enabled: boolean
}
type Report = {
  id: number; name: string; description?: string | null
  dataset?: { id: number; name: string; parameters?: DatasetParam[] } | null
  definition?: Record<string, unknown> | null
}
type SavedView = {
  id: number; report_id: number; name: string
  definition: Record<string, unknown>
  prompt: string | null
  prompt_history: { text: string; at: string; before?: unknown }[]
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
const busy = ref(false)
const pageLoading = ref(true)
const error = ref('')

const runParams = ref<Record<string, string>>({})
const boundDatasetParams = computed(() => report.value?.dataset?.parameters ?? [])
// Same gating as the editor's Preview modal: only params whose Fixed toggle
// is checked (fixed_parameters_enabled) actually appear/get sent, and the
// screen only shows before running if requireParameterScreen (default on)
// AND there's actually something to configure.
const fixedParamsEnabled = computed(() => (definition.value.fixed_parameters_enabled as Record<string, boolean>) ?? {})
const enabledDatasetParams = computed(() => boundDatasetParams.value.filter(p => fixedParamsEnabled.value[p.name] ?? true))
const customParams = computed(() => (definition.value.custom_parameters as CustomParam[]) ?? [])
const enabledCustomParams = computed(() => customParams.value.filter(p => p.enabled))
const customParamValues = ref<Record<string, string | boolean>>({})
const requireParameterScreen = computed(() => (definition.value.require_parameter_screen as boolean | undefined) ?? true)
// false = show the parameter screen (Run button); true = show the result.
const resultReady = ref(false)

// ── Prompt panel — same idea as the report editor's: describe a change,
// AI applies it. Here it always edits a personal SavedView, never the
// source report, regardless of which URL you arrived from.
const promptText = ref('')
const promptFile = ref<File | null>(null)
const promptBusy = ref(false)
const promptError = ref('')
const promptHistory = ref<{ text: string; at: string; before?: unknown }[]>([])
const resetBusy = ref(false)
const restoreBusy = ref<number | null>(null)
// Collapsed to a small toggle button by default — opens into a dockable
// panel the user can place on any side of the page.
const promptOpen = ref(false)
const promptPosition = ref<'top' | 'bottom' | 'left' | 'right'>('right')
const promptIsVertical = computed(() => promptPosition.value === 'left' || promptPosition.value === 'right')

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

// Only enabled dataset params are actually sent — same rule as the editor's
// Preview modal (a Fixed toggle that's unchecked means "ignore this param").
const activeRunParams = computed(() => {
  const out: Record<string, string> = {}
  for (const p of enabledDatasetParams.value) out[p.name] = runParams.value[p.name] ?? ''
  return out
})
const activeCustomParamValues = computed(() => {
  const out: Record<string, string | boolean> = {}
  for (const p of enabledCustomParams.value) out[p.name] = customParamValues.value[p.id] ?? ''
  return out
})

async function runReport() {
  resultReady.value = true
  busy.value = true
  error.value = ''
  try {
    if (savedView.value) {
      const res = await apiRequest<{ data: { html: string; row_count: number } }>(`/saved-views/${savedView.value.id}/run`, {
        method: 'POST', body: JSON.stringify({ params: activeRunParams.value, custom_params: activeCustomParamValues.value }),
      })
      html.value = res.data.html
      rowCount.value = res.data.row_count
    } else if (report.value) {
      const res = await apiRequest<{ data: { html: string; row_count: number } }>(`/reports/${report.value.id}/run`, {
        method: 'POST', body: JSON.stringify({ params: activeRunParams.value, custom_params: activeCustomParamValues.value, apply_filter: true, definition: definition.value }),
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
    for (const p of customParams.value) customParamValues.value[p.id] = p.default_value ?? (p.type === 'checkbox' ? false : '')
    pageLoading.value = false
    // Same rule as the editor's Preview modal: show the parameter screen
    // first unless the toggle is off, or there's simply nothing to
    // configure (no enabled dataset/custom params) — then just run.
    const hasParams = enabledDatasetParams.value.length > 0 || enabledCustomParams.value.length > 0
    if (!requireParameterScreen.value || !hasParams) await runReport()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load'
    pageLoading.value = false
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

// ── Reset / History — discard AI customization back to the source report,
// or step back to how things looked right before a specific past prompt.
async function resetView() {
  if (!savedView.value) return
  if (!confirm('Reset this view back to the report as it currently is? Your prompt history stays, but all customization is discarded.')) return
  resetBusy.value = true
  try {
    savedView.value = (await apiRequest<{ data: SavedView }>(`/saved-views/${savedView.value.id}/reset`, { method: 'POST' })).data
    definition.value = savedView.value.definition
    promptHistory.value = savedView.value.prompt_history ?? []
    await runReport()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Reset failed'
  } finally {
    resetBusy.value = false
  }
}
async function restorePrompt(index: number) {
  if (!savedView.value) return
  restoreBusy.value = index
  try {
    savedView.value = (await apiRequest<{ data: SavedView }>(`/saved-views/${savedView.value.id}/restore`, {
      method: 'POST', body: JSON.stringify({ index }),
    })).data
    definition.value = savedView.value.definition
    promptHistory.value = savedView.value.prompt_history ?? []
    await runReport()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Restore failed'
  } finally {
    restoreBusy.value = null
  }
}
// Always available (unlike the Shareable link row, which only appears once a
// saved view exists) — copies whatever URL is currently open, report or saved view.
function copyPageLink() {
  navigator.clipboard?.writeText(window.location.href)
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
    <div class="max-w-6xl mx-auto flex" :class="promptOpen && !promptIsVertical ? 'flex-col gap-5' : 'gap-5'">
      <!-- Prompt panel docked left/top comes before the main content -->
      <aside v-if="promptOpen && (promptPosition === 'left' || promptPosition === 'top')"
        class="space-y-3" :class="promptIsVertical ? 'w-72 shrink-0' : 'w-full'">
        <div class="flex items-center justify-between">
          <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">Prompt</p>
          <div class="flex items-center gap-1">
            <button v-for="pos in (['top','bottom','left','right'] as const)" :key="pos" @click="promptPosition = pos"
              :title="`Dock ${pos}`" class="text-[10px] px-1.5 py-0.5 rounded" :class="promptPosition === pos ? 'bg-airr-100 text-airr-600' : 'text-slate-300 hover:text-slate-500'">■</button>
            <button @click="promptOpen = false" class="text-slate-400 hover:text-slate-600 text-sm ml-1">×</button>
          </div>
        </div>
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
          <p class="text-[10px] font-medium text-slate-400 uppercase tracking-wide">History</p>
          <div v-for="(p, i) in promptHistory.map((p, i) => ({ ...p, i })).reverse()" :key="i"
            class="flex items-start gap-1.5 text-[11px] text-slate-500 hover:bg-slate-50 rounded px-2 py-1">
            <button @click="usePreviousPrompt(p.text)" class="flex-1 min-w-0 text-left truncate" :title="p.text">
              {{ p.text }}
              <span class="block text-[10px] text-slate-400">{{ new Date(p.at).toLocaleString() }}</span>
            </button>
            <button v-if="p.before" @click="restorePrompt(p.i)" :disabled="restoreBusy === p.i" class="shrink-0 text-airr-600 hover:underline disabled:opacity-50">
              {{ restoreBusy === p.i ? '…' : 'Restore' }}
            </button>
          </div>
        </div>
      </aside>

      <div class="flex-1 min-w-0 space-y-4">
        <div class="flex items-center justify-between flex-wrap gap-2">
          <div>
            <h1 class="text-lg font-bold text-slate-800">{{ savedView?.name ?? report?.name ?? 'Report' }}</h1>
            <p v-if="savedView" class="text-xs text-slate-400">Personal view of "{{ savedView.report?.name ?? report?.name }}"</p>
          </div>
          <div class="flex items-center gap-2">
            <span v-if="rowCount !== null" class="text-xs text-slate-400 mr-1">{{ rowCount }} rows</span>
            <button @click="copyPageLink" title="Copy link to this page" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5">🔗 Copy link</button>
            <button @click="printReport" :disabled="!html" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5 disabled:opacity-40">Print / PDF</button>
            <button @click="exportReport('csv')" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5">Export CSV</button>
            <button @click="exportReport('excel')" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5">Export Excel</button>
            <button v-if="savedView" @click="resetView" :disabled="resetBusy" title="Discard all prompt customization, back to the report as it currently is" class="text-xs font-medium text-slate-500 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5 disabled:opacity-50">{{ resetBusy ? 'Resetting…' : 'Reset' }}</button>
            <button @click="saveView" :disabled="saveBusy" class="text-xs font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5 disabled:opacity-50">{{ saveBusy ? 'Saving…' : 'Save' }}</button>
            <!-- Collapsed by default — just this toggle button, per request -->
            <button @click="promptOpen = !promptOpen" title="AI Prompt"
              class="text-xs font-medium rounded-lg px-3 py-1.5 border" :class="promptOpen ? 'bg-airr-500 text-white border-airr-500' : 'text-airr-600 border-airr-200 hover:bg-airr-50'">✨ AI Prompt</button>
          </div>
        </div>

        <div v-if="shareUrl" class="flex items-center gap-2 text-xs bg-slate-50 rounded-lg px-3 py-2">
          <span class="text-slate-400">Shareable link:</span>
          <code class="text-slate-600 truncate flex-1">{{ shareUrl }}</code>
          <button @click="copyShareUrl" class="text-airr-600 hover:underline shrink-0">Copy</button>
        </div>

        <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>
        <div v-if="pageLoading" class="text-slate-400 text-sm">Loading…</div>

        <!-- Parameter screen — shown before running, same rule as the editor's
             Preview modal: only when requireParameterScreen is on AND there's
             something to configure (enabled dataset/custom params). -->
        <div v-else-if="!resultReady" class="bg-white shadow-sm rounded-xl p-6 flex items-start justify-center">
          <div class="w-full max-w-md space-y-3">
            <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">Parameters</p>
            <div v-for="p in enabledDatasetParams" :key="p.name">
              <label class="block text-[10px] text-slate-500 mb-0.5">{{ p.label || p.name }}</label>
              <input v-model="runParams[p.name]" :type="p.type === 'number' ? 'number' : p.type === 'date' ? 'date' : 'text'"
                class="w-full text-xs rounded border border-slate-200 px-2 py-1 outline-none focus:ring-2 focus:ring-airr-300" />
            </div>
            <div v-for="p in enabledCustomParams" :key="p.id">
              <label class="block text-[10px] text-slate-500 mb-0.5">{{ p.title }}</label>
              <select v-if="p.type === 'dropdown'" v-model="customParamValues[p.id]"
                class="w-full text-xs rounded border border-slate-200 px-2 py-1 outline-none focus:ring-2 focus:ring-airr-300">
                <option v-for="o in (p.options ?? [])" :key="o" :value="o">{{ o }}</option>
              </select>
              <div v-else-if="p.type === 'radio'" class="flex flex-wrap gap-3 pt-0.5">
                <label v-for="o in (p.options ?? [])" :key="o" class="flex items-center gap-1 text-xs text-slate-600">
                  <input type="radio" :name="`cp_${p.id}`" :value="o" v-model="customParamValues[p.id]" /> {{ o }}
                </label>
              </div>
              <label v-else-if="p.type === 'checkbox'" class="flex items-center gap-1.5 text-xs text-slate-600 pt-0.5">
                <input type="checkbox" v-model="customParamValues[p.id]" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /> Yes
              </label>
              <input v-else v-model="customParamValues[p.id]"
                :type="p.type === 'date' ? 'date' : p.type === 'datetime' ? 'datetime-local' : p.type === 'time' ? 'time' : p.type === 'amount' ? 'number' : 'text'"
                class="w-full text-xs rounded border border-slate-200 px-2 py-1 outline-none focus:ring-2 focus:ring-airr-300" />
            </div>
            <div class="flex justify-end pt-2">
              <button @click="runReport" :disabled="busy"
                class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
                {{ busy ? 'Running…' : 'Run' }}
              </button>
            </div>
          </div>
        </div>

        <template v-else>
          <div v-if="enabledDatasetParams.length || enabledCustomParams.length" class="flex items-center justify-between bg-slate-50 rounded-lg px-3 py-2">
            <button @click="resultReady = false" class="text-xs font-medium text-slate-500 hover:text-slate-700">← Back to parameters</button>
            <button @click="runReport" :disabled="busy" class="text-xs font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5 disabled:opacity-50">{{ busy ? 'Running…' : 'Re-run' }}</button>
          </div>
          <div v-if="busy" class="text-slate-400 text-sm">Loading…</div>
          <div v-else-if="html" class="bg-white shadow-sm rounded-xl p-5 overflow-x-auto">
            <div v-html="html"></div>
          </div>
        </template>
      </div>

      <!-- Prompt panel docked right/bottom comes after the main content -->
      <aside v-if="promptOpen && (promptPosition === 'right' || promptPosition === 'bottom')"
        class="space-y-3" :class="promptIsVertical ? 'w-72 shrink-0' : 'w-full'">
        <div class="flex items-center justify-between">
          <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">Prompt</p>
          <div class="flex items-center gap-1">
            <button v-for="pos in (['top','bottom','left','right'] as const)" :key="pos" @click="promptPosition = pos"
              :title="`Dock ${pos}`" class="text-[10px] px-1.5 py-0.5 rounded" :class="promptPosition === pos ? 'bg-airr-100 text-airr-600' : 'text-slate-300 hover:text-slate-500'">■</button>
            <button @click="promptOpen = false" class="text-slate-400 hover:text-slate-600 text-sm ml-1">×</button>
          </div>
        </div>
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
          <p class="text-[10px] font-medium text-slate-400 uppercase tracking-wide">History</p>
          <div v-for="(p, i) in promptHistory.map((p, i) => ({ ...p, i })).reverse()" :key="i"
            class="flex items-start gap-1.5 text-[11px] text-slate-500 hover:bg-slate-50 rounded px-2 py-1">
            <button @click="usePreviousPrompt(p.text)" class="flex-1 min-w-0 text-left truncate" :title="p.text">
              {{ p.text }}
              <span class="block text-[10px] text-slate-400">{{ new Date(p.at).toLocaleString() }}</span>
            </button>
            <button v-if="p.before" @click="restorePrompt(p.i)" :disabled="restoreBusy === p.i" class="shrink-0 text-airr-600 hover:underline disabled:opacity-50">
              {{ restoreBusy === p.i ? '…' : 'Restore' }}
            </button>
          </div>
        </div>
      </aside>
    </div>
  </component>
</template>
