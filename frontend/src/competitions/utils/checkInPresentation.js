const INACTIVE_LABELS = {
  withdrawn: 'Retirado',
  disqualified: 'Descalificado',
}

export function memberDisplayName(member) {
  const name = `${member?.first_name ?? ''} ${member?.last_name ?? ''}`.trim()

  if (name) {
    return name
  }

  if (member?.nickname) {
    return member.nickname
  }

  return `Jugador ${member?.player_id ?? ''}`.trim()
}

export function resolveCheckInAvailability(entry, competitionType) {
  const members = entry?.members ?? []
  const present = members.filter((member) => member.checked_in).length
  const total = members.length
  const status = entry?.status ?? 'active'

  if (status !== 'active') {
    return {
      kind: 'inactive',
      label: INACTIVE_LABELS[status] ?? 'No activo',
      present,
      total,
    }
  }

  if (competitionType === 'singles') {
    if (present >= 1 && total >= 1) {
      return { kind: 'ready', label: 'Presente', present, total }
    }

    return { kind: 'pending', label: 'Pendiente', present, total }
  }

  if (competitionType === 'doubles') {
    if (total > 0 && present === total) {
      return { kind: 'ready', label: 'Pareja presente', present, total }
    }

    if (present === 1) {
      return { kind: 'incomplete', label: 'Pareja incompleta', present, total }
    }

    return { kind: 'incomplete', label: 'Pendientes', present, total }
  }

  const label = `${present} de ${total} presentes`

  if (total > 0 && present === total) {
    return { kind: 'ready', label, present, total }
  }

  return { kind: 'partial', label, present, total }
}

export function summarizeCheckInEntries(entries) {
  let checkedInMembers = 0
  let pendingMembers = 0
  let members = 0

  for (const entry of entries ?? []) {
    for (const member of entry.members ?? []) {
      members += 1

      if (entry.status !== 'active') {
        continue
      }

      if (member.checked_in) {
        checkedInMembers += 1
      } else {
        pendingMembers += 1
      }
    }
  }

  return {
    entries: entries?.length ?? 0,
    members,
    checked_in_members: checkedInMembers,
    pending_members: pendingMembers,
  }
}

export function applyMemberCheckIn(payload, memberId, nextMember) {
  const type = payload?.competition?.type
  const entries = (payload?.entries ?? []).map((entry) => {
    const members = (entry.members ?? []).map((member) =>
      member.id === memberId ? { ...member, ...nextMember } : member,
    )

    const nextEntry = { ...entry, members }

    return {
      ...nextEntry,
      availability: resolveCheckInAvailability(nextEntry, type),
    }
  })

  return {
    ...payload,
    entries,
    summary: summarizeCheckInEntries(entries),
  }
}

export function matchesCheckInSearch(entry, query) {
  const normalized = query.trim().toLowerCase()

  if (!normalized) {
    return true
  }

  const haystacks = [
    entry?.display_name,
    ...(entry?.members ?? []).flatMap((member) => [
      member.first_name,
      member.last_name,
      member.nickname,
      memberDisplayName(member),
    ]),
  ]

  return haystacks.some((value) => String(value ?? '').toLowerCase().includes(normalized))
}

export function matchesCheckInFilter(entry, filter) {
  const kind = entry?.availability?.kind

  if (filter === 'present') {
    return kind === 'ready'
  }

  if (filter === 'pending') {
    return kind === 'pending' || kind === 'incomplete' || kind === 'partial'
  }

  return true
}

export function operationalMemberCount(payload) {
  return (payload?.entries ?? []).reduce((count, entry) => {
    if (entry.status !== 'active') {
      return count
    }

    return count + (entry.members?.length ?? 0)
  }, 0)
}
