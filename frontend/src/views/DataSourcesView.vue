<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, ApiException, uploadFile } from '../api/client'
import { useAuthStore } from '../stores/auth'
import DatasetPanel from '../components/datasources/DatasetPanel.vue'

type Project = { id: number; code: string; name: string }
type DataSource = {
  id: number
  name: string
  type: string
  is_database: boolean
  is_file: boolean
  status: string
  deleted_at: string | null
  project: Project | null
  config_summary: Record<string, string>
  datasets_count: number
}
type LogEntry = {
  id: number
  event?: string
  action?: string
  user_name?: string
  changed_by_name?: string
  properties?: Record<string, unknown>
  snapshot?: Record<string, unknown>
  created_at: string
}

const auth = useAuthStore()
const canManage = auth.can('datasources.manage')

const sources = ref<DataSource[]>([])
const projects = ref<Project[]>([])
const selected = ref<DataSource | null>(null)
const loading = ref(true)
const error = ref('')
const busyId = ref<number | null>(null)
const testMsg = ref<{ id: number; ok: boolean; message: string } | null>(null)
const schema = ref<{ id: number; tables: { table: string; columns: { name: string; type: string }[] }[] } | null>(null)

// Search
const search = ref('')
const activeSources = computed(() => sources.value.filter(d => !d.deleted_at && d.name.toLowerCase().includes(search.value.toLowerCase())))
const deletedSources = computed(() => sources.value.filter(d => !!d.deleted_at))

const TYPES = [
  { value: 'postgres', label: 'PostgreSQL', db: true },
  { value: 'mysql', label: 'MySQL', db: true },
  { value: 'oracle', label: 'Oracle', db: true },
  { value: 'rest_api', label: 'REST API', db: false },
  { value: 'graphql', label: 'GraphQL', db: false },
  { value: 'csv', label: 'CSV file', db: false, file: true },
  { value: 'json', label: 'JSON file', db: false, file: true },
  { value: 'excel', label: 'Excel file', db: false, file: true },
]
const isOracle = computed(() => form.value.type === 'oracle')
const isFileType = computed(() => TYPES.find((t) => t.value === form.value.type)?.file ?? false)
const isJsonType = computed(() => form.value.type === 'json')
const typeLabel = (v: string) => TYPES.find((t) => t.value === v)?.label ?? v
const statusBadge: Record<string, string> = {
  ok: 'bg-emerald-100 text-emerald-700', error: 'bg-rose-100 text-rose-700', unknown: 'bg-slate-100 text-slate-500',
}

const showForm = ref(false)
const editing = ref<DataSource | null>(null)
const jsonContent = ref('')
const form = ref({
  project_id: null as number | null,
  name: '', type: 'postgres',
  config: {
    host: '127.0.0.1', port: 5432, database: '', service_name: '', username: '', password: '',
    base_url: '', auth_type: 'none', token: '', header_name: '',
  } as Record<string, unknown>,
})
const isDbType = computed(() => TYPES.find((t) => t.value === form.value.type)?.db ?? true)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [s, p] = await Promise.all([
      apiRequest<{ data: DataSource[] }>('/data-sources?with_trashed=1'),
      auth.can('projects.view') ? apiRequest<{ data: Project[] }>('/projects') : Promise.resolve({ data: [] }),
    ])
    sources.value = s.data
    projects.value = p.data
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load data sources'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editing.value = null
  jsonContent.value = ''
  form.value = {
    project_id: null, name: '', type: 'postgres',
    config: { host: '127.0.0.1', port: 5432, database: '', service_name: '', username: '', password: '', base_url: '', auth_type: 'none', token: '', header_name: '' },
  }
  showForm.value = true
}
function openEdit(d: DataSource) {
  editing.value = d
  jsonContent.value = ''
  form.value = {
    project_id: d.project?.id ?? null, name: d.name, type: d.type,
    config: { host: '127.0.0.1', port: 5432, database: '', service_name: '', username: '', password: '', base_url: '', auth_type: 'none', token: '', header_name: '', ...d.config_summary },
  }
  showForm.value = true
}

function buildConfig() {
  const c = form.value.config
  if (!isDbType.value) {
    return { base_url: c.base_url, auth_type: c.auth_type, token: c.token, header_name: c.header_name }
  }
  const db: Record<string, unknown> = { host: c.host, port: c.port, username: c.username, password: c.password }
  if (form.value.type === 'oracle') db.service_name = c.service_name
  else db.database = c.database
  return db
}

