<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, ApiException } from '../api/client'
import { useAuthStore } from '../stores/auth'
import StepTimeline from '../components/orchestration/StepTimeline.vue'
import OrchestrationOutputPanel from '../components/orchestration/OrchestrationOutputPanel.vue'

export type OrchestrationStep = {
  id: number
  agent: string
  sequence: number
  status: string
  model_used?: string
  duration_ms?: number
  output?: Record<string, unknown>
  error?: string
}

export type OrchestrationRun = {
  id: number
  status: 'queued' | 'running' | 'auditing' | 'complete' | 'failed' | 'rejected'
  prompt: string
  output_html?: string
  executive_summary?: string
  compliance_passed?: boolean
  compliance_notes?: string
  error?: string
  total_duration_ms?: number
  steps?: OrchestrationStep[]
  created_at: string
}

type DataSourceRef  = { id: number; name: string }
type KbRef          = { id: number; name: string }

const auth = useAuthStore()

const prompt       = ref('')
const projectId    = ref<number | null>(null)
const dataSourceId = ref<number | null>(null)
const kbIds        = ref<number[]>([])

const dataSources = ref<DataSourceRef[]>([])
const kbs         = ref<KbRef[]>([])

const currentRun  = ref<OrchestrationRun | null>(null)
const runs        = ref<OrchestrationRun[]>([])
const submitting  = ref(false)
const loadingRuns = ref(false)
const error       = ref('')

let pollTimer: ReturnType<typeof setInterval> | null = null

onMounted(async () => {
  await Promise.all([loadOptions(), loadRuns()])
  projectId.value = auth.user?.current_project_id ?? null
})

onUnmounted(() => stopPolling())

async function loadOptions() {
  try {
    if (auth.can('datasources.view')) {
      dataSources.value = (await apiRequest<{ data: DataSourceRef[] }>('/data-sources')).data
    }
    const kbRes = await apiRequest<{ data: KbRef[] }>('/knowledge-bases')
    kbs.value = kbRes.data
  } catch { /* non-fatal */ }
}

async function loadRuns() {
  loadingRuns.value = true
  try {
    const res = await apiRequest<{ data: { data: OrchestrationRun[] } }>('/orchestration')
    runs.value = res.data.data
  } catch { /* ignore */ } finally {
    loadingRuns.value = false
  }
}

async function submit() {
  if (!prompt.value.trim() || submitting.value) return
  submitting.value = true
  error.value = ''
  try {
    const res = await apiRequest<{ data: OrchestrationRun }>('/orchestration', {
      method: 'POST',
      body: JSON.stringify({
        prompt:          prompt.value.trim(),
        project_id:      projectId.value  ?? undefined,
        data_source_id:  dataSourceId.value ?? undefined,
        kb_ids:          kbIds.value.length ? kbIds.value : undefined,
      }),
    })
    currentRun.value = res.data
    runs.value.unshift(res.data)
    prompt.value = ''
    startPolling(res.data.id)
  } catch (e) {
    error.value = e instanceof ApiException ? e.message : 'Failed to submit.'
  } finally {
    submitting.value = false
  }
}

function selectRun(run: OrchestrationRun) {
  currentRun.value = run
  if (!run.status.match(/^(complete|failed|rejected)$/)) {
    startPolling(run.id)
  }
}

function startPolling(runId: number) {
  stopPolling()
  pollTimer = setInterval(() => pollRun(runId), 2500)
}

function stopPolling() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null }
}

async function pollRun(runId: number) {
  try {
    const res = await apiRequest<{ data: OrchestrationRun }>(`/orchestration/${runId}`)
    const updated = res.data
    currentRun.value = updated
    const idx = runs.value.findIndex(r => r.id === runId)
    if (idx !== -1) runs.value[idx] = updated
    if (updated.status.match(/^(complete|failed|rejected)$/)) stopPolling()
  } catch { stopPolling() }
}

async function onComplianceDone(run: OrchestrationRun) {
  currentRun.value = run
  const idx = runs.value.findIndex(r => r.id === run.id)
  if (idx !== -1) runs.value[idx] = run
}

async function onPromoted(reportId: number) {
  await loadRuns()
}

function statusClass(status: string) {
  return {
    'bg-yellow-100 text-yellow-800': status === 'queued',
    'bg-blue-100 text-blue-800':     status === 'running' || status === 'auditing',
    'bg-green-100 text-green-800':   status === 'complete',
    'bg-red-100 text-red-800':       status === 'failed' || status === 'rejected',
  }
}

