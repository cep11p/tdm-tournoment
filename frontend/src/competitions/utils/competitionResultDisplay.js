export function getCompetitionResultDisplayName(result) {
  if (!result) {
    return '-'
  }

  const displayName = result.display_name ?? result.name

  return displayName && displayName.trim() !== '' ? displayName : '-'
}

export function getCompetitionResultMembers(result) {
  return Array.isArray(result?.members) ? result.members : []
}

export function hasCompetitionResult(result) {
  const displayName = getCompetitionResultDisplayName(result)

  return displayName !== '-'
}

export function getCompetitionResultKey(result, index = 0) {
  if (result?.competition_entry_id) {
    return `entry-${result.competition_entry_id}`
  }

  if (result?.id) {
    return `player-${result.id}`
  }

  return `result-${index}`
}
