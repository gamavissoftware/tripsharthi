import { api } from './client'

export const ai = {
  suggest: (conversationId, systemPrompt) =>
    api.post('/ai/suggest', { conversation_id: conversationId, system_prompt: systemPrompt }),
  // type: 'call_script' | 'qualification'; pass a contactId and/or dealId for context.
  script: (type, { contactId, dealId } = {}) =>
    api.post('/ai/script', { type, contact_id: contactId ?? null, deal_id: dealId ?? null }),
  usage:        ()     => api.get('/ai/usage'),
  getConfig:    ()     => api.get('/ai/config'),
  saveConfig:   (data) => api.post('/ai/config', data),
  deleteConfig: ()     => api.delete('/ai/config'),
}
