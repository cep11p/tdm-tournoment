import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const RankingService = {
  async index() {
    const response = await httpClient.get('/rankings')
    return unwrap(response) ?? []
  },

  async show(id) {
    const response = await httpClient.get(`/rankings/${id}`)
    return unwrap(response) ?? null
  },

  async standings(id) {
    const response = await httpClient.get(`/rankings/${id}/standings`)
    return unwrap(response) ?? []
  },

  async playerTransactions(rankingId, playerId) {
    const response = await httpClient.get(
      `/rankings/${rankingId}/players/${playerId}/transactions`,
    )
    return unwrap(response) ?? null
  },

  async create(payload) {
    const response = await httpClient.post('/rankings', payload)
    return unwrap(response) ?? null
  },

  async update(id, payload) {
    const response = await httpClient.patch(`/rankings/${id}`, payload)
    return unwrap(response) ?? null
  },

  async delete(id) {
    await httpClient.delete(`/rankings/${id}`)
  },

  async createRule(rankingId, payload) {
    const response = await httpClient.post(`/rankings/${rankingId}/rules`, payload)
    return unwrap(response) ?? null
  },

  async updateRule(rankingId, ruleId, payload) {
    const response = await httpClient.patch(`/rankings/${rankingId}/rules/${ruleId}`, payload)
    return unwrap(response) ?? null
  },

  async deleteRule(rankingId, ruleId) {
    await httpClient.delete(`/rankings/${rankingId}/rules/${ruleId}`)
  },
}

export default RankingService
