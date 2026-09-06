<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowDownTrayIcon, PrinterIcon } from '@heroicons/vue/24/outline'

import AppBackButton from '../../components/AppBackButton.vue'
import GroupPrintSheet from '../../groups/components/GroupPrintSheet.vue'
import GroupService from '../../groups/services/GroupService'
import { savePdfResponse } from '../../shared/utils/downloadBlob'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import { extractBlobErrorMessage } from '../../shared/utils/extractBlobErrorMessage'
import {
  applyPrintPageSize,
  clearPrintPageSize,
  printOrientationFromSheets,
} from '../../groups/utils/groupPrint'

const route = useRoute()
const competitionId = computed(() => route.params.id)

const payload = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')
const isDownloadingPdf = ref(false)
const pdfErrorMessage = ref('')

const competitionDetailHref = computed(() => `/competitions/${competitionId.value}`)

const sheets = computed(() => payload.value?.sheets ?? [])
const orientation = computed(() => printOrientationFromSheets(sheets.value))
const pageSize = computed(() => (orientation.value === 'landscape' ? 'A4 landscape' : 'A4 portrait'))
const groupsCountLabel = computed(() => {
  const count = Number(payload.value?.groups_count) || sheets.value.length

  return `${count} grupo${count === 1 ? '' : 's'}`
})

const loadSheets = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    payload.value = await GroupService.printCompetitionSheets(competitionId.value)
  } catch (error) {
    payload.value = null
    errorMessage.value = extractApiErrorMessage(
      error,
      'No se pudieron cargar las planillas de los grupos.',
    )
  } finally {
    isLoading.value = false
  }
}

const handlePrint = () => {
  window.print()
}

const handleDownloadPdf = async () => {
  if (isDownloadingPdf.value) {
    return
  }

  isDownloadingPdf.value = true
  pdfErrorMessage.value = ''

  try {
    const response = await GroupService.downloadCompetitionGroupsPdf(competitionId.value)
    savePdfResponse(response, 'grupos.pdf')
  } catch (error) {
    pdfErrorMessage.value = await extractBlobErrorMessage(error)
  } finally {
    isDownloadingPdf.value = false
  }
}

watch(pageSize, (size) => {
  applyPrintPageSize(size)
}, { immediate: true })

onMounted(loadSheets)

onUnmounted(() => {
  clearPrintPageSize()
})
</script>

<template>
  <section class="group-print-page">
    <div class="group-print-toolbar no-print">
      <AppBackButton :fallback-to="competitionDetailHref" />

      <div class="group-print-actions">
        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          :disabled="sheets.length === 0"
          @click="handlePrint"
        >
          <PrinterIcon class="h-4 w-4" />
          Imprimir todos
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
          :disabled="isDownloadingPdf"
          @click="handleDownloadPdf"
        >
          <ArrowDownTrayIcon class="h-4 w-4" />
          {{ isDownloadingPdf ? 'Descargando...' : 'Descargar PDF' }}
        </button>
      </div>
    </div>

    <p
      v-if="pdfErrorMessage"
      class="no-print mb-3 text-sm text-red-700 dark:text-red-400"
    >
      {{ pdfErrorMessage }}
    </p>

    <p v-if="isLoading" class="no-print mt-6 text-sm text-slate-600 dark:text-slate-300">Cargando planillas...</p>
    <p v-else-if="errorMessage" class="no-print mt-6 text-sm text-red-700 dark:text-red-400">{{ errorMessage }}</p>

    <template v-else-if="payload">
      <header class="bulk-print-header no-print">
        <h1 class="bulk-print-title">{{ payload.tournament?.name || '—' }}</h1>
        <p class="bulk-print-competition">{{ payload.competition?.name || '—' }}</p>
        <p class="bulk-print-meta">Planillas de grupos · {{ groupsCountLabel }}</p>
      </header>

      <div class="group-print-sheets">
        <GroupPrintSheet
          v-for="sheet in sheets"
          :key="sheet.group?.id"
          class="group-print-sheet"
          :sheet="sheet"
          :orientation="orientation"
        />
      </div>
    </template>
  </section>
</template>

<style scoped>
.group-print-page {
  color: #111;
}

.group-print-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.group-print-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 0.5rem;
}

.bulk-print-header {
  margin: 0 auto 1.25rem;
  max-width: 210mm;
}

.bulk-print-title {
  margin: 0 0 0.25rem;
  font-size: 1.25rem;
  font-weight: 700;
}

.bulk-print-competition {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
}

.bulk-print-meta {
  margin: 0.35rem 0 0;
  font-size: 0.85rem;
  color: #444;
}

.group-print-sheets {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

@media print {
  .no-print {
    display: none !important;
  }

  .group-print-page {
    background: #fff;
    color: #111;
  }

  .group-print-sheets {
    gap: 0;
  }

  .group-print-sheets > :deep(.group-print-sheet:not(:last-child)) {
    break-after: page;
    page-break-after: always;
  }
}
</style>
