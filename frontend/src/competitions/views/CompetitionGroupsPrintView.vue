<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { PrinterIcon } from '@heroicons/vue/24/outline'

import AppBackButton from '../../components/AppBackButton.vue'
import GroupPrintSheet from '../../groups/components/GroupPrintSheet.vue'
import GroupService from '../../groups/services/GroupService'
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

      <button
        type="button"
        class="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70"
        :disabled="sheets.length === 0"
        @click="handlePrint"
      >
        <PrinterIcon class="h-4 w-4" />
        Imprimir todos
      </button>
    </div>

    <p v-if="isLoading" class="no-print mt-6 text-sm text-slate-600">Cargando planillas...</p>
    <p v-else-if="errorMessage" class="no-print mt-6 text-sm text-red-700">{{ errorMessage }}</p>

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
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1rem;
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
