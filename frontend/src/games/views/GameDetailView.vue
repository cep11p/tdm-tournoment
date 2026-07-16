<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import GameService from '../services/GameService'
import { getGameStatusLabel } from '../../shared/constants/gameStatus'

const route = useRoute()
const gameId = computed(() => route.params.id)

const game = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')

const isSavingSet = ref(false)
const setError = ref('')
const setSuccessMessage = ref('')
const form = reactive({
  player1_score: '',
  player2_score: '',
})

const playerName = (player) => {
  if (!player?.id) {
    return 'Jugador no asignado'
  }

  return `${player.first_name} ${player.last_name}`.trim()
}

const player1Name = computed(() => playerName(game.value?.player1))
const player2Name = computed(() => playerName(game.value?.player2))

const isFinished = computed(() => game.value?.status === 'finished')

const winnerName = computed(() => {
  if (!game.value?.winner_id) {
    return '-'
  }

  if (game.value.winner_id === game.value.player1?.id) {
    return playerName(game.value.player1)
  }

  if (game.value.winner_id === game.value.player2?.id) {
    return playerName(game.value.player2)
  }

  return `Jugador #${game.value.winner_id}`
})

const orderedSets = computed(() =>
  [...(game.value?.sets || [])].sort((a, b) => a.set_number - b.set_number),
)

const nextSetNumber = computed(() => orderedSets.value.length + 1)

const setsSummary = computed(() => {
  let player1Sets = 0
  let player2Sets = 0

  for (const currentSet of orderedSets.value) {
    if (currentSet.player1_score > currentSet.player2_score) {
      player1Sets++
    } else if (currentSet.player2_score > currentSet.player1_score) {
      player2Sets++
    }
  }

  return {
    player1Sets,
    player2Sets,
  }
})

const matchFormatLabel = computed(() => {
  if (game.value?.is_bye) {
    return 'Pase directo (BYE)'
  }

  if (game.value?.best_of && game.value?.sets_to_win) {
    return `Mejor de ${game.value.best_of} · gana con ${game.value.sets_to_win} sets`
  }

  return null
})

const loadGame = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    game.value = await GameService.show(gameId.value)
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || 'No se pudo cargar el partido.'
  } finally {
    isLoading.value = false
  }
}

const handleRecordSet = async () => {
  if (form.player1_score === '' || form.player2_score === '') {
    setError.value = 'Completá los puntajes de ambos jugadores.'
    return
  }

  isSavingSet.value = true
  setError.value = ''
  setSuccessMessage.value = ''

  try {
    await GameService.recordSet(gameId.value, {
      set_number: nextSetNumber.value,
      player1_score: Number(form.player1_score),
      player2_score: Number(form.player2_score),
    })

    setSuccessMessage.value = 'Set registrado correctamente.'
    form.player1_score = ''
    form.player2_score = ''
    await loadGame()
  } catch (error) {
    setError.value =
      error?.response?.data?.errors?.set_number?.[0] ||
      error?.response?.data?.errors?.player1_score?.[0] ||
      error?.response?.data?.errors?.player2_score?.[0] ||
      error?.response?.data?.errors?.game?.[0] ||
      error?.response?.data?.message ||
      'No se pudo registrar el set.'
  } finally {
    isSavingSet.value = false
  }
}

onMounted(loadGame)
</script>

