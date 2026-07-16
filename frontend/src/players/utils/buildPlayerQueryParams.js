export function buildPlayerQueryParams({
  q = '',
  categoryId = '',
  clubId = '',
  includeInactive = false,
  page,
  perPage,
  sort = '-id',
} = {}) {
  const params = {
    q: q.trim(),
    sort,
  }

  if (categoryId !== '' && categoryId != null) {
    params.category_id = categoryId
  }

  if (clubId !== '' && clubId != null) {
    params.club_id = clubId
  }

  if (includeInactive) {
    params.include_inactive = 1
  }

  if (page != null) {
    params.page = page
  }

  if (perPage != null) {
    params.per_page = perPage
  }

  return params
}
