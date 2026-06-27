const formatCountLabel = (count, singular, plural) =>
  count === 1 ? `1 ${singular}` : `${count} ${plural}`

/**
 * @param {{
 *   groups_created?: number,
 *   players_assigned?: number,
 *   games_created?: number,
 * }} [result]
 */
export function buildRandomGroupsSuccessMessage(result = {}) {
  const groupsCreated = result.groups_created ?? 0
  const playersAssigned = result.players_assigned ?? 0
  const gamesCreated = result.games_created

  const groupsLabel = formatCountLabel(groupsCreated, 'grupo', 'grupos')
  const playersLabel = formatCountLabel(playersAssigned, 'jugador', 'jugadores')

  if (typeof gamesCreated !== 'number') {
    return `Se generaron ${groupsLabel}, se asignaron ${playersLabel}.`
  }

  if (gamesCreated === 0) {
    return `Se generaron ${groupsLabel}, se asignaron ${playersLabel} y no se crearon partidos porque los grupos tienen un solo jugador.`
  }

  const gamesLabel = formatCountLabel(gamesCreated, 'partido', 'partidos')

  return `Se generaron ${groupsLabel}, se asignaron ${playersLabel} y se crearon ${gamesLabel}.`
}
