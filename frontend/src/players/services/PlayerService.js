import httpClient from '../../services/httpClient'
import { buildPlayerQueryParams } from '../utils/buildPlayerQueryParams'

const unwrap = (response) => response?.data?.data

const PlayerService = {
  async getPlayers(filters = {}) {
    const response = await httpClient.get('/players', {
      params: buildPlayerQueryParams(filters),
    })

    return unwrap(response) ?? []
  },

  async search(query = '', filters = {}) {
    return this.getPlayers({ ...filters, q: query })
  },

  async listPaginated(filters = {}) {
    const response = await httpClient.get('/players', {
      params: buildPlayerQueryParams(filters),
    })

    return {
      data: response?.data?.data ?? [],
      meta: response?.data?.meta ?? {},
      links: response?.data?.links ?? {},
    }
  },

  async show(id) {
    const response = await httpClient.get(`/players/${id}`)
    return unwrap(response) ?? null
  },

  async create(payload) {
    const response = await httpClient.post('/players', payload)
    return unwrap(response) ?? null
  },

  async update(id, payload) {
    const response = await httpClient.patch(`/players/${id}`, payload)
    return unwrap(response) ?? null
  },

  async delete(id) {
    await httpClient.delete(`/players/${id}`)
  },
}

export default PlayerService
