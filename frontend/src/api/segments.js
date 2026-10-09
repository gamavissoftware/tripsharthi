import { api } from './client'

export const segments = {
  list:    ()           => api.get('/segments'),
  get:     (id)         => api.get(`/segments/${id}`),
  create:  (data)       => api.post('/segments', data),
  update:  (id, data)   => api.put(`/segments/${id}`, data),
  del:     (id)         => api.delete(`/segments/${id}`),
  count:   (id)         => api.get(`/segments/${id}/count`),
}
