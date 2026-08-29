<script setup>
import {
  getCompetitionResultDisplayName,
  getCompetitionResultKey,
  getCompetitionResultMembers,
} from '../utils/competitionResultDisplay'

defineProps({
  resultSummary: {
    type: Object,
    required: true,
  },
  compact: {
    type: Boolean,
    default: false,
  },
})

const thirdPlaceEntries = (resultSummary) => resultSummary?.third_place ?? []

const isPlayoffPendingThirdPlace = (resultSummary) =>
  resultSummary?.third_place_mode === 'playoff'
  && resultSummary?.third_place_game_id
  && thirdPlaceEntries(resultSummary).length === 0
  && !resultSummary?.fourth_place

const memberLine = (result) => {
  const members = getCompetitionResultMembers(result)

  if (members.length <= 1) {
    return null
  }

  return members
    .map((member) => `${member.first_name ?? ''} ${member.last_name ?? ''}`.trim())
    .filter(Boolean)
    .join(' · ')
}
</script>

<template>
  <div :class="compact ? 'space-y-1 text-sm' : 'grid gap-3 sm:grid-cols-2'">
    <article
      :class="
        compact
          ? ''
          : 'rounded-md border border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/40'
      "
    >
      <p
        :class="
          compact
            ? 'text-slate-700 dark:text-slate-300'
            : 'text-xs font-semibold uppercase tracking-wide text-emerald-800 dark:text-emerald-300'
        "
      >
        {{ compact ? 'Campeón:' : '🏆 Campeón' }}
      </p>
      <p
        v-if="!compact"
        class="mt-2 text-xl font-bold text-slate-900 dark:text-slate-100"
      >
        {{ getCompetitionResultDisplayName(resultSummary.champion) }}
      </p>
      <span v-else class="font-medium text-slate-900 dark:text-slate-100">
        {{ getCompetitionResultDisplayName(resultSummary.champion) }}
      </span>
      <p
        v-if="!compact && memberLine(resultSummary.champion)"
        class="mt-1 text-sm text-slate-600 dark:text-slate-400"
      >
        {{ memberLine(resultSummary.champion) }}
      </p>
    </article>

    <article
      :class="
        compact
          ? ''
          : 'rounded-md border border-slate-300 bg-slate-50 p-4 dark:border-slate-600 dark:bg-slate-800/60'
      "
    >
      <p
        :class="
          compact
            ? 'text-slate-700 dark:text-slate-300'
            : 'text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300'
        "
      >
        {{ compact ? 'Subcampeón:' : 'Subcampeón' }}
      </p>
      <p
        v-if="!compact"
        class="mt-2 text-lg font-semibold text-slate-900 dark:text-slate-100"
      >
        {{ getCompetitionResultDisplayName(resultSummary.runner_up) }}
      </p>
      <span v-else class="font-medium text-slate-900 dark:text-slate-100">
        {{ getCompetitionResultDisplayName(resultSummary.runner_up) }}
      </span>
      <p
        v-if="!compact && memberLine(resultSummary.runner_up)"
        class="mt-1 text-sm text-slate-600 dark:text-slate-400"
      >
        {{ memberLine(resultSummary.runner_up) }}
      </p>
    </article>

    <article
      v-for="(entry, index) in thirdPlaceEntries(resultSummary)"
      :key="getCompetitionResultKey(entry, index)"
      :class="
        compact
          ? ''
          : 'rounded-md border border-amber-300 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30 sm:col-span-2 sm:max-w-md'
      "
    >
      <p
        :class="
          compact
            ? 'text-slate-700 dark:text-slate-300'
            : 'text-xs font-semibold uppercase tracking-wide text-amber-800 dark:text-amber-300'
        "
      >
        {{ compact ? '3.º puesto:' : '🥉 3.º puesto' }}
      </p>
      <p
        v-if="!compact"
        class="mt-2 text-lg font-semibold text-slate-900 dark:text-slate-100"
      >
        {{ getCompetitionResultDisplayName(entry) }}
      </p>
      <span v-else class="font-medium text-slate-900 dark:text-slate-100">
        {{ getCompetitionResultDisplayName(entry) }}
      </span>
      <p
        v-if="!compact && memberLine(entry)"
        class="mt-1 text-sm text-slate-600 dark:text-slate-400"
      >
        {{ memberLine(entry) }}
      </p>
    </article>

    <article
      v-if="resultSummary.fourth_place"
      :class="
        compact
          ? ''
          : 'rounded-md border border-slate-300 bg-slate-50 p-4 dark:border-slate-600 dark:bg-slate-800/60 sm:col-span-2 sm:max-w-md'
      "
    >
      <p
        :class="
          compact
            ? 'text-slate-700 dark:text-slate-300'
            : 'text-xs font-semibold uppercase tracking-wide text-slate-600 dark:text-slate-300'
        "
      >
        {{ compact ? '4.º puesto:' : '4.º puesto' }}
      </p>
      <p
        v-if="!compact"
        class="mt-2 text-lg font-semibold text-slate-900 dark:text-slate-100"
      >
        {{ getCompetitionResultDisplayName(resultSummary.fourth_place) }}
      </p>
      <span v-else class="font-medium text-slate-900 dark:text-slate-100">
        {{ getCompetitionResultDisplayName(resultSummary.fourth_place) }}
      </span>
      <p
        v-if="!compact && memberLine(resultSummary.fourth_place)"
        class="mt-1 text-sm text-slate-600 dark:text-slate-400"
      >
        {{ memberLine(resultSummary.fourth_place) }}
      </p>
    </article>

    <p
      v-if="isPlayoffPendingThirdPlace(resultSummary)"
      :class="
        compact
          ? 'text-slate-600 dark:text-slate-400'
          : 'text-sm text-amber-800 dark:text-amber-200 sm:col-span-2'
      "
    >
      Tercer puesto pendiente
    </p>
  </div>
</template>
