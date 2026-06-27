export const COMPETITION_FORMATS = {
  groups_knockout: {
    value: 'groups_knockout',
    label: 'Fase de grupos + eliminatoria',
    hasGroupStage: true,
  },
  knockout_direct: {
    value: 'knockout_direct',
    label: 'Eliminación directa',
    hasGroupStage: false,
  },
}

export const FORMAT_OPTIONS = Object.values(COMPETITION_FORMATS)

export function normalizeCompetitionFormat(format) {
  if (format === 'manual') {
    return 'groups_knockout'
  }

  return format || 'groups_knockout'
}

export function competitionHasGroupStage(competition) {
  if (typeof competition?.has_group_stage === 'boolean') {
    return competition.has_group_stage
  }

  const normalized = normalizeCompetitionFormat(competition?.format)

  return COMPETITION_FORMATS[normalized]?.hasGroupStage ?? true
}

export function getCompetitionFormatLabel(competitionOrFormat) {
  if (competitionOrFormat && typeof competitionOrFormat === 'object') {
    if (competitionOrFormat.format_label) {
      return competitionOrFormat.format_label
    }

    return getCompetitionFormatLabel(competitionOrFormat.format)
  }

  const normalized = normalizeCompetitionFormat(competitionOrFormat)

  return COMPETITION_FORMATS[normalized]?.label ?? normalized
}
