const TYPE_LABELS = {
  singles: 'Individual',
  doubles: 'Dobles',
  team: 'Equipos',
}

const PARTICIPANT_KIND_LABELS = {
  player: { singular: 'jugador', plural: 'jugadores' },
  pair: { singular: 'pareja', plural: 'parejas' },
  team: { singular: 'equipo', plural: 'equipos' },
}

export function getCompetitionTypeLabel(type) {
  if (!type) {
    return '-'
  }

  return TYPE_LABELS[type] ?? type
}

export function isDoublesCompetition(competition) {
  return competition?.type === 'doubles'
}

export function isTeamCompetition(competition) {
  return competition?.type === 'team'
}

export function getParticipantKind(competition) {
  if (isTeamCompetition(competition)) {
    return 'team'
  }

  if (isDoublesCompetition(competition)) {
    return 'pair'
  }

  return 'player'
}

export function participantSingular(competition) {
  return PARTICIPANT_KIND_LABELS[getParticipantKind(competition)].singular
}

export function participantPlural(competition) {
  return PARTICIPANT_KIND_LABELS[getParticipantKind(competition)].plural
}

export function participantSingularForKind(kind) {
  return PARTICIPANT_KIND_LABELS[kind]?.singular ?? 'jugador'
}

export function participantPluralForKind(kind) {
  return PARTICIPANT_KIND_LABELS[kind]?.plural ?? 'jugadores'
}

export function isMultiMemberCompetition(competition) {
  const kind = getParticipantKind(competition)

  return kind === 'pair' || kind === 'team'
}
