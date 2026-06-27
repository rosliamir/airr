<script setup lang="ts">
import { onMounted, ref } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, ApiException, uploadFile } from '../api/client'
import { useAuthStore } from '../stores/auth'

type Project = { id: number; code: string; name: string }
type KbDocument = {
  id: number; title: string; type: string; status: string
  category: string; category_label: string; source_kind: string
  chunk_count: number; error: string | null; trained_at: string | null
}
type KnowledgeBase = {
  id: number; name: string; description?: string; tags: string[]
  version: number; embedding_model?: string; project: Project | null
  documents_count: number; chunks_count: number; documents?: KbDocument[]
}

const auth = useAuthStore()
const canManage = auth.can('kb.manage')

const kbs = ref<KnowledgeBase[]>([])
const projects = ref<Project[]>([])
const selected = ref<KnowledgeBase | null>(null)
const loading = ref(true)
const error = ref('')
const busy = ref(false)
const uploading = ref(false)

const statusBadge: Record<string, string> = {
  trained: 'bg-emerald-100 text-emerald-700',
  processing: 'bg-amber-100 text-amber-700',
  queued: 'bg-slate-100 text-slate-500',
  error: 'bg-rose-100 text-rose-700',
}

const showForm = ref(false)
const form = ref({ name: '', description: '', tags: '', project_id: null as number | null })

// Document category taxonomy (FR-M3.2) + upload selection.
const categories = ref<Record<string, string>>({})
const uploadCategory = ref('srs')
const uploadOtherLabel = ref('')

// System-knowledge ingest (FR-M3.2b).
const systemSources = ref<Record<string, string>>({})
const dataSources = ref<{ id: number; name: string }[]>([])
const sysSource = ref('db_schema')
const sysDataSourceId = ref<number | null>(null)
const ingestingSystem = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [k, p] = await Promise.all([
      apiRequest<{ data: KnowledgeBase[] }>('/knowledge-bases'),
      auth.can('projects.view') ? apiRequest<{ data: Project[] }>('/projects') : Promise.resolve({ data: [] }),
    ])
    kbs.value = k.data
    projects.value = p.data
    if (!Object.keys(categories.value).length) {
      const meta = (await apiRequest<{ data: { documents: Record<string, string>; system: Record<string, string> } }>('/knowledge-bases/categories')).data
      categories.value = meta.documents
      systemSources.value = meta.system
    }
    if (auth.can('datasources.view') && !dataSources.value.length) {
      dataSources.value = (await apiRequest<{ data: { id: number; name: string }[] }>('/data-sources')).data
    }
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load knowledge bases'
  } finally {
    loading.value = false
  }
}

async function openDetail(kb: KnowledgeBase) {
  try {
    selected.value = (await apiRequest<{ data: KnowledgeBase }>(`/knowledge-bases/${kb.id}`)).data
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to open KB'
  }
}

function openCreate() {
  form.value = { name: '', description: '', tags: '', project_id: null }
  showForm.value = true
}

async function save() {
  busy.value = true
  error.value = ''
  try {
    await apiRequest('/knowledge-bases', {
      method: 'POST',
      body: JSON.stringify({
        name: form.value.name,
        description: form.value.description || null,
        project_id: form.value.project_id,
        tags: form.value.tags ? form.value.tags.split(',').map((t) => t.trim()).filter(Boolean) : [],
      }),
    })
    showForm.value = false
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    busy.value = false
  }
}

async function removeKb(kb: KnowledgeBase) {
  if (!confirm(`Delete knowledge base "${kb.name}" and all its documents?`)) return
  try {
    await apiRequest(`/knowledge-bases/${kb.id}`, { method: 'DELETE' })
    if (selected.value?.id === kb.id) selected.value = null
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Delete failed'
  }
}

async function onUpload(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !selected.value) return
  uploading.value = true
  error.value = ''
  try {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('category', uploadCategory.value)
    if (uploadCategory.value === 'other' && uploadOtherLabel.value) fd.append('category_label', uploadOtherLabel.value)
    await uploadFile(`/knowledge-bases/${selected.value.id}/documents`, fd)
    await openDetail(selected.value)
    await load()
  } catch (err) {
    error.value = err instanceof ApiException ? err.error.message : 'Upload failed'
  } finally {
    uploading.value = false
    input.value = ''
  }
}

