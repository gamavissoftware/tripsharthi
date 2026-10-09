import { api } from './client'

// A month's lead-ad submissions read live from Meta, and importing them into Contacts.
export const metaLeads = {
  month:  (month) => api.get(`/meta-leads?month=${encodeURIComponent(month)}`),
  import: (payload) => api.post('/meta-leads/import', payload),
}
