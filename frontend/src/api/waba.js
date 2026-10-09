import { api } from './client'

export const waba = {
  get: () =>
    api.get('/waba'),

  connect: (data) =>
    api.post('/waba', data),

  test: () =>
    api.get('/waba/test'),

  phoneNumbers: () =>
    api.get('/waba/phone-numbers'),

  providers: () =>
    api.get('/waba/providers'),
}
