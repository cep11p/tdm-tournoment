<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import CompetitionService from '../../competitions/services/CompetitionService'
import BracketService from '../services/BracketService'

const route = useRoute()
const competitionId = computed(() => route.params.id)
const competition = ref(null)

const bracket = ref(null)
const isLoading = ref(false)
const loadError = ref('')

const isCreatingBracket = ref(false)
const createError = ref('')
const createSuccessMessage = ref('')

const isGeneratingNextRound = ref(false)
const nextRoundError = ref('')
const nextRoundSuccessMessage = ref('')

const form = reactive({
  name: 'Eliminatoria',
})

const hasBracket = computed(() => Boolean(bracket.value?.id))

const loadData = async () => {
  isLoading.value = true
  loadError.value = ''

  try {
    const [competitionData, bracketData] = await Promise.all([
      CompetitionService.show(competitionId.value),
      BracketService.show(competitionId.value),
    ])

    competition.value = competitionData
    bracket.value = bracketData

    if (bracketData?.name) {
      form.name = bracketData.name
    }
  } catch (error) {
    loadError.value = error?.response?.data?.message || 'No se pudo cargar la llave eliminatoria.'
  } finally {
    isLoading.value = false
  }
}

const playerName = (player) => {
  if (!player?.id) {
    return 'BYE'
  }

  return `${player.first_name} ${player.last_name}`.trim()
}

const isByeGame = (game) => game?.is_bye === true || !game?.player2?.id

const opponentLabel = (game, player) => {
  if (isByeGame(game)) {
    return 'BYE'
  }

  return playerName(player)
}

const statusLabel = (game) => {
  if (isByeGame(game)) {
    return 'Avance automático'
  }

  if (game?.status === 'finished') {
    return 'Finalizado'
  }

  if (game?.status === 'in_progress') {
    return 'En curso'
  }

  if (game?.status === 'pending') {
    return 'Pendiente'
  }

  return game?.status || 'Sin estado'
}

