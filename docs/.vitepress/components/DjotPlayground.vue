<script setup lang="ts">
import { ref, watch, onMounted, nextTick } from 'vue'
import hljs from 'highlight.js'

const SANDBOX_URL = 'https://sandbox.dereuromark.de/sandbox/djot'

const djotInput = ref(`# Welcome to Djot

This is a _live playground_ for *djot-php*.

Try editing this text to see the output change!

## Features

- Emphasis with _underscores_
- Strong with *asterisks*
- Links like [djot.net](https://djot.net)

## Code Example

\`\`\` php
$converter = new DjotConverter();
echo $converter->convert($djot);
\`\`\`

> Djot is a lightweight markup language
> with clean, unambiguous syntax.

| Feature | Djot | Markdown |
|---------|------|----------|
| Emphasis | \`_text_\` | \`*text*\` |
| Strong | \`*text*\` | \`**text**\` |
`)

const htmlOutput = ref('')
const activeTab = ref<'preview' | 'source'>('preview')
const profile = ref('')
const isLoading = ref(false)
const error = ref('')
const extensions = ref<string[]>([])
const previewRef = ref<HTMLElement | null>(null)

async function highlightCodeBlocks() {
  await nextTick()
  if (previewRef.value) {
    previewRef.value.querySelectorAll('pre code').forEach((block) => {
      hljs.highlightElement(block as HTMLElement)
    })
  }
}

const availableExtensions = [
  { id: 'autolink', label: 'Autolink' },
  { id: 'external_links', label: 'External Links' },
  { id: 'heading_permalinks', label: 'Heading Permalinks' },
  { id: 'mentions', label: 'Mentions' },
  { id: 'smart_quotes', label: 'Smart Quotes' },
  { id: 'semantic_span', label: 'Semantic Span' },
  { id: 'toc', label: 'Table of Contents' },
  { id: 'default_attributes', label: 'Default Attributes' },
]

const profiles = [
  { id: '', label: 'No filter' },
  { id: 'full', label: 'Full' },
  { id: 'article', label: 'Article' },
  { id: 'comment', label: 'Comment' },
  { id: 'minimal', label: 'Minimal' },
]

let debounceTimer: ReturnType<typeof setTimeout> | null = null
let abortController: AbortController | null = null

async function convert() {
  if (abortController) {
    abortController.abort()
  }
  abortController = new AbortController()

  isLoading.value = true
  error.value = ''

  try {
    const endpoint = extensions.value.length > 0
      ? `${SANDBOX_URL}/convert-with-extensions`
      : `${SANDBOX_URL}/convert`

    const body = new URLSearchParams()
    body.append('djot', djotInput.value)

    if (extensions.value.length > 0) {
      extensions.value.forEach(ext => body.append('extensions[]', ext))
    } else {
      if (profile.value) {
        body.append('profile', profile.value)
      }
    }

    const response = await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
      signal: abortController.signal,
    })

    if (!response.ok) {
      throw new Error(`HTTP error: ${response.status}`)
    }

    const data = await response.json()

    if (data.error) {
      error.value = data.error
      htmlOutput.value = ''
    } else {
      htmlOutput.value = data.html || ''
      error.value = ''
      highlightCodeBlocks()
    }
  } catch (err) {
    if (err instanceof Error && err.name === 'AbortError') {
      return
    }
    error.value = err instanceof Error ? err.message : 'Unknown error'
    htmlOutput.value = ''
  } finally {
    isLoading.value = false
  }
}

function debouncedConvert() {
  if (debounceTimer) {
    clearTimeout(debounceTimer)
  }
  debounceTimer = setTimeout(convert, 300)
}

watch(djotInput, debouncedConvert)
watch(profile, convert)
watch(extensions, convert, { deep: true })

onMounted(() => {
  convert()
})

function toggleExtension(extId: string) {
  const index = extensions.value.indexOf(extId)
  if (index === -1) {
    extensions.value.push(extId)
  } else {
    extensions.value.splice(index, 1)
  }
}
</script>

<template>
  <div class="djot-playground">
    <div class="options">
      <label>
        Profile:
        <select v-model="profile" :disabled="extensions.length > 0">
          <option v-for="p in profiles" :key="p.id" :value="p.id">{{ p.label }}</option>
        </select>
      </label>
      <div class="extension-toggles">
        <label v-for="ext in availableExtensions" :key="ext.id">
          <input
            type="checkbox"
            :checked="extensions.includes(ext.id)"
            @change="toggleExtension(ext.id)"
          />
          {{ ext.label }}
        </label>
      </div>
    </div>

    <div class="input-section">
      <h3>Djot Input</h3>
      <textarea
        v-model="djotInput"
        placeholder="Enter Djot markup here..."
        spellcheck="false"
      ></textarea>
    </div>

    <div class="output-section">
      <h3>Output</h3>
      <div class="output-tabs">
        <button
          :class="{ active: activeTab === 'preview' }"
          @click="activeTab = 'preview'"
        >
          Preview
        </button>
        <button
          :class="{ active: activeTab === 'source' }"
          @click="activeTab = 'source'"
        >
          HTML Source
        </button>
      </div>

      <div v-if="isLoading" class="output-content loading">
        Converting...
      </div>
      <div v-else-if="error" class="output-content error">
        {{ error }}
      </div>
      <div
        v-else-if="activeTab === 'preview'"
        ref="previewRef"
        class="output-content preview"
        v-html="htmlOutput"
      ></div>
      <div
        v-else
        class="output-content source"
      >{{ htmlOutput }}</div>
    </div>
  </div>
</template>
