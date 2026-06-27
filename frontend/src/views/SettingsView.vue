<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, ApiException } from '../api/client'

type Feature = { key: string; enabled: boolean; editions: string[] }
type Settings = {
  regional: Record<string, string>
  subscription: { edition: string; limits: Record<string, number | null>; features: Feature[] }
  about: {
    name: string; motto: string; version: string; edition: string; website: string
    sovereignty: string; stack: string[]; brand_colors: string[]
  }
}

type AiConfig = {
  provider: string
  tasks: string[]
  defaults: { provider: string; models: Record<string, string> }
  per_project: boolean
  edition: string
}
type AiHealth = { provider: string; reachable: boolean; models: { name: string }[] }

const tab = ref<'regional' | 'lookup' | 'ai' | 'subscription' | 'about'>('regional')

// --- Lookups (M14): reference values that feed dropdowns (user_type, project_type, …) ---
type Lookup = { id: number; category: string; value: string; label: string; sort: number; is_active: boolean; is_system: boolean }
const lookups = ref<Record<string, Lookup[]>>({})
const lookupBusy = ref(false)
const showLookupForm = ref(false)
const editingLookup = ref<Lookup | null>(null)
const lookupForm = ref<{ category: string; value: string; label: string; sort: number; is_active: boolean }>({ category: '', value: '', label: '', sort: 0, is_active: true })
const lookupCategories = computed(() => Object.keys(lookups.value))

async function loadLookups() {
  try {
    lookups.value = (await apiRequest<{ data: Record<string, Lookup[]> }>('/lookups/manage')).data
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load lookups'
  }
}
function openLookupCreate(category = '') {
  editingLookup.value = null
  lookupForm.value = { category, value: '', label: '', sort: 0, is_active: true }
  showLookupForm.value = true
}
function openLookupEdit(l: Lookup) {
  editingLookup.value = l
  lookupForm.value = { category: l.category, value: l.value, label: l.label, sort: l.sort, is_active: l.is_active }
  showLookupForm.value = true
}
async function saveLookup() {
  lookupBusy.value = true
  error.value = ''
  try {
    const path = editingLookup.value ? `/lookups/${editingLookup.value.id}` : '/lookups'
    await apiRequest(path, { method: editingLookup.value ? 'PUT' : 'POST', body: JSON.stringify(lookupForm.value) })
    showLookupForm.value = false
    await loadLookups()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    lookupBusy.value = false
  }
}
async function deleteLookup(l: Lookup) {
  if (l.is_system) return
  if (!confirm(`Delete lookup "${l.label}"?`)) return
  try {
    await apiRequest(`/lookups/${l.id}`, { method: 'DELETE' })
    await loadLookups()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Delete failed'
  }
}
const prettyCategory = (c: string) => c.replace(/_/g, ' ').replace(/\b\w/g, (m) => m.toUpperCase())
const data = ref<Settings | null>(null)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const saved = ref(false)

const form = ref<Record<string, string>>({})

// AI / Models tab state
const ai = ref<AiConfig | null>(null)
const aiModels = ref<Record<string, string>>({})
const aiHealth = ref<AiHealth | null>(null)
const checkingHealth = ref(false)

const TASK_LABELS: Record<string, string> = {
  embedding: 'Embedding (RAG vectors)',
  generation: 'Generation (writing)',
  reasoning: 'Reasoning (analysis)',
  audit: 'Audit (double-check)',
}

const REGIONAL_FIELDS = [
  { key: 'region', label: 'Region' },
  { key: 'currency', label: 'Currency code' },
  { key: 'currency_symbol', label: 'Currency symbol' },
  { key: 'locale', label: 'Locale' },
  { key: 'timezone', label: 'Timezone' },
  { key: 'date_format', label: 'Date format' },
  { key: 'number_format', label: 'Number format' },
  { key: 'data_residency', label: 'Data residency' },
]

const LIMIT_LABELS: Record<string, string> = {
  data_sources: 'Data sources',
  rag_kbs: 'Knowledge bases (RAG)',
  api_endpoints: 'API endpoints',
}

async function load() {
  loading.value = true
  try {
    data.value = (await apiRequest<{ data: Settings }>('/settings')).data
    form.value = { ...data.value.regional }
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load settings'
  } finally {
    loading.value = false
  }
}

