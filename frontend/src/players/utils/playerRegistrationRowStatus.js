export const PLAYER_REGISTRATION_ROW_STATUS = {
  UNAVAILABLE: 'unavailable',
  COMPATIBLE: 'compatible',
  CATEGORY_MISMATCH: 'category_mismatch',
  CATEGORY_UNINFORMED: 'category_uninformed',
}

function normalizeCategorySlug(value) {
  if (value == null || value === '') {
    return null
  }

  return String(value).trim().toLowerCase()
}

export function resolveCompetitionCategorySlug(competition) {
  if (!competition) {
    return null
  }

  return (
    normalizeCategorySlug(competition.category_ref?.slug) ??
    normalizeCategorySlug(competition.category)
  )
}

export function resolvePlayerRegistrationRowStatus(player, { registeredPlayerIds, competitionCategorySlug }) {
  if (!player?.active) {
    return PLAYER_REGISTRATION_ROW_STATUS.UNAVAILABLE
  }

  if (registeredPlayerIds?.has?.(player.id)) {
    return PLAYER_REGISTRATION_ROW_STATUS.UNAVAILABLE
  }

  const playerSlug = normalizeCategorySlug(player.category?.slug)
  const competitionSlug = normalizeCategorySlug(competitionCategorySlug)

  if (!playerSlug) {
    return PLAYER_REGISTRATION_ROW_STATUS.CATEGORY_UNINFORMED
  }

  if (!competitionSlug || playerSlug === competitionSlug) {
    return PLAYER_REGISTRATION_ROW_STATUS.COMPATIBLE
  }

  return PLAYER_REGISTRATION_ROW_STATUS.CATEGORY_MISMATCH
}

export function isPlayerRegistrationRowSelectable(status) {
  return status !== PLAYER_REGISTRATION_ROW_STATUS.UNAVAILABLE
}

export function isPlayerRegistrationRowWarning(status) {
  return (
    status === PLAYER_REGISTRATION_ROW_STATUS.CATEGORY_MISMATCH ||
    status === PLAYER_REGISTRATION_ROW_STATUS.CATEGORY_UNINFORMED
  )
}
