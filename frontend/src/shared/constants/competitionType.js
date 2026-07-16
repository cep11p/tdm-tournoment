const TYPE_LABELS = {
  singles: 'Individual',
}

export function getCompetitionTypeLabel(type) {
  if (!type) {
    return '-'
  }

  return TYPE_LABELS[type] ?? type
}
