import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const PlayerService = {
  async search(query = '') {
    const response = await httpClient.get('/players', {
      params: {
        q: query,
      },
    })

    return unwrap(response) ?? []
  },

  async create(payload) {
    const response = await httpClient.post('/players', payload)
    return unwrap(response) ?? null
  },
}

export default PlayerService
