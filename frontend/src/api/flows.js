import { api } from './client'

export const flows = {
  list:      ()                    => api.get('/flows'),
  get:       (id)                  => api.get(`/flows/${id}`),
  create:    (data)                => api.post('/flows', data),
  update:    (id, data)            => api.put(`/flows/${id}`, data),
  setStatus: (id, status)          => api.patch(`/flows/${id}/status`, { status }),
  delete:    (id)                  => api.delete(`/flows/${id}`),
  runs:      (id)                  => api.get(`/flows/${id}/runs`),
  run:       (id, rid)             => api.get(`/flows/${id}/runs/${rid}`),
}
