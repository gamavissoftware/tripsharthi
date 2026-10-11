import { api } from './client'

const unwrap = (r) => r.data

/** A workspace's requests to the TripSarthi team (not the CRM tickets for travellers). */
export const support = {
  list:   (filter = 'all') => api.get('/support/tickets?filter=' + filter).then(unwrap),
  create: (b)              => api.post('/support/tickets', b).then(unwrap),
  get:    (id)             => api.get(`/support/tickets/${id}`).then(unwrap),
  reply:  (id, message)    => api.post(`/support/tickets/${id}/reply`, { message }).then(unwrap),
  close:  (id)             => api.post(`/support/tickets/${id}/close`, {}).then(unwrap),
}
