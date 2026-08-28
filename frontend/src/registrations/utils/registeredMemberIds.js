export function collectRegisteredMemberIds(registrations) {
  const ids = new Set()

  for (const registration of registrations ?? []) {
    if (Array.isArray(registration.members) && registration.members.length > 0) {
      for (const member of registration.members) {
        if (member?.id) {
          ids.add(member.id)
        }
      }

      continue
    }

    if (registration.player?.id) {
      ids.add(registration.player.id)
    }
  }

  return ids
}
