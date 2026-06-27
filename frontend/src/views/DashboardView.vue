<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, ApiException } from '../api/client'
import { useAuthStore } from '../stores/auth'

type Health = {
  status: string
  edition: string
  features: string[]
  services: { database: boolean; pgvector: boolean; pgai: boolean }
}
type Feature = {
  key: string; fr: string; label: string
  developed: boolean; ai_tested: boolean; human_tested: boolean; ready_for_prod: boolean; note: string | null
}
type ModuleDetail = { code: string; name: string; features: Feature[] }
type Summary = Record<string, { total: number; developed: number; ready_for_prod: number }>

const auth = useAuthStore()
const canEdit = auth.can('settings.manage')

const health = ref<Health | null>(null)
const summary = ref<Summary>({})
const detail = ref<ModuleDetail | null>(null)
const loadingDetail = ref(false)
const error = ref('')

onMounted(async () => {
  health.value = (await apiRequest<{ data: Health }>('/health')).data
  try {
    summary.value = (await apiRequest<{ data: Summary }>('/modules/features/summary')).data
  } catch { /* non-fatal */ }
})

async function openModule(code: string) {
  loadingDetail.value = true
  detail.value = { code, name: code, features: [] }
  try {
    detail.value = (await apiRequest<{ data: ModuleDetail }>(`/modules/${code}/features`)).data
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load features'
  } finally {
    loadingDetail.value = false
  }
}

async function toggle(f: Feature, field: 'human_tested' | 'ready_for_prod') {
  if (!canEdit) return
  const next = !f[field]
  f[field] = next // optimistic
  try {
    await apiRequest(`/features/${f.key}`, { method: 'PUT', body: JSON.stringify({ [field]: next }) })
    const s = summary.value[detail.value!.code]
    if (s && field === 'ready_for_prod') s.ready_for_prod += next ? 1 : -1
  } catch (e) {
    f[field] = !next // revert
    error.value = e instanceof ApiException ? e.error.message : 'Update failed'
  }
}

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
      <button v-for="m in modules" :key="m.code" @click="openModule(m.code)"
        class="text-left bg-white rounded-xl border border-slate-100 p-4 flex items-start justify-between hover:border-airr-300 transition">
        <div>
          <div class="text-sm font-semibold">{{ m.code }} · {{ m.name }}</div>
          <div class="text-xs text-slate-400">
            Phase {{ m.phase }}
            <span v-if="summary[m.code]?.developed" class="ml-1">·
              {{ summary[m.code].ready_for_prod }}/{{ summary[m.code].developed }} prod-ready
            </span>
          </div>
        </div>
        <span class="text-[10px] px-2 py-0.5 rounded-full uppercase font-medium whitespace-nowrap"
          :class="badgeClass[m.status]">
          {{ m.status.replace('_', ' ') }}
        </span>
      </button>
    </div>

    <!-- Module feature checklist -->
    <div v-if="detail" class="fixed inset-0 bg-black/30 flex items-center justify-center p-4 z-50" @click.self="detail = null">
      <div class="bg-white rounded-xl w-full max-w-3xl max-h-[85vh] flex flex-col">
        <div class="flex items-center justify-between p-5 border-b border-slate-100">
          <h2 class="font-semibold text-slate-800">{{ detail.code }} · {{ detail.name }} — features</h2>
          <button @click="detail = null" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>
        <p v-if="error" class="text-sm text-airr-700 bg-airr-50 px-5 py-2">{{ error }}</p>
        <div class="overflow-auto p-5">
          <div v-if="loadingDetail" class="text-sm text-slate-400">Loading…</div>
          <div v-else-if="!detail.features.length" class="text-sm text-slate-400">No features yet for this module.</div>
          <table v-else class="w-full text-sm">
            <thead>
              <tr class="text-xs text-slate-400 border-b border-slate-100">
                <th class="text-left py-2 font-medium">Feature</th>
                <th class="py-2 font-medium w-20">Developed</th>
                <th class="py-2 font-medium w-16">AI test</th>
                <th class="py-2 font-medium w-20">Human test</th>
                <th class="py-2 font-medium w-24">Ready prod</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="f in detail.features" :key="f.key" class="border-b border-slate-50">
                <td class="py-2 pr-3">
                  <div class="text-slate-700">{{ f.label }}</div>
                  <div class="text-[10px] text-slate-400">{{ f.fr }}</div>
                </td>
                <td class="text-center"><span :class="f.developed ? 'text-emerald-500' : 'text-slate-300'">{{ f.developed ? '✓' : '—' }}</span></td>
                <td class="text-center"><span :class="f.ai_tested ? 'text-emerald-500' : 'text-slate-300'">{{ f.ai_tested ? '✓' : '—' }}</span></td>
                <td class="text-center">
                  <input type="checkbox" :checked="f.human_tested" :disabled="!canEdit" @change="toggle(f, 'human_tested')"
                    class="rounded border-slate-300 text-airr-500 focus:ring-airr-300 disabled:opacity-50" />
                </td>
                <td class="text-center">
                  <input type="checkbox" :checked="f.ready_for_prod" :disabled="!canEdit" @change="toggle(f, 'ready_for_prod')"
                    class="rounded border-slate-300 text-emerald-500 focus:ring-emerald-300 disabled:opacity-50" />
                </td>
              </tr>
            </tbody>
          </table>
          <p v-if="detail.features.length && !canEdit" class="text-xs text-slate-400 mt-3">Read-only — settings.manage permission required to update test status.</p>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
