// Segunda etapa (backend): registrations_count, groups_count, has_bracket,
// games_total, games_finished para columnas Jugadores y Partidos.

const STRUCTURE_SECONDARY_BY_CODE = {
  no_groups: 'Sin grupos configurados',
  group_stage_pending: 'Grupos generados',
  group_stage_in_progress: 'Fase de grupos activa',
  group_stage_attention_required: 'Fase de grupos requiere atención',
  ready_for_bracket: 'Llave pendiente',
  knockout_in_progress: 'Llave generada',
  completed: 'Competencia finalizada',
  awaiting_registrations: 'Esperando inscriptos',
}

const STATUS_LABEL_BY_CODE = {
  group_stage_pending: 'Fase de grupos pendiente',
  group_stage_in_progress: 'Fase de grupos en curso',
  group_stage_attention_required: 'Fase de grupos requiere atención',
  ready_for_bracket: 'Lista para generar llave',
  knockout_in_progress: 'Eliminatoria en curso',
  completed: 'Finalizada',
  no_groups: 'Sin grupos',
  awaiting_registrations: 'Esperando inscriptos',
}

const STATUS_BADGE_CLASSES_BY_CODE = {
  awaiting_registrations: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
  no_groups: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300',
  group_stage_pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200',
  group_stage_in_progress: 'bg-sky-100 text-sky-800 dark:bg-sky-900/60 dark:text-sky-200',
  group_stage_attention_required: 'bg-amber-100 text-amber-800 dark:bg-amber-900/60 dark:text-amber-200',
  ready_for_bracket: 'bg-violet-100 text-violet-800 dark:bg-violet-900/60 dark:text-violet-200',
  knockout_in_progress: 'bg-blue-100 text-blue-800 dark:bg-blue-900/60 dark:text-blue-200',
  completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/60 dark:text-emerald-200',
}

const DEFAULT_STATUS_BADGE_CLASSES =
  'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'

export function getStructurePrimary(competition) {
  if (competition?.has_group_stage === true) {
    return 'Grupos + llave'
  }

  if (competition?.has_group_stage === false) {
    return 'Eliminación directa'
  }

  return competition?.format_label ?? '-'
}

export function getStructureSecondary(competition) {
  const code = competition?.status_summary?.code

  if (!code) {
    return ''
  }

  return STRUCTURE_SECONDARY_BY_CODE[code] ?? ''
}

export function getStatusLabel(competition) {
  if (competition?.status_summary?.label) {
    return competition.status_summary.label
  }

  const code = competition?.status_summary?.code

  if (code && STATUS_LABEL_BY_CODE[code]) {
    return STATUS_LABEL_BY_CODE[code]
  }

  return '-'
}

export function getStatusBadgeClasses(competition) {
  const code = competition?.status_summary?.code

  if (code && STATUS_BADGE_CLASSES_BY_CODE[code]) {
    return STATUS_BADGE_CLASSES_BY_CODE[code]
  }

  return DEFAULT_STATUS_BADGE_CLASSES
}
