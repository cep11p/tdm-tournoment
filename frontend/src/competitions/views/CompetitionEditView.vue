<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import CompetitionForm from '../components/CompetitionForm.vue'
import CompetitionService from '../services/CompetitionService'
import {
  competitionToFormValues,
  DEFAULT_COMPETITION_FORM_VALUES,
} from '../utils/buildCompetitionPayload'
import {
  isStructureEditable,
  structureLockReason,
} from '../utils/competitionStructure'

const route = useRoute()
const router = useRouter()

const competitionId = computed(() => route.params.id)
const competition = ref(null)
const isLoading = ref(false)
const loadError = ref('')
const isSubmitting = ref(false)
const errors = ref({})
const errorMessage = ref('')

const structureEditable = computed(() => isStructureEditable(competition.value))

const lockReason = computed(
  () =>
    structureLockReason(competition.value) ||
    'La estructura deportiva de esta competencia ya no puede modificarse.',
)

const initialValues = computed(() =>
  competition.value
    ? competitionToFormValues(competition.value)
    : { ...DEFAULT_COMPETITION_FORM_VALUES },
)

const loadCompetition = async () => {
  isLoading.value = true
  loadError.value = ''

  try {
    const data = await CompetitionService.show(competitionId.value)
    competition.value = data
  } catch (error) {
    loadError.value = extractApiErrorMessage(error, 'No se pudo cargar la competencia.')
    competition.value = null
  } finally {
    isLoading.value = false
  }
}

const handleSubmit = async (payload) => {
  isSubmitting.value = true
  errors.value = {}
  errorMessage.value = ''

  try {
    await CompetitionService.update(competitionId.value, payload)
    await router.push(`/competitions/${competitionId.value}`)
  } catch (error) {
    errors.value = error?.response?.data?.errors ?? {}
    errorMessage.value = extractApiErrorMessage(error, 'No se pudo actualizar la competencia.')
  } finally {
    isSubmitting.value = false
  }
}

const handleCancel = () => {
  router.push(`/competitions/${competitionId.value}`)
}

onMounted(loadCompetition)
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
        {{ competition?.name ? `Editar ${competition.name}` : 'Editar competencia' }}
      </h1>
    </div>

    <p class="text-sm text-slate-600 dark:text-slate-300">Modificá los datos de la competencia.</p>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando competencia...</p>
    <p v-else-if="loadError" class="text-sm text-red-600 dark:text-red-400">{{ loadError }}</p>

    <template v-else-if="competition">
      <p v-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

      <CompetitionForm
        mode="edit"
        :initial-values="initialValues"
        :is-submitting="isSubmitting"
        :errors="errors"
        :structure-editable="structureEditable"
        :structure-lock-reason="lockReason"
        @submit="handleSubmit"
        @cancel="handleCancel"
      />
    </template>
  </section>
</template>
