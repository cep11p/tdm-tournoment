import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const AuditLogService = {
  async index(params = {}) {
    const response = await httpClient.get('/audit-logs', { params })

    return {
      data: response?.data?.data ?? [],
      meta: response?.data?.meta ?? {},
      links: response?.data?.links ?? {},
    }
  },

  async show(id) {
    const response = await httpClient.get(`/audit-logs/${id}`)
    return unwrap(response) ?? null
  },
}

export default AuditLogService
