<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, ApiException } from '../api/client'
import { useAuthStore } from '../stores/auth'

type Ref2 = { id: number; name: string; code?: string }
type Grant = { role_id: number; name?: string; view: boolean; edit: boolean; run: boolean }
type Report = {
  id: number; name: string; description?: string; type: string; status: string
  project?: Ref2 | null; dataset?: Ref2 | null; definition?: Record<string, unknown>
  permissions?: Grant[]
}

const auth = useAuthStore()
const canCreate = auth.can('reports.create')
const canEdit = auth.can('reports.edit')
const canRun = auth.can('reports.run')

const TYPES = ['table', 'grouped', 'kpi', 'matrix', 'chart', 'document']

const reports = ref<Report[]>([])
const datasets = ref<Ref2[]>([])
const roles = ref<{ id: number; name: string }[]>([])
const perms = ref<Record<number, { view: boolean; edit: boolean; run: boolean }>>({})
const selected = ref<Report | null>(null)
const defText = ref('')          // JSON definition editor
const preview = ref('')          // rendered HTML
const rowCount = ref<number | null>(null)
const loading = ref(true)
const busy = ref(false)
const error = ref('')

const showForm = ref(false)
const form = ref({ name: '', type: 'table', dataset_id: null as number | null })

async function load() {
  loading.value = true
  error.value = ''
  try {
    reports.value = (await apiRequest<{ data: Report[] }>('/reports')).data
    if (!roles.value.length) {
      roles.value = (await apiRequest<{ data: { id: number; name: string }[] }>('/roles/options')).data
    }
    if (auth.can('datasources.view') && !datasets.value.length) {
      const ds = await apiRequest<{ data: { id: number; datasets_count: number }[] }>('/data-sources')
      // flatten datasets from each source
      const all: Ref2[] = []
      for (const s of ds.data) {
        const list = await apiRequest<{ data: Ref2[] }>(`/data-sources/${s.id}/datasets`)
        all.push(...list.data)
      }
      datasets.value = all
    }
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load reports'
  } finally {
    loading.value = false
  }
}

async function openReport(r: Report) {
  preview.value = ''
  rowCount.value = null
  try {
    selected.value = (await apiRequest<{ data: Report }>(`/reports/${r.id}`)).data
    defText.value = JSON.stringify(selected.value.definition ?? {}, null, 2)
    // Seed the role grant matrix from the report's ACL.
    const map: Record<number, { view: boolean; edit: boolean; run: boolean }> = {}
    for (const g of selected.value.permissions ?? []) map[g.role_id] = { view: g.view, edit: g.edit, run: g.run }
    perms.value = map
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to open report'
  }
}

async function create() {
  busy.value = true
  error.value = ''
  try {
    const r = await apiRequest<{ data: Report }>('/reports', {
      method: 'POST',
      body: JSON.stringify({ name: form.value.name, type: form.value.type, dataset_id: form.value.dataset_id }),
    })
    showForm.value = false
    await load()
    await openReport(r.data)
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Create failed'
  } finally {
    busy.value = false
  }
}

async function saveDefinition() {
  if (!selected.value) return
  busy.value = true
  error.value = ''
  try {
    let definition: unknown
    try { definition = JSON.parse(defText.value) } catch { throw new Error('Definition is not valid JSON') }
    const permissions = Object.entries(perms.value)
      .filter(([, v]) => v.view || v.edit || v.run)
      .map(([role_id, v]) => ({ role_id: Number(role_id), ...v }))
    const r = await apiRequest<{ data: Report }>(`/reports/${selected.value.id}`, {
      method: 'PUT',
      body: JSON.stringify({ name: selected.value.name, type: (definition as { type?: string }).type ?? selected.value.type, definition, permissions }),
    })
    selected.value = r.data
    await load()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Save failed'
  } finally {
    busy.value = false
  }
}

function grant(roleId: number) {
  return perms.value[roleId] ?? { view: false, edit: false, run: false }
}
function toggleGrant(roleId: number, field: 'view' | 'edit' | 'run') {
  const cur = grant(roleId)
  perms.value = { ...perms.value, [roleId]: { ...cur, [field]: !cur[field] } }
}

async function run() {
  if (!selected.value) return
  busy.value = true
  error.value = ''
  try {
    const res = await apiRequest<{ data: { html: string; row_count: number } }>(`/reports/${selected.value.id}/run`, {
      method: 'POST', body: JSON.stringify({ params: {} }),
    })
    preview.value = res.data.html
    rowCount.value = res.data.row_count
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Run failed'
  } finally {
    busy.value = false
  }
}

