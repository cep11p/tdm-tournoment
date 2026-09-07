<script setup>
import { CheckCircleIcon, ChevronDownIcon, MagnifyingGlassIcon } from '@heroicons/vue/24/outline'
import { computed, ref, watch } from 'vue'

import CompetitionContextHint from './CompetitionContextHint.vue'
import CompetitionCheckInService from '../services/CompetitionCheckInService'
import {
  applyMemberCheckIn,
  matchesCheckInFilter,
  matchesCheckInSearch,
  memberDisplayName,
  operationalMemberCount,
} from '../utils/checkInPresentation'
import { extractApiErrorMessage } from '../../shared/utils/extractApiErrorMessage'

const BULK_CONFIRM_THRESHOLD = 8

const props = defineProps({
  competitionId: {
    type: [String, Number],
    required: true,
  },
  canManage: {
    type: Boolean,
    default: false,
  },
  expanded: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['toggle', 'summary-change'])

const payload = ref(null)
const isLoading = ref(false)
const errorMessage = ref('')
const actionError = ref('')
const searchQuery = ref('')
const filter = ref('all')
const inFlightMemberIds = ref([])
const isBulkSaving = ref(false)

const competitionType = computed(() => payload.value?.competition?.type ?? 'singles')
const tournamentFinished = computed(() => Boolean(payload.value?.tournament_finished))
const canMutate = computed(() => props.canManage && !tournamentFinished.value)
const summary = computed(() => payload.value?.summary ?? null)

const summaryLabel = computed(() => {
  if (!summary.value) {
    return ''
  }

  const present = summary.value.checked_in_members
  const pending = summary.value.pending_members

  if (pending === 0) {
    return `${present} presente${present === 1 ? '' : 's'}`
  }

  return `${present} presente${present === 1 ? '' : 's'} · ${pending} pendiente${pending === 1 ? '' : 's'}`
})

const pendingBadgeLabel = computed(() => {
  const pending = summary.value?.pending_members ?? 0

  if (pending <= 0) {
    return null
  }

  return `${pending} pendiente${pending === 1 ? '' : 's'}`
})

const emitSummary = () => {
  emit('summary-change', {
    pendingMembers: summary.value?.pending_members ?? 0,
    checkedInMembers: summary.value?.checked_in_members ?? 0,
    tournamentFinished: tournamentFinished.value,
  })
}

const visibleEntries = computed(() => {
  const entries = payload.value?.entries ?? []

  return entries.filter(
    (entry) => matchesCheckInFilter(entry, filter.value) && matchesCheckInSearch(entry, searchQuery.value),
  )
})

const hasEntries = computed(() => (payload.value?.entries?.length ?? 0) > 0)

const loadCheckIn = async () => {
  if (!props.competitionId) {
    return
  }

  isLoading.value = true
  errorMessage.value = ''

  try {
    payload.value = await CompetitionCheckInService.get(props.competitionId)
    emitSummary()
  } catch (error) {
    errorMessage.value = extractApiErrorMessage(error, 'No se pudo cargar el check-in.')
    payload.value = null
    emit('summary-change', {
      pendingMembers: 0,
      checkedInMembers: 0,
      tournamentFinished: false,
    })
  } finally {
    isLoading.value = false
  }
}

watch(
  () => props.competitionId,
  () => {
    loadCheckIn()
  },
  { immediate: true },
)

const isMemberBusy = (memberId) => inFlightMemberIds.value.includes(memberId)

const canToggleMember = (entry) =>
  canMutate.value && entry?.status === 'active' && !isBulkSaving.value

const toggleMember = async (entry, member) => {
  if (!canToggleMember(entry) || isMemberBusy(member.id)) {
    return
  }

  const previous = payload.value
  const nextCheckedIn = !member.checked_in
  const optimisticMember = {
    ...member,
    checked_in: nextCheckedIn,
    checked_in_at: nextCheckedIn ? new Date().toISOString() : null,
  }

  payload.value = applyMemberCheckIn(previous, member.id, optimisticMember)
  inFlightMemberIds.value = [...inFlightMemberIds.value, member.id]
  actionError.value = ''

  try {
    const saved = nextCheckedIn
      ? await CompetitionCheckInService.checkIn(props.competitionId, member.id)
      : await CompetitionCheckInService.undoCheckIn(props.competitionId, member.id)

    payload.value = applyMemberCheckIn(payload.value, member.id, saved)
    emitSummary()
  } catch (error) {
    payload.value = previous
    actionError.value = extractApiErrorMessage(error, 'No se pudo actualizar el check-in.')
  } finally {
    inFlightMemberIds.value = inFlightMemberIds.value.filter((id) => id !== member.id)
  }
}

const confirmBulkIfNeeded = (label) => {
  const count = operationalMemberCount(payload.value)

  if (count < BULK_CONFIRM_THRESHOLD) {
    return true
  }

  return window.confirm(`${label} a ${count} integrantes. ¿Continuar?`)
}

const runBulk = async (action) => {
  if (!canMutate.value || isBulkSaving.value) {
    return
  }

  const label = action === 'mark_all_present' ? 'Marcar presentes' : 'Limpiar check-in'

  if (!confirmBulkIfNeeded(label)) {
    return
  }

  isBulkSaving.value = true
  actionError.value = ''

  try {
    payload.value =
      action === 'mark_all_present'
        ? await CompetitionCheckInService.markAllPresent(props.competitionId)
        : await CompetitionCheckInService.clearAll(props.competitionId)
    emitSummary()
  } catch (error) {
    actionError.value = extractApiErrorMessage(error, 'No se pudo actualizar el check-in.')
  } finally {
    isBulkSaving.value = false
  }
}

const availabilityBadgeClasses = (kind) => {
  if (kind === 'ready') {
    return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-200'
  }

  if (kind === 'inactive') {
    return 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200'
  }

  return 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200'
}
</script>

<template>
  <section class="overflow-hidden rounded-md border border-slate-200 bg-white text-sm dark:border-slate-700 dark:bg-slate-900">
    <button
      type="button"
      class="flex w-full cursor-pointer items-start gap-3 p-4 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/50"
      :aria-expanded="expanded"
      @click="emit('toggle')"
    >
      <span
        class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-slate-100 ring-1 ring-slate-200 dark:bg-slate-800/80 dark:ring-slate-600"
      >
        <CheckCircleIcon class="h-5 w-5 text-slate-600 dark:text-slate-300" />
      </span>

      <span class="min-w-0 flex-1">
        <span class="flex flex-wrap items-center gap-2">
          <span class="font-medium text-slate-900 dark:text-slate-100">Check-in</span>
          <span
            v-if="pendingBadgeLabel"
            class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/60 dark:text-amber-200"
          >
            {{ pendingBadgeLabel }}
          </span>
        </span>
        <span v-if="summary" class="mt-0.5 block text-xs font-medium text-slate-700 dark:text-slate-300">
          {{ summaryLabel }}
        </span>
        <span v-else-if="isLoading" class="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
          Cargando check-in...
        </span>
      </span>

      <ChevronDownIcon
        class="h-5 w-5 shrink-0 text-slate-400 transition-transform duration-200"
        :class="expanded ? 'rotate-180' : ''"
        aria-hidden="true"
      />
    </button>

    <div v-show="expanded" class="space-y-3 border-t border-slate-200 px-4 py-4 dark:border-slate-700">
      <CompetitionContextHint
        v-if="tournamentFinished"
        message="El check-in no se puede modificar porque el torneo está finalizado."
        variant="warning"
        use-lock-icon
      />

      <p v-if="isLoading" class="text-sm text-slate-600 dark:text-slate-300">Cargando check-in...</p>
      <p v-else-if="errorMessage" class="text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

      <template v-else>
        <p v-if="actionError" class="text-sm text-red-600 dark:text-red-400">{{ actionError }}</p>

        <div v-if="!hasEntries" class="text-sm text-slate-600 dark:text-slate-300">
          Todavía no hay inscriptos para hacer check-in.
        </div>

        <template v-else>
          <div class="flex flex-wrap items-center gap-2">
            <button
              v-for="option in [
                { id: 'all', label: 'Todos' },
                { id: 'present', label: 'Presentes' },
                { id: 'pending', label: 'Pendientes' },
              ]"
              :key="option.id"
              type="button"
              class="rounded-md px-3 py-1.5 text-xs font-medium"
              :class="
                filter === option.id
                  ? 'bg-slate-900 text-white dark:bg-slate-100 dark:text-slate-900'
                  : 'border border-slate-300 text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800'
              "
              @click="filter = option.id"
            >
              {{ option.label }}
            </button>
          </div>

          <label class="relative block">
            <MagnifyingGlassIcon
              class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-slate-400"
            />
            <input
              v-model="searchQuery"
              type="search"
              placeholder="Buscar..."
              class="w-full rounded-md border border-slate-300 bg-white py-2 pr-3 pl-9 text-sm text-slate-900 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
            />
          </label>

          <div v-if="canMutate" class="flex flex-wrap gap-2">
            <button
              type="button"
              class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
              :disabled="isBulkSaving"
              @click="runBulk('mark_all_present')"
            >
              Marcar todos presentes
            </button>
            <button
              type="button"
              class="rounded-md border border-slate-300 bg-white px-3 py-2 text-xs font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800"
              :disabled="isBulkSaving"
              @click="runBulk('clear_all')"
            >
              Limpiar check-in
            </button>
          </div>

          <p v-if="visibleEntries.length === 0" class="text-sm text-slate-600 dark:text-slate-300">
            No hay resultados para ese filtro.
          </p>

          <ul v-else class="space-y-2">
            <li
              v-for="entry in visibleEntries"
              :key="entry.id"
              class="rounded-md border border-slate-200 p-3 dark:border-slate-700"
            >
              <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                <p
                  v-if="competitionType !== 'singles'"
                  class="font-medium text-slate-900 dark:text-slate-100"
                >
                  {{ entry.display_name }}
                </p>
                <p v-else class="sr-only">{{ entry.display_name }}</p>
                <span
                  class="rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="availabilityBadgeClasses(entry.availability?.kind)"
                >
                  {{ entry.availability?.label }}
                </span>
              </div>

              <div class="space-y-1">
                <button
                  v-for="member in entry.members"
                  :key="member.id"
                  type="button"
                  class="flex w-full items-center gap-2 rounded-md px-1 py-1 text-left text-sm text-slate-800 dark:text-slate-100"
                  :class="
                    canToggleMember(entry)
                      ? 'hover:bg-slate-50 dark:hover:bg-slate-800/60'
                      : 'cursor-default opacity-80'
                  "
                  :disabled="!canToggleMember(entry) || isMemberBusy(member.id)"
                  @click="toggleMember(entry, member)"
                >
                  <span
                    class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full border text-xs"
                    :class="
                      member.checked_in
                        ? 'border-emerald-600 bg-emerald-600 text-white'
                        : 'border-slate-400 text-transparent dark:border-slate-500'
                    "
                    aria-hidden="true"
                  >
                    ✓
                  </span>
                  <span>{{ memberDisplayName(member) }}</span>
                </button>
              </div>
            </li>
          </ul>
        </template>
      </template>
    </div>
  </section>
</template>