async function save() {
  busyId.value = -1
  error.value = ''
  try {
    const body: Record<string, unknown> = { project_id: form.value.project_id, name: form.value.name, type: form.value.type }
    if (!isFileType.value && (!editing.value || form.value.config.password || form.value.config.token)) {
      body.config = buildConfig()
    }
    const path = editing.value ? `/data-sources/${editing.value.id}` : '/data-sources'
    const res = await apiRequest<{ data: DataSource }>(path, { method: editing.value ? 'PUT' : 'POST', body: JSON.stringify(body) })

    // For JSON type with inline content, upload immediately after save.
    if (isJsonType.value && jsonContent.value.trim()) {
      const savedId = res.data?.id ?? editing.value?.id
      if (savedId) {
        const fd = new FormData()
        fd.append('json_content', jsonContent.value)
        await uploadFile(`/data-sources/${savedId}/upload`, fd)
      }
    }

    showForm.value = false
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    busyId.value = null
  }
}

const testingAll = ref(false)
async function testAll() {
  testingAll.value = true
  error.value = ''
  testMsg.value = null
  try {
    for (const d of activeSources.value) {
      try {
        await apiRequest(`/data-sources/${d.id}/test`, { method: 'POST' })
      } catch { /* keep going */ }
    }
    await load()
  } finally {
    testingAll.value = false
  }
}

async function test(d: DataSource) {
  busyId.value = d.id
  testMsg.value = null
  try {
    const res = await apiRequest<{ data: { ok: boolean; message: string } }>(`/data-sources/${d.id}/test`, { method: 'POST' })
    testMsg.value = { id: d.id, ...res.data }
    await load()
  } catch (e) {
    testMsg.value = { id: d.id, ok: false, message: e instanceof ApiException ? e.error.message : 'Test failed' }
  } finally {
    busyId.value = null
  }
}

async function introspect(d: DataSource) {
  busyId.value = d.id
  error.value = ''
  try {
    const res = await apiRequest<{ data: { tables: { table: string; columns: { name: string; type: string }[] }[] } }>(`/data-sources/${d.id}/introspect`, { method: 'POST' })
    schema.value = { id: d.id, tables: res.data.tables }
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Introspect failed'
  } finally {
    busyId.value = null
  }
}

// File upload modal
const uploadTarget = ref<DataSource | null>(null)
const uploadOpts = ref({ has_header: true, delimiter: ',' })
const uploadJsonContent = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

function openUpload(d: DataSource) {
  uploadTarget.value = d
  uploadOpts.value = { has_header: true, delimiter: ',' }
  uploadJsonContent.value = ''
}
async function onFilePicked(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file || !uploadTarget.value) return
  busyId.value = uploadTarget.value.id
  error.value = ''
  try {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('has_header', uploadOpts.value.has_header ? '1' : '0')
    if (uploadTarget.value.type === 'csv') fd.append('delimiter', uploadOpts.value.delimiter)
    await uploadFile(`/data-sources/${uploadTarget.value.id}/upload`, fd)
    uploadTarget.value = null
    await load()
  } catch (err) {
    error.value = err instanceof ApiException ? err.error.message : 'Upload failed'
  } finally {
    busyId.value = null
    if (fileInput.value) fileInput.value.value = ''
  }
}
async function uploadJsonText() {
  if (!uploadTarget.value || !uploadJsonContent.value.trim()) return
  busyId.value = uploadTarget.value.id
  error.value = ''
  try {
    const fd = new FormData()
    fd.append('json_content', uploadJsonContent.value)
    await uploadFile(`/data-sources/${uploadTarget.value.id}/upload`, fd)
    uploadTarget.value = null
    await load()
  } catch (err) {
    error.value = err instanceof ApiException ? err.error.message : 'Upload failed'
  } finally {
    busyId.value = null
  }
}

async function remove(d: DataSource) {
  if (!confirm(`Delete data source "${d.name}" and its datasets?`)) return
  busyId.value = d.id
  try {
    await apiRequest(`/data-sources/${d.id}`, { method: 'DELETE' })
    if (selected.value?.id === d.id) selected.value = null
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Delete failed'
  } finally {
    busyId.value = null
  }
}

