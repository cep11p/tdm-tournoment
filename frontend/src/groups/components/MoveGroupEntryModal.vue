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
  sourceGroupId: {
    type: [String, Number],
    required: true,
  },
  competition: {
    type: Object,
    default: null,
  },
  groupEntry: {
    type: Object,
    default: null,
  },
  targetGroups: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['close', 'saved'])

const selectedTargetGroupId = ref('')
const isSubmitting = ref(false)
const submitError = ref('')

const participantKind = computed(() => getParticipantKind(props.competition))
const isPair = computed(() => participantKind.value === 'pair')

const entryName = computed(() =>
  formatEntryDisplayName(props.groupEntry, { participantKind: participantKind.value }),
)

const entryLabel = computed(() => (isPair.value ? 'Pareja' : 'Participante'))

const modalTitle = computed(() => (isPair.value ? 'Mover pareja' : 'Mover participante'))

const selectedTargetGroup = computed(() =>
  props.targetGroups.find((group) => String(group.id) === selectedTargetGroupId.value) ?? null,
)

const successMessage = computed(() => {
  const targetName = selectedTargetGroup.value?.name ?? 'el grupo destino'

  if (isPair.value) {
    return `Pareja movida al ${targetName}.`
  }

  return `Participante movido al ${targetName}.`
})

const canConfirm = computed(
  () =>
    Boolean(props.groupEntry?.competition_entry_id) &&
    Boolean(selectedTargetGroupId.value) &&
    props.targetGroups.length > 0 &&
    !isSubmitting.value,
)

const resetState = () => {
  selectedTargetGroupId.value =
    props.targetGroups.length === 1 ? String(props.targetGroups[0].id) : ''
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
    await GroupService.moveEntry(
      props.sourceGroupId,
      props.groupEntry.competition_entry_id,
      Number(selectedTargetGroupId.value),
    )

    emit('saved', { message: successMessage.value })
  } catch (error) {
    submitError.value = extractApiErrorMessage(
      error,
      isPair.value ? 'No se pudo mover la pareja.' : 'No se pudo mover el participante.',
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
      <p>
        <span class="mb-1 block font-medium text-slate-700 dark:text-slate-200">{{ entryLabel }}</span>
        <span class="text-slate-900 dark:text-slate-100">{{ entryName }}</span>
      </p>

      <label class="block" for="move-group-entry-target">
        <span class="mb-1 block font-medium text-slate-700 dark:text-slate-200">Grupo destino</span>
        <select
          id="move-group-entry-target"
          v-model="selectedTargetGroupId"
          class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
          :disabled="isSubmitting || targetGroups.length === 0"
        >
          <option value="">Seleccionar...</option>
          <option
            v-for="group in targetGroups"
            :key="group.id"
            :value="String(group.id)"
          >
            {{ group.name }}
          </option>
        </select>
      </label>

      <p v-if="targetGroups.length === 0" class="text-sm text-slate-600 dark:text-slate-300">
        No hay otro grupo en esta competencia.
      </p>

      <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-200">
        <p>Se actualizarán automáticamente los partidos pendientes de ambos grupos.</p>
        <p>Los resultados ya jugados no se modificarán.</p>
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
        class="rounded-md bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
        :disabled="!canConfirm"
        @click="handleConfirm"
      >
        {{ isSubmitting ? 'Moviendo...' : 'Mover' }}
      </button>
    </template>
  </BaseModal>
</template>
