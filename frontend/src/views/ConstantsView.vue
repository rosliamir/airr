<script setup lang="ts">
import { ref, onMounted } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import ConstantsPanel from '../components/ConstantsPanel.vue'
import { apiRequest } from '../api/client'

type ProjectOpt = { id: number; code: string; name: string }

const genericTokenExample = '{{' + 'TYPE:KEY' + '}}'

const topTab = ref<'general' | 'project'>('general')
const generalSubTab = ref<'system' | 'global'>('system')

const projects = ref<ProjectOpt[]>([])
const selectedProjectId = ref<number | null>(null)

async function loadProjects() {
  projects.value = (await apiRequest<{ data: ProjectOpt[] }>('/projects')).data
  if (!selectedProjectId.value && projects.value.length) selectedProjectId.value = projects.value[0].id
}

onMounted(loadProjects)
</script>

<template>
  <AdminLayout>
    <div class="space-y-4 max-w-3xl">
      <p class="text-sm text-slate-500">Constants back the <code class="font-mono text-xs">{{ genericTokenExample }}</code> placeholders used across templates and reports.</p>

      <!-- Top-level: General / Project -->
      <div class="flex gap-6 border-b border-slate-200">
        <button v-for="t in (['general', 'project'] as const)" :key="t" @click="topTab = t"
          class="pb-2.5 text-sm font-medium border-b-2 -mb-px transition capitalize"
          :class="topTab === t ? 'border-airr-500 text-airr-600' : 'border-transparent text-slate-500 hover:text-slate-700'">
          {{ t }}
        </button>
      </div>

      <!-- GENERAL: System / Global -->
      <div v-if="topTab === 'general'" class="space-y-3">
        <div class="flex gap-2">
          <button @click="generalSubTab = 'system'" class="text-xs font-medium px-3 py-1.5 rounded-lg" :class="generalSubTab === 'system' ? 'bg-airr-500 text-white' : 'text-slate-500 hover:bg-slate-50'">System (fixed)</button>
          <button @click="generalSubTab = 'global'" class="text-xs font-medium px-3 py-1.5 rounded-lg" :class="generalSubTab === 'global' ? 'bg-airr-500 text-white' : 'text-slate-500 hover:bg-slate-50'">Global (custom)</button>
        </div>
        <ConstantsPanel :key="generalSubTab" :scope="generalSubTab" />
      </div>

      <!-- PROJECT -->
      <div v-else class="space-y-3">
        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">Project</label>
          <select v-model="selectedProjectId" class="w-full max-w-xs rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none">
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} · {{ p.name }}</option>
          </select>
        </div>
        <ConstantsPanel v-if="selectedProjectId" :key="selectedProjectId" scope="project" :project-id="selectedProjectId" />
        <p v-else class="text-sm text-slate-400">No projects yet.</p>
      </div>
    </div>
  </AdminLayout>
</template>
