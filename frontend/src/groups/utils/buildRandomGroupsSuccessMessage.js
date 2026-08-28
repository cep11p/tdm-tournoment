const formatCountLabel = (count, singular, plural) =>
  count === 1 ? `1 ${singular}` : `${count} ${plural}`

/**
 * @param {{
 *   groups_created?: number,
 *   players_assigned?: number,
 *   games_created?: number,
 * }} [result]
 * @param {{ isDoubles?: boolean }} [options]
 */
export function buildRandomGroupsSuccessMessage(result = {}, { isDoubles = false } = {}) {
  const groupsCreated = result.groups_created ?? 0
  const playersAssigned = result.players_assigned ?? 0
  const gamesCreated = result.games_created

  const groupsLabel = formatCountLabel(groupsCreated, 'grupo', 'grupos')
  const participantsLabel = isDoubles
    ? formatCountLabel(playersAssigned, 'pareja', 'parejas')
    : formatCountLabel(playersAssigned, 'jugador', 'jugadores')

  if (typeof gamesCreated !== 'number') {
    return `Se generaron ${groupsLabel}, se asignaron ${participantsLabel}.`
  }

  if (gamesCreated === 0) {
    const singleParticipantLabel = isDoubles ? 'pareja' : 'jugador'

    return `Se generaron ${groupsLabel}, se asignaron ${participantsLabel} y no se crearon partidos porque los grupos tienen un solo ${singleParticipantLabel}.`
  }

  const gamesLabel = formatCountLabel(gamesCreated, 'partido', 'partidos')

  return `Se generaron ${groupsLabel}, se asignaron ${participantsLabel} y se crearon ${gamesLabel}.`
}
