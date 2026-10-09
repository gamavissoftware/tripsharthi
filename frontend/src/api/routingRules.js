import { api } from './client'

export const routingRules = {
  list:   ()          => api.get('/routing-rules'),
  create: (data)      => api.post('/routing-rules', data),
  update: (id, data)  => api.put(`/routing-rules/${id}`, data),
  remove: (id)        => api.delete(`/routing-rules/${id}`),
}
