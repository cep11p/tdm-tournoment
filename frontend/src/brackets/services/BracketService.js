import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const BracketService = {
  async create(competitionId, payload) {
    const response = await httpClient.post(`/competitions/${competitionId}/bracket`, payload)
    return unwrap(response) ?? null
  },

  async generateNextRound(bracketId) {
    const response = await httpClient.post(`/brackets/${bracketId}/next-round`)
    return unwrap(response) ?? null
  },
}

export default BracketService
