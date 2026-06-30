<script setup lang="ts">
import { onMounted, ref, computed, watch } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, ApiException } from '../api/client'
import { useAuthStore } from '../stores/auth'

type Ref2 = { id: number; name: string; code?: string }
type Grant = { role_id: number; name?: string; view: boolean; edit: boolean; run: boolean }
type Dataset = { id: number; name: string; parameters?: { name: string; label?: string; type?: string; default?: string }[] }
type Report = {
  id: number; name: string; description?: string; type: string; status: string
  project?: Ref2 | null; dataset?: Ref2 | null; creator?: Ref2 | null
  definition?: Record<string, unknown>; permissions?: Grant[]; updated_at?: string
}
type HistoryEntry = { id: number; action: string; snapshot: Record<string, unknown>; changed_by_name: string; created_at: string }
type LogEntry   = { id: number; event: string; properties: Record<string, unknown>; user_name: string; created_at: string }

const auth = useAuthStore()
const canCreate = auth.can('reports.create')
const canEdit   = auth.can('reports.edit')
const canRun    = auth.can('reports.run')

const TYPES     = ['table', 'grouped', 'kpi', 'matrix', 'chart', 'document']
const TEMPLATES = ['default', 'minimal', 'executive', 'detailed']
const STATUS_BADGE: Record<string, string> = {
  draft:     'bg-amber-100 text-amber-700',
  published: 'bg-emerald-100 text-emerald-700',
}

// ── State ────────────────────────────────────────────────────────────────────
const reports  = ref<Report[]>([])
const allDatasets = ref<Dataset[]>([])
const roles    = ref<{ id: number; name: string }[]>([])
const selected = ref<Report | null>(null)
const loading  = ref(true)
const busy     = ref(false)
const saveMsg  = ref('')
const runError = ref('')

// Left panel
const prompt   = ref('')   // stored inside definition.prompt

// Center
const preview  = ref('')
const rowCount = ref<number | null>(null)
const runParams = ref<Record<string, string>>({})

// Right panel
const editName   = ref('')
const editDesc   = ref('')
const editType   = ref('table')
const editStatus = ref('draft')
const editDatasetId = ref<number | null>(null)
const editTemplate  = ref('default')

// Permission matrix
const perms = ref<Record<number, { view: boolean; edit: boolean; run: boolean }>>({})

// Bottom tabs
const bottomTab = ref<'history' | 'error' | 'api'>('history')
const historyEntries = ref<HistoryEntry[]>([])
const logEntries     = ref<LogEntry[]>([])
const bottomBusy     = ref(false)
const bottomOpen     = ref(true)

// JSON def editor (raw)
const defText = ref('')

// Create modal
const showCreate = ref(false)
const createForm = ref({ name: '', type: 'table', dataset_id: null as number | null })

// ── Computed ─────────────────────────────────────────────────────────────────
const boundDataset = computed<Dataset | null>(() =>
  allDatasets.value.find(d => d.id === (selected.value?.dataset?.id ?? editDatasetId.value)) ?? null
)
const shareLink = computed(() =>
  selected.value ? `${window.location.origin}/reports/${selected.value.id}` : ''
)
const apiEndpoint = computed(() =>
  selected.value ? `/api/reports/${selected.value.id}/run` : ''
)

// ── Load ─────────────────────────────────────────────────────────────────────
async function load() {
  loading.value = true
  try {
    reports.value = (await apiRequest<{ data: Report[] }>('/reports')).data
    if (!roles.value.length)
      roles.value = (await apiRequest<{ data: { id: number; name: string }[] }>('/roles/options')).data
    if (auth.can('datasources.view') && !allDatasets.value.length) {
      const src = (await apiRequest<{ data: { id: number }[] }>('/data-sources')).data
      const lists = await Promise.all(src.map(s => apiRequest<{ data: Dataset[] }>(`/data-sources/${s.id}/datasets`)))
      allDatasets.value = lists.flatMap(l => l.data)
    }
  } finally {
    loading.value = false
  }
}

