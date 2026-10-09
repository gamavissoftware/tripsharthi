import { api } from './client'

export const ecommerce = {
  get:        (platform)       => api.get(`/ecommerce/${platform}`),
  connect:    (platform, data) => api.post(`/ecommerce/${platform}`, data),
  disconnect: (platform)       => api.delete(`/ecommerce/${platform}`),
}
