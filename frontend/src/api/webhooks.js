import { api } from './client'

export const webhooks = {
  list:   ()          => api.get('/webhooks'),
  create: (data)      => api.post('/webhooks', data),
  update: (id, data)  => api.put(`/webhooks/${id}`, data),
  remove: (id)        => api.delete(`/webhooks/${id}`),
  test:   (id)        => api.post(`/webhooks/${id}/test`),
}
