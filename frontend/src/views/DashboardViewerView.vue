<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import AdminLayout from '../layouts/AdminLayout.vue'
import { apiRequest, ApiException } from '../api/client'
import { useAuthStore } from '../stores/auth'

type ReportOpt = { id: number; name: string }
type WidgetType = 'report' | 'text' | 'task' | 'announcement' | 'chart' | 'kpi'
type Widget = {
  id: string; type: WidgetType
  x: number; y: number; w: number; h: number
  config: Record<string, unknown>
}
type Dashboard = {
  id: number; name: string; description?: string | null
  definition: { widgets: Widget[] }
  status?: string; created_at?: string; updated_at?: string
}

const route = useRoute()
const auth = useAuthStore()
const canEdit = auth.can('dashboards.edit')

const dashboardId = Number(route.params.id)

const dashboard = ref<Dashboard | null>(null)
const reports = ref<ReportOpt[]>([])
const definition = ref<{ widgets: Widget[] }>({ widgets: [] })
const html = ref('')
const pageLoading = ref(true)
const busy = ref(false)
const saveBusy = ref(false)
const error = ref('')

const mode = ref<'view' | 'edit'>('view')
const newWidgetType = ref<WidgetType>('report')
const selectedWidgetId = ref<string | null>(null)
const leftPanelCollapsed = ref(false)
const rightPanelCollapsed = ref(false)

const selectedWidget = computed(() => definition.value.widgets.find(w => w.id === selectedWidgetId.value) ?? null)

const TYPE_LABELS: Record<WidgetType, string> = {
  report: 'Report', text: 'Text', task: 'Task List', announcement: 'Announcement', chart: 'Chart', kpi: 'KPI',
}

const statusOptions = ['', 'open', 'in_progress', 'done']
const aggFnOptions = ['sum', 'count', 'avg', 'min', 'max']
const chartTypeOptions = ['bar', 'line', 'pie']

function defaultConfig(type: WidgetType): Record<string, unknown> {
  switch (type) {
    case 'report': return { report_id: null, title: '' }
    case 'text': return { title: '', content: '' }
    case 'task': return { title: '', status: '' }
    case 'announcement': return { title: '', audience: '' }
    case 'chart': return { source_report_id: null, title: '', group_field: '', agg_field: '', agg_fn: 'count', chart_type: 'bar' }
    case 'kpi': return { source_report_id: null, title: '', agg_field: '', agg_fn: 'count', agg_label: '' }
  }
}

function defaultSize(type: WidgetType): { w: number; h: number } {
  if (type === 'chart') return { w: 6, h: 4 }
  if (type === 'kpi') return { w: 3, h: 2 }
  return { w: 4, h: 3 }
}

function addWidget() {
  const { w, h } = defaultSize(newWidgetType.value)
  const widget: Widget = {
    id: `widget-${Date.now()}`,
    type: newWidgetType.value,
    x: 0, y: 0, w, h,
    config: defaultConfig(newWidgetType.value),
  }
  definition.value.widgets.push(widget)
  selectedWidgetId.value = widget.id
}
function removeWidget(id: string) {
  definition.value.widgets = definition.value.widgets.filter(w => w.id !== id)
  if (selectedWidgetId.value === id) selectedWidgetId.value = null
}
function widgetLabel(w: Widget) {
  return (w.config.title as string) || TYPE_LABELS[w.type]
}
function widgetStyle(w: Widget) {
  return `grid-column: ${w.x + 1} / span ${w.w}; grid-row: ${w.y + 1} / span ${w.h};`
}

async function runDashboard() {
  if (!dashboard.value) return
  busy.value = true
  error.value = ''
  try {
    const res = await apiRequest<{ data: { html: string } }>(`/dashboards/${dashboard.value.id}/run`, { method: 'POST' })
    html.value = res.data.html
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to run dashboard'
  } finally {
    busy.value = false
  }
}

async function saveLayout() {
  if (!dashboard.value) return
  saveBusy.value = true
  error.value = ''
  try {
    dashboard.value = (await apiRequest<{ data: Dashboard }>(`/dashboards/${dashboard.value.id}`, {
      method: 'PUT', body: JSON.stringify({ name: dashboard.value.name, definition: definition.value }),
    })).data
    definition.value = dashboard.value.definition ?? { widgets: [] }
    if (mode.value === 'view') await runDashboard()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Save failed'
  } finally {
    saveBusy.value = false
  }
}

