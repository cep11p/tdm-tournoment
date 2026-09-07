<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowDownTrayIcon, PrinterIcon } from '@heroicons/vue/24/outline'

import AppBackButton from '../../components/AppBackButton.vue'
import GroupPrintSheet from '../../groups/components/GroupPrintSheet.vue'
import GroupService from '../../groups/services/GroupService'
import { downloadFileUrl } from '../../shared/utils/downloadFileUrl'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
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

const handleDownloadPdf = () => {
  if (sheets.value.length === 0) {
    return
  }

  downloadFileUrl(
    GroupService.competitionGroupsPdfDownloadUrl(competitionId.value),
  )
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
    <div class="group-print-toolbar no-print border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
      <div class="group-print-toolbar-start">
        <AppBackButton :fallback-to="competitionDetailHref" />
        <div class="group-print-toolbar-copy">
          <h1 class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">Planillas de grupos</h1>
          <p v-if="payload" class="truncate text-xs text-slate-500 dark:text-slate-400">{{ groupsCountLabel }}</p>
        </div>
      </div>

      <div class="group-print-actions">
        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          :disabled="sheets.length === 0"
          @click="handlePrint"
        >
          <PrinterIcon class="h-4 w-4" />
          Imprimir
        </button>
        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
          :disabled="sheets.length === 0"
          @click="handleDownloadPdf"
        >
          <ArrowDownTrayIcon class="h-4 w-4" />
          Descargar PDF
        </button>
      </div>
    </div>

    <p v-if="isLoading" class="no-print mt-6 text-sm text-slate-600 dark:text-slate-300">Cargando planillas...</p>
    <p v-else-if="errorMessage" class="no-print mt-6 text-sm text-red-700 dark:text-red-400">{{ errorMessage }}</p>

    <div v-else-if="payload" class="print-preview-scroll">
      <div class="group-print-sheets">
        <div
          v-for="sheet in sheets"
          :key="sheet.group?.id"
          class="print-sheet-frame"
        >
          <GroupPrintSheet
            :sheet="sheet"
            :orientation="orientation"
          />
        </div>
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

.group-print-sheets {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2rem;
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

  .group-print-sheets {
    gap: 0;
    align-items: stretch;
  }

  .print-sheet-frame {
    width: auto;
    margin: 0;
    box-shadow: none;
  }

  .group-print-sheets > .print-sheet-frame:not(:last-child) {
    break-after: page;
    page-break-after: always;
  }
}
</style>