// Versioning (FR-M3.9).
const versionTargetId = ref<number | null>(null)
const versionDoc = ref<KbDocument | null>(null)
const versionHistory = ref<{ version: number; title: string; status: string; chunk_count: number; note: string | null; author: string | null; created_at: string }[] | null>(null)

function triggerNewVersion(doc: KbDocument) {
  versionTargetId.value = doc.id
  ;(document.getElementById('kb-version-input') as HTMLInputElement)?.click()
}

async function onUploadVersion(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file || !versionTargetId.value) return
  busy.value = true
  error.value = ''
  try {
    const fd = new FormData()
    fd.append('file', file)
    await uploadFile(`/kb-documents/${versionTargetId.value}/versions`, fd)
    if (selected.value) await openDetail(selected.value)
  } catch (err) {
    error.value = err instanceof ApiException ? err.error.message : 'New version failed'
  } finally {
    busy.value = false
    input.value = ''
    versionTargetId.value = null
  }
}

async function viewHistory(doc: KbDocument) {
  versionDoc.value = doc
  versionHistory.value = null
  try {
    versionHistory.value = (await apiRequest<{ data: typeof versionHistory.value }>(`/kb-documents/${doc.id}/versions`)).data
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load history'
  }
}

async function ingestSystem() {
  if (!selected.value) return
  ingestingSystem.value = true
  error.value = ''
  try {
    const body: Record<string, unknown> = { source: sysSource.value }
    if (sysSource.value === 'db_schema') body.data_source_id = sysDataSourceId.value
    await apiRequest(`/knowledge-bases/${selected.value.id}/system`, { method: 'POST', body: JSON.stringify(body) })
    await openDetail(selected.value)
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'System ingest failed'
  } finally {
    ingestingSystem.value = false
  }
}

async function reindex(doc: KbDocument) {
  busy.value = true
  try {
    await apiRequest(`/kb-documents/${doc.id}/reindex`, { method: 'POST' })
    if (selected.value) await openDetail(selected.value)
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Re-index failed'
  } finally {
    busy.value = false
  }
}

async function removeDoc(doc: KbDocument) {
  if (!confirm(`Delete document "${doc.title}"?`)) return
  try {
    await apiRequest(`/kb-documents/${doc.id}`, { method: 'DELETE' })
    if (selected.value) await openDetail(selected.value)
    await load()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Delete failed'
  }
}

onMounted(load)
</script>

