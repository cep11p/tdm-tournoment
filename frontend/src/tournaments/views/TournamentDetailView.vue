<script setup>
import {
  Cog6ToothIcon,
  EyeIcon,
  PencilSquareIcon,
  PlusIcon,
  TrashIcon,
} from '@heroicons/vue/24/outline'
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

import AppBreadcrumbs from '../../components/AppBreadcrumbs.vue'
import AppTooltip from '../../components/AppTooltip.vue'
import { usePermissions } from '../../composables/usePermissions'
import CompetitionFormModal from '../../competitions/components/CompetitionFormModal.vue'
import CompetitionService from '../../competitions/services/CompetitionService'
import {
  getStatusBadgeClasses,
  getStatusLabel,
  getStructurePrimary,
  getStructureSecondary,
} from '../../competitions/utils/competitionListDisplay'
import { getCompetitionTypeLabel } from '../../shared/constants/competitionType'
import FinalizeTournamentModal from '../components/FinalizeTournamentModal.vue'
import TournamentFormModal from '../components/TournamentFormModal.vue'
import TournamentService from '../services/TournamentService'
import {
  getTournamentStatusBadgeClasses,
  getTournamentStatusLabel,
} from '../utils/tournamentListDisplay'

const route = useRoute()
const { can } = usePermissions()

const canManageTournaments = computed(() => can('tournaments.manage'))
const canManageCompetitions = computed(() => can('competitions.manage'))

const tournament = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')
const showEditModal = ref(false)
const editSuccessMessage = ref('')
const showCompetitionModal = ref(false)
const competitionModalMode = ref('create')
const editingCompetition = ref(null)
const competitionSuccessMessage = ref('')
const closeSuccessMessage = ref('')
const showFinalizeModal = ref(false)

const isTournamentClosed = computed(() => tournament.value?.status === 'finished')

const canCreateCompetition = computed(
  () => canManageCompetitions.value && !isTournamentClosed.value,
)

const canFinalizeTournament = computed(
  () => canManageTournaments.value && tournament.value && !isTournamentClosed.value,
)

const tournamentResults = computed(() => {
  const summary = tournament.value?.results_summary

  if (summary?.results?.length) {
    return summary.results
  }

  return competitions.value
    .filter((competition) => competition.result_summary?.champion?.name)
    .map((competition) => ({
      competition_name: competition.name,
      champion_name: competition.result_summary.champion.name,
      runner_up_name: competition.result_summary.runner_up?.name ?? '-',
    }))
})

const formatClosedAt = (value) => {
  if (!value) {
    return '-'
  }

  return new Intl.DateTimeFormat('es-AR', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(new Date(value))
}

const isUnusedCompetition = (competition) =>
  Number(competition?.registrations_count ?? 0) === 0 && Number(competition?.games_count ?? 0) === 0

const competitions = ref([])
const isLoadingCompetitions = ref(false)
const competitionsErrorMessage = ref('')

const addButtonClasses =
  'inline-flex rounded-md border border-emerald-300 bg-emerald-50 p-2 text-emerald-700 hover:bg-emerald-100 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 dark:hover:bg-emerald-950/60'

const viewButtonClasses =
  'inline-flex rounded-md border border-blue-300 bg-blue-50 p-1.5 text-blue-700 hover:bg-blue-100 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-300 dark:hover:bg-blue-950/60'

const editButtonClasses =
  'inline-flex rounded-md border border-amber-300 bg-amber-50 p-1.5 text-amber-800 hover:bg-amber-100 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-300 dark:hover:bg-amber-950/60'

const deleteButtonClasses =
  'inline-flex cursor-not-allowed rounded-md border border-red-300 bg-red-50 p-1.5 text-red-700 opacity-50 dark:border-red-800 dark:bg-red-950/30 dark:text-red-300'

const manageButtonClasses =
  'inline-flex rounded-md border border-violet-400 bg-violet-600 p-1.5 text-white hover:bg-violet-700 dark:border-violet-500 dark:bg-violet-600 dark:hover:bg-violet-500'

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

const loadCompetitions = async () => {
  isLoadingCompetitions.value = true
  competitionsErrorMessage.value = ''

  try {
    competitions.value = await CompetitionService.listByTournament(route.params.id)
  } catch (error) {
    competitionsErrorMessage.value =
      error?.response?.data?.message || 'No se pudo cargar el listado de competencias.'
  } finally {
    isLoadingCompetitions.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadTournament(), loadCompetitions()])
})

