<script setup>
import { getGroupPlayerStatusLabel } from '../../groups/constants/groupPlayerStatus'
import {
  getQualificationBadgeClasses,
  getQualificationIcon,
  getQualificationLabel,
  getQualificationRowClasses,
} from '../utils/resolveGroupQualification'

defineProps({
  rows: {
    type: Array,
    default: () => [],
  },
  isTeam: {
    type: Boolean,
    default: false,
  },
})

const positionBadgeClasses = (position) => {
  if (position === 1) {
    return 'bg-amber-100 text-amber-900 ring-1 ring-amber-200 dark:bg-amber-900/50 dark:text-amber-200 dark:ring-amber-800'
  }

  if (position === 2) {
    return 'bg-slate-200 text-slate-800 ring-1 ring-slate-300 dark:bg-slate-600 dark:text-slate-100 dark:ring-slate-500'
  }

  if (position === 3) {
    return 'bg-orange-100 text-orange-900 ring-1 ring-orange-200 dark:bg-orange-900/50 dark:text-orange-200 dark:ring-orange-800'
  }

  return 'bg-slate-100 text-slate-700 ring-1 ring-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:ring-slate-700'
}

const playerStatusBadgeClasses = (status) => {
  if (status === 'withdrawn') {
    return 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200'
  }

  if (status === 'disqualified') {
    return 'bg-red-100 text-red-800 dark:bg-red-900/50 dark:text-red-200'
  }

  return ''
}

const rowClasses = (row) =>
  getQualificationRowClasses(row.qualification?.kind, {
    isInactive: (row.groupPlayerStatus ?? 'active') !== 'active',
  })

const playerStatusLabel = (row) => getGroupPlayerStatusLabel(row?.groupPlayerStatus ?? 'active')
</script>

<template>
  <div
    class="overflow-x-auto rounded-md border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-900"
  >
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50 text-slate-700 dark:bg-slate-800/80 dark:text-slate-200">
        <tr>
          <th class="px-3 py-2 text-left font-medium">Pos</th>
          <th class="px-3 py-2 text-left font-medium">{{ isTeam ? 'Equipo' : 'Participante' }}</th>
          <th class="px-3 py-2 text-left font-medium">{{ isTeam ? 'EJ' : 'PJ' }}</th>
          <th class="px-3 py-2 text-left font-medium">{{ isTeam ? 'EG' : 'PG' }}</th>
          <th class="px-3 py-2 text-left font-medium">{{ isTeam ? 'EP' : 'PP' }}</th>
          <slot name="extra-headers" />
          <th class="px-3 py-2 text-left font-medium">Estado</th>
          <th v-if="$slots.actions" class="px-3 py-2 text-right font-medium">Acciones</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="row in rows"
          :key="row.competitionEntryId ?? `position-${row.position}`"
          :class="rowClasses(row)"
        >
          <td class="px-3 py-2">
            <span
              class="inline-flex min-w-[2.5rem] items-center justify-center rounded-full px-2 py-0.5 text-xs font-semibold"
              :class="positionBadgeClasses(row.position)"
            >
              {{ row.position }}°
            </span>
          </td>
          <td class="px-3 py-2 font-medium text-slate-900 dark:text-slate-100">
            <div class="flex flex-wrap items-center gap-2">
              <div class="min-w-0">
                <span>{{ row.displayName }}</span>
                <p
                  v-if="row.membersLabel"
                  class="mt-0.5 text-xs font-normal text-slate-500 dark:text-slate-400"
                >
                  {{ row.membersLabel }}
                </p>
                <slot name="after-name" :row="row" />
              </div>
              <span
                v-if="row.manualTiebreakApplied"
                class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900 dark:bg-amber-900/50 dark:text-amber-200"
              >
                Desempate manual
              </span>
              <span
                v-if="playerStatusLabel(row)"
                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                :class="playerStatusBadgeClasses(row.groupPlayerStatus)"
              >
                {{ playerStatusLabel(row) }}
              </span>
            </div>
          </td>
          <td class="whitespace-nowrap px-3 py-2 text-slate-700 dark:text-slate-300">
            {{ row.played }}
          </td>
          <td class="whitespace-nowrap px-3 py-2 text-slate-700 dark:text-slate-300">
            {{ row.won }}
          </td>
          <td class="whitespace-nowrap px-3 py-2 text-slate-700 dark:text-slate-300">
            {{ row.lost }}
          </td>
          <slot name="extra-cells" :row="row" />
          <td class="px-3 py-2">
            <span
              class="inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium"
              :class="getQualificationBadgeClasses(row.qualification?.kind)"
            >
              <span v-if="getQualificationIcon(row.qualification?.kind)" aria-hidden="true">
                {{ getQualificationIcon(row.qualification?.kind) }}
              </span>
              {{ getQualificationLabel(row.qualification?.kind) }}
            </span>
          </td>
          <td v-if="$slots.actions" class="px-3 py-2">
            <div class="flex items-center justify-end gap-1">
              <slot name="actions" :row="row" />
            </div>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
