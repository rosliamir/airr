<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'

const props = defineProps<{
  modelValue: string
  placeholder?: string
  constants?: { placeholder: string; label: string; scope: string; type?: string; image_url?: string | null }[]
}>()
const emit = defineEmits<{ 'update:modelValue': [string] }>()

const editorRef = ref<HTMLDivElement | null>(null)
const fileInputRef = ref<HTMLInputElement | null>(null)
const showConstantPicker = ref(false)
const showImagePicker = ref(false)
const showTablePicker = ref(false)
const tableRows = ref(2)
const tableCols = ref(2)
const imageConstants = computed(() => (props.constants ?? []).filter((c) => c.type === 'image'))
const constantPickerIcon = '{' + '{…}' + '}'

// Sync incoming value only when it differs (avoid cursor reset on every keystroke)
let lastEmitted = ''
onMounted(() => {
  if (editorRef.value && props.modelValue !== editorRef.value.innerHTML) {
    editorRef.value.innerHTML = props.modelValue ?? ''
  }
})
watch(() => props.modelValue, (val) => {
  if (editorRef.value && val !== lastEmitted && val !== editorRef.value.innerHTML) {
    editorRef.value.innerHTML = val ?? ''
  }
})

function onInput() {
  if (!editorRef.value) return
  lastEmitted = editorRef.value.innerHTML
  emit('update:modelValue', lastEmitted)
}

function exec(cmd: string, value?: string) {
  editorRef.value?.focus()
  document.execCommand(cmd, false, value)
  onInput()
}

function setBlock(tag: string) {
  exec('formatBlock', tag)
}

function insertConstant(placeholder: string) {
  editorRef.value?.focus()
  const sel = window.getSelection()
  if (sel && sel.rangeCount) {
    const range = sel.getRangeAt(0)
    range.deleteContents()
    const span = document.createElement('span')
    span.className = 'inline-block bg-amber-100 text-amber-800 rounded px-1 py-0 text-[11px] font-mono mx-0.5 select-all'
    span.contentEditable = 'false'
    span.dataset.placeholder = placeholder
    span.textContent = placeholder
    range.insertNode(span)
    range.setStartAfter(span)
    range.collapse(true)
    sel.removeAllRanges()
    sel.addRange(range)
  }
  showConstantPicker.value = false
  onInput()
}

// Insert an <img> bound to an image-type constant — serialized back to its
// {{SCOPE:KEY}} placeholder on save (see getRaw), so the reference stays live.
function insertConstantImage(c: { placeholder: string; image_url?: string | null }) {
  editorRef.value?.focus()
  const sel = window.getSelection()
  if (sel && sel.rangeCount && c.image_url) {
    const range = sel.getRangeAt(0)
    range.deleteContents()
    const img = document.createElement('img')
    img.src = c.image_url
    img.dataset.constantPlaceholder = c.placeholder
    img.style.maxHeight = '48px'
    range.insertNode(img)
    range.setStartAfter(img)
    range.collapse(true)
    sel.removeAllRanges()
    sel.addRange(range)
  }
  showImagePicker.value = false
  onInput()
}

// One-off image (e.g. a logo pasted once, not registered as a Constant) —
// embedded as base64 directly in the section HTML, no upload/orphan cleanup needed.
function triggerUpload() {
  fileInputRef.value?.click()
}
function onUploadChange(ev: Event) {
  const file = (ev.target as HTMLInputElement).files?.[0]
  if (!file) return
  const reader = new FileReader()
  reader.onload = () => {
    editorRef.value?.focus()
    const sel = window.getSelection()
    if (sel && sel.rangeCount) {
      const range = sel.getRangeAt(0)
      range.deleteContents()
      const img = document.createElement('img')
      img.src = String(reader.result)
      img.style.maxHeight = '96px'
      range.insertNode(img)
      range.setStartAfter(img)
      range.collapse(true)
      sel.removeAllRanges()
      sel.addRange(range)
    }
    onInput()
  }
  reader.readAsDataURL(file)
  ;(ev.target as HTMLInputElement).value = ''
  showImagePicker.value = false
}

// Insert a plain HTML <table> skeleton, editable cell-by-cell in place.
function insertTable() {
  editorRef.value?.focus()
  const sel = window.getSelection()
  if (sel && sel.rangeCount) {
    const rows = Math.max(1, Math.min(20, tableRows.value))
    const cols = Math.max(1, Math.min(12, tableCols.value))
    const table = document.createElement('table')
    table.style.borderCollapse = 'collapse'
    table.style.width = '100%'
    for (let r = 0; r < rows; r++) {
      const tr = document.createElement('tr')
      for (let c = 0; c < cols; c++) {
        const td = document.createElement('td')
        td.style.border = '1px solid #cbd5e1'
        td.style.padding = '6px 8px'
        td.innerHTML = '&nbsp;'
        tr.appendChild(td)
      }
      table.appendChild(tr)
    }
    const range = sel.getRangeAt(0)
    range.deleteContents()
    range.insertNode(table)
  }
  showTablePicker.value = false
  onInput()
}

