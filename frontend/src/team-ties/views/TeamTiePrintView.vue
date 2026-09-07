<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { ArrowDownTrayIcon, PrinterIcon } from '@heroicons/vue/24/outline'

import AppBackButton from '../../components/AppBackButton.vue'
import { downloadFileUrl } from '../../shared/utils/downloadFileUrl'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import { applyPrintPageSize, clearPrintPageSize } from '../../groups/utils/groupPrint'
import TeamTiePrintSheet from '../components/TeamTiePrintSheet.vue'
import TeamTieService from '../services/TeamTieService'

const route = useRoute()
const teamTieId = computed(() => route.params.id)

const sheet = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')

const detailHref = computed(() => `/team-ties/${teamTieId.value}`)

const loadSheet = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    sheet.value = await TeamTieService.print(teamTieId.value)
  } catch (error) {
    sheet.value = null
    errorMessage.value = extractApiErrorMessage(
      error,
      'No se pudo cargar el enfrentamiento para imprimir.',
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
    TeamTieService.printPdfDownloadUrl(teamTieId.value),
  )
}

onMounted(() => {
  applyPrintPageSize('A4 portrait')
  loadSheet()
})

onUnmounted(() => {
  clearPrintPageSize()
})
</script>

<template>
  <section class="team-tie-print-page">
    <div class="team-tie-print-toolbar no-print border border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900">
      <div class="team-tie-print-toolbar-start">
        <AppBackButton :fallback-to="detailHref" />
        <div class="team-tie-print-toolbar-copy">
          <h1 class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100">Planilla de enfrentamiento</h1>
        </div>
      </div>

      <div class="team-tie-print-actions">
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
        <TeamTiePrintSheet
          :sheet="sheet"
        />
      </div>
    </div>
  </section>
</template>

<style scoped>
.team-tie-print-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
  padding: 0.75rem 1rem;
  border-radius: 0.375rem;
}

.team-tie-print-toolbar-start {
  display: flex;
  min-width: 0;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}

.team-tie-print-toolbar-copy {
  min-width: 0;
}

.team-tie-print-actions {
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

  .team-tie-print-page {
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
