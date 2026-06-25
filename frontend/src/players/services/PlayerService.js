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

  async create(payload) {
    const response = await httpClient.post('/players', payload)
    return unwrap(response) ?? null
  },
}

export default PlayerService
