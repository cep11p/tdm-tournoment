<script setup>
import { computed } from 'vue'

import { usePermissions } from '../../composables/usePermissions'
import { getTeamTieModalityLabel } from '../constants/teamTieModality'
import {
  getRubberMatchupLabel,
  getRubberSideDisplayName,
  getRubberStatusBadgeClasses,
  getRubberStatusLabel,
} from '../utils/teamTieGameDisplay'

const props = defineProps({
  rubber: {
    type: Object,
    required: true,
  },
  teamTie: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['edit-lineup', 'record-result'])

const { can } = usePermissions()
const canManageGroups = computed(() => can('groups.manage'))
const canRecordResults = computed(() => can('matches.record_result'))

const canEditLineup = computed(() => {
  if (!canManageGroups.value) {
    return false
  }

  const status = props.rubber?.game?.status
  return status === 'pending'
})

const canRecordResult = computed(() => {
  if (!canRecordResults.value || !props.rubber?.lineup_complete) {
    return false
  }

  return props.rubber?.game?.status !== 'finished'
})

const matchupLabel = computed(() => {
  if (!props.rubber?.lineup_complete) {
    return 'Lineup pendiente'
  }

  return getRubberMatchupLabel(props.rubber)
})
</script>

<template>
  <article class="rounded-md border border-slate-200 bg-white p-4 dark:border-slate-700 dark:bg-slate-900">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0 flex-1">
        <p class="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">
          {{ rubber.slot_order }}. {{ getTeamTieModalityLabel(rubber.modality) }}
        </p>

        <div
          v-if="rubber.lineup_complete"
          class="mt-2 space-y-1 text-sm text-slate-800 dark:text-slate-100"
        >
          <p>{{ getRubberSideDisplayName(rubber, 'entry1') }}</p>
          <p class="text-xs text-slate-500 dark:text-slate-400">vs</p>
          <p>{{ getRubberSideDisplayName(rubber, 'entry2') }}</p>
        </div>
        <p v-else class="mt-2 text-sm text-slate-600 dark:text-slate-300">{{ matchupLabel }}</p>
      </div>

      <span
        class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
        :class="getRubberStatusBadgeClasses(rubber)"
      >
        {{ getRubberStatusLabel(rubber) }}
      </span>
    </div>

    <div class="mt-3 flex flex-wrap gap-2">
      <button
        v-if="canEditLineup"
        type="button"
        class="rounded-md border border-slate-300 px-3 py-1.5 text-xs font-medium text-slate-700 dark:border-slate-600 dark:text-slate-200"
        @click="emit('edit-lineup', rubber)"
      >
        {{ rubber.lineup_complete ? 'Editar jugadores' : 'Definir jugadores' }}
      </button>

      <button
        v-if="canRecordResult"
        type="button"
        class="rounded-md bg-sky-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-sky-700"
        @click="emit('record-result', rubber)"
      >
        Cargar resultado
      </button>
    </div>
  </article>
</template>
