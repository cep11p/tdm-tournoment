<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import CompetitionService from '../../competitions/services/CompetitionService'
import StandingService from '../services/StandingService'

const route = useRoute()

const groupId = computed(() => route.params.id)
const competitionId = computed(() => route.query.competitionId || '')
const groupName = computed(() => route.query.groupName || `Grupo #${groupId.value}`)
const competition = ref(null)

const standings = ref([])
const isLoading = ref(false)
const errorMessage = ref('')

const standingsWithPosition = computed(() =>
  standings.value.map((standing, index) => ({
    ...standing,
    position: index + 1,
  })),
)

const loadStandings = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    standings.value = await StandingService.listByGroup(groupId.value)
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || 'No se pudo cargar la tabla de posiciones.'
  } finally {
    isLoading.value = false
  }
}

const loadCompetition = async () => {
  if (!competitionId.value) {
    competition.value = null
    return
  }

  try {
    competition.value = await CompetitionService.show(competitionId.value)
  } catch {
    competition.value = null
  }
}

onMounted(async () => {
  await Promise.all([loadStandings(), loadCompetition()])
})
</script>

<template>
  <section class="space-y-4">
    <AppBreadcrumbs
      :context="{
        tournamentId: competition?.tournament_id,
        competitionId: competitionId || competition?.id,
        competitionName: competition?.name,
        groupId,
        groupName,
      }"
    />

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">
        {{ competition?.name ? `${competition.name} - ${groupName} - Posiciones` : `Posiciones - ${groupName}` }}
      </h1>

      <AppBackButton
        :fallback-to="
          competitionId
            ? `/groups/${groupId}?competitionId=${competitionId}&groupName=${encodeURIComponent(groupName)}`
            : `/groups/${groupId}`
        "
      />
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600">Cargando posiciones...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600">{{ errorMessage }}</p>

    <div
      v-else-if="standingsWithPosition.length === 0"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600"
    >
      Este grupo todavía no tiene posiciones para mostrar.
    </div>

    <div v-else class="overflow-x-auto rounded-md border border-slate-200 bg-white">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-slate-700">
          <tr>
            <th class="px-3 py-2 text-left font-medium">Posición</th>
            <th class="px-3 py-2 text-left font-medium">Jugador</th>
            <th class="px-3 py-2 text-left font-medium">PJ</th>
            <th class="px-3 py-2 text-left font-medium">PG</th>
            <th class="px-3 py-2 text-left font-medium">PP</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="standing in standingsWithPosition"
            :key="standing.player_id"
            class="border-t border-slate-200"
          >
            <td class="px-3 py-2 text-slate-900">{{ standing.position }}</td>
            <td class="px-3 py-2 text-slate-900">{{ standing.player_name }}</td>
            <td class="px-3 py-2 text-slate-700">{{ standing.played }}</td>
            <td class="px-3 py-2 text-slate-700">{{ standing.won }}</td>
            <td class="px-3 py-2 text-slate-700">{{ standing.lost }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
