import { api } from './client'

export const templates = {
  list(params = {}) {
    const qs = new URLSearchParams(
      Object.fromEntries(Object.entries(params).filter(([, v]) => v !== undefined && v !== ''))
    ).toString()
    return api.get(`/templates${qs ? `?${qs}` : ''}`)
  },

  get(id) {
    return api.get(`/templates/${id}`)
  },

  create(data) {
    return api.post('/templates', data)
  },

  update(id, data) {
    return api.put(`/templates/${id}`, data)
  },

  del(id) {
    return api.delete(`/templates/${id}`)
  },

  submit(id) {
    return api.post(`/templates/${id}/submit`, {})
  },

  sync(id) {
    return api.post(`/templates/${id}/sync`, {})
  },
}
