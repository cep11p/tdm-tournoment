<script setup>
import { computed } from 'vue'
import { RouterLink } from 'vue-router'

import BaseModal from '../../shared/components/BaseModal.vue'
import GroupStandingsTable from './GroupStandingsTable.vue'
import { buildGroupStandingRows } from '../utils/buildGroupStandingRows'

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  groupId: {
    type: [String, Number],
    default: null,
  },
  groupName: {
    type: String,
    default: '',
  },
  competitionId: {
    type: [String, Number],
    default: '',
  },
  standings: {
    type: Array,
    default: () => [],
  },
  meta: {
    type: Object,
    default: () => ({}),
  },
  qualifiedPerGroup: {
    type: Number,
    default: 2,
  },
  isTeam: {
    type: Boolean,
    default: false,
  },
  description: {
    type: String,
    default: '',
  },
  standingsAvailable: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['close'])

const title = computed(() => `Posiciones · ${props.groupName || 'Grupo'}`)

const rows = computed(() => {
  if (!props.standingsAvailable) {
    return []
  }

  return buildGroupStandingRows({
    standings: props.standings,
    meta: props.meta,
    qualifiedPerGroup: props.qualifiedPerGroup,
  })
})

const standingsRoute = computed(() => {
  const query = {}

  if (props.competitionId) {
    query.competitionId = String(props.competitionId)
  }

  if (props.groupName) {
    query.groupName = props.groupName
  }

  return {
    name: 'groups-standings',
    params: { id: String(props.groupId) },
    query,
  }
})
</script>

<template>
  <BaseModal
    :show="show"
    :title="title"
    :description="description"
    size="xl"
    @close="emit('close')"
  >
    <p v-if="!standingsAvailable" class="text-sm text-red-600 dark:text-red-400">
      No se pudieron cargar las posiciones de este grupo.
    </p>

    <p
      v-else-if="rows.length === 0"
      class="rounded-md border border-slate-200 bg-slate-50 p-3 text-slate-600 dark:border-slate-700 dark:bg-slate-800/50 dark:text-slate-300"
    >
      Este grupo todavía no tiene posiciones para mostrar.
    </p>

    <GroupStandingsTable
      v-else
      :rows="rows"
      :is-team="isTeam"
      :qualifier-cutoff="qualifiedPerGroup"
    />

    <template #footer>
      <RouterLink
        :to="standingsRoute"
        class="text-sm font-medium text-slate-700 hover:underline dark:text-slate-300"
      >
        Ver posiciones completas
      </RouterLink>
    </template>
  </BaseModal>
</template>
