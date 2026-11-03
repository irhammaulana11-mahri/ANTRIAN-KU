import axios from 'axios'
import { DISPLAY_TOKEN_KEY, resetEcho } from '@/lib/echo'
import type { DisplayCall } from '@/lib/announcement'
import type { DisplayMediaSource } from '@/types/api'

const baseURL = import.meta.env.VITE_API_URL ?? '/api/v1'

export interface DisplaySnapshot {
  server_time: string
  branch: { id: string; branch_name: string }
  unit?: { id: string; unit_name: string | null } | null
  branch_only?: boolean
  settings: { announcement: string | null; voice_rate: number; voice_volume: number; media_volume: number; call_expiry_seconds: number; media?: DisplayMediaSource[] }
  calls: DisplayCall[]
  waiting: { service_name: string; total: number }[]
}
export interface DisplaySession { token: string; expires_at: string; device: { id: string; device_name: string; device_type?: 'DISPLAY' | 'KIOSK' }; branch: { id: string; branch_name: string } }

/** Klien API khusus perangkat display; tidak memakai token staf. */
export const displayApi = axios.create({ baseURL, headers: { Accept: 'application/json' } })
displayApi.interceptors.request.use((config) => { const token = localStorage.getItem(DISPLAY_TOKEN_KEY); if (token) config.headers.Authorization = `Bearer ${token}`; return config })

export const displayToken = () => localStorage.getItem(DISPLAY_TOKEN_KEY)
export function clearDisplaySession() { localStorage.removeItem(DISPLAY_TOKEN_KEY); resetEcho(DISPLAY_TOKEN_KEY) }
export async function activateDisplay(credential: string): Promise<DisplaySession> {
  const { data } = await axios.post<{ data: DisplaySession }>(`${baseURL}/display/activate`, { credential: credential.trim() }, { headers: { Accept: 'application/json' } })
  if (data.data.device.device_type && data.data.device.device_type !== 'DISPLAY') throw new Error('Kunci ini milik perangkat kiosk. Buka halaman /kiosk untuk mengaktifkannya.')
  resetEcho(DISPLAY_TOKEN_KEY)
  localStorage.setItem(DISPLAY_TOKEN_KEY, data.data.token)
  return data.data
}
export async function refreshDisplayToken(): Promise<DisplaySession> {
  const { data } = await displayApi.post<{ data: DisplaySession }>('/display/refresh')
  resetEcho(DISPLAY_TOKEN_KEY)
  localStorage.setItem(DISPLAY_TOKEN_KEY, data.data.token)
  return data.data
}
export async function fetchSnapshot() { return (await displayApi.get<{ data: DisplaySnapshot }>('/display/snapshot')).data.data }
