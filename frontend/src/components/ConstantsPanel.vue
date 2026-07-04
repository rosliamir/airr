<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { apiRequest, uploadFile, ApiException } from '../api/client'

type DataSourceOpt = { id: number; name: string }
type Dataset = { id: number; data_source_id: number; name: string; fields?: { name: string }[] }
type Constant = {
  id: number; scope: string; type: string; project_id: number | null
  key: string; label: string; value: string | null; format: string | null
  data_source_id: number | null; dataset_id: number | null; data_column: string | null
  formula: string | null
  image_url: string | null; placeholder: string
}

const props = withDefaults(defineProps<{
  scope: 'system' | 'global' | 'project'
  projectId?: number | null
  editable?: boolean
}>(), {
  editable: true,
})

const examplePlaceholder = computed(() => '{{' + props.scope.toUpperCase() + ':KEY}}')
const genericTokenExample = '{{' + 'TYPE:KEY' + '}}'

const constants = ref<Constant[]>([])
const loading = ref(false)
const error = ref('')
const busy = ref(false)

const dataSources = ref<DataSourceOpt[]>([])
const datasets = ref<Dataset[]>([])
const datasetsForForm = computed(() => datasets.value.filter(d => d.data_source_id === form.value.data_source_id))
const columnsForForm = computed(() => {
  const ds = datasets.value.find(d => d.id === form.value.dataset_id)
  return ds?.fields?.map(f => f.name) ?? []
})

const showForm = ref(false)
const editing = ref<Constant | null>(null)
const imageFile = ref<File | null>(null)
const emptyForm = () => ({
  key: '', label: '', type: 'text' as 'text' | 'data' | 'image' | 'calc', value: '',
  format: '', data_source_id: null as number | null, dataset_id: null as number | null, data_column: '',
  formula: '',
})
const form = ref(emptyForm())

async function load() {
  loading.value = true
  try {
    const params = new URLSearchParams()
    if (props.scope !== 'project') params.set('scope', props.scope)
    else { params.set('scope', 'project'); if (props.projectId) params.set('project_id', String(props.projectId)) }
    constants.value = (await apiRequest<{ data: Constant[] }>(`/constants?${params}`)).data
      .filter(c => props.scope === 'project' ? c.project_id === props.projectId : true)
  } finally {
    loading.value = false
  }
}

async function loadDataSources() {
  if (dataSources.value.length) return
  try {
    const src = (await apiRequest<{ data: DataSourceOpt[] }>('/data-sources')).data
    dataSources.value = src
    const lists = await Promise.all(src.map(s => apiRequest<{ data: Dataset[] }>(`/data-sources/${s.id}/datasets`)))
    datasets.value = lists.flatMap(l => l.data)
  } catch { /* non-fatal: DATA-type constants simply unavailable without datasource access */ }
}

function openCreate() {
  editing.value = null
  form.value = emptyForm()
  imageFile.value = null
  showForm.value = true
  loadDataSources()
}
function openEdit(c: Constant) {
  editing.value = c
  form.value = {
    key: c.key, label: c.label, type: c.type as any, value: c.value ?? '', format: c.format ?? '',
    data_source_id: c.data_source_id, dataset_id: c.dataset_id, data_column: c.data_column ?? '',
    formula: c.formula ?? '',
  }
  imageFile.value = null
  showForm.value = true
  loadDataSources()
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    const scope = props.scope
    const fd = new FormData()
    fd.append('scope', scope)
    if (scope === 'project' && props.projectId) fd.append('project_id', String(props.projectId))
    fd.append('key', form.value.key.toUpperCase())
    fd.append('label', form.value.label)
    fd.append('type', form.value.type)
    if (form.value.type === 'text') fd.append('value', form.value.value ?? '')
    if (form.value.format) fd.append('format', form.value.format)
    if (form.value.type === 'data') {
      fd.append('data_source_id', String(form.value.data_source_id ?? ''))
      fd.append('dataset_id', String(form.value.dataset_id ?? ''))
      fd.append('data_column', form.value.data_column)
    }
    if (form.value.type === 'calc') fd.append('formula', form.value.formula)
    if (form.value.type === 'image' && imageFile.value) fd.append('image', imageFile.value)

    if (editing.value) {
      fd.append('_method', 'PUT')
      await uploadFile(`/constants/${editing.value.id}`, fd)
    } else {
      await uploadFile('/constants', fd)
    }
    showForm.value = false
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    busy.value = false
  }
}

async function remove(c: Constant) {
  if (!confirm(`Delete constant ${c.key}?`)) return
  busy.value = true
  try {
    await apiRequest(`/constants/${c.id}`, { method: 'DELETE' })
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Delete failed'
  } finally {
    busy.value = false
  }
}

function onFileChange(ev: Event) {
  imageFile.value = (ev.target as HTMLInputElement).files?.[0] ?? null
}

onMounted(load)
defineExpose({ load })
</script>

