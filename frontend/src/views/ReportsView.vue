<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed, watch, inject } from 'vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, uploadFile, downloadFile, ApiException } from '../api/client'
import { useAuthStore } from '../stores/auth'

type Ref2 = { id: number; name: string; code?: string }
type Grant = { role_id: number; name?: string; view: boolean; edit: boolean; run: boolean }
type Dataset = { id: number; name: string; data_source_id?: number; parameters?: { name: string; label?: string; type?: string; default?: string }[] }
type DataSourceOpt = { id: number; name: string; type?: string; config_summary?: Record<string, unknown> }
type ReportDataset = { id: number; name: string; data_source_id?: number | null }
type Report = {
  id: number; name: string; description?: string; type: string; status: string
  project?: Ref2 | null; dataset?: ReportDataset | null; creator?: Ref2 | null
  definition?: Record<string, unknown>; permissions?: Grant[]; updated_at?: string
  archived_at?: string | null; deleted_at?: string | null
  version?: number; version_label?: string; layout?: string; printout_size?: string
  printout_width?: number | null; printout_height?: number | null
  output_formats?: string[]; locked?: boolean; templates?: Ref2[]
  tags?: string[]
}
type TemplateOpt = { id: number; name: string }
type CustomParam = {
  id: string; title: string
  type: 'text' | 'dropdown' | 'checkbox' | 'radio' | 'date' | 'datetime' | 'time' | 'amount'
  default_value?: string | boolean | null; options?: string[]
  min?: number | null; max?: number | null
  source_type?: 'json' | 'datasource'
  data_source_id?: number | null; dataset_id?: number | null; data_column?: string | null
  remark?: string; enabled: boolean
}
type Constant = { id: number; scope: string; key: string; label?: string }
type HistoryEntry = { id: number; action: string; snapshot: Record<string, unknown>; version_label?: string; changed_by_name: string; created_at: string }
type LogEntry   = { id: number; event: string; properties: Record<string, unknown>; user_name: string; created_at: string }

const auth = useAuthStore()
const canCreate = auth.can('reports.create')
const canEdit   = auth.can('reports.edit')
const canRun    = auth.can('reports.run')

const TYPES     = ['table', 'grouped', 'kpi', 'matrix', 'chart', 'document']
const TYPE_LABELS: Record<string, string> = {
  table: 'List', grouped: 'Group', kpi: 'KPI', matrix: 'Matrix', chart: 'Chart', document: 'Document',
}
const HISTORY_ACTION_LABELS: Record<string, string> = {
  created: 'Created', updated: 'Updated', published: 'Published', ai_generated: 'AI Generate',
}
const LAYOUTS = ['portrait', 'landscape']
const PRINTOUT_SIZES = ['a4', 'a3', 'a2', 'b5', 'custom']
const OUTPUT_FORMAT_OPTS = ['pdf', 'excel', 'csv']
const STATUS_BADGE: Record<string, string> = {
  draft:     'bg-amber-100 text-amber-700',
  published: 'bg-emerald-100 text-emerald-700',
}

// ── State ────────────────────────────────────────────────────────────────────
const reports  = ref<Report[]>([])
const deletedReports = ref<Report[]>([])
const allDatasets = ref<Dataset[]>([])
const dataSources = ref<DataSourceOpt[]>([])
const roles    = ref<{ id: number; name: string }[]>([])
const selected = ref<Report | null>(null)
const loading  = ref(true)
const busy     = ref(false)
const saveMsg  = ref('')
const runError = ref('')

// Left panel
const prompt   = ref('')   // stored inside definition.prompt
const promptBusy = ref(false)
type PromptHistoryEntry = { text: string; at: string }
const promptHistory = ref<PromptHistoryEntry[]>([])   // stored inside definition.prompt_history — round-trips through save/reload

// Collapsible sections — Permission starts closed, the rest start open.
const openSections = ref<Record<string, boolean>>({
  prompt: true, permission: false, parameters: true,
  setting: true, template: true, datasource: true, property: true,
})
function toggleSection(key: string) { openSections.value[key] = !openSections.value[key] }

// Drag-to-reorder sections within a panel (left: prompt/permission/parameters;
// right: setting/template/datasource/property) — order persisted per browser.
function loadOrder(storageKey: string, fallback: string[]): string[] {
  try {
    const stored = JSON.parse(localStorage.getItem(storageKey) || 'null')
    if (Array.isArray(stored) && fallback.every(k => stored.includes(k)) && stored.length === fallback.length) return stored
  } catch { /* ignore malformed storage */ }
  return [...fallback]
}
const leftOrder = ref<string[]>(loadOrder('airr_reports_left_order', ['prompt', 'permission', 'parameters']))
const rightOrder = ref<string[]>(loadOrder('airr_reports_right_order', ['setting', 'template', 'datasource', 'property']))
const dragKey = ref<string | null>(null)
function onDragStart(key: string) { dragKey.value = key }
function onDrop(targetKey: string, panel: 'left' | 'right') {
  const order = panel === 'left' ? leftOrder : rightOrder
  const storageKey = panel === 'left' ? 'airr_reports_left_order' : 'airr_reports_right_order'
  if (!dragKey.value || dragKey.value === targetKey) return
  const arr = [...order.value]
  const from = arr.indexOf(dragKey.value)
  const to = arr.indexOf(targetKey)
  if (from === -1 || to === -1) return
  arr.splice(from, 1)
  arr.splice(to, 0, dragKey.value)
  order.value = arr
  localStorage.setItem(storageKey, JSON.stringify(arr))
  dragKey.value = null
}

// Left/right panel whole-sidebar collapse (independent of per-section collapse).
const leftPanelCollapsed = ref(false)
const rightPanelCollapsed = ref(false)

// Bottom History/Error/API panel: closed / normal / maximized.
const bottomMaximized = ref(false)

// Resizable panels — drag the divider between panels to resize.
function loadWidth(storageKey: string, fallback: number): number {
  const stored = Number(localStorage.getItem(storageKey))
  return Number.isFinite(stored) && stored > 0 ? stored : fallback
}
const leftWidth = ref(loadWidth('airr_reports_left_width', 288))
const rightWidth = ref(loadWidth('airr_reports_right_width', 288))
const bottomHeight = ref(loadWidth('airr_reports_bottom_height', 176))
const MIN_PANEL_WIDTH = 200
const MAX_PANEL_WIDTH = 560
const MIN_BOTTOM_HEIGHT = 36
const MAX_BOTTOM_HEIGHT = 700

let resizing: { kind: 'left' | 'right' | 'bottom'; startPos: number; startSize: number } | null = null

function startResize(kind: 'left' | 'right' | 'bottom', e: MouseEvent) {
  resizing = {
    kind,
    startPos: kind === 'bottom' ? e.clientY : e.clientX,
    startSize: kind === 'left' ? leftWidth.value : kind === 'right' ? rightWidth.value : bottomHeight.value,
  }
  window.addEventListener('mousemove', onResizeMove)
  window.addEventListener('mouseup', onResizeEnd)
  e.preventDefault()
}
function onResizeMove(e: MouseEvent) {
  if (!resizing) return
  if (resizing.kind === 'bottom') {
    const delta = resizing.startPos - e.clientY // dragging up (negative Y delta) grows the panel
    bottomHeight.value = Math.min(MAX_BOTTOM_HEIGHT, Math.max(MIN_BOTTOM_HEIGHT, resizing.startSize + delta))
    bottomMaximized.value = false
    return
  }
  const delta = resizing.kind === 'left' ? e.clientX - resizing.startPos : resizing.startPos - e.clientX
  const next = Math.min(MAX_PANEL_WIDTH, Math.max(MIN_PANEL_WIDTH, resizing.startSize + delta))
  if (resizing.kind === 'left') leftWidth.value = next
  else rightWidth.value = next
}
function onResizeEnd() {
  if (resizing) {
    const key = resizing.kind === 'left' ? 'airr_reports_left_width' : resizing.kind === 'right' ? 'airr_reports_right_width' : 'airr_reports_bottom_height'
    const val = resizing.kind === 'left' ? leftWidth.value : resizing.kind === 'right' ? rightWidth.value : bottomHeight.value
    localStorage.setItem(key, String(val))
  }
  resizing = null
  window.removeEventListener('mousemove', onResizeMove)
  window.removeEventListener('mouseup', onResizeEnd)
}

// Report-level parameters (left panel > Parameters)
const fixedParamsEnabled = ref<Record<string, boolean>>({})
const requireParamScreen = ref(true)
const customParams = ref<CustomParam[]>([])
// Runtime values entered on the Preview parameter screen for custom params, keyed by param id.
const customParamValues = ref<Record<string, string | boolean>>({})
const showParamModal = ref(false)
const editingParam = ref<CustomParam | null>(null)
const paramForm = ref<CustomParam>({ id: '', title: '', type: 'text', default_value: '', options: [], min: null, max: null, source_type: 'datasource', data_source_id: null, dataset_id: null, data_column: null, remark: '', enabled: true })
const paramOptionsText = ref('') // comma-separated editor for dropdown/radio options (JSON source)
const paramOptionsJsonError = ref('')
const datasetsForParam = computed(() => allDatasets.value.filter(d => d.data_source_id === paramForm.value.data_source_id))

// {{SCOPE:KEY}} constants (Settings > Constants + project-level) insertable into
// a parameter's default value.
const constants = ref<Constant[]>([])
async function loadConstants() {
  if (constants.value.length) return
  try {
    const params = new URLSearchParams()
    if (selected.value?.project?.id) params.set('project_id', String(selected.value.project.id))
    constants.value = (await apiRequest<{ data: Constant[] }>(`/constants?${params}`)).data
  } catch { /* non-critical — constants picker just stays empty */ }
}
function constantToken(c: Constant): string {
  return `{{${c.scope.toUpperCase()}:${c.key}}}`
}
function insertConstant(token: string) {
  if (!token) return
  paramForm.value.default_value = `${(paramForm.value.default_value as string) ?? ''}${token}`
}

// Center
const preview  = ref('')
const rowCount = ref<number | null>(null)
const runParams = ref<Record<string, string>>({})

// Right panel
const editName   = ref('')
const editDesc   = ref('')
const editType   = ref('table')
const editStatus = ref('draft')
const editTagsText = ref('')
const editDataSourceId = ref<number | null>(null)
const editDatasetId = ref<number | null>(null)
const editLayout = ref('portrait')
const editPrintoutSize = ref('a4')
const editPrintoutWidth = ref<number | null>(null)
const editPrintoutHeight = ref<number | null>(null)
const editOutputFormats = ref<string[]>([])
const editTemplateIds = ref<number[]>([])
const editTemplateHeaderId = ref<number | null>(null)
const editTemplateFooterId = ref<number | null>(null)
const allTemplates = ref<TemplateOpt[]>([])
const addTemplateId = ref<number | null>(null)
const testConnResult = ref('')
const testConnBusy = ref(false)

// Permission matrix
const perms = ref<Record<number, { view: boolean; edit: boolean; run: boolean }>>({})

// Bottom tabs
const bottomTab = ref<'history' | 'error' | 'api'>('history')
const historyEntries = ref<HistoryEntry[]>([])
const logEntries     = ref<LogEntry[]>([])
const bottomBusy     = ref(false)
const bottomOpen     = ref(true)

// JSON def editor (raw)
const defText = ref('')

// Create modal
const showCreate = ref(false)
const createForm = ref({ name: '', description: '', type: 'table', data_source_id: null as number | null, dataset_id: null as number | null })
const datasetsForCreate = computed(() => allDatasets.value.filter(d => d.data_source_id === createForm.value.data_source_id))

const STATUS_ACCENT: Record<string, string> = {
  draft: '#F59E0B',
  published: '#10B981',
}

// ── Card grid: selection, sort, per-card menu, debug mode ────────────────────
const selectedIds = ref<Set<number>>(new Set())
const sortBy = ref<'-updated_at' | 'updated_at' | 'name' | '-name' | 'status'>('-updated_at')
const reportSearchText = ref('')
const activeReportTag = ref<string | null>(null)
const allReportTags = computed(() => Array.from(new Set(reports.value.flatMap(r => r.tags ?? []))).sort())
const filteredReports = computed(() => reports.value.filter(r => {
  if (activeReportTag.value && !(r.tags ?? []).includes(activeReportTag.value)) return false
  if (reportSearchText.value && !`${r.name} ${r.description ?? ''}`.toLowerCase().includes(reportSearchText.value.toLowerCase())) return false
  return true
}))
const showArchived = ref(false)
const openMenuId = ref<number | null>(null)
const debugIds = ref<Set<number>>(new Set())
const importInput = ref<HTMLInputElement | null>(null)

const allSelected = computed(() => reports.value.length > 0 && reports.value.every(r => selectedIds.value.has(r.id)))
function toggleSelect(id: number) {
  const s = new Set(selectedIds.value)
  s.has(id) ? s.delete(id) : s.add(id)
  selectedIds.value = s
}
function toggleSelectAll() {
  selectedIds.value = allSelected.value ? new Set() : new Set(reports.value.map(r => r.id))
}
function toggleMenu(id: number) { openMenuId.value = openMenuId.value === id ? null : id }
function toggleDebug(id: number) {
  const s = new Set(debugIds.value)
  s.has(id) ? s.delete(id) : s.add(id)
  debugIds.value = s
}

