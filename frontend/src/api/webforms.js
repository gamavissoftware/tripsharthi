import { api } from './client'

export const webforms = {
  list:    ()          => api.get('/web-forms'),
  get:     (id)        => api.get(`/web-forms/${id}`),
  create:  (data)      => api.post('/web-forms', data),
  update:  (id, data)  => api.put(`/web-forms/${id}`, data),
  del:     (id)        => api.delete(`/web-forms/${id}`),
  snippet: (id)        => api.get(`/web-forms/${id}/snippet`),
}
