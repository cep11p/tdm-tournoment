import httpClient from '../../services/httpClient'

const unwrap = (response) => response?.data?.data

const CategoryService = {
  async list() {
    const response = await httpClient.get('/categories')
    return unwrap(response) ?? []
  },
}

export default CategoryService
