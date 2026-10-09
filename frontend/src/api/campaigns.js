import { api } from './client'

export const campaigns = {
  list:   ()          => api.get('/campaigns'),
  get:    (id)        => api.get(`/campaigns/${id}`),
  create: (data)      => api.post('/campaigns', data),
  update: (id, data)  => api.put(`/campaigns/${id}`, data),
  del:    (id)        => api.delete(`/campaigns/${id}`),
  send:   (id)        => api.post(`/campaigns/${id}/send`),
  schedule:   (id, data) => api.post(`/campaigns/${id}/schedule`, data),
  unschedule: (id)       => api.post(`/campaigns/${id}/unschedule`),
  retargetPreview: (id)       => api.get(`/campaigns/${id}/retarget-preview`),
  retarget:        (id, data) => api.post(`/campaigns/${id}/retarget`, data),
}
