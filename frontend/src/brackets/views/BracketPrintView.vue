<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { PrinterIcon } from '@heroicons/vue/24/outline'

import AppBackButton from '../../components/AppBackButton.vue'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import { applyPrintPageSize, clearPrintPageSize } from '../../groups/utils/groupPrint'
import BracketPrintSheet from '../components/BracketPrintSheet.vue'
import BracketService from '../services/BracketService'

const route = useRoute()
const competitionId = computed(() => route.params.id)

const sheet = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')

const bracketHref = computed(() => `/competitions/${competitionId.value}/bracket`)
const needsLargePage = computed(() => Number(sheet.value?.bracket?.bracket_size) > 16)

watch(sheet, () => {
  applyPrintPageSize('A4 landscape')
}, { immediate: true })

const loadSheet = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    sheet.value = await BracketService.print(competitionId.value)
  } catch (error) {
    sheet.value = null
    errorMessage.value = extractApiErrorMessage(
      error,
      'No se pudo cargar la llave para imprimir.',
    )
  } finally {
    isLoading.value = false
  }
}

const handlePrint = () => {
  window.print()
}

onMounted(loadSheet)

onUnmounted(() => {
  clearPrintPageSize()
})
</script>

<template>
  <section class="bracket-print-page">
    <div class="bracket-print-toolbar no-print">
      <AppBackButton :fallback-to="bracketHref" />

      <button
        type="button"
        class="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70"
        :disabled="!sheet"
        @click="handlePrint"
      >
        <PrinterIcon class="h-4 w-4" />
        Imprimir
      </button>
    </div>

    <p
      v-if="needsLargePage"
      class="no-print mb-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-950"
    >
      Para esta llave se recomienda imprimir en A3. En el diálogo de impresión podés cambiar el tamaño de papel.
    </p>

    <p v-if="isLoading" class="no-print mt-6 text-sm text-slate-600">Cargando llave...</p>
    <p v-else-if="errorMessage" class="no-print mt-6 text-sm text-red-700">{{ errorMessage }}</p>

    <BracketPrintSheet
      v-else-if="sheet"
      :sheet="sheet"
    />
  </section>
</template>

<style scoped>
.bracket-print-page {
  color: #111;
}

.bracket-print-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

@media print {
  .no-print {
    display: none !important;
  }

  .bracket-print-page {
    background: #fff;
    color: #111;
  }
}
</style>
