<script setup>
import { computed, ref, watch } from 'vue'

import BaseModal from '../../shared/components/BaseModal.vue'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import CompetitionService from '../services/CompetitionService'
import {
  competitionToFormValues,
  DEFAULT_COMPETITION_FORM_VALUES,
} from '../utils/buildCompetitionPayload'
import {
  isStructureEditable,
  structureLockReason,
} from '../utils/competitionStructure'
import CompetitionForm from './CompetitionForm.vue'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  mode: {
    type: String,
    default: 'create',
    validator: (value) => ['create', 'edit'].includes(value),
  },
  tournamentId: {
    type: [String, Number],
    default: null,
  },
  competitionId: {
    type: [String, Number],
    default: null,
  },
  competition: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['close', 'saved'])

const loadedCompetition = ref(null)
const isLoading = ref(false)
const loadError = ref('')
const isSubmitting = ref(false)
const errors = ref({})
const errorMessage = ref('')

const resolvedCompetition = computed(
  () => props.competition ?? loadedCompetition.value,
)

const resolvedCompetitionId = computed(
  () => props.competitionId ?? props.competition?.id ?? loadedCompetition.value?.id ?? null,
)

const initialValues = computed(() => {
  if (props.mode === 'create') {
    return { ...DEFAULT_COMPETITION_FORM_VALUES }
  }

  return competitionToFormValues(resolvedCompetition.value)
})

const structureEditable = computed(() =>
  props.mode === 'create' ? true : isStructureEditable(resolvedCompetition.value),
)

const lockReason = computed(() => {
  if (props.mode === 'create') {
    return ''
  }

  return (
    structureLockReason(resolvedCompetition.value) ||
    'La estructura deportiva de esta competencia ya no puede modificarse.'
  )
})

const modalTitle = computed(() =>
  props.mode === 'create' ? 'Nueva competencia' : 'Editar competencia',
)

const modalDescription = computed(() =>
  props.mode === 'create'
    ? 'Definí la categoría, formato y reglas de partidos.'
    : 'Actualizá los datos de la competencia.',
)

const resetState = () => {
  loadedCompetition.value = null
  isLoading.value = false
  loadError.value = ''
  isSubmitting.value = false
  errors.value = {}
  errorMessage.value = ''
}

const loadCompetition = async () => {
  if (props.mode !== 'edit' || !resolvedCompetitionId.value || props.competition) {
    return
  }

  isLoading.value = true
  loadError.value = ''

  try {
    loadedCompetition.value = await CompetitionService.show(resolvedCompetitionId.value)
  } catch (error) {
    loadError.value = extractApiErrorMessage(error, 'No se pudo cargar la competencia.')
    loadedCompetition.value = null
  } finally {
    isLoading.value = false
  }
}

const handleClose = () => {
  if (isSubmitting.value) {
    return
  }

  emit('close')
}

const handleSubmit = async (payload) => {
  if (isSubmitting.value) {
    return
  }

  isSubmitting.value = true
  errors.value = {}
  errorMessage.value = ''

  try {
    const result =
      props.mode === 'create'
        ? await CompetitionService.create(props.tournamentId, payload)
        : await CompetitionService.update(resolvedCompetitionId.value, payload)

    emit('saved', result)
  } catch (error) {
    errors.value = error?.response?.data?.errors ?? {}
    errorMessage.value = extractApiErrorMessage(
      error,
      props.mode === 'edit'
        ? 'No se pudo actualizar la competencia.'
        : 'No se pudo crear la competencia.',
    )
  } finally {
    isSubmitting.value = false
  }
}

watch(
  () => props.show,
  async (isVisible) => {
    if (!isVisible) {
      resetState()
      return
    }

    resetState()

    if (props.mode === 'edit') {
      if (props.competition) {
        loadedCompetition.value = props.competition
        return
      }

      await loadCompetition()
    }
  },
)
</script>

<template>
  <BaseModal
    :show="show"
    :title="modalTitle"
    :description="modalDescription"
    size="xl"
    :prevent-close="isSubmitting"
    @close="handleClose"
  >
    <p v-if="isLoading" class="text-slate-600 dark:text-slate-300">Cargando competencia...</p>
    <p v-else-if="loadError" class="text-red-600 dark:text-red-400">{{ loadError }}</p>

    <template v-else>
      <p v-if="errorMessage" class="text-red-600 dark:text-red-400">{{ errorMessage }}</p>

      <CompetitionForm
        :mode="mode"
        :initial-values="initialValues"
        :is-submitting="isSubmitting"
        :errors="errors"
        :structure-editable="structureEditable"
        :structure-lock-reason="lockReason"
        embedded
        @submit="handleSubmit"
        @cancel="handleClose"
      />
    </template>
  </BaseModal>
</template>
