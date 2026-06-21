<script setup>
import {
  RectangleGroupIcon,
  TrophyIcon,
  UserGroupIcon,
  ViewColumnsIcon,
} from '@heroicons/vue/24/outline'
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import BracketService from '../../brackets/services/BracketService'
import GameService from '../../games/services/GameService'
import GroupService from '../../groups/services/GroupService'
import RegistrationService from '../../registrations/services/RegistrationService'
import StandingService from '../../standings/services/StandingService'
import CompetitionService from '../services/CompetitionService'

const route = useRoute()

const competition = ref(null)
const bracket = ref(null)
const registrations = ref(null)
const groups = ref(null)
const games = ref(null)
const groupStandings = ref({})

const isLoading = ref(false)
const errorMessage = ref('')

const competitionId = computed(() => route.params.id)

const qualifiedPerGroup = computed(() => competition.value?.qualified_per_group ?? 2)

const fallbackBackRoute = computed(() =>
  competition.value?.tournament_id ? `/tournaments/${competition.value.tournament_id}/competitions` : '/tournaments',
)

const formatCount = (value) => (value === null || value === undefined ? '-' : value)

const playerCount = computed(() =>
  registrations.value === null ? '-' : registrations.value.length,
)

const groupCount = computed(() => (groups.value === null ? '-' : groups.value.length))

const gameCount = computed(() => (games.value === null ? '-' : games.value.length))

const finishedGameCount = computed(() => {
  if (games.value === null) {
    return '-'
  }

  return games.value.filter((game) => game.status === 'finished').length
})

const groupGames = computed(() => {
  if (!games.value) {
    return []
  }

  return games.value.filter((game) => game.group_id)
})

const bracketGames = computed(() => {
  if (!games.value) {
    return []
  }

  return games.value.filter((game) => game.bracket_id)
})

const hasBracket = computed(() => Boolean(bracket.value?.id))

const bracketGameCount = computed(() => bracket.value?.games?.length ?? bracketGames.value.length)

const bracketStatus = computed(() => {
  const bracketGameList = bracket.value?.games?.length ? bracket.value.games : bracketGames.value

  if (!hasBracket.value || bracketGameList.length === 0) {
    return null
  }

  if (bracketGameList.every((game) => game.status === 'finished')) {
    return 'Completo'
  }

  if (bracketGameList.some((game) => game.status === 'in_progress' || game.status === 'finished')) {
    return 'En curso'
  }

  return 'Pendiente'
})

const bracketRoute = computed(() => `/competitions/${competitionId.value}/bracket`)

const playerDisplayName = (player) => {
  if (!player?.id) {
    return null
  }

  return `${player.first_name} ${player.last_name}`.trim()
}

const winnerPlayer = (game) => {
  if (!game?.winner_id) {
    return null
  }

  const winner = game.winner_id === game.player1?.id ? game.player1 : game.player2

  if (!winner?.id) {
    return null
  }

  return {
    id: winner.id,
    name: playerDisplayName(winner),
  }
}

const loserPlayer = (game) => {
  if (!game?.winner_id) {
    return null
  }

  const loser = game.winner_id === game.player1?.id ? game.player2 : game.player1

  if (!loser?.id) {
    return null
  }

  return {
    id: loser.id,
    name: playerDisplayName(loser),
  }
}

const finalResults = computed(() => {
  if (bracketGames.value.length === 0) {
    return null
  }

  const maxRound = Math.max(...bracketGames.value.map((game) => Number(game.bracket_round) || 0))

  if (maxRound === 0) {
    return null
  }

  const finalGames = bracketGames.value.filter((game) => Number(game.bracket_round) === maxRound)

  if (finalGames.length !== 1) {
    return null
  }

  const finalGame = finalGames[0]

  if (finalGame.status !== 'finished' || !finalGame.winner_id) {
    return null
  }

  const champion = winnerPlayer(finalGame)
  const runnerUp = loserPlayer(finalGame)

  if (!champion?.name || !runnerUp?.name) {
    return null
  }

  const semifinalRound = maxRound - 1
  let semifinalists = []

  if (semifinalRound >= 1) {
    semifinalists = bracketGames.value
      .filter(
        (game) =>
          Number(game.bracket_round) === semifinalRound &&
          game.status === 'finished' &&
          game.winner_id,
      )
      .map((game) => loserPlayer(game))
      .filter(
        (player) =>
          player?.name && player.id !== champion.id && player.id !== runnerUp.id,
      )
  }

  return {
    champion,
    runnerUp,
    semifinalists,
  }
})

