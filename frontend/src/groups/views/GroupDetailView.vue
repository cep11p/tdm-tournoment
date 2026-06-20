<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import RegistrationService from '../../registrations/services/RegistrationService'
import GroupService from '../services/GroupService'

const route = useRoute()

const groupId = computed(() => route.params.id)
const competitionId = computed(() => route.query.competitionId || '')
const groupName = computed(() => route.query.groupName || `Grupo #${groupId.value}`)

const groupPlayers = ref([])
const isLoadingGroupPlayers = ref(false)
const groupPlayersError = ref('')

const registeredPlayers = ref([])
const isLoadingRegisteredPlayers = ref(false)
const registeredPlayersError = ref('')

const selectedPlayerId = ref('')
const isAssigningPlayer = ref(false)
const assignError = ref('')
const assignSuccessMessage = ref('')

const isGeneratingRoundRobin = ref(false)
const roundRobinError = ref('')
const roundRobinSuccessMessage = ref('')

const loadGroupPlayers = async () => {
  isLoadingGroupPlayers.value = true
  groupPlayersError.value = ''

  try {
    groupPlayers.value = await GroupService.listPlayers(groupId.value)
  } catch (error) {
    groupPlayersError.value =
      error?.response?.data?.message || 'No se pudo cargar los jugadores del grupo.'
  } finally {
    isLoadingGroupPlayers.value = false
  }
}

const loadRegisteredPlayers = async () => {
  if (!competitionId.value) {
    registeredPlayers.value = []
    registeredPlayersError.value =
      'No se recibió competitionId en la ruta. Volvé al listado de grupos para asignar jugadores.'
    return
  }

  isLoadingRegisteredPlayers.value = true
  registeredPlayersError.value = ''

  try {
    const registrations = await RegistrationService.listByCompetition(competitionId.value)
    registeredPlayers.value = registrations
      .map((registration) => registration.player)
      .filter((player) => player?.id)
  } catch (error) {
    registeredPlayersError.value =
      error?.response?.data?.message || 'No se pudo cargar el listado de inscriptos.'
  } finally {
    isLoadingRegisteredPlayers.value = false
  }
}

const availablePlayers = computed(() => {
  const assignedPlayerIds = new Set(
    groupPlayers.value.map((groupPlayer) => groupPlayer.player?.id).filter(Boolean),
  )

  return registeredPlayers.value.filter((player) => !assignedPlayerIds.has(player.id))
})

const handleAssignPlayer = async () => {
  if (!selectedPlayerId.value) {
    assignError.value = 'Seleccioná un jugador para asignar.'
    return
  }

  isAssigningPlayer.value = true
  assignError.value = ''
  assignSuccessMessage.value = ''
  roundRobinError.value = ''
  roundRobinSuccessMessage.value = ''

  try {
    await GroupService.assignPlayer(groupId.value, Number(selectedPlayerId.value))
    selectedPlayerId.value = ''
    assignSuccessMessage.value = 'Jugador asignado correctamente.'
    await loadGroupPlayers()
  } catch (error) {
    assignError.value =
      error?.response?.data?.errors?.player_id?.[0] ||
      error?.response?.data?.message ||
      'No se pudo asignar el jugador.'
  } finally {
    isAssigningPlayer.value = false
  }
}

const handleGenerateRoundRobin = async () => {
  isGeneratingRoundRobin.value = true
  roundRobinError.value = ''
  roundRobinSuccessMessage.value = ''

  try {
    const createdGames = await GroupService.generateRoundRobin(groupId.value)
    roundRobinSuccessMessage.value = `Round robin generado. Partidos creados: ${createdGames.length}.`
  } catch (error) {
    roundRobinError.value =
      error?.response?.data?.errors?.group?.[0] ||
      error?.response?.data?.message ||
      'No se pudo generar el round robin.'
  } finally {
    isGeneratingRoundRobin.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadGroupPlayers(), loadRegisteredPlayers()])
})
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold">{{ groupName }}</h1>

      <div class="flex items-center gap-3">
        <RouterLink
          :to="`/groups/${groupId}/standings?competitionId=${competitionId}&groupName=${encodeURIComponent(groupName)}`"
          class="text-sm font-medium text-slate-700 hover:underline"
        >
          Ver posiciones
        </RouterLink>

        <RouterLink
          v-if="competitionId"
          :to="`/competitions/${competitionId}/groups`"
          class="text-sm font-medium text-slate-700 hover:underline"
        >
          Volver a grupos
        </RouterLink>
      </div>
    </div>

    <div class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm">
      <p class="font-medium text-slate-700">Asignar jugador registrado</p>

      <p v-if="isLoadingRegisteredPlayers" class="text-slate-600">Cargando jugadores inscriptos...</p>
      <p v-else-if="registeredPlayersError" class="text-red-600">{{ registeredPlayersError }}</p>

      <template v-else>
        <div v-if="availablePlayers.length === 0" class="text-slate-600">
          No hay jugadores inscriptos disponibles para asignar.
        </div>

        <form v-else class="flex items-end gap-2" @submit.prevent="handleAssignPlayer">
          <label class="flex-1">
            <span class="mb-1 block font-medium text-slate-700">Jugador</span>
            <select
              v-model="selectedPlayerId"
              class="w-full rounded-md border border-slate-300 px-3 py-2"
            >
              <option value="">Seleccionar jugador</option>
              <option v-for="player in availablePlayers" :key="player.id" :value="player.id">
                {{ player.first_name }} {{ player.last_name }}
                {{ player.nickname ? `(${player.nickname})` : '' }}
              </option>
            </select>
          </label>

          <button
            type="submit"
            class="rounded-md bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70"
            :disabled="isAssigningPlayer"
          >
            {{ isAssigningPlayer ? 'Asignando...' : 'Asignar' }}
          </button>
        </form>

        <p v-if="assignError" class="text-red-600">{{ assignError }}</p>
        <p v-if="assignSuccessMessage" class="text-emerald-700">{{ assignSuccessMessage }}</p>
      </template>
    </div>

    <div class="space-y-3 rounded-md border border-slate-200 bg-white p-4 text-sm">
      <div class="flex items-center justify-between">
        <p class="font-medium text-slate-700">Jugadores asignados</p>

        <button
          type="button"
          class="rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70"
          :disabled="isGeneratingRoundRobin"
          @click="handleGenerateRoundRobin"
        >
          {{ isGeneratingRoundRobin ? 'Generando...' : 'Generar round robin' }}
        </button>
      </div>

      <p v-if="roundRobinError" class="text-red-600">{{ roundRobinError }}</p>
      <p v-if="roundRobinSuccessMessage" class="text-emerald-700">{{ roundRobinSuccessMessage }}</p>

      <p v-if="isLoadingGroupPlayers" class="text-slate-600">Cargando jugadores del grupo...</p>
      <p v-else-if="groupPlayersError" class="text-red-600">{{ groupPlayersError }}</p>

      <div
        v-else-if="groupPlayers.length === 0"
        class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600"
      >
        Este grupo todavía no tiene jugadores asignados.
      </div>

      <div v-else class="space-y-2">
        <article
          v-for="groupPlayer in groupPlayers"
          :key="groupPlayer.id"
          class="rounded border border-slate-200 p-3"
        >
          <p class="font-medium text-slate-900">
            {{ groupPlayer.player.first_name }} {{ groupPlayer.player.last_name }}
          </p>
          <p class="text-slate-600">Nickname: {{ groupPlayer.player.nickname || '-' }}</p>
        </article>
      </div>
    </div>
  </section>
</template>