<template>
  <AdminLayout>
    <div class="space-y-5">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-lg font-semibold text-slate-800">Knowledge Base</h1>
          <p class="text-sm text-slate-400">Train the platform on reference documents (RAG). On-premise embeddings.</p>
        </div>
        <button v-if="canManage" @click="openCreate"
          class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2">+ New KB</button>
      </div>

      <p v-if="error" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ error }}</p>
      <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>

      <div v-else class="grid grid-cols-3 gap-5">
        <!-- KB list -->
        <div class="col-span-1 space-y-2">
          <div v-if="!kbs.length" class="text-sm text-slate-400 border border-dashed border-slate-200 rounded-xl p-6 text-center">
            No knowledge bases yet.
          </div>
          <button v-for="kb in kbs" :key="kb.id" @click="openDetail(kb)"
            class="w-full text-left bg-white rounded-xl border p-4 transition hover:border-airr-300"
            :class="selected?.id === kb.id ? 'border-airr-400 ring-1 ring-airr-200' : 'border-slate-100'">
            <div class="flex items-center justify-between">
              <span class="font-medium text-slate-700">{{ kb.name }}</span>
              <span class="text-xs text-slate-400">v{{ kb.version }}</span>
            </div>
            <div class="text-xs text-slate-400 mt-1">{{ kb.documents_count }} docs · {{ kb.chunks_count }} chunks</div>
            <div v-if="kb.tags?.length" class="flex flex-wrap gap-1 mt-2">
              <span v-for="t in kb.tags" :key="t" class="text-[10px] bg-slate-100 text-slate-500 rounded-full px-1.5 py-0.5">{{ t }}</span>
            </div>
          </button>
        </div>

        <!-- Detail -->
        <div class="col-span-2">
          <div v-if="!selected" class="text-sm text-slate-400 border border-dashed border-slate-200 rounded-xl p-10 text-center">
            Select a knowledge base to manage its documents.
          </div>
          <div v-else class="bg-white rounded-xl border border-slate-100 p-6 space-y-5">
            <div class="flex items-start justify-between">
              <div>
                <h2 class="font-semibold text-slate-800">{{ selected.name }}</h2>
                <p v-if="selected.description" class="text-sm text-slate-500">{{ selected.description }}</p>
                <p class="text-xs text-slate-400 mt-1">
                  Embedding model: {{ selected.embedding_model ?? 'default (set on first ingest)' }}
                </p>
              </div>
              <button v-if="canManage" @click="removeKb(selected)" class="text-xs text-rose-500 hover:underline">Delete KB</button>
            </div>

            <!-- Upload -->
            <div v-if="canManage" class="border border-dashed border-slate-200 rounded-lg p-4 space-y-3">
              <label class="block text-sm font-medium text-slate-600">Upload reference document</label>
              <div class="flex flex-wrap items-end gap-3">
                <div>
                  <label class="block text-xs font-medium text-slate-500 mb-1">Document type</label>
                  <select v-model="uploadCategory" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm focus:ring-2 focus:ring-airr-300 outline-none">
                    <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
                  </select>
                </div>
                <div v-if="uploadCategory === 'other'">
                  <label class="block text-xs font-medium text-slate-500 mb-1">Specify type</label>
                  <input v-model="uploadOtherLabel" placeholder="e.g. Release Note" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm focus:ring-2 focus:ring-airr-300 outline-none" />
                </div>
              </div>
              <input type="file" accept=".pdf,.docx,.xlsx,.xls,.md,.markdown,.txt" :disabled="uploading" @change="onUpload"
                class="text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-airr-50 file:text-airr-700 file:px-3 file:py-1.5 file:text-sm" />
              <p class="text-xs text-slate-400">{{ uploading ? 'Ingesting…' : 'PDF, DOCX, XLSX, Markdown — auto-chunked.' }}</p>
            </div>

            <!-- System knowledge (FR-M3.2b) -->
            <div v-if="canManage" class="border border-dashed border-slate-200 rounded-lg p-4 space-y-3">
              <div>
                <label class="block text-sm font-medium text-slate-600">Add system knowledge</label>
                <p class="text-xs text-slate-400">Ingest AIRR's own structure (DB schema, RBAC…) as descriptive text.</p>
              </div>
              <div class="flex flex-wrap items-end gap-3">
                <div>
                  <label class="block text-xs font-medium text-slate-500 mb-1">Source</label>
                  <select v-model="sysSource" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm focus:ring-2 focus:ring-airr-300 outline-none">
                    <option v-for="(label, key) in systemSources" :key="key" :value="key">{{ label }}</option>
                  </select>
                </div>
                <div v-if="sysSource === 'db_schema'">
                  <label class="block text-xs font-medium text-slate-500 mb-1">Data source</label>
                  <select v-model="sysDataSourceId" class="rounded-lg border border-slate-200 px-3 py-1.5 text-sm focus:ring-2 focus:ring-airr-300 outline-none">
                    <option :value="null">— select —</option>
                    <option v-for="d in dataSources" :key="d.id" :value="d.id">{{ d.name }}</option>
                  </select>
                </div>
                <button @click="ingestSystem" :disabled="ingestingSystem || (sysSource === 'db_schema' && !sysDataSourceId)"
                  class="text-sm font-medium text-airr-600 border border-airr-200 hover:bg-airr-50 rounded-lg px-3 py-1.5 disabled:opacity-50">
                  {{ ingestingSystem ? 'Ingesting…' : 'Ingest' }}
                </button>
              </div>
              <p class="text-[11px] text-slate-400">More sources (ERD, menu, glossary…) coming. Some are gated until Ollama embedding is set up.</p>
            </div>

            <!-- Documents -->
            <div>
              <h3 class="text-sm font-semibold text-slate-600 mb-2">Documents</h3>
              <div v-if="!selected.documents?.length" class="text-sm text-slate-400">No documents ingested yet.</div>
              <table v-else class="w-full text-sm">
                <thead>
                  <tr class="text-left text-xs text-slate-400 border-b border-slate-100">
                    <th class="py-2">Title</th><th>Category</th><th>Type</th><th>Status</th><th>Chunks</th><th></th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="d in selected.documents" :key="d.id" class="border-b border-slate-50">
                    <td class="py-2 text-slate-700">
                      {{ d.title }}
                      <div v-if="d.error" class="text-xs text-rose-500">{{ d.error }}</div>
                    </td>
                    <td><span class="text-xs bg-airr-50 text-airr-700 rounded-full px-2 py-0.5">{{ d.category_label }}</span></td>
                    <td class="uppercase text-xs text-slate-400">{{ d.type }}</td>
                    <td><span class="text-xs rounded-full px-2 py-0.5" :class="statusBadge[d.status]">{{ d.status }}</span></td>
                    <td class="text-slate-600">{{ d.chunk_count }}</td>
                    <td class="text-right whitespace-nowrap">
                      <span class="text-[10px] bg-slate-100 text-slate-500 rounded-full px-1.5 py-0.5 mr-2">v{{ d.version }}</span>
                      <button @click="viewHistory(d)" class="text-xs text-slate-500 hover:underline mr-3">History</button>
                      <button v-if="canManage && d.source_kind === 'upload'" @click="triggerNewVersion(d)" :disabled="busy" class="text-xs text-airr-600 hover:underline mr-3">New version</button>
                      <button v-if="canManage && d.source_kind === 'upload'" @click="reindex(d)" :disabled="busy" class="text-xs text-airr-600 hover:underline mr-3">Re-index</button>
                      <button v-if="canManage" @click="removeDoc(d)" class="text-xs text-rose-500 hover:underline">Delete</button>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Hidden input for "New version" upload -->
    <input id="kb-version-input" type="file" class="hidden" accept=".pdf,.docx,.xlsx,.xls,.md,.markdown,.txt" @change="onUploadVersion" />

    <!-- Version history modal (FR-M3.9 log) -->
    <div v-if="versionDoc" class="fixed inset-0 bg-black/30 flex items-center justify-center p-4 z-50" @click.self="versionDoc = null">
      <div class="bg-white rounded-xl p-6 w-full max-w-lg space-y-4">
        <div class="flex items-center justify-between">
          <h2 class="font-semibold text-slate-800">Version history — {{ versionDoc.title }}</h2>
          <button @click="versionDoc = null" class="text-slate-400 hover:text-slate-600">✕</button>
        </div>
        <div v-if="!versionHistory" class="text-sm text-slate-400">Loading…</div>
        <div v-else-if="!versionHistory.length" class="text-sm text-slate-400">No version history.</div>
        <ul v-else class="space-y-2 max-h-96 overflow-auto">
          <li v-for="v in versionHistory" :key="v.version" class="border border-slate-100 rounded-lg p-3 text-sm">
            <div class="flex items-center justify-between">
              <span class="font-medium text-slate-700">v{{ v.version }} · {{ v.title }}</span>
              <span class="text-xs rounded-full px-2 py-0.5" :class="statusBadge[v.status]">{{ v.status }}</span>
            </div>
            <div class="text-xs text-slate-400 mt-1">
              {{ v.chunk_count }} chunks · {{ v.author ?? 'system' }} · {{ new Date(v.created_at).toLocaleString() }}
            </div>
            <div v-if="v.note" class="text-xs text-slate-500 mt-1 italic">“{{ v.note }}”</div>
          </li>
        </ul>
      </div>
    </div>

    <!-- Create modal -->
    <div v-if="showForm" class="fixed inset-0 bg-black/30 flex items-center justify-center p-4 z-50" @click.self="showForm = false">
      <div class="bg-white rounded-xl p-6 w-full max-w-md space-y-4">
        <h2 class="font-semibold text-slate-800">New Knowledge Base</h2>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Name</label>
          <input v-model="form.name" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Description</label>
          <textarea v-model="form.description" rows="2" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Tags <span class="text-slate-400 font-normal">· comma-separated</span></label>
          <input v-model="form.tags" placeholder="policy, finance" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div v-if="projects.length">
          <label class="block text-sm font-medium text-slate-600 mb-1">Project <span class="text-slate-400 font-normal">· optional</span></label>
          <select v-model="form.project_id" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
            <option :value="null">— none —</option>
            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.code }} — {{ p.name }}</option>
          </select>
        </div>
        <div class="flex justify-end gap-2 pt-1">
          <button @click="showForm = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button @click="save" :disabled="busy || !form.name"
            class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
            {{ busy ? 'Creating…' : 'Create' }}
          </button>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
