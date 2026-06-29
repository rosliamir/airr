<script setup lang="ts">
import { ref } from 'vue'
import { apiRequest, ApiException } from '../../api/client'
import type { OrchestrationRun } from '../../views/AiOrchestrationView.vue'

const props = defineProps<{ run: OrchestrationRun }>()
const emit  = defineEmits<{
  (e: 'compliance-done', run: OrchestrationRun): void
  (e: 'promoted', reportId: number): void
}>()

const activeTab    = ref<'report' | 'summary' | 'compliance'>('report')
const checkingComp = ref(false)
const promoting    = ref(false)
const promoteName  = ref(props.run.prompt.slice(0, 80))
const promoteError = ref('')

async function runComplianceCheck() {
  checkingComp.value = true
  promoteError.value = ''
  try {
    const res = await apiRequest<{ data: OrchestrationRun }>(
      `/orchestration/${props.run.id}/compliance`, { method: 'POST' }
    )
    emit('compliance-done', { ...props.run, ...res.data })
  } catch (e) {
    promoteError.value = e instanceof ApiException ? e.message : 'Compliance check failed.'
  } finally {
    checkingComp.value = false
  }
}

async function promote() {
  if (!promoteName.value.trim()) return
  promoting.value = true
  promoteError.value = ''
  try {
    const res = await apiRequest<{ data: { report_id: number } }>(
      `/orchestration/${props.run.id}/promote`,
      {
        method: 'POST',
        body: JSON.stringify({ name: promoteName.value.trim() }),
      }
    )
    emit('promoted', res.data.report_id)
  } catch (e) {
    promoteError.value = e instanceof ApiException ? e.message : 'Promotion failed.'
  } finally {
    promoting.value = false
  }
}
</script>

<template>
  <div class="bg-white dark:bg-gray-800 rounded-xl shadow overflow-hidden">
    <!-- Tabs -->
    <div class="flex border-b border-gray-200 dark:border-gray-700">
      <button
        v-for="tab in (['report', 'summary', 'compliance'] as const)"
        :key="tab"
        @click="activeTab = tab"
        :class="[
          'px-4 py-3 text-sm font-medium capitalize transition',
          activeTab === tab
            ? 'border-b-2 border-rose-600 text-rose-600 dark:text-rose-400'
            : 'text-gray-500 hover:text-gray-700 dark:hover:text-gray-300',
        ]"
      >{{ tab === 'compliance' ? 'Compliance' : tab.charAt(0).toUpperCase() + tab.slice(1) }}</button>
    </div>

    <div class="p-5">

      <!-- Report Tab -->
      <div v-show="activeTab === 'report'">
        <div
          v-if="run.output_html"
          class="prose prose-sm max-w-none dark:prose-invert airr-report-output"
          v-html="run.output_html"
        />
        <p v-else class="text-sm text-gray-400">No output yet.</p>
      </div>

      <!-- Summary Tab -->
      <div v-show="activeTab === 'summary'">
        <p v-if="run.executive_summary" class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap">
          {{ run.executive_summary }}
        </p>
        <p v-else class="text-sm text-gray-400">No summary yet.</p>
      </div>

      <!-- Compliance Tab -->
      <div v-show="activeTab === 'compliance'" class="space-y-4">
        <!-- Status badge -->
        <div class="flex items-center gap-3">
          <span
            v-if="run.compliance_passed === true"
            class="px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400"
          >Passed</span>
          <span
            v-else-if="run.compliance_passed === false"
            class="px-3 py-1 rounded-full text-sm font-medium bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400"
          >Failed</span>
          <span
            v-else
            class="px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400"
          >Not checked</span>
        </div>

        <p v-if="run.compliance_notes" class="text-sm text-gray-600 dark:text-gray-400">
          {{ run.compliance_notes }}
        </p>

        <div v-if="promoteError" class="text-sm text-red-600 dark:text-red-400">{{ promoteError }}</div>

        <!-- Compliance check button -->
        <button
          v-if="run.compliance_passed === null || run.compliance_passed === undefined"
          @click="runComplianceCheck"
          :disabled="checkingComp"
          class="px-4 py-2 rounded-lg bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-sm text-gray-700 dark:text-gray-300 disabled:opacity-50 transition"
        >
          {{ checkingComp ? 'Checking…' : 'Run Compliance Check' }}
        </button>

        <!-- Promote to report -->
        <div v-if="run.compliance_passed" class="space-y-2 pt-2 border-t border-gray-200 dark:border-gray-700">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Report name</label>
          <input
            v-model="promoteName"
            type="text"
            class="w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-sm text-gray-900 dark:text-white p-2 focus:outline-none focus:ring-2 focus:ring-rose-500"
            placeholder="Report name…"
          />
          <button
            @click="promote"
            :disabled="!promoteName.trim() || promoting"
            class="px-4 py-2 rounded-lg bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white text-sm font-medium transition"
          >
            {{ promoting ? 'Promoting…' : 'Promote to Report' }}
          </button>
        </div>
      </div>

    </div>
  </div>
</template>
