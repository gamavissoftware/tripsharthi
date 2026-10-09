import { api } from './client'

// Meta Ads spend connection. The user token never reaches JS: Facebook Login
// (social.oauthStart('ads')) hands it to the backend, connect() just names the
// finished attempt by its state.
export const metaAds = {
  status:   ()         => api.get('/meta-ads'),
  connect:  (state)    => api.post('/meta-ads/connect', { state }),
  accounts: ()         => api.get('/meta-ads/accounts'),
  select:   (accounts) => api.post('/meta-ads/accounts', { accounts }),
  sync:     (months = 12) => api.post('/meta-ads/sync', { months }),
  disconnect: ()       => api.delete('/meta-ads'),
}
