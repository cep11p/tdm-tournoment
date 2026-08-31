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

const matchupLabel = computed(() => {
  const entry1 = teamTie.value?.entry1?.display_name ?? 'Equipo 1'
  const entry2 = teamTie.value?.entry2?.display_name ?? 'Equipo 2'
  return `${entry1} vs ${entry2}`
})

const formatLabel = computed(() => {
  const name = teamTie.value?.format?.name ?? 'Formato'
  const victories = teamTie.value?.format?.victories_required ?? '?'
  return `${name} · primero en llegar a ${victories}`
})

const scoreLabel = computed(() => {
  const entry1 = teamTie.value?.entry1?.display_name ?? 'Equipo 1'
  const entry2 = teamTie.value?.entry2?.display_name ?? 'Equipo 2'
  const score = teamTie.value?.score ?? { entry1: 0, entry2: 0 }
  return `${entry1} ${score.entry1} - ${score.entry2} ${entry2}`
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
    <AppBreadcrumbs
      :items="[
        { label: 'Torneos', to: '/tournaments' },
        { label: 'Enfrentamiento', to: null },
      ]"
    />

    <AppBackButton fallback-to="/" />

    <p v-if="isLoading" class="text-slate-600 dark:text-slate-300">Cargando enfrentamiento...</p>
    <p v-else-if="errorMessage" class="text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <template v-else-if="teamTie">
      <header class="space-y-2">
        <h1 class="text-2xl font-semibold text-slate-900 dark:text-slate-100">{{ matchupLabel }}</h1>
        <p class="text-sm text-slate-600 dark:text-slate-300">{{ formatLabel }}</p>
        <p class="text-lg font-medium text-slate-800 dark:text-slate-100">{{ scoreLabel }}</p>
      </header>

      <section class="space-y-3">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
          Partidos internos
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
