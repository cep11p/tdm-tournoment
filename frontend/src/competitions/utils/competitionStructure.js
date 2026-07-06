export function isStructureEditable(competition) {
  return competition?.is_structure_editable !== false
}

export function structureLockReason(competition) {
  return competition?.structure_lock_reason ?? null
}

export function isRegistrationsEditable(competition) {
  return competition?.is_registrations_editable !== false
}

export function registrationsLockReason(competition) {
  return competition?.registrations_lock_reason ?? null
}
