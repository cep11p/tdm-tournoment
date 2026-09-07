<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowDownTrayIcon, PrinterIcon } from '@heroicons/vue/24/outline'

import AppBackButton from '../../components/AppBackButton.vue'
import { downloadFileUrl } from '../../shared/utils/downloadFileUrl'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import GroupPrintSheet from '../components/GroupPrintSheet.vue'
import GroupService from '../services/GroupService'
import {
  applyPrintPageSize,
  clearPrintPageSize,
  printOrientation,
} from '../utils/groupPrint'

const route = useRoute()
const groupId = computed(() => route.params.id)
const competitionId = computed(() => route.query.competitionId || '')
const groupNameQuery = computed(() => route.query.groupName || '')

const sheet = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')

const groupDetailHref = computed(() => {
  const params = new URLSearchParams()

  if (competitionId.value) {
    params.set('competitionId', String(competitionId.value))
  }

  const name = groupNameQuery.value || sheet.value?.group?.name

  if (name) {
    params.set('groupName', String(name))
  }

  const query = params.toString()

  return `/groups/${groupId.value}${query ? `?${query}` : ''}`
})

const orientation = computed(() => printOrientation(sheet.value?.best_of))
const pageSize = computed(() => (orientation.value === 'landscape' ? 'A4 landscape' : 'A4 portrait'))
const previewTitle = computed(() => {
  const name = sheet.value?.group?.name || groupNameQuery.value

  if (name) {
    return `Planilla del ${name}`
  }

  return 'Planilla del grupo'
})

const loadSheet = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    sheet.value = await GroupService.printSheet(groupId.value)
  } catch (error) {
    sheet.value = null
    errorMessage.value = extractApiErrorMessage(
      error,
      'No se pudo cargar la planilla del grupo.',
    )
  } finally {
    isLoading.value = false
  }
}

const handlePrint = () => {
  window.print()
}

const handleDownloadPdf = () => {
  if (!sheet.value) {
    return
  }

  downloadFileUrl(
    GroupService.printPdfDownloadUrl(groupId.value),
  )
}

watch(pageSize, (size) => {
  applyPrintPageSize(size)
}, { immediate: true })

onMounted(loadSheet)

onUnmounted(() => {
  clearPrintPageSize()
})
</script>

<template>
  <section class="group-print-page">
    <div class="group-print-toolbar no-print border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
      <div class="group-print-toolbar-start">
        <AppBackButton :fallback-to="groupDetailHref" />
        <div class="group-print-toolbar-copy">
          <h1 class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">{{ previewTitle }}</h1>
        </div>
      </div>

      <div class="group-print-actions">
        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          :disabled="!sheet"
          @click="handlePrint"
        >
          <PrinterIcon class="h-4 w-4" />
          Imprimir
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
          :disabled="!sheet"
          @click="handleDownloadPdf"
        >
          <ArrowDownTrayIcon class="h-4 w-4" />
          Descargar PDF
        </button>
      </div>
    </div>

    <p v-if="isLoading" class="no-print mt-6 text-sm text-slate-600 dark:text-slate-300">Cargando planilla...</p>
    <p v-else-if="errorMessage" class="no-print mt-6 text-sm text-red-700 dark:text-red-400">{{ errorMessage }}</p>

    <div v-else-if="sheet" class="print-preview-scroll">
      <div class="print-sheet-frame">
        <GroupPrintSheet
          :sheet="sheet"
          :orientation="orientation"
        />
      </div>
    </div>
  </section>
</template>

<style scoped>
.group-print-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
  padding: 0.75rem 1rem;
  border-radius: 0.375rem;
}

.group-print-toolbar-start {
  display: flex;
  min-width: 0;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}

.group-print-toolbar-copy {
  min-width: 0;
}

.group-print-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 0.5rem;
}

.print-preview-scroll {
  padding: 0.25rem 0 3rem;
}

.print-sheet-frame {
  width: fit-content;
  margin-inline: auto;
}

@media screen {
  .print-preview-scroll {
    overflow-x: auto;
  }

  .print-sheet-frame {
    box-shadow: 0 8px 24px rgb(15 23 42 / 0.14);
  }

  :global(.dark) .print-sheet-frame {
    box-shadow: 0 14px 40px rgb(0 0 0 / 0.55);
  }
}

@media print {
  .no-print {
    display: none !important;
  }

  .group-print-page {
    background: #fff;
    color: #111;
  }

  .print-preview-scroll {
    overflow: visible;
    padding: 0;
  }

  .print-sheet-frame {
    width: auto;
    margin: 0;
    box-shadow: none;
  }
}
</style>