const openEditModal = () => {
  editSuccessMessage.value = ''
  showEditModal.value = true
}

const handleEditClose = () => {
  showEditModal.value = false
}

const handleEditSaved = async () => {
  showEditModal.value = false
  editSuccessMessage.value = 'Torneo actualizado correctamente.'
  await loadTournament()
}

const openCreateCompetitionModal = () => {
  competitionModalMode.value = 'create'
  editingCompetition.value = null
  competitionSuccessMessage.value = ''
  showCompetitionModal.value = true
}

const openEditCompetitionModal = (competition) => {
  competitionModalMode.value = 'edit'
  editingCompetition.value = competition
  competitionSuccessMessage.value = ''
  showCompetitionModal.value = true
}

const handleCompetitionModalClose = () => {
  showCompetitionModal.value = false
}

const handleCompetitionSaved = async () => {
  showCompetitionModal.value = false
  competitionSuccessMessage.value =
    competitionModalMode.value === 'create'
      ? 'Competencia creada correctamente.'
      : 'Competencia actualizada correctamente.'
  await loadCompetitions()
}

const openFinalizeModal = () => {
  closeSuccessMessage.value = ''
  showFinalizeModal.value = true
}

const handleFinalizeClose = () => {
  showFinalizeModal.value = false
}

const handleFinalizeSaved = async () => {
  showFinalizeModal.value = false
  closeSuccessMessage.value = 'Torneo finalizado correctamente.'
  await Promise.all([loadTournament(), loadCompetitions()])
}
</script>