<template>
  <section class="space-y-4">
    <AppBreadcrumbs
      :context="{
        tournamentId: route.query.tournamentId,
        tournamentName: route.query.tournamentName,
        competitionId: game?.competition_id,
        competitionName: route.query.competitionName,
        gameId: game?.id || gameId,
        gameName: game ? `${playerName(game.player1)} vs ${playerName(game.player2)}` : undefined,
      }"
    />

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
        {{ game ? `${playerName(game.player1)} vs ${playerName(game.player2)}` : `Partido #${gameId}` }}
      </h1>
      <AppBackButton
        :fallback-to="game?.competition_id ? `/competitions/${game.competition_id}/games` : '/'"
        :label="game?.competition_id ? '← Volver a partidos' : '← Volver'"
      />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando partido...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <template v-else-if="game">
      <p v-if="matchFormatLabel" class="text-sm text-slate-600 dark:text-slate-300">
        {{ matchFormatLabel }}
      </p>

      <div
        class="space-y-2 rounded-md border p-4 text-sm"
        :class="
          isFinished
            ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/40'
            : 'border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900'
        "
      >
        <p class="font-medium text-slate-900 dark:text-slate-100">Resultado del partido</p>
        <p class="text-slate-600 dark:text-slate-300">Estado: {{ getGameStatusLabel(game.status) }}</p>
        <p
          v-if="isFinished"
          class="font-semibold text-emerald-800 dark:text-emerald-300"
        >
          🏆 Ganador: {{ winnerName }}
        </p>
        <p v-else class="text-slate-600 dark:text-slate-300">Ganador: {{ winnerName }}</p>
      </div>

      <div class="space-y-2 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900">
        <p class="font-medium text-slate-700 dark:text-slate-200">Resultado actual</p>
        <p class="text-slate-700 dark:text-slate-200">{{ player1Name }}: {{ setsSummary.player1Sets }} sets</p>
        <p class="text-slate-700 dark:text-slate-200">{{ player2Name }}: {{ setsSummary.player2Sets }} sets</p>
      </div>

      <div class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900">
        <p class="font-medium text-slate-700 dark:text-slate-200">Sets registrados</p>

        <div
          v-if="orderedSets.length === 0"
          class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-300"
        >
          Este partido todavía no tiene sets.
        </div>

        <div v-else class="space-y-2">
          <article
            v-for="gameSet in orderedSets"
            :key="gameSet.id"
            class="rounded border border-slate-200 p-3 dark:border-slate-700"
          >
            <p class="font-medium text-slate-900 dark:text-slate-100">Set {{ gameSet.set_number }}</p>
            <div class="space-y-1 text-slate-700 dark:text-slate-300">
              <div
                class="flex items-center justify-between gap-3 border-b border-dotted border-slate-200 pb-1 dark:border-slate-700"
              >
                <span>{{ player1Name }}</span>
                <span class="font-semibold text-slate-900 dark:text-slate-100">{{ gameSet.player1_score }}</span>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span>{{ player2Name }}</span>
                <span class="font-semibold text-slate-900 dark:text-slate-100">{{ gameSet.player2_score }}</span>
              </div>
            </div>
          </article>
        </div>
      </div>

      <form
        v-if="!isFinished"
        class="max-w-xl space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
        @submit.prevent="handleRecordSet"
      >
        <p class="font-medium text-slate-700 dark:text-slate-200">Registrar set</p>

        <div class="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-slate-700 dark:bg-slate-800/60">
          <p class="mb-1 font-medium text-slate-700 dark:text-slate-200">Número de set</p>
          <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">
            {{ nextSetNumber }} <span class="text-sm font-normal text-slate-500 dark:text-slate-400">(automático)</span>
          </p>
        </div>

        <div>
          <label for="player1-score" class="mb-1 block font-medium text-slate-700 dark:text-slate-200">
            Puntos {{ player1Name }}
          </label>
          <input
            id="player1-score"
            v-model="form.player1_score"
            type="number"
            min="0"
            class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
          />
        </div>

        <div>
          <label for="player2-score" class="mb-1 block font-medium text-slate-700 dark:text-slate-200">
            Puntos {{ player2Name }}
          </label>
          <input
            id="player2-score"
            v-model="form.player2_score"
            type="number"
            min="0"
            class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
          />
        </div>

        <p v-if="setError" class="text-red-600 dark:text-red-400">{{ setError }}</p>
        <p v-if="setSuccessMessage" class="text-emerald-700 dark:text-emerald-300">{{ setSuccessMessage }}</p>

        <button
          type="submit"
          class="rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70"
          :disabled="isSavingSet"
        >
          {{ isSavingSet ? 'Guardando...' : 'Guardar set' }}
        </button>
      </form>
    </template>
  </section>
</template>
