<script setup>
import { Cog6ToothIcon, EyeIcon, PencilSquareIcon, PlusIcon } from '@heroicons/vue/24/outline'
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'

import AppTooltip from '../../components/AppTooltip.vue'
import TournamentFormModal from '../components/TournamentFormModal.vue'
import TournamentService from '../services/TournamentService'
import {
  getTournamentStatusBadgeClasses,
  getTournamentStatusLabel,
} from '../utils/tournamentListDisplay'

const tournaments = ref([])
const isLoading = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const showCreateModal = ref(false)
const showEditModal = ref(false)
const editingTournament = ref(null)

const addButtonClasses =
  'inline-flex rounded-md border border-emerald-300 bg-emerald-50 p-2 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60'

const viewButtonClasses =
  'inline-flex rounded-md border border-blue-300 bg-blue-50 p-1.5 text-blue-700 hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300 dark:hover:bg-blue-950/60'

const editButtonClasses =
  'inline-flex rounded-md border border-amber-300 bg-amber-50 p-1.5 text-amber-800 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-950/60'

const manageButtonClasses =
  'inline-flex rounded-md border border-violet-400 bg-violet-600 p-1.5 text-white hover:bg-violet-700 dark:border-violet-500 dark:bg-violet-600 dark:hover:bg-violet-500'

const loadTournaments = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    tournaments.value = await TournamentService.list()
  } catch (error) {
    errorMessage.value =
      error?.response?.data?.message || 'No se pudo cargar el listado de torneos.'
  } finally {
    isLoading.value = false
  }
}

const openCreateModal = () => {
  successMessage.value = ''
  showCreateModal.value = true
}

const handleCreateClose = () => {
  showCreateModal.value = false
}

const handleCreateSaved = async () => {
  showCreateModal.value = false
  successMessage.value = 'Torneo creado correctamente.'
  await loadTournaments()
}

const openEditModal = (tournament) => {
  successMessage.value = ''
  editingTournament.value = tournament
  showEditModal.value = true
}

const handleEditClose = () => {
  showEditModal.value = false
  editingTournament.value = null
}

const handleEditSaved = async () => {
  showEditModal.value = false
  editingTournament.value = null
  successMessage.value = 'Torneo actualizado correctamente.'
  await loadTournaments()
}

onMounted(loadTournaments)
</script>

<template>
  <section class="min-w-0 space-y-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Torneos</h1>
        <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
          Gestioná los torneos y sus competencias.
        </p>
      </div>
      <AppTooltip label="Nuevo torneo">
        <button
          type="button"
          :class="addButtonClasses"
          aria-label="Nuevo torneo"
          @click="openCreateModal"
        >
          <PlusIcon class="h-4 w-4" aria-hidden="true" />
        </button>
      </AppTooltip>
    </div>

    <p
      v-if="successMessage"
      class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100"
    >
      {{ successMessage }}
    </p>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando torneos...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <div
      v-else-if="tournaments.length === 0"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
    >
      Todavía no hay torneos cargados.
    </div>

    <div
      v-else
      class="w-full overflow-x-auto rounded-md border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
    >
      <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
        <thead class="bg-slate-50 dark:bg-slate-800">
          <tr>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Torneo
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Estado
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Fecha inicio
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Fecha fin
            </th>
            <th
              class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Ubicación
            </th>
            <th
              class="w-40 px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
            >
              Acciones
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-700 dark:bg-slate-900">
          <tr
            v-for="tournament in tournaments"
            :key="tournament.id"
            class="hover:bg-slate-50 dark:hover:bg-slate-800"
          >
            <td class="px-4 py-3 text-sm">
              <p class="font-medium text-slate-900 dark:text-slate-100">{{ tournament.name }}</p>
            </td>
            <td class="px-4 py-3 text-sm">
              <span
                v-if="tournament.status"
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="getTournamentStatusBadgeClasses(tournament.status)"
              >
                {{ getTournamentStatusLabel(tournament.status) }}
              </span>
              <span v-else class="text-slate-400 dark:text-slate-500">-</span>
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ tournament.start_date || '-' }}
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ tournament.end_date || '-' }}
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ tournament.location || '-' }}
            </td>
            <td class="w-40 px-4 py-3 text-sm">
              <div class="flex flex-nowrap items-center justify-end gap-1.5">
                <AppTooltip label="Ver detalle">
                  <RouterLink
                    :to="`/tournaments/${tournament.id}`"
                    :class="viewButtonClasses"
                    aria-label="Ver detalle del torneo"
                  >
                    <EyeIcon class="h-4 w-4" aria-hidden="true" />
                  </RouterLink>
                </AppTooltip>

                <AppTooltip label="Editar torneo">
                  <button
                    type="button"
                    :class="editButtonClasses"
                    aria-label="Editar torneo"
                    @click="openEditModal(tournament)"
                  >
                    <PencilSquareIcon class="h-4 w-4" aria-hidden="true" />
                  </button>
                </AppTooltip>

                <AppTooltip label="Administrar competencias">
                  <RouterLink
                    :to="`/tournaments/${tournament.id}`"
                    :class="manageButtonClasses"
                    aria-label="Administrar competencias del torneo"
                  >
                    <Cog6ToothIcon class="h-4 w-4" aria-hidden="true" />
                  </RouterLink>
                </AppTooltip>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <TournamentFormModal
      :show="showCreateModal"
      mode="create"
      @close="handleCreateClose"
      @saved="handleCreateSaved"
    />

    <TournamentFormModal
      :show="showEditModal"
      mode="edit"
      :tournament="editingTournament"
      :tournament-id="editingTournament?.id"
      @close="handleEditClose"
      @saved="handleEditSaved"
    />
  </section>
</template>
