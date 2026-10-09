import { api } from './client'

export const tags = {
  list:   ()            => api.get('/tags'),
  create: (data)        => api.post('/tags', data),
  update: (id, data)    => api.put(`/tags/${id}`, data),
  remove: (id)          => api.delete(`/tags/${id}`),
}
