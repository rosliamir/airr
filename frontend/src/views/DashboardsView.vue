<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, ApiException } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useListViewMode } from '../composables/useListViewMode'
import ViewModeToggle from '../components/ViewModeToggle.vue'

type Dashboard = {
  id: number; name: string; description?: string | null
  definition?: { widgets: unknown[] } | null
  status?: string; created_at?: string; updated_at?: string
}

const auth = useAuthStore()
const canCreate = auth.can('dashboards.create')
const canEdit = auth.can('dashboards.edit')

const router = useRouter()
const dashboards = ref<Dashboard[]>([])
const loading = ref(true)
const error = ref('')

const showCreate = ref(false)
const createForm = ref({ name: '', description: '' })
const createBusy = ref(false)

const { viewMode } = useListViewMode('dashboards')

async function load() {
  loading.value = true
  try {
    dashboards.value = (await apiRequest<{ data: Dashboard[] }>('/dashboards')).data
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load dashboards'
  } finally {
    loading.value = false
  }
}

async function createDashboard() {
  if (!createForm.value.name.trim()) return
  createBusy.value = true
  try {
    const res = await apiRequest<{ data: Dashboard }>('/dashboards', {
      method: 'POST', body: JSON.stringify({ name: createForm.value.name, description: createForm.value.description || undefined }),
    })
    showCreate.value = false
    router.push(`/dashboards/${res.data.id}`)
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Create failed'
  } finally {
    createBusy.value = false
  }
}

async function deleteDashboard(d: Dashboard) {
  if (!confirm(`Delete "${d.name}"?`)) return
  try {
    await apiRequest(`/dashboards/${d.id}`, { method: 'DELETE' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Delete failed'
  }
}

onMounted(load)
</script>

<template>
  <AdminLayout>
    <div class="max-w-6xl mx-auto space-y-4">
      <div class="flex items-center justify-between flex-wrap gap-2">
        <div>
          <h1 class="text-lg font-bold text-slate-800">Dashboards</h1>
          <p class="text-xs text-slate-400">Compose grids of reports, notes, tasks, and announcements.</p>
        </div>
        <div class="flex items-center gap-2">
          <ViewModeToggle v-model="viewMode" />
          <button v-if="canCreate" @click="showCreate = true; createForm = { name: '', description: '' }"
            class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5">+ New Dashboard</button>
        </div>
      </div>

      <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>
      <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>
      <div v-else-if="!dashboards.length" class="text-slate-400 text-sm">No dashboards yet.</div>
      <div v-else-if="viewMode === 'card'" class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        <div v-for="d in dashboards" :key="d.id" class="relative bg-white rounded-xl border border-slate-100 p-4 flex flex-col gap-2 hover:border-slate-200 transition">
          <div class="flex items-start justify-between gap-2">
            <router-link :to="`/dashboards/${d.id}`" class="min-w-0">
              <div class="font-semibold text-slate-700 truncate">{{ d.name }}</div>
            </router-link>
            <button v-if="canEdit" @click="deleteDashboard(d)" class="text-slate-400 hover:text-rose-600 px-1 rounded hover:bg-rose-50 shrink-0" title="Delete">×</button>
          </div>
          <p v-if="d.description" class="text-xs text-slate-500 line-clamp-2">{{ d.description }}</p>
          <div class="text-xs text-slate-400 mt-auto">
            {{ d.updated_at ? new Date(d.updated_at).toLocaleString() : '' }}
          </div>
          <router-link :to="`/dashboards/${d.id}`" class="text-xs font-medium text-airr-600 hover:underline">Open →</router-link>
        </div>
      </div>

      <!-- Tabular listing — same fields/actions as the cards above, one row per dashboard -->
      <div v-else class="overflow-x-auto border border-slate-100 rounded-xl">
        <table class="w-full text-sm border-collapse">
          <thead>
            <tr class="bg-slate-50 text-left text-xs text-slate-500">
              <th class="px-3 py-2 border-b border-slate-200">Name</th>
              <th class="px-3 py-2 border-b border-slate-200">Description</th>
              <th class="px-3 py-2 border-b border-slate-200">Updated</th>
              <th class="px-3 py-2 border-b border-slate-200 w-8"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="d in dashboards" :key="d.id" class="hover:bg-slate-50 transition">
              <td class="px-3 py-2 border-b border-slate-100">
                <router-link :to="`/dashboards/${d.id}`" class="font-semibold text-slate-700 hover:underline">{{ d.name }}</router-link>
              </td>
              <td class="px-3 py-2 border-b border-slate-100 text-xs text-slate-500 line-clamp-1">{{ d.description }}</td>
              <td class="px-3 py-2 border-b border-slate-100 text-xs text-slate-400">
                {{ d.updated_at ? new Date(d.updated_at).toLocaleString() : '' }}
              </td>
              <td class="px-3 py-2 border-b border-slate-100 text-right">
                <button v-if="canEdit" @click="deleteDashboard(d)" class="text-slate-400 hover:text-rose-600 px-1 rounded hover:bg-rose-50" title="Delete">×</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="showCreate" class="fixed inset-0 bg-black/30 flex items-center justify-center p-4 z-50" @click.self="showCreate = false">
      <div class="bg-white rounded-xl p-6 w-full max-w-md space-y-4">
        <h2 class="font-semibold text-slate-800">New Dashboard</h2>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Name</label>
          <input v-model="createForm.name" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Description <span class="text-slate-400 font-normal">· optional</span></label>
          <textarea v-model="createForm.description" rows="2" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none"></textarea>
        </div>
        <div class="flex justify-end gap-2 pt-1">
          <button @click="showCreate = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button @click="createDashboard" :disabled="createBusy || !createForm.name" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
            {{ createBusy ? 'Creating…' : 'Create' }}
          </button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