async function openReport(r: Report) {
  preview.value = ''; rowCount.value = null; runError.value = ''; saveMsg.value = ''
  historyEntries.value = []; logEntries.value = []
  try {
    selected.value = (await apiRequest<{ data: Report }>(`/reports/${r.id}`)).data
    const def = selected.value.definition ?? {}
    defText.value  = JSON.stringify(def, null, 2)
    prompt.value   = (def.prompt as string) ?? ''
    editName.value   = selected.value.name
    editDesc.value   = selected.value.description ?? ''
    editType.value   = selected.value.type
    editStatus.value = selected.value.status
    editDatasetId.value = selected.value.dataset?.id ?? null
    editTemplate.value  = (def.template as string) ?? 'default'
    // seed role grants
    const map: Record<number, { view: boolean; edit: boolean; run: boolean }> = {}
    for (const g of selected.value.permissions ?? []) map[g.role_id] = { view: g.view, edit: g.edit, run: g.run }
    perms.value = map
    // seed run params from dataset
    runParams.value = {}
    for (const p of boundDataset.value?.parameters ?? []) runParams.value[p.name] = p.default ?? ''
    // load history
    await loadHistory()
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Failed to open report'
  }
}

// ── Save ─────────────────────────────────────────────────────────────────────
async function save() {
  if (!selected.value) return
  busy.value = true; saveMsg.value = ''; runError.value = ''
  try {
    let definition: Record<string, unknown>
    try { definition = JSON.parse(defText.value) } catch { throw new Error('Definition is not valid JSON') }
    definition.prompt   = prompt.value
    definition.template = editTemplate.value
    const permissions = Object.entries(perms.value)
      .filter(([, v]) => v.view || v.edit || v.run)
      .map(([role_id, v]) => ({ role_id: Number(role_id), ...v }))
    const r = await apiRequest<{ data: Report }>(`/reports/${selected.value.id}`, {
      method: 'PUT',
      body: JSON.stringify({
        name: editName.value, description: editDesc.value,
        type: editType.value, status: editStatus.value,
        dataset_id: editDatasetId.value,
        definition, permissions,
      }),
    })
    selected.value = r.data
    saveMsg.value = 'Saved'
    setTimeout(() => saveMsg.value = '', 2000)
    await load()
    await loadHistory()
  } catch (e) {
    runError.value = e instanceof Error ? e.message : 'Save failed'
    bottomTab.value = 'error'
  } finally {
    busy.value = false
  }
}

// ── Run ──────────────────────────────────────────────────────────────────────
async function run() {
  if (!selected.value) return
  busy.value = true; runError.value = ''; preview.value = ''
  try {
    const res = await apiRequest<{ data: { html: string; row_count: number } }>(`/reports/${selected.value.id}/run`, {
      method: 'POST', body: JSON.stringify({ params: runParams.value }),
    })
    preview.value  = res.data.html
    rowCount.value = res.data.row_count
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Run failed'
    bottomTab.value = 'error'
    bottomOpen.value = true
  } finally {
    busy.value = false
  }
}

// ── Create ───────────────────────────────────────────────────────────────────
async function create() {
  busy.value = true
  try {
    const r = await apiRequest<{ data: Report }>('/reports', {
      method: 'POST',
      body: JSON.stringify({ name: createForm.value.name, type: createForm.value.type, dataset_id: createForm.value.dataset_id }),
    })
    showCreate.value = false
    await load()
    await openReport(r.data)
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Create failed'
  } finally {
    busy.value = false
  }
}

// ── Delete ───────────────────────────────────────────────────────────────────
async function deleteReport() {
  if (!selected.value || !confirm(`Delete "${selected.value.name}"?`)) return
  try {
    await apiRequest(`/reports/${selected.value.id}`, { method: 'DELETE' })
    selected.value = null; preview.value = ''
    await load()
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Delete failed'
  }
}

// ── Bottom tabs ───────────────────────────────────────────────────────────────
async function loadHistory() {
  if (!selected.value) return
  bottomBusy.value = true
  try {
    historyEntries.value = (await apiRequest<{ data: HistoryEntry[] }>(`/reports/${selected.value.id}/history`)).data
  } finally {
    bottomBusy.value = false
  }
}
async function switchBottomTab(tab: 'history' | 'error' | 'api') {
  bottomTab.value = tab
  if (!selected.value) return
  if (tab === 'history' && !historyEntries.value.length) await loadHistory()
  if (tab === 'error' && !logEntries.value.length) {
    bottomBusy.value = true
    try {
      logEntries.value = (await apiRequest<{ data: LogEntry[] }>(`/reports/${selected.value.id}/logs`)).data
    } finally {
      bottomBusy.value = false
    }
  }
}