const showFinalResults = computed(() => finalResults.value !== null)

const statusSteps = computed(() => {
  const steps = []

  if (registrations.value !== null) {
    steps.push({
      done: registrations.value.length > 0,
      label:
        registrations.value.length > 0 ? 'Inscripciones completas' : 'Sin inscripciones registradas',
    })
  }

  if (groups.value !== null) {
    steps.push({
      done: groups.value.length > 0,
      label: groups.value.length > 0 ? 'Grupos creados' : 'Grupos pendientes',
    })
  }

  if (games.value !== null) {
    if (games.value.length > 0) {
      steps.push({
        done: true,
        label: 'Partidos generados',
      })
    } else {
      steps.push({
        done: false,
        label: 'Partidos pendientes',
      })
    }
  }

  if (games.value !== null && games.value.length > 0) {
    const groupPhaseGames = groupGames.value
    const relevantGames = groupPhaseGames.length > 0 ? groupPhaseGames : games.value
    const allFinished = relevantGames.every((game) => game.status === 'finished')

    if (allFinished) {
      steps.push({
        done: true,
        label: 'Fase de grupos completada',
      })
    } else {
      steps.push({
        done: false,
        label: 'Competencia en curso',
      })
    }
  }

  return steps
})

const qualifiersByGroup = computed(() => {
  if (!groups.value?.length) {
    return []
  }

  return groups.value.map((group) => {
    const standings = groupStandings.value[group.id]

    if (!standings?.length) {
      return {
        group,
        qualifiers: null,
      }
    }

    return {
      group,
      qualifiers: standings.slice(0, qualifiedPerGroup.value).map((standing, index) => ({
        ...standing,
        position: index + 1,
      })),
    }
  })
})

const hasQualifiersData = computed(() =>
  qualifiersByGroup.value.some((entry) => entry.qualifiers?.length > 0),
)

const groupPhaseGamesForQualifiers = computed(() => {
  if (!games.value) {
    return []
  }

  return groupGames.value.length > 0 ? groupGames.value : games.value
})

const isGroupPhaseComplete = computed(() => {
  const phaseGames = groupPhaseGamesForQualifiers.value

  if (phaseGames.length === 0) {
    return false
  }

  return phaseGames.every((game) => game.status === 'finished')
})

const qualifiersSectionTitle = computed(() =>
  isGroupPhaseComplete.value ? 'Clasificados' : 'Posiciones provisionales',
)

const qualifiersSectionMessage = computed(() =>
  isGroupPhaseComplete.value
    ? 'Clasificación definida'
    : 'Los clasificados se definirán cuando finalice la fase de grupos.',
)

const qualifiersStatusBadgeClasses = computed(() =>
  isGroupPhaseComplete.value
    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200'
    : 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200',
)

const qualifiersStatusBadgeLabel = computed(() =>
  isGroupPhaseComplete.value ? 'Definitivo' : 'Provisional',
)

const qualifierItemClasses = computed(() =>
  isGroupPhaseComplete.value
    ? 'border-emerald-200 bg-emerald-50/40 dark:border-emerald-900 dark:bg-emerald-950/20'
    : 'border-amber-200 bg-amber-50/40 dark:border-amber-900 dark:bg-amber-950/20',
)

const positionBadgeClasses = (position) => {
  if (position === 1) {
    return 'bg-amber-100 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-900/50 dark:text-amber-200 dark:ring-amber-800'
  }

  if (position === 2) {
    return 'bg-slate-200 text-slate-800 ring-1 ring-slate-300 dark:bg-slate-600 dark:text-slate-100 dark:ring-slate-500'
  }

  return 'bg-slate-100 text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'
}

const statusStepClasses = (step) =>
  step.done
    ? 'text-emerald-800 dark:text-emerald-300'
    : 'text-amber-800 dark:text-amber-300'

const statusStepIcon = (step) => (step.done ? '✓' : '⏳')

