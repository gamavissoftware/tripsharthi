import { api } from './client'

// CRM — accounts, tasks, notes, activities/timeline. Helpers return the raw
// { success, data } envelope; callers read `.data` (see api envelope convention).
export const crm = {
  // Accounts
  listAccounts:  (params = {}) => api.get('/accounts?' + new URLSearchParams(params)),
  getAccount:    (id)          => api.get(`/accounts/${id}`),
  createAccount: (data)        => api.post('/accounts', data),
  updateAccount: (id, data)    => api.put(`/accounts/${id}`, data),
  deleteAccount: (id)          => api.delete(`/accounts/${id}`),

  // Tasks
  listTasks:    (params = {}) => api.get('/tasks?' + new URLSearchParams(params)),
  createTask:   (data)        => api.post('/tasks', data),
  updateTask:   (id, data)    => api.put(`/tasks/${id}`, data),
  completeTask: (id)          => api.post(`/tasks/${id}/complete`, {}),
  deleteTask:   (id)          => api.delete(`/tasks/${id}`),

  listMeetings:  (params = {}) => api.get('/meetings?' + new URLSearchParams(params)),
  createMeeting: (data)        => api.post('/meetings', data),
  updateMeeting: (id, data)    => api.put(`/meetings/${id}`, data),
  deleteMeeting: (id)          => api.delete(`/meetings/${id}`),

  listEmails:    (contactId)       => api.get(`/contacts/${contactId}/emails`),
  sendEmail:     (contactId, data) => api.post(`/contacts/${contactId}/emails`, data),

  // Notes
  listNotes:  (relatedType, relatedId) =>
    api.get('/notes?' + new URLSearchParams({ related_type: relatedType, related_id: relatedId })),
  createNote: (data)     => api.post('/notes', data),
  updateNote: (id, data) => api.put(`/notes/${id}`, data),
  deleteNote: (id)       => api.delete(`/notes/${id}`),

  // Activities + unified timeline
  timeline:    (relatedType, relatedId) =>
    api.get('/timeline?' + new URLSearchParams({ related_type: relatedType, related_id: relatedId })),
  logActivity: (data) => api.post('/activities', data),

  // Pipelines + deals (Phase B)
  listPipelines: ()           => api.get('/pipelines'),
  updateStage:   (id, data)   => api.put(`/pipelines/stages/${id}`, data),
  dealBoard:     (pipelineId) => api.get('/deals/board' + (pipelineId ? `?pipeline_id=${pipelineId}` : '')),
  getDeal:       (id)         => api.get(`/deals/${id}`),
  createDeal:    (data)       => api.post('/deals', data),
  updateDeal:    (id, data)   => api.put(`/deals/${id}`, data),
  moveDeal:      (id, stageId, lostReason) => api.post(`/deals/${id}/move`, { stage_id: stageId, lost_reason: lostReason }),
  deleteDeal:    (id)         => api.delete(`/deals/${id}`),
  addLineItem:   (id, data)   => api.post(`/deals/${id}/line-items`, data),
  deleteLineItem:(id, itemId) => api.delete(`/deals/${id}/line-items/${itemId}`),

  // Tickets (Phase D)
  listTickets:  (params = {}) => api.get('/tickets?' + new URLSearchParams(params)),
  getTicket:    (id)          => api.get(`/tickets/${id}`),
  createTicket: (data)        => api.post('/tickets', data),
  updateTicket: (id, data)    => api.put(`/tickets/${id}`, data),
  ticketStatus: (id, status)  => api.post(`/tickets/${id}/status`, { status }),
  deleteTicket: (id)          => api.delete(`/tickets/${id}`),

  // Custom objects + records + associations + industry templates (Phase E)
  listObjects:   ()           => api.get('/custom-objects'),
  getObject:     (id)         => api.get(`/custom-objects/${id}`),
  createObject:  (data)       => api.post('/custom-objects', data),
  deleteObject:  (id)         => api.delete(`/custom-objects/${id}`),
  addField:      (id, data)   => api.post(`/custom-objects/${id}/fields`, data),
  deleteField:   (fieldId)    => api.delete(`/custom-object-fields/${fieldId}`),
  listRecords:   (objectId)   => api.get(`/custom-objects/${objectId}/records`),
  createRecord:  (objectId, data) => api.post(`/custom-objects/${objectId}/records`, data),
  getRecord:     (id)         => api.get(`/custom-object-records/${id}`),
  updateRecord:  (id, data)   => api.put(`/custom-object-records/${id}`, data),
  deleteRecord:  (id)         => api.delete(`/custom-object-records/${id}`),

  listAssociations: (type, id) => api.get('/associations?' + new URLSearchParams({ type, id })),
  link:          (data)       => api.post('/associations', data),
  unlink:        (id)         => api.delete(`/associations/${id}`),

  listTemplates: ()           => api.get('/industry-templates'),
  applyTemplate: (key)        => api.post(`/industry-templates/${key}/apply`, {}),

  // Unified list views + saved views (Phase G)
  list:      (entity, params = {}) => api.get(`/crm/list/${entity}?` + new URLSearchParams(params)),
  listMeta:  (entity)             => api.get(`/crm/list/${entity}/meta`),
  listViews: (entityType)         => api.get('/crm/views?' + new URLSearchParams({ entity_type: entityType })),
  createView:(data)               => api.post('/crm/views', data),
  updateView:(id, data)           => api.put(`/crm/views/${id}`, data),
  deleteView:(id)                 => api.delete(`/crm/views/${id}`),
  bulk:      (entity, payload)    => api.post(`/crm/bulk/${entity}`, payload),
  search:    (q)                  => api.get('/crm/search?' + new URLSearchParams({ q })),
  recycleList:    (entity)        => api.get(`/crm/recycle/${entity}`),
  recycleRestore: (entity, ids)   => api.post(`/crm/recycle/${entity}/restore`, { ids }),
  recyclePurge:   (entity, ids)   => api.post(`/crm/recycle/${entity}/purge`, { ids }),
  // CSV export of the current (filtered) view — returns a Blob (bypasses JSON client).
  exportCsv: async (entity, params = {}) => {
    const token = localStorage.getItem('tp_token')
    const clean = Object.fromEntries(Object.entries(params).filter(([, v]) => v !== '' && v != null))
    const qs  = new URLSearchParams(clean).toString()
    const res = await fetch(`/api/v1/crm/export/${entity}${qs ? '?' + qs : ''}`, {
      headers: token ? { Authorization: `Bearer ${token}` } : {},
    })
    if (!res.ok) throw new Error(`Export failed (HTTP ${res.status})`)
    return res.blob()
  },
  // CSV import (accounts/deals/records). Upload is multipart; the rest are JSON.
  importStart: async (entity, file, customObjectId) => {
    const token = localStorage.getItem('tp_token')
    const fd = new FormData()
    fd.append('file', file)
    fd.append('entity', entity)
    if (customObjectId) fd.append('custom_object_id', customObjectId)
    const res = await fetch('/api/v1/crm/imports', { method: 'POST', headers: token ? { Authorization: `Bearer ${token}` } : {}, body: fd })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(data?.messages?.error || data?.error || `Upload failed (HTTP ${res.status})`)
    return data
  },
  importMapping: (id, mapping) => api.post(`/crm/imports/${id}/mapping`, { mapping }),
  importProcess: (id)          => api.post(`/crm/imports/${id}/process`, {}),
  // Dedupe + merge (Phase G5)
  duplicates: (entity)                      => api.get(`/crm/duplicates/${entity}`),
  merge:      (entity, primaryId, loserIds) => api.post(`/crm/merge/${entity}`, { primary_id: primaryId, loser_ids: loserIds }),
  // Lead scoring (Phase H1)
  scoringRules:       ()     => api.get('/crm/scoring-rules'),
  updateScoringRules: (data) => api.put('/crm/scoring-rules', data),
  recalcScores:       ()     => api.post('/crm/scoring/recalc', {}),
  // Assignment rules (Phase H2)
  assignmentRules:       (entityType) => api.get('/crm/assignment-rules?' + new URLSearchParams({ entity_type: entityType })),
  createAssignmentRule:  (data)       => api.post('/crm/assignment-rules', data),
  updateAssignmentRule:  (id, data)   => api.put(`/crm/assignment-rules/${id}`, data),
  deleteAssignmentRule:  (id)         => api.delete(`/crm/assignment-rules/${id}`),
  // Business hours / SLA (Phase H5)
  businessHours:       ()     => api.get('/crm/business-hours'),
  updateBusinessHours: (data) => api.put('/crm/business-hours', data),

  // Dashboard (Phase F)
  dashboard: () => api.get('/crm/dashboard'),

  // Configurable dashboards + report widgets (Phase I)
  dashboards:      ()              => api.get('/crm/dashboards'),
  createDashboard: (name)          => api.post('/crm/dashboards', { name }),
  updateDashboard: (id, data)      => api.put(`/crm/dashboards/${id}`, data),
  deleteDashboard: (id)            => api.delete(`/crm/dashboards/${id}`),
  addWidget:       (dashId, data)  => api.post(`/crm/dashboards/${dashId}/widgets`, data),
  updateWidget:    (id, data)      => api.put(`/crm/widgets/${id}`, data),
  deleteWidget:    (id)            => api.delete(`/crm/widgets/${id}`),
  runReport:       (spec)          => api.post('/crm/reports/run', spec),
  reportOptions:   ()              => api.get('/crm/reports/options'),
  exportReport: async (spec) => {
    const token = localStorage.getItem('tp_token')
    const clean = Object.fromEntries(Object.entries(spec).filter(([, v]) => v !== '' && v != null))
    const res = await fetch('/api/v1/crm/reports/export?' + new URLSearchParams(clean), { headers: token ? { Authorization: `Bearer ${token}` } : {} })
    if (!res.ok) throw new Error(`Export failed (HTTP ${res.status})`)
    return res.blob()
  },
  // Forecasting + targets (Phase I3)
  forecast:     ()           => api.get('/crm/forecast'),
  attainment:   (from, to)   => api.get('/crm/forecast/attainment?' + new URLSearchParams({ ...(from ? { from } : {}), ...(to ? { to } : {}) })),
  salesTargets: ()           => api.get('/crm/sales-targets'),
  createTarget: (data)       => api.post('/crm/sales-targets', data),
  updateTarget: (id, data)   => api.put(`/crm/sales-targets/${id}`, data),
  deleteTarget: (id)         => api.delete(`/crm/sales-targets/${id}`),
  leaderboard:  (from, to)   => api.get('/crm/leaderboard?' + new URLSearchParams({ ...(from ? { from } : {}), ...(to ? { to } : {}) })),
  // Price books (Phase J3)
  priceBooks:        ()                  => api.get('/crm/price-books'),
  createPriceBook:   (data)              => api.post('/crm/price-books', data),
  updatePriceBook:   (id, data)          => api.put(`/crm/price-books/${id}`, data),
  deletePriceBook:   (id)                => api.delete(`/crm/price-books/${id}`),
  setPriceBookEntry: (bookId, data)      => api.post(`/crm/price-books/${bookId}/entries`, data),
  deletePriceBookEntry: (id)             => api.delete(`/crm/price-book-entries/${id}`),
  // Currency preferences (Phase J4)
  preferences:       ()     => api.get('/crm/preferences'),
  updatePreferences: (data) => api.put('/crm/preferences', data),
  // Audit log (Phase K1)
  auditLogs: (params = {}) => api.get('/crm/audit-logs?' + new URLSearchParams(params)),
  // Plan usage (Phase K2)
  planUsage: () => api.get('/crm/plan-usage'),
  // Teams (Phase K3)
  teams:           ()                  => api.get('/crm/teams'),
  createTeam:      (name)              => api.post('/crm/teams', { name }),
  updateTeam:      (id, data)          => api.put(`/crm/teams/${id}`, data),
  deleteTeam:      (id)                => api.delete(`/crm/teams/${id}`),
  addTeamMember:   (teamId, userId)    => api.post(`/crm/teams/${teamId}/members`, { user_id: userId }),
  removeTeamMember:(teamId, userId)    => api.delete(`/crm/teams/${teamId}/members/${userId}`),

  // Quotes (Phase F / CPQ)
  listQuotes:  (dealId)     => api.get(`/deals/${dealId}/quotes`),
  createQuote: (dealId)     => api.post(`/deals/${dealId}/quotes`, {}),
  getQuote:    (id)         => api.get(`/quotes/${id}`),
  quoteStatus: (id, status) => api.post(`/quotes/${id}/status`, { status }),
  deleteQuote: (id)         => api.delete(`/quotes/${id}`),
  sendQuote:   (id)         => api.post(`/quotes/${id}/send`, {}),
  sendQuoteEmail: (id)      => api.post(`/quotes/${id}/send-email`, {}),
  // Quote PDF — returns a Blob (bypasses JSON client) (Phase J1).
  quotePdf: async (id) => {
    const token = localStorage.getItem('tp_token')
    const res = await fetch(`/api/v1/quotes/${id}/pdf`, { headers: token ? { Authorization: `Bearer ${token}` } : {} })
    if (!res.ok) throw new Error(`PDF failed (HTTP ${res.status})`)
    return res.blob()
  },
}

export const rupees = (paise) => '₹' + (Number(paise || 0) / 100).toLocaleString('en-IN', { maximumFractionDigits: 0 })
