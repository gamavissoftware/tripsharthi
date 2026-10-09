import { api } from './client'

const token = () => localStorage.getItem('tp_token')

/**
 * Record document attachments. Upload is multipart and download is an auth-gated
 * blob (the file is never a public URL), so both bypass the JSON client and use
 * fetch with the Bearer token directly — mirroring the CSV-import upload.
 */
export const documents = {
  list:   (relatedType, relatedId) => api.get(`/documents?related_type=${relatedType}&related_id=${relatedId}`),
  remove: (id)                     => api.delete(`/documents/${id}`),

  upload(relatedType, relatedId, file) {
    const form = new FormData()
    form.append('file', file)
    form.append('related_type', relatedType)
    form.append('related_id', relatedId)
    return fetch('/api/v1/documents', {
      method: 'POST',
      headers: token() ? { Authorization: `Bearer ${token()}` } : {},
      body: form,
    }).then(r => r.json())
  },

  async download(id, filename) {
    const res = await fetch(`/api/v1/documents/${id}/download`, {
      headers: token() ? { Authorization: `Bearer ${token()}` } : {},
    })
    if (!res.ok) throw new Error('Download failed')
    const blob = await res.blob()
    const url  = URL.createObjectURL(blob)
    const a    = document.createElement('a')
    a.href = url
    a.download = filename || 'document'
    document.body.appendChild(a)
    a.click()
    a.remove()
    URL.revokeObjectURL(url)
  },
}
