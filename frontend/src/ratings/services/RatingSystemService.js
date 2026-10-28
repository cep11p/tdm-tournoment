import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const RatingSystemService = {
  async index() {
    const response = await httpClient.get('/rating-systems')
    return unwrap(response) ?? []
  },

  async show(id) {
    const response = await httpClient.get(`/rating-systems/${id}`)
    return unwrap(response) ?? null
  },

  async create(payload) {
    const response = await httpClient.post('/rating-systems', payload)
    return unwrap(response) ?? null
  },

  async update(id, payload) {
    const response = await httpClient.patch(`/rating-systems/${id}`, payload)
    return unwrap(response) ?? null
  },

  async versions(systemId) {
    const response = await httpClient.get(`/rating-systems/${systemId}/versions`)
    return unwrap(response) ?? []
  },

  async showVersion(systemId, versionId) {
    const response = await httpClient.get(`/rating-systems/${systemId}/versions/${versionId}`)
    return unwrap(response) ?? null
  },

  async createVersion(systemId, payload = {}) {
    const response = await httpClient.post(`/rating-systems/${systemId}/versions`, payload)
    return unwrap(response) ?? null
  },

  async updateVersion(systemId, versionId, payload) {
    const response = await httpClient.patch(
      `/rating-systems/${systemId}/versions/${versionId}`,
      payload,
    )
    return unwrap(response) ?? null
  },

  async publishVersion(systemId, versionId) {
    const response = await httpClient.post(
      `/rating-systems/${systemId}/versions/${versionId}/publish`,
    )
    return unwrap(response) ?? null
  },
}

export default RatingSystemService