<template>
  <section class="min-w-0 space-y-4">
    <AppBreadcrumbs :context="{ tournamentId: route.params.id, tournamentName: tournament?.name }" />

    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">
        {{ tournament?.name || `Torneo #${route.params.id}` }}
      </h1>
      <div class="flex items-center gap-3">
        <button
          v-if="canFinalizeTournament"
          type="button"
          class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-600 dark:bg-emerald-600 dark:hover:bg-emerald-500"
          @click="openFinalizeModal"
        >
          Finalizar torneo
        </button>
        <button
          v-if="tournament && canManageTournaments"
          type="button"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
          @click="openEditModal"
        >
          Editar torneo
        </button>
        <RouterLink
          to="/tournaments"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100 dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
        >
          Volver a torneos
        </RouterLink>
      </div>
    </div>

    <p
      v-if="editSuccessMessage"
      class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100"
    >
      {{ editSuccessMessage }}
    </p>

    <p
      v-if="closeSuccessMessage"
      class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100"
    >
      {{ closeSuccessMessage }}
    </p>

    <p
      v-if="competitionSuccessMessage"
      class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100"
    >
      {{ competitionSuccessMessage }}
    </p>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-400">Cargando torneo...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <template v-else-if="tournament">
      <div
        class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
      >
        <p class="font-medium text-slate-900 dark:text-slate-100">Información del torneo</p>

        <div
          v-if="isTournamentClosed"
          class="mt-3 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-100"
        >
          Torneo finalizado
          <span v-if="tournament.closed_at" class="text-emerald-800 dark:text-emerald-200">
            · Cerrado el {{ formatClosedAt(tournament.closed_at) }}
          </span>
        </div>

        <dl class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Estado</dt>
            <dd class="mt-1">
              <span
                v-if="tournament.status"
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="getTournamentStatusBadgeClasses(tournament.status)"
              >
                {{ getTournamentStatusLabel(tournament.status) }}
              </span>
              <span v-else class="font-medium text-slate-900 dark:text-slate-100">-</span>
            </dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Fecha inicio</dt>
            <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100">
              {{ tournament.start_date || '-' }}
            </dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Fecha fin</dt>
            <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100">
              {{ tournament.end_date || '-' }}
            </dd>
          </div>

          <div v-if="tournament.closed_at">
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Fecha de cierre</dt>
            <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100">
              {{ formatClosedAt(tournament.closed_at) }}
            </dd>
          </div>

          <div>
            <dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Ubicación</dt>
            <dd class="mt-1 font-medium text-slate-900 dark:text-slate-100">
              {{ tournament.location || '-' }}
            </dd>
          </div>
        </dl>
      </div>

      <div
        v-if="isTournamentClosed || tournamentResults.length > 0"
        class="rounded-md border border-emerald-200 bg-gradient-to-b from-emerald-50 to-white p-4 text-sm dark:border-emerald-900 dark:from-emerald-950/30 dark:to-slate-900"
      >
        <p class="text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:text-emerald-300">
          Resultados del torneo
        </p>

        <div v-if="tournamentResults.length > 0" class="mt-4 space-y-3">
          <article
            v-for="result in tournamentResults"
            :key="result.competition_name"
            class="rounded-md border border-emerald-200 bg-white/80 p-4 dark:border-emerald-800 dark:bg-slate-900/50"
          >
            <p class="font-semibold text-slate-900 dark:text-slate-100">{{ result.competition_name }}</p>
            <p class="mt-2 text-slate-700 dark:text-slate-300">Campeón: {{ result.champion_name }}</p>
            <p class="text-slate-700 dark:text-slate-300">Subcampeón: {{ result.runner_up_name }}</p>
          </article>
        </div>

        <p v-else class="mt-3 text-slate-600 dark:text-slate-300">
          Todavía no hay resultados deportivos registrados para este torneo.
        </p>
      </div>

      <div class="space-y-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">Competencias</h2>
            <p class="mt-1 text-sm text-slate-600 dark:text-slate-400">
              Gestioná las categorías, formatos y fases de este torneo.
            </p>
          </div>
          <AppTooltip v-if="canCreateCompetition" label="Nueva competencia">
            <button
              type="button"
              :class="addButtonClasses"
              aria-label="Nueva competencia"
              @click="openCreateCompetitionModal"
            >
              <PlusIcon class="h-4 w-4" aria-hidden="true" />
            </button>
          </AppTooltip>
        </div>

        <p v-if="isLoadingCompetitions" class="text-sm text-slate-600 dark:text-slate-400">
          Cargando competencias...
        </p>
        <p v-else-if="competitionsErrorMessage" class="text-sm text-red-600 dark:text-red-400">
          {{ competitionsErrorMessage }}
        </p>

        <div
          v-else-if="competitions.length === 0"
          class="rounded-md border border-slate-200 bg-white p-4 text-sm dark:border-slate-700 dark:bg-slate-900"
        >
          <p class="text-slate-600 dark:text-slate-300">
            Este torneo todavía no tiene competencias cargadas.
          </p>
          <AppTooltip v-if="canCreateCompetition" label="Nueva competencia">
            <button
              type="button"
              :class="['mt-3', addButtonClasses]"
              aria-label="Nueva competencia"
              @click="openCreateCompetitionModal"
            >
              <PlusIcon class="h-4 w-4" aria-hidden="true" />
            </button>
          </AppTooltip>
        </div>

        <div
          v-else
          class="w-full overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
        >
          <table class="w-full divide-y divide-slate-200 dark:divide-slate-700">
            <thead class="bg-slate-50 dark:bg-slate-800">
              <tr>
                <th
                  class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
                >
                  Competencia
                </th>
                <th
                  class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
                >
                  Formato
                </th>
                <th
                  class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300"
                >
                  Estado
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
                v-for="competition in competitions"
                :key="competition.id"
                class="hover:bg-slate-50 dark:hover:bg-slate-800"
              >
                <td class="px-4 py-3 text-sm">
                  <p class="font-medium text-slate-900 dark:text-slate-100">{{ competition.name }}</p>
                  <p
                    v-if="competition.category || competition.type"
                    class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"
                  >
                    {{ [competition.category, getCompetitionTypeLabel(competition.type)].filter(Boolean).join(' · ') }}
                  </p>
                  <p
                    v-if="competition.result_summary?.champion?.name"
                    class="mt-1 text-xs font-medium text-emerald-700 dark:text-emerald-300"
                  >
                    Campeón: {{ competition.result_summary.champion.name }}
                  </p>
                  <p
                    v-else-if="isUnusedCompetition(competition)"
                    class="mt-1 text-xs text-slate-500 dark:text-slate-400"
                  >
                    Sin actividad
                  </p>
                </td>
                <td class="px-4 py-3 text-sm">
                  <p class="text-slate-900 dark:text-slate-100">{{ getStructurePrimary(competition) }}</p>
                  <p
                    v-if="getStructureSecondary(competition)"
                    class="mt-0.5 text-xs text-slate-500 dark:text-slate-400"
                  >
                    {{ getStructureSecondary(competition) }}
                  </p>
                </td>
                <td class="px-4 py-3 text-sm">
                  <span
                    v-if="competition.status_summary"
                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="getStatusBadgeClasses(competition)"
                  >
                    {{ getStatusLabel(competition) }}
                  </span>
                  <span v-else class="text-slate-400 dark:text-slate-500">-</span>
                </td>
                <td class="w-40 px-4 py-3 text-sm">
                  <div class="flex flex-nowrap items-center justify-end gap-1.5">
                    <AppTooltip label="Ver detalle">
                      <RouterLink
                        :to="`/competitions/${competition.id}`"
                        :class="viewButtonClasses"
                        aria-label="Ver detalle de la competencia"
                      >
                        <EyeIcon class="h-4 w-4" aria-hidden="true" />
                      </RouterLink>
                    </AppTooltip>

                    <AppTooltip v-if="canManageCompetitions && !isTournamentClosed" label="Editar">
                      <button
                        type="button"
                        :class="editButtonClasses"
                        aria-label="Editar competencia"
                        @click="openEditCompetitionModal(competition)"
                      >
                        <PencilSquareIcon class="h-4 w-4" aria-hidden="true" />
                      </button>
                    </AppTooltip>

                    <AppTooltip label="Eliminación no disponible aún">
                      <button
                        type="button"
                        disabled
                        :class="deleteButtonClasses"
                        aria-label="Eliminar competencia"
                      >
                        <TrashIcon class="h-4 w-4" aria-hidden="true" />
                      </button>
                    </AppTooltip>

                    <AppTooltip label="Gestionar">
                      <RouterLink
                        :to="`/competitions/${competition.id}`"
                        :class="manageButtonClasses"
                        aria-label="Gestionar competencia"
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
      </div>
    </template>

    <TournamentFormModal
      :show="showEditModal"
      mode="edit"
      :tournament="tournament"
      :tournament-id="route.params.id"
      @close="handleEditClose"
      @saved="handleEditSaved"
    />

    <CompetitionFormModal
      :show="showCompetitionModal"
      :mode="competitionModalMode"
      :tournament-id="route.params.id"
      :competition="editingCompetition"
      :competition-id="editingCompetition?.id"
      @close="handleCompetitionModalClose"
      @saved="handleCompetitionSaved"
    />

    <FinalizeTournamentModal
      :show="showFinalizeModal"
      :tournament="tournament"
      :competitions="competitions"
      @close="handleFinalizeClose"
      @saved="handleFinalizeSaved"
    />
  </section>
</template>
