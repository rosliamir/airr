<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest } from '../api/client'

type AuditRow = {
  id: number
  action: string
  object_type: string | null
  object_id: string | null
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  ip: string | null
  created_at: string
  user: { name: string; email: string } | null
}

const rows = ref<AuditRow[]>([])
const loading = ref(true)
const search = ref('')
const page = ref(1)
const totalPages = ref(1)
const total = ref(0)
const openId = ref<number | null>(null)
const PER_PAGE = 10

let debounce: ReturnType<typeof setTimeout>

async function load() {
  loading.value = true
  try {
    const params = new URLSearchParams({ page: String(page.value), limit: String(PER_PAGE) })
    if (search.value.trim()) params.set('q', search.value.trim())
    const res = await apiRequest<{ data: AuditRow[]; meta: { totalPages: number; total: number } }>(`/audit?${params}`)
    rows.value = res.data
    totalPages.value = res.meta.totalPages
    total.value = res.meta.total
  } finally {
    loading.value = false
  }
}

watch(search, () => {
  clearTimeout(debounce)
  debounce = setTimeout(() => {
    page.value = 1
    load()
  }, 300)
})
watch(page, load)

function go(p: number) {
  if (p >= 1 && p <= totalPages.value) page.value = p
}
function toggle(id: number) {
  openId.value = openId.value === id ? null : id
}

onMounted(load)
</script>

<template>
  <AdminLayout>
    <div class="space-y-4">
      <div class="flex items-center justify-between gap-3">
        <input v-model="search" type="search" placeholder="Search action, object, or user…"
          class="w-72 max-w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none" />
        <span class="text-xs text-slate-400">{{ total }} entries</span>
      </div>

      <div class="bg-white rounded-xl border border-slate-100 overflow-hidden">
        <table class="w-full text-sm">
          <thead class="bg-slate-50 text-slate-500 text-left">
            <tr>
              <th class="px-4 py-3 font-medium w-8"></th>
              <th class="px-4 py-3 font-medium">Action</th>
              <th class="px-4 py-3 font-medium">User</th>
              <th class="px-4 py-3 font-medium">Object</th>
              <th class="px-4 py-3 font-medium">IP</th>
              <th class="px-4 py-3 font-medium">When</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr v-if="loading"><td colspan="6" class="px-4 py-8 text-center text-slate-400">Loading…</td></tr>
            <tr v-else-if="!rows.length"><td colspan="6" class="px-4 py-8 text-center text-slate-400">No audit entries.</td></tr>
            <template v-for="r in rows" :key="r.id">
              <tr class="hover:bg-slate-50 cursor-pointer" @click="toggle(r.id)">
                <td class="px-4 py-2.5 text-slate-400">{{ openId === r.id ? '▾' : '▸' }}</td>
                <td class="px-4 py-2.5 font-mono text-xs">{{ r.action }}</td>
                <td class="px-4 py-2.5">{{ r.user?.name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-slate-500">{{ r.object_type ? `${r.object_type.split('\\').pop()} #${r.object_id}` : '—' }}</td>
                <td class="px-4 py-2.5 text-slate-400">{{ r.ip }}</td>
                <td class="px-4 py-2.5 text-slate-400">{{ new Date(r.created_at).toLocaleString() }}</td>
              </tr>
              <tr v-if="openId === r.id" class="bg-slate-50/70">
                <td colspan="6" class="px-6 py-3">
                  <div class="grid grid-cols-2 gap-4 text-xs">
                    <div>
                      <div class="text-slate-400 mb-1">Old values</div>
                      <pre class="bg-white border border-slate-100 rounded-lg p-2 overflow-x-auto">{{ r.old_values ? JSON.stringify(r.old_values, null, 2) : '—' }}</pre>
                    </div>
                    <div>
                      <div class="text-slate-400 mb-1">New values</div>
                      <pre class="bg-white border border-slate-100 rounded-lg p-2 overflow-x-auto">{{ r.new_values ? JSON.stringify(r.new_values, null, 2) : '—' }}</pre>
                    </div>
                  </div>
                  <div class="text-slate-400 mt-2">{{ r.user?.email }}</div>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="totalPages > 1" class="flex items-center justify-end gap-1">
        <button @click="go(page - 1)" :disabled="page === 1"
          class="px-2.5 py-1.5 text-sm rounded-lg border border-slate-200 disabled:opacity-40 hover:bg-slate-50">Prev</button>
        <span class="px-3 text-sm text-slate-500">Page {{ page }} / {{ totalPages }}</span>
        <button @click="go(page + 1)" :disabled="page === totalPages"
          class="px-2.5 py-1.5 text-sm rounded-lg border border-slate-200 disabled:opacity-40 hover:bg-slate-50">Next</button>
      </div>
    </div>
  </AdminLayout>
</template>
