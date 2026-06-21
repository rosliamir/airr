<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { apiRequest } from '../api/client'
import AirrLogo from '../components/AirrLogo.vue'

const auth = useAuthStore()
const router = useRouter()

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

async function logout() {
  await auth.logout()
  router.push({ name: 'login' })
}

// MVP module map (15 modules) — wired progressively per roadmap phase.
const modules = [
  { code: 'M1', name: 'Core Platform & Security', phase: 0, status: 'active' },
  { code: 'M2', name: 'Data Source Connector', phase: 1, status: 'planned' },
  { code: 'M3', name: 'Knowledge Base / RAG', phase: 2, status: 'planned' },
  { code: 'M4', name: 'AI Orchestration (Multi-Agent)', phase: 3, status: 'planned' },
  { code: 'M5', name: 'Design-Time Studio', phase: 1, status: 'planned' },
  { code: 'M6', name: 'Report Engine & Definition', phase: 1, status: 'planned' },
  { code: 'M7', name: 'Compiler & Artifact (CRA)', phase: 4, status: 'planned' },
  { code: 'M8', name: 'Runtime Engine', phase: 4, status: 'planned' },
  { code: 'M9', name: 'Interactive Chat', phase: 4, status: 'planned' },
  { code: 'M10', name: 'Output & Export', phase: 1, status: 'planned' },
  { code: 'M11', name: 'API Delivery', phase: 4, status: 'planned' },
  { code: 'M12', name: 'Scheduler', phase: 5, status: 'planned' },
  { code: 'M13', name: 'Mini Data Warehouse', phase: 5, status: 'planned' },
  { code: 'M14', name: 'Admin & Governance', phase: 5, status: 'planned' },
  { code: 'M15', name: 'Deployment & Infra', phase: 0, status: 'active' },
]
</script>

<template>
  <div class="min-h-screen">
    <header class="bg-white border-b border-slate-200">
      <div class="max-w-6xl mx-auto px-6 py-3 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <AirrLogo :size="34" />
          <div>
            <div class="font-bold leading-tight">AIRR</div>
            <div class="text-[10px] text-slate-400 uppercase tracking-widest">AI &amp; RAG Reporting</div>
          </div>
        </div>
        <div class="flex items-center gap-4">
          <span class="text-xs px-2 py-1 rounded-full bg-airr-50 text-airr-700 font-medium uppercase">
            {{ health?.edition ?? '…' }} edition
          </span>
          <div class="text-right">
            <div class="text-sm font-medium">{{ auth.user?.name }}</div>
            <div class="text-xs text-slate-400">{{ auth.user?.roles.join(', ') }}</div>
          </div>
          <button @click="logout" class="text-sm text-slate-500 hover:text-airr-600">Sign out</button>
        </div>
      </div>
    </header>

    <main class="max-w-6xl mx-auto px-6 py-8">
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

      <h2 class="text-lg font-semibold mb-3">MVP 1.0 Modules</h2>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <div v-for="m in modules" :key="m.code"
          class="bg-white rounded-xl border border-slate-100 p-4 flex items-start justify-between">
          <div>
            <div class="text-sm font-semibold">{{ m.code }} · {{ m.name }}</div>
            <div class="text-xs text-slate-400">Phase {{ m.phase }}</div>
          </div>
          <span class="text-[10px] px-2 py-0.5 rounded-full uppercase font-medium"
            :class="m.status === 'active' ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-100 text-slate-400'">
            {{ m.status }}
          </span>
        </div>
      </div>
    </main>
  </div>
</template>
