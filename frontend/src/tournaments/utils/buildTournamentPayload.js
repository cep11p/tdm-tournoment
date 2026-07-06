export function buildTournamentPayload(form) {
  return {
    name: form.name,
    location: form.location,
    start_date: form.start_date,
    ...(form.end_date ? { end_date: form.end_date } : {}),
    ...(form.status ? { status: form.status } : {}),
  }
}
