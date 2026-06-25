<script setup>
import { ref, watch } from 'vue'

import {
  GROUP_PLAYER_STATUS_REASONS,
  GROUP_PLAYER_STATUSES,
} from '../constants/groupPlayerStatus'
import GroupService from '../services/GroupService'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  groupId: {
    type: [String, Number],
    required: true,
  },
  player: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['close', 'saved'])

const status = ref('withdrawn')
const reason = ref('')
const notes = ref('')
const isSaving = ref(false)
const errorMessage = ref('')

const playerName = (player) => {
  if (!player?.id) {
    return 'Jugador no asignado'
  }

  return `${player.first_name} ${player.last_name}`.trim()
}

const extractGroupPlayerStatusError = (error) => {
  const errors = error?.response?.data?.errors ?? {}

  if (errors.group?.[0]) {
    return 'No se puede cambiar el estado porque el bracket ya fue creado.'
  }

  if (errors.player_id?.[0]?.includes('ya no está activo')) {
    return 'El jugador ya no está activo.'
  }

  return (
    errors.player_id?.[0] ||
    errors.status?.[0] ||
    errors.reason?.[0] ||
    error?.response?.data?.message ||
    'No se pudo actualizar el estado del jugador.'
  )
}

watch(
  () => [props.show, props.player?.id],
  () => {
    if (!props.show || !props.player) {
      return
    }

    status.value = 'withdrawn'
    reason.value = ''
    notes.value = ''
    errorMessage.value = ''
  },
  { immediate: true },
)

const handleClose = () => {
  if (isSaving.value) {
    return
  }

  emit('close')
}

const handleSubmit = async () => {
  if (!props.player?.id) {
    return
  }

  isSaving.value = true
  errorMessage.value = ''

  const payload = {
    player_id: props.player.id,
    status: status.value,
  }

  if (reason.value) {
    payload.reason = reason.value
  }

  const trimmedNotes = notes.value.trim()

  if (trimmedNotes) {
    payload.notes = trimmedNotes
  }

  try {
    await GroupService.setGroupPlayerStatus(props.groupId, payload)
    emit('saved')
  } catch (error) {
    errorMessage.value = extractGroupPlayerStatusError(error)
  } finally {
    isSaving.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="show && player"
      class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4"
      @click.self="handleClose"
    >
      <div
        class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-md border border-slate-200 bg-white p-4 text-sm shadow-xl dark:border-slate-700 dark:bg-slate-900"
        role="dialog"
        aria-modal="true"
        aria-labelledby="group-player-status-modal-title"
      >
        <div class="space-y-4">
          <div>
            <h2
              id="group-player-status-modal-title"
              class="text-lg font-semibold text-slate-900 dark:text-slate-100"
            >
              Cambiar estado del jugador
            </h2>
            <p class="mt-1 font-medium text-slate-900 dark:text-slate-100">
              {{ playerName(player) }}
            </p>
          </div>

          <label class="block">
            <span class="mb-1 block font-medium text-slate-700 dark:text-slate-200">Estado</span>
            <select
              v-model="status"
              class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
              :disabled="isSaving"
            >
              <option
                v-for="option in GROUP_PLAYER_STATUSES"
                :key="option.value"
                :value="option.value"
              >
                {{ option.label }}
              </option>
            </select>
          </label>

          <label class="block">
            <span class="mb-1 block font-medium text-slate-700 dark:text-slate-200">Motivo</span>
            <select
              v-model="reason"
              class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
              :disabled="isSaving"
            >
              <option
                v-for="option in GROUP_PLAYER_STATUS_REASONS"
                :key="option.value || 'empty'"
                :value="option.value"
              >
                {{ option.label }}
              </option>
            </select>
          </label>

          <label class="block">
            <span class="mb-1 block font-medium text-slate-700 dark:text-slate-200">
              Notas (opcional)
            </span>
            <textarea
              v-model="notes"
              rows="3"
              maxlength="500"
              class="w-full rounded-md border border-slate-300 px-3 py-2 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100"
              :disabled="isSaving"
              placeholder="Detalle adicional sobre el retiro o descalificación"
            />
          </label>

          <p class="rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
            Esta acción cerrará automáticamente los partidos pendientes o en curso del jugador a favor
            de sus rivales. No se modificarán partidos ya finalizados.
          </p>

          <p v-if="errorMessage" class="text-red-600 dark:text-red-400">{{ errorMessage }}</p>

          <div class="flex justify-end gap-2">
            <button
              type="button"
              class="rounded-md border border-slate-300 px-3 py-2 font-medium text-slate-700 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-70 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
              :disabled="isSaving"
              @click="handleClose"
            >
              Cancelar
            </button>
            <button
              type="button"
              class="rounded-md bg-emerald-700 px-3 py-2 font-medium text-white hover:bg-emerald-600 disabled:cursor-not-allowed disabled:opacity-70"
              :disabled="isSaving"
              @click="handleSubmit"
            >
              {{ isSaving ? 'Guardando...' : 'Confirmar' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
