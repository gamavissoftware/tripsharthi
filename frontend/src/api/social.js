import { api } from './client'

export const social = {
  accounts:      ()     => api.get('/social/accounts'),
  connect:       (data) => api.post('/social/accounts', data),
  removeAccount: (id)   => api.delete(`/social/accounts/${id}`),

  // multipart — the client's json helpers would set the wrong Content-Type
  uploadMedia: (file) => {
    const fd = new FormData()
    fd.append('file', file)
    return api.postForm('/social/media', fd)
  },

  posts:      ()     => api.get('/social/posts'),
  createPost: (data) => api.post('/social/posts', data),
  removePost: (id)   => api.delete(`/social/posts/${id}`),

  // Facebook Login. start() returns the URL to send the browser to; Meta
  // redirects back to the backend, which bounces here with ?social_oauth_state,
  // and that state is what pages()/select() act on. No token ever reaches JS.
  oauthStatus: ()             => api.get('/social/oauth/status'),
  // return_to: which page Meta's redirect should bounce back to
  // ('social' | 'integrations'); the backend validates it.
  oauthStart:  (returnTo = 'social') => api.post('/social/oauth/start', { return_to: returnTo }),
  oauthPages:  (state)        => api.get(`/social/oauth/pages?state=${encodeURIComponent(state)}`),
  oauthSelect: (state, pageId) => api.post('/social/oauth/select', { state, page_id: pageId }),
}
