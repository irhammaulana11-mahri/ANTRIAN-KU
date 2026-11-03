import axios from 'axios'

const baseURL = import.meta.env.VITE_API_URL ?? '/api/v1'
export const KIOSK_TOKEN_KEY = 'sqms_kiosk_token'

export interface KioskConfig {
  server_time: string
  branch: { id: string; branch_name: string }
  unit?: { id: string; unit_name: string | null } | null
  services: { id: string; service_name: string; queue_prefix: string }[]
  availability: { is_open: boolean; message: string | null; opens_at: string | null; closes_at: string | null; waiting_count: number }
}
export interface KioskTicket {
  queue: { queue_number: string; customer_name: string; service_name: string | null; created_at: string; status: string }
  branch_name: string | null
  token: string
  position: number | null
  estimated_wait_seconds: number | null
}
interface Session { token: string; device: { device_type?: 'DISPLAY' | 'KIOSK' } }

/** Klien API khusus perangkat kiosk; tidak memakai token staf atau token display. */
export const kioskApi = axios.create({ baseURL, headers: { Accept: 'application/json' } })
kioskApi.interceptors.request.use((config) => { const token = localStorage.getItem(KIOSK_TOKEN_KEY); if (token) config.headers.Authorization = `Bearer ${token}`; return config })

export const kioskToken = () => localStorage.getItem(KIOSK_TOKEN_KEY)
export const clearKioskSession = () => localStorage.removeItem(KIOSK_TOKEN_KEY)

export async function activateKiosk(credential: string) {
  const { data } = await axios.post<{ data: Session }>(`${baseURL}/display/activate`, { credential: credential.trim() }, { headers: { Accept: 'application/json' } })
  if (data.data.device.device_type !== 'KIOSK') throw new Error('Kunci ini milik perangkat Central Display. Buka halaman /display untuk mengaktifkannya.')
  localStorage.setItem(KIOSK_TOKEN_KEY, data.data.token)
}
export async function refreshKioskToken() {
  const { data } = await kioskApi.post<{ data: Session }>('/display/refresh')
  localStorage.setItem(KIOSK_TOKEN_KEY, data.data.token)
}
export const fetchKioskConfig = async () => (await kioskApi.get<{ data: KioskConfig }>('/kiosk/config')).data.data
export const issueKioskTicket = async (payload: { service_type_id: string; customer_name: string; customer_phone?: string }) => (await kioskApi.post<{ data: KioskTicket }>('/kiosk/queues', payload)).data.data
