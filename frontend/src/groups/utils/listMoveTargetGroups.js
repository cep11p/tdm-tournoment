import { sortCompetitionGroupsByName } from './groupResultNavigation.js'

const toPositiveInt = (value) => {
  const id = Number(value)

  return Number.isInteger(id) && id > 0 ? id : null
}

/**
 * Grupos destino para mover: misma competencia, distinto al actual, orden por name.
 *
 * @param {Array<{id?: number, name?: string, competition_id?: number}>} groups
 * @param {{ currentGroupId?: number|string|null, competitionId?: number|string|null }} [options]
 * @returns {Array}
 */
export function listMoveTargetGroups(
  groups = [],
  { currentGroupId = null, competitionId = null } = {},
) {
  const currentId = toPositiveInt(currentGroupId)
  const compId = toPositiveInt(competitionId)

  const filtered = (Array.isArray(groups) ? groups : []).filter((group) => {
    const id = toPositiveInt(group?.id)

    if (!id || (currentId && id === currentId)) {
      return false
    }

    const groupCompetitionId = toPositiveInt(group?.competition_id)

    if (compId && groupCompetitionId && groupCompetitionId !== compId) {
      return false
    }

    return true
  })

  return sortCompetitionGroupsByName(filtered)
}
