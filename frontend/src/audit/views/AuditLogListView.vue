<script setup>
import { computed, onMounted, ref, watch } from 'vue'

import CompetitionService from '../../competitions/services/CompetitionService'
import TournamentService from '../../tournaments/services/TournamentService'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'
import { formatDateTime } from '../../shared/utils/formatDateTime'
import AuditLogDetailModal from '../components/AuditLogDetailModal.vue'
import AuditLogService from '../services/AuditLogService'
import { buildAuditSummary } from '../utils/buildAuditSummary'

const AUDIT_ACTIONS = [
  { value: '', label: 'Todas las acciones' },
  { value: 'groups.regenerated', label: 'Regeneración de grupos' },
  { value: 'bracket.created', label: 'Generación de llave' },
  { value: 'bracket.round_advanced', label: 'Avance de ronda' },
  { value: 'game.set_recorded', label: 'Registro de set' },
  { value: 'groups.player_status_changed', label: 'Cambio de estado de jugador' },
  { value: 'groups.manual_tiebreak_applied', label: 'Desempate manual' },
]

const LOG_NAMES = [
  { value: '', label: 'Todos los módulos' },
  { value: 'groups', label: 'Grupos' },
  { value: 'bracket', label: 'Llave' },
  { value: 'games', label: 'Partidos' },
]

const auditLogs = ref([])
const meta = ref({})
const tournaments = ref([])
const competitions = ref([])

const search = ref('')
const action = ref('')
const logName = ref('')
const fromDate = ref('')
const toDate = ref('')
const tournamentId = ref('')
const competitionId = ref('')

const currentPage = ref(1)
const perPage = 25

const isLoading = ref(false)
const isLoadingCompetitions = ref(false)
const errorMessage = ref('')

const selectedAuditLogId = ref(null)
const showDetailModal = ref(false)

const hasActiveFilters = computed(
  () =>
    Boolean(
      search.value ||
        action.value ||
        logName.value ||
        fromDate.value ||
        toDate.value ||
        tournamentId.value ||
        competitionId.value,
    ),
)

const isEmpty = computed(() => !isLoading.value && auditLogs.value.length === 0)
const emptyMessage = computed(() =>
  hasActiveFilters.value
    ? 'No se encontraron actividades con los filtros seleccionados.'
    : 'Todavía no hay actividades registradas.',
)

const canGoPrevious = computed(() => (meta.value.current_page ?? 1) > 1)
const canGoNext = computed(
  () => (meta.value.current_page ?? 1) < (meta.value.last_page ?? 1),
)
const currentPageLabel = computed(() => meta.value.current_page ?? 1)
const lastPageLabel = computed(() => meta.value.last_page ?? 1)

const moduleBadgeClass = (logNameValue) => {
  switch (logNameValue) {
    case 'groups':
      return 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-200'
    case 'bracket':
      return 'bg-violet-100 text-violet-800 dark:bg-violet-950/40 dark:text-violet-200'
    case 'games':
      return 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-200'
    default:
      return 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
  }
}

const actorName = (auditLog) => auditLog.actor?.name || auditLog.actor?.email || 'Sin usuario'

const contextLabel = (auditLog) => {
  const context = auditLog.context ?? {}
  const parts = [context.tournament_name, context.competition_name, context.group_name].filter(Boolean)

  return parts.length ? parts.join(' · ') : '-'
}

const buildQueryParams = () => {
  const params = {
    page: currentPage.value,
    per_page: perPage,
  }

  if (search.value.trim()) params.search = search.value.trim()
  if (action.value) params.action = action.value
  if (logName.value) params.log_name = logName.value
  if (fromDate.value) params.from = fromDate.value
  if (toDate.value) params.to = toDate.value
  if (tournamentId.value) params.tournament_id = tournamentId.value
  if (competitionId.value) params.competition_id = competitionId.value

  return params
}

const loadAuditLogs = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    const result = await AuditLogService.index(buildQueryParams())
    auditLogs.value = result.data
    meta.value = result.meta
  } catch (error) {
    auditLogs.value = []
    meta.value = {}
    errorMessage.value = extractApiErrorMessage(error, 'No se pudo cargar la auditoría.')
  } finally {
    isLoading.value = false
  }
}

const loadTournaments = async () => {
  try {
    tournaments.value = await TournamentService.list()
  } catch {
    tournaments.value = []
  }
}

const loadCompetitions = async () => {
  if (!tournamentId.value) {
    competitions.value = []
    return
  }

  isLoadingCompetitions.value = true

  try {
    competitions.value = await CompetitionService.listByTournament(tournamentId.value)
  } catch {
    competitions.value = []
  } finally {
    isLoadingCompetitions.value = false
  }
}

const handleSearch = async () => {
  currentPage.value = 1
  await loadAuditLogs()
}

const clearFilters = async () => {
  search.value = ''
  action.value = ''
  logName.value = ''
  fromDate.value = ''
  toDate.value = ''
  tournamentId.value = ''
  competitionId.value = ''
  competitions.value = []
  currentPage.value = 1
  await loadAuditLogs()
}

const openDetail = (auditLog) => {
  selectedAuditLogId.value = auditLog.id
  showDetailModal.value = true
}

const closeDetail = () => {
  showDetailModal.value = false
  selectedAuditLogId.value = null
}

const goToPreviousPage = async () => {
  if (!canGoPrevious.value) return
  currentPage.value -= 1
  await loadAuditLogs()
}

const goToNextPage = async () => {
  if (!canGoNext.value) return
  currentPage.value += 1
  await loadAuditLogs()
}

watch(tournamentId, async () => {
  competitionId.value = ''
  await loadCompetitions()
})

