<script setup>
import { computed, ref, watch } from 'vue'

import BaseModal from '../../shared/components/BaseModal.vue'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import GroupService from '../services/GroupService'
import { suggestNextGroupName } from '../utils/suggestNextGroupName'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  competitionId: {
    type: [String, Number],
    required: true,
  },
  existingGroups: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['close', 'saved'])

const name = ref('')
const isSubmitting = ref(false)
const submitError = ref('')

const suggestedName = computed(() => suggestNextGroupName(props.existingGroups))

const canConfirm = computed(() => name.value.trim() !== '' && !isSubmitting.value)

const resetState = () => {
  name.value = suggestedName.value
  isSubmitting.value = false
  submitError.value = ''
}

const handleClose = () => {
  if (isSubmitting.value) {
    return
  }

  emit('close')
}

const handleConfirm = async () => {
  if (!canConfirm.value) {
    return
  }

  isSubmitting.value = true
  submitError.value = ''

  try {
    const group = await GroupService.create(props.competitionId, {
      name: name.value.trim(),
    })

    emit('saved', group)
  } catch (error) {
    submitError.value = extractApiErrorMessage(error, 'No se pudo crear el grupo.')
  } finally {
    isSubmitting.value = false
  }
}

watch(
  () => props.show,
  (isVisible) => {
    if (isVisible) {
      resetState()
    }
  },
)
</script>

<template>
  <BaseModal
    :show="show"
    title="Crear grupo"
    description="Se va a crear un grupo vacío. Después podés agregarle participantes."
    size="sm"
    :prevent-close="isSubmitting"
    @close="handleClose"
  >
    <label class="block" for="create-group-name">
      <span class="mb-1 block font-medium text-slate-700 dark:text-slate-200">Nombre</span>
      <input
        id="create-group-name"
        v-model="name"
        type="text"
        autocomplete="off"
        class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
        :disabled="isSubmitting"
      />
    </label>

    <p v-if="submitError" class="mt-3 text-red-600 dark:text-red-400">{{ submitError }}</p>

    <template #footer>
      <button
        type="button"
        class="rounded-md border border-slate-300 px-3 py-2 font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
        :disabled="isSubmitting"
        @click="handleClose"
      >
        Cancelar
      </button>
      <button
        type="button"
        class="rounded-md bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
        :disabled="!canConfirm"
        @click="handleConfirm"
      >
        {{ isSubmitting ? 'Creando...' : 'Crear grupo' }}
      </button>
    </template>
  </BaseModal>
</template>
