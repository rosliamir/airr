<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, ApiException } from '../api/client'
import { useAuthStore } from '../stores/auth'

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
}

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
const allUsers = ref<Member[]>([])
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
    const reqs: [Promise<{ data: Project[] }>, Promise<{ data: Member[] }>?] = [
      apiRequest<{ data: Project[] }>('/projects'),
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
  showForm.value = true
}
async function openEdit(p: Project) {
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
    const body = {
      ...rest,
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

async function loadAiCfg() {
  if (!auth.can('settings.manage')) return
  try {
    aiCfg.value = (await apiRequest<{ data: typeof aiCfg.value }>('/ai/config')).data
  } catch { /* non-fatal: AI override section simply hidden */ }
}

onMounted(() => {
  load()
  loadAiCfg()
})
</script>

<template>
  <AdminLayout>
    <div class="space-y-4">
      <div class="flex items-center justify-between">
        <p class="text-sm text-slate-500">Projects scope access and store reports. Assign users for membership.</p>
        <button v-if="canManage" @click="openCreate" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5">+ New Project</button>
      </div>

      <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>

      <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>
        <div v-else-if="!projects.length" class="text-slate-400 text-sm">No projects yet.</div>
        <div v-for="p in projects" :key="p.id" class="bg-white rounded-xl border border-slate-100 p-4 flex flex-col gap-2"
          :style="{ borderLeft: `4px solid ${colorOf(p)}` }">
          <div class="flex items-start justify-between gap-2">
            <div class="flex items-center gap-2.5 min-w-0">
              <span class="w-9 h-9 rounded-lg shrink-0 inline-flex items-center justify-center text-white text-xs font-bold"
                :style="{ background: colorOf(p) }">{{ initialsOf(p.name) }}</span>
              <div class="min-w-0">
                <div class="font-semibold text-slate-700 truncate">{{ p.name }}</div>
                <div class="text-xs text-slate-400 font-mono">{{ p.code }}</div>
              </div>
            </div>
            <span class="text-xs font-medium rounded-full px-2 py-0.5 shrink-0" :class="statusBadge[p.status]">{{ statusLabel[p.status] }}</span>
          </div>
          <div v-if="p.customer_name" class="text-xs text-slate-500">👤 {{ p.customer_name }}</div>
          <div v-if="p.type" class="text-xs text-slate-500 capitalize">{{ p.type }}</div>
          <div class="text-xs text-slate-400">
            {{ p.start_date ?? '—' }} → {{ p.end_date ?? '—' }}
          </div>
          <div class="text-xs text-slate-500">{{ p.users_count }} users · {{ p.reports_count }} reports · {{ p.templates_count }} templates</div>
          <div v-if="canManage" class="flex gap-3 pt-1 mt-auto">
            <button @click="openEdit(p)" class="text-xs text-airr-600 hover:underline">Edit</button>
            <button @click="remove(p)" :disabled="busy" class="text-xs text-rose-600 hover:underline">Delete</button>
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
            <input v-model="form.type" placeholder="development" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
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
  </AdminLayout>
</template>
