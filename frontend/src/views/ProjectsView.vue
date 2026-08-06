<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed, watch } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import ConstantsPanel from '../components/ConstantsPanel.vue'
import { apiRequest, uploadFile, downloadFile, ApiException } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useListViewMode } from '../composables/useListViewMode'
import ViewModeToggle from '../components/ViewModeToggle.vue'

type Member = { id: number; name: string; email?: string }
type Project = {
  id: number
  code: string
  name: string
  customer_name: string | null
  type: string | null
  color: string | null
  status: string
  start_date: string | null
  end_date: string | null
  description: string | null
  ai_config: { provider?: string; models?: Record<string, string> } | null
  creator: { id: number; name: string } | null
  users_count: number
  reports_count: number
  templates_count: number
  users?: Member[]
  deleted_at?: string | null
  archived_at?: string | null
  tags?: string[]
}
type LogEntry = { id: number; action?: string; event?: string; properties?: Record<string, unknown>; user_name?: string; created_at: string }

// Palette for project colour-coding.
const PALETTE = ['#E11D48', '#F97316', '#EAB308', '#22C55E', '#06B6D4', '#3B82F6', '#8B5CF6', '#EC4899', '#64748B', '#0F766E']

// Stable fallback colour from the code when none is chosen.
function colorOf(p: { color: string | null; code: string }) {
  if (p.color) return p.color
  let h = 0
  for (let i = 0; i < p.code.length; i++) h = (h * 31 + p.code.charCodeAt(i)) % PALETTE.length
  return PALETTE[h]
}
function initialsOf(name: string) {
  return name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]?.toUpperCase() ?? '').join('')
}

const auth = useAuthStore()
const canManage = auth.can('projects.manage')

const projects = ref<Project[]>([])
const deletedProjects = ref<Project[]>([])
const allUsers = ref<Member[]>([])

// Search + hashtag filter + sort + bulk selection (mirrors ReportsView/TemplatesView).
const searchText = ref('')
const activeTag = ref<string | null>(null)
const showArchived = ref(false)
const sortBy = ref<'-created_at' | 'created_at' | 'name' | '-name' | 'status'>('-created_at')
const allTags = computed(() => Array.from(new Set(projects.value.flatMap(p => p.tags ?? []))).sort())
const filteredProjects = computed(() => projects.value.filter(p => {
  if (activeTag.value && !(p.tags ?? []).includes(activeTag.value)) return false
  if (searchText.value && !`${p.name} ${p.code} ${p.description ?? ''}`.toLowerCase().includes(searchText.value.toLowerCase())) return false
  return true
}))
const sortedProjects = computed(() => {
  const col = sortBy.value.replace(/^-/, '') as 'created_at' | 'name' | 'status'
  const dir = sortBy.value.startsWith('-') ? -1 : 1
  return [...filteredProjects.value].sort((a, b) => {
    const av = String(a[col === 'created_at' ? 'id' : col] ?? '')
    const bv = String(b[col === 'created_at' ? 'id' : col] ?? '')
    return av < bv ? -1 * dir : av > bv ? 1 * dir : 0
  })
})

