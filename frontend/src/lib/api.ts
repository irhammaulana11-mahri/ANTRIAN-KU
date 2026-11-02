import axios from 'axios'

export const api = axios.create({ baseURL: import.meta.env.VITE_API_URL ?? '/api/v1', headers: { Accept: 'application/json', 'Content-Type': 'application/json' } })
api.interceptors.request.use((config) => { const token = localStorage.getItem('sqms_token'); if (token) config.headers.Authorization = `Bearer ${token}`; return config })
api.interceptors.response.use((response) => response, (error) => { if (error.response?.status === 401 && !String(error.config?.url ?? '').includes('/auth/login')) { localStorage.removeItem('sqms_token'); localStorage.removeItem('sqms_user'); localStorage.removeItem('sqms_token_meta'); window.location.assign('/login?expired=1') } return Promise.reject(error) })

export async function getData<T>(url: string, params?: Record<string, unknown>) {
  const publicCatalog: Record<string, string> = { '/branches': '/catalog/branches', '/service-types': '/catalog/service-types' }
  const target = !localStorage.getItem('sqms_token') ? (publicCatalog[url] ?? url) : url
  const response = await api.get<{ data: T; meta?: unknown }>(target, { params })
  return response.data
}
export async function postData<T>(url: string, payload?: unknown) { const response = await api.post<{ data: T }>(url, payload); return response.data }
export async function putData<T>(url: string, payload?: unknown) { const response = await api.put<{ data: T }>(url, payload); return response.data }
export async function patchData<T>(url: string, payload?: unknown) { const response = await api.patch<{ data: T }>(url, payload); return response.data }
export async function deleteData(url: string) { await api.delete(url) }

export function errorMessage(error: unknown, fallback = 'Terjadi kesalahan. Silakan coba lagi.') {
  const data = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data
  return Object.values(data?.errors ?? {})[0]?.[0] ?? data?.message ?? fallback
}

/**
 * Galat validasi (422) milik satu field, mis. 'display_media.0.url'. Aman dipanggil saat `error` masih null/undefined
 * (sebelum ada permintaan gagal) dan untuk galat non-axios — jangan pernah melempar saat dirender.
 */
export function fieldError(error: unknown, field: string): string | undefined {
  const errors = (error as { response?: { data?: { errors?: Record<string, string[]> } } } | null | undefined)?.response?.data?.errors
  return errors?.[field]?.[0]
}
