<script setup>
import { computed, ref, watch } from 'vue'

import BaseModal from '../../shared/components/BaseModal.vue'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import PlayingTableService from '../services/PlayingTableService'
import PlayingTableForm from './PlayingTableForm.vue'

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
  playingTable: {
    type: Object,
    default: null,
  },
  suggestedNumber: {
    type: Number,
    default: 1,
  },
})

const emit = defineEmits(['close', 'saved'])

const isSubmitting = ref(false)
const errors = ref({})
const errorMessage = ref('')

const initialValues = computed(() => {
  if (props.mode === 'edit' && props.playingTable) {
    return {
      number: props.playingTable.number ?? 1,
      name: props.playingTable.name ?? '',
      sort_order: props.playingTable.sort_order ?? props.playingTable.number ?? 1,
      active: props.playingTable.active ?? true,
    }
  }

  return {
    number: props.suggestedNumber,
    name: '',
    sort_order: props.suggestedNumber,
    active: true,
  }
})

const modalTitle = computed(() => (props.mode === 'create' ? 'Nueva mesa' : 'Editar mesa'))

const modalDescription = computed(() =>
  props.mode === 'create'
    ? 'Agregá una mesa al catálogo de este torneo.'
    : 'Modificá los datos de la mesa.',
)

const submitLabel = computed(() => (props.mode === 'edit' ? 'Guardar cambios' : 'Guardar'))

const resetState = () => {
  isSubmitting.value = false
  errors.value = {}
  errorMessage.value = ''
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
      props.mode === 'edit'
        ? await PlayingTableService.update(props.tournamentId, props.playingTable.id, payload)
        : await PlayingTableService.create(props.tournamentId, payload)

    emit('saved', result)
  } catch (error) {
    errors.value = error?.response?.data?.errors ?? {}
    errorMessage.value = extractApiErrorMessage(
      error,
      props.mode === 'edit' ? 'No se pudo actualizar la mesa.' : 'No se pudo crear la mesa.',
    )
  } finally {
    isSubmitting.value = false
  }
}

watch(
  () => props.show,
  (isVisible) => {
    if (!isVisible) {
      resetState()
    }
  },
)
</script>

<template>
  <BaseModal
    :show="show"
    :title="modalTitle"
    :description="modalDescription"
    size="md"
    :prevent-close="isSubmitting"
    @close="handleClose"
  >
    <p v-if="errorMessage" class="mb-3 text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <PlayingTableForm
      :initial-values="initialValues"
      :is-submitting="isSubmitting"
      :errors="errors"
      :submit-label="submitLabel"
      embedded
      @submit="handleSubmit"
      @cancel="handleClose"
    />
  </BaseModal>
</template>
