<script setup>
import { computed, ref, watch } from 'vue'

import { getParticipantKind } from '../../shared/constants/competitionType'
import BaseModal from '../../shared/components/BaseModal.vue'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import GroupService from '../services/GroupService'
import { formatEntryDisplayName } from '../utils/unassignedCompetitionEntries'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  groupId: {
    type: [String, Number],
    required: true,
  },
  groupName: {
    type: String,
    default: 'Grupo',
  },
  competition: {
    type: Object,
    default: null,
  },
  groupEntry: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['close', 'saved'])

const isSubmitting = ref(false)
const submitError = ref('')

const participantKind = computed(() => getParticipantKind(props.competition))
const isPair = computed(() => participantKind.value === 'pair')

const entryName = computed(() =>
  formatEntryDisplayName(props.groupEntry, { participantKind: participantKind.value }),
)

const modalTitle = computed(() => (isPair.value ? 'Quitar pareja' : 'Quitar participante'))

const confirmQuestion = computed(
  () => `¿Querés quitar a ${entryName.value} del ${props.groupName}?`,
)

const successMessage = computed(() =>
  isPair.value ? 'Pareja quitada del grupo.' : 'Participante quitado del grupo.',
)

const canConfirm = computed(
  () => Boolean(props.groupEntry?.competition_entry_id) && !isSubmitting.value,
)

const resetState = () => {
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
    await GroupService.removeEntry(props.groupId, props.groupEntry.competition_entry_id)

    emit('saved', { message: successMessage.value })
  } catch (error) {
    submitError.value = extractApiErrorMessage(
      error,
      isPair.value ? 'No se pudo quitar la pareja.' : 'No se pudo quitar el participante.',
    )
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
    :title="modalTitle"
    size="md"
    :prevent-close="isSubmitting"
    @close="handleClose"
  >
    <div class="space-y-3">
      <p class="text-slate-800 dark:text-slate-100">{{ confirmQuestion }}</p>

      <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-200">
        <p>Se eliminarán únicamente los partidos pendientes que correspondan.</p>
        <p>Los resultados ya jugados nunca se modifican.</p>
      </div>

      <p v-if="submitError" class="text-red-600 dark:text-red-400">{{ submitError }}</p>
    </div>

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
        class="rounded-md bg-red-700 px-3 py-2 font-medium text-white hover:bg-red-600 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-red-600 dark:hover:bg-red-500"
        :disabled="!canConfirm"
        @click="handleConfirm"
      >
        {{ isSubmitting ? 'Quitando...' : 'Quitar del grupo' }}
      </button>
    </template>
  </BaseModal>
</template>