const { viewMode } = useListViewMode('projects')
const selectedIds = ref<Set<number>>(new Set())
const allSelected = computed(() => sortedProjects.value.length > 0 && sortedProjects.value.every(p => selectedIds.value.has(p.id)))
function toggleSelectAll() {
  selectedIds.value = allSelected.value ? new Set() : new Set(sortedProjects.value.map(p => p.id))
}
function toggleSelect(id: number) {
  const next = new Set(selectedIds.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selectedIds.value = next
}

const openMenuId = ref<number | null>(null)
function toggleMenu(id: number) { openMenuId.value = openMenuId.value === id ? null : id }
function closeMenuOnOutsideClick() { openMenuId.value = null }
onMounted(() => document.addEventListener('click', closeMenuOnOutsideClick))
onUnmounted(() => document.removeEventListener('click', closeMenuOnOutsideClick))
const projectTypes = ref<{ value: string; label: string }[]>([])
const loading = ref(true)
const error = ref('')
const busy = ref(false)

const STATUSES = ['active', 'on_hold', 'completed', 'archived']
const statusLabel: Record<string, string> = {
  active: 'Active', on_hold: 'On hold', completed: 'Completed', archived: 'Archived',
}
const statusBadge: Record<string, string> = {
  active: 'bg-emerald-100 text-emerald-700',
  on_hold: 'bg-amber-100 text-amber-700',
  completed: 'bg-sky-100 text-sky-700',
  archived: 'bg-slate-100 text-slate-500',
}

const tagsText = ref('')
const showForm = ref(false)
const editing = ref<Project | null>(null)
const form = ref({
  code: '', name: '', customer_name: '', type: '', color: '', status: 'active',
  start_date: '', end_date: '', description: '',
  users: [] as number[],
  ai_models: {} as Record<string, string>,
})

// AI defaults + per-project gate (FR-M14.5). Loaded once; inputs show defaults
// as placeholders and only persist values that differ.
const aiCfg = ref<{ provider: string; tasks: string[]; defaults: { models: Record<string, string> }; per_project: boolean } | null>(null)
const AI_TASK_LABELS: Record<string, string> = {
  embedding: 'Embedding', generation: 'Generation', reasoning: 'Reasoning', audit: 'Audit',
}

// Member pick search.
const userSearch = ref('')
const filteredUsers = computed(() =>
  allUsers.value.filter((u) => u.name.toLowerCase().includes(userSearch.value.toLowerCase())),
)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const archivedParam = showArchived.value ? '?include_archived=1' : ''
    const reqs: [Promise<{ data: Project[] }>, Promise<{ data: Member[] }>?] = [
      apiRequest<{ data: Project[] }>(`/projects${archivedParam}`),
    ]
    // Member picker needs users.manage; degrade gracefully if not held.
    if (auth.can('users.manage')) {
      reqs[1] = apiRequest<{ data: Member[] }>('/users?limit=100')
    }
    const [p, u] = await Promise.all(reqs as Promise<unknown>[]) as [
      { data: Project[] }, { data: Member[] }?,
    ]
    projects.value = p.data
    allUsers.value = u?.data ?? []
    if (canManage) {
      const trashed = (await apiRequest<{ data: Project[] }>('/projects?with_trashed=1')).data
      deletedProjects.value = trashed.filter(x => !!x.deleted_at)
    }
    if (!projectTypes.value.length) {
      projectTypes.value = (await apiRequest<{ data: { value: string; label: string }[] }>('/lookups?category=project_type')).data
    }
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load projects'
  } finally {
    loading.value = false
  }
}

