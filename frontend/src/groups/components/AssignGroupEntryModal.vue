<script setup>
import { computed, ref, watch } from 'vue'

import {
  getParticipantKind,
  participantPlural,
  participantSingular,
} from '../../shared/constants/competitionType'
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
  availableEntries: {
    type: Array,
    default: () => [],
  },
  currentMemberCount: {
    type: Number,
    default: 0,
  },
})

const emit = defineEmits(['close', 'saved'])

const selectedEntryId = ref('')
const isSubmitting = ref(false)
const submitError = ref('')

const participantKind = computed(() => getParticipantKind(props.competition))
const singularLabel = computed(() => participantSingular(props.competition))
const pluralLabel = computed(() => participantPlural(props.competition))

const modalTitle = computed(() => `Agregar ${singularLabel.value} a ${props.groupName}`)

const newGamesCount = computed(() => Math.max(0, Number(props.currentMemberCount) || 0))

const infoLines = computed(() => {
  const lines = [
    'Al agregarlo se crearán automáticamente los partidos que falten.',
    'Los resultados ya cargados no se modificarán.',
  ]

  if (newGamesCount.value > 0) {
    const gamesLabel = newGamesCount.value === 1 ? '1 partido nuevo' : `${newGamesCount.value} partidos nuevos`
    const subject =
      participantKind.value === 'pair' ? 'para la nueva pareja' : `para el nuevo ${singularLabel.value}`

    lines.unshift(`Se crearán ${gamesLabel} ${subject}.`)
  }

  return lines
})

const emptyOptionsMessage = computed(
  () => `No hay ${pluralLabel.value} disponibles para agregar. Inscribí ${pluralLabel.value} o elegí ${singularLabel.value === 'pareja' ? 'una que' : 'uno que'} todavía no esté en un grupo.`,
)

const successMessage = computed(() => {
  if (participantKind.value === 'pair') {
    return 'Pareja agregada. Se completaron los partidos del grupo.'
  }

  return 'Participante agregado. Se completaron los partidos del grupo.'
})

const firstAddedMessage = computed(() => {
  if (participantKind.value === 'pair') {
    return 'Pareja agregada.'
  }

  return 'Participante agregado.'
})

const canConfirm = computed(
  () => Boolean(selectedEntryId.value) && !isSubmitting.value && props.availableEntries.length > 0,
)

const resetState = () => {
  selectedEntryId.value = ''
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
    const assigned = await GroupService.assignEntry(props.groupId, {
      competition_entry_id: Number(selectedEntryId.value),
    })

    emit('saved', {
      assigned,
      message: props.currentMemberCount >= 1 ? successMessage.value : firstAddedMessage.value,
    })
  } catch (error) {
    submitError.value = extractApiErrorMessage(error, `No se pudo agregar ${singularLabel.value}.`)
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
      <label class="block" for="assign-group-entry">
        <span class="mb-1 block font-medium text-slate-700 dark:text-slate-200">
          {{ singularLabel.charAt(0).toUpperCase() + singularLabel.slice(1) }}
        </span>
        <select
          id="assign-group-entry"
          v-model="selectedEntryId"
          class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
          :disabled="isSubmitting || availableEntries.length === 0"
        >
          <option value="">Seleccionar...</option>
          <option
            v-for="entry in availableEntries"
            :key="entry.id"
            :value="String(entry.id)"
          >
            {{ formatEntryDisplayName(entry, { participantKind }) }}
          </option>
        </select>
      </label>

      <p v-if="availableEntries.length === 0" class="text-sm text-slate-600 dark:text-slate-300">
        {{ emptyOptionsMessage }}
      </p>

      <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-slate-700 dark:border-slate-700 dark:bg-slate-800/60 dark:text-slate-200">
        <p v-for="(line, index) in infoLines" :key="`assign-info-${index}`">
          {{ line }}
        </p>
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
        {{ isSubmitting ? 'Agregando...' : `Agregar ${singularLabel}` }}
      </button>
    </template>
  </BaseModal>
</template>
