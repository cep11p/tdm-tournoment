<script setup>
import { computed, ref, watch } from 'vue'

import BaseModal from '../../shared/components/BaseModal.vue'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import TournamentService from '../services/TournamentService'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  tournament: {
    type: Object,
    default: null,
  },
  competitions: {
    type: Array,
    default: () => [],
  },
})

const emit = defineEmits(['close', 'saved'])

const isSubmitting = ref(false)
const errorMessage = ref('')
const confirmed = ref(false)

const isUnusedCompetition = (competition) =>
  Number(competition?.registrations_count ?? 0) === 0 && Number(competition?.games_count ?? 0) === 0

const completedCompetitions = computed(() =>
  props.competitions.filter((competition) => competition.status_summary?.code === 'completed'),
)

const unusedCompetitions = computed(() => props.competitions.filter(isUnusedCompetition))

const pendingCompetitions = computed(() =>
  props.competitions.filter((competition) => {
    if (isUnusedCompetition(competition)) {
      return false
    }

    return competition.status_summary?.code !== 'completed'
  }),
)

const availableResults = computed(() =>
  props.competitions
    .filter((competition) => competition.result_summary?.champion?.name)
    .map((competition) => ({
      name: competition.name,
      champion: competition.result_summary.champion.name,
      runnerUp: competition.result_summary.runner_up?.name ?? '-',
    })),
)

const canSubmit = computed(() => confirmed.value && !isSubmitting.value)

const resetState = () => {
  isSubmitting.value = false
  errorMessage.value = ''
  confirmed.value = false
}

const handleClose = () => {
  if (isSubmitting.value) {
    return
  }

  emit('close')
}

const handleSubmit = async () => {
  if (!props.tournament?.id || !canSubmit.value) {
    return
  }

  isSubmitting.value = true
  errorMessage.value = ''

  try {
    const result = await TournamentService.close(props.tournament.id)
    emit('saved', result)
  } catch (error) {
    errorMessage.value = extractApiErrorMessage(error, 'No se pudo finalizar el torneo.')
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
    title="Finalizar torneo"
    description="Confirmá el cierre administrativo del torneo."
    size="lg"
    :prevent-close="isSubmitting"
    @close="handleClose"
  >
    <div class="space-y-4 text-sm">
      <p class="text-slate-600 dark:text-slate-300">
        Al finalizar el torneo se bloquearán nuevas modificaciones deportivas.
      </p>

      <dl class="grid gap-3 rounded-md border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900/60 sm:grid-cols-2">
        <div>
          <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Competencias</dt>
          <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
            {{ competitions.length }}
          </dd>
        </div>
        <div>
          <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Completadas</dt>
          <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
            {{ completedCompetitions.length }}
          </dd>
        </div>
        <div>
          <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Sin actividad</dt>
          <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
            {{ unusedCompetitions.length }}
          </dd>
        </div>
        <div>
          <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Pendientes</dt>
          <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
            {{ pendingCompetitions.length }}
          </dd>
        </div>
      </dl>

      <div
        v-if="pendingCompetitions.length > 0"
        class="rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-100"
      >
        <p class="font-medium">Competencias pendientes</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
          <li v-for="competition in pendingCompetitions" :key="competition.id">
            {{ competition.name }}
            <span v-if="competition.status_summary?.label" class="text-amber-800 dark:text-amber-200">
              ({{ competition.status_summary.label }})
            </span>
          </li>
        </ul>
      </div>

      <div
        v-if="availableResults.length > 0"
        class="rounded-md border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-900 dark:bg-emerald-950/30"
      >
        <p class="font-medium text-emerald-900 dark:text-emerald-100">Resultados disponibles</p>
        <ul class="mt-2 space-y-2">
          <li
            v-for="result in availableResults"
            :key="result.name"
            class="rounded-md border border-emerald-200 bg-white/70 p-3 dark:border-emerald-800 dark:bg-slate-900/40"
          >
            <p class="font-medium text-slate-900 dark:text-slate-100">{{ result.name }}</p>
            <p class="mt-1 text-slate-700 dark:text-slate-300">Campeón: {{ result.champion }}</p>
            <p class="text-slate-700 dark:text-slate-300">Subcampeón: {{ result.runnerUp }}</p>
          </li>
        </ul>
      </div>

      <label class="flex items-start gap-2 text-slate-700 dark:text-slate-200">
        <input
          v-model="confirmed"
          type="checkbox"
          class="mt-1 rounded border-slate-300 text-slate-900 focus:ring-slate-500 dark:border-slate-600 dark:bg-slate-800"
          :disabled="isSubmitting"
        />
        <span>Entiendo que se bloquearán las modificaciones deportivas del torneo.</span>
      </label>

      <p v-if="errorMessage" class="text-red-600 dark:text-red-400">{{ errorMessage }}</p>
    </div>

    <template #footer>
      <div class="flex justify-end gap-3">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
          :disabled="isSubmitting"
          @click="handleClose"
        >
          Cancelar
        </button>
        <button
          type="button"
          class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-60 dark:bg-emerald-600 dark:hover:bg-emerald-500"
          :disabled="!canSubmit"
          @click="handleSubmit"
        >
          {{ isSubmitting ? 'Finalizando...' : 'Finalizar torneo' }}
        </button>
      </div>
    </template>
  </BaseModal>
</template>
