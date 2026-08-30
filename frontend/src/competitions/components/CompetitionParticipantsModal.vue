<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'

import {
  getParticipantKind,
  participantPlural,
  participantSingular,
} from '../../shared/constants/competitionType'
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

const participantKind = computed(() => getParticipantKind(props.competition))

const participantCount = computed(() => props.registrations.length)

const modalTitle = computed(() => {
  const label = participantPlural(props.competition)

  return `${label.charAt(0).toUpperCase()}${label.slice(1)} inscriptos`
})

const modalDescription = computed(() => {
  const count = participantCount.value
  const label = participantPlural(props.competition)

  if (count === 0) {
    return `Todavía no hay ${label} inscriptos.`
  }

  return `${count} ${label} inscripto${count === 1 ? '' : 's'}.`
})

const emptyStateMessage = computed(() => {
  const label = participantPlural(props.competition)

  return `Esta competencia todavía no tiene ${label} inscriptos.`
})

const formatParticipantName = (registration) => {
  if (participantKind.value === 'team' || participantKind.value === 'pair') {
    return registration?.display_name || `${participantSingular(props.competition)} desconocido`
  }

  const player = registration?.player

  if (!player) {
    return 'Jugador desconocido'
  }

  return `${player.first_name ?? ''} ${player.last_name ?? ''}`.trim()
}

const formatParticipantDetails = (registration) => {
  if (participantKind.value !== 'team') {
    return null
  }

  const members = registration?.members ?? []

  if (members.length === 0) {
    return null
  }

  return members
    .map((member) => `${member.first_name ?? ''} ${member.last_name ?? ''}`.trim())
    .filter(Boolean)
    .join(', ')
}
</script>

<template>
  <BaseModal
    :show="show"
    :title="modalTitle"
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

    <ul v-else class="space-y-2">
      <li
        v-for="registration in registrations"
        :key="registration.id"
        class="rounded-md border border-slate-200 p-3 dark:border-slate-700"
      >
        <p class="font-medium text-slate-900 dark:text-slate-100">
          {{ formatParticipantName(registration) }}
        </p>
        <p v-if="participantKind === 'player'" class="text-slate-600 dark:text-slate-400">
          Apodo: {{ registration.player?.nickname || '-' }}
        </p>
        <p v-if="formatParticipantDetails(registration)" class="mt-1 text-sm text-slate-600 dark:text-slate-400">
          {{ formatParticipantDetails(registration) }}
        </p>
      </li>
    </ul>

    <template #footer>
      <RouterLink
        v-if="registrationsEditable"
        :to="registrationsRoute"
        class="rounded-md bg-emerald-700 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-600"
        @click="$emit('close')"
      >
        Gestionar inscripciones
      </RouterLink>
    </template>
  </BaseModal>
</template>