const actionLinks = computed(() => [
  {
    to: `/competitions/${competitionId.value}/registrations`,
    label: 'Administrar inscripciones',
    description: 'Gestionar jugadores inscriptos',
    icon: UserGroupIcon,
  },
  {
    to: `/competitions/${competitionId.value}/groups`,
    label: 'Administrar grupos',
    description: 'Crear grupos y asignar jugadores',
    icon: RectangleGroupIcon,
  },
  {
    to: `/competitions/${competitionId.value}/games`,
    label: 'Ver partidos',
    description: 'Consultar resultados y estado',
    icon: ViewColumnsIcon,
  },
  {
    to: bracketRoute.value,
    label: hasBracket.value ? 'Ver llave eliminatoria' : 'Generar bracket',
    description: hasBracket.value
      ? 'Consultar rondas y partidos eliminatorios'
      : 'Crear la llave eliminatoria',
    icon: TrophyIcon,
  },
])

const loadCompetitionSummary = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const [competitionData, registrationsData, groupsData, gamesData, bracketData] = await Promise.all([
      CompetitionService.show(competitionId.value),
      RegistrationService.listByCompetition(competitionId.value).catch(() => null),
      GroupService.listByCompetition(competitionId.value).catch(() => null),
      GameService.listByCompetition(competitionId.value).catch(() => null),
      BracketService.show(competitionId.value).catch(() => null),
    ])

    competition.value = competitionData
    bracket.value = bracketData
    registrations.value = registrationsData
    groups.value = groupsData
    games.value = gamesData

    if (groupsData?.length > 0) {
      const standingsEntries = await Promise.all(
        groupsData.map(async (group) => {
          try {
            const standings = await StandingService.listByGroup(group.id)
            return [group.id, standings]
          } catch {
            return [group.id, null]
          }
        }),
      )

      groupStandings.value = Object.fromEntries(standingsEntries)
    } else {
      groupStandings.value = {}
    }
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || 'No se pudo cargar la competencia.'
  } finally {
    isLoading.value = false
  }
}

onMounted(loadCompetitionSummary)
</script>

