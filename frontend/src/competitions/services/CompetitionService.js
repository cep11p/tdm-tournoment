import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const CompetitionService = {
  async listByTournament(tournamentId) {
    const response = await httpClient.get(`/tournaments/${tournamentId}/competitions`)
    return unwrap(response) ?? []
  },

  async show(id) {
    const response = await httpClient.get(`/competitions/${id}`)
    return unwrap(response) ?? null
  },

  async create(tournamentId, payload) {
    const response = await httpClient.post(`/tournaments/${tournamentId}/competitions`, payload)
    return unwrap(response) ?? null
  },

  async update(id, payload) {
    const response = await httpClient.put(`/competitions/${id}`, payload)
    return unwrap(response) ?? null
  },
}

export default CompetitionService