// Insert a bordered box (rectangular or rounded) as a container users can type into.
function insertBox(rounded: boolean) {
  editorRef.value?.focus()
  const sel = window.getSelection()
  if (sel && sel.rangeCount) {
    const box = document.createElement('div')
    box.style.border = '1px solid #cbd5e1'
    box.style.padding = '12px'
    box.style.borderRadius = rounded ? '12px' : '0px'
    box.textContent = 'Box content…'
    const range = sel.getRangeAt(0)
    range.deleteContents()
    range.insertNode(box)
  }
  showTablePicker.value = false
  onInput()
}

// Before emitting, replace rendered placeholder spans/images back to raw text.
function getRaw(): string {
  if (!editorRef.value) return ''
  const clone = editorRef.value.cloneNode(true) as HTMLDivElement
  clone.querySelectorAll('[data-placeholder]').forEach((el) => {
    el.replaceWith(document.createTextNode((el as HTMLElement).dataset.placeholder ?? ''))
  })
  clone.querySelectorAll('img[data-constant-placeholder]').forEach((el) => {
    el.replaceWith(document.createTextNode((el as HTMLElement).dataset.constantPlaceholder ?? ''))
  })
  return clone.innerHTML
}

// When parent needs raw value (for save), expose getRaw via defineExpose.
defineExpose({ getRaw })

const SCOPE_COLOR: Record<string, string> = {
  system:  'bg-blue-100 text-blue-700',
  global:  'bg-violet-100 text-violet-700',
  project: 'bg-emerald-100 text-emerald-700',
}
</script>