function toggleMode() {
  mode.value = mode.value === 'view' ? 'edit' : 'view'
  if (mode.value === 'view') runDashboard()
}

const canToggleEdit = computed(() => canEdit)

onMounted(async () => {
  try {
    dashboard.value = (await apiRequest<{ data: Dashboard }>(`/dashboards/${dashboardId}`)).data
    definition.value = dashboard.value.definition ?? { widgets: [] }
    reports.value = (await apiRequest<{ data: ReportOpt[] }>('/reports')).data
    pageLoading.value = false
    await runDashboard()
  } catch (e) {
    error.value = e instanceof ApiException ? e.error.message : 'Failed to load dashboard'
    pageLoading.value = false
  }
})
</script>

<template>
  <AdminLayout>
    <div class="-m-6 flex flex-col" style="height: calc(100vh - 4rem)">
      <div class="shrink-0 flex items-center justify-between flex-wrap gap-2 px-4 py-2 border-b border-slate-100 bg-white">
        <div>
          <h1 class="text-lg font-bold text-slate-800">{{ dashboard?.name ?? 'Dashboard' }}</h1>
          <p v-if="dashboard?.description" class="text-xs text-slate-400">{{ dashboard.description }}</p>
        </div>
        <div class="flex items-center gap-2">
          <button v-if="mode === 'view'" @click="runDashboard" :disabled="busy" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5 disabled:opacity-50">{{ busy ? 'Running…' : 'Refresh' }}</button>
          <button v-if="canToggleEdit && mode === 'view'" @click="toggleMode" class="text-xs font-medium rounded-lg px-3 py-1.5 border text-airr-600 border-airr-200 hover:bg-airr-50">Edit</button>
        </div>
      </div>

      <p v-if="error" class="shrink-0 text-sm text-airr-700 bg-airr-50 px-4 py-2">{{ error }}</p>
      <div v-if="pageLoading" class="text-slate-400 text-sm p-4">Loading…</div>

      <!-- View mode — server-rendered widget grid -->
      <template v-else-if="mode === 'view'">
        <div class="flex-1 overflow-auto p-5">
          <div v-if="busy" class="text-slate-400 text-sm">Loading…</div>
          <div v-else-if="html" class="bg-white shadow-sm rounded-xl p-5 overflow-x-auto">
            <div v-html="html"></div>
          </div>
          <div v-else class="text-slate-400 text-sm">No content yet — switch to Edit to add widgets.</div>
        </div>
      </template>

      <!-- Edit mode — 3-panel studio layout -->
      <div v-else class="flex-1 flex overflow-hidden min-h-0">

        <!-- LEFT PANEL — Widgets -->
        <aside :style="{ width: leftPanelCollapsed ? '36px' : '288px' }" class="shrink-0 flex flex-col border-r border-slate-100 overflow-y-auto bg-white">
          <button @click="leftPanelCollapsed = !leftPanelCollapsed" class="shrink-0 text-slate-400 hover:text-slate-600 text-xs px-2 py-1.5 text-left border-b border-slate-50">
            {{ leftPanelCollapsed ? '»' : '« Collapse' }}
          </button>
          <template v-if="!leftPanelCollapsed">
            <div class="border-b border-slate-50">
              <div class="w-full flex items-center justify-between px-3 py-2.5">
                <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">⠿ Widgets</span>
              </div>
              <div class="px-3 pb-3 flex items-center gap-1.5">
                <select v-model="newWidgetType" class="flex-1 text-xs rounded-lg border border-slate-200 px-2 py-1.5 outline-none focus:ring-2 focus:ring-airr-300">
                  <option v-for="t in (['report','text','task','announcement','chart','kpi'] as WidgetType[])" :key="t" :value="t">{{ TYPE_LABELS[t] }}</option>
                </select>
                <button @click="addWidget" class="text-xs font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5 shrink-0">+ Add</button>
              </div>
            </div>
            <div class="px-1 py-1">
              <div v-if="!definition.widgets.length" class="text-xs text-slate-400 px-2 py-2">No widgets yet.</div>
              <button v-for="w in definition.widgets" :key="w.id" @click="selectedWidgetId = w.id"
                class="w-full flex items-center justify-between gap-2 px-2.5 py-2 rounded-lg text-left"
                :class="selectedWidgetId === w.id ? 'bg-airr-50 border-l-2 border-airr-500' : 'hover:bg-slate-50 border-l-2 border-transparent'">
                <div class="min-w-0 flex items-center gap-1.5">
                  <span class="text-[10px] font-semibold text-airr-600 bg-airr-50 rounded-full px-2 py-0.5 uppercase tracking-wide shrink-0">{{ TYPE_LABELS[w.type] }}</span>
                  <span class="text-xs text-slate-600 truncate">{{ widgetLabel(w) }}</span>
                </div>
                <span @click.stop="removeWidget(w.id)" class="text-slate-400 hover:text-rose-600 px-1 rounded hover:bg-rose-50 shrink-0" title="Remove">×</span>
              </button>
            </div>
          </template>
        </aside>

        <!-- CENTER PANEL — grid canvas -->
        <main class="flex-1 flex flex-col min-w-0 overflow-hidden bg-slate-50">
          <div class="shrink-0 flex items-center justify-between px-4 py-2 border-b border-slate-100 bg-white">
            <span class="text-xs font-medium text-slate-500">Layout · {{ definition.widgets.length }} widgets</span>
            <div class="flex items-center gap-2">
              <button @click="saveLayout" :disabled="saveBusy" class="text-xs font-medium text-white bg-airr-500 hover:bg-airr-600 rounded-lg px-3 py-1.5 disabled:opacity-50">{{ saveBusy ? 'Saving…' : 'Save Layout' }}</button>
              <button @click="toggleMode" class="text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 rounded-lg px-3 py-1.5">Done Editing</button>
            </div>
          </div>
          <div class="flex-1 overflow-auto p-5">
            <div v-if="!definition.widgets.length" class="flex flex-col items-center justify-center h-full text-slate-300 gap-2">
              <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
              <span class="text-sm">No widgets yet — add one from the left panel.</span>
            </div>
            <div v-else style="display:grid;grid-template-columns:repeat(12,1fr);gap:1rem">
              <div v-for="w in definition.widgets" :key="w.id" :style="widgetStyle(w)" @click="selectedWidgetId = w.id"
                class="bg-white shadow-sm rounded-xl border p-3 space-y-1 cursor-pointer transition"
                :class="selectedWidgetId === w.id ? 'ring-2 ring-airr-400 border-airr-200' : 'border-slate-100 hover:border-slate-200'">
                <span class="text-[10px] font-semibold text-airr-600 bg-airr-50 rounded-full px-2 py-0.5 uppercase tracking-wide">{{ TYPE_LABELS[w.type] }}</span>
                <div class="text-sm text-slate-700 truncate">{{ widgetLabel(w) }}</div>
                <div class="text-[10px] text-slate-400">{{ w.w }}×{{ w.h }} @ ({{ w.x }},{{ w.y }})</div>
              </div>
            </div>
          </div>
        </main>

        <!-- RIGHT PANEL — Property -->
        <aside v-if="selectedWidgetId" :style="{ width: rightPanelCollapsed ? '36px' : '288px' }" class="shrink-0 flex flex-col border-l border-slate-100 overflow-y-auto bg-white">
          <button @click="rightPanelCollapsed = !rightPanelCollapsed" class="shrink-0 text-slate-400 hover:text-slate-600 text-xs px-2 py-1.5 text-left border-b border-slate-50">
            {{ rightPanelCollapsed ? '«' : 'Collapse »' }}
          </button>
          <template v-if="!rightPanelCollapsed && selectedWidget">
            <div>
              <div class="w-full flex items-center justify-between px-3 py-2.5">
                <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">⠿ Property</span>
              </div>
              <div class="px-3 pb-3 space-y-2">
                <div class="grid grid-cols-4 gap-1.5">
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">x</label>
                    <input v-model.number="selectedWidget.x" type="number" min="0" max="11" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">y</label>
                    <input v-model.number="selectedWidget.y" type="number" min="0" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">w</label>
                    <input v-model.number="selectedWidget.w" type="number" min="1" max="12" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">h</label>
                    <input v-model.number="selectedWidget.h" type="number" min="1" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                </div>

                <!-- report -->
                <template v-if="selectedWidget.type === 'report'">
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Title</label>
                    <input v-model="selectedWidget.config.title" placeholder="Title (optional)" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Report</label>
                    <select v-model="selectedWidget.config.report_id" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300">
                      <option :value="null">— select report —</option>
                      <option v-for="r in reports" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>
                  </div>
                </template>

                <!-- text -->
                <template v-else-if="selectedWidget.type === 'text'">
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Title</label>
                    <input v-model="selectedWidget.config.title" placeholder="Title (optional)" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Content</label>
                    <textarea v-model="selectedWidget.config.content" rows="4" placeholder="Content" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300 resize-none"></textarea>
                  </div>
                </template>

                <!-- task -->
                <template v-else-if="selectedWidget.type === 'task'">
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Title</label>
                    <input v-model="selectedWidget.config.title" placeholder="Title (optional)" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Status</label>
                    <select v-model="selectedWidget.config.status" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300">
                      <option v-for="s in statusOptions" :key="s" :value="s">{{ s || 'Any status' }}</option>
                    </select>
                  </div>
                </template>

                <!-- announcement -->
                <template v-else-if="selectedWidget.type === 'announcement'">
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Title</label>
                    <input v-model="selectedWidget.config.title" placeholder="Title (optional)" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Audience</label>
                    <input v-model="selectedWidget.config.audience" placeholder="Audience (optional)" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                </template>

                <!-- chart -->
                <template v-else-if="selectedWidget.type === 'chart'">
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Data source</label>
                    <select v-model="selectedWidget.config.source_report_id" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300">
                      <option :value="null">— select data source —</option>
                      <option v-for="r in reports" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Title</label>
                    <input v-model="selectedWidget.config.title" placeholder="Title (optional)" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Group by field</label>
                    <input v-model="selectedWidget.config.group_field" placeholder="Group by field" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Aggregate field</label>
                    <input v-model="selectedWidget.config.agg_field" placeholder="e.g. amount or *" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Aggregate function</label>
                    <select v-model="selectedWidget.config.agg_fn" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300">
                      <option v-for="f in aggFnOptions" :key="f" :value="f">{{ f }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Chart type</label>
                    <select v-model="selectedWidget.config.chart_type" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300">
                      <option v-for="c in chartTypeOptions" :key="c" :value="c">{{ c }}</option>
                    </select>
                  </div>
                </template>

                <!-- kpi -->
                <template v-else-if="selectedWidget.type === 'kpi'">
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Data source</label>
                    <select v-model="selectedWidget.config.source_report_id" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300">
                      <option :value="null">— select data source —</option>
                      <option v-for="r in reports" :key="r.id" :value="r.id">{{ r.name }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Title</label>
                    <input v-model="selectedWidget.config.title" placeholder="Title (optional)" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Aggregate field</label>
                    <input v-model="selectedWidget.config.agg_field" placeholder="e.g. amount or *" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Aggregate function</label>
                    <select v-model="selectedWidget.config.agg_fn" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300">
                      <option v-for="f in aggFnOptions" :key="f" :value="f">{{ f }}</option>
                    </select>
                  </div>
                  <div>
                    <label class="block text-[11px] text-slate-500 mb-0.5">Label</label>
                    <input v-model="selectedWidget.config.agg_label" placeholder="Label (optional)" class="w-full text-xs rounded-lg border border-slate-200 px-2.5 py-1.5 outline-none focus:ring-2 focus:ring-airr-300" />
                  </div>
                </template>

                <button @click="removeWidget(selectedWidget.id); selectedWidgetId = null" class="w-full text-xs font-medium text-rose-600 border border-rose-200 hover:bg-rose-50 rounded-lg px-3 py-1.5 mt-2">Remove widget</button>
              </div>
            </div>
          </template>
        </aside>
      </div>
    </div>
  </AdminLayout>
</template>
