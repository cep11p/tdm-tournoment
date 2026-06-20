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
      <h1 class="text-2xl font-bold">Partidos de la competencia</h1>
      <RouterLink
        :to="`/competitions/${competitionId}`"
        class="text-sm font-medium text-slate-700 hover:underline"
      >
        Volver a competencia
      </RouterLink>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600">Cargando partidos...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600">{{ errorMessage }}</p>

    <div
      v-else-if="games.length === 0"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600"
    >
      Esta competencia todavía no tiene partidos.
    </div>

    <div v-else class="space-y-2 rounded-md border border-slate-200 bg-white p-4">
      <article
        v-for="game in games"
        :key="game.id"
        class="space-y-2 rounded border border-slate-200 p-3 text-sm"
      >
        <p class="font-medium text-slate-900">{{ playerName(game.player1) }} vs {{ playerName(game.player2) }}</p>
        <p class="text-slate-600">Estado: {{ game.status }}</p>
        <p class="text-slate-600">Ganador: {{ winnerName(game) }}</p>

        <RouterLink :to="`/games/${game.id}`" class="text-sm font-medium text-slate-700 hover:underline">
          Ver detalle
        </RouterLink>
      </article>
    </div>
  </section>
</template>
