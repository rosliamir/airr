<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import RichEditor from '../components/RichEditor.vue'
import ConstantsPanel from '../components/ConstantsPanel.vue'
import { apiRequest, uploadFile, downloadFile, ApiException } from '../api/client'
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
  tags?: string[]
  archived_at?: string | null
}
type ConstantOpt = { placeholder: string; label: string; scope: string; type?: string; image_url?: string | null }
type LogEntry = { id: number; action?: string; event?: string; snapshot?: unknown; properties?: Record<string, unknown>; changed_by_name?: string; user_name?: string; created_at: string }

const auth = useAuthStore()
const canManage = auth.can('reports.create')

const templates = ref<Template[]>([])
const searchText = ref('')
const activeTag = ref<string | null>(null)
const allTags = computed(() => Array.from(new Set(templates.value.flatMap(t => t.tags ?? []))).sort())
const filteredTemplates = computed(() => templates.value.filter(t => {
  if (activeTag.value && !(t.tags ?? []).includes(activeTag.value)) return false
  if (searchText.value && !`${t.name} ${t.description ?? ''}`.toLowerCase().includes(searchText.value.toLowerCase())) return false
  return true
}))
const deletedTemplates = ref<Template[]>([])

// Bulk selection + sort + archive toggle (mirrors ReportsView).
const selectedIds = ref<Set<number>>(new Set())
const showArchived = ref(false)
const sortBy = ref<'-updated_at' | 'updated_at' | 'name' | '-name'>('-updated_at')
const allSelected = computed(() => filteredTemplates.value.length > 0 && filteredTemplates.value.every(t => selectedIds.value.has(t.id)))
function toggleSelectAll() {
  selectedIds.value = allSelected.value ? new Set() : new Set(filteredTemplates.value.map(t => t.id))
}
function toggleSelect(id: number) {
  const next = new Set(selectedIds.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selectedIds.value = next
}
const sortedTemplates = computed(() => {
  const col = sortBy.value.replace(/^-/, '') as 'updated_at' | 'name'
  const dir = sortBy.value.startsWith('-') ? -1 : 1
  return [...filteredTemplates.value].sort((a, b) => {
    const av = col === 'name' ? a.name : a.updated_at
    const bv = col === 'name' ? b.name : b.updated_at
    return av < bv ? -1 * dir : av > bv ? 1 * dir : 0
  })
})

// Per-card action menu
const openMenuId = ref<number | null>(null)
function toggleMenu(id: number) { openMenuId.value = openMenuId.value === id ? null : id }
function closeMenuOnOutsideClick() { openMenuId.value = null }
onMounted(() => document.addEventListener('click', closeMenuOnOutsideClick))
onUnmounted(() => document.removeEventListener('click', closeMenuOnOutsideClick))

async function duplicateTemplate(t: Template) {
  openMenuId.value = null
  busy.value = true
  try {
    await apiRequest(`/templates/${t.id}/duplicate`, { method: 'POST' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Duplicate failed'
  } finally {
    busy.value = false
  }
}
async function exportTemplate(t: Template) {
  openMenuId.value = null
  try {
    await downloadFile(`/templates/${t.id}/export`, `${t.name}.json`)
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Export failed'
  }
}
async function archiveTemplate(t: Template) {
  openMenuId.value = null
  busy.value = true
  try {
    await apiRequest(`/templates/${t.id}/archive`, { method: 'POST' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Archive failed'
  } finally {
    busy.value = false
  }
}
async function unarchiveTemplate(t: Template) {
  openMenuId.value = null
  busy.value = true
  try {
    await apiRequest(`/templates/${t.id}/unarchive`, { method: 'POST' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Unarchive failed'
  } finally {
    busy.value = false
  }
}

async function bulkDuplicate() {
  busy.value = true
  try {
    for (const id of selectedIds.value) await apiRequest(`/templates/${id}/duplicate`, { method: 'POST' })
    selectedIds.value = new Set()
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Duplicate failed'
  } finally {
    busy.value = false
  }
}
async function bulkExport() {
  for (const id of selectedIds.value) {
    const t = templates.value.find(x => x.id === id)
    try {
      await downloadFile(`/templates/${id}/export`, `${t?.name ?? id}.json`)
    } catch (e) {
      error.value = e instanceof ApiException ? e.error.message : 'Export failed'
    }
  }
}
async function bulkArchive() {
  busy.value = true
  try {
    for (const id of selectedIds.value) await apiRequest(`/templates/${id}/archive`, { method: 'POST' })
    selectedIds.value = new Set()
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Archive failed'
  } finally {
    busy.value = false
  }
}
async function bulkDelete() {
  if (!selectedIds.value.size || !confirm(`Delete ${selectedIds.value.size} template(s)?`)) return
  busy.value = true
  try {
    for (const id of selectedIds.value) await apiRequest(`/templates/${id}`, { method: 'DELETE' })
    selectedIds.value = new Set()
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Delete failed'
  } finally {
    busy.value = false
  }
}

// Import
const importInput = ref<HTMLInputElement | null>(null)
function triggerImport() { importInput.value?.click() }
async function onImportFile(ev: Event) {
  const file = (ev.target as HTMLInputElement).files?.[0]
  if (!file) return
  const fd = new FormData()
  fd.append('file', file)
  try {
    await uploadFile('/templates/import', fd)
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Import failed'
  } finally {
    await load()
    ;(ev.target as HTMLInputElement).value = ''
  }
}
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
    const archivedParam = showArchived.value ? '&include_archived=1' : ''
    const [tRes, cRes, pRes] = await Promise.all([
      apiRequest<{ data: Template[] }>(`/templates?with_trashed=1${archivedParam}`),
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

const tagsText = ref('')

function openCreate() {
  editing.value = null
  form.value = emptyForm()
  tagsText.value = ''
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
  tagsText.value = (full.tags ?? []).join(', ')
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

    const tags = tagsText.value.split(',').map(s => s.trim().replace(/^#/, '')).filter(Boolean)
    const payload = { ...form.value, tags }
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

watch(showArchived, load)
onMounted(load)
</script>

<template>
  <AdminLayout>
    <div class="space-y-4">
      <div class="flex items-center justify-between gap-3">
        <p class="text-sm text-slate-500">Reusable report layouts — header/footer, page banners, group bands, and the parameter screen.</p>
        <div class="flex items-center gap-2 shrink-0">
          <input v-model="searchText" placeholder="Search templates…" class="text-sm rounded-lg border border-slate-200 px-3 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 w-48" />
          <template v-if="canManage">
            <button @click="triggerImport" class="text-sm font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5">Import</button>
            <input ref="importInput" type="file" accept=".json,application/json" class="hidden" @change="onImportFile" />
          </template>
          <button v-if="canManage" @click="openCreate" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5">+ New Template</button>
        </div>
      </div>

      <div v-if="allTags.length" class="flex flex-wrap items-center gap-1.5">
        <span class="text-xs text-slate-400">Filter by hashtag:</span>
        <button v-for="tag in allTags" :key="tag" @click="activeTag = activeTag === tag ? null : tag"
          class="text-xs rounded-full px-2.5 py-1"
          :class="activeTag === tag ? 'bg-airr-500 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">
          #{{ tag }}
        </button>
      </div>

      <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>

      <!-- Controls: select-all, sort, archived toggle, bulk actions -->
      <div class="flex items-center justify-between gap-3 flex-wrap">
        <div class="flex items-center gap-3">
          <label class="flex items-center gap-1.5 text-xs text-slate-500">
            <input type="checkbox" :checked="allSelected" @change="toggleSelectAll" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" />
            Select all
          </label>
          <label class="flex items-center gap-1.5 text-xs text-slate-500">
            <input type="checkbox" v-model="showArchived" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" />
            Show archived
          </label>
          <select v-model="sortBy" class="text-xs rounded-lg border border-slate-200 px-2 py-1.5 outline-none focus:ring-2 focus:ring-airr-300">
            <option value="-updated_at">Newest first</option>
            <option value="updated_at">Oldest first</option>
            <option value="name">Name A–Z</option>
            <option value="-name">Name Z–A</option>
          </select>
        </div>
        <div v-if="selectedIds.size" class="flex items-center gap-2">
          <span class="text-xs text-slate-400">{{ selectedIds.size }} selected</span>
          <button @click="bulkDuplicate" :disabled="busy" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-2.5 py-1.5">Duplicate</button>
          <button @click="bulkExport" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-2.5 py-1.5">Export</button>
          <button @click="bulkArchive" :disabled="busy" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-2.5 py-1.5">Archive</button>
          <button @click="bulkDelete" :disabled="busy" class="text-xs font-medium text-rose-600 border border-rose-200 hover:bg-rose-50 rounded-lg px-2.5 py-1.5">Delete</button>
        </div>
      </div>

      <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>
        <div v-else-if="!sortedTemplates.length" class="text-slate-400 text-sm">No templates match.</div>
        <div v-for="t in sortedTemplates" :key="t.id" class="relative bg-white rounded-xl border border-slate-100 p-4 flex flex-col gap-2 hover:border-slate-200 transition"
          :class="t.archived_at ? 'opacity-50' : ''">
          <div class="flex items-start justify-between gap-2">
            <div class="flex items-start gap-2 min-w-0 cursor-pointer" @click="openEdit(t)">
              <input type="checkbox" :checked="selectedIds.has(t.id)" @click.stop @change="toggleSelect(t.id)"
                class="mt-1 rounded border-slate-300 text-airr-500 focus:ring-airr-300 shrink-0" />
              <div class="min-w-0">
                <div class="font-semibold text-slate-700 truncate">{{ t.name }}</div>
                <div class="text-xs text-slate-400">{{ t.project?.code ?? 'global' }}</div>
              </div>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <span v-if="t.archived_at" class="text-[10px] rounded-full px-1.5 py-0.5 bg-slate-200 text-slate-500">archived</span>
              <span class="text-xs font-medium rounded-full px-2 py-0.5" :class="t.scope === 'global' ? 'bg-slate-100 text-slate-500' : 'bg-emerald-100 text-emerald-700'">{{ t.scope }}</span>
              <!-- Per-card action menu -->
              <div class="relative">
                <button @click.stop="toggleMenu(t.id)" class="text-slate-400 hover:text-slate-600 px-1 rounded hover:bg-slate-100">⋮</button>
                <div v-if="openMenuId === t.id"
                  class="absolute right-0 top-full mt-1 w-40 bg-white border border-slate-200 rounded-xl shadow-lg z-20 py-1 text-xs">
                  <button @click.stop="duplicateTemplate(t)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Duplicate</button>
                  <button @click.stop="exportTemplate(t)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Export</button>
                  <button @click.stop="openLog(t); openMenuId = null" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Log</button>
                  <div class="border-t border-slate-100 my-1"></div>
                  <button v-if="!t.archived_at" @click.stop="archiveTemplate(t)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Archive</button>
                  <button v-else @click.stop="unarchiveTemplate(t)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Unarchive</button>
                  <button @click.stop="remove(t); openMenuId = null" class="w-full text-left px-3 py-1.5 hover:bg-rose-50 text-rose-600">Delete</button>
                </div>
              </div>
            </div>
          </div>
          <p v-if="t.description" class="text-xs text-slate-500 line-clamp-2 cursor-pointer" @click="openEdit(t)">{{ t.description }}</p>
          <div v-if="t.tags?.length" class="flex flex-wrap gap-1">
            <span v-for="tag in t.tags" :key="tag" class="text-[10px] bg-airr-50 text-airr-600 rounded-full px-2 py-0.5">#{{ tag }}</span>
          </div>
          <div class="text-xs text-slate-400 mt-auto cursor-pointer" @click="openEdit(t)">by {{ t.creator?.name ?? '—' }}</div>
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

        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Hashtags <span class="text-slate-400 font-normal">(comma-separated, e.g. kutipan, pbt)</span></label>
          <input v-model="tagsText" placeholder="kutipan, pbt" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
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
              <span class="text-slate-400">{{ e.changed_by_name ?? e.user_name }} · {{ new Date(e.created_at).toLocaleString() }}</span>
            </div>
            <pre v-if="e.snapshot" class="text-[10px] text-slate-500 whitespace-pre-wrap break-all">{{ JSON.stringify(e.snapshot, null, 2) }}</pre>
            <pre v-else-if="e.properties && Object.keys(e.properties).length" class="text-[10px] text-slate-500 whitespace-pre-wrap break-all">{{ JSON.stringify(e.properties, null, 2) }}</pre>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
