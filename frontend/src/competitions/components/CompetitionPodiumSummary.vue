<script setup>
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
        {{ resultSummary.champion.name }}
      </p>
      <span v-else class="font-medium text-slate-900 dark:text-slate-100">
        {{ resultSummary.champion.name }}
      </span>
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
        {{ resultSummary.runner_up.name }}
      </p>
      <span v-else class="font-medium text-slate-900 dark:text-slate-100">
        {{ resultSummary.runner_up.name }}
      </span>
    </article>

    <article
      v-for="(entry, index) in thirdPlaceEntries(resultSummary)"
      :key="`${entry.id}-${index}`"
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
        {{ entry.name }}
      </p>
      <span v-else class="font-medium text-slate-900 dark:text-slate-100">
        {{ entry.name }}
      </span>
    </article>
  </div>
</template>
