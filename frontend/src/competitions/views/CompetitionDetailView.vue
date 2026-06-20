<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import CompetitionService from '../services/CompetitionService'

const route = useRoute()

const competition = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')
const fallbackBackRoute = computed(() =>
  competition.value?.tournament_id ? `/tournaments/${competition.value.tournament_id}/competitions` : '/tournaments',
)

const loadCompetition = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    competition.value = await CompetitionService.show(route.params.id)
  } catch (error) {
    errorMessage.value =
      error?.response?.data?.message || 'No se pudo cargar la competencia.'
  } finally {
    isLoading.value = false
  }
}

onMounted(loadCompetition)
</script>

<template>
  <section class="space-y-4">
    <AppBreadcrumbs
      :context="{
        tournamentId: competition?.tournament_id,
        competitionId: competition?.id || route.params.id,
        competitionName: competition?.name,
      }"
    />

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">{{ competition?.name || `Competencia #${route.params.id}` }}</h1>
      <AppBackButton :fallback-to="fallbackBackRoute" />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600">Cargando competencia...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600">{{ errorMessage }}</p>

    <div
      v-else-if="competition"
      class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm"
    >
      <div>
        <RouterLink
          :to="`/competitions/${competition.id}/registrations`"
          class="inline-flex rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
        >
          Administrar inscripciones
        </RouterLink>
      </div>

      <div>
        <RouterLink
          :to="`/competitions/${competition.id}/groups`"
          class="inline-flex rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
        >
          Administrar grupos
        </RouterLink>
      </div>

      <div>
        <RouterLink
          :to="`/competitions/${competition.id}/games`"
          class="inline-flex rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
        >
          Ver partidos
        </RouterLink>
      </div>

      <div>
        <RouterLink
          :to="`/competitions/${competition.id}/bracket`"
          class="inline-flex rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
        >
          Bracket
        </RouterLink>
      </div>

      <div>
        <p class="text-slate-500">Nombre</p>
        <p class="font-medium text-slate-900">{{ competition.name }}</p>
      </div>

      <div>
        <p class="text-slate-500">Categoría</p>
        <p class="font-medium text-slate-900">{{ competition.category }}</p>
      </div>

      <div>
        <p class="text-slate-500">Tipo</p>
        <p class="font-medium text-slate-900">{{ competition.type }}</p>
      </div>

      <div>
        <p class="text-slate-500">Formato</p>
        <p class="font-medium text-slate-900">{{ competition.format }}</p>
      </div>

      <div>
        <p class="text-slate-500">Sets para ganar</p>
        <p class="font-medium text-slate-900">{{ competition.sets_to_win }}</p>
      </div>

      <div>
        <p class="text-slate-500">Puntos por set</p>
        <p class="font-medium text-slate-900">{{ competition.points_per_set }}</p>
      </div>
    </div>
  </section>
</template>
