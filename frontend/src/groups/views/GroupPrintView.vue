<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { PrinterIcon } from '@heroicons/vue/24/outline'

import AppBackButton from '../../components/AppBackButton.vue'
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
    <div class="group-print-toolbar no-print">
      <AppBackButton :fallback-to="groupDetailHref" />

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

    <GroupPrintSheet
      v-else-if="sheet"
      :sheet="sheet"
      :orientation="orientation"
    />
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

@media print {
  .no-print {
    display: none !important;
  }

  .group-print-page {
    background: #fff;
    color: #111;
  }
}
</style>