<template>
  <section class="space-y-4">
    <AppBreadcrumbs
      :context="{
        tournamentId: competition?.tournament_id,
        competitionId: competition?.id || competitionId,
        competitionName: competition?.name,
      }"
    />

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
        {{ competition?.name || `Competencia #${competitionId}` }}
      </h1>
      <AppBackButton :fallback-to="fallbackBackRoute" />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando competencia...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <template v-else-if="competition">
      <div
        class="rounded-md border border-slate-200 bg-slate-50 p-4 text-sm dark:border-slate-700 dark:bg-slate-900/60"
      >
        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Competencia</p>
        <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ competition.name }}</p>

        <p class="mt-4 font-medium text-slate-700 dark:text-slate-200">Resumen</p>

        <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Jugadores</dt>
            <dd class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">
              {{ formatCount(playerCount) }}
            </dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Grupos</dt>
            <dd class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">
              {{ formatCount(groupCount) }}
            </dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Partidos</dt>
            <dd class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">
              {{ formatCount(gameCount) }}
            </dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Finalizados</dt>
            <dd class="mt-1 text-lg font-semibold text-slate-900 dark:text-slate-100">
              {{ formatCount(finishedGameCount) }}
            </dd>
          </div>
        </dl>

        <p
          v-if="games !== null && games.length === 0"
          class="mt-3 text-sm text-slate-600 dark:text-slate-300"
        >
          No hay partidos generados
        </p>
      </div>

      <div
        v-if="showFinalResults"
        class="rounded-md border border-amber-200 bg-gradient-to-b from-amber-50 to-white p-4 text-sm dark:border-amber-900 dark:from-amber-950/30 dark:to-slate-900"
      >
        <div class="flex items-center gap-2">
          <TrophyIcon class="h-6 w-6 text-amber-600 dark:text-amber-400" />
          <p class="text-lg font-semibold text-slate-900 dark:text-slate-100">Resultados finales</p>
        </div>

        <div
          class="mt-4 grid gap-3"
          :class="finalResults.semifinalists.length > 0 ? 'lg:grid-cols-3' : 'sm:grid-cols-2'"
        >
          <article
            class="rounded-md border border-amber-300 bg-amber-50 p-4 shadow-sm dark:border-amber-800 dark:bg-amber-950/40"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-amber-800 dark:text-amber-300">
              Campeón
            </p>
            <p class="mt-2 text-xl font-bold text-slate-900 dark:text-slate-100">
              {{ finalResults.champion.name }}
            </p>
          </article>

          <article
            class="rounded-md border border-slate-300 bg-slate-50 p-4 shadow-sm dark:border-slate-600 dark:bg-slate-800/60"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
              Subcampeón
            </p>
            <p class="mt-2 text-lg font-semibold text-slate-900 dark:text-slate-100">
              {{ finalResults.runnerUp.name }}
            </p>
          </article>

          <article
            v-if="finalResults.semifinalists.length > 0"
            class="rounded-md border border-orange-200 bg-orange-50/70 p-4 shadow-sm dark:border-orange-900 dark:bg-orange-950/30"
          >
            <p class="text-xs font-semibold uppercase tracking-wide text-orange-800 dark:text-orange-300">
              Semifinalistas
            </p>
            <ul class="mt-2 space-y-1">
              <li
                v-for="semifinalist in finalResults.semifinalists"
                :key="semifinalist.id"
                class="font-medium text-slate-900 dark:text-slate-100"
              >
                {{ semifinalist.name }}
              </li>
            </ul>
          </article>
        </div>
      </div>

      <div
        v-if="statusSteps.length > 0"
        class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <p class="font-medium text-slate-700 dark:text-slate-200">Estado de la competencia</p>

        <ul class="mt-3 space-y-2">
          <li
            v-for="step in statusSteps"
            :key="step.label"
            class="flex items-center gap-2 font-medium"
            :class="statusStepClasses(step)"
          >
            <span aria-hidden="true">{{ statusStepIcon(step) }}</span>
            {{ step.label }}
          </li>
        </ul>
      </div>

      <div
        class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <div class="flex flex-wrap items-center justify-between gap-2">
          <p class="font-medium text-slate-700 dark:text-slate-200">{{ qualifiersSectionTitle }}</p>

          <span
            v-if="hasQualifiersData"
            class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
            :class="qualifiersStatusBadgeClasses"
          >
            <span aria-hidden="true">{{ isGroupPhaseComplete ? '✓' : '⏳' }}</span>
            {{ qualifiersStatusBadgeLabel }}
          </span>
        </div>

        <p
          v-if="hasQualifiersData"
          class="mt-2 text-slate-600 dark:text-slate-300"
        >
          <span aria-hidden="true">{{ isGroupPhaseComplete ? '✓' : '⏳' }}</span>
          {{ qualifiersSectionMessage }}
        </p>

        <p
          v-if="groups !== null && groups.length === 0"
          class="mt-3 text-slate-600 dark:text-slate-300"
        >
          No hay grupos creados
        </p>

        <p
          v-else-if="groups !== null && groups.length > 0 && !hasQualifiersData"
          class="mt-3 text-slate-600 dark:text-slate-300"
        >
          Las posiciones aún no están disponibles
        </p>

        <div v-else-if="hasQualifiersData" class="mt-3 space-y-4">
          <div v-for="entry in qualifiersByGroup" :key="entry.group.id">
            <template v-if="entry.qualifiers?.length">
              <p class="font-medium text-slate-900 dark:text-slate-100">{{ entry.group.name }}</p>

              <ul class="mt-2 space-y-2">
                <li
                  v-for="qualifier in entry.qualifiers"
                  :key="qualifier.player_id"
                  class="flex items-center gap-2 rounded-md border px-3 py-2"
                  :class="qualifierItemClasses"
                >
                  <span
                    class="inline-flex min-w-[2.5rem] items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold"
                    :class="positionBadgeClasses(qualifier.position)"
                  >
                    {{ qualifier.position }}°
                  </span>
                  <span class="font-medium text-slate-900 dark:text-slate-100">
                    {{ qualifier.player_name }}
                  </span>
                </li>
              </ul>
            </template>
          </div>
        </div>
      </div>

      <div
        class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <p class="font-medium text-slate-700 dark:text-slate-200">Llave eliminatoria</p>

        <template v-if="!hasBracket">
          <p class="mt-3 text-slate-600 dark:text-slate-300">
            Todavía no se generó la llave eliminatoria.
          </p>

          <RouterLink
            :to="bracketRoute"
            class="mt-4 inline-flex rounded-md bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          >
            Generar bracket
          </RouterLink>
        </template>

        <template v-else>
          <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Nombre</dt>
              <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracket.name }}</dd>
            </div>

            <div>
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Clasifican por grupo</dt>
              <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">
                {{ bracket.qualifiers_per_group }}
              </dd>
            </div>

            <div>
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Partidos</dt>
              <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracketGameCount }}</dd>
            </div>

            <div v-if="bracketStatus">
              <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Estado</dt>
              <dd class="mt-1 font-semibold text-slate-900 dark:text-slate-100">{{ bracketStatus }}</dd>
            </div>
          </dl>

          <RouterLink
            :to="bracketRoute"
            class="mt-4 inline-flex rounded-md bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          >
            Ver llave eliminatoria
          </RouterLink>
        </template>
      </div>

      <div>
        <p class="mb-3 text-sm font-medium text-slate-700 dark:text-slate-200">Acciones principales</p>

        <div class="grid gap-3 sm:grid-cols-2">
          <RouterLink
            v-for="action in actionLinks"
            :key="action.to"
            :to="action.to"
            class="group flex items-start gap-3 rounded-md border border-slate-200 bg-white p-4 text-sm transition hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:hover:border-slate-600 dark:hover:bg-slate-800/80"
          >
            <component
              :is="action.icon"
              class="mt-0.5 h-6 w-6 shrink-0 text-slate-500 group-hover:text-slate-700 dark:text-slate-400 dark:group-hover:text-slate-200"
            />
            <div>
              <p class="font-medium text-slate-900 dark:text-slate-100">{{ action.label }}</p>
              <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ action.description }}</p>
            </div>
          </RouterLink>
        </div>
      </div>

      <div
        class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <p class="font-medium text-slate-700 dark:text-slate-200">Detalle de la competencia</p>

        <div>
          <p class="text-slate-500 dark:text-slate-400">Nombre</p>
          <p class="font-medium text-slate-900 dark:text-slate-100">{{ competition.name }}</p>
        </div>

        <div>
          <p class="text-slate-500 dark:text-slate-400">Categoría</p>
          <p class="font-medium text-slate-900 dark:text-slate-100">{{ competition.category }}</p>
        </div>

        <div>
          <p class="text-slate-500 dark:text-slate-400">Tipo</p>
          <p class="font-medium text-slate-900 dark:text-slate-100">{{ competition.type }}</p>
        </div>

        <div>
          <p class="text-slate-500 dark:text-slate-400">Formato</p>
          <p class="font-medium text-slate-900 dark:text-slate-100">{{ competition.format }}</p>
        </div>

        <div>
          <p class="text-slate-500 dark:text-slate-400">Sets para ganar (legacy)</p>
          <p class="font-medium text-slate-900 dark:text-slate-100">{{ competition.sets_to_win }}</p>
        </div>

        <div class="space-y-1 border-t border-slate-200 pt-3 dark:border-slate-700">
          <p class="font-medium text-slate-700 dark:text-slate-200">Formato de partidos</p>
          <p class="text-slate-600 dark:text-slate-300">
            Grupos: mejor de {{ formatCount(competition.group_stage_best_of) }}
          </p>
          <p class="text-slate-600 dark:text-slate-300">
            Eliminatorias: mejor de {{ formatCount(competition.knockout_stage_best_of) }}
          </p>
          <p class="text-slate-600 dark:text-slate-300">
            Semifinal: mejor de {{ formatCount(competition.semifinal_best_of) }}
          </p>
          <p class="text-slate-600 dark:text-slate-300">
            Final: mejor de {{ formatCount(competition.final_best_of) }}
          </p>
        </div>

        <div>
          <p class="text-slate-500 dark:text-slate-400">Puntos por set</p>
          <p class="font-medium text-slate-900 dark:text-slate-100">{{ competition.points_per_set }}</p>
        </div>
      </div>
    </template>
  </section>
</template>