async function duplicateReport(r: Report) {
  busy.value = true
  try {
    await apiRequest(`/reports/${r.id}/duplicate`, { method: 'POST' })
    await load()
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Duplicate failed'
  } finally {
    busy.value = false
    openMenuId.value = null
  }
}
async function archiveReport(r: Report) {
  await apiRequest(`/reports/${r.id}/archive`, { method: 'POST' })
  await load()
  openMenuId.value = null
}
async function unarchiveReport(r: Report) {
  await apiRequest(`/reports/${r.id}/unarchive`, { method: 'POST' })
  await load()
  openMenuId.value = null
}
// Card-list "Log" modal (Change History + Access Log), matching Templates'.
const cardLogTarget = ref<Report | null>(null)
const cardLogTab = ref<'history' | 'access'>('history')
const cardLogEntries = ref<(HistoryEntry | LogEntry)[]>([])
const cardLogBusy = ref(false)
async function openCardLog(r: Report) {
  openMenuId.value = null
  cardLogTarget.value = r
  await fetchCardLog(r, 'history')
}
async function fetchCardLog(r: Report, tab: 'history' | 'access') {
  cardLogTab.value = tab
  cardLogBusy.value = true
  cardLogEntries.value = []
  try {
    const path = tab === 'history' ? `/reports/${r.id}/history` : `/reports/${r.id}/logs`
    cardLogEntries.value = (await apiRequest<{ data: (HistoryEntry | LogEntry)[] }>(path)).data
  } finally {
    cardLogBusy.value = false
  }
}

async function deleteReportCard(r: Report) {
  if (!confirm(`Delete "${r.name}"?`)) return
  await apiRequest(`/reports/${r.id}`, { method: 'DELETE' })
  await load()
  openMenuId.value = null
}
async function restoreReport(r: Report) {
  busy.value = true
  try {
    await apiRequest(`/reports/${r.id}/restore`, { method: 'POST' })
    await load()
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Restore failed'
  } finally {
    busy.value = false
  }
}
async function exportReport(r: Report) {
  openMenuId.value = null
  try {
    await downloadFile(`/reports/${r.id}/export`, `${r.name}.json`)
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Export failed'
  }
}
function copyReportUrl(r: Report) {
  navigator.clipboard?.writeText(`${window.location.origin}/reports/${r.id}`)
  openMenuId.value = null
}
function triggerImport() { importInput.value?.click() }
async function onImportFile(ev: Event) {
  const file = (ev.target as HTMLInputElement).files?.[0]
  if (!file) return
  const fd = new FormData()
  fd.append('file', file)
  try {
    const res = await uploadFile<{ data: { import_notes?: string[] } }>('/reports/import', fd)
    if (res.data.import_notes?.length) alert('Import complete:\n' + res.data.import_notes.join('\n'))
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Import failed'
  } finally {
    await load()
    ;(ev.target as HTMLInputElement).value = ''
  }
}

// ── Bulk actions (on checked cards) ──────────────────────────────────────────
async function bulkDelete() {
  if (!selectedIds.value.size || !confirm(`Delete ${selectedIds.value.size} report(s)?`)) return
  busy.value = true
  try {
    await Promise.all([...selectedIds.value].map(id => apiRequest(`/reports/${id}`, { method: 'DELETE' })))
    selectedIds.value = new Set()
    await load()
  } finally {
    busy.value = false
  }
}
async function bulkArchive() {
  if (!selectedIds.value.size) return
  busy.value = true
  try {
    await Promise.all([...selectedIds.value].map(id => apiRequest(`/reports/${id}/archive`, { method: 'POST' })))
    selectedIds.value = new Set()
    await load()
  } finally {
    busy.value = false
  }
}
async function bulkDuplicate() {
  if (!selectedIds.value.size) return
  busy.value = true
  try {
    await Promise.all([...selectedIds.value].map(id => apiRequest(`/reports/${id}/duplicate`, { method: 'POST' })))
    selectedIds.value = new Set()
    await load()
  } finally {
    busy.value = false
  }
}
async function bulkExport() {
  for (const id of selectedIds.value) {
    const r = reports.value.find(x => x.id === id)
    try {
      await downloadFile(`/reports/${id}/export`, `${r?.name ?? id}.json`)
    } catch (e) {
      runError.value = e instanceof ApiException ? e.error.message : 'Export failed'
    }
  }
}

// ── Computed ─────────────────────────────────────────────────────────────────
const boundDataset = computed<Dataset | null>(() =>
  allDatasets.value.find(d => d.id === (selected.value?.dataset?.id ?? editDatasetId.value)) ?? null
)
const datasetsForEdit = computed(() => allDatasets.value.filter(d => d.data_source_id === editDataSourceId.value))
const boundDataSource = computed<DataSourceOpt | null>(() =>
  dataSources.value.find(s => s.id === editDataSourceId.value) ?? null
)
const templatesAvailableToAdd = computed(() => allTemplates.value.filter(t => !editTemplateIds.value.includes(t.id)))
const shareLink = computed(() =>
  selected.value ? `${window.location.origin}/reports/${selected.value.id}` : ''
)
const apiEndpoint = computed(() =>
  selected.value ? `/api/reports/${selected.value.id}/run` : ''
)

// ── Load ─────────────────────────────────────────────────────────────────────
async function load() {
  loading.value = true
  try {
    const params = new URLSearchParams({ sort: sortBy.value })
    if (showArchived.value) params.set('include_archived', '1')
    reports.value = (await apiRequest<{ data: Report[] }>(`/reports?${params}`)).data
    const trashedRes = (await apiRequest<{ data: Report[] }>(`/reports?${params}&with_trashed=1`)).data
    deletedReports.value = trashedRes.filter(r => !!r.deleted_at)
    if (!roles.value.length)
      roles.value = (await apiRequest<{ data: { id: number; name: string }[] }>('/roles/options')).data
    if (auth.can('datasources.view') && !allDatasets.value.length) {
      const src = (await apiRequest<{ data: DataSourceOpt[] }>('/data-sources')).data
      dataSources.value = src
      const lists = await Promise.all(src.map(s => apiRequest<{ data: Dataset[] }>(`/data-sources/${s.id}/datasets`)))
      allDatasets.value = lists.flatMap(l => l.data)
    }
    if (!allTemplates.value.length) {
      allTemplates.value = (await apiRequest<{ data: TemplateOpt[] }>('/templates')).data
    }
  } finally {
    loading.value = false
  }
}

const sidebar = inject<{ collapsed: { value: boolean }; setCollapsed: (v: boolean) => void } | null>('sidebar', null)

async function openReport(r: Report) {
  preview.value = ''; rowCount.value = null; runError.value = ''; saveMsg.value = ''
  historyEntries.value = []; logEntries.value = []
  sidebar?.setCollapsed(true) // only minimise the main nav once a specific report is actually opened
  try {
    selected.value = (await apiRequest<{ data: Report }>(`/reports/${r.id}`)).data
    const def = selected.value.definition ?? {}
    defText.value  = JSON.stringify(def, null, 2)
    prompt.value   = (def.prompt as string) ?? ''
    promptHistory.value = (def.prompt_history as PromptHistoryEntry[]) ?? []
    editName.value   = selected.value.name
    editDesc.value   = selected.value.description ?? ''
    editType.value   = selected.value.type
    editStatus.value = selected.value.status
    editTagsText.value = (selected.value.tags ?? []).join(', ')
    editDatasetId.value = selected.value.dataset?.id ?? null
    editDataSourceId.value = selected.value.dataset?.data_source_id ?? null
    editLayout.value = selected.value.layout ?? 'portrait'
    editPrintoutSize.value = selected.value.printout_size ?? 'a4'
    editPrintoutWidth.value = selected.value.printout_width ?? null
    editPrintoutHeight.value = selected.value.printout_height ?? null
    editOutputFormats.value = selected.value.output_formats ?? []
    editTemplateIds.value = (selected.value.templates ?? []).map(t => t.id)
    editTemplateHeaderId.value = (def.template_header_id as number) ?? null
    editTemplateFooterId.value = (def.template_footer_id as number) ?? null
    addTemplateId.value = null
    testConnResult.value = ''
    fixedParamsEnabled.value = (def.fixed_parameters_enabled as Record<string, boolean>) ?? {}
    customParams.value = (def.custom_parameters as CustomParam[]) ?? []
    customParamValues.value = Object.fromEntries(customParams.value.map(p => [p.id, p.default_value ?? (p.type === 'checkbox' ? false : '')]))
    requireParamScreen.value = (def.require_parameter_screen as boolean) ?? true
    // seed role grants
    const map: Record<number, { view: boolean; edit: boolean; run: boolean }> = {}
    for (const g of selected.value.permissions ?? []) map[g.role_id] = { view: g.view, edit: g.edit, run: g.run }
    perms.value = map
    // seed run params from dataset
    runParams.value = {}
    for (const p of boundDataset.value?.parameters ?? []) runParams.value[p.name] = p.default ?? ''
    // load history
    await loadHistory()
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Failed to open report'
  }
}

// Builds the definition object exactly as save() would send it, from the
// live (possibly unsaved) editor state — reused by save() and by Preview so
// Preview always reflects what's actually on screen right now, not whatever
// was last persisted to the database.
function buildDraftDefinition(): Record<string, unknown> {
  let definition: Record<string, unknown>
  try { definition = JSON.parse(defText.value) } catch { throw new Error('Definition is not valid JSON') }
  definition.prompt = prompt.value
  definition.prompt_history = promptHistory.value
  definition.fixed_parameters_enabled = fixedParamsEnabled.value
  definition.custom_parameters = customParams.value
  definition.require_parameter_screen = requireParamScreen.value
  definition.template_header_id = editTemplateHeaderId.value
  definition.template_footer_id = editTemplateFooterId.value
  return definition
}

