function showGroupStageFields(format) {
  return format === 'groups_knockout' || format === 'manual'
}

export const DEFAULT_COMPETITION_FORM_VALUES = {
  name: '',
  category_id: '',
  type: 'singles',
  team_size: 4,
  team_tie_format_id: '',
  format: 'groups_knockout',
  points_per_set: 11,
  qualified_per_group: 2,
  group_stage_best_of: 5,
  knockout_stage_best_of: 5,
  semifinal_best_of: 7,
  final_best_of: 7,
  third_place_mode: 'shared',
}

export function competitionToFormValues(competition) {
  if (!competition) {
    return { ...DEFAULT_COMPETITION_FORM_VALUES }
  }

  return {
    name: competition.name ?? '',
    category_id: competition.category_id ?? competition.category_ref?.id ?? '',
    type: competition.type ?? 'singles',
    team_size: competition.team_size ?? 4,
    team_tie_format_id: competition.team_tie_format_id ?? competition.team_tie_format?.id ?? '',
    format: competition.format ?? 'groups_knockout',
    points_per_set: competition.points_per_set ?? 11,
    qualified_per_group: competition.qualified_per_group ?? 2,
    group_stage_best_of: competition.group_stage_best_of ?? 5,
    knockout_stage_best_of: competition.knockout_stage_best_of ?? 5,
    semifinal_best_of: competition.semifinal_best_of ?? 7,
    final_best_of: competition.final_best_of ?? 7,
    third_place_mode: competition.third_place_mode ?? 'shared',
  }
}

export function buildCompetitionPayload(form, { structureEditable = true } = {}) {
  if (!structureEditable) {
    return {
      name: form.name,
      category_id: form.category_id === '' ? null : Number(form.category_id),
    }
  }

  const hasGroupStage = showGroupStageFields(form.format)
  const isTeam = form.type === 'team'

  const payload = {
    name: form.name,
    category_id: form.category_id === '' ? null : Number(form.category_id),
    type: form.type,
    format: form.format,
    points_per_set: Number(form.points_per_set),
    qualified_per_group: hasGroupStage ? Number(form.qualified_per_group) : 2,
    group_stage_best_of: hasGroupStage ? Number(form.group_stage_best_of) : 5,
    knockout_stage_best_of: Number(form.knockout_stage_best_of),
    semifinal_best_of: Number(form.semifinal_best_of),
    final_best_of: Number(form.final_best_of),
    third_place_mode: form.third_place_mode,
  }

  if (isTeam) {
    payload.team_size = Number(form.team_size)
    payload.team_tie_format_id = form.team_tie_format_id === '' ? null : Number(form.team_tie_format_id)
  }

  return payload
}