function duration(ms?: number) {
  if (!ms) return ''
  return ms < 1000 ? `${ms}ms` : `${(ms / 1000).toFixed(1)}s`
}
</script>

<template>
  <AdminLayout title="AI Orchestration">
    <div class="max-w-7xl mx-auto px-4 py-6 space-y-6">

      <!-- Prompt Panel -->
      <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 space-y-4">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Generate Report with AI</h2>

        <textarea
          v-model="prompt"
          rows="4"
          placeholder="Describe the report you want… e.g. 'Show monthly user registrations grouped by department for Q1 2026'"
          class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white p-3 focus:outline-none focus:ring-2 focus:ring-rose-500 resize-none"
        />

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Data Source (optional)</label>
            <select v-model="dataSourceId" class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white p-2">
              <option :value="null">— none —</option>
              <option v-for="ds in dataSources" :key="ds.id" :value="ds.id">{{ ds.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Knowledge Bases (optional)</label>
            <select v-model="kbIds" multiple class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white p-2 h-20">
              <option v-for="kb in kbs" :key="kb.id" :value="kb.id">{{ kb.name }}</option>
            </select>
          </div>
        </div>

        <div v-if="error" class="text-sm text-red-600 dark:text-red-400">{{ error }}</div>

        <button
          @click="submit"
          :disabled="!prompt.trim() || submitting"
          class="px-6 py-2 rounded-lg bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white text-sm font-medium transition"
        >
          {{ submitting ? 'Submitting…' : 'Generate' }}
        </button>
      </div>

      <!-- Main content: run list + detail -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Run list -->
        <div class="space-y-3">
          <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Recent Runs</h3>

          <div v-if="loadingRuns" class="text-sm text-gray-500 dark:text-gray-400">Loading…</div>
          <div v-else-if="!runs.length" class="text-sm text-gray-400">No runs yet.</div>

          <button
            v-for="run in runs"
            :key="run.id"
            @click="selectRun(run)"
            class="w-full text-left rounded-xl border p-3 space-y-1 transition"
            :class="currentRun?.id === run.id
              ? 'border-rose-500 bg-rose-50 dark:bg-rose-900/20'
              : 'border-gray-200 dark:border-gray-700 hover:border-rose-300'"
          >
            <div class="flex items-center justify-between gap-2">
              <span :class="['text-xs font-medium px-2 py-0.5 rounded-full', statusClass(run.status)]">
                {{ run.status }}
              </span>
              <span class="text-xs text-gray-400">{{ duration(run.total_duration_ms) }}</span>
            </div>
            <p class="text-xs text-gray-700 dark:text-gray-300 line-clamp-2">{{ run.prompt }}</p>
          </button>
        </div>

        <!-- Run detail -->
        <div class="lg:col-span-2 space-y-4">
          <div v-if="!currentRun" class="text-sm text-gray-400 dark:text-gray-500 pt-12 text-center">
            Select or submit a run to see results.
          </div>

          <template v-else>
            <!-- Status + Steps -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-5 space-y-3">
              <div class="flex items-center gap-3">
                <span :class="['text-sm font-semibold px-3 py-1 rounded-full', statusClass(currentRun.status)]">
                  {{ currentRun.status }}
                </span>
                <span v-if="currentRun.total_duration_ms" class="text-xs text-gray-500">{{ duration(currentRun.total_duration_ms) }}</span>
                <span v-if="currentRun.status === 'running' || currentRun.status === 'queued'" class="text-xs text-blue-500 animate-pulse">● Live</span>
              </div>
              <p class="text-sm text-gray-600 dark:text-gray-300 italic">"{{ currentRun.prompt }}"</p>
              <div v-if="currentRun.error" class="text-sm text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded p-3">
                {{ currentRun.error }}
              </div>
              <StepTimeline v-if="currentRun.steps?.length" :steps="currentRun.steps" />
            </div>

            <!-- Output -->
            <OrchestrationOutputPanel
              v-if="currentRun.status === 'complete'"
              :run="currentRun"
              @compliance-done="onComplianceDone"
              @promoted="onPromoted"
            />
          </template>
        </div>
      </div>

    </div>
  </AdminLayout>
</template>