async function restoreHistory(entry: HistoryEntry) {
  if (!selected.value || !confirm('Restore this version?')) return
  defText.value = JSON.stringify(entry.snapshot?.definition ?? {}, null, 2)
  editName.value   = (entry.snapshot?.name as string) ?? editName.value
  editType.value   = (entry.snapshot?.type as string) ?? editType.value
  editStatus.value = (entry.snapshot?.status as string) ?? editStatus.value
}

// ── Permissions ───────────────────────────────────────────────────────────────
function grant(roleId: number) { return perms.value[roleId] ?? { view: false, edit: false, run: false } }
function toggleGrant(roleId: number, field: 'view' | 'edit' | 'run') {
  const cur = grant(roleId)
  perms.value = { ...perms.value, [roleId]: { ...cur, [field]: !cur[field] } }
}

// ── Clipboard ─────────────────────────────────────────────────────────────────
function copyLink() { navigator.clipboard?.writeText(shareLink.value) }

onMounted(load)
</script>

<template>
  <AdminLayout>
    <!-- strip default padding: negative margin trick -->
    <div class="-m-6 flex flex-col" style="height: calc(100vh - 4rem)">

      <!-- ── Top bar ─────────────────────────────────────────────────────── -->
      <div class="shrink-0 flex items-center justify-between px-6 py-3 border-b border-slate-100 bg-white">
        <div>
          <h1 class="text-base font-semibold text-slate-800 leading-tight">Reports</h1>
          <p class="text-xs text-slate-400">Define a report (JSON) bound to a dataset, then run to render.</p>
        </div>
        <div class="flex items-center gap-2">
          <!-- Version badge -->
          <span v-if="selected" class="text-xs border border-slate-200 rounded px-2 py-0.5 text-slate-500">
            v {{ selected.updated_at ? new Date(selected.updated_at).toLocaleDateString() : '—' }}
          </span>
          <!-- Team icon placeholder -->
          <button class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100" title="Team">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-5-5M9 20H4v-2a4 4 0 015-5m6-4a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
          </button>
          <!-- Copy link -->
          <button @click="copyLink" :disabled="!selected" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100 disabled:opacity-30" title="Copy link">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
          </button>
          <button v-if="canCreate" @click="showCreate = true; createForm = { name: '', type: 'table', dataset_id: null }"
            class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5">+ New Report</button>
        </div>
      </div>

      <!-- ── 3-column body ──────────────────────────────────────────────── -->
      <div class="flex-1 flex overflow-hidden min-h-0">

        <!-- LEFT PANEL -->
        <aside class="w-72 shrink-0 flex flex-col border-r border-slate-100 overflow-y-auto bg-white">
          <!-- Report list -->
          <div class="p-3 border-b border-slate-50">
            <div v-if="loading" class="text-xs text-slate-400 py-2">Loading…</div>
            <div v-else-if="!reports.length" class="text-xs text-slate-400 py-2">No reports yet.</div>
            <button v-for="r in reports" :key="r.id" @click="openReport(r)"
              class="w-full text-left rounded-lg px-3 py-2 mb-1 transition text-sm"
              :class="selected?.id === r.id ? 'bg-airr-50 border border-airr-200 text-airr-800' : 'hover:bg-slate-50 text-slate-700'">
              <div class="flex items-center justify-between gap-1">
                <span class="font-medium truncate">{{ r.name }}</span>
                <span class="text-[10px] rounded-full px-1.5 py-0.5 shrink-0" :class="STATUS_BADGE[r.status] ?? 'bg-slate-100 text-slate-500'">{{ r.status }}</span>
              </div>
              <div class="text-xs text-slate-400 truncate mt-0.5">{{ r.type }} · {{ r.dataset?.name ?? 'no dataset' }}</div>
            </button>
          </div>

          <template v-if="selected">
            <!-- Prompt -->
            <div class="p-3 border-b border-slate-50">
              <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-1.5">Prompt</label>
              <textarea v-model="prompt" rows="6" placeholder="Describe what this report should show…"
                class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-2 outline-none focus:ring-2 focus:ring-airr-300 resize-none"></textarea>
            </div>

            <!-- Permission -->
            <div class="p-3 border-b border-slate-50">
              <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-2">Permission</label>
              <div class="text-[10px] text-slate-400 mb-1.5">Access — which roles can use this report</div>
              <table class="w-full text-xs">
                <thead>
                  <tr class="text-[10px] text-slate-400">
                    <th class="text-left font-medium pb-1">Role</th>
                    <th class="text-center font-medium w-8 pb-1">View</th>
                    <th class="text-center font-medium w-8 pb-1">Run</th>
                    <th class="text-center font-medium w-8 pb-1">Edit</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="role in roles" :key="role.id" class="border-t border-slate-50">
                    <td class="py-1 text-slate-600 text-[11px]">{{ role.name }}</td>
                    <td class="text-center py-1"><input type="checkbox" :checked="grant(role.id).view" @change="toggleGrant(role.id, 'view')" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /></td>
                    <td class="text-center py-1"><input type="checkbox" :checked="grant(role.id).run" @change="toggleGrant(role.id, 'run')" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /></td>
                    <td class="text-center py-1"><input type="checkbox" :checked="grant(role.id).edit" @change="toggleGrant(role.id, 'edit')" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /></td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Data source -->
            <div class="p-3 border-b border-slate-50">
              <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-2">Data Source</label>
              <div v-if="boundDataset" class="rounded-lg border border-slate-100 bg-slate-50 p-2.5 text-xs">
                <div class="font-medium text-slate-700 mb-1">{{ boundDataset.name }}</div>
                <pre class="text-[10px] text-slate-500 overflow-x-auto max-h-28 whitespace-pre-wrap break-all">{{ JSON.stringify(selected?.definition ?? {}, null, 2) }}</pre>
              </div>
              <div v-else class="text-xs text-slate-400">No dataset bound.</div>
            </div>

            <!-- Parameters -->
            <div class="p-3">
              <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-2">Parameters</label>
              <div v-if="!boundDataset?.parameters?.length" class="text-xs text-slate-400">No parameters.</div>
              <div v-else class="space-y-1.5">
                <div v-for="p in boundDataset.parameters" :key="p.name">
                  <label class="block text-[10px] text-slate-500 mb-0.5">{{ p.label || p.name }}</label>
                  <input v-model="runParams[p.name]"
                    :type="p.type === 'number' ? 'number' : p.type === 'date' ? 'date' : 'text'"
                    class="w-full text-xs rounded border border-slate-200 px-2 py-1 outline-none focus:ring-2 focus:ring-airr-300" />
                </div>
              </div>
            </div>
          </template>
        </aside>

        <!-- CENTER PANEL (Preview) -->
        <main class="flex-1 flex flex-col min-w-0 overflow-hidden bg-slate-50">
          <div v-if="!selected" class="flex-1 flex items-center justify-center text-slate-400 text-sm">
            Select a report to preview and edit.
          </div>
          <template v-else>
            <!-- Preview toolbar -->
            <div class="shrink-0 flex items-center justify-between px-4 py-2 border-b border-slate-100 bg-white">
              <span class="text-xs font-medium text-slate-500">Preview<span v-if="rowCount !== null"> · {{ rowCount }} rows</span></span>
              <div class="flex items-center gap-2">
                <span v-if="saveMsg" class="text-xs text-emerald-600">{{ saveMsg }}</span>
                <button v-if="canEdit" @click="save" :disabled="busy"
                  class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5 disabled:opacity-50">
                  {{ busy ? 'Saving…' : 'Save' }}
                </button>
                <button v-if="canRun" @click="run" :disabled="busy"
                  class="text-xs font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5 disabled:opacity-50">
                  {{ busy ? '…' : 'Run' }}
                </button>
              </div>
            </div>
            <!-- Preview content -->
            <div class="flex-1 overflow-auto p-5">
              <div v-if="preview" class="bg-white rounded-xl border border-slate-100 p-5 shadow-sm">
                <div v-html="preview"></div>
              </div>
              <div v-else class="flex flex-col items-center justify-center h-full text-slate-300 gap-2">
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <span class="text-sm">Click Run to preview report</span>
              </div>
            </div>
          </template>
        </main>

        <!-- RIGHT PANEL -->
        <aside v-if="selected" class="w-64 shrink-0 flex flex-col border-l border-slate-100 overflow-y-auto bg-white">
          <!-- Setting -->
          <div class="p-3 border-b border-slate-50">
            <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-2">Setting</label>
            <div class="space-y-2">
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Name</label>
                <input v-model="editName" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
              </div>
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Type</label>
                <select v-model="editType" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300">
                  <option v-for="t in TYPES" :key="t" :value="t">{{ t }}</option>
                </select>
              </div>
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Status</label>
                <select v-model="editStatus" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300">
                  <option value="draft">Draft</option>
                  <option value="published">Published</option>
                </select>
              </div>
              <div v-if="canEdit">
                <button @click="deleteReport" class="text-xs text-rose-500 hover:underline">Delete report</button>
              </div>
            </div>
          </div>

          <!-- Property -->
          <div class="p-3 border-b border-slate-50">
            <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-2">Property</label>
            <div class="space-y-2">
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Description</label>
                <textarea v-model="editDesc" rows="3" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 resize-none"></textarea>
              </div>
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Dataset</label>
                <select v-model="editDatasetId" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300">
                  <option :value="null">— none —</option>
                  <option v-for="d in allDatasets" :key="d.id" :value="d.id">{{ d.name }}</option>
                </select>
              </div>
              <div class="text-[10px] text-slate-400">
                Creator: {{ selected.creator?.name ?? '—' }}<br/>
                Updated: {{ selected.updated_at ? new Date(selected.updated_at).toLocaleString() : '—' }}
              </div>
            </div>
          </div>

          <!-- Definition JSON (raw) -->
          <div class="p-3 border-b border-slate-50">
            <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-2">Definition JSON</label>
            <textarea v-model="defText" rows="10" spellcheck="false"
              class="w-full font-mono text-[10px] rounded-lg border border-slate-200 px-2 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 resize-none"></textarea>
          </div>

          <!-- Template -->
          <div class="p-3">
            <label class="block text-[11px] font-semibold text-slate-400 uppercase tracking-wide mb-2">Template</label>
            <div class="grid grid-cols-2 gap-1.5">
              <button v-for="t in TEMPLATES" :key="t" @click="editTemplate = t"
                class="text-xs rounded-lg border px-2 py-2 text-center capitalize transition"
                :class="editTemplate === t ? 'border-airr-400 bg-airr-50 text-airr-700 font-medium' : 'border-slate-200 text-slate-500 hover:border-slate-300'">
                {{ t }}
              </button>
            </div>
          </div>
        </aside>
      </div>

      <!-- ── Bottom panel (tabs) ────────────────────────────────────────── -->
      <div class="shrink-0 border-t border-slate-100 bg-white" :class="bottomOpen ? 'h-44' : 'h-9'">
        <!-- Tab bar -->
        <div class="flex items-center gap-0 border-b border-slate-100 px-4">
          <button v-for="tab in (['history', 'error', 'api'] as const)" :key="tab"
            @click="switchBottomTab(tab)"
            class="text-xs font-medium px-3 py-2 border-b-2 capitalize transition"
            :class="bottomTab === tab ? 'border-airr-500 text-airr-600' : 'border-transparent text-slate-400 hover:text-slate-600'">
            {{ tab === 'error' ? 'Error' : tab === 'api' ? 'API' : 'History' }}
          </button>
          <div class="flex-1"></div>
          <button @click="bottomOpen = !bottomOpen" class="text-xs text-slate-400 hover:text-slate-600 px-2 py-1.5">
            {{ bottomOpen ? '▾' : '▴' }}
          </button>
        </div>

        <!-- Tab content -->
        <div v-if="bottomOpen" class="overflow-y-auto" style="height: calc(100% - 2rem)">
          <!-- History tab -->
          <div v-if="bottomTab === 'history'" class="p-3">
            <div v-if="!selected" class="text-xs text-slate-400">Select a report first.</div>
            <div v-else-if="bottomBusy" class="text-xs text-slate-400">Loading…</div>
            <div v-else-if="!historyEntries.length" class="text-xs text-slate-400">No history yet — save the report to create the first entry.</div>
            <div v-else class="space-y-1.5">
              <div v-for="h in historyEntries" :key="h.id"
                class="flex items-center justify-between gap-3 text-xs border border-slate-100 rounded-lg px-3 py-2">
                <div class="flex items-center gap-2 min-w-0">
                  <span class="text-slate-400 shrink-0">{{ h.created_at }}</span>
                  <span class="font-medium text-slate-700">{{ h.action }}</span>
                  <span class="text-slate-400 truncate">by {{ h.changed_by_name }}</span>
                  <span v-if="h.snapshot?.name" class="text-slate-500 truncate">— {{ h.snapshot.name }}</span>
                </div>
                <div class="flex items-center gap-1.5 shrink-0">
                  <button @click="restoreHistory(h)" class="text-[11px] text-airr-600 hover:underline">Restore</button>
                </div>
              </div>
            </div>
          </div>

          <!-- Error tab -->
          <div v-else-if="bottomTab === 'error'" class="p-3">
            <div v-if="runError" class="text-xs text-rose-700 bg-rose-50 rounded-lg px-3 py-2">{{ runError }}</div>
            <div v-else-if="bottomBusy" class="text-xs text-slate-400">Loading…</div>
            <div v-else-if="!logEntries.length" class="text-xs text-slate-400">No errors logged.</div>
            <div v-else class="space-y-1.5">
              <div v-for="l in logEntries.filter(l => l.event?.includes('error') || l.event?.includes('fail'))" :key="l.id"
                class="text-xs border border-rose-100 rounded-lg px-3 py-2 bg-rose-50">
                <span class="text-slate-400 mr-2">{{ l.created_at }}</span>
                <span class="font-medium text-rose-700">{{ l.event }}</span>
                <span class="text-slate-500 ml-2">by {{ l.user_name }}</span>
              </div>
              <div v-if="!logEntries.filter(l => l.event?.includes('error') || l.event?.includes('fail')).length"
                class="text-xs text-slate-400">No errors found in access log.</div>
            </div>
          </div>

          <!-- API tab -->
          <div v-else-if="bottomTab === 'api'" class="p-4 space-y-3">
            <div v-if="!selected" class="text-xs text-slate-400">Select a report first.</div>
            <template v-else>
              <div>
                <div class="text-[11px] font-medium text-slate-500 mb-1">Endpoint</div>
                <div class="flex items-center gap-2">
                  <span class="text-xs bg-emerald-100 text-emerald-700 rounded px-1.5 py-0.5 font-mono">POST</span>
                  <code class="text-xs font-mono text-slate-700 bg-slate-50 rounded px-2 py-1 flex-1">{{ apiEndpoint }}</code>
                  <button @click="navigator.clipboard?.writeText(apiEndpoint)" class="text-slate-400 hover:text-slate-600 text-xs">copy</button>
                </div>
              </div>
              <div>
                <div class="text-[11px] font-medium text-slate-500 mb-1">Authentication</div>
                <code class="text-xs font-mono text-slate-600 bg-slate-50 rounded px-2 py-1 block">Authorization: Bearer &lt;token&gt;</code>
              </div>
              <div>
                <div class="text-[11px] font-medium text-slate-500 mb-1">Request body</div>
                <pre class="text-[10px] font-mono text-slate-600 bg-slate-50 rounded px-2 py-1.5">{"params": { "param_name": "value" }}</pre>
              </div>
              <div>
                <div class="text-[11px] font-medium text-slate-500 mb-1">Status</div>
                <span class="text-xs rounded-full px-2 py-0.5" :class="STATUS_BADGE[selected.status] ?? 'bg-slate-100 text-slate-500'">{{ selected.status }}</span>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Create modal ───────────────────────────────────────────────────── -->
    <div v-if="showCreate" class="fixed inset-0 bg-black/30 flex items-center justify-center p-4 z-50" @click.self="showCreate = false">
      <div class="bg-white rounded-xl p-6 w-full max-w-md space-y-4">
        <h2 class="font-semibold text-slate-800">New Report</h2>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Name</label>
          <input v-model="createForm.name" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Type</label>
          <select v-model="createForm.type" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
            <option v-for="t in TYPES" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Dataset <span class="text-slate-400 font-normal">· optional</span></label>
          <select v-model="createForm.dataset_id" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
            <option :value="null">— none —</option>
            <option v-for="d in allDatasets" :key="d.id" :value="d.id">{{ d.name }}</option>
          </select>
        </div>
        <div class="flex justify-end gap-2 pt-1">
          <button @click="showCreate = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button @click="create" :disabled="busy || !createForm.name" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
            {{ busy ? 'Creating…' : 'Create' }}
          </button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