// ── Save ─────────────────────────────────────────────────────────────────────
async function save() {
  if (!selected.value) return
  if (selected.value.locked) { runError.value = 'This report is locked. Unlock it first.'; return }
  busy.value = true; saveMsg.value = ''; runError.value = ''
  try {
    const definition = buildDraftDefinition()
    const permissions = Object.entries(perms.value)
      .filter(([, v]) => v.view || v.edit || v.run)
      .map(([role_id, v]) => ({ role_id: Number(role_id), ...v }))
    const r = await apiRequest<{ data: Report }>(`/reports/${selected.value.id}`, {
      method: 'PUT',
      body: JSON.stringify({
        name: editName.value, description: editDesc.value,
        type: editType.value, status: editStatus.value,
        tags: editTagsText.value.split(',').map(s => s.trim().replace(/^#/, '')).filter(Boolean),
        dataset_id: editDatasetId.value,
        layout: editLayout.value, printout_size: editPrintoutSize.value,
        printout_width: editPrintoutSize.value === 'custom' ? editPrintoutWidth.value : null,
        printout_height: editPrintoutSize.value === 'custom' ? editPrintoutHeight.value : null,
        output_formats: editOutputFormats.value,
        templates: editTemplateIds.value,
        definition, permissions,
      }),
    })
    selected.value = r.data
    saveMsg.value = 'Saved'
    setTimeout(() => saveMsg.value = '', 2000)
    await load()
    await loadHistory()
  } catch (e) {
    runError.value = e instanceof Error ? e.message : 'Save failed'
    bottomTab.value = 'error'
  } finally {
    busy.value = false
  }
}

// ── Preview (sample data, no live datasource call) ───────────────────────────
async function previewReport() {
  if (!selected.value) return
  busy.value = true; runError.value = ''; preview.value = ''
  try {
    const res = await apiRequest<{ data: { html: string; row_count: number } }>(`/reports/${selected.value.id}/preview`, { method: 'POST' })
    preview.value  = res.data.html
    rowCount.value = res.data.row_count
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Preview failed'
    bottomTab.value = 'error'
    bottomOpen.value = true
  } finally {
    busy.value = false
  }
}

// ── Preview Mode popup — tick which parameters to use, run with real data,
// then print/save/export straight from the popup. ──────────────────────────
const showPreviewModal = ref(false)
const previewModalHtml = ref('')
const previewModalRowCount = ref<number | null>(null)
const previewModalBusy = ref(false)
const previewModalError = ref('')
const previewModalExporting = ref<'csv' | 'excel' | null>(null)
const previewModalStarted = ref(false) // false = show the parameter screen (Run/Cancel); true = show the result
const previewModalMaximized = ref(false)

// Only send params whose checkbox is ticked (fixedParamsEnabled — the same
// state as the Parameters panel's checkboxes, so ticking there or here is one
// single source of truth) — previously run() sent every param regardless of
// its checkbox, making that checkbox purely cosmetic.
const activePreviewParams = computed(() => {
  const out: Record<string, string> = {}
  for (const p of boundDataset.value?.parameters ?? []) {
    if (fixedParamsEnabled.value[p.name] ?? true) out[p.name] = runParams.value[p.name] ?? ''
  }
  return out
})
// Which dataset/custom parameters actually appear on the Preview parameter
// screen — whatever's enabled in the editor's Parameters panel, nothing more.
const enabledDatasetParams = computed(() => (boundDataset.value?.parameters ?? []).filter(p => fixedParamsEnabled.value[p.name] ?? true))
const enabledCustomParams = computed(() => customParams.value.filter(p => p.enabled))

async function openPreviewModal() {
  if (!selected.value) return
  showPreviewModal.value = true
  previewModalHtml.value = ''
  previewModalError.value = ''
  previewModalRowCount.value = null
  previewModalStarted.value = false
  // Show the parameter screen first — the report only actually runs once the
  // user explicitly clicks Run (or Cancel to back out without running).
  // Only skip straight to running when "Show parameter screen before
  // running" is explicitly off — if it's on, always show the screen
  // (even with zero dataset params, it still shows a "no parameters, just
  // click Run" message) since the toggle is what the user controls directly.
  if (!requireParamScreen.value) await runPreviewModal()
}
async function runPreviewModal() {
  if (!selected.value) return
  previewModalStarted.value = true
  previewModalBusy.value = true
  previewModalError.value = ''
  try {
    // Send the live, possibly-unsaved editor state so Preview reflects what's
    // actually on screen (columns, template header/footer, conditional
    // rules, toggles, etc.) rather than the last-saved database version.
    let definition: Record<string, unknown> | undefined
    try { definition = buildDraftDefinition() } catch { definition = undefined }
    const res = await apiRequest<{ data: { html: string; row_count: number } }>(`/reports/${selected.value.id}/run`, {
      method: 'POST', body: JSON.stringify({ params: activePreviewParams.value, definition }),
    })
    previewModalHtml.value = res.data.html
    previewModalRowCount.value = res.data.row_count
  } catch (e) {
    previewModalError.value = e instanceof ApiException ? e.error.message : 'Run failed'
  } finally {
    previewModalBusy.value = false
  }
}
function exitPreviewModal() {
  showPreviewModal.value = false
}
function printPreviewModal() {
  const w = window.open('', '_blank')
  if (!w) return
  w.document.write(`<html><head><title>${selected.value?.name ?? 'Report'}</title></html><body>${previewModalHtml.value}</body></html>`)
  w.document.close()
  w.focus()
  w.print()
}
async function exportPreviewModal(format: 'csv' | 'excel') {
  if (!selected.value) return
  previewModalExporting.value = format
  try {
    const res = await fetch(`${(import.meta as { env: { VITE_API_BASE_URL?: string } }).env.VITE_API_BASE_URL ?? ''}/api/reports/${selected.value.id}/export-file`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${localStorage.getItem('airr_token') ?? ''}` },
      body: JSON.stringify({ format, params: activePreviewParams.value, definition: (() => { try { return buildDraftDefinition() } catch { return undefined } })() }),
    })
    if (!res.ok) {
      const json = await res.json().catch(() => null)
      throw new Error(json?.error?.message ?? 'Export failed')
    }
    const blob = await res.blob()
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `${selected.value.name}.${format === 'csv' ? 'csv' : 'xlsx'}`
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  } catch (e) {
    previewModalError.value = e instanceof Error ? e.message : 'Export failed'
  } finally {
    previewModalExporting.value = null
  }
}

// ── Publish / Lock ────────────────────────────────────────────────────────────
async function publishReport() {
  if (!selected.value) return
  busy.value = true
  try {
    const r = await apiRequest<{ data: Report }>(`/reports/${selected.value.id}/publish`, { method: 'POST' })
    selected.value = r.data
    editStatus.value = r.data.status
    await load()
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Publish failed'
  } finally {
    busy.value = false
  }
}
async function toggleLock() {
  if (!selected.value) return
  busy.value = true
  try {
    const path = selected.value.locked ? 'unlock' : 'lock'
    const r = await apiRequest<{ data: Report }>(`/reports/${selected.value.id}/${path}`, { method: 'POST' })
    selected.value = r.data
    await load()
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Failed'
  } finally {
    busy.value = false
  }
}

// ── Datasource: test connection, template attach ─────────────────────────────
async function testConnection() {
  if (!editDataSourceId.value) return
  testConnBusy.value = true; testConnResult.value = ''
  try {
    const res = await apiRequest<{ data: { ok: boolean; message: string } }>(`/data-sources/${editDataSourceId.value}/test`, { method: 'POST' })
    testConnResult.value = res.data.ok ? `✓ ${res.data.message}` : `✗ ${res.data.message}`
  } catch (e) {
    testConnResult.value = e instanceof ApiException ? `✗ ${e.error.message}` : '✗ Test failed'
  } finally {
    testConnBusy.value = false
  }
}
function addTemplateToReport() {
  if (addTemplateId.value && !editTemplateIds.value.includes(addTemplateId.value)) {
    editTemplateIds.value = [...editTemplateIds.value, addTemplateId.value]
  }
  addTemplateId.value = null
}
function removeTemplateFromReport(id: number) {
  editTemplateIds.value = editTemplateIds.value.filter(t => t !== id)
}
function templateName(id: number) {
  return allTemplates.value.find(t => t.id === id)?.name ?? `#${id}`
}

// ── Run ──────────────────────────────────────────────────────────────────────
async function run() {
  if (!selected.value) return
  busy.value = true; runError.value = ''; preview.value = ''
  try {
    const res = await apiRequest<{ data: { html: string; row_count: number } }>(`/reports/${selected.value.id}/run`, {
      method: 'POST', body: JSON.stringify({ params: runParams.value }),
    })
    preview.value  = res.data.html
    rowCount.value = res.data.row_count
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Run failed'
    bottomTab.value = 'error'
    bottomOpen.value = true
  } finally {
    busy.value = false
  }
}

// ── Create ───────────────────────────────────────────────────────────────────
async function create() {
  busy.value = true
  try {
    const r = await apiRequest<{ data: Report }>('/reports', {
      method: 'POST',
      body: JSON.stringify({
        name: createForm.value.name, description: createForm.value.description,
        type: createForm.value.type, dataset_id: createForm.value.dataset_id,
      }),
    })
    showCreate.value = false
    await load()
    await openReport(r.data)
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Create failed'
  } finally {
    busy.value = false
  }
}

// ── Bottom tabs ───────────────────────────────────────────────────────────────
async function loadHistory() {
  if (!selected.value) return
  bottomBusy.value = true
  try {
    historyEntries.value = (await apiRequest<{ data: HistoryEntry[] }>(`/reports/${selected.value.id}/history`)).data
  } finally {
    bottomBusy.value = false
  }
}
async function switchBottomTab(tab: 'history' | 'error' | 'api') {
  bottomTab.value = tab
  if (!selected.value) return
  if (tab === 'history' && !historyEntries.value.length) await loadHistory()
  if (tab === 'error' && !logEntries.value.length) {
    bottomBusy.value = true
    try {
      logEntries.value = (await apiRequest<{ data: LogEntry[] }>(`/reports/${selected.value.id}/logs`)).data
    } finally {
      bottomBusy.value = false
    }
  }
}

async function restoreHistory(entry: HistoryEntry) {
  if (!selected.value || !confirm('Restore this version? This saves it as the current version.')) return
  const def = (entry.snapshot?.definition as Record<string, unknown>) ?? {}
  defText.value = JSON.stringify(def, null, 2)
  editName.value   = (entry.snapshot?.name as string) ?? editName.value
  editType.value   = (entry.snapshot?.type as string) ?? editType.value
  editStatus.value = (entry.snapshot?.status as string) ?? editStatus.value
  prompt.value = (def.prompt as string) ?? (entry.snapshot?.prompt as string) ?? prompt.value
  // save() rebuilds definition.custom_parameters / fixed_parameters_enabled from
  // these two refs (not from defText) — they MUST be resynced from the restored
  // snapshot here, otherwise save() clobbers the restored version's parameters
  // with whatever was left in memory from before the restore.
  fixedParamsEnabled.value = (def.fixed_parameters_enabled as Record<string, boolean>) ?? {}
  customParams.value = (def.custom_parameters as CustomParam[]) ?? []
  customParamValues.value = Object.fromEntries(customParams.value.map(p => [p.id, p.default_value ?? (p.type === 'checkbox' ? false : '')]))
  requireParamScreen.value = (def.require_parameter_screen as boolean) ?? true
  promptHistory.value = (def.prompt_history as PromptHistoryEntry[]) ?? promptHistory.value
  editTemplateHeaderId.value = (def.template_header_id as number) ?? null
  editTemplateFooterId.value = (def.template_footer_id as number) ?? null
  await save()
  await previewReport()
}

// Clear history — either everything, or just the checked rows.
const selectedHistoryIds = ref<Set<number>>(new Set())
function toggleHistorySelect(id: number) {
  const next = new Set(selectedHistoryIds.value)
  next.has(id) ? next.delete(id) : next.add(id)
  selectedHistoryIds.value = next
}
async function clearHistory(idsOnly = false) {
  if (!selected.value) return
  const ids = idsOnly ? Array.from(selectedHistoryIds.value) : undefined
  if (idsOnly && !ids?.length) return
  if (!confirm(idsOnly ? `Delete ${ids!.length} selected history entr${ids!.length === 1 ? 'y' : 'ies'}?` : 'Clear ALL history for this report? This cannot be undone.')) return
  await apiRequest(`/reports/${selected.value.id}/history`, { method: 'DELETE', body: JSON.stringify({ ids }) })
  selectedHistoryIds.value = new Set()
  historyEntries.value = []
  await loadHistory()
}

// "Team" icon — who has touched this report (from history + creator).
const contributors = computed(() => {
  const names = new Set<string>()
  if (selected.value?.creator?.name) names.add(selected.value.creator.name)
  for (const h of historyEntries.value) if (h.changed_by_name) names.add(h.changed_by_name)
  return Array.from(names)
})
const showContributors = ref(false)
function ensureHistoryLoaded() { if (!historyEntries.value.length) loadHistory() }
function toggleContributors() {
  showContributors.value = !showContributors.value
  if (showContributors.value) ensureHistoryLoaded()
}
const showVersionMenu = ref(false)
const versionPageIndex = ref(0)
const versionPageSize = 10
const versionPageCount = computed(() => Math.max(1, Math.ceil(historyEntries.value.length / versionPageSize)))
const versionPage = computed(() => {
  const start = versionPageIndex.value * versionPageSize
  return historyEntries.value.slice(start, start + versionPageSize)
})
function toggleVersionMenu() {
  showVersionMenu.value = !showVersionMenu.value
  versionPageIndex.value = 0
  if (showVersionMenu.value) ensureHistoryLoaded()
}
function onVersionPick(entry: HistoryEntry) {
  showVersionMenu.value = false
  restoreHistory(entry)
}

// ── Permissions ───────────────────────────────────────────────────────────────
function grant(roleId: number) { return perms.value[roleId] ?? { view: false, edit: false, run: false } }
function toggleGrant(roleId: number, field: 'view' | 'edit' | 'run') {
  const cur = grant(roleId)
  perms.value = { ...perms.value, [roleId]: { ...cur, [field]: !cur[field] } }
}
function allGranted(field: 'view' | 'edit' | 'run') {
  return roles.value.length > 0 && roles.value.every(r => grant(r.id)[field])
}
function toggleAllGrant(field: 'view' | 'edit' | 'run') {
  const next = !allGranted(field)
  const map = { ...perms.value }
  for (const r of roles.value) map[r.id] = { ...grant(r.id), [field]: next }
  perms.value = map
}

// ── Report-level parameters ──────────────────────────────────────────────────
function openAddParam() {
  editingParam.value = null
  paramForm.value = { id: '', title: '', type: 'text', default_value: '', options: [], min: null, max: null, source_type: 'datasource', data_source_id: null, dataset_id: null, data_column: null, remark: '', enabled: true }
  paramOptionsText.value = ''
  paramOptionsJsonError.value = ''
  loadConstants()
  showParamModal.value = true
}
function openEditParam(p: CustomParam) {
  editingParam.value = p
  paramForm.value = { source_type: 'datasource', ...p }
  paramOptionsText.value = p.source_type === 'json' ? JSON.stringify(p.options ?? [], null, 0) : (p.options ?? []).join(', ')
  paramOptionsJsonError.value = ''
  loadConstants()
  showParamModal.value = true
}
function saveParam() {
  if (!paramForm.value.title) return
  const isList = ['dropdown', 'radio', 'checkbox'].includes(paramForm.value.type)
  if (isList && paramForm.value.source_type === 'json') {
    try {
      const parsed = JSON.parse(paramOptionsText.value || '[]')
      if (!Array.isArray(parsed)) throw new Error('not an array')
      paramForm.value.options = parsed
      paramOptionsJsonError.value = ''
    } catch {
      paramOptionsJsonError.value = 'Invalid JSON array — fix it before saving.'
      return
    }
  } else if (isList) {
    paramForm.value.options = []
  } else {
    paramForm.value.options = []
  }
  if (editingParam.value) {
    customParams.value = customParams.value.map(p => p.id === editingParam.value!.id ? { ...paramForm.value } : p)
    customParamValues.value[editingParam.value.id] = paramForm.value.default_value ?? (paramForm.value.type === 'checkbox' ? false : '')
  } else {
    const id = `p${Date.now()}`
    customParams.value = [...customParams.value, { ...paramForm.value, id }]
    customParamValues.value[id] = paramForm.value.default_value ?? (paramForm.value.type === 'checkbox' ? false : '')
  }
  showParamModal.value = false
}
function deleteParam(id: string) {
  customParams.value = customParams.value.filter(p => p.id !== id)
  delete customParamValues.value[id]
}
function toggleCustomParamEnabled(id: string) {
  customParams.value = customParams.value.map(p => p.id === id ? { ...p, enabled: !p.enabled } : p)
}
const datasetColumnsForParam = computed(() => {
  const ds = allDatasets.value.find(d => d.id === paramForm.value.dataset_id)
  return ds?.parameters?.map(p => p.name) ?? []
})

// ── Prompt: AI rewrites the report definition ────────────────────────────────
const promptFile = ref<File | null>(null)
const promptDropActive = ref(false)
function onPromptFileChange(e: Event) {
  promptFile.value = (e.target as HTMLInputElement).files?.[0] ?? null
}
function onPromptDrop(e: DragEvent) {
  promptDropActive.value = false
  promptFile.value = e.dataTransfer?.files?.[0] ?? null
}
async function generateFromPrompt() {
  if (!selected.value || !prompt.value) return
  promptBusy.value = true; runError.value = ''
  try {
    // The backend now persists the AI-edited definition directly (and logs a
    // dedicated 'ai_generated' history entry with the prompt) — no separate
    // save() needed here.
    const form = new FormData()
    form.append('prompt', prompt.value)
    if (promptFile.value) form.append('file', promptFile.value)
    const res = await uploadFile<{ data: { definition: Record<string, unknown>; report: Report } }>(`/reports/${selected.value.id}/generate-from-prompt`, form)
    defText.value = JSON.stringify(res.data.definition, null, 2)
    selected.value = res.data.report
    promptHistory.value = (res.data.definition.prompt_history as PromptHistoryEntry[]) ?? promptHistory.value
    prompt.value = '' // already recorded in prompt history below — don't leave it sitting in the box
    promptFile.value = null
    await run()
    historyEntries.value = []
    await loadHistory()
  } catch (e) {
    runError.value = e instanceof ApiException ? e.error.message : 'Generate failed'
    bottomTab.value = 'error'
  } finally {
    promptBusy.value = false
  }
}

function usePreviousPrompt(text: string) { prompt.value = text }
// Save the prompt text to history without calling the AI — for jotting an idea down first.
async function savePromptOnly() {
  if (!prompt.value) return
  promptHistory.value = [...promptHistory.value, { text: prompt.value, at: new Date().toISOString() }]
  await save()
}
function copyPreviousPrompt(text: string) { navigator.clipboard?.writeText(text) }
const showAllPrompts = ref(false)
const selectedPromptIdx = ref<Set<number>>(new Set())
const displayedPromptHistory = computed(() => {
  const withIdx = promptHistory.value.map((p, idx) => ({ ...p, idx })).reverse()
  return showAllPrompts.value ? withIdx : withIdx.slice(0, 3)
})
function togglePromptSelect(idx: number) {
  const next = new Set(selectedPromptIdx.value)
  next.has(idx) ? next.delete(idx) : next.add(idx)
  selectedPromptIdx.value = next
}
async function deleteSelectedPrompts() {
  if (!selectedPromptIdx.value.size) return
  promptHistory.value = promptHistory.value.filter((_, idx) => !selectedPromptIdx.value.has(idx))
  selectedPromptIdx.value = new Set()
  await save()
}

// ── Clipboard ─────────────────────────────────────────────────────────────────
function copyLink() { navigator.clipboard?.writeText(shareLink.value); showShareMenu.value = false }
async function duplicateFromEditor() {
  if (!selected.value) return
  showShareMenu.value = false
  await duplicateReport(selected.value)
  selected.value = null // duplicate lands in the card list, not this editor
}
const showShareMenu = ref(false)

watch([sortBy, showArchived], load)
onMounted(load)
function closeMenuOnOutsideClick() { openMenuId.value = null }
onMounted(() => document.addEventListener('click', closeMenuOnOutsideClick))
onUnmounted(() => document.removeEventListener('click', closeMenuOnOutsideClick))
onUnmounted(() => { window.removeEventListener('mousemove', onResizeMove); window.removeEventListener('mouseup', onResizeEnd) })
</script>

<template>
  <AdminLayout>
    <!-- strip default padding: negative margin trick -->
    <div class="-m-6 flex flex-col" style="height: calc(100vh - 4rem)">

      <!-- ── Top bar ─────────────────────────────────────────────────────── -->
      <div class="shrink-0 flex items-center justify-between px-6 py-3 border-b border-slate-100 bg-white">
        <div>
          <h1 class="text-base font-semibold text-slate-800 leading-tight">{{ selected ? selected.name : 'Reports' }}</h1>
          <p class="text-xs text-slate-400">{{ selected ? (selected.description || 'No description') : 'Build, preview, and share data-driven reports.' }}</p>
        </div>
        <div class="flex items-center gap-2">
          <!-- Version badge + paginated history dropdown -->
          <div v-if="selected" class="relative">
            <button @click="toggleVersionMenu" class="text-xs border border-slate-200 rounded px-2 py-0.5 text-slate-500 bg-white hover:bg-slate-50">
              v {{ selected.version_label ?? '—' }}
            </button>
            <div v-if="showVersionMenu" class="absolute right-0 mt-1 w-72 bg-white border border-slate-200 rounded-lg shadow-lg z-20 py-1" @click.self="showVersionMenu = false">
              <div v-if="!historyEntries.length" class="text-xs text-slate-400 px-3 py-2">No history yet.</div>
              <button v-for="h in versionPage" :key="h.id" @click="onVersionPick(h)"
                class="w-full text-left text-xs text-slate-600 hover:bg-slate-50 px-3 py-1.5">
                v{{ h.version_label }} — {{ h.changed_by_name }} ({{ new Date(h.created_at).toLocaleString() }})
              </button>
              <div v-if="historyEntries.length > versionPageSize" class="flex items-center justify-between px-3 pt-1.5 mt-1 border-t border-slate-100">
                <button @click="versionPageIndex--" :disabled="versionPageIndex === 0" class="text-xs text-airr-600 disabled:text-slate-300 disabled:cursor-not-allowed">‹ Prev</button>
                <span class="text-[11px] text-slate-400">{{ versionPageIndex + 1 }} / {{ versionPageCount }}</span>
                <button @click="versionPageIndex++" :disabled="versionPageIndex >= versionPageCount - 1" class="text-xs text-airr-600 disabled:text-slate-300 disabled:cursor-not-allowed">Next ›</button>
              </div>
            </div>
          </div>
          <!-- Team icon — who has worked on this report (only relevant once one's open) -->
          <div v-if="selected" class="relative">
            <button @click="toggleContributors" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100" title="Contributors">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-5-5M9 20H4v-2a4 4 0 015-5m6-4a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </button>
            <div v-if="showContributors" class="absolute right-0 mt-1 w-56 bg-white border border-slate-200 rounded-lg shadow-lg z-20 p-2" @click.self="showContributors = false">
              <p class="text-[10px] font-semibold text-slate-400 uppercase px-2 pb-1">Contributors</p>
              <div v-if="!contributors.length" class="text-xs text-slate-400 px-2 py-1">No history yet.</div>
              <div v-for="n in contributors" :key="n" class="text-sm text-slate-600 px-2 py-1">{{ n }}</div>
            </div>
          </div>
          <!-- Copy / duplicate menu (only relevant once a report is open) -->
          <div v-if="selected" class="relative">
            <button @click="showShareMenu = !showShareMenu" class="text-slate-400 hover:text-slate-600 p-1.5 rounded-lg hover:bg-slate-100" title="Copy link or duplicate report">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
            </button>
            <div v-if="showShareMenu" class="absolute right-0 mt-1 w-48 bg-white border border-slate-200 rounded-lg shadow-lg z-20 py-1" @click.self="showShareMenu = false">
              <button @click="copyLink" class="w-full text-left text-sm text-slate-600 hover:bg-slate-50 px-3 py-1.5">Copy report link</button>
              <button @click="duplicateFromEditor" class="w-full text-left text-sm text-slate-600 hover:bg-slate-50 px-3 py-1.5">Duplicate report</button>
            </div>
          </div>
          <button v-if="selected" @click="selected = null" class="text-sm text-slate-500 hover:text-slate-700 px-2 py-1.5">← Back to reports</button>
          <template v-if="canCreate && !selected">
            <button v-if="sidebar" @click="sidebar.setCollapsed(!sidebar.collapsed.value)" class="text-sm text-slate-500 border border-slate-200 hover:bg-slate-50 rounded-lg px-2.5 py-1.5" title="Collapse/expand the main menu">
              {{ sidebar.collapsed.value ? '»' : '«' }}
            </button>
            <button @click="triggerImport" class="text-sm font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5">Import</button>
            <input ref="importInput" type="file" accept=".json,application/json" class="hidden" @change="onImportFile" />
          </template>
          <button v-if="canCreate" @click="showCreate = true; createForm = { name: '', description: '', type: 'table', data_source_id: null, dataset_id: null }"
            class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5">+ New Report</button>
        </div>
      </div>

      <!-- ── Card grid landing (no report selected) ───────────────────────── -->
      <div v-if="!selected" class="flex-1 overflow-y-auto p-6 bg-white">
        <!-- Hashtag filter -->
        <div v-if="allReportTags.length" class="flex flex-wrap items-center gap-1.5 mb-3">
          <span class="text-xs text-slate-400">Filter by hashtag:</span>
          <button v-for="tag in allReportTags" :key="tag" @click="activeReportTag = activeReportTag === tag ? null : tag"
            class="text-xs rounded-full px-2.5 py-1"
            :class="activeReportTag === tag ? 'bg-airr-500 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'">
            #{{ tag }}
          </button>
        </div>
        <!-- Controls: select-all, sort, archived toggle, bulk actions -->
        <div class="flex items-center justify-between gap-3 mb-4 flex-wrap">
          <div class="flex items-center gap-3">
            <label class="flex items-center gap-1.5 text-xs text-slate-500">
              <input type="checkbox" :checked="allSelected" @change="toggleSelectAll" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" />
              Select all
            </label>
            <label class="flex items-center gap-1.5 text-xs text-slate-500">
              <input type="checkbox" v-model="showArchived" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" />
              Show archived
            </label>
            <select v-model="sortBy" class="text-xs rounded-lg border border-slate-200 px-2 py-1.5 outline-none focus:ring-2 focus:ring-airr-300">
              <option value="-updated_at">Newest first</option>
              <option value="updated_at">Oldest first</option>
              <option value="name">Name A–Z</option>
              <option value="-name">Name Z–A</option>
              <option value="status">Status</option>
            </select>
            <input v-model="reportSearchText" placeholder="Search reports…" class="text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 w-40" />
          </div>
          <div v-if="selectedIds.size" class="flex items-center gap-2">
            <span class="text-xs text-slate-400">{{ selectedIds.size }} selected</span>
            <button @click="bulkDuplicate" :disabled="busy" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-2.5 py-1.5">Duplicate</button>
            <button @click="bulkExport" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-2.5 py-1.5">Export</button>
            <button @click="bulkArchive" :disabled="busy" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-2.5 py-1.5">Archive</button>
            <button @click="bulkDelete" :disabled="busy" class="text-xs font-medium text-rose-600 border border-rose-200 hover:bg-rose-50 rounded-lg px-2.5 py-1.5">Delete</button>
          </div>
        </div>

        <div v-if="loading" class="text-slate-400 text-sm">Loading…</div>
        <div v-else-if="!reports.length" class="text-slate-400 text-sm">No reports yet.</div>
        <div v-else-if="!filteredReports.length" class="text-slate-400 text-sm">No reports match.</div>
        <div v-else class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          <div v-for="r in filteredReports" :key="r.id" class="relative bg-white rounded-xl border border-slate-100 p-4 flex flex-col gap-2 hover:border-slate-200 transition"
            :class="r.archived_at ? 'opacity-50' : ''"
            :style="{ borderLeft: `4px solid ${STATUS_ACCENT[r.status] ?? '#94A3B8'}` }">
            <div class="flex items-start justify-between gap-2">
              <div class="flex items-start gap-2 min-w-0 cursor-pointer" @click="openReport(r)">
                <input type="checkbox" :checked="selectedIds.has(r.id)" @click.stop @change="toggleSelect(r.id)"
                  class="mt-1 rounded border-slate-300 text-airr-500 focus:ring-airr-300 shrink-0" />
                <div class="min-w-0">
                  <div class="font-semibold text-slate-700 truncate">{{ r.name }}</div>
                  <div class="text-xs text-slate-400 truncate">{{ r.type }} · {{ r.dataset?.name ?? 'no dataset' }}</div>
                </div>
              </div>
              <div class="flex items-center gap-1 shrink-0">
                <span v-if="r.archived_at" class="text-[10px] rounded-full px-1.5 py-0.5 bg-slate-200 text-slate-500">archived</span>
                <span class="text-xs font-medium rounded-full px-2 py-0.5" :class="STATUS_BADGE[r.status] ?? 'bg-slate-100 text-slate-500'">{{ r.status }}</span>
                <!-- Per-card action menu -->
                <div class="relative">
                  <button @click.stop="toggleMenu(r.id)" class="text-slate-400 hover:text-slate-600 px-1 rounded hover:bg-slate-100">⋮</button>
                  <div v-if="openMenuId === r.id"
                    class="absolute right-0 top-full mt-1 w-40 bg-white border border-slate-200 rounded-xl shadow-lg z-20 py-1 text-xs">
                    <button @click.stop="duplicateReport(r)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Duplicate</button>
                    <button @click.stop="exportReport(r)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Export</button>
                    <button @click.stop="openCardLog(r)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Log</button>
                    <button @click.stop="copyReportUrl(r)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Copy URL</button>
                    <button @click.stop="toggleDebug(r.id); openMenuId = null" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">
                      {{ debugIds.has(r.id) ? '✓ Debug mode' : 'Debug mode' }}
                    </button>
                    <div class="border-t border-slate-100 my-1"></div>
                    <button v-if="!r.archived_at" @click.stop="archiveReport(r)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Archive</button>
                    <button v-else @click.stop="unarchiveReport(r)" class="w-full text-left px-3 py-1.5 hover:bg-slate-50 text-slate-600">Unarchive</button>
                    <button @click.stop="deleteReportCard(r)" class="w-full text-left px-3 py-1.5 hover:bg-rose-50 text-rose-600">Delete</button>
                  </div>
                </div>
              </div>
            </div>
            <p v-if="r.description" class="text-xs text-slate-500 line-clamp-2 cursor-pointer" @click="openReport(r)">{{ r.description }}</p>
            <div v-if="r.tags?.length" class="flex flex-wrap gap-1">
              <span v-for="tag in r.tags" :key="tag" class="text-[10px] bg-airr-50 text-airr-600 rounded-full px-2 py-0.5">#{{ tag }}</span>
            </div>
            <div class="text-xs text-slate-400 cursor-pointer" @click="openReport(r)">{{ r.project?.name ?? 'global' }}</div>
            <div class="text-xs text-slate-400 mt-auto cursor-pointer" @click="openReport(r)">by {{ r.creator?.name ?? '—' }}</div>
          </div>
        </div>

        <!-- Deleted reports -->
        <div v-if="deletedReports.length" class="mt-6">
          <p class="text-xs font-medium text-slate-400 mb-2">Deleted reports</p>
          <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <div v-for="r in deletedReports" :key="r.id" class="bg-slate-50 rounded-xl border border-dashed border-slate-200 p-4 flex flex-col gap-2 opacity-60">
              <div class="font-semibold text-slate-600 truncate line-through">{{ r.name }}</div>
              <p v-if="r.description" class="text-xs text-slate-400 line-clamp-2">{{ r.description }}</p>
              <button @click="restoreReport(r)" :disabled="busy" class="text-xs text-emerald-600 hover:underline font-medium self-start">Undo delete</button>
            </div>
          </div>
        </div>
      </div>

      <!-- ── 3-column editor ──────────────────────────────────────────────── -->
      <div v-else class="flex-1 flex overflow-hidden min-h-0">

        <!-- LEFT PANEL -->
        <aside :style="{ width: leftPanelCollapsed ? '36px' : leftWidth + 'px' }" class="shrink-0 flex flex-col border-r border-slate-100 overflow-y-auto bg-white">
          <button @click="leftPanelCollapsed = !leftPanelCollapsed" class="shrink-0 text-slate-400 hover:text-slate-600 text-xs px-2 py-1.5 text-left border-b border-slate-50">
            {{ leftPanelCollapsed ? '»' : '« Collapse' }}
          </button>
          <template v-if="selected && !leftPanelCollapsed">
            <!-- Prompt -->
            <div class="border-b border-slate-50" :style="{ order: leftOrder.indexOf('prompt') }" @dragover.prevent @drop="onDrop('prompt', 'left')">
              <button draggable="true" @dragstart="onDragStart('prompt')" @click="toggleSection('prompt')" class="w-full flex items-center justify-between px-3 py-2.5 text-left hover:bg-slate-50 cursor-move">
                <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">⠿ Prompt</span>
                <span class="text-slate-400 text-xs">{{ openSections.prompt ? '▾' : '▸' }}</span>
              </button>
              <div v-show="openSections.prompt" class="px-3 pb-3">
                <p class="text-[10px] text-slate-400 mb-1.5">Tell the AI how this report should look — e.g. "switch to a form layout", "remove the email column", "add a running total".</p>
                <textarea v-model="prompt" rows="6" placeholder="Describe what this report should show… (or drop an image/document here)"
                  @dragover.prevent="promptDropActive = true" @dragleave.prevent="promptDropActive = false" @drop.prevent="onPromptDrop"
                  class="w-full text-xs rounded-lg border px-2.5 py-2 outline-none focus:ring-2 focus:ring-airr-300 resize-none transition"
                  :class="promptDropActive ? 'border-airr-400 bg-airr-50' : 'border-slate-200'"></textarea>
                <div class="flex items-center gap-1.5 mt-1.5">
                  <label class="text-[11px] text-slate-500 border border-slate-200 hover:bg-slate-50 rounded-lg px-2 py-1 cursor-pointer">
                    📎 Attach <span class="text-slate-400">/ drop file</span>
                    <input type="file" accept="image/png,image/jpeg,image/webp,application/pdf,.txt,.md,.csv" class="hidden" @change="onPromptFileChange" />
                  </label>
                  <span v-if="promptFile" class="text-[11px] text-slate-500 truncate max-w-[7rem]" :title="promptFile.name">{{ promptFile.name }}</span>
                  <button v-if="promptFile" @click="promptFile = null" class="text-[11px] text-rose-500">✕</button>
                </div>
                <p v-if="promptFile" class="text-[10px] text-slate-400 mt-1">The AI will read this image/document together with your instruction.</p>
                <div class="flex gap-1.5 mt-1.5">
                  <button @click="prompt = ''" :disabled="!prompt" class="text-[11px] text-slate-500 border border-slate-200 hover:bg-slate-50 rounded-lg px-2 py-1 disabled:opacity-40">Clear</button>
                  <button @click="copyPreviousPrompt(prompt)" :disabled="!prompt" class="text-[11px] text-slate-500 border border-slate-200 hover:bg-slate-50 rounded-lg px-2 py-1 disabled:opacity-40">Copy</button>
                  <button @click="savePromptOnly" :disabled="!prompt || selected.locked" class="text-[11px] text-slate-500 border border-slate-200 hover:bg-slate-50 rounded-lg px-2 py-1 disabled:opacity-40">Save</button>
                  <button @click="generateFromPrompt" :disabled="!prompt || promptBusy || selected.locked"
                    class="flex-1 text-xs font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1 disabled:opacity-40">
                    {{ promptBusy ? 'Generating…' : 'Generate' }}
                  </button>
                </div>
                <div v-if="promptHistory.length" class="mt-2 space-y-1">
                  <div class="flex items-center justify-between">
                    <p class="text-[10px] font-semibold text-slate-400 uppercase">Previous prompts</p>
                    <button v-if="selectedPromptIdx.size" @click="deleteSelectedPrompts" class="text-[10px] text-rose-600 hover:underline">Delete selected</button>
                  </div>
                  <div class="max-h-56 overflow-y-auto space-y-1">
                    <div v-for="p in displayedPromptHistory" :key="p.idx"
                      class="flex items-start justify-between gap-1.5 text-[11px] border border-slate-100 rounded-lg px-2 py-1.5">
                      <input type="checkbox" :checked="selectedPromptIdx.has(p.idx)" @change="togglePromptSelect(p.idx)" class="mt-0.5 rounded border-slate-300 text-airr-500 focus:ring-airr-300 shrink-0" />
                      <div class="min-w-0 flex-1">
                        <div class="text-slate-600 truncate" :title="p.text">{{ p.text }}</div>
                        <div class="text-slate-400">{{ new Date(p.at).toLocaleString() }}</div>
                      </div>
                      <div class="flex flex-col items-end gap-0.5 shrink-0">
                        <button @click="copyPreviousPrompt(p.text)" class="text-airr-600 hover:underline">Copy</button>
                        <button @click="usePreviousPrompt(p.text)" class="text-airr-600 hover:underline">Use</button>
                      </div>
                    </div>
                  </div>
                  <button v-if="promptHistory.length > 3" @click="showAllPrompts = !showAllPrompts" class="text-[10px] text-slate-400 hover:underline">
                    {{ showAllPrompts ? 'Show less' : `Show all (${promptHistory.length})` }}
                  </button>
                </div>
              </div>
            </div>

            <!-- Permission (starts closed) -->
            <div class="border-b border-slate-50" :style="{ order: leftOrder.indexOf('permission') }" @dragover.prevent @drop="onDrop('permission', 'left')">
              <button draggable="true" @dragstart="onDragStart('permission')" @click="toggleSection('permission')" class="w-full flex items-center justify-between px-3 py-2.5 text-left hover:bg-slate-50 cursor-move">
                <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">⠿ Permission</span>
                <span class="text-slate-400 text-xs">{{ openSections.permission ? '▾' : '▸' }}</span>
              </button>
              <div v-show="openSections.permission" class="px-3 pb-3">
                <div class="text-[10px] text-slate-400 mb-1.5">Access — which roles can use this report</div>
                <table class="w-full text-xs">
                  <thead>
                    <tr class="text-[10px] text-slate-400">
                      <th class="text-left font-medium pb-1">Role</th>
                      <th class="text-center font-medium w-8 pb-1">View</th>
                      <th class="text-center font-medium w-8 pb-1">Run</th>
                      <th class="text-center font-medium w-8 pb-1">Edit</th>
                    </tr>
                    <tr class="text-[10px] text-slate-400 border-t border-slate-50">
                      <td class="py-1 font-medium">All</td>
                      <td class="text-center py-1"><input type="checkbox" :checked="allGranted('view')" @change="toggleAllGrant('view')" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /></td>
                      <td class="text-center py-1"><input type="checkbox" :checked="allGranted('run')" @change="toggleAllGrant('run')" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /></td>
                      <td class="text-center py-1"><input type="checkbox" :checked="allGranted('edit')" @change="toggleAllGrant('edit')" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /></td>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="role in roles" :key="role.id" class="border-t border-slate-50">
                      <td class="py-1 text-slate-600 text-[11px]">{{ role.name }}</td>
                      <td class="text-center py-1"><input type="checkbox" :checked="grant(role.id).view" @change="toggleGrant(role.id, 'view')" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /></td>
                      <td class="text-center py-1"><input type="checkbox" :checked="grant(role.id).run" @change="toggleGrant(role.id, 'run')" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /></td>
                      <td class="text-center py-1"><input type="checkbox" :checked="grant(role.id).edit" @change="toggleGrant(role.id, 'edit')" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Parameters -->
            <div :style="{ order: leftOrder.indexOf('parameters') }" @dragover.prevent @drop="onDrop('parameters', 'left')">
              <button draggable="true" @dragstart="onDragStart('parameters')" @click="toggleSection('parameters')" class="w-full flex items-center justify-between px-3 py-2.5 text-left hover:bg-slate-50 cursor-move">
                <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">⠿ Parameters</span>
                <span class="text-slate-400 text-xs">{{ openSections.parameters ? '▾' : '▸' }}</span>
              </button>
              <div v-show="openSections.parameters" class="px-3 pb-3 space-y-3">
                <!-- Preview behaviour -->
                <div class="flex items-start gap-2 bg-slate-50 rounded-lg px-2 py-1.5">
                  <input type="checkbox" :checked="requireParamScreen" @change="requireParamScreen = !requireParamScreen"
                    class="rounded border-slate-300 text-airr-500 focus:ring-airr-300 shrink-0 mt-0.5" />
                  <div class="min-w-0 flex-1">
                    <label class="block text-[11px] font-medium text-slate-600">Show parameter screen before running</label>
                    <p class="text-[10px] text-slate-400">If off, Preview skips straight to the report using current parameter values.</p>
                  </div>
                </div>
                <!-- Fixed (built-in + dataset) -->
                <div>
                  <div class="text-[10px] font-medium text-slate-400 uppercase tracking-wide mb-1">Fixed</div>
                  <div class="space-y-1.5">
                    <!-- Built-in: Layout type -->
                    <div class="flex items-center gap-2">
                      <input type="checkbox" :checked="fixedParamsEnabled['layout_type'] ?? true"
                        @change="fixedParamsEnabled = { ...fixedParamsEnabled, layout_type: !(fixedParamsEnabled['layout_type'] ?? true) }"
                        class="rounded border-slate-300 text-airr-500 focus:ring-airr-300 shrink-0" />
                      <div class="min-w-0 flex-1">
                        <label class="block text-[10px] text-slate-500 mb-0.5">Layout type</label>
                        <select v-model="editLayout" class="w-full text-xs rounded border border-slate-200 px-2 py-1 outline-none focus:ring-2 focus:ring-airr-300 capitalize">
                          <option v-for="l in LAYOUTS" :key="l" :value="l">{{ l }}</option>
                        </select>
                      </div>
                    </div>
                    <!-- Built-in: Layout size -->
                    <div class="flex items-center gap-2">
                      <input type="checkbox" :checked="fixedParamsEnabled['layout_size'] ?? true"
                        @change="fixedParamsEnabled = { ...fixedParamsEnabled, layout_size: !(fixedParamsEnabled['layout_size'] ?? true) }"
                        class="rounded border-slate-300 text-airr-500 focus:ring-airr-300 shrink-0" />
                      <div class="min-w-0 flex-1">
                        <label class="block text-[10px] text-slate-500 mb-0.5">Layout size</label>
                        <select v-model="editPrintoutSize" class="w-full text-xs rounded border border-slate-200 px-2 py-1 outline-none focus:ring-2 focus:ring-airr-300 uppercase">
                          <option v-for="p in PRINTOUT_SIZES" :key="p" :value="p">{{ p }}</option>
                        </select>
                      </div>
                    </div>
                    <!-- Built-in: Template header/footer — which attached Template's
                         header/footer content wraps the report output. -->
                    <div class="flex items-center gap-2">
                      <input type="checkbox" :checked="fixedParamsEnabled['template_header'] ?? true"
                        @change="fixedParamsEnabled = { ...fixedParamsEnabled, template_header: !(fixedParamsEnabled['template_header'] ?? true) }"
                        class="rounded border-slate-300 text-airr-500 focus:ring-airr-300 shrink-0" />
                      <div class="min-w-0 flex-1">
                        <label class="block text-[10px] text-slate-500 mb-0.5">Template header</label>
                        <select v-model="editTemplateHeaderId" class="w-full text-xs rounded border border-slate-200 px-2 py-1 outline-none focus:ring-2 focus:ring-airr-300">
                          <option :value="null">— none —</option>
                          <option v-for="t in allTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                      </div>
                    </div>
                    <div class="flex items-center gap-2">
                      <input type="checkbox" :checked="fixedParamsEnabled['template_footer'] ?? true"
                        @change="fixedParamsEnabled = { ...fixedParamsEnabled, template_footer: !(fixedParamsEnabled['template_footer'] ?? true) }"
                        class="rounded border-slate-300 text-airr-500 focus:ring-airr-300 shrink-0" />
                      <div class="min-w-0 flex-1">
                        <label class="block text-[10px] text-slate-500 mb-0.5">Template footer</label>
                        <select v-model="editTemplateFooterId" class="w-full text-xs rounded border border-slate-200 px-2 py-1 outline-none focus:ring-2 focus:ring-airr-300">
                          <option :value="null">— none —</option>
                          <option v-for="t in allTemplates" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                      </div>
                    </div>
                    <!-- Dataset-driven -->
                    <div v-for="p in (boundDataset?.parameters ?? [])" :key="p.name" class="flex items-center gap-2">
                      <input type="checkbox" :checked="fixedParamsEnabled[p.name] ?? true"
                        @change="fixedParamsEnabled = { ...fixedParamsEnabled, [p.name]: !(fixedParamsEnabled[p.name] ?? true) }"
                        class="rounded border-slate-300 text-airr-500 focus:ring-airr-300 shrink-0" />
                      <div class="min-w-0 flex-1">
                        <label class="block text-[10px] text-slate-500 mb-0.5 truncate">{{ p.label || p.name }}</label>
                        <input v-model="runParams[p.name]"
                          :type="p.type === 'number' ? 'number' : p.type === 'date' ? 'date' : 'text'"
                          class="w-full text-xs rounded border border-slate-200 px-2 py-1 outline-none focus:ring-2 focus:ring-airr-300" />
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Custom (report-defined) -->
                <div>
                  <div class="flex items-center justify-between mb-1">
                    <span class="text-[10px] font-medium text-slate-400 uppercase tracking-wide">Custom</span>
                    <button v-if="!selected.locked" @click="openAddParam" class="text-[11px] text-airr-600 hover:underline">+ Add</button>
                  </div>
                  <div v-if="!customParams.length" class="text-xs text-slate-400">No custom parameters.</div>
                  <div v-else class="space-y-1">
                    <div v-for="p in customParams" :key="p.id" class="text-xs border border-slate-100 rounded-lg px-2 py-1.5 space-y-1">
                      <label class="flex items-center gap-1.5 min-w-0 cursor-pointer">
                        <input type="checkbox" :checked="p.enabled" @change="toggleCustomParamEnabled(p.id)" :disabled="selected.locked"
                          class="rounded border-slate-300 text-airr-500 focus:ring-airr-300 shrink-0" />
                        <span class="text-slate-600 truncate">{{ p.title }}</span>
                        <span class="text-[10px] text-slate-400 shrink-0">({{ p.type }})</span>
                      </label>
                      <div v-if="!selected.locked" class="flex items-center gap-3 justify-end">
                        <button @click="openEditParam(p)" class="text-airr-600 hover:underline">Edit</button>
                        <button @click="deleteParam(p.id)" class="text-rose-500 hover:underline">Delete</button>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </template>
        </aside>
        <div v-if="!leftPanelCollapsed" @mousedown="startResize('left', $event)" class="w-1 shrink-0 cursor-col-resize hover:bg-airr-300 active:bg-airr-400"></div>

        <!-- CENTER PANEL (Preview) -->
        <main class="flex-1 flex flex-col min-w-0 overflow-hidden bg-slate-50">
          <div v-if="!selected" class="flex-1 flex items-center justify-center text-slate-400 text-sm">
            Select a report to preview and edit.
          </div>
          <template v-else>
            <!-- Preview toolbar -->
            <div class="shrink-0 flex items-center justify-between px-4 py-2 border-b border-slate-100 bg-white">
              <span class="text-xs font-medium text-slate-500">Preview<span v-if="rowCount !== null"> · {{ rowCount }} rows</span></span>
              <div class="flex items-center gap-2">
                <span v-if="saveMsg" class="text-xs text-emerald-600">{{ saveMsg }}</span>
                <span v-if="selected.locked" class="text-[10px] rounded-full px-2 py-0.5 bg-slate-200 text-slate-500">🔒 locked</span>
                <button @click="toggleDebug(selected.id)"
                  class="text-xs font-medium rounded-lg px-3 py-1.5 border"
                  :class="debugIds.has(selected.id) ? 'bg-slate-800 text-white border-slate-800' : 'text-slate-600 border-slate-200 hover:bg-slate-50'">
                  Debug
                </button>
                <button v-if="canEdit" @click="save" :disabled="busy || selected.locked"
                  class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5 disabled:opacity-50">
                  {{ busy ? 'Saving…' : 'Save' }}
                </button>
                <button v-if="canRun" @click="run" :disabled="busy"
                  class="text-xs font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5 disabled:opacity-50">
                  {{ busy ? '…' : 'Run' }}
                </button>
                <button v-if="canRun" @click="openPreviewModal" :disabled="busy"
                  class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5 disabled:opacity-50">
                  Preview
                </button>
                <button v-if="canEdit" @click="toggleLock" :disabled="busy"
                  class="text-xs font-medium rounded-lg px-3 py-1.5 border"
                  :class="selected.locked ? 'bg-amber-500 text-white border-amber-500' : 'text-slate-600 border-slate-200 hover:bg-slate-50'">
                  {{ selected.locked ? 'Unlock' : 'Lock' }}
                </button>
              </div>
            </div>
            <!-- Debug mode: raw definition JSON + full error trace -->
            <div v-if="debugIds.has(selected.id)" class="shrink-0 border-b border-slate-100 bg-slate-900 text-slate-100 p-3 max-h-40 overflow-y-auto">
              <div class="text-[10px] uppercase tracking-wide text-slate-400 mb-1">Debug — Definition JSON</div>
              <pre class="text-[10px] font-mono whitespace-pre-wrap break-all">{{ JSON.stringify(selected.definition ?? {}, null, 2) }}</pre>
              <template v-if="runError">
                <div class="text-[10px] uppercase tracking-wide text-rose-400 mt-2 mb-1">Debug — Error</div>
                <pre class="text-[10px] font-mono text-rose-300 whitespace-pre-wrap break-all">{{ runError }}</pre>
              </template>
            </div>
            <!-- Preview content -->
            <div class="flex-1 overflow-auto p-5">
              <div v-if="preview" class="bg-white rounded-xl border border-slate-100 p-5 shadow-sm">
                <div v-html="preview"></div>
              </div>
              <div v-else class="flex flex-col items-center justify-center h-full text-slate-300 gap-2">
                <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <span class="text-sm">Click Run to preview report</span>
              </div>
            </div>
          </template>
        </main>

        <!-- RIGHT PANEL -->
        <div v-if="selected && !rightPanelCollapsed" @mousedown="startResize('right', $event)" class="w-1 shrink-0 cursor-col-resize hover:bg-airr-300 active:bg-airr-400"></div>
        <aside v-if="selected" :style="{ width: rightPanelCollapsed ? '36px' : rightWidth + 'px' }" class="shrink-0 flex flex-col border-l border-slate-100 overflow-y-auto bg-white">
          <button @click="rightPanelCollapsed = !rightPanelCollapsed" class="shrink-0 text-slate-400 hover:text-slate-600 text-xs px-2 py-1.5 text-left border-b border-slate-50">
            {{ rightPanelCollapsed ? '«' : 'Collapse »' }}
          </button>
          <template v-if="!rightPanelCollapsed">
          <!-- Setting -->
          <div class="border-b border-slate-50" :style="{ order: rightOrder.indexOf('setting') }" @dragover.prevent @drop="onDrop('setting', 'right')">
            <button draggable="true" @dragstart="onDragStart('setting')" @click="toggleSection('setting')" class="w-full flex items-center justify-between px-3 py-2.5 text-left hover:bg-slate-50 cursor-move">
              <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">⠿ Setting</span>
              <span class="text-slate-400 text-xs">{{ openSections.setting ? '▾' : '▸' }}</span>
            </button>
            <div v-show="openSections.setting" class="px-3 pb-3 space-y-2">
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Name</label>
                <input v-model="editName" :disabled="selected.locked" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 disabled:bg-slate-50" />
              </div>
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Type</label>
                <select v-model="editType" :disabled="selected.locked" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 disabled:bg-slate-50">
                  <option v-for="t in TYPES" :key="t" :value="t">{{ TYPE_LABELS[t] ?? t }}</option>
                </select>
              </div>
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Status</label>
                <select v-model="editStatus" :disabled="selected.locked" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 disabled:bg-slate-50">
                  <option value="draft">Draft</option>
                  <option value="published">Published</option>
                </select>
              </div>
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Hashtags <span class="text-slate-400 font-normal">(comma-separated)</span></label>
                <input v-model="editTagsText" :disabled="selected.locked" placeholder="kutipan, pbt" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 disabled:bg-slate-50" />
              </div>
              <div class="flex items-center justify-between">
                <span class="text-[11px] text-slate-500">Version <span class="font-mono text-slate-600">v{{ selected.version ?? 0 }}</span></span>
                <button v-if="canEdit" @click="publishReport" :disabled="busy || selected.locked" class="text-[11px] text-airr-600 hover:underline disabled:opacity-40">Publish (compile)</button>
              </div>
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Layout</label>
                <div class="flex gap-1.5">
                  <button v-for="l in LAYOUTS" :key="l" @click="editLayout = l" :disabled="selected.locked"
                    class="flex-1 text-xs rounded-lg border px-2 py-1.5 text-center capitalize transition disabled:opacity-40"
                    :class="editLayout === l ? 'border-airr-400 bg-airr-50 text-airr-700 font-medium' : 'border-slate-200 text-slate-500 hover:border-slate-300'">
                    {{ l }}
                  </button>
                </div>
              </div>
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Printout</label>
                <select v-model="editPrintoutSize" :disabled="selected.locked" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 disabled:bg-slate-50 uppercase">
                  <option v-for="p in PRINTOUT_SIZES" :key="p" :value="p">{{ p }}</option>
                </select>
                <div v-if="editPrintoutSize === 'custom'" class="flex gap-1.5 mt-1.5">
                  <input v-model.number="editPrintoutWidth" type="number" placeholder="Width (mm)" :disabled="selected.locked"
                    class="w-full text-xs rounded-lg border border-slate-200 px-2 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 disabled:bg-slate-50" />
                  <input v-model.number="editPrintoutHeight" type="number" placeholder="Height (mm)" :disabled="selected.locked"
                    class="w-full text-xs rounded-lg border border-slate-200 px-2 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 disabled:bg-slate-50" />
                </div>
              </div>
              <div>
                <label class="block text-[11px] text-slate-500 mb-1">Output</label>
                <div class="flex gap-3">
                  <label v-for="o in OUTPUT_FORMAT_OPTS" :key="o" class="flex items-center gap-1 text-xs text-slate-600 capitalize">
                    <input type="checkbox" :value="o" :checked="editOutputFormats.includes(o)" :disabled="selected.locked"
                      @change="editOutputFormats = editOutputFormats.includes(o) ? editOutputFormats.filter(x => x !== o) : [...editOutputFormats, o]"
                      class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" />
                    {{ o }}
                  </label>
                </div>
              </div>
            </div>
          </div>

          <!-- Template -->
          <div class="border-b border-slate-50" :style="{ order: rightOrder.indexOf('template') }" @dragover.prevent @drop="onDrop('template', 'right')">
            <button draggable="true" @dragstart="onDragStart('template')" @click="toggleSection('template')" class="w-full flex items-center justify-between px-3 py-2.5 text-left hover:bg-slate-50 cursor-move">
              <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">⠿ Template</span>
              <span class="text-slate-400 text-xs">{{ openSections.template ? '▾' : '▸' }}</span>
            </button>
            <div v-show="openSections.template" class="px-3 pb-3">
              <p class="text-[10px] text-slate-400 mb-1.5">Attach one or more Templates (Author) — e.g. one for the page header, another for the report header.</p>
              <div v-if="!editTemplateIds.length" class="text-xs text-slate-400 mb-2">No templates attached.</div>
              <div v-else class="space-y-1 mb-2">
                <div v-for="tid in editTemplateIds" :key="tid" class="flex items-center justify-between text-xs border border-slate-100 rounded-lg px-2 py-1.5">
                  <span class="truncate text-slate-600">{{ templateName(tid) }}</span>
                  <button v-if="!selected.locked" @click="removeTemplateFromReport(tid)" class="text-rose-500 hover:underline shrink-0 ml-2">Delete</button>
                </div>
              </div>
              <div v-if="!selected.locked" class="flex gap-1.5">
                <select v-model="addTemplateId" class="flex-1 text-xs rounded-lg border border-slate-200 px-2 py-1.5 outline-none focus:ring-2 focus:ring-airr-300">
                  <option :value="null">— select template —</option>
                  <option v-for="t in templatesAvailableToAdd" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
                <button @click="addTemplateToReport" :disabled="!addTemplateId" class="text-xs font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-2.5 py-1.5 disabled:opacity-40">+ Add</button>
              </div>
            </div>
          </div>

          <!-- Datasource -->
          <div class="border-b border-slate-50" :style="{ order: rightOrder.indexOf('datasource') }" @dragover.prevent @drop="onDrop('datasource', 'right')">
            <button draggable="true" @dragstart="onDragStart('datasource')" @click="toggleSection('datasource')" class="w-full flex items-center justify-between px-3 py-2.5 text-left hover:bg-slate-50 cursor-move">
              <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">⠿ Datasource</span>
              <span class="text-slate-400 text-xs">{{ openSections.datasource ? '▾' : '▸' }}</span>
            </button>
            <div v-show="openSections.datasource" class="px-3 pb-3 space-y-2">
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Data source</label>
                <select v-model="editDataSourceId" :disabled="selected.locked" @change="editDatasetId = null; testConnResult = ''"
                  class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 disabled:bg-slate-50">
                  <option :value="null">— none —</option>
                  <option v-for="s in dataSources" :key="s.id" :value="s.id">{{ s.name }}</option>
                </select>
              </div>
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Dataset</label>
                <select v-model="editDatasetId" :disabled="selected.locked || !editDataSourceId"
                  class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 disabled:bg-slate-50">
                  <option :value="null">— none —</option>
                  <option v-for="d in datasetsForEdit" :key="d.id" :value="d.id">{{ d.name }}</option>
                </select>
              </div>
              <div v-if="editDataSourceId" class="flex items-center gap-2">
                <button @click="testConnection" :disabled="testConnBusy" class="text-[11px] text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-2.5 py-1 disabled:opacity-50">
                  {{ testConnBusy ? 'Testing…' : 'Test connection' }}
                </button>
                <span v-if="testConnResult" class="text-[11px]" :class="testConnResult.startsWith('✓') ? 'text-emerald-600' : 'text-rose-600'">{{ testConnResult }}</span>
              </div>
              <div v-if="boundDataSource?.type === 'json'" class="text-[10px] text-slate-400 bg-slate-50 rounded-lg px-2 py-1.5">
                This data source is JSON-based — edit its raw content from Data Sources.
              </div>
              <div v-if="boundDataset" class="text-[10px] text-slate-400">
                Fields: {{ boundDataset.parameters?.length ?? 0 }} parameter(s) · dataset #{{ boundDataset.id }}
              </div>
            </div>
          </div>

          <!-- Property -->
          <div :style="{ order: rightOrder.indexOf('property') }" @dragover.prevent @drop="onDrop('property', 'right')">
            <button draggable="true" @dragstart="onDragStart('property')" @click="toggleSection('property')" class="w-full flex items-center justify-between px-3 py-2.5 text-left hover:bg-slate-50 cursor-move">
              <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">⠿ Property</span>
              <span class="text-slate-400 text-xs">{{ openSections.property ? '▾' : '▸' }}</span>
            </button>
            <div v-show="openSections.property" class="px-3 pb-3 space-y-2">
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Description</label>
                <textarea v-model="editDesc" :disabled="selected.locked" rows="3" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 resize-none disabled:bg-slate-50"></textarea>
              </div>
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Definition JSON</label>
                <textarea v-model="defText" :disabled="selected.locked" rows="8" spellcheck="false"
                  class="w-full font-mono text-[10px] rounded-lg border border-slate-200 px-2 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 resize-none disabled:bg-slate-50"></textarea>
              </div>
              <div class="text-[10px] text-slate-400">
                Creator: {{ selected.creator?.name ?? '—' }}<br/>
                Updated: {{ selected.updated_at ? new Date(selected.updated_at).toLocaleString() : '—' }}
              </div>
            </div>
          </div>
          </template>
        </aside>
      </div>

      <!-- ── Bottom panel (tabs) — only relevant once a report is open ───── -->
      <div v-if="selected" class="shrink-0 border-t border-slate-100 bg-white flex flex-col"
        :style="{ height: !bottomOpen ? '36px' : (bottomMaximized ? '70vh' : bottomHeight + 'px') }">
        <div v-if="bottomOpen" @mousedown="startResize('bottom', $event)" class="h-1 -mt-1 shrink-0 cursor-row-resize hover:bg-airr-300 active:bg-airr-400"></div>
        <!-- Tab bar -->
        <div class="flex items-center gap-0 border-b border-slate-100 px-4 shrink-0">
          <button v-for="tab in (['history', 'error', 'api'] as const)" :key="tab"
            @click="switchBottomTab(tab)"
            class="text-xs font-medium px-3 py-2 border-b-2 capitalize transition"
            :class="bottomTab === tab ? 'border-airr-500 text-airr-600' : 'border-transparent text-slate-400 hover:text-slate-600'">
            {{ tab === 'error' ? 'Error' : tab === 'api' ? 'API' : 'History' }}
          </button>
          <div class="flex-1"></div>
          <template v-if="bottomTab === 'history' && historyEntries.length">
            <span class="text-[11px] text-slate-400 mr-3">{{ selectedHistoryIds.size }} selected</span>
            <button v-if="selectedHistoryIds.size" @click="clearHistory(true)" class="text-[11px] text-rose-600 hover:underline mr-3">Delete selected</button>
            <button @click="clearHistory(false)" class="text-[11px] text-rose-600 hover:underline mr-3">Clear all</button>
          </template>
          <button v-if="bottomOpen" @click="bottomMaximized = !bottomMaximized" class="text-xs text-slate-400 hover:text-slate-600 px-2 py-1.5" :title="bottomMaximized ? 'Restore' : 'Maximize'">
            {{ bottomMaximized ? '⤡' : '⤢' }}
          </button>
          <button @click="bottomOpen = !bottomOpen; bottomMaximized = false" class="text-xs text-slate-400 hover:text-slate-600 px-2 py-1.5">
            {{ bottomOpen ? '▾' : '▴' }}
          </button>
        </div>

        <!-- Tab content -->
        <div v-if="bottomOpen" class="overflow-y-auto flex-1 min-h-0">
          <!-- History tab -->
          <div v-if="bottomTab === 'history'" class="p-3">
            <div v-if="!selected" class="text-xs text-slate-400">Select a report first.</div>
            <div v-else-if="bottomBusy" class="text-xs text-slate-400">Loading…</div>
            <div v-else-if="!historyEntries.length" class="text-xs text-slate-400">No history yet — save the report to create the first entry.</div>
            <template v-else>
              <div class="space-y-1.5">
                <div v-for="h in historyEntries" :key="h.id"
                  class="flex items-center justify-between gap-3 text-xs border border-slate-100 rounded-lg px-3 py-2">
                  <div class="flex items-center gap-2 min-w-0">
                    <input type="checkbox" :checked="selectedHistoryIds.has(h.id)" @change="toggleHistorySelect(h.id)" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300 shrink-0" />
                    <span class="text-slate-400 shrink-0">{{ new Date(h.created_at).toLocaleString() }}</span>
                    <span class="text-slate-400 shrink-0 font-mono">v{{ h.version_label }}</span>
                    <span class="font-medium text-slate-700">{{ HISTORY_ACTION_LABELS[h.action] ?? h.action }}</span>
                    <span class="text-slate-400 truncate">by {{ h.changed_by_name }}</span>
                    <span v-if="h.action === 'ai_generated' && h.snapshot?.prompt" class="text-airr-600 truncate">— "{{ h.snapshot.prompt }}"</span>
                    <span v-else-if="h.snapshot?.name" class="text-slate-500 truncate">— {{ h.snapshot.name }}</span>
                  </div>
                  <div class="flex items-center gap-1.5 shrink-0">
                    <button @click="restoreHistory(h)" class="text-[11px] text-airr-600 hover:underline">Restore</button>
                  </div>
                </div>
              </div>
            </template>
          </div>

          <!-- Error tab -->
          <div v-else-if="bottomTab === 'error'" class="p-3">
            <div v-if="runError" class="text-xs text-rose-700 bg-rose-50 rounded-lg px-3 py-2">{{ runError }}</div>
            <div v-else-if="bottomBusy" class="text-xs text-slate-400">Loading…</div>
            <div v-else-if="!logEntries.length" class="text-xs text-slate-400">No errors logged.</div>
            <div v-else class="space-y-1.5">
              <div v-for="l in logEntries.filter(l => l.event?.includes('error') || l.event?.includes('fail'))" :key="l.id"
                class="text-xs border border-rose-100 rounded-lg px-3 py-2 bg-rose-50">
                <span class="text-slate-400 mr-2">{{ new Date(l.created_at).toLocaleString() }}</span>
                <span class="font-medium text-rose-700">{{ l.event }}</span>
                <span class="text-slate-500 ml-2">by {{ l.user_name }}</span>
              </div>
              <div v-if="!logEntries.filter(l => l.event?.includes('error') || l.event?.includes('fail')).length"
                class="text-xs text-slate-400">No errors found in access log.</div>
            </div>
          </div>

          <!-- API tab -->
          <div v-else-if="bottomTab === 'api'" class="p-4 space-y-3">
            <div v-if="!selected" class="text-xs text-slate-400">Select a report first.</div>
            <template v-else>
              <div>
                <div class="text-[11px] font-medium text-slate-500 mb-1">Endpoint</div>
                <div class="flex items-center gap-2">
                  <span class="text-xs bg-emerald-100 text-emerald-700 rounded px-1.5 py-0.5 font-mono">POST</span>
                  <code class="text-xs font-mono text-slate-700 bg-slate-50 rounded px-2 py-1 flex-1">{{ apiEndpoint }}</code>
                  <button @click="navigator.clipboard?.writeText(apiEndpoint)" class="text-slate-400 hover:text-slate-600 text-xs">copy</button>
                </div>
              </div>
              <div>
                <div class="text-[11px] font-medium text-slate-500 mb-1">Authentication</div>
                <code class="text-xs font-mono text-slate-600 bg-slate-50 rounded px-2 py-1 block">Authorization: Bearer &lt;token&gt;</code>
              </div>
              <div>
                <div class="text-[11px] font-medium text-slate-500 mb-1">Request body</div>
                <pre class="text-[10px] font-mono text-slate-600 bg-slate-50 rounded px-2 py-1.5">{"params": { "param_name": "value" }}</pre>
              </div>
              <div>
                <div class="text-[11px] font-medium text-slate-500 mb-1">Status</div>
                <span class="text-xs rounded-full px-2 py-0.5" :class="STATUS_BADGE[selected.status] ?? 'bg-slate-100 text-slate-500'">{{ selected.status }}</span>
              </div>
            </template>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Create modal ───────────────────────────────────────────────────── -->
    <div v-if="showCreate" class="fixed inset-0 bg-black/30 flex items-center justify-center p-4 z-50" @click.self="showCreate = false">
      <div class="bg-white rounded-xl p-6 w-full max-w-md space-y-4">
        <h2 class="font-semibold text-slate-800">New Report</h2>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Name</label>
          <input v-model="createForm.name" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Description <span class="text-slate-400 font-normal">· optional</span></label>
          <textarea v-model="createForm.description" rows="2" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none"></textarea>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Type</label>
          <select v-model="createForm.type" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
            <option v-for="t in TYPES" :key="t" :value="t">{{ t }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Data source <span class="text-slate-400 font-normal">· optional</span></label>
          <select v-model="createForm.data_source_id" @change="createForm.dataset_id = null" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
            <option :value="null">— none —</option>
            <option v-for="s in dataSources" :key="s.id" :value="s.id">{{ s.name }}</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Dataset <span class="text-slate-400 font-normal">· optional</span></label>
          <select v-model="createForm.dataset_id" :disabled="!createForm.data_source_id" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none disabled:bg-slate-50">
            <option :value="null">— none —</option>
            <option v-for="d in datasetsForCreate" :key="d.id" :value="d.id">{{ d.name }}</option>
          </select>
        </div>
        <div class="flex justify-end gap-2 pt-1">
          <button @click="showCreate = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button @click="create" :disabled="busy || !createForm.name" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
            {{ busy ? 'Creating…' : 'Create' }}
          </button>
        </div>
      </div>
    </div>

    <!-- ── Custom parameter modal ────────────────────────────────────────── -->
    <div v-if="showParamModal" class="fixed inset-0 bg-black/30 flex items-center justify-center p-4 z-50" @click.self="showParamModal = false">
      <div class="bg-white rounded-xl p-6 w-full max-w-md space-y-3">
        <h2 class="font-semibold text-slate-800">{{ editingParam ? 'Edit Parameter' : 'Add Parameter' }}</h2>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Title</label>
          <input v-model="paramForm.title" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Type</label>
          <select v-model="paramForm.type" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
            <option value="text">Text (default value)</option>
            <option value="dropdown">Dropdown</option>
            <option value="checkbox">Checkbox (multi-select)</option>
            <option value="radio">Radio</option>
            <option value="date">Date</option>
            <option value="datetime">Date &amp; Time</option>
            <option value="time">Time</option>
            <option value="amount">Amount</option>
          </select>
        </div>

        <!-- Default value per type -->
        <div v-if="paramForm.type === 'text'">
          <div class="flex items-center justify-between mb-1">
            <label class="text-sm font-medium text-slate-600">Default value</label>
            <select v-if="constants.length" @change="insertConstant(($event.target as HTMLSelectElement).value); ($event.target as HTMLSelectElement).value = ''"
              class="text-[11px] border border-slate-200 rounded px-1.5 py-0.5 text-slate-500">
              <option value="">Insert constant…</option>
              <option v-for="c in constants" :key="c.id" :value="constantToken(c)">{{ c.label || c.key }}</option>
            </select>
          </div>
          <input v-model="paramForm.default_value as string" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div v-else-if="['date', 'datetime', 'time'].includes(paramForm.type)">
          <label class="block text-sm font-medium text-slate-600 mb-1">Default value</label>
          <input v-model="paramForm.default_value as string"
            :type="paramForm.type === 'datetime' ? 'datetime-local' : paramForm.type"
            class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
        </div>
        <div v-else-if="paramForm.type === 'amount'" class="grid grid-cols-3 gap-2">
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Default</label>
            <input v-model="paramForm.default_value as string" type="number" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Min</label>
            <input v-model.number="paramForm.min" type="number" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
          <div>
            <label class="block text-sm font-medium text-slate-600 mb-1">Max</label>
            <input v-model.number="paramForm.max" type="number" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none" />
          </div>
        </div>

        <!-- Options source for dropdown/radio/checkbox: JSON list or a datasource column -->
        <template v-if="['dropdown', 'radio', 'checkbox'].includes(paramForm.type)">
          <div class="flex gap-4 text-sm text-slate-600">
            <label class="flex items-center gap-1.5">
              <input type="radio" value="json" v-model="paramForm.source_type" class="text-airr-500 focus:ring-airr-300" /> JSON list
            </label>
            <label class="flex items-center gap-1.5">
              <input type="radio" value="datasource" v-model="paramForm.source_type" class="text-airr-500 focus:ring-airr-300" /> Data source
            </label>
          </div>
          <div v-if="paramForm.source_type === 'json'">
            <label class="block text-sm font-medium text-slate-600 mb-1">Options <span class="text-slate-400 font-normal">· JSON array</span></label>
            <textarea v-model="paramOptionsText" rows="3" placeholder='["Option A", "Option B"] or [{"value":"a","label":"Option A"}]'
              class="w-full rounded-lg border border-slate-200 px-3 py-2 font-mono text-xs focus:ring-2 focus:ring-airr-300 outline-none"></textarea>
            <p v-if="paramOptionsJsonError" class="text-xs text-rose-600 mt-1">{{ paramOptionsJsonError }}</p>
          </div>
          <div v-else class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-sm font-medium text-slate-600 mb-1">Data source</label>
              <select v-model="paramForm.data_source_id" @change="paramForm.dataset_id = null; paramForm.data_column = null" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
                <option :value="null">— none —</option>
                <option v-for="s in dataSources" :key="s.id" :value="s.id">{{ s.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-slate-600 mb-1">Dataset</label>
              <select v-model="paramForm.dataset_id" @change="paramForm.data_column = null" :disabled="!paramForm.data_source_id" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none disabled:bg-slate-50">
                <option :value="null">— none —</option>
                <option v-for="d in datasetsForParam" :key="d.id" :value="d.id">{{ d.name }}</option>
              </select>
            </div>
            <div v-if="paramForm.dataset_id" class="col-span-2">
              <label class="block text-sm font-medium text-slate-600 mb-1">Column</label>
              <select v-model="paramForm.data_column" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none">
                <option :value="null">— none —</option>
                <option v-for="c in datasetColumnsForParam" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
          </div>
        </template>

        <div>
          <label class="block text-sm font-medium text-slate-600 mb-1">Remark <span class="text-slate-400 font-normal">· optional</span></label>
          <textarea v-model="paramForm.remark" rows="2" class="w-full rounded-lg border border-slate-200 px-3 py-2 focus:ring-2 focus:ring-airr-300 outline-none"></textarea>
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-600">
          <input type="checkbox" v-model="paramForm.enabled" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" />
          Enabled
        </label>
        <div class="flex justify-end gap-2 pt-1">
          <button @click="showParamModal = false" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
          <button @click="saveParam" :disabled="!paramForm.title" class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
            Save
          </button>
        </div>
      </div>
    </div>

    <!-- Preview Mode popup -->
    <div v-if="showPreviewModal" class="fixed inset-0 bg-slate-900/40 flex items-center justify-center z-50"
      :class="previewModalMaximized ? '' : 'px-4 py-6'" @click.self="exitPreviewModal">
      <div class="bg-white shadow-xl flex flex-col"
        :class="previewModalMaximized ? 'w-screen h-screen rounded-none' : 'rounded-2xl w-full max-w-5xl h-full max-h-[90vh]'">
        <!-- Header -->
        <div class="shrink-0 flex items-center justify-between px-5 py-3 border-b border-slate-100">
          <h3 class="font-bold text-lg">Preview — {{ selected?.name }}</h3>
          <div class="flex items-center gap-3">
            <button @click="previewModalMaximized = !previewModalMaximized" class="text-slate-400 hover:text-slate-600 text-sm" :title="previewModalMaximized ? 'Restore' : 'Maximize'">
              {{ previewModalMaximized ? '🗗' : '🗖' }}
            </button>
            <button @click="exitPreviewModal" class="text-slate-400 hover:text-slate-600 text-xl leading-none">×</button>
          </div>
        </div>

        <!-- Step 1: parameter screen — which parameters appear here (and whether
             this screen shows at all) is decided in the editor's Parameters
             panel; this is just the runtime value-entry form for whatever was
             enabled there. Layout/template header/footer aren't "parameters"
             a user fills in — they're applied automatically when rendering. -->
        <div v-if="!previewModalStarted" class="flex-1 overflow-y-auto p-6 flex items-start justify-center">
          <div class="w-full max-w-md space-y-3">
            <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">Parameters</p>
            <div v-if="!enabledDatasetParams.length && !enabledCustomParams.length" class="text-xs text-slate-400">No parameters — just click Run.</div>

            <div v-for="p in enabledDatasetParams" :key="p.name">
              <label class="block text-[10px] text-slate-500 mb-0.5">{{ p.label || p.name }}</label>
              <input v-model="runParams[p.name]"
                :type="p.type === 'number' ? 'number' : p.type === 'date' ? 'date' : 'text'"
                class="w-full text-xs rounded border border-slate-200 px-2 py-1 outline-none focus:ring-2 focus:ring-airr-300" />
            </div>

            <div v-for="p in enabledCustomParams" :key="p.id">
              <label class="block text-[10px] text-slate-500 mb-0.5">{{ p.title }}</label>
              <select v-if="p.type === 'dropdown'" v-model="customParamValues[p.id]"
                class="w-full text-xs rounded border border-slate-200 px-2 py-1 outline-none focus:ring-2 focus:ring-airr-300">
                <option v-for="o in (p.options ?? [])" :key="o" :value="o">{{ o }}</option>
              </select>
              <div v-else-if="p.type === 'radio'" class="flex flex-wrap gap-3 pt-0.5">
                <label v-for="o in (p.options ?? [])" :key="o" class="flex items-center gap-1 text-xs text-slate-600">
                  <input type="radio" :name="`cp_${p.id}`" :value="o" v-model="customParamValues[p.id]" /> {{ o }}
                </label>
              </div>
              <label v-else-if="p.type === 'checkbox'" class="flex items-center gap-1.5 text-xs text-slate-600 pt-0.5">
                <input type="checkbox" v-model="customParamValues[p.id]" class="rounded border-slate-300 text-airr-500 focus:ring-airr-300" /> Yes
              </label>
              <input v-else v-model="customParamValues[p.id]"
                :type="p.type === 'date' ? 'date' : p.type === 'datetime' ? 'datetime-local' : p.type === 'time' ? 'time' : p.type === 'amount' ? 'number' : 'text'"
                class="w-full text-xs rounded border border-slate-200 px-2 py-1 outline-none focus:ring-2 focus:ring-airr-300" />
            </div>

            <p v-if="previewModalError" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2">{{ previewModalError }}</p>
            <div class="flex justify-end gap-2 pt-2">
              <button @click="exitPreviewModal" class="text-sm text-slate-500 px-3 py-2">Cancel</button>
              <button @click="runPreviewModal" :disabled="previewModalBusy"
                class="text-sm font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-4 py-2 disabled:opacity-50">
                {{ previewModalBusy ? 'Running…' : 'Run' }}
              </button>
            </div>
          </div>
        </div>

        <!-- Step 2: result, after Run -->
        <template v-else>
          <div class="flex-1 flex flex-col overflow-hidden min-h-0">
            <div class="shrink-0 px-5 py-2 border-b border-slate-100">
              <button @click="previewModalStarted = false" class="text-xs font-medium text-slate-500 hover:text-slate-700">← Back to parameters</button>
            </div>
            <!-- Result -->
            <div class="flex-1 overflow-auto p-5 bg-slate-100">
              <p v-if="previewModalError" class="text-sm text-airr-700 bg-airr-50 rounded-lg px-3 py-2 mb-3">{{ previewModalError }}</p>
              <div v-if="previewModalBusy" class="text-slate-400 text-sm">Running…</div>
              <div v-else-if="previewModalHtml" class="bg-white shadow-sm mx-auto" style="max-width: 100%;">
                <div v-html="previewModalHtml"></div>
              </div>
              <div v-else class="text-slate-400 text-sm">No output yet.</div>
            </div>
          </div>

          <!-- Footer actions -->
          <div class="shrink-0 flex items-center justify-between px-5 py-3 border-t border-slate-100">
            <span class="text-xs text-slate-400" v-if="previewModalRowCount !== null">{{ previewModalRowCount }} rows</span>
            <span v-else></span>
            <div class="flex items-center gap-2">
              <button @click="printPreviewModal" :disabled="!previewModalHtml" title="Opens the print dialog — choose 'Save as PDF' there for a PDF file" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5 disabled:opacity-40">Print / PDF</button>
              <button @click="exportPreviewModal('csv')" :disabled="previewModalExporting !== null" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5 disabled:opacity-40">{{ previewModalExporting === 'csv' ? 'Exporting…' : 'Export CSV' }}</button>
              <button @click="exportPreviewModal('excel')" :disabled="previewModalExporting !== null" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5 disabled:opacity-40">{{ previewModalExporting === 'excel' ? 'Exporting…' : 'Export Excel' }}</button>
              <button v-if="canEdit" @click="save" :disabled="busy || selected?.locked" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5 disabled:opacity-50">{{ busy ? 'Saving…' : 'Save' }}</button>
              <button @click="exitPreviewModal" class="text-xs font-medium text-white bg-slate-700 hover:bg-slate-800 rounded-lg px-3 py-1.5">Exit</button>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- Card-list Log modal -->
    <div v-if="cardLogTarget" class="fixed inset-0 bg-slate-900/30 flex items-center justify-center z-50 px-4 py-8 overflow-y-auto" @click.self="cardLogTarget = null">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl p-6 space-y-4 my-auto">
        <div class="flex items-center justify-between">
          <h3 class="font-bold text-lg">Log · {{ cardLogTarget.name }}</h3>
          <button @click="cardLogTarget = null" class="text-slate-400 hover:text-slate-600 text-xl leading-none">×</button>
        </div>
        <div class="flex gap-2 border-b border-slate-100 pb-2">
          <button @click="fetchCardLog(cardLogTarget, 'history')" class="text-xs font-medium px-3 py-1.5 rounded-lg" :class="cardLogTab === 'history' ? 'bg-airr-500 text-white' : 'text-slate-500 hover:bg-slate-50'">Change History</button>
          <button @click="fetchCardLog(cardLogTarget, 'access')" class="text-xs font-medium px-3 py-1.5 rounded-lg" :class="cardLogTab === 'access' ? 'bg-airr-500 text-white' : 'text-slate-500 hover:bg-slate-50'">Access Log</button>
        </div>
        <div v-if="cardLogBusy" class="text-slate-400 text-sm">Loading…</div>
        <div v-else-if="!cardLogEntries.length" class="text-slate-400 text-sm">No entries yet.</div>
        <div v-else class="space-y-2 max-h-96 overflow-y-auto pr-1">
          <div v-for="e in cardLogEntries" :key="e.id" class="border border-slate-100 rounded-lg p-3 text-xs">
            <div class="flex justify-between items-center mb-1">
              <span class="font-medium text-slate-700">{{ (e as HistoryEntry).action ?? (e as LogEntry).event }}</span>
              <span class="text-slate-400">{{ (e as HistoryEntry).changed_by_name ?? (e as LogEntry).user_name }} · {{ new Date(e.created_at).toLocaleString() }}</span>
            </div>
            <pre v-if="(e as HistoryEntry).snapshot" class="text-[10px] text-slate-500 whitespace-pre-wrap break-all">{{ JSON.stringify((e as HistoryEntry).snapshot, null, 2) }}</pre>
            <pre v-else-if="(e as LogEntry).properties && Object.keys((e as LogEntry).properties).length" class="text-[10px] text-slate-500 whitespace-pre-wrap break-all">{{ JSON.stringify((e as LogEntry).properties, null, 2) }}</pre>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
