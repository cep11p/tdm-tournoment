const TYPE_LABELS = {
  singles: 'Individual',
  doubles: 'Dobles',
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
