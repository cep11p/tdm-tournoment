<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const props = defineProps({
  context: {
    type: Object,
    default: () => ({}),
  },
})

const route = useRoute()

const breadcrumbItems = computed(() => {
  const context = props.context || {}

  const tournamentId = context.tournamentId
  const tournamentName = context.tournamentName
  const competitionId = context.competitionId ?? route.params.id
  const competitionName = context.competitionName
  const groupId = context.groupId ?? route.params.id
  const groupName = context.groupName
  const gameId = context.gameId ?? route.params.id
  const gameName = context.gameName

  const items = [{ label: 'Tournaments', to: '/tournaments' }]

  switch (route.name) {
    case 'tournaments-detail':
      items.push({
        label: tournamentName || `Torneo #${route.params.id}`,
      })
      break

    case 'competitions-detail':
      if (tournamentId) {
        items.push({
          label: tournamentName || `Torneo #${tournamentId}`,
          to: `/tournaments/${tournamentId}`,
        })
      }

      items.push({
        label: competitionName || `Competencia #${competitionId}`,
      })
      break

    case 'competitions-registrations':
      if (tournamentId) {
        items.push({
          label: tournamentName || `Torneo #${tournamentId}`,
          to: `/tournaments/${tournamentId}`,
        })
      }

      items.push({
        label: competitionName || `Competencia #${competitionId}`,
        to: `/competitions/${competitionId}`,
      })
      items.push({ label: 'Inscripciones' })
      break

    case 'groups-detail':
      if (tournamentId) {
        items.push({
          label: tournamentName || `Torneo #${tournamentId}`,
          to: `/tournaments/${tournamentId}`,
        })
      }

      if (competitionId) {
        items.push({
          label: competitionName || `Competencia #${competitionId}`,
          to: `/competitions/${competitionId}`,
        })
      }

      items.push({
        label: groupName || `Grupo #${groupId}`,
      })
      break

    case 'groups-standings':
      if (tournamentId) {
        items.push({
          label: tournamentName || `Torneo #${tournamentId}`,
          to: `/tournaments/${tournamentId}`,
        })
      }

      if (competitionId) {
        items.push({
          label: competitionName || `Competencia #${competitionId}`,
          to: `/competitions/${competitionId}`,
        })
      }

      items.push({
        label: groupName || `Grupo #${groupId}`,
        to: `/groups/${groupId}`,
      })
      items.push({
        label: 'Posiciones',
      })
      break

    case 'games-detail':
      if (tournamentId) {
        items.push({
          label: tournamentName || `Torneo #${tournamentId}`,
          to: `/tournaments/${tournamentId}`,
        })
      }

      if (competitionId) {
        items.push({
          label: competitionName || `Competencia #${competitionId}`,
          to: `/competitions/${competitionId}`,
        })
        items.push({
          label: 'Partidos',
          to: `/competitions/${competitionId}/games`,
        })
      }

      items.push({
        label: gameName || `Partido #${gameId}`,
      })
      break

    case 'competitions-bracket':
      if (tournamentId) {
        items.push({
          label: tournamentName || `Torneo #${tournamentId}`,
          to: `/tournaments/${tournamentId}`,
        })
      }

      items.push({
        label: competitionName || `Competencia #${competitionId}`,
        to: `/competitions/${competitionId}`,
      })
      items.push({ label: 'Bracket' })
      break

    default:
      break
  }

  return items
})
</script>

<template>
  <nav aria-label="breadcrumb" class="text-sm text-slate-500 dark:text-slate-400">
    <ol class="flex flex-wrap items-center gap-2">
      <li v-for="(item, index) in breadcrumbItems" :key="`${item.label}-${index}`" class="flex items-center gap-2">
        <RouterLink
          v-if="item.to && index !== breadcrumbItems.length - 1"
          :to="item.to"
          class="hover:text-slate-900 hover:underline dark:hover:text-slate-100"
        >
          {{ item.label }}
        </RouterLink>
        <span v-else class="font-medium text-slate-700 dark:text-slate-200">{{ item.label }}</span>

        <span v-if="index !== breadcrumbItems.length - 1" class="text-slate-400 dark:text-slate-500">/</span>
      </li>
    </ol>
  </nav>
</template>
