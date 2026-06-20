import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const GameService = {
  async listByCompetition(competitionId) {
    const response = await httpClient.get(`/competitions/${competitionId}/games`)
    return unwrap(response) ?? []
  },

  async show(gameId) {
    const response = await httpClient.get(`/games/${gameId}`)
    return unwrap(response) ?? null
  },

  async recordSet(gameId, payload) {
    const response = await httpClient.post(`/games/${gameId}/sets`, payload)
    return unwrap(response) ?? null
  },
}

export default GameService
