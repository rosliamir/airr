<script setup lang="ts">
import type { OrchestrationStep } from '../../views/AiOrchestrationView.vue'

const props = defineProps<{ steps: OrchestrationStep[] }>()

const AGENT_LABELS: Record<string, string> = {
  retriever:    'Retriever',
  data_analyst: 'Data Analyst',
  auditor:      'Auditor',
  writer:       'Writer',
  compliance:   'Compliance',
}

function statusIcon(status: string) {
  if (status === 'complete') return '✓'
  if (status === 'failed')   return '✕'
  if (status === 'running')  return '●'
  return '○'
}

function statusClass(status: string) {
  if (status === 'complete') return 'text-green-600 dark:text-green-400'
  if (status === 'failed')   return 'text-red-600 dark:text-red-400'
  if (status === 'running')  return 'text-blue-500 animate-pulse'
  return 'text-gray-400'
}

function duration(ms?: number) {
  if (!ms) return ''
  return ms < 1000 ? `${ms}ms` : `${(ms / 1000).toFixed(1)}s`
}
</script>

<template>
  <div class="space-y-2">
    <div
      v-for="step in steps"
      :key="step.id"
      class="flex items-start gap-3"
    >
      <!-- Icon -->
      <span :class="['text-sm font-bold w-5 text-center shrink-0 mt-0.5', statusClass(step.status)]">
        {{ statusIcon(step.status) }}
      </span>

      <!-- Content -->
      <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2 flex-wrap">
          <span class="text-sm font-medium text-gray-800 dark:text-gray-200">
            {{ AGENT_LABELS[step.agent] ?? step.agent }}
          </span>
          <span v-if="step.model_used" class="text-xs text-gray-400">{{ step.model_used }}</span>
          <span v-if="step.duration_ms" class="text-xs text-gray-400">{{ duration(step.duration_ms) }}</span>
        </div>

        <!-- Error callout -->
        <div v-if="step.error" class="mt-1 text-xs text-red-600 dark:text-red-400 bg-red-50 dark:bg-red-900/20 rounded px-2 py-1">
          {{ step.error }}
        </div>

        <!-- Step output summary -->
        <div v-else-if="step.output && step.status === 'complete'" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
          <template v-if="step.agent === 'retriever'">
            {{ step.output.rag_chunks_count ?? 0 }} passages retrieved
          </template>
          <template v-else-if="step.agent === 'data_analyst'">
            {{ step.output.query_count ?? 0 }} rows · {{ step.output.query_columns?.length ?? 0 }} columns
          </template>
          <template v-else-if="step.agent === 'auditor'">
            Verdict: <span :class="step.output.verdict === 'pass' ? 'text-green-600' : 'text-red-600'">{{ step.output.verdict }}</span>
            <span v-if="step.output.notes" class="ml-1 text-gray-400">— {{ step.output.notes }}</span>
          </template>
          <template v-else-if="step.agent === 'writer'">
            {{ step.output.output_html_length ?? 0 }} chars generated
          </template>
          <template v-else-if="step.agent === 'compliance'">
            <span :class="step.output.passed ? 'text-green-600' : 'text-red-600'">
              {{ step.output.passed ? 'Passed' : 'Failed' }}
            </span>
            <span v-if="step.output.notes" class="ml-1 text-gray-400">— {{ step.output.notes }}</span>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>
