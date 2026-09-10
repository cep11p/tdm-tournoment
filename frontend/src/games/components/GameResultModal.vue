<script setup>
import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/vue/24/outline'
import { computed, ref, watch } from 'vue'

import GameService from '../services/GameService'
import { gameMatchupLabel, getGameSideDisplayName } from '../utils/gameDisplay'

const DIRTY_NAVIGATION_MESSAGE =
  'Hay cambios sin guardar. ¿Querés descartarlos y continuar?'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  game: {
    type: Object,
    default: null,
  },
  showGroupNavigation: {
    type: Boolean,
    default: false,
  },
  groups: {
    type: Array,
    default: () => [],
  },
  selectedGroupId: {
    type: [Number, String],
    default: null,
  },
  roundLabel: {
    type: String,
    default: '',
  },
  matchLabel: {
    type: String,
    default: '',
  },
  canGoPrevious: {
    type: Boolean,
    default: false,
  },
  canGoNext: {
    type: Boolean,
    default: false,
  },
  groupCompleteMessage: {
    type: String,
    default: '',
  },
  statusMessage: {
    type: String,
    default: '',
  },
  nextGroup: {
    type: Object,
    default: null,
  },
  competitionCompleteMessage: {
    type: String,
    default: '',
  },
  isBusy: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['close', 'saved', 'previous', 'next', 'change-group', 'dirty-change', 'go-to-group'])

const activeGame = ref(null)
const setRows = ref([])
const isSavingResult = ref(false)
const resultError = ref('')

const playerName = (player) => {
  if (!player?.id) {
    return 'Participante no asignado'
  }

  return `${player.first_name} ${player.last_name}`.trim()
}

const sideDisplayName = (game, sideNumber) => getGameSideDisplayName(game, sideNumber)

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

const isFinishedGame = computed(() => activeGame.value?.status === 'finished')
const isNavigationLocked = computed(() => isSavingResult.value || props.isBusy)

const canSaveResult = computed(
  () =>
    Boolean(activeGame.value?.id) &&
    !isFinishedGame.value &&
    !isNavigationLocked.value,
)

const isDirty = computed(() =>
  setRows.value.some(
    (row) => !row.locked && (row.player1Score !== '' || row.player2Score !== ''),
  ),
)

const confirmIfDirty = () => {
  if (!isDirty.value) {
    return true
  }

  return window.confirm(DIRTY_NAVIGATION_MESSAGE)
}

const coerceGroupId = (value) => {
  const asNumber = Number(value)

  return Number.isFinite(asNumber) && value !== '' ? asNumber : value
}

watch(isDirty, (dirty) => {
  if (dirty) {
    emit('dirty-change')
  }
})

