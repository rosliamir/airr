<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, ApiException } from '../api/client'

type Report = { id: number; name: string; description?: string | null }

const route = useRoute()
const reportId = Number(route.params.id)

const report = ref<Report | null>(null)
const html = ref('')
const rowCount = ref<number | null>(null)
const busy = ref(true)
const error = ref('')

// A simple, in-system "publish" link: still requires login (same auth/RBAC
// as everything else), just skips straight to the rendered report with no
// editor chrome — opened directly from the Publish action, or shared as a URL.
onMounted(async () => {
  try {
    report.value = (await apiRequest<{ data: Report }>(`/reports/${reportId}`)).data
    const res = await apiRequest<{ data: { html: string; row_count: number } }>(`/reports/${reportId}/run`, {
      method: 'POST', body: JSON.stringify({ params: {} }),
    })
    html.value = res.data.html
    rowCount.value = res.data.row_count
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load report'
  } finally {
    busy.value = false
  }
})
</script>

<template>
  <AdminLayout>
    <div class="max-w-4xl mx-auto space-y-4">
      <div class="flex items-center justify-between">
        <h1 class="text-lg font-bold text-slate-800">{{ report?.name ?? 'Report' }}</h1>
        <span v-if="rowCount !== null" class="text-xs text-slate-400">{{ rowCount }} rows</span>
      </div>
      <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>
      <div v-if="busy" class="text-slate-400 text-sm">Loading…</div>
      <div v-else-if="html" class="bg-white shadow-sm rounded-xl p-5">
        <div v-html="html"></div>
      </div>
    </div>
  </AdminLayout>
</template>
