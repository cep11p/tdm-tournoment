const BLOCKING_STATUSES = new Set(['in_progress', 'finished', 'not_needed'])

const toPositiveInt = (value) => {
  const id = Number(value)

  return Number.isInteger(id) && id > 0 ? id : null
}

/**
 * IDs de participación en un Game. La API usa side1/side2;
 * entry1_id / entry2_id se aceptan por si el payload los incluye.
 *
 * @param {object|null} game
 * @returns {number[]}
 */
export function collectGameEntryIds(game) {
  return [
    game?.entry1_id,
    game?.entry2_id,
    game?.side1?.competition_entry_id,
    game?.side2?.competition_entry_id,
  ]
    .map(toPositiveInt)
    .filter(Boolean)
}

export function gameInvolvesEntry(game, entryId) {
  const id = toPositiveInt(entryId)

  if (!id) {
    return false
  }

  return collectGameEntryIds(game).includes(id)
}

const gameHasBlockingSportingState = (game) => {
  const status = game?.status

  if (BLOCKING_STATUSES.has(status)) {
    return true
  }

  if (status === 'pending' || status == null) {
    if (game?.winner_entry_id || game?.winner_id || game?.finished_at) {
      return true
    }

    if (Array.isArray(game?.sets) && game.sets.length > 0) {
      return true
    }
  }

  return false
}

/**
 * True si la entry tiene en este grupo un Game iniciado o finalizado.
 * Pending puro no bloquea. Games de otras entries no bloquean.
 *
 * @param {number|string} entryId
 * @param {Array} games
 * @param {{ groupId?: number|string|null }} [options]
 * @returns {boolean}
 */
export function entryHasStartedOrFinishedGames(entryId, games = [], { groupId = null } = {}) {
  const id = toPositiveInt(entryId)

  if (!id) {
    return false
  }

  const scopedGroupId = toPositiveInt(groupId)

  return (Array.isArray(games) ? games : []).some((game) => {
    const gameGroupId = toPositiveInt(game?.group_id)

    if (scopedGroupId && gameGroupId && gameGroupId !== scopedGroupId) {
      return false
    }

    if (!gameInvolvesEntry(game, id)) {
      return false
    }

    return gameHasBlockingSportingState(game)
  })
}

/**
 * @param {number|string} entryId
 * @param {Array} games
 * @param {{ groupId?: number|string|null }} [options]
 * @returns {boolean}
 */
export function canEditGroupEntry(entryId, games = [], options = {}) {
  const id = toPositiveInt(entryId)

  if (!id) {
    return false
  }

  return !entryHasStartedOrFinishedGames(id, games, options)
}

/**
 * @param {{ participantKind?: 'player'|'pair'|'team' }} [options]
 * @returns {string}
 */
export function groupEntryLockMessage({ participantKind = 'player' } = {}) {
  if (participantKind === 'pair') {
    return 'Esta pareja ya tiene partidos iniciados o finalizados y no puede quitarse ni moverse.'
  }

  return 'Este participante ya tiene partidos iniciados o finalizados y no puede quitarse ni moverse.'
}