const winnerName = (game) => {
  if (isByeGame(game)) {
    return playerName(game.player1)
  }

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

const statusBadgeClasses = (game) => {
  if (isByeGame(game) || game?.status === 'finished') {
    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
  }

  if (game?.status === 'in_progress') {
    return 'bg-sky-100 text-sky-800 dark:bg-sky-900/60 dark:text-sky-200'
  }

  return 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200'
}

const setsResult = (game) => {
  if (isByeGame(game)) {
    return null
  }

  const player1Sets = game?.sets_won?.player1
  const player2Sets = game?.sets_won?.player2

  if (typeof player1Sets === 'number' && typeof player2Sets === 'number') {
    return `${player1Sets} - ${player2Sets}`
  }

  if (!Array.isArray(game?.sets) || game.sets.length === 0) {
    return null
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

const setScoresDetail = (game) => {
  if (isByeGame(game)) {
    return []
  }

  if (!Array.isArray(game?.sets) || game.sets.length === 0) {
    return []
  }

  return [...game.sets]
    .sort((left, right) => left.set_number - right.set_number)
    .map((currentSet) => `${currentSet.player1_score}-${currentSet.player2_score}`)
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

    createSuccessMessage.value = 'Llave eliminatoria generada correctamente.'
  } catch (error) {
    createError.value =
      error?.response?.data?.errors?.qualified_per_group?.[0] ||
      error?.response?.data?.errors?.competition?.[0] ||
      error?.response?.data?.errors?.group?.[0] ||
      error?.response?.data?.message ||
      'No se pudo generar la llave eliminatoria.'
  } finally {
    isCreatingBracket.value = false
  }
}

const handleGenerateNextRound = async () => {
  if (!bracket.value?.id) {
    nextRoundError.value = 'Primero generá la llave eliminatoria.'
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

onMounted(loadData)
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
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
        Llave eliminatoria - {{ competition?.name || `Competencia #${competitionId}` }}
      </h1>
      <AppBackButton :fallback-to="`/competitions/${competitionId}`" />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando llave eliminatoria...</p>
    <p v-else-if="loadError" class="text-sm text-red-600 dark:text-red-400">{{ loadError }}</p>

    <template v-else>
      <form
        v-if="!hasBracket"
        class="max-w-xl space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
        @submit.prevent="handleCreateBracket"
      >
        <p class="font-medium text-slate-700 dark:text-slate-200">Generar llave eliminatoria</p>

        <p class="text-slate-600 dark:text-slate-300">
          Todavía no se generó la llave eliminatoria para esta competencia.
        </p>

        <p class="text-slate-600 dark:text-slate-300">
          Clasificados por grupo (configuración de la competencia):
          <span class="font-medium text-slate-900 dark:text-slate-100">{{ competition?.qualified_per_group ?? 2 }}</span>
        </p>

        <div>
          <label for="bracket-name" class="mb-1 block font-medium text-slate-700 dark:text-slate-200">Nombre</label>
          <input
            id="bracket-name"
            v-model="form.name"
            type="text"
            class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
          />
        </div>

        <p v-if="createError" class="text-red-600 dark:text-red-400">{{ createError }}</p>
        <p v-if="createSuccessMessage" class="text-emerald-700 dark:text-emerald-300">{{ createSuccessMessage }}</p>

        <button
          type="submit"
          class="rounded-md bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          :disabled="isCreatingBracket"
        >
          {{ isCreatingBracket ? 'Generando...' : 'Generar bracket' }}
        </button>
      </form>

      <div
        v-else
        class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <p class="font-medium text-slate-700 dark:text-slate-200">Resumen de la llave</p>

        <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Nombre</dt>
            <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracket.name }}</dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Clasifican por grupo</dt>
            <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracket.qualifiers_per_group }}</dd>
          </div>

          <div v-if="bracket.bracket_size">
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Tamaño</dt>
            <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracket.bracket_size }}</dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">BYEs</dt>
            <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracket.byes_count ?? 0 }}</dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Partidos</dt>
            <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracket.games?.length ?? 0 }}</dd>
          </div>
        </dl>
      </div>

      <div
        class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="font-medium text-slate-700 dark:text-slate-200">Rondas eliminatorias</p>

          <button
            v-if="hasBracket"
            type="button"
            class="rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70"
            :disabled="isGeneratingNextRound"
            @click="handleGenerateNextRound"
          >
            {{ isGeneratingNextRound ? 'Generando...' : 'Generar siguiente ronda' }}
          </button>
        </div>

        <p v-if="nextRoundError" class="text-red-600 dark:text-red-400">{{ nextRoundError }}</p>
        <p v-if="nextRoundSuccessMessage" class="text-emerald-700 dark:text-emerald-300">
          {{ nextRoundSuccessMessage }}
        </p>

        <div
          v-if="!hasBracket"
          class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600 dark:border-slate-700 dark:bg-slate-950/40 dark:text-slate-300"
        >
          Generá la llave eliminatoria para visualizar las rondas y partidos.
        </div>

        <div
          v-else-if="groupedRounds.length === 0"
          class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600 dark:border-slate-700 dark:bg-slate-950/40 dark:text-slate-300"
        >
          La llave no tiene partidos para mostrar.
        </div>

        <div v-else class="space-y-4">
          <section
            v-for="round in groupedRounds"
            :key="`${round.roundNumber}-${round.roundLabel}`"
            class="space-y-2 rounded-md border border-slate-200 p-3 dark:border-slate-700"
          >
            <h2 class="font-semibold text-slate-900 dark:text-slate-100">
              {{ round.roundLabel }}
            </h2>

            <ul class="space-y-2">
              <li
                v-for="game in round.games"
                :key="game.id"
                class="space-y-1 rounded-md border border-slate-200 p-3 dark:border-slate-700 dark:bg-slate-950/30"
              >
                <p class="font-medium text-slate-900 dark:text-slate-100">
                  {{ playerName(game.player1) }} vs {{ opponentLabel(game, game.player2) }}
                </p>

                <div class="flex flex-wrap items-center gap-2">
                  <span
                    v-if="isByeGame(game)"
                    class="inline-flex rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-800 dark:bg-violet-900/60 dark:text-violet-200"
                  >
                    BYE
                  </span>

                  <span
                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="statusBadgeClasses(game)"
                  >
                    {{ statusLabel(game) }}
                  </span>

                  <span v-if="setsResult(game)" class="text-slate-600 dark:text-slate-300">
                    Sets: {{ setsResult(game) }}
                  </span>
                </div>

                <p v-if="setScoresDetail(game).length > 0" class="text-slate-600 dark:text-slate-300">
                  Detalle: {{ setScoresDetail(game).join(', ') }}
                </p>

                <p class="text-slate-600 dark:text-slate-300">Ganador: {{ winnerName(game) }}</p>
              </li>
            </ul>
          </section>
        </div>
      </div>

      <RouterLink
        :to="`/competitions/${competitionId}`"
        class="inline-flex text-sm font-medium text-slate-700 hover:text-slate-900 dark:text-slate-300 dark:hover:text-slate-100"
      >
        ← Volver al detalle de la competencia
      </RouterLink>
    </template>
  </section>
</template>
