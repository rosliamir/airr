<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import RichEditor from '../components/RichEditor.vue'
import ConstantsPanel from '../components/ConstantsPanel.vue'
import { apiRequest, ApiException } from '../api/client'
import { useAuthStore } from '../stores/auth'

type Group = { level: number; header: string | null; footer: string | null }
type DataSourceOpt = { id: number; name: string }
type DatasetOpt = { id: number; data_source_id: number; name: string }
type Template = {
  id: number
  name: string
  description: string | null
  scope: 'global' | 'project'
  project: { id: number; code: string; name: string } | null
  data_source_id?: number | null
  dataset_id?: number | null
  creator: { id: number; name: string } | null
  updated_at: string
  deleted_at?: string | null
  header?: string; body?: string; footer?: string
  page_header?: string; page_footer?: string
  groups?: Group[]
  parameter_screen?: string
  prompt?: string | null
}
type ConstantOpt = { placeholder: string; label: string; scope: string; type?: string; image_url?: string | null }
type LogEntry = { id: number; action?: string; event?: string; snapshot?: unknown; properties?: Record<string, unknown>; changed_by_name?: string; user_name?: string; created_at: string }

const auth = useAuthStore()
const canManage = auth.can('reports.create')

const templates = ref<Template[]>([])
const deletedTemplates = ref<Template[]>([])
const constants = ref<ConstantOpt[]>([])
const projects = ref<{ id: number; code: string; name: string }[]>([])
const dataSources = ref<DataSourceOpt[]>([])
const datasets = ref<DatasetOpt[]>([])
const datasetsForForm = computed(() => datasets.value.filter((d) => d.data_source_id === form.value.data_source_id))
const loading = ref(true)
const error = ref('')
const busy = ref(false)

const SECTION_TABS = [
  { key: 'header', label: 'Report Header/Footer' },
  { key: 'page', label: 'Page Header/Footer' },
  { key: 'groups', label: 'Groups' },
  { key: 'body', label: 'Body' },
  { key: 'parameter_screen', label: 'Parameter Screen' },
] as const
const sectionTab = ref<typeof SECTION_TABS[number]['key']>('header')

const showForm = ref(false)
const editing = ref<Template | null>(null)
const form = ref({
  name: '', description: '', project_id: null as number | null,
  data_source_id: null as number | null, dataset_id: null as number | null,
  header: '', body: '', footer: '', page_header: '', page_footer: '',
  parameter_screen: '', groups: [] as Group[], prompt: '',
})
const genBusy = ref<string | null>(null)

async function load() {
  loading.value = true
  try {
    const [tRes, cRes, pRes] = await Promise.all([
      apiRequest<{ data: Template[] }>('/templates?with_trashed=1'),
      apiRequest<{ data: ConstantOpt[] }>('/constants'),
      apiRequest<{ data: { id: number; code: string; name: string }[] }>('/projects'),
    ])
    templates.value = tRes.data.filter((t) => !t.deleted_at)
    deletedTemplates.value = tRes.data.filter((t) => !!t.deleted_at)
    constants.value = cRes.data
    projects.value = pRes.data

    if (auth.can('datasources.view') && !dataSources.value.length) {
      const src = (await apiRequest<{ data: DataSourceOpt[] }>('/data-sources')).data
      dataSources.value = src
      const lists = await Promise.all(src.map((s) => apiRequest<{ data: DatasetOpt[] }>(`/data-sources/${s.id}/datasets`)))
      datasets.value = lists.flatMap((l) => l.data)
    }
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load templates'
  } finally {
    loading.value = false
  }
}

function emptyForm() {
  return {
    name: '', description: '', project_id: auth.user?.current_project?.id ?? null,
    data_source_id: null as number | null, dataset_id: null as number | null,
    header: '', body: '', footer: '', page_header: '', page_footer: '',
    parameter_screen: '', groups: [] as Group[], prompt: '',
  }
}

function openCreate() {
  editing.value = null
  form.value = emptyForm()
  sectionTab.value = 'header'
  showForm.value = true
}

