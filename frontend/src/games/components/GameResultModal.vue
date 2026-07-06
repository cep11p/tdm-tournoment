<script setup>
import { ref, watch } from 'vue'

import GameService from '../services/GameService'

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
const isSavingResult = ref(false)
const resultError = ref('')

const playerName = (player) => {
  if (!player?.id) {
    return 'Jugador no asignado'
  }

  return `${player.first_name} ${player.last_name}`.trim()
}

const matchFormatLabel = (game) => {
  if (game?.is_bye) {
    return null
  }

  if (game?.best_of && game?.sets_to_win) {
    return `Mejor de ${game.best_of} · gana con ${game.sets_to_win} sets`
  }

  if (game?.best_of) {
    return `Mejor de ${game.best_of}`
  }

  return null
}

const extractSetError = (error) =>
  error?.response?.data?.errors?.set_number?.[0] ||
  error?.response?.data?.errors?.player1_score?.[0] ||
  error?.response?.data?.errors?.player2_score?.[0] ||
  error?.response?.data?.errors?.game?.[0] ||
  error?.response?.data?.message ||
  'No se pudo registrar el resultado.'

const buildSetRows = (game) => {
  const maxSets = game?.best_of || 3
  const existingSets = [...(game?.sets || [])].sort(
    (left, right) => left.set_number - right.set_number,
  )

  return Array.from({ length: maxSets }, (_, index) => {
    const setNumber = index + 1
    const existing = existingSets.find((currentSet) => currentSet.set_number === setNumber)

    return {
      setNumber,
      player1Score: existing ? String(existing.player1_score) : '',
      player2Score: existing ? String(existing.player2_score) : '',
      locked: Boolean(existing),
    }
  })
}

const collectSetsToSubmit = () => {
  const newRows = setRows.value.filter((row) => !row.locked)
  const setsToSubmit = []

  for (const row of newRows) {
    const hasPlayer1 = row.player1Score !== ''
    const hasPlayer2 = row.player2Score !== ''

    if (!hasPlayer1 && !hasPlayer2) {
      break
    }

    if (!hasPlayer1 || !hasPlayer2) {
      return {
        error: `Completá ambos puntajes del set ${row.setNumber}.`,
        sets: [],
      }
    }

    const player1Score = Number(row.player1Score)
    const player2Score = Number(row.player2Score)

    if (!Number.isFinite(player1Score) || !Number.isFinite(player2Score)) {
      return {
        error: `Los puntajes del set ${row.setNumber} deben ser números válidos.`,
        sets: [],
      }
    }

    if (player1Score < 0 || player2Score < 0) {
      return {
        error: `Los puntajes del set ${row.setNumber} no pueden ser negativos.`,
        sets: [],
      }
    }

    setsToSubmit.push({
      set_number: row.setNumber,
      player1_score: player1Score,
      player2_score: player2Score,
    })
  }

  if (setsToSubmit.length === 0) {
    return {
      error: 'Completá al menos un set para guardar el resultado.',
      sets: [],
    }
  }

  return { error: null, sets: setsToSubmit }
}

watch(
  () => [props.show, props.game?.id, props.game?.sets?.length, props.game?.status],
  () => {
    if (!props.show || !props.game) {
      return
    }

    activeGame.value = props.game
    setRows.value = buildSetRows(props.game)
    resultError.value = ''
  },
  { immediate: true },
)

const handleClose = () => {
  if (isSavingResult.value) {
    return
  }

  emit('close')
}

const isGameFinishedAfterSaveError = (error, game) => {
  if (game?.status !== 'finished') {
    return false
  }

  const gameError = error?.response?.data?.errors?.game?.[0]

  return (
    gameError === 'El partido ya finalizó.' ||
    gameError === 'El partido ya tiene un ganador definido.'
  )
}

const handleSave = async () => {
  if (!activeGame.value?.id || isSavingResult.value) {
    return
  }

  const { error, sets } = collectSetsToSubmit()

  if (error) {
    resultError.value = error
    return
  }

  isSavingResult.value = true
  resultError.value = ''

  try {
    for (const set of sets) {
      const updatedGame = await GameService.recordSet(activeGame.value.id, set)
      activeGame.value = updatedGame
      setRows.value = buildSetRows(updatedGame)

      if (updatedGame?.status === 'finished') {
        break
      }
    }

    emit('saved')
  } catch (error) {
    let refreshedGame = null

    try {
      refreshedGame = await GameService.show(activeGame.value.id)
      activeGame.value = refreshedGame
      setRows.value = buildSetRows(refreshedGame)
    } catch {
      // Mantener filas actuales si no se pudo refrescar el partido.
    }

    if (isGameFinishedAfterSaveError(error, refreshedGame)) {
      emit('saved')
      return
    }

    resultError.value = extractSetError(error)
  } finally {
    isSavingResult.value = false
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
        aria-labelledby="game-result-modal-title"
      >
        <div class="overflow-y-auto overflow-x-hidden p-4">
          <form class="space-y-4" @submit.prevent="handleSave">
            <div class="min-w-0">
              <h2 id="game-result-modal-title" class="text-lg font-semibold text-slate-900 dark:text-slate-100">
                Cargar resultado
              </h2>
              <p class="mt-1 break-words font-medium text-slate-900 dark:text-slate-100">
                {{ playerName(activeGame.player1) }} vs {{ playerName(activeGame.player2) }}
              </p>
              <p v-if="matchFormatLabel(activeGame)" class="text-slate-600 dark:text-slate-300">
                {{ matchFormatLabel(activeGame) }}
              </p>
            </div>

            <div class="min-w-0 space-y-2">
              <div
                v-for="row in setRows"
                :key="row.setNumber"
                class="grid min-w-0 grid-cols-[auto_1fr_1fr] items-center gap-2"
              >
                <span class="shrink-0 font-medium text-slate-700 dark:text-slate-200">
                  Set {{ row.setNumber }}
                </span>
                <input
                  v-model="row.player1Score"
                  type="number"
                  min="0"
                  :disabled="row.locked || isSavingResult"
                  :placeholder="playerName(activeGame.player1)"
                  class="min-w-0 w-full rounded-md border border-slate-300 px-2 py-1.5 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                />
                <input
                  v-model="row.player2Score"
                  type="number"
                  min="0"
                  :disabled="row.locked || isSavingResult"
                  :placeholder="playerName(activeGame.player2)"
                  class="min-w-0 w-full rounded-md border border-slate-300 px-2 py-1.5 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                />
              </div>
            </div>

            <p class="text-xs text-slate-500 dark:text-slate-400">
              Completá los sets en orden. No hace falta llenar todos si el partido se define antes.
            </p>

            <p v-if="resultError" class="text-red-600 dark:text-red-400">{{ resultError }}</p>

            <div class="flex justify-end gap-2">
              <button
                type="button"
                class="rounded-md border border-slate-300 px-3 py-2 font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                :disabled="isSavingResult"
                @click="handleClose"
              >
                Cancelar
              </button>
              <button
                type="submit"
                class="rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70"
                :disabled="isSavingResult"
              >
                {{ isSavingResult ? 'Guardando...' : 'Guardar resultado' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </Teleport>
</template>
