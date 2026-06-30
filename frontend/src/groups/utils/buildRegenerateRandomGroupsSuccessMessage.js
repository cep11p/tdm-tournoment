const formatCountLabel = (count, singular, plural) =>
  count === 1 ? `1 ${singular}` : `${count} ${plural}`

/**
 * @param {{
 *   groups_created?: number,
 *   players_assigned?: number,
 *   games_created?: number,
 * }} [result]
 */
export function buildRegenerateRandomGroupsSuccessMessage(result = {}) {
  const groupsCreated = result.groups_created ?? 0
  const playersAssigned = result.players_assigned ?? 0
  const gamesCreated = result.games_created ?? 0

  const groupsLabel = formatCountLabel(groupsCreated, 'grupo', 'grupos')
  const playersLabel = formatCountLabel(playersAssigned, 'jugador', 'jugadores')
  const gamesLabel = formatCountLabel(gamesCreated, 'partido', 'partidos')

  return `Se regeneraron ${groupsLabel}, se asignaron ${playersLabel} y se crearon ${gamesLabel}.`
}
