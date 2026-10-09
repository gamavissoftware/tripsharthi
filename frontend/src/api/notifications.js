import { api } from './client'

export const notifications = {
  getSettings:  ()     => api.get('/notifications/settings'),
  saveSettings: (data) => api.post('/notifications/settings', data),
  subscribePush:(sub)  => api.post('/notifications/push/subscribe', sub),
  // In-app feed (Phase L)
  feed:        ()     => api.get('/notifications/feed'),
  markRead:    (id)   => api.post(`/notifications/${id}/read`, {}),
  markAllRead: ()     => api.post('/notifications/read-all', {}),
}
