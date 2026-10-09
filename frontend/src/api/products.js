import { api } from './client'

export const products = {
  list:        ()          => api.get('/products'),
  create:      (data)      => api.post('/products', data),
  update:      (id, data)  => api.put(`/products/${id}`, data),
  remove:      (id)        => api.delete(`/products/${id}`),
  send:        (id, data)  => api.post(`/products/${id}/send`, data),
  getCatalog:  ()          => api.get('/products/catalog'),
  saveCatalog: (data)      => api.post('/products/catalog', data),
}
