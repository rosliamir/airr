<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { apiRequest, ApiException } from '../../api/client'

type Field = { name: string; label?: string; type?: string }
type Param = { name: string; label?: string; type?: string; default?: string; required?: boolean }
type Dataset = {
  id: number
  name: string
  description: string | null
  query: string | null
  method: string | null
  body: string | null
  fields: Field[]
  parameters: Param[]
}
type Source = { id: number; name: string; type: string; is_database: boolean; is_file?: boolean }

const props = defineProps<{ source: Source; canManage: boolean }>()
const emit = defineEmits<{ changed: [] }>()

const datasets = ref<Dataset[]>([])
const loading = ref(true)
const error = ref('')
const busy = ref(false)

const showEditor = ref(false)
const editing = ref<Dataset | null>(null)
const form = ref<Dataset>(blank())

// Log state
type LogEntry = { id: number; event?: string; action?: string; user_name?: string; changed_by_name?: string; properties?: Record<string, unknown>; snapshot?: Record<string, unknown>; created_at: string }
const logTarget = ref<Dataset | null>(null)
const logTab = ref<'access' | 'history'>('history')
const logEntries = ref<LogEntry[]>([])
const logBusy = ref(false)

async function openLog(d: Dataset) {
  logTarget.value = d
  logTab.value = 'history'
  await fetchLogs(d, 'history')
}
async function fetchLogs(d: Dataset, tab: 'access' | 'history') {
  logTab.value = tab
  logBusy.value = true
  logEntries.value = []
  try {
    const path = tab === 'history' ? `/datasets/${d.id}/history` : `/datasets/${d.id}/logs`
    const res = await apiRequest<{ data: LogEntry[] }>(path)
    logEntries.value = res.data
  } finally {
    logBusy.value = false
  }
}

// Preview state
const previewFor = ref<number | null>(null)
const previewParams = ref<Record<string, string>>({})
const previewResult = ref<{
  columns: string[]; rows: Record<string, unknown>[]; count: number
  total: number | null; page: number; per_page: number; total_pages: number | null
} | null>(null)
const previewError = ref('')
const previewPage = ref(1)

const isDb = computed(() => props.source.is_database)
const isFile = computed(() => props.source.is_file ?? false)

// Literal double-brace hints (kept out of the template so Vue doesn't parse them).
const PARAM_HINT = '{{param}}'
const PATH_HINT = '/v1/data?state={{state}}'
const BODY_HINT = '{"state":"{{state}}"}'

function blank(): Dataset {
  return { id: 0, name: '', description: '', query: '', method: 'GET', body: '', fields: [], parameters: [] }
}

async function load() {
  loading.value = true
  try {
    datasets.value = (await apiRequest<{ data: Dataset[] }>(`/data-sources/${props.source.id}/datasets`)).data
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load datasets'
  } finally {
    loading.value = false
  }
}
watch(() => props.source.id, load, { immediate: true })

function openCreate() {
  editing.value = null
  form.value = blank()
  showEditor.value = true
}
function openEdit(d: Dataset) {
  editing.value = d
  form.value = JSON.parse(JSON.stringify({ ...blank(), ...d }))
  showEditor.value = true
}
function addField() { form.value.fields.push({ name: '', label: '', type: 'string' }) }
function addParam() { form.value.parameters.push({ name: '', label: '', type: 'text', default: '', required: false }) }

async function save() {
  busy.value = true
  error.value = ''
  try {
    const path = editing.value ? `/datasets/${editing.value.id}` : `/data-sources/${props.source.id}/datasets`
    await apiRequest(path, { method: editing.value ? 'PUT' : 'POST', body: JSON.stringify(form.value) })
    showEditor.value = false
    await load()
    emit('changed')
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    busy.value = false
  }
}

async function remove(d: Dataset) {
  if (!confirm(`Delete dataset "${d.name}"?`)) return
  await apiRequest(`/datasets/${d.id}`, { method: 'DELETE' })
  await load()
  emit('changed')
}