async function openEdit(t: Template) {
  const full = (await apiRequest<{ data: Template }>(`/templates/${t.id}`)).data
  editing.value = full
  form.value = {
    name: full.name, description: full.description ?? '', project_id: full.project?.id ?? null,
    data_source_id: full.data_source_id ?? null, dataset_id: full.dataset_id ?? null,
    header: full.header ?? '', body: full.body ?? '', footer: full.footer ?? '',
    page_header: full.page_header ?? '', page_footer: full.page_footer ?? '',
    parameter_screen: full.parameter_screen ?? '', groups: full.groups ?? [], prompt: full.prompt ?? '',
  }
  sectionTab.value = 'header'
  showForm.value = true
}

function addGroupLevel() {
  const next = (form.value.groups[form.value.groups.length - 1]?.level ?? 0) + 1
  form.value.groups.push({ level: next, header: '', footer: '' })
}
function removeGroupLevel(idx: number) {
  form.value.groups.splice(idx, 1)
}

// Editors are refs keyed by field name; call getRaw() on each before saving.
const editorRefs = ref<Record<string, InstanceType<typeof RichEditor> | null>>({})
function setEditorRef(key: string) {
  return (el: unknown) => { editorRefs.value[key] = el as InstanceType<typeof RichEditor> | null }
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    // Pull raw placeholder text back out of each rich editor before sending.
    const sectionKeys = ['header', 'body', 'footer', 'page_header', 'page_footer', 'parameter_screen'] as const
    for (const key of sectionKeys) {
      const ed = editorRefs.value[key]
      if (ed) form.value[key] = ed.getRaw()
    }
    form.value.groups.forEach((g, i) => {
      const h = editorRefs.value[`group_header_${i}`]
      const f = editorRefs.value[`group_footer_${i}`]
      if (h) g.header = h.getRaw()
      if (f) g.footer = f.getRaw()
    })

    const payload = { ...form.value }
    const path = editing.value ? `/templates/${editing.value.id}` : '/templates'
    await apiRequest(path, { method: editing.value ? 'PUT' : 'POST', body: JSON.stringify(payload) })
    showForm.value = false
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    busy.value = false
  }
}

