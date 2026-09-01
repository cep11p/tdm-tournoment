<script setup>
import { computed, ref, watch } from 'vue'

import { getManualTiebreakReasons } from '../constants/manualTiebreakReasons'
import StandingService from '../services/StandingService'

const props = defineProps({
  groupId: {
    type: [String, Number],
    required: true,
  },
  tiebreakGroup: {
    type: Object,
    required: true,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  isTeam: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits(['saved'])

const entryIds = ref([...(props.tiebreakGroup.entry_ids ?? props.tiebreakGroup.player_ids ?? [])])
const reason = ref('draw')
const notes = ref('')
const isSaving = ref(false)
const errorMessage = ref('')

const entryLabelsById = computed(() => {
  const labels = {}
  const ids = props.tiebreakGroup.entry_ids ?? props.tiebreakGroup.player_ids ?? []
  const names = props.tiebreakGroup.display_names ?? props.tiebreakGroup.player_names ?? []

  ids.forEach((entryId, index) => {
    labels[entryId] = names[index] ?? `Participación #${entryId}`
  })

  return labels
})

const tiebreakGroupKey = computed(() =>
  [...(props.tiebreakGroup.entry_ids ?? props.tiebreakGroup.player_ids ?? [])]
    .sort((left, right) => left - right)
    .join('-'),
)

const manualTiebreakReasons = computed(() => getManualTiebreakReasons(props.isTeam))

watch(tiebreakGroupKey, () => {
  entryIds.value = [...(props.tiebreakGroup.entry_ids ?? props.tiebreakGroup.player_ids ?? [])]
  reason.value = 'draw'
  notes.value = ''
  errorMessage.value = ''
})

const extractManualTiebreakError = (error) =>
  error?.response?.data?.errors?.entry_ids?.[0] ||
  error?.response?.data?.errors?.player_ids?.[0] ||
  error?.response?.data?.errors?.group?.[0] ||
  error?.response?.data?.errors?.reason?.[0] ||
  error?.response?.data?.message ||
  'No se pudo guardar el desempate manual.'

const moveEntry = (index, direction) => {
  const targetIndex = index + direction

  if (targetIndex < 0 || targetIndex >= entryIds.value.length) {
    return
  }

  const updated = [...entryIds.value]
  const [movedEntry] = updated.splice(index, 1)
  updated.splice(targetIndex, 0, movedEntry)
  entryIds.value = updated
}

const handleSubmit = async () => {
  if (props.disabled) {
    return
  }

  isSaving.value = true
  errorMessage.value = ''

  try {
    await StandingService.applyManualTiebreak(props.groupId, {
      entry_ids: entryIds.value,
      reason: reason.value,
      notes: notes.value || null,
    })

    emit('saved')
  } catch (error) {
    errorMessage.value = extractManualTiebreakError(error)
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <div
    class="space-y-3 rounded-md border border-amber-200 bg-amber-50/60 p-4 text-sm dark:border-amber-900 dark:bg-amber-950/20"
  >
    <p class="font-medium text-amber-900 dark:text-amber-200">
      Empate pendiente entre:
      {{
        (tiebreakGroup.display_names ?? tiebreakGroup.player_names)?.join(', ') ||
        'participaciones empatadas'
      }}
    </p>

    <ul class="space-y-2">
      <li
        v-for="(entryId, index) in entryIds"
        :key="entryId"
        class="flex items-center justify-between gap-3 rounded-md border border-amber-100 bg-white px-3 py-2 dark:border-amber-900/60 dark:bg-slate-900"
      >
        <span class="font-medium text-slate-900 dark:text-slate-100">
          {{ index + 1 }}. {{ entryLabelsById[entryId] }}
        </span>

        <div class="flex items-center gap-1">
          <button
            type="button"
            class="rounded-md border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
            :disabled="disabled || isSaving || index === 0"
            @click="moveEntry(index, -1)"
          >
            ↑
          </button>
          <button
            type="button"
            class="rounded-md border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
            :disabled="disabled || isSaving || index === entryIds.length - 1"
            @click="moveEntry(index, 1)"
          >
            ↓
          </button>
        </div>
      </li>
    </ul>

    <form class="space-y-3" @submit.prevent="handleSubmit">
      <label class="block">
        <span class="mb-1 block font-medium text-slate-700 dark:text-slate-200">Motivo</span>
        <select
          v-model="reason"
          class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
          :disabled="disabled || isSaving"
        >
          <option v-for="option in manualTiebreakReasons" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
        </select>
      </label>

      <label class="block">
        <span class="mb-1 block font-medium text-slate-700 dark:text-slate-200">Notas (opcional)</span>
        <textarea
          v-model="notes"
          rows="2"
          maxlength="500"
          class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
          :disabled="disabled || isSaving"
          placeholder="Ej.: definido por sorteo entre empatados"
        />
      </label>

      <p v-if="disabled" class="text-amber-800 dark:text-amber-200">
        No se puede definir desempate manual porque el cuadro eliminatorio ya fue creado.
      </p>

      <p v-if="errorMessage" class="text-red-600 dark:text-red-400">{{ errorMessage }}</p>

      <button
        type="submit"
        class="rounded-md bg-slate-900 px-3 py-2 font-medium text-white hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-70 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200"
        :disabled="disabled || isSaving"
      >
        {{ isSaving ? 'Guardando...' : 'Guardar desempate' }}
      </button>
    </form>
  </div>
</template>
