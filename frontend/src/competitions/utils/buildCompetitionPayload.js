function showGroupStageFields(format) {
  return format === 'groups_knockout' || format === 'manual'
}

export const DEFAULT_COMPETITION_FORM_VALUES = {
  name: '',
  category: '',
  type: 'singles',
  format: 'groups_knockout',
  points_per_set: 11,
  qualified_per_group: 2,
  group_stage_best_of: 5,
  knockout_stage_best_of: 5,
  semifinal_best_of: 7,
  final_best_of: 7,
}

export function competitionToFormValues(competition) {
  if (!competition) {
    return { ...DEFAULT_COMPETITION_FORM_VALUES }
  }

  return {
    name: competition.name ?? '',
    category: competition.category ?? '',
    type: competition.type ?? 'singles',
    format: competition.format ?? 'groups_knockout',
    points_per_set: competition.points_per_set ?? 11,
    qualified_per_group: competition.qualified_per_group ?? 2,
    group_stage_best_of: competition.group_stage_best_of ?? 5,
    knockout_stage_best_of: competition.knockout_stage_best_of ?? 5,
    semifinal_best_of: competition.semifinal_best_of ?? 7,
    final_best_of: competition.final_best_of ?? 7,
  }
}

export function buildCompetitionPayload(form, { structureEditable = true } = {}) {
  if (!structureEditable) {
    return {
      name: form.name,
      category: form.category,
    }
  }

  const hasGroupStage = showGroupStageFields(form.format)

  return {
    name: form.name,
    category: form.category,
    type: form.type,
    format: form.format,
    points_per_set: Number(form.points_per_set),
    qualified_per_group: hasGroupStage ? Number(form.qualified_per_group) : 2,
    group_stage_best_of: hasGroupStage ? Number(form.group_stage_best_of) : 5,
    knockout_stage_best_of: Number(form.knockout_stage_best_of),
    semifinal_best_of: Number(form.semifinal_best_of),
    final_best_of: Number(form.final_best_of),
  }
}
