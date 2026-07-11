<script setup>
import { computed, ref, watch } from 'vue'

import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import GroupService from '../services/GroupService'
import { buildRandomGroupsSuccessMessage } from '../utils/buildRandomGroupsSuccessMessage'
import {
  formatEstimatedDistributionSizes,
  formatEstimatedDistributionSummary,
  isValidGroupDistribution,
  maxValidGroupsCount,
} from '../utils/calculateBalancedGroupSizes'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  competitionId: {
    type: [String, Number],
    required: true,
  },
  registeredCount: {
    type: Number,
    default: 0,
  },
  hasExistingGroups: {
    type: Boolean,
    default: false,
  },
  isCompetitionCompleted: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['close', 'saved'])

const groupsCount = ref(2)
const isSubmitting = ref(false)
const submitError = ref('')
const successMessage = ref('')

const maxGroups = computed(() => maxValidGroupsCount(props.registeredCount))

const estimatedDistributionSummary = computed(() =>
  formatEstimatedDistributionSummary(props.registeredCount, groupsCount.value),
)

const estimatedDistributionSizes = computed(() =>
  formatEstimatedDistributionSizes(props.registeredCount, groupsCount.value),
)

const confirmDisabledReason = computed(() => {
  if (props.isCompetitionCompleted) {
    return 'La competencia ya está finalizada.'
  }

  if (props.hasExistingGroups) {
    return 'Esta competencia ya tiene grupos configurados.'
  }

  if (props.registeredCount < 2) {
    return 'Se requieren al menos 2 jugadores inscriptos.'
  }

  if (groupsCount.value < 1 || groupsCount.value > maxGroups.value) {
    const maxLabel = maxGroups.value === 1 ? '1 grupo' : `${maxGroups.value} grupos`

    return `Con ${props.registeredCount} jugadores, el máximo es ${maxLabel}.`
  }

  if (!isValidGroupDistribution(props.registeredCount, groupsCount.value)) {
    const maxLabel = maxGroups.value === 1 ? '1 grupo' : `${maxGroups.value} grupos`

    return `Con ${props.registeredCount} jugadores, el máximo es ${maxLabel}.`
  }

  if (isSubmitting.value) {
    return 'Generando grupos...'
  }

  return ''
})

const canConfirm = computed(() => confirmDisabledReason.value === '')

const isConfirmDisabled = computed(() => !canConfirm.value)

const resetState = () => {
  const max = maxValidGroupsCount(props.registeredCount)
  groupsCount.value = max >= 2 ? Math.min(2, max) : Math.max(1, max)
  submitError.value = ''
  successMessage.value = ''
}

const handleClose = () => {
  if (isSubmitting.value) {
    return
  }

  emit('close')
}

const handleConfirm = async () => {
  if (isConfirmDisabled.value) {
    return
  }

  isSubmitting.value = true
  submitError.value = ''
  successMessage.value = ''

  try {
    const result = await GroupService.generateRandomGroups(props.competitionId, {
      groups_count: groupsCount.value,
    })

    successMessage.value = buildRandomGroupsSuccessMessage(result)

    emit('saved', result)
  } catch (error) {
    submitError.value = extractApiErrorMessage(error, 'No se pudieron generar los grupos.')
  } finally {
    isSubmitting.value = false
  }
}

watch(
  () => props.show,
  (isVisible) => {
    if (!isVisible) {
      resetState()
      return
    }

    resetState()
  },
)

watch(
  () => props.registeredCount,
  (count) => {
    const max = maxValidGroupsCount(count)

    if (max > 0 && groupsCount.value > max) {
      groupsCount.value = max
    }
  },
)
</script>

<template>
  <Teleport to="body">
    <div
      v-if="show"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"
      @click.self="handleClose"
    >
      <div
        class="flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-md border border-slate-200 bg-white text-sm shadow-xl dark:border-slate-700 dark:bg-slate-900"
        role="dialog"
        aria-modal="true"
        aria-labelledby="generate-random-groups-modal-title"
      >
        <div class="space-y-4 overflow-y-auto p-4">
          <div>
            <h2
              id="generate-random-groups-modal-title"
              class="text-lg font-semibold text-slate-900 dark:text-slate-100"
            >
              Generar grupos aleatorios
            </h2>
            <p class="mt-1 text-slate-600 dark:text-slate-300">
              Se crearán grupos y se asignarán los jugadores inscriptos de forma aleatoria y balanceada.
            </p>
          </div>

          <div class="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/60">
            <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Inscriptos</p>
            <p class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">
              {{ registeredCount }}
            </p>
          </div>

          <p
            v-if="registeredCount < 2"
            class="rounded-md bg-amber-50 px-3 py-2 text-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
          >
            Se requieren al menos 2 jugadores inscriptos para generar grupos.
          </p>

          <p
            v-if="hasExistingGroups"
            class="rounded-md bg-amber-50 px-3 py-2 text-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
          >
            Esta competencia ya tiene grupos. La generación automática solo está disponible cuando no hay grupos creados.
          </p>

          <p
            v-if="isCompetitionCompleted"
            class="rounded-md bg-amber-50 px-3 py-2 text-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
          >
            La competencia ya está finalizada.
          </p>

          <label class="block">
            <span class="mb-1 block font-medium text-slate-700 dark:text-slate-200">Cantidad de grupos</span>
            <input
              v-model.number="groupsCount"
              type="number"
              min="1"
              :max="maxGroups || 1"
              class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
              :disabled="isSubmitting || registeredCount < 2 || hasExistingGroups || isCompetitionCompleted"
            />
          </label>

          <div v-if="estimatedDistributionSummary" class="space-y-1 text-slate-600 dark:text-slate-300">
            <p>{{ estimatedDistributionSummary }}</p>
            <p v-if="estimatedDistributionSizes">
              Distribución estimada: {{ estimatedDistributionSizes }}
            </p>
          </div>

          <p v-if="confirmDisabledReason && !canConfirm" class="text-xs text-slate-500 dark:text-slate-400">
            {{ confirmDisabledReason }}
          </p>

          <p v-if="submitError" class="text-red-600 dark:text-red-400">{{ submitError }}</p>
          <p v-if="successMessage" class="text-emerald-700 dark:text-emerald-300">{{ successMessage }}</p>
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-200 p-4 dark:border-slate-700">
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
            class="rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70"
            :disabled="isConfirmDisabled"
            @click="handleConfirm"
          >
            {{ isSubmitting ? 'Generando...' : 'Confirmar generación' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
