export function getBracketMatches(bracket, isTeam) {
  if (isTeam) {
    return (bracket?.team_ties ?? []).map(adaptTeamTieToMatch)
  }

  return bracket?.games ?? []
}

export function adaptTeamTieToMatch(teamTie) {
  const score = teamTie?.score ?? null

  return {
    ...teamTie,
    isTeamTie: true,
    side1: teamTie?.entry1 ?? null,
    side2: teamTie?.entry2 ?? null,
    sets_won:
      score && typeof score.entry1 === 'number' && typeof score.entry2 === 'number'
        ? { player1: score.entry1, player2: score.entry2 }
        : null,
  }
}

export function bracketMatchDetailPath(match) {
  if (match?.isTeamTie) {
    return `/team-ties/${match.id}`
  }

  return `/games/${match.id}`
}

export function bracketMatchScoreLabel(match) {
  if (match?.is_bye) {
    return null
  }

  if (match?.isTeamTie && match?.score) {
    return `${match.score.entry1} - ${match.score.entry2}`
  }

  return null
}

export function bracketMatchFormatLabel(match) {
  if (match?.is_bye || match?.isTeamTie) {
    return null
  }

  if (match?.best_of && match?.sets_to_win) {
    return `Mejor de ${match.best_of} · gana con ${match.sets_to_win} sets`
  }

  if (match?.best_of) {
    return `Mejor de ${match.best_of}`
  }

  return null
}
