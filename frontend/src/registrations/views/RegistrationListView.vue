<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import AppBackButton from '../../components/AppBackButton.vue'
import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import CompetitionService from '../../competitions/services/CompetitionService'
import PlayerService from '../../players/services/PlayerService'
import RegistrationService from '../services/RegistrationService'

const route = useRoute()
const competitionId = computed(() => route.params.id)
const competition = ref(null)

const registrations = ref([])
const isLoadingRegistrations = ref(false)
const registrationListError = ref('')

const showRegistrationForm = ref(false)
const isSubmittingRegistration = ref(false)
const registrationSubmitError = ref('')
const registrationSuccessMessage = ref('')

const searchQuery = ref('')
const searchedOnce = ref(false)
const isSearchingPlayers = ref(false)
const playerSearchError = ref('')
const playerSearchResults = ref([])
const selectedPlayerId = ref(null)

const showCreatePlayerForm = ref(false)
const isCreatingPlayer = ref(false)
const createPlayerError = ref('')
const createPlayerForm = ref({
  first_name: '',
  last_name: '',
  nickname: '',
})

const loadRegistrations = async () => {
  isLoadingRegistrations.value = true
  registrationListError.value = ''

  try {
    registrations.value = await RegistrationService.listByCompetition(competitionId.value)
  } catch (error) {
    registrationListError.value =
      error?.response?.data?.message || 'No se pudo cargar el listado de inscriptos.'
  } finally {
    isLoadingRegistrations.value = false
  }
}

const loadCompetition = async () => {
  try {
    competition.value = await CompetitionService.show(competitionId.value)
  } catch {
    competition.value = null
  }
}

const handleSearchPlayers = async () => {
  searchedOnce.value = true
  isSearchingPlayers.value = true
  playerSearchError.value = ''
  selectedPlayerId.value = null
  registrationSubmitError.value = ''
  registrationSuccessMessage.value = ''

  try {
    playerSearchResults.value = await PlayerService.search(searchQuery.value.trim())
    showCreatePlayerForm.value = playerSearchResults.value.length === 0
  } catch (error) {
    playerSearchError.value = error?.response?.data?.message || 'No se pudo buscar jugadores.'
  } finally {
    isSearchingPlayers.value = false
  }
}

const handleRegisterSelectedPlayer = async () => {
  if (!selectedPlayerId.value) {
    registrationSubmitError.value = 'Seleccioná un jugador para inscribir.'
    return
  }

  isSubmittingRegistration.value = true
  registrationSubmitError.value = ''
  registrationSuccessMessage.value = ''

  try {
    await RegistrationService.create(competitionId.value, selectedPlayerId.value)
    registrationSuccessMessage.value = 'Jugador inscripto correctamente.'
    await loadRegistrations()
  } catch (error) {
    registrationSubmitError.value =
      error?.response?.data?.errors?.player_id?.[0] ||
      error?.response?.data?.message ||
      'No se pudo inscribir el jugador.'
  } finally {
    isSubmittingRegistration.value = false
  }
}

const handleCreateAndRegisterPlayer = async () => {
  if (!createPlayerForm.value.first_name.trim() || !createPlayerForm.value.last_name.trim()) {
    createPlayerError.value = 'Nombre y apellido son obligatorios.'
    return
  }

  isCreatingPlayer.value = true
  createPlayerError.value = ''
  registrationSubmitError.value = ''
  registrationSuccessMessage.value = ''

  try {
    const newPlayer = await PlayerService.create({
      first_name: createPlayerForm.value.first_name.trim(),
      last_name: createPlayerForm.value.last_name.trim(),
      nickname: createPlayerForm.value.nickname.trim() || null,
    })

    await RegistrationService.create(competitionId.value, newPlayer.id)
    registrationSuccessMessage.value = 'Jugador creado e inscripto correctamente.'

    createPlayerForm.value = {
      first_name: '',
      last_name: '',
      nickname: '',
    }
    showCreatePlayerForm.value = false
    playerSearchResults.value = []
    searchedOnce.value = false
    searchQuery.value = ''
    selectedPlayerId.value = null

    await loadRegistrations()
  } catch (error) {
    createPlayerError.value =
      error?.response?.data?.message ||
      error?.response?.data?.errors?.first_name?.[0] ||
      'No se pudo crear e inscribir el jugador.'
  } finally {
    isCreatingPlayer.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadRegistrations(), loadCompetition()])
})
</script>

