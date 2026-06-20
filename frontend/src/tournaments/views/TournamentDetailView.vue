<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import TournamentService from '../services/TournamentService'

const route = useRoute()

const tournament = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')

const loadTournament = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    tournament.value = await TournamentService.show(route.params.id)
  } catch (error) {
    errorMessage.value =
      error?.response?.data?.message || 'No se pudo cargar el torneo.'
  } finally {
    isLoading.value = false
  }
}

onMounted(loadTournament)
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">Detalle de torneo</h1>
      <RouterLink to="/tournaments" class="text-sm font-medium text-slate-700 hover:underline">
        Volver al listado
      </RouterLink>
    </div>

    <p v-if="isLoading" class="text-sm text-slate-600">Cargando torneo...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600">{{ errorMessage }}</p>

    <div
      v-else-if="tournament"
      class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm"
    >
      <div>
        <RouterLink
          :to="`/tournaments/${tournament.id}/competitions`"
          class="inline-flex rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
        >
          Administrar competencias
        </RouterLink>
      </div>

      <div>
        <p class="text-slate-500">Nombre</p>
        <p class="font-medium text-slate-900">{{ tournament.name }}</p>
      </div>

      <div>
        <p class="text-slate-500">Ubicación</p>
        <p class="font-medium text-slate-900">{{ tournament.location }}</p>
      </div>

      <div>
        <p class="text-slate-500">Fecha inicio</p>
        <p class="font-medium text-slate-900">{{ tournament.start_date || '-' }}</p>
      </div>

      <div>
        <p class="text-slate-500">Fecha fin</p>
        <p class="font-medium text-slate-900">{{ tournament.end_date || '-' }}</p>
      </div>

      <div>
        <p class="text-slate-500">Estado</p>
        <p class="font-medium text-slate-900">{{ tournament.status || '-' }}</p>
      </div>
    </div>
  </section>
</template>
