<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'

import BaseModal from '../../shared/components/BaseModal.vue'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  registrations: {
    type: Array,
    default: () => [],
  },
  registrationsEditable: {
    type: Boolean,
    default: false,
  },
  registrationsRoute: {
    type: String,
    required: true,
  },
})

defineEmits(['close'])

const participantCount = computed(() => props.registrations.length)

const modalDescription = computed(() => {
  const count = participantCount.value

  if (count === 0) {
    return 'Todavía no hay jugadores inscriptos.'
  }

  return `${count} jugador${count === 1 ? '' : 'es'} inscripto${count === 1 ? '' : 's'}.`
})

const formatParticipantName = (registration) => {
  const player = registration?.player

  if (!player) {
    return 'Jugador desconocido'
  }

  return `${player.first_name ?? ''} ${player.last_name ?? ''}`.trim()
}
</script>

<template>
  <BaseModal
    :show="show"
    title="Participantes"
    :description="modalDescription"
    size="lg"
    @close="$emit('close')"
  >
    <div
      v-if="registrations.length === 0"
      class="rounded-md border border-slate-200 p-4 text-slate-600 dark:border-slate-700 dark:text-slate-300"
    >
      Esta competencia todavía no tiene inscriptos.
    </div>

    <div
      v-else
      class="max-h-[min(60vh,28rem)] space-y-2 overflow-y-auto pr-1"
    >
      <article
        v-for="registration in registrations"
        :key="registration.id"
        class="rounded-md border border-slate-200 p-3 dark:border-slate-700 dark:bg-slate-950/30"
      >
        <p class="font-medium text-slate-900 dark:text-slate-100">
          {{ formatParticipantName(registration) }}
        </p>
        <p class="text-slate-600 dark:text-slate-400">
          Apodo: {{ registration.player?.nickname || '-' }}
        </p>
      </article>
    </div>

    <template #footer>
      <RouterLink
        v-if="registrationsEditable"
        :to="registrationsRoute"
        class="rounded-md border border-slate-300 px-3 py-2 font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
        @click="$emit('close')"
      >
        Administrar inscripciones
      </RouterLink>
      <button
        type="button"
        class="rounded-md border border-slate-300 px-3 py-2 font-medium text-slate-700 hover:bg-slate-50 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-800"
        @click="$emit('close')"
      >
        Cerrar
      </button>
    </template>
  </BaseModal>
</template>
