<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { apiRequest, ApiException } from '../../api/client'

type Group = { id: number; name: string; description: string | null; users_count: number }

const groups = ref<Group[]>([])
const loading = ref(true)
const error = ref('')
const busy = ref(false)

const showForm = ref(false)
const editing = ref<Group | null>(null)
const form = ref({ name: '', description: '' })

async function load() {
  loading.value = true
  try {
    groups.value = (await apiRequest<{ data: Group[] }>('/user-groups')).data
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load groups'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editing.value = null
  form.value = { name: '', description: '' }
  showForm.value = true
}
function openEdit(g: Group) {
  editing.value = g
  form.value = { name: g.name, description: g.description ?? '' }
  showForm.value = true
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    const path = editing.value ? `/user-groups/${editing.value.id}` : '/user-groups'
    await apiRequest(path, { method: editing.value ? 'PUT' : 'POST', body: JSON.stringify(form.value) })
    showForm.value = false
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    busy.value = false
  }
}

async function remove(g: Group) {
  if (!confirm(`Delete group "${g.name}"? Members will be ungrouped.`)) return
  busy.value = true
  try {
    await apiRequest(`/user-groups/${g.id}`, { method: 'DELETE' })
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
      <p class="text-sm text-slate-500">Organisational groups (e.g. departments/teams) assignable to users.</p>
      <button @click="openCreate" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5">+ New Group</button>
    </div>

    <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>

    <div class="bg-white rounded-xl border border-slate-100 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-left">
          <tr>
            <th class="px-4 py-3 font-medium">Name</th>
            <th class="px-4 py-3 font-medium">Description</th>
            <th class="px-4 py-3 font-medium">Members</th>
            <th class="px-4 py-3 font-medium text-right">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading"><td colspan="4" class="px-4 py-8 text-center text-slate-400">Loading…</td></tr>
          <tr v-else-if="!groups.length"><td colspan="4" class="px-4 py-8 text-center text-slate-400">No groups yet.</td></tr>
          <tr v-for="g in groups" :key="g.id" class="hover:bg-slate-50">
            <td class="px-4 py-3 font-medium text-slate-700">{{ g.name }}</td>
            <td class="px-4 py-3 text-slate-500">{{ g.description ?? '—' }}</td>
            <td class="px-4 py-3 text-slate-500">{{ g.users_count }}</td>
            <td class="px-4 py-3 text-right whitespace-nowrap">
              <button @click="openEdit(g)" class="text-xs text-airr-600 hover:underline">Edit</button>
              <button @click="remove(g)" :disabled="busy" class="text-xs text-rose-600 hover:underline ml-3">Delete</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Form modal -->
    <div v-if="showForm" class="fixed inset-0 bg-slate-900/30 flex items-center justify-center z-50 px-4" @click.self="showForm = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6 space-y-4">
        <h3 class="font-bold text-lg">{{ editing ? 'Edit Group' : 'New Group' }}</h3>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Name</label>
          <input v-model="form.name" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Description</label>
          <input v-model="form.description" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
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
