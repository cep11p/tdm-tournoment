<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import GameService from '../services/GameService'

const route = useRoute()
const competitionId = computed(() => route.params.id)

const games = ref([])
const isLoading = ref(false)
const errorMessage = ref('')

const playerName = (player) => {
  if (!player?.id) {
    return 'Jugador no asignado'
  }

  return `${player.first_name} ${player.last_name}`.trim()
}

const winnerName = (game) => {
  if (!game?.winner_id) {
    return '-'
  }

  if (game.winner_id === game.player1?.id) {
    return playerName(game.player1)
  }

  if (game.winner_id === game.player2?.id) {
    return playerName(game.player2)
  }

  return `Jugador #${game.winner_id}`
}

const statusBadge = (game) => {
  if (game?.status === 'finished') {
    return '✓ Finalizado'
  }

  if (game?.status === 'pending') {
    return '⏳ Pendiente'
  }

  return game?.status || 'Sin estado'
}

const cardClasses = (game) => {
  if (game?.status === 'finished') {
    return 'border-emerald-200 bg-emerald-50 dark:border-emerald-900 dark:bg-emerald-950/40'
  }

  if (game?.status === 'pending') {
    return 'border-amber-200 bg-amber-50 dark:border-amber-900 dark:bg-amber-950/40'
  }

  return 'border-slate-200 bg-slate-50 dark:border-slate-700 dark:bg-slate-900/50'
}

const badgeClasses = (game) => {
  if (game?.status === 'finished') {
    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
  }

  if (game?.status === 'pending') {
    return 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200'
  }

  return 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200'
}

const winnerClasses = (game) =>
  game?.status === 'finished'
    ? 'text-emerald-700 dark:text-emerald-300'
    : 'text-slate-600 dark:text-slate-300'

const setsResult = (game) => {
  const player1Sets = game?.sets_won?.player1
  const player2Sets = game?.sets_won?.player2

  if (typeof player1Sets === 'number' && typeof player2Sets === 'number') {
    return `${player1Sets} - ${player2Sets}`
  }

  if (!Array.isArray(game?.sets) || game.sets.length === 0) {
    return '-'
  }

  let player1Wins = 0
  let player2Wins = 0

  for (const currentSet of game.sets) {
    if (currentSet.player1_score > currentSet.player2_score) {
      player1Wins++
    } else if (currentSet.player2_score > currentSet.player1_score) {
      player2Wins++
    }
  }

  return `${player1Wins} - ${player2Wins}`
}

const loadGames = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    games.value = await GameService.listByCompetition(competitionId.value)
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || 'No se pudo cargar el listado de partidos.'
  } finally {
    isLoading.value = false
  }
}

onMounted(loadGames)
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Partidos de la competencia</h1>
      <RouterLink
        :to="`/competitions/${competitionId}`"
        class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-300"
      >
        Volver a competencia
      </RouterLink>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando partidos...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600">{{ errorMessage }}</p>

    <div
      v-else-if="games.length === 0"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
    >
      Esta competencia todavía no tiene partidos.
    </div>

    <div v-else class="space-y-2 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
      <article
        v-for="game in games"
        :key="game.id"
        class="space-y-3 rounded border p-3 text-sm"
        :class="cardClasses(game)"
      >
        <div class="flex items-center justify-between gap-3">
          <p class="text-base font-semibold text-slate-900 dark:text-slate-100">
            {{ playerName(game.player1) }} vs {{ playerName(game.player2) }}
          </p>
          <span class="rounded-full px-2 py-1 text-xs font-semibold" :class="badgeClasses(game)">
            {{ statusBadge(game) }}
          </span>
        </div>

        <p class="text-slate-600 dark:text-slate-300">Estado: {{ game.status }}</p>
        <p class="font-medium" :class="winnerClasses(game)">Ganador: {{ winnerName(game) }}</p>
        <p class="text-slate-600 dark:text-slate-300">Sets: {{ setsResult(game) }}</p>

        <RouterLink
          :to="`/games/${game.id}`"
          class="inline-flex text-sm font-semibold text-slate-700 hover:underline dark:text-slate-200"
        >
          → Ver detalle
        </RouterLink>
      </article>
    </div>
  </section>
</template>
