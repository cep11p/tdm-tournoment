<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'

import { isDoublesCompetition } from '../../shared/constants/competitionType'
import BaseModal from '../../shared/components/BaseModal.vue'
import CompetitionContextHint from './CompetitionContextHint.vue'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  registrations: {
    type: Array,
    default: () => [],
  },
  competition: {
    type: Object,
    default: null,
  },
  registrationsEditable: {
    type: Boolean,
    default: false,
  },
  registrationsRoute: {
    type: String,
    required: true,
  },
  registrationsLockMessage: {
    type: String,
    default: null,
  },
})

defineEmits(['close'])

const isDoubles = computed(() => isDoublesCompetition(props.competition))

const participantCount = computed(() => props.registrations.length)

const modalDescription = computed(() => {
  const count = participantCount.value

  if (isDoubles.value) {
    if (count === 0) {
      return 'Todavía no hay parejas inscriptas.'
    }

    return `${count} pareja${count === 1 ? '' : 's'} inscripta${count === 1 ? '' : 's'}.`
  }

  if (count === 0) {
    return 'Todavía no hay jugadores inscriptos.'
  }

  return `${count} jugador${count === 1 ? '' : 'es'} inscripto${count === 1 ? '' : 's'}.`
})

const emptyStateMessage = computed(() =>
  isDoubles.value
    ? 'Esta competencia todavía no tiene parejas inscriptas.'
    : 'Esta competencia todavía no tiene inscriptos.',
)

const formatParticipantName = (registration) => {
  if (isDoubles.value) {
    return registration?.display_name || 'Pareja desconocida'
  }

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
    :title="isDoubles ? 'Parejas inscriptas' : 'Participantes'"
    :description="modalDescription"
    size="lg"
    @close="$emit('close')"
  >
    <CompetitionContextHint
      v-if="!registrationsEditable && registrationsLockMessage"
      :message="registrationsLockMessage"
      variant="warning"
      use-lock-icon
      class="mb-3"
    />

    <div
      v-if="registrations.length === 0"
      class="rounded-md border border-slate-200 p-4 text-slate-600 dark:border-slate-700 dark:text-slate-300"
    >
      {{ emptyStateMessage }}
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
        <p v-if="!isDoubles" class="text-slate-600 dark:text-slate-400">
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
