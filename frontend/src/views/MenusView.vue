<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, ApiException } from '../api/client'

type Menu = {
  id: number; parent_id: number | null; label: string; route: string | null
  icon: string | null; module: string | null; permission: string | null
  feature: string | null; user_types: string[]; sort: number; is_active: boolean; auto_collapse: boolean
}

// User types sourced from the lookup (Settings → Lookup), not hardcoded.
const USER_TYPES = ref<{ value: string; label: string }[]>([])

const menus = ref<Menu[]>([])
const loading = ref(true)
const error = ref('')
const busy = ref(false)

const parents = computed(() => menus.value.filter((m) => m.parent_id === null && !m.route))
const parentLabel = (id: number | null) => (id === null ? '—' : menus.value.find((m) => m.id === id)?.label ?? '—')

// Ordered for display: each top-level then its children.
const ordered = computed(() => {
  const tops = menus.value.filter((m) => m.parent_id === null).sort((a, b) => a.sort - b.sort)
  const out: Menu[] = []
  for (const t of tops) {
    out.push(t)
    out.push(...menus.value.filter((m) => m.parent_id === t.id).sort((a, b) => a.sort - b.sort))
  }
  return out
})

const showForm = ref(false)
const editing = ref<Menu | null>(null)
const blank = (): Omit<Menu, 'id'> => ({
  parent_id: null, label: '', route: '', icon: '', module: '', permission: '', feature: '',
  user_types: [], sort: 0, is_active: true, auto_collapse: false,
})
function toggleType(t: string) {
  form.value.user_types = form.value.user_types.includes(t)
    ? form.value.user_types.filter((x) => x !== t)
    : [...form.value.user_types, t]
}
const form = ref<Omit<Menu, 'id'>>(blank())

async function load() {
  loading.value = true
  error.value = ''
  try {
    menus.value = (await apiRequest<{ data: Menu[] }>('/menus')).data
    if (!USER_TYPES.value.length) {
      USER_TYPES.value = (await apiRequest<{ data: { value: string; label: string }[] }>('/lookups?category=user_type')).data
    }
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load menus'
  } finally {
    loading.value = false
  }
}

function openCreate() { editing.value = null; form.value = blank(); showForm.value = true }
function openEdit(m: Menu) {
  editing.value = m
  form.value = { ...m, route: m.route ?? '', icon: m.icon ?? '', module: m.module ?? '', permission: m.permission ?? '', feature: m.feature ?? '', user_types: [...(m.user_types ?? [])] }
  showForm.value = true
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    const body = {
      ...form.value,
      route: form.value.route || null, icon: form.value.icon || null, module: form.value.module || null,
      permission: form.value.permission || null, feature: form.value.feature || null,
    }
    const path = editing.value ? `/menus/${editing.value.id}` : '/menus'
    await apiRequest(path, { method: editing.value ? 'PUT' : 'POST', body: JSON.stringify(body) })
    showForm.value = false
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    busy.value = false
  }
}

async function remove(m: Menu) {
  if (!confirm(`Delete menu "${m.label}"? Children become top-level.`)) return
  try {
    await apiRequest(`/menus/${m.id}`, { method: 'DELETE' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Delete failed'
  }
}

onMounted(load)
</script>

<template>
  <AdminLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-lg font-semibold text-slate-800">Menu</h1>
          <p class="text-sm text-slate-400">DB-driven navigation. Visibility follows each item's permission & edition feature.</p>
        </div>
        <button @click="openCreate" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2">+ New Menu Item</button>
      </div>

      <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>
      <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>

      <div v-else class="bg-white rounded-xl border border-slate-100 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-left">
            <tr>
              <th class="px-4 py-3 font-medium">Label</th>
              <th class="px-4 py-3 font-medium">Route</th>
              <th class="px-4 py-3 font-medium">Permission</th>
              <th class="px-4 py-3 font-medium">Feature</th>
              <th class="px-4 py-3 font-medium">Active</th>
              <th class="px-4 py-3 font-medium text-right">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-for="m in ordered" :key="m.id" class="hover:bg-slate-50">
              <td class="px-4 py-2.5">
                <span :class="m.parent_id ? 'pl-5 text-slate-600' : 'font-semibold text-slate-700'">{{ m.label }}</span>
                <span v-if="!m.route" class="ml-2 text-[10px] uppercase text-slate-400">group</span>
              </td>
              <td class="px-4 py-2.5 text-slate-500">{{ m.route ?? '—' }}</td>
              <td class="px-4 py-2.5"><code class="text-xs text-slate-500">{{ m.permission ?? '—' }}</code></td>
              <td class="px-4 py-2.5"><code class="text-xs text-slate-500">{{ m.feature ?? '—' }}</code></td>
              <td class="px-4 py-2.5"><span :class="m.is_active ? 'text-emerald-500' : 'text-slate-300'">{{ m.is_active ? '✓' : '✕' }}</span></td>
              <td class="px-4 py-2.5 text-right whitespace-nowrap">
                <button @click="openEdit(m)" class="text-xs text-airr-600 hover:underline mr-3">Edit</button>
                <button @click="remove(m)" class="text-xs text-rose-500 hover:underline">Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Form modal -->
    <div v-if="showForm" class="fixed inset-0 bg-black/30 flex items-center justify-center p-4 z-50" @click.self="showForm = false">
      <div class="bg-white rounded-xl p-6 w-full max-w-md space-y-3">
        <h2 class="font-semibold text-slate-800">{{ editing ? 'Edit' : 'New' }} menu item</h2>
        <div class="grid grid-cols-2 gap-3">
          <div class="col-span-2">
            <label class="block text-xs font-medium text-slate-500 mb-1">Label</label>
            <input v-model="form.label" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Parent group</label>
            <select v-model="form.parent_id" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none">
              <option :value="null">— top level —</option>
              <option v-for="p in parents" :key="p.id" :value="p.id">{{ p.label }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Route name <span class="text-slate-300">(blank = group)</span></label>
            <input v-model="form.route" placeholder="reports" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Icon (lucide)</label>
            <input v-model="form.icon" placeholder="FileText" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Module</label>
            <input v-model="form.module" placeholder="M6" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Permission</label>
            <input v-model="form.permission" placeholder="reports.view" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Feature flag</label>
            <input v-model="form.feature" placeholder="multi_agent" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-500 mb-1">Sort</label>
            <input v-model.number="form.sort" type="number" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div class="col-span-2">
            <label class="block text-xs font-medium text-slate-500 mb-1">User types with access <span class="text-slate-300">(none = all types)</span></label>
            <div class="flex gap-4">
              <label v-for="t in USER_TYPES" :key="t.value" class="flex items-center gap-1.5 text-sm text-slate-600">
                <input type="checkbox" :checked="form.user_types.includes(t.value)" @change="toggleType(t.value)" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" />
                {{ t.label }}
              </label>
            </div>
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" v-model="form.is_active" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /> Active
          </label>
          <label class="flex items-center gap-2 text-sm text-slate-600" title="Minimise the sidebar to icons when this item is active (authoring)">
            <input type="checkbox" v-model="form.auto_collapse" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /> Auto-collapse sidebar
          </label>
        </div>
        <div class="flex justify-end gap-2 pt-1">
          <button @click="showForm = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button @click="save" :disabled="busy || !form.label" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">{{ busy ? 'Saving…' : 'Save' }}</button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
