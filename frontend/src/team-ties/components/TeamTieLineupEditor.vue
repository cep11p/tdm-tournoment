<script setup>
import { computed, ref, watch } from 'vue'

import TeamTieGameService from '../services/TeamTieGameService'
import { getTeamTieModalityLabel } from '../constants/teamTieModality'
import {
  getRubberSideDisplayName,
  getRubberSidePlayers,
} from '../utils/teamTieGameDisplay'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  rubber: {
    type: Object,
    default: null,
  },
  teamTie: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['close', 'saved'])

const entry1PlayerIds = ref([])
const entry2PlayerIds = ref([])
const isSaving = ref(false)
const errorMessage = ref('')

const modality = computed(() => props.rubber?.modality ?? 'singles')
const requiredPerSide = computed(() => (modality.value === 'doubles' ? 2 : 1))
const entry1Members = computed(() => props.teamTie?.entry1?.members ?? [])
const entry2Members = computed(() => props.teamTie?.entry2?.members ?? [])

const memberLabel = (member) => {
  const name = `${member.first_name ?? ''} ${member.last_name ?? ''}`.trim()
  return name || `Jugador ${member.id}`
}

const resetForm = () => {
  const entry1 = getRubberSidePlayers(props.rubber, 'entry1')
  const entry2 = getRubberSidePlayers(props.rubber, 'entry2')

  entry1PlayerIds.value = Array.from({ length: requiredPerSide.value }, (_, index) => {
    return entry1[index]?.player_id ?? ''
  })
  entry2PlayerIds.value = Array.from({ length: requiredPerSide.value }, (_, index) => {
    return entry2[index]?.player_id ?? ''
  })
  errorMessage.value = ''
}

watch(
  () => [props.show, props.rubber?.id],
  () => {
    if (props.show) {
      resetForm()
    }
  },
  { immediate: true },
)

const payload = () => ({
  entry1_player_ids: entry1PlayerIds.value.map((value) => Number(value)).filter(Boolean),
  entry2_player_ids: entry2PlayerIds.value.map((value) => Number(value)).filter(Boolean),
})

const saveLineup = async () => {
  if (!props.rubber?.id) {
    return
  }

  isSaving.value = true
  errorMessage.value = ''

  try {
    await TeamTieGameService.setLineup(props.rubber.id, payload())
    emit('saved')
    emit('close')
  } catch (error) {
    errorMessage.value =
      error?.response?.data?.message ||
      error?.response?.data?.errors?.entry1_player_ids?.[0] ||
      error?.response?.data?.errors?.entry2_player_ids?.[0] ||
      'No se pudo guardar el plantel.'
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <div
    v-if="show"
    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
    @click.self="emit('close')"
  >
    <div class="w-full max-w-lg rounded-lg border border-slate-200 bg-white p-5 shadow-xl dark:border-slate-700 dark:bg-slate-900">
      <h3 class="text-lg font-semibold text-slate-900 dark:text-slate-100">
        Plantel · {{ getTeamTieModalityLabel(modality) }}
      </h3>
      <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
        Partido {{ rubber?.slot_order }} · {{ teamTie?.entry1?.display_name }} vs
        {{ teamTie?.entry2?.display_name }}
      </p>

      <div class="mt-4 space-y-4">
        <div>
          <p class="mb-2 text-sm font-medium text-slate-700 dark:text-slate-200">
            {{ teamTie?.entry1?.display_name }}
          </p>
          <div class="space-y-2">
            <select
              v-for="index in requiredPerSide"
              :key="`entry1-${index}`"
              v-model="entry1PlayerIds[index - 1]"
              class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800"
            >
              <option value="">Seleccionar jugador</option>
              <option
                v-for="member in entry1Members"
                :key="`entry1-member-${member.id}`"
                :value="member.id"
              >
                {{ memberLabel(member) }}
              </option>
            </select>
          </div>
        </div>

        <div>
          <p class="mb-2 text-sm font-medium text-slate-700 dark:text-slate-200">
            {{ teamTie?.entry2?.display_name }}
          </p>
          <div class="space-y-2">
            <select
              v-for="index in requiredPerSide"
              :key="`entry2-${index}`"
              v-model="entry2PlayerIds[index - 1]"
              class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-600 dark:bg-slate-800"
            >
              <option value="">Seleccionar jugador</option>
              <option
                v-for="member in entry2Members"
                :key="`entry2-member-${member.id}`"
                :value="member.id"
              >
                {{ memberLabel(member) }}
              </option>
            </select>
          </div>
        </div>
      </div>

      <p v-if="errorMessage" class="mt-3 text-sm text-red-600 dark:text-red-400">{{ errorMessage }}</p>

      <div class="mt-5 flex justify-end gap-2">
        <button
          type="button"
          class="rounded-md border border-slate-300 px-4 py-2 text-sm text-slate-700 dark:border-slate-600 dark:text-slate-200"
          @click="emit('close')"
        >
          Cancelar
        </button>
        <button
          type="button"
          class="rounded-md bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:opacity-60"
          :disabled="isSaving"
          @click="saveLineup"
        >
          {{ isSaving ? 'Guardando...' : 'Guardar plantel' }}
        </button>
      </div>
    </div>
  </div>
</template>