watch(
  () => [props.show, props.game?.id, props.game?.sets?.length, props.game?.status],
  () => {
    if (!props.show) {
      return
    }

    if (!props.game) {
      activeGame.value = null
      setRows.value = []
      resultError.value = ''
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

const handlePrevious = () => {
  if (!props.canGoPrevious || isNavigationLocked.value) {
    return
  }

  if (!confirmIfDirty()) {
    return
  }

  emit('previous')
}

const handleNext = () => {
  if (!props.canGoNext || isNavigationLocked.value) {
    return
  }

  if (!confirmIfDirty()) {
    return
  }

  emit('next')
}

const handleGroupSelect = (event) => {
  if (isNavigationLocked.value) {
    event.target.value = props.selectedGroupId == null ? '' : String(props.selectedGroupId)
    return
  }

  const nextGroupId = coerceGroupId(event.target.value)

  if (String(nextGroupId) === String(props.selectedGroupId)) {
    return
  }

  if (!confirmIfDirty()) {
    event.target.value = props.selectedGroupId == null ? '' : String(props.selectedGroupId)
    return
  }

  emit('change-group', nextGroupId)
}

const handleGoToGroup = () => {
  if (isNavigationLocked.value || !props.nextGroup?.id) {
    return
  }

  if (!confirmIfDirty()) {
    return
  }

  emit('go-to-group', props.nextGroup.id)
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
  if (!canSaveResult.value) {
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
    let updatedGame = activeGame.value

    for (const set of sets) {
      updatedGame = await GameService.recordSet(activeGame.value.id, set)
      activeGame.value = updatedGame
      setRows.value = buildSetRows(updatedGame)

      if (updatedGame?.status === 'finished') {
        break
      }
    }

    emit('saved', { game: updatedGame })
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
      emit('saved', { game: refreshedGame })
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
      v-if="show && (activeGame || showGroupNavigation)"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"
      @click.self="handleClose"
    >
      <div
        class="mx-auto flex max-h-[90vh] w-full max-w-xl min-w-0 flex-col overflow-hidden rounded-md border border-slate-200 bg-white text-sm shadow-xl dark:border-slate-700 dark:bg-slate-900"
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

              <div v-if="showGroupNavigation" class="mt-3 space-y-2">
                <div class="flex justify-center px-1">
                  <label class="w-full max-w-xs">
                    <span class="mb-1 block text-center text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
                      Grupo
                    </span>
                    <select
                      id="game-result-group-select"
                      :value="selectedGroupId ?? ''"
                      class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-center font-medium text-slate-800 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
                      :disabled="isNavigationLocked || groups.length === 0"
                      @change="handleGroupSelect"
                    >
                      <option
                        v-for="group in groups"
                        :key="group.id"
                        :value="group.id"
                      >
                        {{ group.name }}
                      </option>
                    </select>
                  </label>
                </div>

                <div class="flex items-center gap-1 sm:gap-2">
                  <button
                    type="button"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-800 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100"
                    :disabled="!canGoPrevious || isNavigationLocked"
                    aria-label="Partido anterior"
                    @click="handlePrevious"
                  >
                    <ChevronLeftIcon class="h-5 w-5" aria-hidden="true" />
                  </button>

                  <p class="min-w-0 flex-1 truncate px-1 text-center text-sm font-semibold text-slate-800 dark:text-slate-100">
                    {{ roundLabel }}
                  </p>

                  <button
                    type="button"
                    class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-800 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100"
                    :disabled="!canGoNext || isNavigationLocked"
                    aria-label="Partido siguiente"
                    @click="handleNext"
                  >
                    <ChevronRightIcon class="h-5 w-5" aria-hidden="true" />
                  </button>
                </div>

                <p
                  v-if="matchLabel"
                  class="text-center text-xs text-slate-500 dark:text-slate-400"
                >
                  {{ matchLabel }}
                </p>

                <div
                  v-if="competitionCompleteMessage || groupCompleteMessage"
                  class="space-y-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-center dark:border-emerald-900 dark:bg-emerald-950/30"
                >
                  <p class="text-xs font-medium text-emerald-800 dark:text-emerald-100">
                    {{ competitionCompleteMessage || groupCompleteMessage }}
                  </p>
                  <p
                    v-if="!competitionCompleteMessage && nextGroup?.name"
                    class="text-xs text-emerald-800/80 dark:text-emerald-200/80"
                  >
                    Siguiente grupo disponible: {{ nextGroup.name }}
                  </p>
                  <button
                    v-if="!competitionCompleteMessage && nextGroup?.id"
                    type="button"
                    class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
                    :disabled="isNavigationLocked"
                    @click="handleGoToGroup"
                  >
                    Ir a {{ nextGroup.name }}
                  </button>
                </div>
              </div>

              <template v-if="activeGame">
                <p
                  class="break-words font-medium text-slate-900 dark:text-slate-100"
                  :class="showGroupNavigation ? 'mt-3' : 'mt-1'"
                >
                  {{ gameMatchupLabel(activeGame) }}
                </p>
                <p v-if="matchFormatLabel(activeGame)" class="text-slate-600 dark:text-slate-300">
                  {{ matchFormatLabel(activeGame) }}
                </p>
              </template>
              <p
                v-else
                class="mt-3 text-center text-slate-600 dark:text-slate-300"
              >
                Este grupo no tiene partidos para navegar.
              </p>
            </div>

            <div v-if="activeGame" class="min-w-0 space-y-2">
              <div
                v-for="row in setRows"
                :key="row.setNumber"
                class="grid min-w-0 grid-cols-[auto_minmax(0,1fr)_minmax(0,1fr)] items-center gap-2"
              >
                <span class="shrink-0 font-medium text-slate-700 dark:text-slate-200">
                  Set {{ row.setNumber }}
                </span>
                <input
                  v-model="row.player1Score"
                  type="number"
                  min="0"
                  :disabled="row.locked || isNavigationLocked || isFinishedGame"
                  :placeholder="sideDisplayName(activeGame, 1)"
                  class="min-w-0 w-full rounded-md border border-slate-300 px-2 py-1.5 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                />
                <input
                  v-model="row.player2Score"
                  type="number"
                  min="0"
                  :disabled="row.locked || isNavigationLocked || isFinishedGame"
                  :placeholder="sideDisplayName(activeGame, 2)"
                  class="min-w-0 w-full rounded-md border border-slate-300 px-2 py-1.5 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100 disabled:cursor-not-allowed disabled:opacity-60"
                />
              </div>
            </div>

            <p v-if="isFinishedGame" class="text-xs text-slate-500 dark:text-slate-400">
              Este partido ya tiene resultado. La corrección se hace desde el detalle del partido.
            </p>
            <p v-else-if="activeGame" class="text-xs text-slate-500 dark:text-slate-400">
              Completá los sets en orden. No hace falta llenar todos si el partido se define antes.
            </p>

            <p v-if="statusMessage" class="text-emerald-700 dark:text-emerald-300">{{ statusMessage }}</p>
            <p v-if="resultError" class="text-red-600 dark:text-red-400">{{ resultError }}</p>

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
              <button
                type="button"
                class="rounded-md border border-slate-300 px-3 py-2 font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                :disabled="isSavingResult"
                @click="handleClose"
              >
                Cancelar
              </button>
              <button
                v-if="activeGame"
                type="submit"
                class="rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70"
                :disabled="!canSaveResult"
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
