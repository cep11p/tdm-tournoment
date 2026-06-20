import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const TournamentService = {
  async list() {
    const response = await httpClient.get('/tournaments')
    return unwrap(response) ?? []
  },

  async show(id) {
    const response = await httpClient.get(`/tournaments/${id}`)
    return unwrap(response) ?? null
  },

  async create(payload) {
    const response = await httpClient.post('/tournaments', payload)
    return unwrap(response) ?? null
  },
}

export default TournamentService
