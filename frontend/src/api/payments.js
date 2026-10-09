import { api } from './client'

export const payments = {
  getConfig:  ()       => api.get('/payments/config'),
  saveConfig: (data)   => api.post('/payments/config', data),
  links:      ()       => api.get('/payments/links'),
  createLink: (data)   => api.post('/payments/links', data),
}
