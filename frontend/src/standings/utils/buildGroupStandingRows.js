import { resolveGroupQualification } from './resolveGroupQualification.js'

const memberName = (member) =>
  `${member?.first_name ?? ''} ${member?.last_name ?? ''}`.trim()

const normalizeName = (value) =>
  String(value ?? '')
    .toLocaleLowerCase('es')
    .replace(/\s+/g, ' ')
    .trim()

export function buildMembersLabel(standing) {
  const members = Array.isArray(standing?.members) ? standing.members : []
  const names = members.map(memberName).filter(Boolean)

  if (names.length <= 1) {
    return null
  }

  const displayName = normalizeName(standing?.display_name)
  const slashForm = normalizeName(names.join(' / '))
  const dotForm = normalizeName(names.join(' · '))

  if (displayName && (displayName === slashForm || displayName === dotForm)) {
    return null
  }

  if (names.length >= 3) {
    return `${names.length} integrantes`
  }

  return names.join(' · ')
}

const resolveDisplayName = (standing) => {
  const displayName = String(standing?.display_name ?? '').trim()

  if (displayName) {
    return displayName
  }

  return 'Participación'
}

export function buildGroupStandingRows({
  standings = [],
  meta = {},
  qualifiedPerGroup = 2,
} = {}) {
  const list = Array.isArray(standings) ? standings : []
  const normalizedMeta = meta ?? {}
  const standingsAreProvisional = Boolean(normalizedMeta.standings_are_provisional)
  const requiresManualTiebreak =
    !standingsAreProvisional && Boolean(normalizedMeta.requires_manual_tiebreak)
  const manualTiebreakGroups = normalizedMeta.manual_tiebreak_groups ?? []

  return list.map((standing, index) => {
    const position = index + 1
    const groupPlayerStatus = standing?.group_player_status ?? 'active'

    return {
      position,
      displayName: resolveDisplayName(standing),
      membersLabel: buildMembersLabel(standing),
      played: Number(standing?.played ?? 0),
      won: Number(standing?.won ?? 0),
      lost: Number(standing?.lost ?? 0),
      groupPlayerStatus,
      qualification: resolveGroupQualification({
        standing: standing ?? {},
        position,
        qualifiedPerGroup: Number(qualifiedPerGroup ?? 0),
        standingsAreProvisional,
        requiresManualTiebreak,
        manualTiebreakGroups,
        allStandings: list,
      }),
      competitionEntryId: standing?.competition_entry_id ?? null,
      manualTiebreakApplied:
        Boolean(standing?.manual_tiebreak_applied) && !standingsAreProvisional,
      rubberDifference: Number(standing?.rubber_difference ?? 0),
      setDifference: Number(standing?.set_difference ?? 0),
      pointDifference: Number(standing?.point_difference ?? 0),
    }
  })
}
