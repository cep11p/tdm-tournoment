export function isStructureEditable(competition) {
  return competition?.is_structure_editable !== false
}

export function structureLockReason(competition) {
  return competition?.structure_lock_reason ?? null
}
