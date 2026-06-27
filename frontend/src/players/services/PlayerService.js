import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const PlayerService = {
  async getPlayers({ q = '' } = {}) {
    const response = await httpClient.get('/players', {
      params: {
        q,
      },
    })

    return unwrap(response) ?? []
  },

  async search(query = '') {
    return this.getPlayers({ q: query })
  },

  async listPaginated({
    page = 1,
    per_page = 15,
    q = '',
    include_inactive = false,
    sort = '-id',
  } = {}) {
    const response = await httpClient.get('/players', {
      params: {
        page,
        per_page,
        q,
        ...(include_inactive ? { include_inactive: 1 } : {}),
        sort,
      },
    })

    return {
      data: response?.data?.data ?? [],
      meta: response?.data?.meta ?? {},
      links: response?.data?.links ?? {},
    }
  },

  async show(id) {
    const response = await httpClient.get(`/players/${id}`)
    return unwrap(response) ?? null
  },

  async create(payload) {
    const response = await httpClient.post('/players', payload)
    return unwrap(response) ?? null
  },

  async update(id, payload) {
    const response = await httpClient.patch(`/players/${id}`, payload)
    return unwrap(response) ?? null
  },

  async delete(id) {
    await httpClient.delete(`/players/${id}`)
  },
}

export default PlayerService