<template>
  <div class="border border-slate-200 rounded-xl overflow-hidden">
    <!-- Toolbar -->
    <div class="flex flex-wrap items-center gap-0.5 bg-slate-50 border-b border-slate-200 px-2 py-1.5">
      <!-- Text style -->
      <select @change="setBlock(($event.target as HTMLSelectElement).value)"
        class="text-xs rounded border border-slate-200 bg-white px-1.5 py-0.5 outline-none focus:ring-1 focus:ring-airr-300 mr-1">
        <option value="p">Normal</option>
        <option value="h1">Heading 1</option>
        <option value="h2">Heading 2</option>
        <option value="h3">Heading 3</option>
      </select>

      <div class="w-px h-5 bg-slate-200 mx-1"></div>

      <!-- Format buttons -->
      <button @click="exec('bold')" title="Bold" class="toolbar-btn font-bold">B</button>
      <button @click="exec('italic')" title="Italic" class="toolbar-btn italic">I</button>
      <button @click="exec('underline')" title="Underline" class="toolbar-btn underline">U</button>
      <button @click="exec('strikeThrough')" title="Strikethrough" class="toolbar-btn line-through">S</button>

      <div class="w-px h-5 bg-slate-200 mx-1"></div>

      <!-- Lists -->
      <button @click="exec('insertUnorderedList')" title="Bullet list" class="toolbar-btn">• —</button>
      <button @click="exec('insertOrderedList')" title="Numbered list" class="toolbar-btn">1 —</button>

      <div class="w-px h-5 bg-slate-200 mx-1"></div>

      <!-- Alignment -->
      <button @click="exec('justifyLeft')" title="Align left" class="toolbar-btn">◧</button>
      <button @click="exec('justifyCenter')" title="Align center" class="toolbar-btn">◫</button>
      <button @click="exec('justifyRight')" title="Align right" class="toolbar-btn">◨</button>

      <div class="w-px h-5 bg-slate-200 mx-1"></div>

      <!-- Color -->
      <label title="Text colour" class="toolbar-btn cursor-pointer" style="line-height:1">
        A
        <input type="color" class="w-0 h-0 opacity-0 absolute" @change="exec('foreColor', ($event.target as HTMLInputElement).value)" />
      </label>

      <div class="w-px h-5 bg-slate-200 mx-1"></div>

      <!-- Constants picker -->
      <div class="relative">
        <button @click="showConstantPicker = !showConstantPicker"
          class="toolbar-btn text-amber-600 font-mono text-[10px] px-2">{{ constantPickerIcon }}</button>
        <div v-if="showConstantPicker && constants?.length"
          class="absolute left-0 top-full mt-1 w-64 bg-white border border-slate-200 rounded-xl shadow-lg z-20 p-2 space-y-1 max-h-56 overflow-y-auto">
          <div v-for="c in constants" :key="c.placeholder">
            <button @click="insertConstant(c.placeholder)"
              class="w-full text-left flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50 text-xs">
              <span class="text-[10px] rounded px-1.5 py-0.5 shrink-0 font-medium" :class="SCOPE_COLOR[c.scope] ?? 'bg-slate-100 text-slate-500'">{{ c.scope }}</span>
              <span class="font-mono text-amber-700 truncate">{{ c.placeholder }}</span>
              <span class="text-slate-400 truncate">{{ c.label }}</span>
            </button>
          </div>
        </div>
        <div v-else-if="showConstantPicker" class="absolute left-0 top-full mt-1 w-48 bg-white border border-slate-200 rounded-xl shadow-lg z-20 p-3 text-xs text-slate-400">
          No constants available.
        </div>
      </div>

      <div class="w-px h-5 bg-slate-200 mx-1"></div>

      <!-- Insert image -->
      <div class="relative">
        <button @click="showImagePicker = !showImagePicker" title="Insert image" class="toolbar-btn">🖼</button>
        <div v-if="showImagePicker" class="absolute left-0 top-full mt-1 w-64 bg-white border border-slate-200 rounded-xl shadow-lg z-20 p-2 space-y-1">
          <button @click="triggerUpload" class="w-full text-left rounded-lg px-2 py-1.5 hover:bg-slate-50 text-xs text-slate-600">Upload one-off image…</button>
          <template v-if="imageConstants.length">
            <div class="text-[10px] uppercase tracking-wide text-slate-400 px-2 pt-1">Image constants</div>
            <button v-for="c in imageConstants" :key="c.placeholder" @click="insertConstantImage(c)"
              class="w-full text-left flex items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50 text-xs">
              <img v-if="c.image_url" :src="c.image_url" class="w-5 h-5 rounded object-cover" />
              <span class="font-mono text-amber-700 truncate">{{ c.placeholder }}</span>
            </button>
          </template>
        </div>
        <input ref="fileInputRef" type="file" accept="image/*" class="hidden" @change="onUploadChange" />
      </div>

      <div class="w-px h-5 bg-slate-200 mx-1"></div>

      <!-- Insert table / box -->
      <div class="relative">
        <button @click="showTablePicker = !showTablePicker" title="Insert table or box" class="toolbar-btn">▦</button>
        <div v-if="showTablePicker" class="absolute left-0 top-full mt-1 w-56 bg-white border border-slate-200 rounded-xl shadow-lg z-20 p-3 space-y-3 text-xs">
          <div>
            <div class="text-[10px] uppercase tracking-wide text-slate-400 mb-1.5">Table</div>
            <div class="flex items-center gap-2 mb-2">
              <input v-model.number="tableRows" type="number" min="1" max="20" class="w-14 rounded border border-slate-200 px-1.5 py-1 text-xs" />
              <span class="text-slate-400">rows ×</span>
              <input v-model.number="tableCols" type="number" min="1" max="12" class="w-14 rounded border border-slate-200 px-1.5 py-1 text-xs" />
              <span class="text-slate-400">cols</span>
            </div>
            <button @click="insertTable" class="w-full rounded-lg bg-airr-500 hover:bg-airr-600 text-white text-xs font-medium py-1.5">Insert table</button>
          </div>
          <div class="border-t border-slate-100 pt-2">
            <div class="text-[10px] uppercase tracking-wide text-slate-400 mb-1.5">Box</div>
            <div class="flex gap-2">
              <button @click="insertBox(false)" class="flex-1 rounded border border-slate-200 hover:bg-slate-50 py-1.5">Rectangular</button>
              <button @click="insertBox(true)" class="flex-1 rounded-xl border border-slate-200 hover:bg-slate-50 py-1.5">Rounded</button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Editable area -->
    <div
      ref="editorRef"
      contenteditable="true"
      :data-placeholder="placeholder ?? 'Type here…'"
      class="rich-editor min-h-[120px] px-4 py-3 text-sm text-slate-800 outline-none focus:ring-0"
      @input="onInput"
      @blur="onInput"
    ></div>
  </div>
</template>

<style scoped>
@reference "../style.css";
.toolbar-btn {
  @apply text-xs text-slate-600 hover:bg-slate-200 rounded px-1.5 py-0.5 leading-none transition select-none;
}
.rich-editor:empty::before {
  content: attr(data-placeholder);
  color: #94a3b8;
  pointer-events: none;
}
/* Style for injected constant spans (not scoped — lives in contenteditable) */
</style>
