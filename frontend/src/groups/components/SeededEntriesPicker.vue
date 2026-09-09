<script setup>
import { computed, watch } from 'vue'

import { participantPluralForKind, participantSingularForKind } from '../../shared/constants/competitionType'

const props = defineProps({
  entries: {
    type: Array,
    default: () => [],
  },
  groupsCount: {
    type: Number,
    default: 0,
  },
  modelValue: {
    type: Array,
    default: () => [],
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  participantKind: {
    type: String,
    default: 'player',
    validator: (value) => ['player', 'pair', 'team'].includes(value),
  },
})

const emit = defineEmits(['update:modelValue'])

const eligibleEntries = computed(() =>
  props.entries.filter((entry) => {
    if (entry == null || entry.id == null) {
      return false
    }

    return entry.status == null || entry.status === 'active'
  }),
)

const selectedIds = computed(() => props.modelValue.map(Number).filter((id) => Number.isInteger(id) && id > 0))

const maxSeeds = computed(() => Math.max(0, Number.isInteger(props.groupsCount) ? props.groupsCount : 0))

const canSelectMore = computed(() => selectedIds.value.length < maxSeeds.value)

const groupNameForIndex = (index) => {
  const groupNumber = index + 1

  if (groupNumber <= 26) {
    return `Grupo ${String.fromCharCode(64 + groupNumber)}`
  }

  return `Grupo ${groupNumber}`
}

const selectedGroupByEntryId = computed(() => {
  const map = {}

  selectedIds.value.forEach((id, index) => {
    map[id] = groupNameForIndex(index)
  })

  return map
})

const selectionSummary = computed(() => {
  const selected = selectedIds.value.length
  const max = maxSeeds.value
  const selectedLabel = selected === 1 ? '1 cabeza' : `${selected} cabezas`

  return `${selectedLabel} de ${max}`
})

const description = computed(() => {
  const participants = participantPluralForKind(props.participantKind)

  return `Seleccioná ${participants} que querés mantener separados. Cada cabeza de serie irá a un grupo diferente y el resto se distribuirá aleatoriamente.`
})

const entryLabel = (entry) =>
  entry.display_name || `${participantSingularForKind(props.participantKind)} #${entry.id}`

const isSelected = (entryId) => selectedIds.value.includes(Number(entryId))

const toggle = (entryId) => {
  if (props.disabled) {
    return
  }

  const id = Number(entryId)
  const current = [...selectedIds.value]
  const index = current.indexOf(id)

  if (index >= 0) {
    current.splice(index, 1)
    emit('update:modelValue', current)
    return
  }

  if (!canSelectMore.value) {
    return
  }

  emit('update:modelValue', [...current, id])
}

watch(
  () => props.groupsCount,
  (count) => {
    const max = Math.max(0, Number.isInteger(count) ? count : 0)

    if (selectedIds.value.length > max) {
      emit('update:modelValue', selectedIds.value.slice(0, max))
    }
  },
)
</script>

<template>
  <fieldset class="space-y-2">
    <legend class="font-medium text-slate-700 dark:text-slate-200">Cabezas de serie</legend>
    <p class="text-slate-600 dark:text-slate-300">
      {{ description }}
    </p>
    <p class="text-xs text-slate-500 dark:text-slate-400">
      {{ selectionSummary }}
    </p>

    <div
      v-if="eligibleEntries.length === 0"
      class="rounded-md border border-dashed border-slate-300 px-3 py-2 text-slate-500 dark:border-slate-600 dark:text-slate-400"
    >
      No hay {{ participantPluralForKind(participantKind) }} activos para seleccionar.
    </div>

    <ul
      v-else
      class="max-h-56 space-y-1 overflow-y-auto rounded-md border border-slate-200 p-2 dark:border-slate-700"
    >
      <li v-for="entry in eligibleEntries" :key="entry.id">
        <label
          class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 hover:bg-slate-50 dark:hover:bg-slate-800/80"
          :class="{
            'cursor-not-allowed opacity-60': disabled || (!isSelected(entry.id) && !canSelectMore),
          }"
        >
          <input
            type="checkbox"
            class="rounded border-slate-300 text-emerald-700 focus:ring-emerald-600"
            :checked="isSelected(entry.id)"
            :disabled="disabled || (!isSelected(entry.id) && !canSelectMore)"
            @change="toggle(entry.id)"
          />
          <span class="min-w-0 flex-1 text-slate-800 dark:text-slate-100">
            {{ entryLabel(entry) }}
          </span>
          <span
            v-if="selectedGroupByEntryId[entry.id]"
            class="shrink-0 text-xs font-medium text-emerald-700 dark:text-emerald-300"
          >
            {{ selectedGroupByEntryId[entry.id] }}
          </span>
        </label>
      </li>
    </ul>
  </fieldset>
</template>
