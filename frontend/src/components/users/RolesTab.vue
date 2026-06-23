<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { apiRequest, ApiException } from '../../api/client'

type Role = {
  id: number
  slug: string
  name: string
  description: string | null
  clearance: number
  is_system: boolean
  permissions_count: number
  users_count: number
}

const roles = ref<Role[]>([])
const loading = ref(true)
const error = ref('')
const busy = ref(false)

const showForm = ref(false)
const editing = ref<Role | null>(null)
const form = ref({ name: '', slug: '', description: '', clearance: 10 })

async function load() {
  loading.value = true
  try {
    roles.value = (await apiRequest<{ data: Role[] }>('/roles')).data
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load roles'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editing.value = null
  form.value = { name: '', slug: '', description: '', clearance: 10 }
  showForm.value = true
}
function openEdit(r: Role) {
  editing.value = r
  form.value = { name: r.name, slug: r.slug, description: r.description ?? '', clearance: r.clearance }
  showForm.value = true
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    const path = editing.value ? `/roles/${editing.value.id}` : '/roles'
    await apiRequest(path, { method: editing.value ? 'PUT' : 'POST', body: JSON.stringify(form.value) })
    showForm.value = false
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    busy.value = false
  }
}

async function remove(r: Role) {
  if (!confirm(`Delete role "${r.name}"?`)) return
  busy.value = true
  error.value = ''
  try {
    await apiRequest(`/roles/${r.id}`, { method: 'DELETE' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Delete failed'
  } finally {
    busy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-4">
    <div class="flex justify-between items-center">
      <p class="text-sm text-slate-500">Roles bundle permissions. Permission assignment will be added once Authoring & Data Source permissions exist.</p>
      <button @click="openCreate" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5">+ New Role</button>
    </div>

    <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>

    <div class="bg-white rounded-xl border border-slate-100 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-left">
          <tr>
            <th class="px-4 py-3 font-medium">Role</th>
            <th class="px-4 py-3 font-medium">Clearance</th>
            <th class="px-4 py-3 font-medium">Users</th>
            <th class="px-4 py-3 font-medium text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading"><td colspan="4" class="px-4 py-8 text-center text-slate-400">Loading…</td></tr>
          <tr v-else-if="!roles.length"><td colspan="4" class="px-4 py-8 text-center text-slate-400">No roles yet.</td></tr>
          <tr v-for="r in roles" :key="r.id" class="hover:bg-slate-50">
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <span class="font-medium text-slate-700">{{ r.name }}</span>
                <span v-if="r.is_system" class="text-[10px] uppercase tracking-wide bg-slate-100 text-slate-400 rounded px-1.5 py-0.5">system</span>
              </div>
              <div class="text-slate-400 text-xs font-mono">{{ r.slug }}</div>
              <div v-if="r.description" class="text-slate-400 text-xs mt-0.5">{{ r.description }}</div>
            </td>
            <td class="px-4 py-3 text-slate-500">{{ r.clearance }}</td>
            <td class="px-4 py-3 text-slate-500">{{ r.users_count }}</td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <button @click="openEdit(r)" class="text-xs text-airr-600 hover:underline">Edit</button>
              <button @click="remove(r)" :disabled="busy || r.is_system" :title="r.is_system ? 'System roles cannot be deleted' : ''"
                class="text-xs text-rose-600 hover:underline ml-3 disabled:text-slate-300 disabled:no-underline disabled:cursor-not-allowed">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Form modal -->
    <div v-if="showForm" class="fixed inset-0 bg-slate-900/30 flex items-center justify-center z-50 px-4" @click.self="showForm = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6 space-y-4">
        <h3 class="font-bold text-lg">{{ editing ? 'Edit Role' : 'New Role' }}</h3>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Name</label>
          <input v-model="form.name" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Slug <span class="text-slate-400 font-normal">(lowercase, a–z, 0–9, hyphen)</span></label>
          <input v-model="form.slug" :disabled="!!editing && editing.is_system" placeholder="auto from name if blank"
            class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none disabled:bg-slate-50 disabled:text-slate-400" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Description</label>
          <input v-model="form.description" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Clearance <span class="text-slate-400 font-normal">(0–1000; higher = more access)</span></label>
          <input v-model.number="form.clearance" type="number" min="0" max="1000" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div class="flex justify-end gap-2 pt-2">
          <button @click="showForm = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button @click="save" :disabled="busy || !form.name" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
            {{ busy ? 'Saving…' : 'Save' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
