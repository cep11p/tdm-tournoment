<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import CompetitionService from '../../competitions/services/CompetitionService'
import BracketService from '../services/BracketService'

const route = useRoute()
const competitionId = computed(() => route.params.id)
const competition = ref(null)

const bracket = ref(null)
const isCreatingBracket = ref(false)
const createError = ref('')
const createSuccessMessage = ref('')

const isGeneratingNextRound = ref(false)
const nextRoundError = ref('')
const nextRoundSuccessMessage = ref('')

const form = reactive({
  name: 'Eliminatoria',
})

const loadCompetition = async () => {
  try {
    competition.value = await CompetitionService.show(competitionId.value)
  } catch {
    competition.value = null
  }
}

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

const groupedRounds = computed(() => {
  if (!bracket.value?.games?.length) {
    return []
  }

  const orderedGames = [...bracket.value.games].sort((left, right) => {
    if ((left.bracket_round || 0) !== (right.bracket_round || 0)) {
      return (left.bracket_round || 0) - (right.bracket_round || 0)
    }

    return (left.bracket_match || 0) - (right.bracket_match || 0)
  })

  const roundsMap = new Map()

  for (const game of orderedGames) {
    const roundNumber = game.bracket_round || 0
    const roundLabel = game.round || `Ronda ${roundNumber || '-'}`
    const key = `${roundNumber}-${roundLabel}`

    if (!roundsMap.has(key)) {
      roundsMap.set(key, {
        roundNumber,
        roundLabel,
        games: [],
      })
    }

    roundsMap.get(key).games.push(game)
  }

  return [...roundsMap.values()].sort((left, right) => left.roundNumber - right.roundNumber)
})

const handleCreateBracket = async () => {
  isCreatingBracket.value = true
  createError.value = ''
  createSuccessMessage.value = ''
  nextRoundError.value = ''
  nextRoundSuccessMessage.value = ''

  try {
    bracket.value = await BracketService.create(competitionId.value, {
      name: form.name.trim() || 'Eliminatoria',
    })

    createSuccessMessage.value = 'Bracket generado correctamente.'
  } catch (error) {
    createError.value =
      error?.response?.data?.errors?.qualified_per_group?.[0] ||
      error?.response?.data?.errors?.competition?.[0] ||
      error?.response?.data?.errors?.group?.[0] ||
      error?.response?.data?.message ||
      'No se pudo generar el bracket.'
  } finally {
    isCreatingBracket.value = false
  }
}

const handleGenerateNextRound = async () => {
  if (!bracket.value?.id) {
    nextRoundError.value = 'Primero generá el bracket.'
    return
  }

  isGeneratingNextRound.value = true
  nextRoundError.value = ''
  nextRoundSuccessMessage.value = ''
  createError.value = ''

  try {
    bracket.value = await BracketService.generateNextRound(bracket.value.id)
    nextRoundSuccessMessage.value = 'Siguiente ronda generada correctamente.'
  } catch (error) {
    nextRoundError.value =
      error?.response?.data?.errors?.bracket?.[0] ||
      error?.response?.data?.message ||
      'No se pudo generar la siguiente ronda.'
  } finally {
    isGeneratingNextRound.value = false
  }
}

onMounted(loadCompetition)
</script>

<template>
  <section class="space-y-4">
    <AppBreadcrumbs
      :context="{
        tournamentId: competition?.tournament_id,
        competitionId,
        competitionName: competition?.name,
      }"
    />

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">Bracket - {{ competition?.name || `Competencia #${competitionId}` }}</h1>
      <AppBackButton :fallback-to="`/competitions/${competitionId}`" />
    </div>

    <form
      class="max-w-xl space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm"
      @submit.prevent="handleCreateBracket"
    >
      <p class="font-medium text-slate-700">Generar bracket</p>

      <p class="text-slate-600">
        Clasificados por grupo (configuración de la competencia):
        <span class="font-medium text-slate-900">{{ competition?.qualified_per_group ?? 2 }}</span>
      </p>

      <div>
        <label for="bracket-name" class="mb-1 block font-medium text-slate-700">Nombre</label>
        <input
          id="bracket-name"
          v-model="form.name"
          type="text"
          class="w-full rounded-md border border-slate-300 px-3 py-2"
        />
      </div>

      <p v-if="createError" class="text-red-600">{{ createError }}</p>
      <p v-if="createSuccessMessage" class="text-emerald-700">{{ createSuccessMessage }}</p>

      <button
        type="submit"
        class="rounded-md bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70"
        :disabled="isCreatingBracket"
      >
        {{ isCreatingBracket ? 'Generando...' : 'Generar bracket' }}
      </button>
    </form>

    <div class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm">
      <div class="flex items-center justify-between">
        <p class="font-medium text-slate-700">Rondas eliminatorias</p>

        <button
          type="button"
          class="rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70"
          :disabled="isGeneratingNextRound || !bracket?.id"
          @click="handleGenerateNextRound"
        >
          {{ isGeneratingNextRound ? 'Generando...' : 'Generar siguiente ronda' }}
        </button>
      </div>

      <p v-if="nextRoundError" class="text-red-600">{{ nextRoundError }}</p>
      <p v-if="nextRoundSuccessMessage" class="text-emerald-700">{{ nextRoundSuccessMessage }}</p>

      <div
        v-if="!bracket"
        class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600"
      >
        Todavía no hay bracket cargado en esta vista. Generalo para visualizar las rondas.
      </div>

      <div
        v-else-if="groupedRounds.length === 0"
        class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600"
      >
        El bracket no tiene partidos para mostrar.
      </div>

      <div v-else class="space-y-3">
        <section
          v-for="round in groupedRounds"
          :key="`${round.roundNumber}-${round.roundLabel}`"
          class="space-y-2 rounded border border-slate-200 p-3"
        >
          <h2 class="font-semibold text-slate-900">
            {{ round.roundLabel }} (Ronda {{ round.roundNumber }})
          </h2>

          <article
            v-for="game in round.games"
            :key="game.id"
            class="space-y-1 rounded border border-slate-200 p-3"
          >
            <p class="font-medium text-slate-900">
              Partido {{ game.bracket_match || '-' }}: {{ playerName(game.player1) }} vs
              {{ playerName(game.player2) }}
            </p>
            <p class="text-slate-600">Estado: {{ game.status }}</p>
            <p class="text-slate-600">Ganador: {{ winnerName(game) }}</p>
          </article>
        </section>
      </div>
    </div>
  </section>
</template>