function openPreview(d: Dataset) {
  previewFor.value = d.id
  previewResult.value = null
  previewError.value = ''
  previewParams.value = {}
  previewPage.value = 1
  for (const p of d.parameters ?? []) previewParams.value[p.name] = p.default ?? ''
}
async function runPreview(d: Dataset, page = 1) {
  busy.value = true
  previewError.value = ''
  previewPage.value = page
  try {
    const res = await apiRequest<{ data: typeof previewResult.value }>(
      `/datasets/${d.id}/preview`,
      { method: 'POST', body: JSON.stringify({ params: previewParams.value, page, per_page: 20 }) },
    )
    previewResult.value = res.data
  } catch (e) {
    previewError.value = e instanceof ApiException ? e.error.message : 'Preview failed'
    previewResult.value = null
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="bg-white rounded-xl border border-slate-100 p-4 space-y-3">
    <div class="flex items-center justify-between">
      <h3 class="font-semibold text-slate-700 text-sm">Datasets · {{ source.name }}</h3>
      <button v-if="canManage" @click="openCreate" class="text-xs font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-2.5 py-1.5">+ New Dataset</button>
    </div>

    <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>
    <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>
    <div v-else-if="!datasets.length" class="text-slate-400 text-sm">No datasets yet.</div>

    <div v-for="d in datasets" :key="d.id" class="border border-slate-100 rounded-lg p-3">
      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
          <div class="font-medium text-slate-700">{{ d.name }}</div>
          <div v-if="d.description" class="text-xs text-slate-400">{{ d.description }}</div>
          <div class="text-xs text-slate-400 mt-1">
            {{ (d.parameters?.length ?? 0) }} params · {{ (d.fields?.length ?? 0) }} fields
          </div>
        </div>
        <div class="flex gap-3 shrink-0">
          <button @click="openPreview(d)" class="text-xs text-airr-600 hover:underline">Preview</button>
          <button v-if="canManage" @click="openEdit(d)" class="text-xs text-slate-500 hover:underline">Edit</button>
          <button v-if="canManage" @click="openLog(d)" class="text-xs text-slate-500 hover:underline">Log</button>
          <button v-if="canManage" @click="remove(d)" class="text-xs text-rose-600 hover:underline">Delete</button>
        </div>
      </div>

      <!-- Inline preview -->
      <div v-if="previewFor === d.id" class="mt-3 border-t border-slate-100 pt-3 space-y-2">
        <div v-if="d.parameters?.length" class="flex flex-wrap gap-2">
          <div v-for="p in d.parameters" :key="p.name">
            <label class="block text-[11px] text-slate-500">{{ p.label || p.name }}</label>
            <input v-model="previewParams[p.name]" :type="p.type === 'number' ? 'number' : p.type === 'date' ? 'date' : 'text'"
              class="rounded border border-slate-200 px-2 py-1 text-sm outline-none focus:ring-2 focus:ring-airr-300" />
          </div>
        </div>
        <button @click="runPreview(d, 1)" :disabled="busy" class="text-xs font-medium text-white bg-airr-500 hover:bg-airr-600 rounded px-3 py-1.5 disabled:opacity-50">
          {{ busy ? 'Running…' : 'Run' }}
        </button>
        <p v-if="previewError" class="text-xs text-rose-700 bg-rose-50 rounded px-2 py-1 whitespace-pre-wrap">{{ previewError }}</p>
        <div v-if="previewResult" class="overflow-x-auto">
          <div class="text-[11px] text-slate-400 mb-1">
            {{ previewResult.count }} rows shown
            <template v-if="previewResult.total !== null"> · {{ previewResult.total }} total · page {{ previewResult.page }} of {{ previewResult.total_pages }}</template>
          </div>
          <table class="text-xs border border-slate-100 rounded">
            <thead class="bg-slate-50"><tr><th v-for="c in previewResult.columns" :key="c" class="px-2 py-1 text-left font-medium text-slate-500">{{ c }}</th></tr></thead>
            <tbody>
              <tr v-for="(row, i) in previewResult.rows" :key="i" class="border-t border-slate-50">
                <td v-for="c in previewResult.columns" :key="c" class="px-2 py-1 text-slate-600">{{ row[c] }}</td>
              </tr>
            </tbody>
          </table>

          <!-- Page navigation -->
          <div v-if="previewResult.total_pages && previewResult.total_pages > 1" class="flex items-center gap-1 mt-2 flex-wrap">
            <button @click="runPreview(d, previewPage - 1)" :disabled="busy || previewPage <= 1"
              class="text-xs px-2 py-1 rounded border border-slate-200 hover:bg-slate-50 disabled:opacity-40">‹ Prev</button>
            <button v-for="p in Math.min(previewResult.total_pages, 8)" :key="p" @click="runPreview(d, p)"
              :disabled="busy" class="text-xs w-7 h-7 rounded"
              :class="p === previewPage ? 'bg-airr-500 text-white' : 'border border-slate-200 hover:bg-slate-50 text-slate-600'">{{ p }}</button>
            <span v-if="previewResult.total_pages > 8" class="text-xs text-slate-400 px-1">… {{ previewResult.total_pages }}</span>
            <button @click="runPreview(d, previewPage + 1)" :disabled="busy || previewPage >= previewResult.total_pages"
              class="text-xs px-2 py-1 rounded border border-slate-200 hover:bg-slate-50 disabled:opacity-40">Next ›</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Log modal -->
    <div v-if="logTarget" class="fixed inset-0 bg-slate-900/30 flex items-center justify-center z-50 px-4 py-8 overflow-y-auto" @click.self="logTarget = null">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl p-6 space-y-4 my-auto">
        <div class="flex items-center justify-between">
          <h3 class="font-bold text-lg">Log · {{ logTarget.name }}</h3>
          <button @click="logTarget = null" class="text-slate-400 hover:text-slate-600 text-xl leading-none">×</button>
        </div>
        <div class="flex gap-2 border-b border-slate-100 pb-2">
          <button @click="fetchLogs(logTarget, 'history')" class="text-xs font-medium px-3 py-1.5 rounded-lg" :class="logTab === 'history' ? 'bg-airr-500 text-white' : 'text-slate-500 hover:bg-slate-50'">Change History</button>
          <button @click="fetchLogs(logTarget, 'access')" class="text-xs font-medium px-3 py-1.5 rounded-lg" :class="logTab === 'access' ? 'bg-airr-500 text-white' : 'text-slate-500 hover:bg-slate-50'">Access Log</button>
        </div>
        <div v-if="logBusy" class="text-slate-400 text-sm">Loading…</div>
        <div v-else-if="!logEntries.length" class="text-slate-400 text-sm">No entries yet.</div>
        <div v-else class="space-y-2 max-h-96 overflow-y-auto pr-1">
          <div v-for="e in logEntries" :key="e.id" class="border border-slate-100 rounded-lg p-3 text-xs">
            <div class="flex justify-between items-center mb-1">
              <span class="font-medium text-slate-700">{{ e.action ?? e.event }}</span>
              <span class="text-slate-400">{{ e.changed_by_name ?? e.user_name }} · {{ e.created_at }}</span>
            </div>
            <pre v-if="e.snapshot" class="text-[10px] text-slate-500 whitespace-pre-wrap break-all">{{ JSON.stringify(e.snapshot, null, 2) }}</pre>
            <pre v-else-if="e.properties && Object.keys(e.properties).length" class="text-[10px] text-slate-500 whitespace-pre-wrap break-all">{{ JSON.stringify(e.properties, null, 2) }}</pre>
          </div>
        </div>
      </div>
    </div>

    <!-- Editor modal -->
    <div v-if="showEditor" class="fixed inset-0 bg-slate-900/30 flex items-center justify-center z-50 px-4 py-8 overflow-y-auto" @click.self="showEditor = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl p-6 space-y-4 my-auto">
        <h3 class="font-bold text-lg">{{ editing ? 'Edit Dataset' : 'New Dataset' }}</h3>

        <div class="grid grid-cols-2 gap-3">
          <div><label class="block text-sm font-medium text-slate-600 mb-1">Name</label><input v-model="form.name" class="w-full rounded-lg border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-airr-300" /></div>
          <div><label class="block text-sm font-medium text-slate-600 mb-1">Description</label><input v-model="form.description" class="w-full rounded-lg border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-airr-300" /></div>
        </div>

        <!-- DB: SQL -->
        <div v-if="isDb">
          <label class="block text-sm font-medium text-slate-600 mb-1">SQL <span class="text-slate-400 font-normal">(use :param for runtime parameters; SELECT only)</span></label>
          <textarea v-model="form.query" rows="4" class="w-full rounded-lg border border-slate-200 px-3 py-2 font-mono text-sm outline-none focus:ring-2 focus:ring-airr-300" placeholder="select * from table where col = :param"></textarea>
        </div>
        <!-- File: no query — parameters filter rows by matching column name -->
        <div v-else-if="isFile" class="text-sm text-slate-500 bg-slate-50 rounded-lg px-3 py-2">
          ℹ️ This dataset reads the uploaded file. Add parameters whose <span class="font-medium">name matches a column</span> to filter rows by equality.
        </div>

        <!-- API: path + method + body -->
        <div v-else class="space-y-2">
          <div class="grid grid-cols-4 gap-2">
            <div><label class="block text-sm font-medium text-slate-600 mb-1">Method</label>
              <select v-model="form.method" class="w-full rounded-lg border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-airr-300"><option>GET</option><option>POST</option></select>
            </div>
            <div class="col-span-3"><label class="block text-sm font-medium text-slate-600 mb-1">Path <span class="text-slate-400 font-normal">(use {{ PARAM_HINT }})</span></label>
              <input v-model="form.query" :placeholder="PATH_HINT" class="w-full rounded-lg border border-slate-200 px-3 py-2 font-mono text-sm outline-none focus:ring-2 focus:ring-airr-300" />
            </div>
          </div>
          <div v-if="form.method === 'POST'"><label class="block text-sm font-medium text-slate-600 mb-1">Body</label>
            <textarea v-model="form.body" rows="3" class="w-full rounded-lg border border-slate-200 px-3 py-2 font-mono text-sm outline-none focus:ring-2 focus:ring-airr-300" :placeholder="BODY_HINT"></textarea>
          </div>
        </div>

        <!-- Fields -->
        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="text-sm font-medium text-slate-600">Output fields <span class="text-slate-400 font-normal">(optional)</span></label>
            <button @click="addField" class="text-xs text-airr-600 hover:underline">+ Add field</button>
          </div>
          <div v-for="(f, i) in form.fields" :key="i" class="grid grid-cols-12 gap-1.5 mb-1.5 items-center">
            <input v-model="f.name" placeholder="name" class="col-span-4 rounded border border-slate-200 px-2 py-1 text-sm outline-none focus:ring-2 focus:ring-airr-300" />
            <input v-model="f.label" placeholder="label" class="col-span-4 rounded border border-slate-200 px-2 py-1 text-sm outline-none focus:ring-2 focus:ring-airr-300" />
            <input v-model="f.type" placeholder="type" class="col-span-3 rounded border border-slate-200 px-2 py-1 text-sm outline-none focus:ring-2 focus:ring-airr-300" />
            <button @click="form.fields.splice(i, 1)" class="col-span-1 text-rose-500 text-xs">✕</button>
          </div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button @click="showEditor = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button @click="save" :disabled="busy || !form.name" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
            {{ busy ? 'Saving…' : 'Save' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
