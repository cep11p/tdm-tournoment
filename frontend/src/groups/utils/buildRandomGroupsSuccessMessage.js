const formatCountLabel = (count, singular, plural) =>
  count === 1 ? `1 ${singular}` : `${count} ${plural}`

const PARTICIPANT_LABELS = {
  player: { singular: 'jugador', plural: 'jugadores' },
  pair: { singular: 'pareja', plural: 'parejas' },
  team: { singular: 'equipo', plural: 'equipos' },
}

/**
 * @param {{
 *   groups_created?: number,
 *   players_assigned?: number,
 *   games_created?: number,
 * }} [result]
 * @param {{ participantKind?: 'player' | 'pair' | 'team' }} [options]
 */
export function buildRandomGroupsSuccessMessage(result = {}, { participantKind = 'player' } = {}) {
  const groupsCreated = result.groups_created ?? 0
  const playersAssigned = result.players_assigned ?? 0
  const gamesCreated = result.games_created
  const labels = PARTICIPANT_LABELS[participantKind] ?? PARTICIPANT_LABELS.player

  const groupsLabel = formatCountLabel(groupsCreated, 'grupo', 'grupos')
  const participantsLabel = formatCountLabel(playersAssigned, labels.singular, labels.plural)

  if (typeof gamesCreated !== 'number') {
    return `Se generaron ${groupsLabel}, se asignaron ${participantsLabel}.`
  }

  if (gamesCreated === 0) {
    return `Se generaron ${groupsLabel}, se asignaron ${participantsLabel} y no se crearon partidos porque los grupos tienen un solo ${labels.singular}.`
  }

  const gamesLabel = formatCountLabel(gamesCreated, 'partido', 'partidos')

  return `Se generaron ${groupsLabel}, se asignaron ${participantsLabel} y se crearon ${gamesLabel}.`
}
