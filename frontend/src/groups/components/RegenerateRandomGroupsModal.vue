<script setup>
import { computed, ref, watch } from 'vue'

import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import GroupService from '../services/GroupService'
import { buildRegenerateRandomGroupsSuccessMessage } from '../utils/buildRegenerateRandomGroupsSuccessMessage'
import {
  calculateBalancedGroupSizes,
  formatBalancedGroupSizes,
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
  existingGroupsCount: {
    type: Number,
    default: 2,
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

const maxGroups = computed(() => Math.max(props.registeredCount, 1))

const estimatedDistribution = computed(() => {
  const sizes = calculateBalancedGroupSizes(props.registeredCount, groupsCount.value)

  if (sizes.length === 0) {
    return ''
  }

  return `${props.registeredCount} jugadores en ${groupsCount.value} grupo${groupsCount.value === 1 ? '' : 's'}: ${formatBalancedGroupSizes(sizes)} por grupo`
})

const confirmDisabledReason = computed(() => {
  if (props.isCompetitionCompleted) {
    return 'La competencia ya está finalizada.'
  }

  if (props.registeredCount < 2) {
    return 'Se requieren al menos 2 jugadores inscriptos.'
  }

  if (groupsCount.value < 1 || groupsCount.value > props.registeredCount) {
    return 'La cantidad de grupos debe estar entre 1 y la cantidad de inscriptos.'
  }

  if (isSubmitting.value) {
    return 'Regenerando grupos...'
  }

  return ''
})

const canConfirm = computed(() => confirmDisabledReason.value === '')

const isConfirmDisabled = computed(() => !canConfirm.value)

const resetState = () => {
  groupsCount.value = Math.max(1, props.existingGroupsCount || Math.min(2, props.registeredCount))
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
    const result = await GroupService.regenerateRandomGroups(props.competitionId, {
      groups_count: groupsCount.value,
    })

    successMessage.value = buildRegenerateRandomGroupsSuccessMessage(result)

    emit('saved', result)
  } catch (error) {
    submitError.value = extractApiErrorMessage(error, 'No se pudieron regenerar los grupos.')
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
    if (count > 0 && groupsCount.value > count) {
      groupsCount.value = count
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
        aria-labelledby="regenerate-random-groups-modal-title"
      >
        <div class="space-y-4 overflow-y-auto p-4">
          <div>
            <h2
              id="regenerate-random-groups-modal-title"
              class="text-lg font-semibold text-slate-900 dark:text-slate-100"
            >
              Regenerar grupos y partidos
            </h2>
            <p class="mt-1 text-slate-600 dark:text-slate-300">
              Esto eliminará los grupos y partidos pendientes actuales y los volverá a generar con las
              inscripciones vigentes. ¿Querés continuar?
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
            Se requieren al menos 2 jugadores inscriptos para regenerar grupos.
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
              :max="maxGroups"
              class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
              :disabled="isSubmitting || registeredCount < 2 || isCompetitionCompleted"
            />
          </label>

          <p v-if="estimatedDistribution" class="text-slate-600 dark:text-slate-300">
            Distribución estimada: {{ estimatedDistribution }}
          </p>

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
            class="rounded-md bg-amber-700 px-3 py-2 font-medium text-white hover:bg-amber-600 disabled:cursor-not-allowed disabled:opacity-70"
            :disabled="isConfirmDisabled"
            @click="handleConfirm"
          >
            {{ isSubmitting ? 'Regenerando...' : 'Confirmar regeneración' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