<template>
  <section class="space-y-4">
    <AppBreadcrumbs
      :context="{
        tournamentId: competition?.tournament_id,
        tournamentName: competition?.tournament?.name,
        competitionId,
        competitionName: competition?.name,
      }"
    />

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">Inscriptos - {{ competition?.name || `Competencia #${competitionId}` }}</h1>

      <AppBackButton :fallback-to="`/competitions/${competitionId}`" />
    </div>

    <div>
      <button
        type="button"
        class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700"
        @click="showRegistrationForm = !showRegistrationForm"
      >
        Inscribir jugador
      </button>
    </div>

    <div
      v-if="showRegistrationForm"
      class="space-y-4 rounded-md border border-slate-200 bg-white p-4 text-sm"
    >
      <div class="flex items-end gap-2">
        <label class="flex-1">
          <span class="mb-1 block text-sm font-medium text-slate-700">Buscar jugador</span>
          <input
            v-model="searchQuery"
            type="text"
            placeholder="Nombre, apellido o nickname"
            class="w-full rounded-md border border-slate-300 px-3 py-2"
          />
        </label>

        <button
          type="button"
          class="rounded-md bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70"
          :disabled="isSearchingPlayers"
          @click="handleSearchPlayers"
        >
          {{ isSearchingPlayers ? 'Buscando...' : 'Buscar' }}
        </button>
      </div>

      <p v-if="playerSearchError" class="text-red-600">{{ playerSearchError }}</p>

      <div v-if="playerSearchResults.length > 0" class="space-y-2">
        <p class="font-medium text-slate-700">Seleccioná un jugador existente</p>

        <label
          v-for="player in playerSearchResults"
          :key="player.id"
          class="flex items-center gap-2 rounded border border-slate-200 p-2"
        >
          <input v-model="selectedPlayerId" :value="player.id" type="radio" name="selected-player" />
          <span>
            {{ player.first_name }} {{ player.last_name }}
            <span v-if="player.nickname" class="text-slate-500">({{ player.nickname }})</span>
          </span>
        </label>

        <button
          type="button"
          class="rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70"
          :disabled="isSubmittingRegistration"
          @click="handleRegisterSelectedPlayer"
        >
          {{ isSubmittingRegistration ? 'Inscribiendo...' : 'Registrar seleccionado' }}
        </button>
      </div>

      <div
        v-else-if="searchedOnce && !isSearchingPlayers"
        class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-700"
      >
        No se encontraron jugadores con esa búsqueda.
      </div>

      <div v-if="searchedOnce && playerSearchResults.length === 0 && !isSearchingPlayers">
        <button
          type="button"
          class="text-sm font-medium text-slate-700 underline"
          @click="showCreatePlayerForm = !showCreatePlayerForm"
        >
          {{ showCreatePlayerForm ? 'Ocultar creación de jugador' : 'Crear jugador' }}
        </button>
      </div>

      <form
        v-if="showCreatePlayerForm"
        class="space-y-3 rounded-md border border-slate-200 p-3"
        @submit.prevent="handleCreateAndRegisterPlayer"
      >
        <p class="font-medium text-slate-700">Crear jugador</p>

        <div>
          <label for="first_name" class="mb-1 block font-medium text-slate-700">Nombre</label>
          <input
            id="first_name"
            v-model="createPlayerForm.first_name"
            type="text"
            class="w-full rounded-md border border-slate-300 px-3 py-2"
          />
        </div>

        <div>
          <label for="last_name" class="mb-1 block font-medium text-slate-700">Apellido</label>
          <input
            id="last_name"
            v-model="createPlayerForm.last_name"
            type="text"
            class="w-full rounded-md border border-slate-300 px-3 py-2"
          />
        </div>

        <div>
          <label for="nickname" class="mb-1 block font-medium text-slate-700">Nickname</label>
          <input
            id="nickname"
            v-model="createPlayerForm.nickname"
            type="text"
            class="w-full rounded-md border border-slate-300 px-3 py-2"
          />
        </div>

        <p v-if="createPlayerError" class="text-red-600">{{ createPlayerError }}</p>

        <button
          type="submit"
          class="rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70"
          :disabled="isCreatingPlayer"
        >
          {{ isCreatingPlayer ? 'Creando...' : 'Crear e inscribir' }}
        </button>
      </form>

      <p v-if="registrationSubmitError" class="text-red-600">{{ registrationSubmitError }}</p>
      <p v-if="registrationSuccessMessage" class="text-emerald-700">{{ registrationSuccessMessage }}</p>
    </div>

    <p v-if="isLoadingRegistrations" class="text-sm text-slate-600">Cargando inscriptos...</p>
    <p v-else-if="registrationListError" class="text-sm text-red-600">{{ registrationListError }}</p>

    <div
      v-else-if="registrations.length === 0"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600"
    >
      Esta competencia todavía no tiene inscriptos.
    </div>

    <div v-else class="space-y-2 rounded-md border border-slate-200 bg-white p-4">
      <article
        v-for="registration in registrations"
        :key="registration.id"
        class="rounded border border-slate-200 p-3 text-sm"
      >
        <p class="font-medium text-slate-900">
          {{ registration.player.first_name }} {{ registration.player.last_name }}
        </p>
        <p class="text-slate-600">Nickname: {{ registration.player.nickname || '-' }}</p>
      </article>
    </div>
  </section>
</template>
