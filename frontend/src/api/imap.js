import { api } from './client'

/**
 * IMAP inbound-email integration (Phase M). The password is write-only — the
 * server never returns it; `config.has_password` signals whether one is stored.
 * All return the raw { success, data } envelope — callers unwrap `.data`.
 */
export const imap = {
  get:        ()      => api.get('/imap-integrations'),
  save:       (cfg)   => api.post('/imap-integrations', cfg),
  disconnect: (id)    => api.delete(`/imap-integrations/${id}`),
}
