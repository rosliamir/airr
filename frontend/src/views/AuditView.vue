<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest } from '../api/client'

type AuditRow = {
  id: number
  action: string
  object_type: string | null
  object_id: string | null
  ip: string | null
  created_at: string
  user: { name: string; email: string } | null
}

const rows = ref<AuditRow[]>([])
const loading = ref(true)

onMounted(async () => {
  try {
    const res = await apiRequest<{ data: AuditRow[] }>('/audit?limit=50')
    rows.value = res.data
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <AdminLayout>
    <div class="bg-white rounded-xl border border-slate-100 overflow-hidden">
      <table class="w-full text-sm">
        <thead class="bg-slate-50 text-slate-500 text-left">
          <tr>
            <th class="px-4 py-3 font-medium">Action</th>
            <th class="px-4 py-3 font-medium">User</th>
            <th class="px-4 py-3 font-medium">Object</th>
            <th class="px-4 py-3 font-medium">IP</th>
            <th class="px-4 py-3 font-medium">When</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-if="loading"><td colspan="5" class="px-4 py-8 text-center text-slate-400">Loading…</td></tr>
          <tr v-else-if="!rows.length"><td colspan="5" class="px-4 py-8 text-center text-slate-400">No audit entries yet.</td></tr>
          <tr v-for="r in rows" :key="r.id" class="hover:bg-slate-50">
            <td class="px-4 py-2.5 font-mono text-xs">{{ r.action }}</td>
            <td class="px-4 py-2.5">{{ r.user?.name ?? '—' }}</td>
            <td class="px-4 py-2.5 text-slate-500">{{ r.object_type ? `${r.object_type.split('\\').pop()} #${r.object_id}` : '—' }}</td>
            <td class="px-4 py-2.5 text-slate-400">{{ r.ip }}</td>
            <td class="px-4 py-2.5 text-slate-400">{{ new Date(r.created_at).toLocaleString() }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </AdminLayout>
</template>
