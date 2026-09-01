<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import GameResultModal from '../../games/components/GameResultModal.vue'
import GameService from '../../games/services/GameService'
import TeamTieService from '../services/TeamTieService'
import TeamTieLineupEditor from '../components/TeamTieLineupEditor.vue'
import TeamTieRubberCard from '../components/TeamTieRubberCard.vue'
import {
  getTeamTieStatusBadgeClasses,
  getTeamTieStatusLabel,
} from '../constants/teamTieStatus'

const route = useRoute()
const teamTieId = computed(() => route.params.id)

const teamTie = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')
const selectedRubber = ref(null)
const showLineupEditor = ref(false)
const resultRubber = ref(null)
const showResultModal = ref(false)
const resultGame = ref(null)

const navigation = computed(() => teamTie.value?.navigation ?? {})

const isBye = computed(() => teamTie.value?.is_bye === true)

const classifiedTeamLabel = computed(() => {
  const winner = teamTie.value?.winner?.display_name
  const entry1 = teamTie.value?.entry1?.display_name

  return winner || entry1 || 'Equipo clasificado'
})

const matchupLabel = computed(() => {
  if (isBye.value) {
    return classifiedTeamLabel.value
  }

  const entry1 = teamTie.value?.entry1?.display_name ?? 'Equipo 1'
  const entry2 = teamTie.value?.entry2?.display_name ?? 'Equipo 2'
  return `${entry1} vs ${entry2}`
})

const formatLabel = computed(() => {
  if (isBye.value) {
    return null
  }

  const name = teamTie.value?.format?.name ?? 'Formato'
  const victories = teamTie.value?.format?.victories_required ?? '?'
  return `${name} · primero en llegar a ${victories}`
})

const score = computed(() => teamTie.value?.score ?? { entry1: 0, entry2: 0 })

const scoreLabel = computed(() => {
  if (isBye.value) {
    return null
  }

  const entry1 = teamTie.value?.entry1?.display_name ?? 'Equipo 1'
  const entry2 = teamTie.value?.entry2?.display_name ?? 'Equipo 2'
  return `${entry1} ${score.value.entry1} - ${score.value.entry2} ${entry2}`
})

const isFinished = computed(() => teamTie.value?.status === 'finished')

const winnerLabel = computed(() => teamTie.value?.winner?.display_name ?? null)

const finishedAtLabel = computed(() => {
  const finishedAt = teamTie.value?.finished_at
  if (!finishedAt) {
    return null
  }

  return new Date(finishedAt).toLocaleString()
})

const statusLabel = computed(() => getTeamTieStatusLabel(teamTie.value?.status))

const roundLabel = computed(() => teamTie.value?.round ?? null)

const breadcrumbContext = computed(() => ({
  tournamentId: navigation.value.tournament_id,
  tournamentName: navigation.value.tournament_name,
  competitionId: navigation.value.competition_id,
  competitionName: navigation.value.competition_name,
  groupId: navigation.value.group_id,
  groupName: navigation.value.group_name,
  teamTieLabel: matchupLabel.value,
}))

const fallbackBackRoute = computed(() => {
  if (navigation.value.group_id) {
    const query = new URLSearchParams()

    if (navigation.value.competition_id) {
      query.set('competitionId', String(navigation.value.competition_id))
    }

    if (navigation.value.group_name) {
      query.set('groupName', navigation.value.group_name)
    }

    const queryString = query.toString()

    return `/groups/${navigation.value.group_id}${queryString ? `?${queryString}` : ''}`
  }

  if (navigation.value.competition_id) {
    return `/competitions/${navigation.value.competition_id}/bracket`
  }

  if (navigation.value.tournament_id) {
    return `/tournaments/${navigation.value.tournament_id}`
  }

  return '/tournaments'
})

const backButtonLabel = computed(() => {
  if (navigation.value.group_id) {
    return 'Volver al grupo'
  }

  if (navigation.value.bracket_id) {
    return 'Volver a la llave'
  }

  return 'Volver'
})