function resetSearch() {
  userSearch.value = ''
}
async function openCreate() {
  editing.value = null
  resetSearch()
  form.value = { code: '', name: '', customer_name: '', type: '', color: '', status: 'active', start_date: '', end_date: '', description: '', users: [], ai_models: {} }
  tagsText.value = ''
  showForm.value = true
}
async function openEdit(p: Project) {
  openMenuId.value = null
  resetSearch()
  // Fetch detail to get current members.
  const res = await apiRequest<{ data: Project }>(`/projects/${p.id}`)
  const d = res.data
  editing.value = d
  form.value = {
    code: d.code, name: d.name, customer_name: d.customer_name ?? '', type: d.type ?? '',
    color: d.color ?? '', status: d.status,
    start_date: d.start_date ?? '', end_date: d.end_date ?? '', description: d.description ?? '',
    users: (d.users ?? []).map((m) => m.id),    ai_models: { ...(d.ai_config?.models ?? {}) },
  }
  tagsText.value = (d.tags ?? []).join(', ')
  showForm.value = true
}
function toggle(list: 'users', id: number) {
  const arr = form.value[list]
  form.value[list] = arr.includes(id) ? arr.filter((x) => x !== id) : [...arr, id]
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    const { ai_models, ...rest } = form.value
    // Persist only non-empty per-task overrides; otherwise inherit system default.
    const models = Object.fromEntries(Object.entries(ai_models).filter(([, v]) => v && v.trim()))
    const tags = tagsText.value.split(',').map(s => s.trim().replace(/^#/, '')).filter(Boolean)
    const body = {
      ...rest,
      tags,
      ai_config: Object.keys(models).length ? { provider: aiCfg.value?.provider, models } : null,
    }
    const path = editing.value ? `/projects/${editing.value.id}` : '/projects'
    await apiRequest(path, { method: editing.value ? 'PUT' : 'POST', body: JSON.stringify(body) })
    showForm.value = false
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    busy.value = false
  }
}

async function remove(p: Project) {
  if (!confirm(`Delete project "${p.name}"? Reports under it must be moved first.`)) return
  busy.value = true
  try {
    await apiRequest(`/projects/${p.id}`, { method: 'DELETE' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Delete failed'
  } finally {
    busy.value = false
  }
}

async function restore(p: Project) {
  busy.value = true
  try {
    await apiRequest(`/projects/${p.id}/restore`, { method: 'POST' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Restore failed'
  } finally {
    busy.value = false
  }
}

async function duplicateProject(p: Project) {
  openMenuId.value = null
  busy.value = true
  try {
    await apiRequest(`/projects/${p.id}/duplicate`, { method: 'POST' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Duplicate failed'
  } finally {
    busy.value = false
  }
}
async function exportProject(p: Project) {
  openMenuId.value = null
  try {
    await downloadFile(`/projects/${p.id}/export`, `${p.name}.json`)
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Export failed'
  }
}
async function archiveProject(p: Project) {
  openMenuId.value = null
  busy.value = true
  try {
    await apiRequest(`/projects/${p.id}/archive`, { method: 'POST' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Archive failed'
  } finally {
    busy.value = false
  }
}
async function unarchiveProject(p: Project) {
  openMenuId.value = null
  busy.value = true
  try {
    await apiRequest(`/projects/${p.id}/unarchive`, { method: 'POST' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Unarchive failed'
  } finally {
    busy.value = false
  }
}
async function toggleActive(p: Project) {
  openMenuId.value = null
  busy.value = true
  try {
    await apiRequest(`/projects/${p.id}`, {
      method: 'PUT',
      body: JSON.stringify({ code: p.code, name: p.name, status: p.status === 'active' ? 'on_hold' : 'active' }),
    })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Update failed'
  } finally {
    busy.value = false
  }
}

async function bulkDelete() {
  if (!selectedIds.value.size || !confirm(`Delete ${selectedIds.value.size} project(s)?`)) return
  busy.value = true
  try {
    for (const id of selectedIds.value) await apiRequest(`/projects/${id}`, { method: 'DELETE' })
    selectedIds.value = new Set()
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Delete failed'
  } finally {
    busy.value = false
  }
}
async function bulkArchive() {
  busy.value = true
  try {
    for (const id of selectedIds.value) await apiRequest(`/projects/${id}/archive`, { method: 'POST' })
    selectedIds.value = new Set()
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Archive failed'
  } finally {
    busy.value = false
  }
}
async function bulkExport() {
  for (const id of selectedIds.value) {
    const p = projects.value.find(x => x.id === id)
    try {
      await downloadFile(`/projects/${id}/export`, `${p?.name ?? id}.json`)
    } catch (e) {
      error.value = e instanceof ApiException ? e.error.message : 'Export failed'
    }
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
    await uploadFile('/projects/import', fd)
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Import failed'
  } finally {
    await load()
    ;(ev.target as HTMLInputElement).value = ''
  }
}

// Log modal
const logTarget = ref<Project | null>(null)
const logEntries = ref<LogEntry[]>([])
const logBusy = ref(false)
async function openLog(p: Project) {
  openMenuId.value = null
  logTarget.value = p
  logBusy.value = true
  logEntries.value = []
  try {
    logEntries.value = (await apiRequest<{ data: LogEntry[] }>(`/projects/${p.id}/logs`)).data
  } finally {
    logBusy.value = false
  }
}

async function loadAiCfg() {
  if (!auth.can('settings.manage')) return
  try {
    aiCfg.value = (await apiRequest<{ data: typeof aiCfg.value }>('/ai/config')).data
  } catch { /* non-fatal: AI override section simply hidden */ }
}

watch(showArchived, load)
onMounted(() => {
  load()
  loadAiCfg()
})
</script>

<template>
  <AdminLayout>
    <div class="space-y-4">
      <div class="flex items-center justify-between gap-3">
        <p class="text-sm text-slate-500">Projects scope access and store reports. Assign users for membership.</p>
        <div class="flex items-center gap-2 shrink-0">
          <input v-model="searchText" placeholder="Search projects…" class="text-sm rounded-lg border border-slate-200 px-3 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 w-48" />
          <template v-if="canManage">
            <button @click="triggerImport" class="text-sm font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5">Import</button>
            <input ref="importInput" type="file" accept=".json,application/json" class="hidden" @change="onImportFile" />
          </template>
          <button v-if="canManage" @click="openCreate" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5">+ New Project</button>
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
            <option value="-created_at">Newest first</option>
            <option value="created_at">Oldest first</option>
            <option value="name">Name A–Z</option>
            <option value="-name">Name Z–A</option>
            <option value="status">Status</option>
          </select>
        </div>
        <div class="flex items-center gap-2">
          <div v-if="selectedIds.size" class="flex items-center gap-2">
            <span class="text-xs text-slate-400">{{ selectedIds.size }} selected</span>
            <button @click="bulkExport" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-2.5 py-1.5">Export</button>
            <button @click="bulkArchive" :disabled="busy" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-2.5 py-1.5">Archive</button>
            <button @click="bulkDelete" :disabled="busy" class="text-xs font-medium text-rose-600 border border-rose-200 hover:bg-rose-50 rounded-lg px-2.5 py-1.5">Delete</button>
          </div>
          <ViewModeToggle v-model="viewMode" />
        </div>
      </div>

      <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>
      <div v-else-if="!sortedProjects.length" class="text-slate-400 text-sm">No projects match.</div>
      <div v-else-if="viewMode === 'card'" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <div v-for="p in sortedProjects" :key="p.id" class="relative bg-white rounded-xl border border-slate-100 p-4 flex flex-col gap-2 hover:border-slate-200 transition"
          :class="p.archived_at ? 'opacity-50' : ''"
          :style="{ borderLeft: `4px solid ${colorOf(p)}` }">
          <div class="flex items-start justify-between gap-2">
            <div class="flex items-start gap-2 min-w-0 cursor-pointer" @click="openEdit(p)">
              <input type="checkbox" :checked="selectedIds.has(p.id)" @click.stop @change="toggleSelect(p.id)"
                class="mt-2 rounded border-slate-300 text-airr-500 focus:ring-airr-300 shrink-0" />
              <span class="w-9 h-9 rounded-lg shrink-0 inline-flex items-center justify-center text-white text-xs font-bold"
                :style="{ background: colorOf(p) }">{{ initialsOf(p.name) }}</span>
              <div class="min-w-0">
                <div class="font-semibold text-slate-700 truncate">{{ p.name }}</div>
                <div class="text-xs text-slate-400 font-mono">{{ p.code }}</div>
              </div>
            </div>
            <div class="flex items-center gap-1 shrink-0">
              <span v-if="p.archived_at" class="text-[10px] rounded-full px-1.5 py-0.5 bg-slate-200 text-slate-500">archived</span>
              <span class="text-xs font-medium rounded-full px-2 py-0.5" :class="statusBadge[p.status]">{{ statusLabel[p.status] }}</span>
              <!-- Per-card action menu -->
              <div v-if="canManage" class="relative">
                <button @click.stop="toggleMenu(p.id)" class="text-slate-400 hover:text-slate-600 px-1 rounded hover:bg-slate-100">⋮</button>
                <div v-if="openMenuId === p.id"
                  class="absolute right-0 top-full mt-1 w-40 bg-white border border-slate-200 rounded-xl shadow-lg z-20 py-1 text-xs">
                  <button @click.stop="duplicateProject(p)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Duplicate</button>
                  <button @click.stop="exportProject(p)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Export</button>
                  <button @click.stop="openLog(p)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Log</button>
                  <button @click.stop="toggleActive(p)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">{{ p.status === 'active' ? 'Set Inactive' : 'Set Active' }}</button>
                  <div class="border-t border-slate-100 my-1"></div>
                  <button v-if="!p.archived_at" @click.stop="archiveProject(p)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Archive</button>
                  <button v-else @click.stop="unarchiveProject(p)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Unarchive</button>
                  <button @click.stop="remove(p); openMenuId = null" class="w-full text-left px-3 py-1.5 hover:bg-rose-50 text-rose-600">Delete</button>
                </div>
              </div>
            </div>
          </div>
          <div v-if="p.customer_name" class="text-xs text-slate-500 cursor-pointer" @click="openEdit(p)">👤 {{ p.customer_name }}</div>
          <div v-if="p.type" class="text-xs text-slate-500 capitalize cursor-pointer" @click="openEdit(p)">{{ p.type }}</div>
          <div class="text-xs text-slate-400 cursor-pointer" @click="openEdit(p)">
            {{ p.start_date ?? '—' }} → {{ p.end_date ?? '—' }}
          </div>
          <div v-if="p.tags?.length" class="flex flex-wrap gap-1">
            <span v-for="tag in p.tags" :key="tag" class="text-[10px] bg-airr-50 text-airr-600 rounded-full px-2 py-0.5">#{{ tag }}</span>
          </div>
          <div class="text-xs text-slate-500 mt-auto cursor-pointer" @click="openEdit(p)">{{ p.users_count }} users · {{ p.reports_count }} reports · {{ p.templates_count }} templates</div>
        </div>
      </div>

      <!-- Tabular listing — same fields/actions as the cards above, one row per project -->
      <div v-else class="overflow-x-auto border border-slate-100 rounded-xl">
        <table class="w-full text-sm border-collapse">
          <thead>
            <tr class="bg-slate-50 text-left text-xs text-slate-500">
              <th class="px-3 py-2 border-b border-slate-200 w-8">
                <input type="checkbox" :checked="allSelected" @change="toggleSelectAll" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" />
              </th>
              <th class="px-3 py-2 border-b border-slate-200">Name</th>
              <th class="px-3 py-2 border-b border-slate-200">Customer</th>
              <th class="px-3 py-2 border-b border-slate-200">Type</th>
              <th class="px-3 py-2 border-b border-slate-200">Dates</th>
              <th class="px-3 py-2 border-b border-slate-200">Status</th>
              <th class="px-3 py-2 border-b border-slate-200">Tags</th>
              <th class="px-3 py-2 border-b border-slate-200">Counts</th>
              <th class="px-3 py-2 border-b border-slate-200 w-8"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in sortedProjects" :key="p.id" class="hover:bg-slate-50 transition"
              :class="p.archived_at ? 'opacity-50' : ''">
              <td class="px-3 py-2 border-b border-slate-100" @click.stop>
                <input type="checkbox" :checked="selectedIds.has(p.id)" @change="toggleSelect(p.id)"
                  class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" />
              </td>
              <td class="px-3 py-2 border-b border-slate-100 cursor-pointer" @click="openEdit(p)">
                <div class="flex items-center gap-2 min-w-0">
                  <span class="w-7 h-7 rounded-lg shrink-0 inline-flex items-center justify-center text-white text-[10px] font-bold"
                    :style="{ background: colorOf(p) }">{{ initialsOf(p.name) }}</span>
                  <div class="min-w-0">
                    <div class="font-semibold text-slate-700 truncate">{{ p.name }}</div>
                    <div class="text-xs text-slate-400 font-mono">{{ p.code }}</div>
                  </div>
                </div>
              </td>
              <td class="px-3 py-2 border-b border-slate-100 text-xs text-slate-500 cursor-pointer" @click="openEdit(p)">{{ p.customer_name ?? '—' }}</td>
              <td class="px-3 py-2 border-b border-slate-100 text-xs text-slate-500 capitalize cursor-pointer" @click="openEdit(p)">{{ p.type ?? '—' }}</td>
              <td class="px-3 py-2 border-b border-slate-100 text-xs text-slate-400 cursor-pointer" @click="openEdit(p)">{{ p.start_date ?? '—' }} → {{ p.end_date ?? '—' }}</td>
              <td class="px-3 py-2 border-b border-slate-100">
                <span v-if="p.archived_at" class="text-[10px] rounded-full px-1.5 py-0.5 bg-slate-200 text-slate-500 mr-1">archived</span>
                <span class="text-xs font-medium rounded-full px-2 py-0.5" :class="statusBadge[p.status]">{{ statusLabel[p.status] }}</span>
              </td>
              <td class="px-3 py-2 border-b border-slate-100">
                <div class="flex flex-wrap gap-1">
                  <span v-for="tag in p.tags ?? []" :key="tag" class="text-[10px] bg-airr-50 text-airr-600 rounded-full px-2 py-0.5">#{{ tag }}</span>
                </div>
              </td>
              <td class="px-3 py-2 border-b border-slate-100 text-xs text-slate-500 cursor-pointer" @click="openEdit(p)">{{ p.users_count }}u · {{ p.reports_count }}r · {{ p.templates_count }}t</td>
              <td class="px-3 py-2 border-b border-slate-100 text-right">
                <div v-if="canManage" class="relative inline-block">
                  <button @click.stop="toggleMenu(p.id)" class="text-slate-400 hover:text-slate-600 px-1 rounded hover:bg-slate-100">⋮</button>
                  <div v-if="openMenuId === p.id"
                    class="absolute right-0 top-full mt-1 w-40 bg-white border border-slate-200 rounded-xl shadow-lg z-20 py-1 text-xs text-left">
                    <button @click.stop="duplicateProject(p)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Duplicate</button>
                    <button @click.stop="exportProject(p)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Export</button>
                    <button @click.stop="openLog(p)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Log</button>
                    <button @click.stop="toggleActive(p)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">{{ p.status === 'active' ? 'Set Inactive' : 'Set Active' }}</button>
                    <div class="border-t border-slate-100 my-1"></div>
                    <button v-if="!p.archived_at" @click.stop="archiveProject(p)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Archive</button>
                    <button v-else @click.stop="unarchiveProject(p)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Unarchive</button>
                    <button @click.stop="remove(p); openMenuId = null" class="w-full text-left px-3 py-1.5 hover:bg-rose-50 text-rose-600">Delete</button>
                  </div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Deleted projects -->
      <div v-if="deletedProjects.length" class="mt-6">
        <p class="text-xs font-medium text-slate-400 mb-2">Deleted projects</p>
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          <div v-for="p in deletedProjects" :key="p.id" class="bg-slate-50 rounded-xl border border-dashed border-slate-200 p-4 flex flex-col gap-2 opacity-60">
            <div class="font-semibold text-slate-600 truncate line-through">{{ p.name }} <span class="font-mono text-xs">({{ p.code }})</span></div>
            <button @click="restore(p)" :disabled="busy" class="text-xs text-emerald-600 hover:underline font-medium self-start">Undo delete</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Create / Edit modal -->
    <div v-if="showForm" class="fixed inset-0 bg-slate-900/30 flex items-center justify-center z-50 px-4 py-8 overflow-y-auto" @click.self="showForm = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl p-6 space-y-4 my-auto">
        <h3 class="font-bold text-lg">{{ editing ? 'Edit Project' : 'New Project' }}</h3>

        <div class="flex items-center gap-4">
          <span class="w-12 h-12 rounded-xl shrink-0 inline-flex items-center justify-center text-white font-bold"
            :style="{ background: colorOf({ color: form.color, code: form.code || 'PRJ' }) }">{{ initialsOf(form.name || 'P') }}</span>
          <div class="flex flex-wrap gap-1.5">
            <button v-for="c in PALETTE" :key="c" type="button" @click="form.color = c"
              class="w-6 h-6 rounded-full border-2 transition" :style="{ background: c }"
              :class="form.color === c ? 'border-slate-800 scale-110' : 'border-white'"></button>
            <button type="button" @click="form.color = ''" title="Auto colour"
              class="w-6 h-6 rounded-full border-2 border-slate-200 text-[10px] text-slate-400 flex items-center justify-center" :class="!form.color ? 'ring-2 ring-slate-400' : ''">A</button>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-3">
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Code</label>
            <input v-model="form.code" placeholder="PRJ-001" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div class="col-span-2">
            <label class="block text-sm font-medium text-slate-600 mb-1">Name</label>
            <input v-model="form.name" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Customer name</label>
          <input v-model="form.customer_name" placeholder="e.g. Lembaga Zakat Selangor" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>

        <div class="grid grid-cols-4 gap-3">
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Type</label>
            <select v-model="form.type" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
              <option value="">— select —</option>
              <option v-for="t in projectTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Status</label>
            <select v-model="form.status" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
              <option v-for="s in STATUSES" :key="s" :value="s">{{ statusLabel[s] }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Start</label>
            <input v-model="form.start_date" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">End</label>
            <input v-model="form.end_date" type="date" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Description</label>
          <textarea v-model="form.description" rows="2" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none"></textarea>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Hashtags <span class="text-slate-400 font-normal">(comma-separated)</span></label>
          <input v-model="tagsText" placeholder="kutipan, pbt" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>

        <!-- Per-project AI override (FR-M14.5) — Standard/Enterprise only -->
        <div v-if="aiCfg?.per_project" class="rounded-lg border border-slate-200 p-4 space-y-3">
          <div>
            <div class="text-sm font-medium text-slate-600">AI models <span class="text-slate-400 font-normal">· override (optional)</span></div>
            <p class="text-xs text-slate-400">Leave blank to inherit the system default. On-premise via {{ aiCfg.provider }}.</p>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div v-for="t in aiCfg.tasks" :key="t">
              <label class="block text-xs font-medium text-slate-500 mb-1">{{ AI_TASK_LABELS[t] ?? t }}</label>
              <input v-model="form.ai_models[t]" :placeholder="aiCfg.defaults.models[t] ?? 'default'"
                class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:ring-2 focus:ring-airr-300 outline-none" />
            </div>
          </div>
        </div>

        <!-- Per-project constants — gated the same tier as the AI override above (FR-M14.5 precedent) -->
        <div v-if="aiCfg?.per_project && editing" class="rounded-lg border border-slate-200 p-4 space-y-2">
          <div class="text-sm font-medium text-slate-600">Project constants <span class="text-slate-400 font-normal">· optional</span></div>
          <ConstantsPanel scope="project" :project-id="editing.id" />
        </div>

        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Members (users) <span class="text-slate-400 font-normal">· {{ form.users.length }} selected</span></label>
            <input v-model="userSearch" type="search" placeholder="Search users…" class="w-full mb-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm focus:ring-2 focus:ring-airr-300 outline-none" />
            <div class="max-h-32 overflow-y-auto border border-slate-100 rounded-lg p-2 space-y-1">
              <label v-for="u in filteredUsers" :key="u.id" class="flex items-center gap-2 text-sm">
                <input type="checkbox" :checked="form.users.includes(u.id)" @change="toggle('users', u.id)" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" />
                <span>{{ u.name }}</span>
              </label>
              <span v-if="!filteredUsers.length" class="text-xs text-slate-400">{{ allUsers.length ? 'No match.' : 'No users available.' }}</span>
            </div>
          </div>
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button @click="showForm = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button @click="save" :disabled="busy || !form.code || !form.name"
            class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
            {{ busy ? 'Saving…' : editing ? 'Save' : 'Create Project' }}
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
        <div v-if="logBusy" class="text-slate-400 text-sm">Loading…</div>
        <div v-else-if="!logEntries.length" class="text-slate-400 text-sm">No entries yet.</div>
        <div v-else class="space-y-2 max-h-96 overflow-y-auto pr-1">
          <div v-for="e in logEntries" :key="e.id" class="border border-slate-100 rounded-lg p-3 text-xs">
            <div class="flex justify-between items-center mb-1">
              <span class="font-medium text-slate-700">{{ e.action ?? e.event }}</span>
              <span class="text-slate-400">{{ e.user_name }} · {{ new Date(e.created_at).toLocaleString() }}</span>
            </div>
            <pre v-if="e.properties && Object.keys(e.properties).length" class="text-[10px] text-slate-500 whitespace-pre-wrap break-all">{{ JSON.stringify(e.properties, null, 2) }}</pre>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
