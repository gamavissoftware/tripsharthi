import { api } from './client'

/**
 * White-label / branding settings.
 *
 * GET  is readable by any authenticated tenant member (the brand applies
 *      app-wide); POST is owner-only and 403s for other roles.
 *
 * Both return the raw { success, data } envelope — callers unwrap `.data`.
 */
export const settings = {
  getBranding: () =>
    api.get('/settings/branding'),

  updateBranding: (branding) =>
    api.post('/settings/branding', branding),

  // Record-level permissions (Phase M). Mode: open | owner | team. Write is owner/admin.
  getRecordVisibility: () =>
    api.get('/settings/record-visibility'),

  updateRecordVisibility: (mode) =>
    api.post('/settings/record-visibility', { mode }),
}
