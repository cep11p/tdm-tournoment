import { isTeamCompetition } from '../../shared/constants/competitionType.js'

// Estados administrativos de Tournament que admiten modificaciones deportivas.
const MUTABLE_TOURNAMENT_STATUSES = new Set(['draft', 'in_progress'])

/**
 * Visibilidad de retirar / descalificar un participante del grupo.
 * Replica los guards de SetGroupEntryStatusAction: en singles/dobles se
 * permite con partidos generados (los pendientes se cierran a favor del
 * rival); en equipos solo mientras no haya enfrentamientos generados.
 *
 * tournamentStatus es Tournament.status (no Competition.status_summary).
 * Mientras no se conozca (carga en curso o error) la acción no se habilita.
 *
 * @param {object|null} groupEntry
 * @param {{ competition?: object|null, hasBracket?: boolean, hasTeamTies?: boolean, tournamentStatus?: string|null }} [options]
 * @returns {boolean}
 */
export function canChangeGroupEntryStatus(
  groupEntry,
  { competition = null, hasBracket = false, hasTeamTies = false, tournamentStatus = null } = {},
) {
  if (!groupEntry) {
    return false
  }

  if ((groupEntry.status ?? 'active') !== 'active') {
    return false
  }

  if (!MUTABLE_TOURNAMENT_STATUSES.has(tournamentStatus) || hasBracket) {
    return false
  }

  if (isTeamCompetition(competition) && hasTeamTies) {
    return false
  }

  return true
}

/**
 * Obtiene Tournament.status para canChangeGroupEntryStatus.
 * Devuelve null si no hay torneo o la consulta falla, para no habilitar
 * acciones de escritura con un estado desconocido.
 *
 * @param {(id: number|string) => Promise<object|null>} fetchTournament
 * @param {number|string|null|undefined} tournamentId
 * @returns {Promise<string|null>}
 */
export async function fetchTournamentStatus(fetchTournament, tournamentId) {
  if (!tournamentId) {
    return null
  }

  try {
    const tournament = await fetchTournament(tournamentId)

    return tournament?.status ?? null
  } catch {
    return null
  }
}