async function remove(t: Template) {
  if (!confirm(`Delete template "${t.name}"?`)) return
  busy.value = true
  try {
    await apiRequest(`/templates/${t.id}`, { method: 'DELETE' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Delete failed'
  } finally {
    busy.value = false
  }
}

async function restore(t: Template) {
  busy.value = true
  try {
    await apiRequest(`/templates/${t.id}/restore`, { method: 'POST' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Restore failed'
  } finally {
    busy.value = false
  }
}

type TextSectionField = 'header' | 'body' | 'footer' | 'page_header' | 'page_footer' | 'parameter_screen'
async function generate(section: string, field: TextSectionField) {
  if (!form.value.prompt) return
  genBusy.value = field
  try {
    const res = await apiRequest<{ data: { html: string } }>('/templates/fill-from-prompt', {
      method: 'POST',
      body: JSON.stringify({ prompt: form.value.prompt, section }),
    })
    form.value[field] = res.data.html
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Generate failed'
  } finally {
    genBusy.value = null
  }
}

// Log modal
const logTarget = ref<Template | null>(null)
const logTab = ref<'access' | 'history'>('history')
const logEntries = ref<LogEntry[]>([])
const logBusy = ref(false)
async function openLog(t: Template) {
  logTarget.value = t
  await fetchLog(t, 'history')
}
async function fetchLog(t: Template, tab: 'access' | 'history') {
  logTab.value = tab
  logBusy.value = true
  logEntries.value = []
  try {
    const path = tab === 'history' ? `/templates/${t.id}/history` : `/templates/${t.id}/logs`
    logEntries.value = (await apiRequest<{ data: LogEntry[] }>(path)).data
  } finally {
    logBusy.value = false
  }
}

onMounted(load)
</script>

<template>
  <AdminLayout>
    <div class="space-y-4">
      <div class="flex items-center justify-between">
        <p class="text-sm text-slate-500">Reusable report layouts — header/footer, page banners, group bands, and the parameter screen.</p>
        <button v-if="canManage" @click="openCreate" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5">+ New Template</button>
      </div>

      <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>

      <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>
        <div v-else-if="!templates.length" class="text-slate-400 text-sm">No templates yet.</div>
        <div v-for="t in templates" :key="t.id" class="bg-white rounded-xl border border-slate-100 p-4 flex flex-col gap-2">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <div class="font-semibold text-slate-700 truncate">{{ t.name }}</div>
              <div class="text-xs text-slate-400">{{ t.project?.code ?? 'global' }}</div>
            </div>
            <span class="text-xs font-medium rounded-full px-2 py-0.5 shrink-0" :class="t.scope === 'global' ? 'bg-slate-100 text-slate-500' : 'bg-emerald-100 text-emerald-700'">{{ t.scope }}</span>
          </div>
          <p v-if="t.description" class="text-xs text-slate-500 line-clamp-2">{{ t.description }}</p>
          <div class="text-xs text-slate-400">by {{ t.creator?.name ?? '—' }}</div>
          <div class="flex gap-3 pt-1 mt-auto">
            <button @click="openEdit(t)" class="text-xs text-airr-600 hover:underline">{{ canManage ? 'Edit' : 'View' }}</button>
            <button @click="openLog(t)" class="text-xs text-slate-500 hover:underline">Log</button>
            <button v-if="canManage" @click="remove(t)" :disabled="busy" class="text-xs text-rose-600 hover:underline">Delete</button>
          </div>
        </div>
      </div>

      <!-- Deleted templates -->
      <div v-if="deletedTemplates.length" class="mt-2">
        <p class="text-xs font-medium text-slate-400 mb-2">Deleted templates</p>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          <div v-for="t in deletedTemplates" :key="t.id" class="bg-slate-50 rounded-xl border border-dashed border-slate-200 p-4 flex flex-col gap-2 opacity-60">
            <div class="font-semibold text-slate-500 truncate line-through">{{ t.name }}</div>
            <button v-if="canManage" @click="restore(t)" :disabled="busy" class="text-xs text-emerald-600 hover:underline font-medium self-start">Undo delete</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Editor modal -->
    <div v-if="showForm" class="fixed inset-0 bg-slate-900/30 flex items-center justify-center z-50 px-4 py-8 overflow-y-auto" @click.self="showForm = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-4xl p-6 space-y-4 my-auto">
        <h3 class="font-bold text-lg">{{ editing ? 'Edit Template' : 'New Template' }}</h3>

        <div class="grid grid-cols-3 gap-3">
          <div class="col-span-2">
            <label class="block text-sm font-medium text-slate-600 mb-1">Name</label>
            <input v-model="form.name" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Project <span class="text-slate-400 font-normal">(blank = global)</span></label>
            <select v-model="form.project_id" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
              <option :value="null">— global —</option>
              <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option>
            </select>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Description</label>
          <input v-model="form.description" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>

        <!-- Default datasource/dataset — used to preview and resolve {{DATA:X}} constants for this template -->
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Data source <span class="text-slate-400 font-normal">(optional)</span></label>
            <select v-model="form.data_source_id" @change="form.dataset_id = null"
              class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
              <option :value="null">— none —</option>
              <option v-for="s in dataSources" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Dataset</label>
            <select v-model="form.dataset_id" :disabled="!form.data_source_id"
              class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none disabled:bg-slate-50">
              <option :value="null">— none —</option>
              <option v-for="d in datasetsForForm" :key="d.id" :value="d.id">{{ d.name }}</option>
            </select>
          </div>
        </div>

        <!-- Prompt-assisted creation -->
        <div class="rounded-lg border border-slate-200 p-3 space-y-2 bg-slate-50">
          <label class="block text-xs font-medium text-slate-500">Describe this section in plain language, then Generate to draft its HTML</label>
          <textarea v-model="form.prompt" rows="2" placeholder="e.g. A crimson report header with the company logo, report title, and date on the right."
            class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none"></textarea>
        </div>

        <!-- Project constants — manage directly while authoring this template -->
        <details v-if="form.project_id" class="rounded-lg border border-slate-200 p-3">
          <summary class="text-xs font-medium text-slate-500 cursor-pointer select-none">Project constants for this template's project</summary>
          <div class="mt-2">
            <ConstantsPanel scope="project" :project-id="form.project_id" />
          </div>
        </details>

        <!-- Section tabs -->
        <div class="flex gap-2 border-b border-slate-100 pb-2 flex-wrap">
          <button v-for="s in SECTION_TABS" :key="s.key" @click="sectionTab = s.key"
            class="text-xs font-medium px-3 py-1.5 rounded-lg" :class="sectionTab === s.key ? 'bg-airr-500 text-white' : 'text-slate-500 hover:bg-slate-50'">
            {{ s.label }}
          </button>
        </div>

        <!-- Report header/footer -->
        <div v-if="sectionTab === 'header'" class="space-y-3">
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-sm font-medium text-slate-600">Report Header</label>
              <button @click="generate('header', 'header')" :disabled="!form.prompt || genBusy === 'header'" class="text-xs text-airr-600 hover:underline disabled:opacity-40">{{ genBusy === 'header' ? 'Generating…' : 'Generate from prompt' }}</button>
            </div>
            <RichEditor v-model="form.header" :constants="constants" :ref="setEditorRef('header')" />
          </div>
          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-sm font-medium text-slate-600">Report Footer</label>
              <button @click="generate('footer', 'footer')" :disabled="!form.prompt || genBusy === 'footer'" class="text-xs text-airr-600 hover:underline disabled:opacity-40">{{ genBusy === 'footer' ? 'Generating…' : 'Generate from prompt' }}</button>
            </div>
            <RichEditor v-model="form.footer" :constants="constants" :ref="setEditorRef('footer')" />
          </div>
        </div>

        <!-- Page header/footer -->
        <div v-else-if="sectionTab === 'page'" class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Page Header</label>
            <RichEditor v-model="form.page_header" :constants="constants" :ref="setEditorRef('page_header')" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Page Footer</label>
            <RichEditor v-model="form.page_footer" :constants="constants" :ref="setEditorRef('page_footer')" />
          </div>
        </div>

        <!-- Groups -->
        <div v-else-if="sectionTab === 'groups'" class="space-y-3">
          <div v-for="(g, i) in form.groups" :key="i" class="rounded-lg border border-slate-200 p-3 space-y-2">
            <div class="flex items-center justify-between">
              <span class="text-xs font-semibold text-slate-500">Group {{ g.level }}</span>
              <button @click="removeGroupLevel(i)" class="text-xs text-rose-600 hover:underline">Remove</button>
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Header</label>
              <RichEditor v-model="g.header as string" :constants="constants" :ref="setEditorRef(`group_header_${i}`)" />
            </div>
            <div>
              <label class="block text-xs font-medium text-slate-500 mb-1">Footer</label>
              <RichEditor v-model="g.footer as string" :constants="constants" :ref="setEditorRef(`group_footer_${i}`)" />
            </div>
          </div>
          <button @click="addGroupLevel" class="text-xs font-medium text-airr-600 hover:underline">+ Add group level</button>
        </div>

        <!-- Body -->
        <div v-else-if="sectionTab === 'body'">
          <div class="flex items-center justify-between mb-1">
            <label class="block text-sm font-medium text-slate-600">Body</label>
            <button @click="generate('body', 'body')" :disabled="!form.prompt || genBusy === 'body'" class="text-xs text-airr-600 hover:underline disabled:opacity-40">{{ genBusy === 'body' ? 'Generating…' : 'Generate from prompt' }}</button>
          </div>
          <RichEditor v-model="form.body" :constants="constants" :ref="setEditorRef('body')" />
        </div>

        <!-- Parameter screen -->
        <div v-else-if="sectionTab === 'parameter_screen'">
          <label class="block text-sm font-medium text-slate-600 mb-1">Parameter Screen <span class="text-slate-400 font-normal">— shown before the report runs</span></label>
          <RichEditor v-model="form.parameter_screen" :constants="constants" :ref="setEditorRef('parameter_screen')" />
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button @click="showForm = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button v-if="canManage" @click="save" :disabled="busy || !form.name" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
            {{ busy ? 'Saving…' : editing ? 'Save' : 'Create Template' }}
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