async function saveRegional() {
  saving.value = true
  error.value = ''
  saved.value = false
  try {
    const res = await apiRequest<{ data: Record<string, string> }>('/settings/regional', {
      method: 'PUT',
      body: JSON.stringify(form.value),
    })
    form.value = { ...res.data }
    if (data.value) data.value.regional = res.data
    saved.value = true
    setTimeout(() => (saved.value = false), 2000)
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    saving.value = false
  }
}

async function loadAi() {
  try {
    ai.value = (await apiRequest<{ data: AiConfig }>('/ai/config')).data
    aiModels.value = { ...ai.value.defaults.models }
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load AI config'
  }
}

async function saveAi() {
  saving.value = true
  error.value = ''
  saved.value = false
  try {
    const res = await apiRequest<{ data: { defaults: AiConfig['defaults'] } }>('/ai/config', {
      method: 'PUT',
      body: JSON.stringify({ provider: ai.value?.provider, models: aiModels.value }),
    })
    aiModels.value = { ...res.data.defaults.models }
    saved.value = true
    setTimeout(() => (saved.value = false), 2000)
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    saving.value = false
  }
}

async function checkHealth() {
  checkingHealth.value = true
  try {
    aiHealth.value = (await apiRequest<{ data: AiHealth }>('/ai/health')).data
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Health check failed'
  } finally {
    checkingHealth.value = false
  }
}

const fmtLimit = (v: number | null) => (v === null ? 'Unlimited' : String(v))
const editionLabel = (e: string) => e.charAt(0).toUpperCase() + e.slice(1)
const featureLabel = (k: string) => k.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())

onMounted(() => {
  load()
  loadAi()
  loadLookups()
})
</script>

