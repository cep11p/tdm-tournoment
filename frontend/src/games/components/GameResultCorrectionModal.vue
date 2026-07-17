<script setup>
import { computed, ref, watch } from 'vue'

import GameService from '../services/GameService'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import { FORBIDDEN_MESSAGE } from '../../services/httpInterceptors'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  game: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['close', 'saved'])

const activeGame = ref(null)
const setRows = ref([])
const reason = ref('')
const confirmed = ref(false)
const isSaving = ref(false)
const fieldErrors = ref({})
const generalError = ref('')

const playerName = (player) => {
  if (!player?.id) {
    return 'Jugador no asignado'
  }

  return `${player.first_name} ${player.last_name}`.trim()
}

const matchFormatLabel = computed(() => {
  if (activeGame.value?.best_of && activeGame.value?.sets_to_win) {
    return `Mejor de ${activeGame.value.best_of} · gana con ${activeGame.value.sets_to_win} sets`
  }

  return null
})

const contextWarning = computed(() => {
  if (activeGame.value?.group_id) {
    return 'La corrección puede modificar posiciones y desempates del grupo.'
  }

  if (activeGame.value?.bracket_id) {
    return 'La corrección solo es posible si la llave todavía no avanzó a otra ronda.'
  }

  return null
})

const currentSetsSummary = computed(() => {
  const sets = [...(activeGame.value?.sets || [])].sort(
    (left, right) => left.set_number - right.set_number,
  )

  if (sets.length === 0) {
    return 'Sin sets registrados.'
  }

  return sets
    .map((currentSet) => `Set ${currentSet.set_number}: ${currentSet.player1_score}–${currentSet.player2_score}`)
    .join(' · ')
})

const buildSetRows = (game) => {
  const existingSets = [...(game?.sets || [])].sort(
    (left, right) => left.set_number - right.set_number,
  )

  if (existingSets.length === 0) {
    return [{ player1Score: '', player2Score: '' }]
  }

  return existingSets.map((currentSet) => ({
    player1Score: String(currentSet.player1_score),
    player2Score: String(currentSet.player2_score),
  }))
}

const resetForm = (game) => {
  activeGame.value = game
  setRows.value = buildSetRows(game)
  reason.value = ''
  confirmed.value = false
  fieldErrors.value = {}
  generalError.value = ''
}

watch(
  () => [props.show, props.game?.id, props.game?.sets?.length],
  () => {
    if (!props.show || !props.game) {
      return
    }

    resetForm(props.game)
  },
  { immediate: true },
)

const addSetRow = () => {
  const maxSets = activeGame.value?.best_of || 7

  if (setRows.value.length >= maxSets) {
    return
  }

  setRows.value.push({ player1Score: '', player2Score: '' })
}

const removeSetRow = (index) => {
  if (setRows.value.length <= 1) {
    return
  }

  setRows.value.splice(index, 1)
}

const collectPayload = () => {
  fieldErrors.value = {}
  generalError.value = ''

  const trimmedReason = reason.value.trim()

  if (trimmedReason.length < 10) {
    fieldErrors.value.reason = 'El motivo debe tener al menos 10 caracteres.'
  }

  if (!confirmed.value) {
    fieldErrors.value.confirmation = 'Debés confirmar que entendés el impacto de la corrección.'
  }

  const sets = []

  for (const [index, row] of setRows.value.entries()) {
    if (row.player1Score === '' || row.player2Score === '') {
      fieldErrors.value[`sets.${index}`] = `Completá ambos puntajes del set ${index + 1}.`
      continue
    }

    const player1Score = Number(row.player1Score)
    const player2Score = Number(row.player2Score)

    if (!Number.isFinite(player1Score) || !Number.isFinite(player2Score)) {
      fieldErrors.value[`sets.${index}`] = `Los puntajes del set ${index + 1} deben ser números válidos.`
      continue
    }

    if (player1Score < 0 || player2Score < 0) {
      fieldErrors.value[`sets.${index}`] = `Los puntajes del set ${index + 1} no pueden ser negativos.`
      continue
    }

    sets.push({ player1_score: player1Score, player2_score: player2Score })
  }

  if (sets.length === 0 && !fieldErrors.value.sets) {
    fieldErrors.value.sets = 'Agregá al menos un set válido.'
  }

  if (Object.keys(fieldErrors.value).length > 0) {
    return null
  }

  return {
    reason: trimmedReason,
    sets,
  }
}

const applyApiErrors = (error) => {
  const errors = error?.response?.data?.errors

  if (errors && typeof errors === 'object') {
    fieldErrors.value = {
      reason: errors.reason?.[0] ?? null,
      sets: errors.sets?.[0] ?? null,
      game: errors.game?.[0] ?? null,
      competition: errors.competition?.[0] ?? null,
    }

    Object.entries(errors).forEach(([key, messages]) => {
      if (key.startsWith('sets.') && Array.isArray(messages) && messages[0]) {
        const index = key.split('.')[1]
        fieldErrors.value[`sets.${index}`] = messages[0]
      }
    })
  }

  generalError.value = extractApiErrorMessage(error, FORBIDDEN_MESSAGE)
}

const handleClose = () => {
  if (isSaving.value) {
    return
  }

  emit('close')
}

