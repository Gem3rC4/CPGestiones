import axios from 'axios'
// sessionStorage survives a page reload but is cleared when the tab closes.
const TOKEN_KEY = 'cp-gestiones.token', REFRESH_KEY = 'cp-gestiones.refresh'
const storage = {
  get: key => { try { return sessionStorage.getItem(key) } catch { return null } },
  set: (key, value) => { try { value ? sessionStorage.setItem(key, value) : sessionStorage.removeItem(key) } catch {} },
}
let token = storage.get(TOKEN_KEY), refreshToken = storage.get(REFRESH_KEY), refreshing = null
export const api = axios.create({ baseURL: import.meta.env.VITE_API_URL || '/api/v1', timeout: 20000, headers: { Accept: 'application/json' } })
api.interceptors.request.use(config => { if (token && !config.url.includes('/auth/refresh')) config.headers.Authorization = `Bearer ${token}`; return config })
// The JWT access token lasts 15 minutes: on expiry it is renewed once with the refresh token and the request is retried.
api.interceptors.response.use(r => r, async error => {
  const config = error.config || {}
  const isAuthCall = /\/auth\/(login|refresh)/.test(config.url || '')
  if (error.response?.status === 401 && !isAuthCall && !config._retried && refreshToken) {
    config._retried = true
    try {
      refreshing ||= api.post('/auth/refresh', { refresh_token: refreshToken }).then(r => setSession(r.data.data)).finally(() => { refreshing = null })
      await refreshing
      config.headers.Authorization = `Bearer ${token}`
      return api.request(config)
    } catch {}
  }
  if (error.response?.status === 401 && !isAuthCall) {
    setSession(null)
    window.dispatchEvent(new Event('session-expired'))
  }
  return Promise.reject(error)
})
export const setSession = data => { token = data?.token || null; refreshToken = data?.refresh_token || null; storage.set(TOKEN_KEY, token); storage.set(REFRESH_KEY, refreshToken) }
export const refreshValue = () => refreshToken
export const hasToken = () => Boolean(token || refreshToken)
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
export const day = value => value ? new Intl.DateTimeFormat('es-GT', { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`)) : '—'
export const identifier = item => item?.id || item?._id
export const label = value => ({ prestamo: 'Préstamo', autorizado: 'Autorizado', vigente: 'Vigente', pagado: 'Pagado', pendiente: 'Pendiente', parcial: 'Pago parcial', 'credito-personal': 'Crédito Personal', 'credito-vehicular': 'Crédito Vehicular', 'credito-hipotecario': 'Préstamo Hipotecario', en_evaluacion: 'En evaluación', revision_manual: 'Revisión manual', aprobada: 'Aprobada', aceptada: 'Aceptada', en_tramite: 'En trámite', emitida: 'Emitida', rechazada: 'Rechazada', cancelada: 'Cancelada', pendiente_autorizacion: 'Por autorizar', en_cola: 'En cola', procesando: 'Procesando', pagada: 'Pagada', activa: 'Activa', bloqueada: 'Bloqueada', credito: 'Crédito', debito: 'Débito', 'credito-clasica': 'Crédito Clásica', 'credito-oro': 'Crédito Oro', 'credito-platino': 'Crédito Platino', ahorro: 'Ahorro', monetaria: 'Monetaria', administrador: 'Administrador', operador: 'Operador', empresa: 'Empresa (banca corporativa)', pago_servicio: 'Pago de servicio', transferencia: 'Transferencia', deposito: 'Depósito', retiro: 'Retiro', pago_tarjeta: 'Pago de tarjeta', consumo_debito: 'Consumo con débito', consumo_credito: 'Consumo con crédito', interes_ahorro: 'Interés de ahorro', planilla: 'Planilla', desembolso_prestamo: 'Desembolso de préstamo', pago_prestamo: 'Pago de préstamo', apertura_fondos: 'Fondos iniciales', reverso_demo: 'Reverso de datos de prueba', cobrado: 'Cobrado', sin_fondos: 'Sin fondos' }[value] || (value ? String(value).replaceAll('_', ' ') : '—'))
