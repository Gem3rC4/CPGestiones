import axios from 'axios'
let token = null
export const api = axios.create({ baseURL: import.meta.env.VITE_API_URL || '/api/v1', timeout: 20000, headers: { Accept: 'application/json' } })
api.interceptors.request.use(config => { if (token) config.headers.Authorization = `Bearer ${token}`; return config })
api.interceptors.response.use(r => r, error => {
  if (error.response?.status === 401 && !error.config.url.includes('/login')) {
    token = null
    window.dispatchEvent(new Event('session-expired'))
  }
  return Promise.reject(error)
})
export const setToken = value => { token = value }
export const get = async (path, params = {}) => (await api.get(path, { params })).data.data
export const mutate = async (method, path, body, key) => (await api.request({ method, url: path, data: body, headers: { 'Idempotency-Key': key } })).data.data
export const message = error => {
  const body = error.response?.data
  if (body?.errors) return Object.values(body.errors).flat().join(' ')
  if (error.response?.status === 429) return 'Demasiados intentos. Espera un minuto antes de continuar.'
  return body?.message || 'No se pudo conectar con el portal. Revisa la conexión y vuelve a intentar.'
}
export const money = cents => new Intl.NumberFormat('es-GT', { style: 'currency', currency: 'GTQ' }).format((Number(cents) || 0) / 100)
export const date = value => value ? new Intl.DateTimeFormat('es-GT', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Guatemala' }).format(new Date(value)) : '—'
export const identifier = item => item?.id || item?._id
export const label = value => ({ prestamo: 'Préstamo', autorizado: 'Autorizado', 'credito-personal': 'Crédito Personal', 'credito-vehicular': 'Crédito Vehicular', 'credito-hipotecario': 'Préstamo Hipotecario', en_evaluacion: 'En evaluación', revision_manual: 'Revisión manual', aprobada: 'Aprobada', aceptada: 'Aceptada', en_tramite: 'En trámite', emitida: 'Emitida', rechazada: 'Rechazada', cancelada: 'Cancelada', pendiente_autorizacion: 'Por autorizar', en_cola: 'En cola', pagada: 'Pagada', activa: 'Activa', bloqueada: 'Bloqueada', credito: 'Crédito', debito: 'Débito', 'credito-clasica': 'Crédito Clásica', 'credito-oro': 'Crédito Oro', 'credito-platino': 'Crédito Platino', ahorro: 'Ahorro', monetaria: 'Monetaria', empresa_operador: 'Operador de empresa', empresa_aprobador: 'Aprobador de empresa' }[value] || (value ? String(value).replaceAll('_', ' ') : '—'))