const rubbers = computed(() => {
  const items = teamTie.value?.team_tie_games ?? []
  return [...items].sort((left, right) => left.slot_order - right.slot_order)
})

const loadTeamTie = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    teamTie.value = await TeamTieService.show(teamTieId.value)
  } catch (error) {
    teamTie.value = null
    errorMessage.value =
      error?.response?.data?.message || 'No se pudo cargar el enfrentamiento.'
  } finally {
    isLoading.value = false
  }
}

const openLineupEditor = (rubber) => {
  selectedRubber.value = rubber
  showLineupEditor.value = true
}

const openResultModal = async (rubber) => {
  if (!rubber?.game?.id) {
    return
  }

  try {
    resultGame.value = await GameService.show(rubber.game.id)
    resultRubber.value = rubber
    showResultModal.value = true
  } catch (error) {
    errorMessage.value =
      error?.response?.data?.message || 'No se pudo cargar el partido para registrar el resultado.'
  }
}

const handleLineupSaved = async () => {
  await loadTeamTie()
}

const handleResultSaved = async () => {
  showResultModal.value = false
  resultRubber.value = null
  resultGame.value = null
  await loadTeamTie()
}

onMounted(loadTeamTie)
</script>

<template>
  <div class="mx-auto max-w-4xl space-y-6 p-4 sm:p-6">
    <AppBreadcrumbs :context="breadcrumbContext" />

    <AppBackButton :fallback-to="fallbackBackRoute" :label="backButtonLabel" />

    <p v-if="isLoading" class="text-slate-600 dark:text-slate-300">Cargando enfrentamiento...</p>
    <p v-else-if="errorMessage" class="text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <template v-else-if="teamTie">
      <header class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
          <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ matchupLabel }}</h1>
          <span
            class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
            :class="getTeamTieStatusBadgeClasses(teamTie.status)"
          >
            {{ statusLabel }}
          </span>
        </div>

        <template v-if="isBye">
          <div
            class="rounded-md border border-violet-200 bg-violet-50 p-4 text-sm text-violet-900 dark:border-violet-900/60 dark:bg-violet-950/30 dark:text-violet-100"
          >
            <p class="font-semibold">Clasificado automáticamente</p>
            <p class="mt-1">
              Este equipo avanzó de ronda sin disputar el enfrentamiento.
            </p>
            <p v-if="roundLabel" class="mt-2 text-xs text-violet-800 dark:text-violet-200">
              Ronda: {{ roundLabel }}
            </p>
          </div>
        </template>

        <template v-else>
          <p class="text-lg font-medium text-slate-800 dark:text-slate-100">{{ scoreLabel }}</p>
          <p class="text-sm text-slate-600 dark:text-slate-300">{{ formatLabel }}</p>
          <p v-if="isFinished && winnerLabel" class="text-sm font-medium text-emerald-700 dark:text-emerald-300">
            Ganador: {{ winnerLabel }}
          </p>
          <p v-if="isFinished && finishedAtLabel" class="text-xs text-slate-500 dark:text-slate-400">
            Finalizado el {{ finishedAtLabel }}
          </p>
        </template>
      </header>

      <section v-if="!isBye" class="space-y-3">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
          Partidos del enfrentamiento
        </h2>

        <TeamTieRubberCard
          v-for="rubber in rubbers"
          :key="rubber.id"
          :rubber="rubber"
          :team-tie="teamTie"
          @edit-lineup="openLineupEditor"
          @record-result="openResultModal"
        />
      </section>
    </template>

    <TeamTieLineupEditor
      :show="showLineupEditor"
      :rubber="selectedRubber"
      :team-tie="teamTie"
      @close="showLineupEditor = false"
      @saved="handleLineupSaved"
    />

    <GameResultModal
      :show="showResultModal"
      :game="resultGame"
      @close="showResultModal = false"
      @saved="handleResultSaved"
    />
  </div>
</template>