async function removeReport(r: Report) {
  if (!confirm(`Delete report "${r.name}"?`)) return
  try {
    await apiRequest(`/reports/${r.id}`, { method: 'DELETE' })
    if (selected.value?.id === r.id) selected.value = null
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
          <h1 class="text-lg font-semibold text-slate-800">Reports</h1>
          <p class="text-sm text-slate-400">Define a report (JSON) bound to a dataset, then run to render.</p>
        </div>
        <button v-if="canCreate" @click="showForm = true; form = { name: '', type: 'table', dataset_id: null }"
          class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2">+ New Report</button>
      </div>

      <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>
      <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>

      <div v-else class="grid grid-cols-3 gap-5">
        <!-- list -->
        <div class="col-span-1 space-y-2">
          <div v-if="!reports.length" class="text-sm text-slate-400 border border-dashed border-slate-200 rounded-xl p-6 text-center">No reports yet.</div>
          <button v-for="r in reports" :key="r.id" @click="openReport(r)"
            class="w-full text-left bg-white rounded-xl border p-4 transition hover:border-airr-300"
            :class="selected?.id === r.id ? 'border-airr-400 ring-1 ring-airr-200' : 'border-slate-100'">
            <div class="flex items-center justify-between">
              <span class="font-medium text-slate-700">{{ r.name }}</span>
              <span class="text-[10px] uppercase bg-slate-100 text-slate-500 rounded-full px-2 py-0.5">{{ r.type }}</span>
            </div>
            <div class="text-xs text-slate-400 mt-1">{{ r.dataset?.name ?? 'no dataset' }}</div>
          </button>
        </div>

        <!-- editor + preview -->
        <div class="col-span-2">
          <div v-if="!selected" class="text-sm text-slate-400 border border-dashed border-slate-200 rounded-xl p-10 text-center">Select a report to edit & run.</div>
          <div v-else class="space-y-4">
            <div class="bg-white rounded-xl border border-slate-100 p-5 space-y-3">
              <div class="flex items-center justify-between">
                <div>
                  <h2 class="font-semibold text-slate-800">{{ selected.name }}</h2>
                  <p class="text-xs text-slate-400">Dataset: {{ selected.dataset?.name ?? '— none —' }}</p>
                </div>
                <div class="flex gap-2">
                  <button v-if="canRun" @click="run" :disabled="busy" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5 disabled:opacity-50">{{ busy ? '…' : 'Run' }}</button>
                  <button v-if="canEdit" @click="removeReport(selected)" class="text-xs text-rose-500 hover:underline px-2">Delete</button>
                </div>
              </div>
              <div v-if="canEdit">
                <label class="block text-xs font-medium text-slate-500 mb-1">Definition (JSON)</label>
                <textarea v-model="defText" rows="10" spellcheck="false"
                  class="w-full font-mono text-xs rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none"></textarea>
              </div>

              <!-- Per-report access (FR-M6 ACL) -->
              <div v-if="canEdit && roles.length" class="border-t border-slate-100 pt-3">
                <label class="block text-xs font-medium text-slate-500 mb-2">Access — which roles can use this report</label>
                <table class="w-full text-sm">
                  <thead>
                    <tr class="text-[11px] text-slate-400 text-left">
                      <th class="font-medium py-1">Role</th>
                      <th class="font-medium w-14 text-center">View</th>
                      <th class="font-medium w-14 text-center">Run</th>
                      <th class="font-medium w-14 text-center">Edit</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="role in roles" :key="role.id" class="border-t border-slate-50">
                      <td class="py-1.5 text-slate-600">{{ role.name }}</td>
                      <td class="text-center"><input type="checkbox" :checked="grant(role.id).view" @change="toggleGrant(role.id, 'view')" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /></td>
                      <td class="text-center"><input type="checkbox" :checked="grant(role.id).run" @change="toggleGrant(role.id, 'run')" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /></td>
                      <td class="text-center"><input type="checkbox" :checked="grant(role.id).edit" @change="toggleGrant(role.id, 'edit')" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /></td>
                    </tr>
                  </tbody>
                </table>
                <p class="text-[11px] text-slate-400 mt-1.5">No roles ticked = private to you (creator) and admins.</p>
              </div>

              <button @click="saveDefinition" :disabled="busy" class="mt-3 text-sm font-medium text-airr-600 border border-airr-200 hover:bg-airr-50 rounded-lg px-3 py-1.5">Save definition &amp; access</button>
            </div>

            <div v-if="preview" class="bg-white rounded-xl border border-slate-100 p-5">
              <div class="text-xs text-slate-400 mb-3">Preview · {{ rowCount }} rows</div>
              <div v-html="preview"></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Create modal -->
    <div v-if="showForm" class="fixed inset-0 bg-black/30 flex items-center justify-center p-4 z-50" @click.self="showForm = false">
      <div class="bg-white rounded-xl p-6 w-full max-w-md space-y-4">
        <h2 class="font-semibold text-slate-800">New Report</h2>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Name</label>
          <input v-model="form.name" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Type</label>
          <select v-model="form.type" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
            <option v-for="t in TYPES" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Dataset <span class="text-slate-400 font-normal">· optional</span></label>
          <select v-model="form.dataset_id" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
            <option :value="null">— none —</option>
            <option v-for="d in datasets" :key="d.id" :value="d.id">{{ d.name }}</option>
          </select>
        </div>
        <div class="flex justify-end gap-2 pt-1">
          <button @click="showForm = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button @click="create" :disabled="busy || !form.name" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">{{ busy ? 'Creating…' : 'Create' }}</button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
