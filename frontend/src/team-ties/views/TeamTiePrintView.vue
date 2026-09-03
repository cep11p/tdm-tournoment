<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { PrinterIcon } from '@heroicons/vue/24/outline'

import AppBackButton from '../../components/AppBackButton.vue'
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
    <div class="team-tie-print-toolbar no-print">
      <AppBackButton :fallback-to="detailHref" />

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

    <p v-if="isLoading" class="no-print mt-6 text-sm text-slate-600">Cargando planilla...</p>
    <p v-else-if="errorMessage" class="no-print mt-6 text-sm text-red-700">{{ errorMessage }}</p>

    <TeamTiePrintSheet
      v-else-if="sheet"
      :sheet="sheet"
    />
  </section>
</template>

<style scoped>
.team-tie-print-page {
  color: #111;
}

.team-tie-print-toolbar {
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

  .team-tie-print-page {
    background: #fff;
    color: #111;
  }
}
</style>
