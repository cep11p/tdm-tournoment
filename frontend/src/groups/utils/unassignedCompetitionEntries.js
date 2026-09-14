import { participantSingularForKind } from '../../shared/constants/competitionType.js'

const toPositiveInt = (value) => {
  const id = Number(value)

  return Number.isInteger(id) && id > 0 ? id : null
}

const isActiveEntry = (entry) => entry?.status == null || entry.status === 'active'

/**
 * @param {Array<{group_players?: Array<{competition_entry_id?: number}>}>} groups
 * @param {Array<{competition_entry_id?: number}>} extraGroupPlayers
 * @returns {Set<number>}
 */
export function collectAssignedEntryIds(groups = [], extraGroupPlayers = []) {
  const ids = new Set()

  for (const group of groups) {
    for (const groupPlayer of group?.group_players ?? []) {
      const id = toPositiveInt(groupPlayer?.competition_entry_id)

      if (id) {
        ids.add(id)
      }
    }
  }

  for (const groupPlayer of extraGroupPlayers) {
    const id = toPositiveInt(groupPlayer?.competition_entry_id)

    if (id) {
      ids.add(id)
    }
  }

  return ids
}

/**
 * Entries inscriptas, activas y todavía no asignadas a ningún grupo.
 *
 * @param {Array<{id?: number, status?: string}>} registrations
 * @param {Iterable<number>|Set<number>} assignedEntryIds
 * @returns {Array}
 */
export function listUnassignedEntries(registrations = [], assignedEntryIds = []) {
  const assigned =
    assignedEntryIds instanceof Set ? assignedEntryIds : new Set([...assignedEntryIds].map(toPositiveInt).filter(Boolean))

  return registrations.filter((entry) => {
    const id = toPositiveInt(entry?.id)

    if (!id || !isActiveEntry(entry)) {
      return false
    }

    return !assigned.has(id)
  })
}

/**
 * Reutiliza display_name del resource. Fallback alineado con inscripciones.
 *
 * @param {{display_name?: string, player?: {first_name?: string, last_name?: string}, members?: Array<{first_name?: string, last_name?: string}>, id?: number}} entry
 * @param {{participantKind?: 'player'|'pair'|'team'}} [options]
 * @returns {string}
 */
export function formatEntryDisplayName(entry, { participantKind = 'player' } = {}) {
  if (entry?.display_name) {
    return entry.display_name
  }

  if (participantKind === 'player') {
    const player = entry?.player
    const playerName = `${player?.first_name ?? ''} ${player?.last_name ?? ''}`.trim()

    if (playerName) {
      return playerName
    }
  }

  const memberNames = (entry?.members ?? [])
    .map((member) => `${member?.first_name ?? ''} ${member?.last_name ?? ''}`.trim())
    .filter(Boolean)

  if (memberNames.length > 0) {
    return memberNames.join(' / ')
  }

  return `${participantSingularForKind(participantKind)} #${entry?.id ?? ''}`.trim()
}