<template>
  <div class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs text-slate-400">Reference with <code class="font-mono">{{ examplePlaceholder }}</code> in templates.</p>
      <button v-if="editable !== false" @click="openCreate" class="text-xs font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-2.5 py-1.5">+ New constant</button>
    </div>

    <p v-if="error" class="text-xs text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>
    <div v-if="loading" class="text-slate-400 text-xs">Loading…</div>
    <div v-else-if="!constants.length" class="text-slate-400 text-xs">No constants yet.</div>

    <div v-else class="space-y-1.5 max-h-72 overflow-y-auto pr-1">
      <div v-for="c in constants" :key="c.id" class="flex items-center gap-2 flex-wrap border border-slate-100 rounded-lg px-3 py-2 text-xs">
        <img v-if="c.type === 'image' && c.image_url" :src="c.image_url" class="w-6 h-6 rounded object-cover shrink-0" />
        <span class="font-mono text-amber-700 shrink-0">{{ c.placeholder }}</span>
        <span class="text-slate-500 truncate flex-1">{{ c.label }}</span>
        <span v-if="c.type === 'text'" class="text-slate-400 truncate max-w-[8rem]">{{ c.value }}</span>
        <span v-else-if="c.type === 'data'" class="text-emerald-600 truncate max-w-[8rem]">dataset#{{ c.dataset_id }}.{{ c.data_column }}</span>
        <span v-else-if="c.type === 'calc'" class="text-sky-600 font-mono truncate max-w-[10rem]" :title="c.formula ?? ''">{{ c.formula }}</span>
        <span v-if="c.format" class="text-[10px] rounded px-1.5 py-0.5 bg-violet-50 text-violet-600 font-mono shrink-0" :title="'Format / regex: ' + c.format">{{ c.format }}</span>
        <span class="text-[10px] rounded px-1.5 py-0.5 bg-slate-100 text-slate-500 shrink-0">{{ c.type }}</span>
        <button v-if="editable !== false" @click="openEdit(c)" class="text-airr-600 hover:underline shrink-0">Edit</button>
        <button v-if="editable !== false && c.scope !== 'system'" @click="remove(c)" class="text-rose-600 hover:underline shrink-0">Delete</button>
      </div>
    </div>

    <!-- Create / Edit form modal -->
    <div v-if="showForm" class="fixed inset-0 bg-slate-900/30 flex items-center justify-center z-[60] px-4 py-8 overflow-y-auto" @click.self="showForm = false">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6 space-y-3 my-auto">
        <h3 class="font-bold text-base">{{ editing ? 'Edit Constant' : 'New Constant' }}</h3>

        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Key</label>
            <input v-model="form.key" :disabled="!!editing" placeholder="LOGO" class="w-full uppercase rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-airr-300 disabled:bg-slate-50" />
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Label</label>
            <input v-model="form.label" class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-airr-300" />
          </div>
        </div>

        <div>
          <label class="block text-xs font-medium text-slate-600 mb-1">Type</label>
          <select v-model="form.type" class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-airr-300">
            <option value="text">Text</option>
            <option value="data">From datasource/dataset</option>
            <option value="image">Image</option>
            <option value="calc">Calculation (formula)</option>
          </select>
        </div>

        <div v-if="form.type === 'text'">
          <label class="block text-xs font-medium text-slate-600 mb-1">Value</label>
          <input v-model="form.value" class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-airr-300" />
        </div>

        <div v-else-if="form.type === 'data'" class="space-y-2">
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Data source</label>
            <select v-model="form.data_source_id" @change="form.dataset_id = null; form.data_column = ''"
              class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-airr-300">
              <option :value="null">— select —</option>
              <option v-for="s in dataSources" :key="s.id" :value="s.id">{{ s.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Dataset</label>
            <select v-model="form.dataset_id" @change="form.data_column = ''"
              class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-airr-300">
              <option :value="null">— select —</option>
              <option v-for="d in datasetsForForm" :key="d.id" :value="d.id">{{ d.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Column</label>
            <select v-if="columnsForForm.length" v-model="form.data_column" class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-airr-300">
              <option value="">— select —</option>
              <option v-for="c in columnsForForm" :key="c" :value="c">{{ c }}</option>
            </select>
            <input v-else v-model="form.data_column" placeholder="e.g. name"
              class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm outline-none focus:ring-2 focus:ring-airr-300" />
            <p v-if="!columnsForForm.length && form.dataset_id" class="text-[11px] text-slate-400 mt-1">This dataset has no cached column list — type the column name manually.</p>
          </div>
        </div>

        <div v-else-if="form.type === 'image'">
          <label class="block text-xs font-medium text-slate-600 mb-1">Image file</label>
          <input type="file" accept="image/*" @change="onFileChange" class="w-full text-xs" />
          <img v-if="editing?.image_url" :src="editing.image_url" class="mt-2 h-12 rounded object-contain" />
        </div>

        <div v-else-if="form.type === 'calc'">
          <label class="block text-xs font-medium text-slate-600 mb-1">Formula</label>
          <textarea v-model="form.formula" rows="2" placeholder="e.g. {{DATA:SUBTOTAL}} * 1.06"
            class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm font-mono outline-none focus:ring-2 focus:ring-airr-300"></textarea>
          <p class="text-[11px] text-slate-400 mt-1">Reference other constants with <code class="font-mono">{{ genericTokenExample }}</code>, combined with + − × ÷ and parentheses. Resolved to a plain number at render time.</p>
        </div>

        <div v-if="scope === 'system'">
          <label class="block text-xs font-medium text-slate-600 mb-1">Format <span class="text-slate-400 font-normal">(date format like d/m/Y, or a regexp like ^[0-9]{4}$ to validate the value)</span></label>
          <input v-model="form.format" placeholder="d/m/Y or ^[A-Z]{2}\d{4}$" class="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-sm font-mono outline-none focus:ring-2 focus:ring-airr-300" />
        </div>

        <div class="flex justify-end gap-2 pt-2">
          <button @click="showForm = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button @click="save" :disabled="busy || !form.key || !form.label" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
            {{ busy ? 'Saving…' : 'Save' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
