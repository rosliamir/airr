<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest } from '../api/client'

type Health = {
  status: string
  edition: string
  features: string[]
  services: { database: boolean; pgvector: boolean; pgai: boolean }
}
const health = ref<Health | null>(null)

onMounted(async () => {
  const res = await apiRequest<{ data: Health }>('/health')
  health.value = res.data
})

// MVP module map (15 modules). status: 'active' = shipped/usable ·
// 'in_progress' = partially built · 'planned' = not started.
// UPDATE this as modules progress (kept in sync with docs/AIRR-Dev-Tracker.md).
const modules = [
  { code: 'M1', name: 'Core Platform & Security', phase: 0, status: 'active' },
  { code: 'M2', name: 'Data Source Connector', phase: 1, status: 'active' },
  { code: 'M3', name: 'Knowledge Base / RAG', phase: 2, status: 'in_progress' },
  { code: 'M4', name: 'AI Orchestration (Multi-Agent)', phase: 3, status: 'planned' },
  { code: 'M5', name: 'Design-Time Studio', phase: 1, status: 'planned' },
  { code: 'M6', name: 'Report Engine & Definition', phase: 1, status: 'in_progress' },
  { code: 'M7', name: 'Compiler & Artifact (CRA)', phase: 4, status: 'planned' },
  { code: 'M8', name: 'Runtime Engine', phase: 4, status: 'planned' },
  { code: 'M9', name: 'Interactive Chat', phase: 4, status: 'planned' },
  { code: 'M10', name: 'Output & Export', phase: 1, status: 'planned' },
  { code: 'M11', name: 'API Delivery', phase: 4, status: 'planned' },
  { code: 'M12', name: 'Scheduler', phase: 5, status: 'planned' },
  { code: 'M13', name: 'Mini Data Warehouse', phase: 5, status: 'planned' },
  { code: 'M14', name: 'Admin & Governance', phase: 5, status: 'active' },
  { code: 'M15', name: 'Deployment & Infra', phase: 0, status: 'active' },
]

const badgeClass: Record<string, string> = {
  active: 'bg-emerald-50 text-emerald-600',
  in_progress: 'bg-amber-50 text-amber-600',
  planned: 'bg-slate-100 text-slate-400',
}
const done = modules.filter((m) => m.status === 'active').length
const inProgress = modules.filter((m) => m.status === 'in_progress').length
</script>

<template>
  <AdminLayout>
    <section class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
      <div class="bg-white rounded-xl border border-slate-100 p-5">
        <div class="text-xs text-slate-400 uppercase tracking-wide">Backend</div>
        <div class="mt-1 text-2xl font-bold" :class="health?.status === 'UP' ? 'text-emerald-600' : 'text-amber-600'">
          {{ health?.status ?? '…' }}
        </div>
      </div>
      <div class="bg-white rounded-xl border border-slate-100 p-5">
        <div class="text-xs text-slate-400 uppercase tracking-wide">Data layer</div>
        <div class="mt-2 flex gap-3 text-sm">
          <span :class="health?.services.database ? 'text-emerald-600' : 'text-slate-300'">● Postgres</span>
          <span :class="health?.services.pgvector ? 'text-emerald-600' : 'text-slate-300'">● pgvector</span>
          <span :class="health?.services.pgai ? 'text-emerald-600' : 'text-slate-300'">● pgai</span>
        </div>
      </div>
      <div class="bg-white rounded-xl border border-slate-100 p-5">
        <div class="text-xs text-slate-400 uppercase tracking-wide">Enabled features</div>
        <div class="mt-1 text-2xl font-bold text-airr-600">{{ health?.features.length ?? 0 }}</div>
      </div>
    </section>

    <div class="flex items-center justify-between mb-3">
      <h2 class="text-lg font-semibold">MVP 1.0 Modules</h2>
      <div class="text-xs text-slate-400">
        <span class="text-emerald-600 font-medium">{{ done }} active</span> ·
        <span class="text-amber-600 font-medium">{{ inProgress }} in progress</span> ·
        {{ modules.length - done - inProgress }} planned
      </div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
      <div v-for="m in modules" :key="m.code"
        class="bg-white rounded-xl border border-slate-100 p-4 flex items-start justify-between">
        <div>
          <div class="text-sm font-semibold">{{ m.code }} · {{ m.name }}</div>
          <div class="text-xs text-slate-400">Phase {{ m.phase }}</div>
        </div>
        <span class="text-[10px] px-2 py-0.5 rounded-full uppercase font-medium whitespace-nowrap"
          :class="badgeClass[m.status]">
          {{ m.status.replace('_', ' ') }}
        </span>
      </div>
    </div>
  </AdminLayout>
</template>