async function restore(d: DataSource) {
  busyId.value = d.id
  try {
    await apiRequest(`/data-sources/${d.id}/restore`, { method: 'POST' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Restore failed'
  } finally {
    busyId.value = null
  }
}

// Log modal
const logTarget = ref<DataSource | null>(null)
const logTab = ref<'access' | 'history'>('history')
const logEntries = ref<LogEntry[]>([])
const logBusy = ref(false)

async function openLog(d: DataSource) {
  logTarget.value = d
  logTab.value = 'history'
  await fetchLog(d, 'history')
}
async function fetchLog(d: DataSource, tab: 'access' | 'history') {
  logTab.value = tab
  logBusy.value = true
  logEntries.value = []
  try {
    const path = tab === 'history' ? `/data-sources/${d.id}/history` : `/data-sources/${d.id}/logs`
    const res = await apiRequest<{ data: LogEntry[] }>(path)
    logEntries.value = res.data
  } finally {
    logBusy.value = false
  }
}

onMounted(load)
</script>

<template>
  <AdminLayout>
    <div class="space-y-4">
      <div class="flex items-center justify-between gap-3">
        <div class="relative flex-1 max-w-xs">
          <input v-model="search" placeholder="Search sources…"
            class="w-full pl-8 pr-3 py-1.5 text-sm rounded-lg border border-slate-200 outline-none focus:ring-2 focus:ring-airr-300" />
          <span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-slate-400 text-xs">🔍</span>
        </div>
        <div v-if="canManage" class="flex items-center gap-2 shrink-0">
          <button @click="testAll" :disabled="testingAll || !activeSources.length"
            class="text-sm font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5 disabled:opacity-50">
            {{ testingAll ? 'Testing…' : 'Test all' }}
          </button>
          <button @click="openCreate" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5">+ New Data Source</button>
        </div>
      </div>

      <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>

      <!-- Active sources -->
      <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>
        <div v-else-if="!activeSources.length && !search" class="text-slate-400 text-sm">No data sources yet.</div>
        <div v-else-if="!activeSources.length" class="text-slate-400 text-sm">No results for "{{ search }}".</div>
        <div v-for="d in activeSources" :key="d.id"
          class="bg-white rounded-xl border p-4 flex flex-col gap-2 cursor-pointer transition"
          :class="selected?.id === d.id ? 'border-airr-400 ring-1 ring-airr-200' : 'border-slate-100 hover:border-slate-200'"
          @click="selected = d">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <div class="font-semibold text-slate-700 truncate">{{ d.name }}</div>
              <div class="text-xs text-slate-400">{{ typeLabel(d.type) }} · {{ d.project?.code ?? 'no project' }}</div>
            </div>
            <span class="text-xs font-medium rounded-full px-2 py-0.5 shrink-0" :class="statusBadge[d.status]">{{ d.status }}</span>
          </div>
          <div class="text-xs text-slate-400 truncate">
            <template v-if="d.is_file">{{ d.config_summary.original_name ?? 'no file' }}<span v-if="d.config_summary.row_count"> · {{ d.config_summary.row_count }} rows</span></template>
            <template v-else-if="d.is_database">{{ d.config_summary.host }}/{{ d.config_summary.database ?? d.config_summary.service_name }}</template>
            <template v-else>{{ d.config_summary.base_url }}</template>
          </div>
          <div class="text-xs text-slate-500">{{ d.datasets_count }} datasets</div>

          <p v-if="testMsg && testMsg.id === d.id" class="text-xs rounded px-2 py-1" :class="testMsg.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'">
            {{ testMsg.message }}
          </p>

          <div v-if="canManage" class="flex flex-wrap gap-3 pt-1 mt-auto" @click.stop>
            <button v-if="d.is_file" @click="openUpload(d)" :disabled="busyId === d.id" class="text-xs text-airr-600 hover:underline font-medium">Upload</button>
            <button @click="test(d)" :disabled="busyId === d.id" class="text-xs text-airr-600 hover:underline">Test</button>
            <button v-if="d.is_database" @click="introspect(d)" :disabled="busyId === d.id" class="text-xs text-airr-600 hover:underline">Introspect</button>
            <button @click="openEdit(d)" class="text-xs text-slate-500 hover:underline">Edit</button>
            <button @click="openLog(d)" class="text-xs text-slate-500 hover:underline">Log</button>
            <button @click="remove(d)" :disabled="busyId === d.id" class="text-xs text-rose-600 hover:underline">Delete</button>
          </div>
        </div>
      </div>

      <!-- Deleted sources -->
      <div v-if="deletedSources.length" class="mt-2">
        <p class="text-xs font-medium text-slate-400 mb-2">Deleted sources</p>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          <div v-for="d in deletedSources" :key="d.id"
            class="bg-slate-50 rounded-xl border border-dashed border-slate-200 p-4 flex flex-col gap-2 opacity-60">
            <div class="flex items-start justify-between gap-2">
              <div class="min-w-0">
                <div class="font-semibold text-slate-500 truncate line-through">{{ d.name }}</div>
                <div class="text-xs text-slate-400">{{ typeLabel(d.type) }} · {{ d.project?.code ?? 'no project' }}</div>
              </div>
              <span class="text-xs font-medium rounded-full px-2 py-0.5 shrink-0 bg-slate-100 text-slate-400">deleted</span>
            </div>
            <div v-if="canManage" class="flex gap-3 pt-1 mt-auto">
              <button @click="restore(d)" :disabled="busyId === d.id" class="text-xs text-emerald-600 hover:underline font-medium">Undo delete</button>
            </div>
          </div>
        </div>
      </div>

      <!-- Introspected schema -->
      <div v-if="schema && selected && schema.id === selected.id" class="bg-white rounded-xl border border-slate-100 p-4">
        <h3 class="font-semibold text-slate-700 mb-2 text-sm">Schema · {{ selected.name }} ({{ schema.tables.length }} tables)</h3>
        <div class="grid gap-2 md:grid-cols-3 max-h-64 overflow-y-auto">
          <div v-for="t in schema.tables" :key="t.table" class="text-xs border border-slate-100 rounded-lg p-2">
            <div class="font-mono font-medium text-slate-600">{{ t.table }}</div>
            <div v-for="c in t.columns" :key="c.name" class="text-slate-400 flex justify-between">
              <span>{{ c.name }}</span><span class="text-slate-300">{{ c.type }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Datasets for the selected source -->
      <DatasetPanel v-if="selected && !selected.deleted_at" :source="selected" :can-manage="canManage" @changed="load" />
    </div>

    <!-- File upload modal -->
    <div v-if="uploadTarget" class="fixed inset-0 bg-slate-900/30 flex items-center justify-center z-50 px-4" @click.self="uploadTarget = null">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-6 space-y-4">
        <h3 class="font-bold text-lg">Upload file · {{ uploadTarget.name }}</h3>

        <template v-if="uploadTarget.type === 'json'">
          <p class="text-xs text-slate-400">Paste JSON or choose a file (max 10 MB).</p>
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Paste JSON</label>
            <textarea v-model="uploadJsonContent" rows="8" placeholder='[{"col": "value"}]'
              class="w-full rounded-lg border border-slate-200 px-3 py-2 font-mono text-xs outline-none focus:ring-2 focus:ring-airr-300"></textarea>
          </div>
          <div class="flex items-center gap-2">
            <div class="flex-1 border-t border-slate-200"></div><span class="text-xs text-slate-400">or</span><div class="flex-1 border-t border-slate-200"></div>
          </div>
          <div class="flex justify-between items-center gap-2">
            <button @click="fileInput?.click()" :disabled="busyId === uploadTarget.id" class="text-sm text-slate-500 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-2">Choose file…</button>
            <input ref="fileInput" type="file" class="hidden" accept=".json" @change="onFilePicked" />
            <div class="flex gap-2">
              <button @click="uploadTarget = null" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
              <button @click="uploadJsonText" :disabled="busyId === uploadTarget.id || !uploadJsonContent.trim()"
                class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
                {{ busyId === uploadTarget.id ? 'Saving…' : 'Save JSON' }}
              </button>
            </div>
          </div>
        </template>

        <template v-else>
          <p class="text-xs text-slate-400">Accepted: {{ uploadTarget.type === 'csv' ? 'CSV' : 'XLSX/XLS' }} (max 10 MB). Stored locally.</p>
          <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" v-model="uploadOpts.has_header" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" />
            First row is a header
          </label>
          <div v-if="uploadTarget.type === 'csv'">
            <label class="block text-sm font-medium text-slate-600 mb-1">Delimiter</label>
            <input v-model="uploadOpts.delimiter" maxlength="2" class="w-20 rounded-lg border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-airr-300" />
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="uploadTarget = null" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
            <button @click="fileInput?.click()" :disabled="busyId === uploadTarget.id" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
              {{ busyId === uploadTarget.id ? 'Uploading…' : 'Choose file…' }}
            </button>
            <input ref="fileInput" type="file" class="hidden" accept=".csv,.txt,.xlsx,.xls" @change="onFilePicked" />
          </div>
        </template>
      </div>
    </div>

    <!-- Connection modal -->
    <div v-if="showForm" class="fixed inset-0 bg-slate-900/30 flex items-center justify-center z-50 px-4 py-8 overflow-y-auto" @click.self="showForm = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-6 space-y-4 my-auto">
        <h3 class="font-bold text-lg">{{ editing ? 'Edit Data Source' : 'New Data Source' }}</h3>

        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Name</label>
            <input v-model="form.name" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Type</label>
            <select v-model="form.type" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
              <option v-for="t in TYPES" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Project</label>
          <select v-model="form.project_id" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
            <option :value="null">— None —</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option>
          </select>
        </div>

        <!-- DB config -->
        <div v-if="isDbType" class="grid grid-cols-2 gap-3">
          <div><label class="block text-sm font-medium text-slate-600 mb-1">Host</label><input v-model="form.config.host" class="w-full rounded-lg border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-airr-300" /></div>
          <div><label class="block text-sm font-medium text-slate-600 mb-1">Port</label><input v-model.number="form.config.port" type="number" class="w-full rounded-lg border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-airr-300" /></div>
          <div v-if="!isOracle"><label class="block text-sm font-medium text-slate-600 mb-1">Database</label><input v-model="form.config.database" class="w-full rounded-lg border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-airr-300" /></div>
          <div v-else><label class="block text-sm font-medium text-slate-600 mb-1">Service name</label><input v-model="form.config.service_name" placeholder="ORCLPDB1" class="w-full rounded-lg border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-airr-300" /></div>
          <div><label class="block text-sm font-medium text-slate-600 mb-1">Username</label><input v-model="form.config.username" class="w-full rounded-lg border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-airr-300" /></div>
          <div class="col-span-2"><label class="block text-sm font-medium text-slate-600 mb-1">Password {{ editing ? '(leave blank to keep)' : '' }}</label><input v-model="form.config.password" type="password" autocomplete="new-password" class="w-full rounded-lg border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-airr-300" /></div>
        </div>

        <!-- JSON type: textarea for inline entry -->
        <div v-else-if="isJsonType" class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">JSON Content <span class="text-slate-400 font-normal">(optional — paste now or upload later)</span></label>
            <textarea v-model="jsonContent" rows="8" placeholder='[{"column": "value"}, ...]'
              class="w-full rounded-lg border border-slate-200 px-3 py-2 font-mono text-xs outline-none focus:ring-2 focus:ring-airr-300"></textarea>
          </div>
          <p class="text-xs text-slate-400">You can also upload a .json file after creating the source using the Upload button on its card.</p>
        </div>

        <!-- Other file types -->
        <div v-else-if="isFileType" class="text-sm text-slate-500 bg-slate-50 rounded-lg px-3 py-2">
          ℹ️ Create the source, then use <span class="font-medium">Upload</span> on its card to attach the {{ form.type.toUpperCase() }} file.
        </div>

        <!-- API config -->
        <div v-else class="space-y-3">
          <div><label class="block text-sm font-medium text-slate-600 mb-1">Base URL</label><input v-model="form.config.base_url" placeholder="https://api.example.gov.my" class="w-full rounded-lg border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-airr-300" /></div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-slate-600 mb-1">Auth</label>
              <select v-model="form.config.auth_type" class="w-full rounded-lg border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-airr-300">
                <option value="none">None</option><option value="bearer">Bearer token</option><option value="header">Custom header</option>
              </select>
            </div>
            <div v-if="form.config.auth_type === 'header'"><label class="block text-sm font-medium text-slate-600 mb-1">Header name</label><input v-model="form.config.header_name" placeholder="X-API-Key" class="w-full rounded-lg border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-airr-300" /></div>
          </div>
          <div v-if="form.config.auth_type !== 'none'"><label class="block text-sm font-medium text-slate-600 mb-1">Token {{ editing ? '(leave blank to keep)' : '' }}</label><input v-model="form.config.token" type="password" autocomplete="new-password" class="w-full rounded-lg border border-slate-200 px-3 py-2 outline-none focus:ring-2 focus:ring-airr-300" /></div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button @click="showForm = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button @click="save" :disabled="busyId === -1 || !form.name" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
            {{ busyId === -1 ? 'Saving…' : editing ? 'Save' : 'Create' }}
          </button>
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
          <button @click="fetchLog(logTarget, 'history')" class="text-xs font-medium px-3 py-1.5 rounded-lg" :class="logTab === 'history' ? 'bg-airr-500 text-white' : 'text-slate-500 hover:bg-slate-50'">Change History</button>
          <button @click="fetchLog(logTarget, 'access')" class="text-xs font-medium px-3 py-1.5 rounded-lg" :class="logTab === 'access' ? 'bg-airr-500 text-white' : 'text-slate-500 hover:bg-slate-50'">Access Log</button>
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
  </AdminLayout>
</template>
