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
}

export default RankingService
