<script setup>
import { computed, ref, watch } from 'vue'

import BaseModal from '../../shared/components/BaseModal.vue'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import TournamentService from '../services/TournamentService'
import TournamentForm from './TournamentForm.vue'

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
  tournament: {
    type: Object,
    default: null,
  },
  tournamentId: {
    type: [String, Number],
    default: null,
  },
})

const emit = defineEmits(['close', 'saved'])

const loadedTournament = ref(null)
const isLoading = ref(false)
const loadError = ref('')
const isSubmitting = ref(false)
const errors = ref({})
const errorMessage = ref('')

const resolvedTournamentId = computed(
  () => props.tournamentId ?? props.tournament?.id ?? loadedTournament.value?.id ?? null,
)

const initialValues = computed(() => {
  const source = props.tournament ?? loadedTournament.value

  if (!source) {
    return {
      name: '',
      location: '',
      start_date: '',
      end_date: '',
      status: 'draft',
    }
  }

  return {
    name: source.name ?? '',
    location: source.location ?? '',
    start_date: source.start_date ?? '',
    end_date: source.end_date ?? '',
    status: source.status ?? 'draft',
  }
})

const modalTitle = computed(() =>
  props.mode === 'edit'
    ? loadedTournament.value?.name
      ? `Editar ${loadedTournament.value.name}`
      : props.tournament?.name
        ? `Editar ${props.tournament.name}`
        : 'Editar torneo'
    : 'Nuevo torneo',
)

const modalDescription = computed(() =>
  props.mode === 'edit'
    ? 'Modificá los datos del torneo.'
    : 'Formulario inicial para crear un torneo.',
)

const submitLabel = computed(() => (props.mode === 'edit' ? 'Guardar cambios' : 'Guardar'))

const resetState = () => {
  loadedTournament.value = null
  isLoading.value = false
  loadError.value = ''
  isSubmitting.value = false
  errors.value = {}
  errorMessage.value = ''
}

const loadTournament = async () => {
  if (props.mode !== 'edit' || !resolvedTournamentId.value || props.tournament) {
    return
  }

  isLoading.value = true
  loadError.value = ''

  try {
    loadedTournament.value = await TournamentService.show(resolvedTournamentId.value)
  } catch (error) {
    loadError.value = extractApiErrorMessage(error, 'No se pudo cargar el torneo.')
    loadedTournament.value = null
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
  isSubmitting.value = true
  errors.value = {}
  errorMessage.value = ''

  try {
    const result =
      props.mode === 'edit'
        ? await TournamentService.update(resolvedTournamentId.value, payload)
        : await TournamentService.create(payload)

    emit('saved', result)
  } catch (error) {
    errors.value = error?.response?.data?.errors ?? {}
    errorMessage.value = extractApiErrorMessage(
      error,
      props.mode === 'edit' ? 'No se pudo actualizar el torneo.' : 'No se pudo crear el torneo.',
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
      if (props.tournament) {
        loadedTournament.value = props.tournament
        return
      }

      await loadTournament()
    }
  },
)
</script>

<template>
  <BaseModal
    :show="show"
    :title="modalTitle"
    :description="modalDescription"
    size="lg"
    :prevent-close="isSubmitting"
    @close="handleClose"
  >
    <p v-if="isLoading" class="text-slate-600 dark:text-slate-300">Cargando torneo...</p>
    <p v-else-if="loadError" class="text-red-600 dark:text-red-400">{{ loadError }}</p>

    <template v-else>
      <p v-if="errorMessage" class="text-red-600 dark:text-red-400">{{ errorMessage }}</p>

      <TournamentForm
        :initial-values="initialValues"
        :is-submitting="isSubmitting"
        :errors="errors"
        :submit-label="submitLabel"
        embedded
        @submit="handleSubmit"
        @cancel="handleClose"
      />
    </template>
  </BaseModal>
</template>
