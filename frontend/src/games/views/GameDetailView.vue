<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import GameService from '../services/GameService'

const route = useRoute()
const gameId = computed(() => route.params.id)

const game = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')

const isSavingSet = ref(false)
const setError = ref('')
const setSuccessMessage = ref('')
const form = reactive({
  set_number: '',
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
  if (!form.set_number || form.player1_score === '' || form.player2_score === '') {
    setError.value = 'Todos los campos del set son obligatorios.'
    return
  }

  isSavingSet.value = true
  setError.value = ''
  setSuccessMessage.value = ''

  try {
    await GameService.recordSet(gameId.value, {
      set_number: Number(form.set_number),
      player1_score: Number(form.player1_score),
      player2_score: Number(form.player2_score),
    })

    setSuccessMessage.value = 'Set registrado correctamente.'
    form.set_number = ''
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
      <h1 class="text-2xl font-bold">
        {{ game ? `${playerName(game.player1)} vs ${playerName(game.player2)}` : `Partido #${gameId}` }}
      </h1>
      <AppBackButton
        :fallback-to="game?.competition_id ? `/competitions/${game.competition_id}/games` : '/'"
        :label="game?.competition_id ? '← Volver a partidos' : '← Volver'"
      />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600">Cargando partido...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600">{{ errorMessage }}</p>

    <template v-else-if="game">
      <div class="space-y-2 rounded-md border border-slate-200 bg-white p-4 text-sm">
        <p class="font-medium text-slate-900">{{ playerName(game.player1) }} vs {{ playerName(game.player2) }}</p>
        <p class="text-slate-600">Estado: {{ game.status }}</p>
        <p class="text-slate-600">Ganador: {{ winnerName }}</p>
      </div>

      <div class="space-y-2 rounded-md border border-slate-200 bg-white p-4 text-sm">
        <p class="font-medium text-slate-700">Resultado actual</p>
        <p class="text-slate-700">{{ player1Name }}: {{ setsSummary.player1Sets }} sets</p>
        <p class="text-slate-700">{{ player2Name }}: {{ setsSummary.player2Sets }} sets</p>
      </div>

      <div
        v-if="isFinished"
        class="space-y-1 rounded-md border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800"
      >
        <p class="font-semibold">✅ Partido finalizado</p>
        <p>Ganador: {{ winnerName }}</p>
      </div>

      <div class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm">
        <p class="font-medium text-slate-700">Sets registrados</p>

        <div
          v-if="orderedSets.length === 0"
          class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600"
        >
          Este partido todavía no tiene sets.
        </div>

        <div v-else class="space-y-2">
          <article
            v-for="gameSet in orderedSets"
            :key="gameSet.id"
            class="rounded border border-slate-200 p-3"
          >
            <p class="font-medium text-slate-900">Set {{ gameSet.set_number }}</p>
            <div class="space-y-1 text-slate-700">
              <div class="flex items-center justify-between gap-3 border-b border-dotted border-slate-200 pb-1">
                <span>{{ player1Name }}</span>
                <span class="font-semibold text-slate-900">{{ gameSet.player1_score }}</span>
              </div>
              <div class="flex items-center justify-between gap-3">
                <span>{{ player2Name }}</span>
                <span class="font-semibold text-slate-900">{{ gameSet.player2_score }}</span>
              </div>
            </div>
          </article>
        </div>
      </div>

      <form
        v-if="!isFinished"
        class="max-w-xl space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm"
        @submit.prevent="handleRecordSet"
      >
        <p class="font-medium text-slate-700">Registrar set</p>

        <div>
          <label for="set-number" class="mb-1 block font-medium text-slate-700">Número de set</label>
          <input
            id="set-number"
            v-model="form.set_number"
            type="number"
            min="1"
            class="w-full rounded-md border border-slate-300 px-3 py-2"
          />
        </div>

        <div>
          <label for="player1-score" class="mb-1 block font-medium text-slate-700">
            Puntos {{ player1Name }}
          </label>
          <input
            id="player1-score"
            v-model="form.player1_score"
            type="number"
            min="0"
            class="w-full rounded-md border border-slate-300 px-3 py-2"
          />
        </div>

        <div>
          <label for="player2-score" class="mb-1 block font-medium text-slate-700">
            Puntos {{ player2Name }}
          </label>
          <input
            id="player2-score"
            v-model="form.player2_score"
            type="number"
            min="0"
            class="w-full rounded-md border border-slate-300 px-3 py-2"
          />
        </div>

        <p v-if="setError" class="text-red-600">{{ setError }}</p>
        <p v-if="setSuccessMessage" class="text-emerald-700">{{ setSuccessMessage }}</p>

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