const handleSubmit = async () => {
  if (!activeGame.value?.id || isSaving.value) {
    return
  }

  const payload = collectPayload()

  if (!payload) {
    return
  }

  isSaving.value = true
  generalError.value = ''

  try {
    await GameService.correctResult(activeGame.value.id, payload)
    emit('saved')
  } catch (error) {
    applyApiErrors(error)
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="show && activeGame"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"
      @click.self="handleClose"
    >
      <div
        class="mx-auto flex max-h-[90vh] w-full max-w-xl flex-col overflow-hidden rounded-md border border-slate-200 bg-white text-sm shadow-xl dark:border-slate-700 dark:bg-slate-900"
        role="dialog"
        aria-modal="true"
        aria-labelledby="game-correction-modal-title"
      >
        <div class="overflow-y-auto overflow-x-hidden p-4">
          <form class="space-y-4" @submit.prevent="handleSubmit">
            <div class="min-w-0">
              <h2 id="game-correction-modal-title" class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                Corregir resultado
              </h2>
              <p class="mt-1 break-words font-medium text-slate-900 dark:text-slate-100">
                {{ playerName(activeGame.player1) }} vs {{ playerName(activeGame.player2) }}
              </p>
              <p v-if="matchFormatLabel" class="text-slate-600 dark:text-slate-300">
                {{ matchFormatLabel }}
              </p>
            </div>

            <div class="rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
              <p class="font-medium">Resultado actual</p>
              <p class="mt-1">{{ currentSetsSummary }}</p>
              <p v-if="contextWarning" class="mt-2 text-xs">{{ contextWarning }}</p>
            </div>

            <div class="space-y-2">
              <div class="flex items-center justify-between">
                <p class="font-medium text-slate-700 dark:text-slate-200">Nuevo resultado (sets)</p>
                <button
                  type="button"
                  class="text-xs font-medium text-emerald-700 hover:text-emerald-600 disabled:opacity-50 dark:text-emerald-300"
                  :disabled="isSaving || setRows.length >= (activeGame.best_of || 7)"
                  @click="addSetRow"
                >
                  + Agregar set
                </button>
              </div>

              <div
                v-for="(row, index) in setRows"
                :key="index"
                class="grid min-w-0 grid-cols-[auto_1fr_1fr_auto] items-center gap-2"
              >
                <span class="shrink-0 font-medium text-slate-700 dark:text-slate-200">
                  Set {{ index + 1 }}
                </span>
                <input
                  v-model="row.player1Score"
                  type="number"
                  min="0"
                  :disabled="isSaving"
                  :placeholder="playerName(activeGame.player1)"
                  class="min-w-0 w-full rounded-md border border-slate-300 px-2 py-1.5 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100 disabled:opacity-60"
                />
                <input
                  v-model="row.player2Score"
                  type="number"
                  min="0"
                  :disabled="isSaving"
                  :placeholder="playerName(activeGame.player2)"
                  class="min-w-0 w-full rounded-md border border-slate-300 px-2 py-1.5 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100 disabled:opacity-60"
                />
                <button
                  type="button"
                  class="text-xs text-red-600 disabled:opacity-40 dark:text-red-400"
                  :disabled="isSaving || setRows.length <= 1"
                  @click="removeSetRow(index)"
                >
                  Quitar
                </button>
                <p
                  v-if="fieldErrors[`sets.${index}`]"
                  class="col-span-4 text-xs text-red-600 dark:text-red-400"
                >
                  {{ fieldErrors[`sets.${index}`] }}
                </p>
              </div>

              <p v-if="fieldErrors.sets" class="text-xs text-red-600 dark:text-red-400">{{ fieldErrors.sets }}</p>
            </div>

            <div>
              <label for="correction-reason" class="mb-1 block font-medium text-slate-700 dark:text-slate-200">
                Motivo de la corrección
              </label>
              <textarea
                id="correction-reason"
                v-model="reason"
                rows="3"
                maxlength="500"
                :disabled="isSaving"
                class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 disabled:opacity-60"
                placeholder="Describí por qué se corrige el resultado."
              />
              <p v-if="fieldErrors.reason" class="mt-1 text-xs text-red-600 dark:text-red-400">
                {{ fieldErrors.reason }}
              </p>
            </div>

            <label class="flex items-start gap-2 text-slate-700 dark:text-slate-200">
              <input
                v-model="confirmed"
                type="checkbox"
                class="mt-1"
                :disabled="isSaving"
              />
              <span>Entiendo que se reemplazará el resultado completo.</span>
            </label>
            <p v-if="fieldErrors.confirmation" class="text-xs text-red-600 dark:text-red-400">
              {{ fieldErrors.confirmation }}
            </p>

            <p v-if="fieldErrors.game" class="text-red-600 dark:text-red-400">{{ fieldErrors.game }}</p>
            <p v-if="fieldErrors.competition" class="text-red-600 dark:text-red-400">{{ fieldErrors.competition }}</p>
            <p v-if="generalError" class="text-red-600 dark:text-red-400">{{ generalError }}</p>

            <div class="flex justify-end gap-2">
              <button
                type="button"
                class="rounded-md border border-slate-300 px-3 py-2 font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                :disabled="isSaving"
                @click="handleClose"
              >
                Cancelar
              </button>
              <button
                type="submit"
                class="rounded-md bg-amber-700 px-3 py-2 font-medium text-white hover:bg-amber-600 disabled:cursor-not-allowed disabled:opacity-70"
                :disabled="isSaving"
              >
                {{ isSaving ? 'Guardando...' : 'Confirmar corrección' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </Teleport>
</template>