onMounted(async () => {
  await Promise.all([loadTournaments(), loadAuditLogs()])
})
</script>

<template>
  <section class="space-y-4">
    <div>
      <h1 class="text-2xl font-bold text-slate-900 dark:text-slate-100">Auditoría</h1>
      <p class="mt-1 text-sm text-slate-600 dark:text-slate-300">
        Consultá las operaciones críticas registradas en el torneo, con contexto y detalle de cambios.
      </p>
    </div>

    <form
      class="grid gap-3 rounded-md border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900 md:grid-cols-2 xl:grid-cols-4"
      @submit.prevent="handleSearch"
    >
      <label class="space-y-1 text-sm md:col-span-2 xl:col-span-2">
        <span class="font-medium text-slate-700 dark:text-slate-300">Búsqueda</span>
        <input
          v-model="search"
          type="search"
          maxlength="150"
          placeholder="Usuario, acción o contexto"
          class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-950"
        />
      </label>

      <label class="space-y-1 text-sm">
        <span class="font-medium text-slate-700 dark:text-slate-300">Acción</span>
        <select
          v-model="action"
          class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-950"
        >
          <option v-for="option in AUDIT_ACTIONS" :key="option.value || 'all-actions'" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>

      <label class="space-y-1 text-sm">
        <span class="font-medium text-slate-700 dark:text-slate-300">Módulo</span>
        <select
          v-model="logName"
          class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-950"
        >
          <option v-for="option in LOG_NAMES" :key="option.value || 'all-modules'" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>

      <label class="space-y-1 text-sm">
        <span class="font-medium text-slate-700 dark:text-slate-300">Desde</span>
        <input
          v-model="fromDate"
          type="date"
          class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-950"
        />
      </label>

      <label class="space-y-1 text-sm">
        <span class="font-medium text-slate-700 dark:text-slate-300">Hasta</span>
        <input
          v-model="toDate"
          type="date"
          class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-950"
        />
      </label>

      <label class="space-y-1 text-sm">
        <span class="font-medium text-slate-700 dark:text-slate-300">Torneo</span>
        <select
          v-model="tournamentId"
          class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-950"
        >
          <option value="">Todos</option>
          <option v-for="tournament in tournaments" :key="tournament.id" :value="tournament.id">
            {{ tournament.name }}
          </option>
        </select>
      </label>

      <label class="space-y-1 text-sm">
        <span class="font-medium text-slate-700 dark:text-slate-300">Competencia</span>
        <select
          v-model="competitionId"
          class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-600 dark:bg-slate-950"
          :disabled="!tournamentId || isLoadingCompetitions"
        >
          <option value="">Todas</option>
          <option v-for="competition in competitions" :key="competition.id" :value="competition.id">
            {{ competition.name }}
          </option>
        </select>
      </label>

      <div class="flex flex-wrap items-end gap-2 md:col-span-2 xl:col-span-4">
        <button
          type="submit"
          class="rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
          :disabled="isLoading"
        >
          Filtrar
        </button>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
          :disabled="isLoading || !hasActiveFilters"
          @click="clearFilters"
        >
          Limpiar filtros
        </button>
      </div>
    </form>

    <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando auditoría...</p>
    <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

    <div
      v-else-if="isEmpty"
      class="rounded-md border border-slate-200 bg-white p-4 text-sm text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300"
    >
      {{ emptyMessage }}
    </div>

    <div
      v-else
      class="overflow-hidden rounded-md border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
    >
      <table class="min-w-full divide-y divide-slate-200 dark:divide-slate-700">
        <thead class="bg-slate-50 dark:bg-slate-800">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
              Fecha
            </th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
              Usuario
            </th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
              Acción
            </th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
              Contexto
            </th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
              Resumen
            </th>
            <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300">
              Acciones
            </th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200 bg-white dark:divide-slate-700 dark:bg-slate-900">
          <tr
            v-for="auditLog in auditLogs"
            :key="auditLog.id"
            class="hover:bg-slate-50 dark:hover:bg-slate-800"
          >
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ formatDateTime(auditLog.occurred_at) }}
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ actorName(auditLog) }}
            </td>
            <td class="px-4 py-3 text-sm">
              <div class="space-y-1">
                <span
                  class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="moduleBadgeClass(auditLog.log_name)"
                >
                  {{ auditLog.category_label }}
                </span>
                <p class="font-medium text-slate-900 dark:text-slate-100">{{ auditLog.action_label }}</p>
              </div>
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ contextLabel(auditLog) }}
            </td>
            <td class="px-4 py-3 text-sm text-slate-700 dark:text-slate-300">
              {{ buildAuditSummary(auditLog) || '-' }}
            </td>
            <td class="px-4 py-3 text-sm">
              <button
                type="button"
                class="rounded-md border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
                @click="openDetail(auditLog)"
              >
                Ver detalle
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div
      v-if="!isLoading && !errorMessage && !isEmpty"
      class="flex items-center justify-between gap-3"
    >
      <p class="text-sm text-slate-600 dark:text-slate-300">
        Página {{ currentPageLabel }} de {{ lastPageLabel }}
      </p>

      <div class="flex gap-2">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
          :disabled="!canGoPrevious || isLoading"
          @click="goToPreviousPage"
        >
          Anterior
        </button>
        <button
          type="button"
          class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
          :disabled="!canGoNext || isLoading"
          @click="goToNextPage"
        >
          Siguiente
        </button>
      </div>
    </div>

    <AuditLogDetailModal
      :show="showDetailModal"
      :audit-log-id="selectedAuditLogId"
      @close="closeDetail"
    />
  </section>
</template>