<template>
  <AdminLayout>
    <div class="space-y-5">
      <div class="flex gap-6 border-b border-slate-200">
        <button v-for="t in (['regional', 'lookup', 'ai', 'subscription', 'about'] as const)" :key="t" @click="tab = t"
          class="pb-2.5 text-sm font-medium border-b-2 -mb-px transition capitalize"
          :class="tab === t ? 'border-airr-500 text-airr-600' : 'border-transparent text-slate-500 hover:text-slate-700'">
          {{ t === 'ai' ? 'AI / Models' : t }}
        </button>
      </div>

      <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>
      <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>

      <!-- REGIONAL -->
      <div v-else-if="tab === 'regional' && data" class="bg-white rounded-xl border border-slate-100 p-6 max-w-2xl space-y-4">
        <div class="grid grid-cols-2 gap-4">
          <div v-for="f in REGIONAL_FIELDS" :key="f.key">
            <label class="block text-sm font-medium text-slate-600 mb-1">{{ f.label }}</label>
            <input v-model="form[f.key]" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
        </div>
        <div class="flex items-center gap-3 pt-2">
          <button @click="saveRegional" :disabled="saving"
            class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
            {{ saving ? 'Saving…' : 'Save changes' }}
          </button>
          <span v-if="saved" class="text-sm text-emerald-600">✓ Saved</span>
        </div>
      </div>

      <!-- LOOKUP (reference values) -->
      <div v-else-if="tab === 'lookup'" class="space-y-4 max-w-3xl">
        <div class="flex items-center justify-between">
          <p class="text-sm text-slate-400">Reference values that feed dropdowns (user types, project types, …). Add more lookups as needed.</p>
          <button @click="openLookupCreate()" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5">+ New value</button>
        </div>

        <div v-for="(items, category) in lookups" :key="category" class="bg-white rounded-xl border border-slate-100 p-5">
          <div class="flex items-center justify-between mb-3">
            <h3 class="font-semibold text-slate-700">{{ prettyCategory(category) }}</h3>
            <button @click="openLookupCreate(category)" class="text-xs text-airr-600 hover:underline">+ Add to {{ prettyCategory(category) }}</button>
          </div>
          <table class="w-full text-sm">
            <thead>
              <tr class="text-xs text-slate-400 text-left">
                <th class="font-medium py-1">Label</th><th class="font-medium">Value</th><th class="font-medium w-16">Active</th><th class="font-medium w-24 text-right">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="l in items" :key="l.id" class="border-t border-slate-50">
                <td class="py-1.5 text-slate-700">{{ l.label }} <span v-if="l.is_system" class="text-[9px] uppercase text-slate-400 ml-1">system</span></td>
                <td class="text-slate-500"><code class="text-xs">{{ l.value }}</code></td>
                <td><span :class="l.is_active ? 'text-emerald-500' : 'text-slate-300'">{{ l.is_active ? '✓' : '✕' }}</span></td>
                <td class="text-right whitespace-nowrap">
                  <button @click="openLookupEdit(l)" class="text-xs text-airr-600 hover:underline mr-3">Edit</button>
                  <button v-if="!l.is_system" @click="deleteLookup(l)" class="text-xs text-rose-500 hover:underline">Delete</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="!lookupCategories.length" class="text-sm text-slate-400">No lookups yet.</div>
      </div>

      <!-- AI / MODELS -->
      <div v-else-if="tab === 'ai' && ai" class="space-y-4 max-w-2xl">
        <div class="bg-white rounded-xl border border-slate-100 p-6 space-y-4">
          <div class="flex items-center justify-between">
            <div>
              <h3 class="font-semibold text-slate-700">System-default models</h3>
              <p class="text-xs text-slate-400">On-premise via <span class="font-medium capitalize">{{ ai.provider }}</span> · no external AI APIs. Projects may override these.</p>
            </div>
          </div>
          <div class="space-y-3">
            <div v-for="t in ai.tasks" :key="t">
              <label class="block text-sm font-medium text-slate-600 mb-1">{{ TASK_LABELS[t] ?? t }}</label>
              <input v-model="aiModels[t]" list="ai-model-list"
                class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
            </div>
            <datalist id="ai-model-list">
              <option v-for="m in (aiHealth?.models ?? [])" :key="m.name" :value="m.name" />
            </datalist>
          </div>
          <div class="flex items-center gap-3 pt-1">
            <button @click="saveAi" :disabled="saving"
              class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
              {{ saving ? 'Saving…' : 'Save defaults' }}
            </button>
            <span v-if="saved" class="text-sm text-emerald-600">✓ Saved</span>
          </div>
        </div>

        <!-- Provider health -->
        <div class="bg-white rounded-xl border border-slate-100 p-6 space-y-3">
          <div class="flex items-center justify-between">
            <h3 class="font-semibold text-slate-700">Provider health</h3>
            <button @click="checkHealth" :disabled="checkingHealth"
              class="text-sm font-medium text-airr-600 border border-airr-200 hover:bg-airr-50 rounded-lg px-3 py-1.5 disabled:opacity-50">
              {{ checkingHealth ? 'Checking…' : 'Check now' }}
            </button>
          </div>
          <div v-if="aiHealth" class="text-sm space-y-2">
            <div class="flex items-center gap-2">
              <span :class="aiHealth.reachable ? 'text-emerald-500' : 'text-airr-500'">●</span>
              <span class="text-slate-600">{{ aiHealth.provider }} — {{ aiHealth.reachable ? 'reachable' : 'unreachable' }}</span>
            </div>
            <div v-if="aiHealth.reachable">
              <div class="text-slate-500 mb-1">Installed models ({{ aiHealth.models.length }})</div>
              <div class="flex flex-wrap gap-1.5">
                <span v-for="m in aiHealth.models" :key="m.name" class="text-xs bg-slate-100 text-slate-600 rounded-full px-2 py-0.5">{{ m.name }}</span>
                <span v-if="!aiHealth.models.length" class="text-xs text-slate-400">none pulled yet</span>
              </div>
            </div>
          </div>
          <p v-else class="text-xs text-slate-400">Run a check to see if {{ ai.provider }} is up and which models are installed.</p>
        </div>

        <p v-if="!ai.per_project" class="text-xs text-slate-400">
          Per-project model override is available on Standard & Enterprise editions.
        </p>
      </div>

      <!-- SUBSCRIPTION -->
      <div v-else-if="tab === 'subscription' && data" class="space-y-4 max-w-2xl">
        <div class="bg-white rounded-xl border border-slate-100 p-6">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-sm text-slate-500">Current edition</div>
              <div class="text-xl font-bold text-airr-600">{{ editionLabel(data.subscription.edition) }}</div>
            </div>
            <span class="text-xs text-slate-400">Edition is set at deploy time (AIRR_EDITION).</span>
          </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-100 p-6">
          <h3 class="font-semibold text-slate-700 mb-3">Limits</h3>
          <div class="space-y-2">
            <div v-for="(v, k) in data.subscription.limits" :key="k" class="flex justify-between text-sm border-b border-slate-50 py-1.5 last:border-0">
              <span class="text-slate-600">{{ LIMIT_LABELS[k] ?? k }}</span>
              <span class="font-medium" :class="v === null ? 'text-emerald-600' : 'text-slate-700'">{{ fmtLimit(v) }}</span>
            </div>
          </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-100 p-6">
          <h3 class="font-semibold text-slate-700 mb-3">Module features</h3>
          <div class="grid grid-cols-2 gap-y-2 gap-x-4 text-sm">
            <div v-for="f in data.subscription.features" :key="f.key" class="flex items-center gap-2">
              <span :class="f.enabled ? 'text-emerald-500' : 'text-slate-300'">{{ f.enabled ? '✓' : '✕' }}</span>
              <span :class="f.enabled ? 'text-slate-700' : 'text-slate-400'">{{ featureLabel(f.key) }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- ABOUT -->
      <div v-else-if="tab === 'about' && data" class="bg-white rounded-xl border border-slate-100 p-6 max-w-2xl space-y-4">
        <div class="flex items-center gap-1.5">
          <span v-for="c in data.about.brand_colors" :key="c" class="w-6 h-6 rounded" :style="{ background: c }"></span>
        </div>
        <div>
          <div class="text-xl font-bold">{{ data.about.name }}</div>
          <div class="text-sm text-slate-500 italic">{{ data.about.motto }}</div>
        </div>
        <dl class="text-sm divide-y divide-slate-50">
          <div class="flex justify-between py-2"><dt class="text-slate-500">Version</dt><dd class="font-medium">{{ data.about.version }}</dd></div>
          <div class="flex justify-between py-2"><dt class="text-slate-500">Edition</dt><dd class="font-medium capitalize">{{ data.about.edition }}</dd></div>
          <div class="flex justify-between py-2"><dt class="text-slate-500">Website</dt><dd class="font-medium">{{ data.about.website }}</dd></div>
          <div class="py-2">
            <dt class="text-slate-500 mb-1">Sovereignty</dt>
            <dd class="text-slate-600">{{ data.about.sovereignty }}</dd>
          </div>
          <div class="py-2">
            <dt class="text-slate-500 mb-1.5">Stack</dt>
            <dd class="flex flex-wrap gap-1.5">
              <span v-for="s in data.about.stack" :key="s" class="text-xs bg-slate-100 text-slate-600 rounded-full px-2 py-0.5">{{ s }}</span>
            </dd>
          </div>
        </dl>
      </div>
    </div>

    <!-- Lookup form modal -->
    <div v-if="showLookupForm" class="fixed inset-0 bg-black/30 flex items-center justify-center p-4 z-50" @click.self="showLookupForm = false">
      <div class="bg-white rounded-xl p-6 w-full max-w-sm space-y-3">
        <h2 class="font-semibold text-slate-800">{{ editingLookup ? 'Edit' : 'New' }} lookup value</h2>
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">Category</label>
          <input v-model="lookupForm.category" list="lookup-cats" :disabled="!!editingLookup?.is_system"
            placeholder="user_type" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none disabled:bg-slate-50" />
          <datalist id="lookup-cats"><option v-for="c in lookupCategories" :key="c" :value="c" /></datalist>
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">Value <span class="text-slate-300">(stable key)</span></label>
          <input v-model="lookupForm.value" :disabled="!!editingLookup?.is_system"
            placeholder="development" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none disabled:bg-slate-50" />
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-500 mb-1">Label</label>
          <input v-model="lookupForm.label" placeholder="Development" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div class="flex items-center gap-4">
          <div class="w-24">
            <label class="block text-xs font-medium text-slate-500 mb-1">Sort</label>
            <input v-model.number="lookupForm.sort" type="number" class="w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <label class="flex items-center gap-2 text-sm text-slate-600 mt-5">
            <input type="checkbox" v-model="lookupForm.is_active" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /> Active
          </label>
        </div>
        <div class="flex justify-end gap-2 pt-1">
          <button @click="showLookupForm = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button @click="saveLookup" :disabled="lookupBusy || !lookupForm.category || !lookupForm.value || !lookupForm.label"
            class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">{{ lookupBusy ? 'Saving…' : 'Save' }}</button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
