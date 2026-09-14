import { competitionHasGroupStage } from '../../competitions/constants/competitionFormats.js'
import { isTeamCompetition } from '../../shared/constants/competitionType.js'

export const GROUP_COMPOSITION_LOCKED_MESSAGE =
  'La composición de grupos está cerrada porque la llave ya fue generada.'

/**
 * Visibilidad de crear grupo / agregar integrante.
 * No usa is_structure_editable: ese flag bloquea regenerar/editar reglas,
 * no la mutación tardía permitida mientras no haya llave.
 *
 * Team queda excluido a propósito (ETAPA 4).
 *
 * @param {object|null} competition
 * @param {{ hasBracket?: boolean }} [options]
 * @returns {boolean}
 */
export function canMutateGroupComposition(competition, { hasBracket = false } = {}) {
  if (!competition) {
    return false
  }

  if (isTeamCompetition(competition)) {
    return false
  }

  if (!competitionHasGroupStage(competition)) {
    return false
  }

  if (hasBracket) {
    return false
  }

  if (competition.status_summary?.code === 'completed') {
    return false
  }

  return true
}

/**
 * @param {{ hasBracket?: boolean }} [options]
 * @returns {string|null}
 */
export function groupCompositionLockMessage({ hasBracket = false } = {}) {
  return hasBracket ? GROUP_COMPOSITION_LOCKED_MESSAGE : null
}
