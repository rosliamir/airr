<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'

const props = defineProps<{
  modelValue: string
  placeholder?: string
  constants?: { placeholder: string; label: string; scope: string }[]
}>()
const emit = defineEmits<{ 'update:modelValue': [string] }>()

const editorRef = ref<HTMLDivElement | null>(null)
const showConstantPicker = ref(false)

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

// Before emitting, replace rendered placeholder spans back to raw text.
function getRaw(): string {
  if (!editorRef.value) return ''
  const clone = editorRef.value.cloneNode(true) as HTMLDivElement
  clone.querySelectorAll('[data-placeholder]').forEach((el) => {
    el.replaceWith(document.createTextNode((el as HTMLElement).dataset.placeholder ?? ''))
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
          class="toolbar-btn text-amber-600 font-mono text-[10px] px-2">{{…}}</button>
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
