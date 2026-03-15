<script setup lang="ts">
import { ref, useSlots } from 'vue'

const activeTab = ref<'output' | 'result'>('output')
const slots = useSlots()
</script>

<template>
  <div class="output-tabs-container">
    <div class="output-tabs">
      <button
        :class="{ active: activeTab === 'output' }"
        @click="activeTab = 'output'"
      >
        Output
      </button>
      <button
        :class="{ active: activeTab === 'result' }"
        @click="activeTab = 'result'"
      >
        Result
      </button>
    </div>

    <div v-show="activeTab === 'output'" class="tab-content output">
      <slot name="output"></slot>
    </div>
    <div v-show="activeTab === 'result'" class="tab-content result">
      <slot name="result"></slot>
    </div>
  </div>
</template>

<style scoped>
.output-tabs-container {
  margin: 1rem 0;
}

.output-tabs {
  display: flex;
  gap: 0.5rem;
  margin-bottom: 0;
}

.output-tabs button {
  padding: 0.5rem 1rem;
  border: 1px solid var(--vp-c-divider);
  border-bottom: none;
  border-radius: 6px 6px 0 0;
  background: var(--vp-c-bg-soft);
  color: var(--vp-c-text-2);
  cursor: pointer;
  font-size: 14px;
  transition: all 0.2s;
}

.output-tabs button:hover {
  color: var(--vp-c-text-1);
}

.output-tabs button.active {
  background: var(--vp-c-bg);
  color: var(--vp-c-text-1);
  border-color: var(--vp-c-divider);
  position: relative;
}

.output-tabs button.active::after {
  content: '';
  position: absolute;
  bottom: -1px;
  left: 0;
  right: 0;
  height: 1px;
  background: var(--vp-c-bg);
}

.tab-content {
  border: 1px solid var(--vp-c-divider);
  border-radius: 0 8px 8px 8px;
  overflow: hidden;
}

.tab-content.output :deep(div[class*="language-"]) {
  margin: 0;
  border-radius: 0;
}

.tab-content.result {
  padding: 1rem;
  background: var(--vp-c-bg-soft);
}

.tab-content.result :deep(h1),
.tab-content.result :deep(h2),
.tab-content.result :deep(h3),
.tab-content.result :deep(h4),
.tab-content.result :deep(h5),
.tab-content.result :deep(h6) {
  margin: 0 0 0.5rem 0;
  border: none;
  padding: 0;
}

.tab-content.result :deep(h1) { font-size: 1.5rem; }
.tab-content.result :deep(h2) { font-size: 1.3rem; }
.tab-content.result :deep(h3) { font-size: 1.15rem; }

.tab-content.result :deep(p) {
  margin: 0.5rem 0;
}

.tab-content.result :deep(p:first-child) {
  margin-top: 0;
}

.tab-content.result :deep(p:last-child) {
  margin-bottom: 0;
}

.tab-content.result :deep(blockquote) {
  margin: 0.5rem 0;
  padding: 0.5rem 1rem;
  border-left: 3px solid var(--vp-c-brand-1);
  background: var(--vp-c-bg);
}

.tab-content.result :deep(ul),
.tab-content.result :deep(ol) {
  margin: 0.5rem 0;
  padding-left: 1.5rem;
}

.tab-content.result :deep(table) {
  border-collapse: collapse;
  margin: 0.5rem 0;
}

.tab-content.result :deep(th),
.tab-content.result :deep(td) {
  border: 1px solid var(--vp-c-divider);
  padding: 0.5rem;
}

.tab-content.result :deep(hr) {
  margin: 1rem 0;
  border: none;
  border-top: 1px solid var(--vp-c-divider);
}

.tab-content.result :deep(figure) {
  margin: 0.5rem 0;
}

.tab-content.result :deep(figcaption) {
  font-size: 0.9rem;
  color: var(--vp-c-text-2);
  margin-top: 0.25rem;
}

.tab-content.result :deep(dl) {
  margin: 0.5rem 0;
}

.tab-content.result :deep(dt) {
  font-weight: 600;
}

.tab-content.result :deep(dd) {
  margin-left: 1.5rem;
}

.tab-content.result :deep(.task-list) {
  list-style: none;
  padding-left: 0;
}

.tab-content.result :deep(.task-list li) {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.tab-content.result :deep(.footnote) {
  font-size: 0.9rem;
  border-top: 1px solid var(--vp-c-divider);
  padding-top: 0.5rem;
  margin-top: 1rem;
}

/* Example classes used in syntax demos */
.tab-content.result :deep(.highlight) {
  background: linear-gradient(to right, rgba(255, 255, 0, 0.3), rgba(255, 200, 0, 0.3));
  padding: 0.1em 0.3em;
  border-radius: 3px;
}
</style>
