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
}

export default RatingSystemService
